<?php

namespace Modules\Governance;

use API;
use RuntimeException;
use Throwable;

/** Current-state audit, not a transactional/historical snapshot. One API request per step. */
final class QualityCalculation {
    const MAX_HOSTS = 50000;
    const BATCH_SIZE = 100;
    private $api;

    public function __construct(?callable $api = null) {
        $this->api = $api ?? static function(string $service, array $options) {
            return API::$service()->get($options);
        };
    }

    public static function create(array $config, string $pageId, array $groupids, string $revision): array {
        if (!hash_equals(GovernanceConfig::qualityRevision($config), $revision)) {
            throw new RuntimeException('Configuration changed.');
        }
        $pages = GovernanceConfig::getQualityPages($config);
        $page = null;
        foreach ($pages as $candidate) { if ($candidate['id'] === $pageId) { $page = $candidate; break; } }
        if ($page === null && ($pages || $pageId !== '')) { throw new RuntimeException('Page changed.'); }
        $page = $page ?? ['id' => '', 'name' => '', 'cards' => []];
        $cards = [];
        foreach ($page['cards'] as $card) {
            $card += GovernanceConfig::crossFilters($card);
            $card['names'] = GovernanceConfig::splitList($card['tag_names']);
            $card['values'] = GovernanceConfig::splitList($card['tag_values']);
            $card['groups'] = GovernanceConfig::splitList($card['group_names']);
            $card['valid'] = 0;
            $card['total'] = 0;
            $card['examples'] = [];
            $cards[] = $card;
        }
        $groups = array_values(array_unique(array_map('strval', $groupids)));
        sort($groups, SORT_STRING);
        return ['format' => 2, 'status' => 'running', 'page' => $pageId, 'revision' => $revision,
            'started_at' => time(), 'finished_at' => null, 'groupids' => $groups,
            'cards' => $cards, 'hostids' => [], 'cursor' => 0, 'item_cursor' => 0, 'item_count' => 0,
            'progress' => ['stage' => 'scope', 'hosts_total' => null, 'hosts_done' => 0, 'calls' => 0, 'api_ms' => []],
            'overview' => ['registered' => 0, 'monitored' => 0, 'disabled' => 0,
                'maintenance' => 0, 'available' => 0, 'unavailable' => 0], 'result' => null];
    }

    public function advance(array $state): array {
        if ($state['status'] !== 'running') { return $state; }
        $phase = $state['progress']['stage'];
        try {
            if (($state['format'] ?? 0) !== 2) {
                throw new QualityScopeException('Module updated. Reload the page and start a new analysis / Módulo atualizado. Recarregue a página e inicie uma nova análise.');
            }
            switch ($phase) {
                case 'scope': $this->scope($state); break;
                case 'hosts': $this->hosts($state); break;
                case 'problems': $this->problems($state); break;
                case 'unsupported': $this->unsupported($state); break;
                default: throw new RuntimeException('Invalid stage.');
            }
        }
        catch (Throwable $e) {
            // Operational counters do not participate in card scores. A failed counter is not zero.
            if ($state['result'] !== null && in_array($phase, ['problems', 'unsupported'], true)) {
                $key = $phase === 'problems' ? 'high_problems' : 'unsupported_items';
                $state['result']['metrics'][$key] = ['status' => 'failed', 'value' => null];
                if ($phase === 'problems') { $state['progress']['stage'] = 'unsupported'; }
                else { $this->finish($state); }
            }
            else {
                $state['status'] = 'failed';
                $state['progress']['stage'] = 'failed';
                $state['result'] = null;
                $state['error'] = $e instanceof QualityScopeException ? $e->getMessage()
                    : 'Cannot read the full host scope. Retry or narrow the groups / Não foi possível ler todo o escopo. Tente novamente ou filtre os grupos.';
                unset($state['cards'], $state['hostids']);
            }
            // Never log exception messages: API errors may contain SQL or credentials.
            error_log('[Governance quality] ' . $phase . ' failure (' . get_class($e) . ').');
        }
        return $state;
    }

    private function query(array &$state, string $service, array $options) {
        $stage = $state['progress']['stage'];
        $begin = microtime(true);
        $state['progress']['calls']++;
        try {
            $result = call_user_func($this->api, $service, $options);
            if (!empty($options['countOutput'])) {
                if (!is_numeric($result) || (float) $result < 0 || floor((float) $result) != (float) $result) {
                    throw new RuntimeException('Invalid count response.');
                }
                return (int) $result;
            }
            if (!is_array($result)) { throw new RuntimeException('Invalid API response.'); }
            return $result;
        }
        finally {
            $state['progress']['api_ms'][$stage] = ($state['progress']['api_ms'][$stage] ?? 0)
                + (int) round((microtime(true) - $begin) * 1000);
        }
    }

    private function scope(array &$state): void {
        $options = ['output' => ['hostid', 'status'], 'sortfield' => 'hostid', 'sortorder' => 'ASC',
            'limit' => self::MAX_HOSTS + 1];
        if ($state['groupids']) { $options['groupids'] = $state['groupids']; }
        $rows = $this->query($state, 'Host', $options);
        if (count($rows) > self::MAX_HOSTS) {
            throw new QualityScopeException('Scope exceeds 50,000 hosts; filter the groups / Escopo excede 50.000 hosts; filtre os grupos.');
        }
        $seen = [];
        foreach ($rows as $row) {
            if (!isset($row['hostid'], $row['status']) || !preg_match('/^[1-9][0-9]*$/D', (string) $row['hostid'])
                    || isset($seen[$row['hostid']]) || !in_array((int) $row['status'], [0, 1], true)) {
                throw new RuntimeException('Invalid host scope.');
            }
            $seen[$row['hostid']] = true;
            if ((int) $row['status'] === HOST_STATUS_MONITORED) { $state['hostids'][] = (string) $row['hostid']; }
            else { $state['overview']['disabled']++; }
        }
        $state['overview']['registered'] = count($rows);
        $state['overview']['monitored'] = count($state['hostids']);
        $state['progress']['hosts_total'] = count($state['hostids']);
        $state['progress']['stage'] = 'hosts';
        if (!$state['hostids']) { $this->completeCards($state); }
    }

    private function hosts(array &$state): void {
        $ids = array_slice($state['hostids'], $state['cursor'], self::BATCH_SIZE);
        $types = array_column($state['cards'], 'type');
        $options = ['output' => ['hostid', 'name', 'status', 'maintenance_status'], 'hostids' => $ids,
            'filter' => ['status' => HOST_STATUS_MONITORED],
            'selectInterfaces' => ['type', 'available', 'useip', 'ip', 'dns'], 'preservekeys' => true];
        if ($state['groupids']) { $options['groupids'] = $state['groupids']; }
        if (in_array('tag', $types, true) || array_filter(array_column($state['cards'], 'scope_tag_name'))) { $options['selectTags'] = ['tag', 'value']; }
        if (in_array('inventory', $types, true)) { $options['selectInventory'] = array_values(array_unique(array_merge(['os', 'serialno_a', 'location', 'type', 'software'], array_filter(array_column($state['cards'], 'inventory_field'))))); }
        if (in_array('templates', $types, true)) { $options['selectParentTemplates'] = 'count'; }
        if (array_filter(array_column($state['cards'], 'template_names'))) { $options['selectParentTemplates'] = ['templateid', 'host', 'name']; }
        if (in_array('hostgroups', $types, true) || array_filter(array_column($state['cards'], 'scope_group_names'))) { $options['selectGroups'] = ['groupid', 'name']; }
        $rows = $this->query($state, 'Host', $options);
        $index = [];
        foreach ($rows as $host) {
            if (!isset($host['hostid'], $host['name'], $host['status']) || isset($index[$host['hostid']])) {
                throw new RuntimeException('Invalid host data.');
            }
            $index[$host['hostid']] = $host;
        }
        if (count($index) !== count($ids)) { throw self::changedScope(); }
        foreach ($ids as $id) {
            if (!isset($index[$id]) || (int) $index[$id]['status'] !== HOST_STATUS_MONITORED) { throw self::changedScope(); }
            $host = $index[$id];
            if ((int) ($host['maintenance_status'] ?? 0) === HOST_MAINTENANCE_STATUS_ON) { $state['overview']['maintenance']++; }
            $availability = array_map('intval', array_column($host['interfaces'] ?? [], 'available'));
            if (in_array(INTERFACE_AVAILABLE_FALSE, $availability, true)) { $state['overview']['unavailable']++; }
            elseif (in_array(INTERFACE_AVAILABLE_TRUE, $availability, true)) { $state['overview']['available']++; }
            foreach ($state['cards'] as &$card) {
                if (!self::inScope($host, $card)) { continue; }
                $card['total']++;
                if (self::compliant($host, $card)) { $card['valid']++; }
                elseif (count($card['examples']) < 10) { $card['examples'][] = ['hostid' => $id, 'name' => $host['name']]; }
            }
            unset($card);
        }
        $state['cursor'] += count($ids);
        $state['progress']['hosts_done'] = $state['cursor'];
        if ($state['cursor'] === count($state['hostids'])) { $this->completeCards($state); }
    }

    private static function changedScope(): QualityScopeException {
        return new QualityScopeException('Host scope changed during analysis. Start again / O escopo de hosts mudou durante a análise. Atualize novamente.');
    }

    private function completeCards(array &$state): void {
        $total = count($state['hostids']);
        $kpis = []; $scores = [];
        foreach ($total ? $state['cards'] : [] as $card) {
            $score = $card['total'] ? round(100 * $card['valid'] / $card['total'], 1) : null;
            if ($card['include_score'] && $score !== null) { $scores[] = $score; }
            $kpis[] = ['id' => $card['id'], 'score' => $score, 'valid_count' => $card['valid'],
                'total_count' => $card['total'], 'display_mode' => $card['display_mode'], 'non_compliant' => $card['examples']];
        }
        $state['result'] = ['overall_score' => $scores ? round(array_sum($scores) / count($scores), 1) : null,
            'total_hosts' => $total, 'kpis' => $kpis, 'overview' => $state['overview'],
            'metrics' => ['high_problems' => ['status' => 'pending', 'value' => null],
                'unsupported_items' => ['status' => 'pending', 'value' => null]]];
        unset($state['cards']);
        $state['progress']['stage'] = 'problems';
        if (!$total) {
            foreach ($state['result']['metrics'] as &$metric) { $metric = ['status' => 'complete', 'value' => 0]; }
            unset($metric);
            $this->finish($state);
        }
    }

    private function problems(array &$state): void {
        // One exact scope count: summing host batches would double-count multi-host trigger events.
        $count = $this->query($state, 'Problem', ['countOutput' => true, 'hostids' => $state['hostids'],
            'recent' => false, 'suppressed' => false, 'severities' => [TRIGGER_SEVERITY_HIGH, TRIGGER_SEVERITY_DISASTER]]);
        $state['result']['metrics']['high_problems'] = ['status' => 'complete', 'value' => $count];
        $state['progress']['stage'] = 'unsupported';
    }

    private function unsupported(array &$state): void {
        $ids = array_slice($state['hostids'], $state['item_cursor'], self::BATCH_SIZE);
        $count = $this->query($state, 'Item', ['countOutput' => true, 'hostids' => $ids,
            'monitored' => true, 'filter' => ['state' => ITEM_STATE_NOTSUPPORTED]]);
        $state['item_count'] += $count;
        $state['item_cursor'] += count($ids);
        if ($state['item_cursor'] === count($state['hostids'])) {
            $state['result']['metrics']['unsupported_items'] = ['status' => 'complete', 'value' => $state['item_count']];
            $this->finish($state);
        }
    }

    private function finish(array &$state): void {
        $state['status'] = 'complete'; $state['progress']['stage'] = 'complete';
        $state['finished_at'] = time();
        unset($state['hostids'], $state['cards']);
    }

    private static function compliant(array $host, array $card): bool {
        switch ($card['type']) {
            case 'tag':
                foreach ($host['tags'] ?? [] as $tag) {
                    $name = mb_strtolower(trim($tag['tag'] ?? ''), 'UTF-8');
                    $value = mb_strtolower(trim($tag['value'] ?? ''), 'UTF-8');
                    if (in_array($name, $card['names'], true) && $value !== ''
                            && (!$card['values'] || in_array($value, $card['values'], true))) { return true; }
                }
                return false;
            case 'inventory':
                foreach (!empty($card['inventory_field']) ? [$card['inventory_field']] : ['os', 'serialno_a', 'location', 'type', 'software'] as $field) {
                    if (trim($host['inventory'][$field] ?? '') !== '') { return true; }
                }
                return false;
            case 'templates':
                $expected = GovernanceConfig::splitList($card['template_names'] ?? '');
                if (!$expected) { return !empty($host['parentTemplates']); }
                $linked = [];
                foreach ($host['parentTemplates'] ?? [] as $template) {
                    foreach (['templateid', 'host', 'name'] as $key) {
                        $linked[] = mb_strtolower(trim((string) ($template[$key] ?? '')), 'UTF-8');
                    }
                }
                $matches = array_intersect($expected, $linked);
                return $card['template_mode'] === 'all' ? count($matches) === count($expected) : (bool) $matches;
            case 'interface':
                foreach ($host['interfaces'] ?? [] as $interface) {
                    $address = (int) ($interface['useip'] ?? 1) === 1 ? ($interface['ip'] ?? '') : ($interface['dns'] ?? '');
                    if (trim($address) !== '') { return true; }
                }
                return false;
            case 'hostgroups':
                foreach ($host['groups'] ?? [] as $group) {
                    $id = trim((string) ($group['groupid'] ?? ''));
                    $name = mb_strtolower(trim($group['name'] ?? ''), 'UTF-8');
                    foreach ($card['groups'] as $accepted) {
                        $prefix = rtrim($accepted, '/');
                        if ($id === $accepted || $name === $prefix || (!empty($card['group_include_subgroups']) && $prefix !== '' && strpos($name, $prefix . '/') === 0)) { return true; }
                    }
                }
                return false;
        }
        return false;
    }

    private static function inScope(array $host, array $card): bool {
        if ($card['scope_tag_name'] !== '') {
            $name = mb_strtolower($card['scope_tag_name'], 'UTF-8');
            $value = mb_strtolower($card['scope_tag_value'], 'UTF-8');
            $found = false;
            foreach ($host['tags'] ?? [] as $tag) {
                if (mb_strtolower(trim($tag['tag']), 'UTF-8') === $name
                        && ($value === '' || mb_strtolower(trim($tag['value']), 'UTF-8') === $value)) { $found = true; break; }
            }
            if (!$found) { return false; }
        }
        $groups = GovernanceConfig::splitList($card['scope_group_names']);
        return !$groups || self::compliant($host, ['type' => 'hostgroups', 'groups' => $groups,
            'group_include_subgroups' => $card['scope_include_subgroups']]);
    }

    public static function projection(array $state): array {
        return ['status' => $state['status'], 'page' => $state['page'] ?? '', 'revision' => $state['revision'] ?? '',
            'started_at' => $state['started_at'] ?? null, 'finished_at' => $state['finished_at'] ?? null,
            'progress' => array_intersect_key($state['progress'], array_flip(['stage', 'hosts_total', 'hosts_done', 'calls', 'api_ms'])),
            'result' => $state['result'] ?? null, 'error' => $state['error'] ?? null];
    }
}

/** Only messages constructed here (not API exception text) may be shown to the user. */
final class QualityScopeException extends RuntimeException {}
