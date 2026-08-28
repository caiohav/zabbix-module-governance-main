<?php

namespace Modules\Governance;

use API;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Checkpointable, read-only monthly calculation. Only server-side checkpoints are accepted.
 * The period and rules are fixed at creation; discovery fixes all host/item scopes before
 * reading history. A processing failure is not a measurement of unknown availability.
 */
final class AvailabilityCalculation {
    const FORMAT = 1;
    const MAX_HOSTS = 200;
    const PAGE_ROWS = 5000;
    const MAX_ROWS = 20000000;
    const MAX_INTERVALS = 200000;
    const MAX_MEMORY = 268435456;
    const MEMORY_RESERVE = 16777216;
    private $clock;

    public function __construct(?callable $clock = null) {
        $this->clock = $clock ?? static function() { return microtime(true); };
    }

    public static function create(array $fullConfig, string $month, int $department = -1, ?int $now = null): array {
        $config = AvailabilityConfig::validate($fullConfig);
        if (!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $month)) {
            throw new RuntimeException('Invalid month / Mês inválido.');
        }
        $selected = $config;
        if ($department !== -1) {
            if (!isset($config['departments'][$department])) {
                throw new RuntimeException('Invalid department / Departamento inválido.');
            }
            $selected['departments'] = [$config['departments'][$department]];
        }
        if (!$selected['departments']) {
            throw new RuntimeException('Configure a department first / Configure um departamento primeiro.');
        }
        $start = new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone($config['timezone']));
        $now = $now ?? time();
        $from = $start->getTimestamp();
        $monthEnd = $start->modify('+1 month')->getTimestamp();
        $to = min($monthEnd, $now);
        if ($from >= $to || $to > 2147483647) {
            throw new RuntimeException('Choose a past or current month supported by Zabbix / Selecione o mês atual ou anterior suportado pelo Zabbix.');
        }
        $state = ['format' => self::FORMAT, 'status' => 'running', 'phase' => 'groups',
            'source_config' => $config, 'department_filter' => $department,
            'started_at' => time(), 'working_seconds' => 0.0,
            'report' => ['month' => $month, 'timezone' => $config['timezone'], 'from' => $from, 'to' => $to,
                'generated_at' => $now, 'partial' => $to < $monthEnd, 'configuration' => $selected,
                'departments' => [], 'rows' => 0],
            'progress' => ['hosts_total' => 0, 'hosts_done' => 0, 'checks_total' => 0, 'checks_done' => 0,
                'rows' => 0, 'calls' => 0, 'percent' => 0, 'stage' => 'groups',
                'department' => '', 'technology' => '', 'host' => ''],
            'tasks' => [], 'scope_index' => 0, 'task_index' => 0, 'host_index' => 0, 'check_index' => 0,
            'department_index' => 0];
        foreach ($selected['departments'] as $d => $node) {
            $state['report']['departments'][] = ['name' => $node['name'], 'target' => $node['target'],
                'technologies' => [], 'warnings' => []];
            foreach ($node['technologies'] as $technology) {
                $state['tasks'][] = ['department' => $d, 'config' => $technology,
                    'result' => ['name' => $technology['name'], 'weight' => $technology['weight'],
                        'target' => $technology['target'], 'mode' => $technology['mode'],
                        'groups' => [], 'hosts' => [], 'hosts_total' => 0, 'warnings' => []],
                    'scope_hosts' => [], 'host_series' => []];
            }
        }
        return $state;
    }

    /** Each operation performs at most one bounded API query or one consolidation. */
    public function advance(array $state, int $maxOperations = 4, float $maxSeconds = 3.0): array {
        if (($state['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('Incompatible calculation; start again / Cálculo incompatível; inicie novamente.');
        }
        if ($state['status'] !== 'running') { return $state; }
        $started = ($this->clock)();
        $maxOperations = max(1, min(8, $maxOperations));
        $maxSeconds = max(0.1, min(5.0, $maxSeconds));
        try {
            for ($operation = 0; $operation < $maxOperations && $state['status'] === 'running'; $operation++) {
                // Yield between queries; do not increase PHP's request timeout.
                if ($operation > 0 && ($this->clock)() - $started >= $maxSeconds) { break; }
                $this->guard();
                $this->operation($state);
                $this->progress($state);
            }
        }
        catch (RuntimeException $e) {
            $state['status'] = 'failed';
            $state['error'] = $e->getMessage();
        }
        catch (\Throwable $e) {
            error_log('[Governance availability] ' . get_class($e) . ' at ' . $e->getFile() . ':' . $e->getLine()
                . ' during ' . $state['phase']);
            $state['status'] = 'failed';
            $state['error'] = 'Calculation interrupted; check the frontend log / Cálculo interrompido; consulte o log do frontend.';
        }
        $state['working_seconds'] += max(0, ($this->clock)() - $started);
        if ($state['status'] === 'complete') {
            $state['report']['processing']['working_seconds'] = $state['working_seconds'];
        }
        return $state;
    }

    public static function result(array $state): array {
        if (($state['format'] ?? null) !== self::FORMAT || ($state['status'] ?? '') !== 'complete') {
            throw new RuntimeException('Calculation not complete; no final indicator available / Cálculo não concluído; indicador final indisponível.');
        }
        return $state['report'];
    }

    private function operation(array &$state): void {
        switch ($state['phase']) {
            case 'groups':
                $state['groups'] = $this->query($state, 'HostGroup', ['output' => ['groupid', 'name']]);
                $state['phase'] = 'scope_hosts';
                break;
            case 'scope_hosts': $this->scopeHosts($state); break;
            case 'scope_items': $this->scopeItems($state); break;
            case 'check': $this->startCheck($state); break;
            case 'history': $this->historyPage($state); break;
            case 'host': $this->finishHost($state); break;
            case 'technology': $this->finishTechnology($state); break;
            case 'department': $this->finishDepartment($state); break;
            case 'finish': $this->finish($state); break;
            default: throw new RuntimeException('Invalid processing stage / Etapa de processamento inválida.');
        }
    }

    private function scopeHosts(array &$state): void {
        $task = &$state['tasks'][$state['scope_index']];
        $ids = [];
        foreach (AvailabilityConfig::groups($task['config']['groups']) as $token) {
            $found = false;
            foreach ($state['groups'] as $group) {
                $name = mb_strtolower($group['name'], 'UTF-8');
                $matches = ctype_digit($token) ? (string) $group['groupid'] === $token
                    : ($name === $token || strpos($name, $token . '/') === 0);
                if ($matches) { $ids[$group['groupid']] = $group['name']; $found = true; }
            }
            if (!$found) {
                // Never compute just the subset of a multi-group rule that happened to resolve.
                $task['result']['warnings'][] = 'Group not found / Grupo não encontrado: ' . $token;
                $this->nextScope($state);
                return;
            }
        }
        $task['result']['groups'] = array_values($ids);
        $hosts = $this->query($state, 'Host', ['output' => ['hostid', 'name', 'status'],
            'groupids' => array_keys($ids), 'sortfield' => 'name', 'limit' => self::MAX_HOSTS + 1]);
        if (count($hosts) > self::MAX_HOSTS) {
            throw new RuntimeException('Scope exceeds 200 hosts per technology; no partial indicator is published / Escopo excede 200 hosts por tecnologia; nenhum indicador parcial é publicado.');
        }
        $unique = [];
        foreach ($hosts as $host) { $unique[$host['hostid']] = $host; }
        $task['scope_hosts'] = array_values($unique);
        $task['result']['hosts_total'] = count($unique);
        $state['progress']['hosts_total'] += count($unique);
        $state['progress']['checks_total'] += count($unique) * count($task['config']['checks']);
        if (!$unique) {
            $task['result']['warnings'][] = 'No hosts in the selected groups / Nenhum host nos grupos selecionados.';
            $this->nextScope($state);
        }
        else { $state['phase'] = 'scope_items'; }
    }

    private function scopeItems(array &$state): void {
        $task = &$state['tasks'][$state['scope_index']];
        $items = $this->query($state, 'Item', [
            'output' => ['itemid', 'hostid', 'key_', 'value_type', 'status', 'delay', 'type'],
            'selectPreprocessing' => ['type', 'params'], 'hostids' => array_column($task['scope_hosts'], 'hostid'),
            'filter' => ['key_' => array_values(array_unique(array_column($task['config']['checks'], 'key')))],
            'webitems' => true]);
        $index = [];
        foreach ($items as $item) { $index[$item['hostid']][$item['key_']] = $item; }
        foreach ($task['scope_hosts'] as &$host) {
            $host['checks'] = [];
            $host['warnings'] = (int) $host['status'] !== 0
                ? ['Host currently disabled; historical data included / Host atualmente desabilitado; histórico incluído.'] : [];
            foreach ($task['config']['checks'] as $check) {
                $item = $index[$host['hostid']][$check['key']] ?? null;
                $source = ['key' => $check['key'], 'itemid' => $item ? (string) $item['itemid'] : null,
                    'sample_count' => 0, 'max_gap_seconds' => null, 'first_clock' => null, 'last_clock' => null,
                    'seed_clock' => null];
                if (!$item || !in_array((int) $item['value_type'], [0, 3], true)) {
                    $source += ['max_age' => null, 'freshness_mode' => $check['max_age'] === null ? 'auto' : 'manual',
                        'freshness_source' => 'unresolved', 'interval_seconds' => null, 'heartbeat_seconds' => null,
                        'warnings' => ['Missing or non-numeric item / Item ausente ou não numérico: ' . $check['key']]];
                }
                else {
                    // Persist the resolved policy, not unrelated preprocessing scripts or macro values.
                    $source += AvailabilityFreshness::resolve($item, $check['max_age']);
                    if ((int) $item['status'] !== 0) {
                        $source['warnings'][] = 'Item currently disabled / Item atualmente desabilitado: ' . $check['key'];
                    }
                }
                foreach ($source['warnings'] as $warning) { $host['warnings'][] = $check['key'] . ': ' . $warning; }
                $host['checks'][] = ['source' => $source, 'value_type' => $item ? (int) $item['value_type'] : null,
                    'rule' => $check];
            }
        }
        unset($host);
        $this->nextScope($state);
    }

    private function nextScope(array &$state): void {
        $state['scope_index']++;
        if ($state['scope_index'] >= count($state['tasks'])) {
            unset($state['groups']);
            $state['scope_frozen_at'] = time();
            $state['phase'] = 'check';
        }
        else { $state['phase'] = 'scope_hosts'; }
    }

    private function startCheck(array &$state): void {
        $task = &$state['tasks'][$state['task_index']];
        if ($state['host_index'] >= $task['result']['hosts_total']) { $state['phase'] = 'technology'; return; }
        if (!isset($state['current_host'])) { $state['current_host'] = ['series' => [], 'sources' => []]; }
        $check = $task['scope_hosts'][$state['host_index']]['checks'][$state['check_index']];
        $state['current_check'] = $check + ['last' => null, 'series' => [],
            'cursor' => max(0, $state['report']['from'] - ($check['source']['max_age'] ?? 0))];
        if ($check['source']['max_age'] === null) {
            $state['current_check']['series'] = AvailabilityEngine::unknown($state['report']['from'], $state['report']['to']);
            $this->finishCheck($state);
        }
        else { $state['phase'] = 'history'; }
    }

    private function historyPage(array &$state): void {
        $current = &$state['current_check'];
        $from = $state['report']['from'];
        $to = $state['report']['to'];
        $begin = $current['cursor'];
        $end = min($to, $begin + 7 * 86400);
        $fetchLimit = min(self::PAGE_ROWS, (int) floor($this->memoryAvailable() / 4096) - 1);
        if ($fetchLimit < 16) { throw new RuntimeException('Insufficient safe memory for history / Memória segura insuficiente para o histórico.'); }
        $this->guard(($fetchLimit + 1) * 4096);
        $samples = $this->query($state, 'History', ['output' => ['clock', 'ns', 'value'],
            'history' => $current['value_type'], 'itemids' => [$current['source']['itemid']],
            'time_from' => $begin, 'time_till' => $end - 1,
            // Zabbix 6.0 supports clock but not ns as a sort field.
            'sortfield' => 'clock', 'sortorder' => 'ASC', 'limit' => $fetchLimit + 1]);
        $state['progress']['rows'] += count($samples);
        if ($state['progress']['rows'] > self::MAX_ROWS) {
            throw new RuntimeException('Calculation exceeds 20 million history rows; narrow the scope / Cálculo excede 20 milhões de amostras; reduza o escopo.');
        }
        $previous = $begin;
        foreach ($samples as $sample) {
            if (!isset($sample['clock']) || !is_numeric($sample['clock']) || !array_key_exists('value', $sample)
                    || (int) $sample['clock'] < $previous || (int) $sample['clock'] >= $end) {
                throw new RuntimeException('Invalid history page; no truncated result is used / Página de histórico inválida; resultado truncado não utilizado.');
            }
            $previous = (int) $sample['clock'];
        }
        if (count($samples) > $fetchLimit) {
            // Drop the WHOLE boundary second, then query it inclusively next time. An API limit
            // can split values sharing a clock, in arbitrary ns order. Never skip that second.
            $end = $previous;
            if ($end <= $begin || count($samples) > $fetchLimit + 1) {
                throw new RuntimeException('Too many history values in one second; exact pagination unavailable / Valores demais no mesmo segundo; paginação exata indisponível.');
            }
            $samples = array_values(array_filter($samples, static function($sample) use ($end) {
                return (int) $sample['clock'] < $end;
            }));
        }
        $carry = $current['last'];
        foreach ($samples as $sample) {
            $clock = (int) $sample['clock'];
            if ($clock >= $from) {
                $current['source']['sample_count']++;
                if ($current['source']['first_clock'] === null) { $current['source']['first_clock'] = $clock; }
                $current['source']['last_clock'] = $clock;
                if ($current['last'] !== null) {
                    $current['source']['max_gap_seconds'] = max($current['source']['max_gap_seconds'] ?? 0,
                        $clock - (int) $current['last']['clock']);
                }
            }
            else { $current['source']['seed_clock'] = $clock; }
            if ($current['last'] === null || [$clock, (int) ($sample['ns'] ?? 0)]
                    > [(int) $current['last']['clock'], (int) ($current['last']['ns'] ?? 0)]) {
                $current['last'] = $sample;
            }
        }
        if ($carry !== null) { $samples[] = $carry; }
        if ($end > $from) {
            foreach (AvailabilityEngine::samples($samples, $current['rule'], $current['source']['max_age'], max($from, $begin), $end) as $interval) {
                AvailabilityEngine::append($current['series'], $interval);
            }
        }
        if (count($current['series']) > self::MAX_INTERVALS) {
            throw new RuntimeException('Timeline complexity limit reached / Limite de complexidade temporal atingido.');
        }
        $current['cursor'] = $end;
        if ($end >= $to) { $this->finishCheck($state); }
    }

    private function finishCheck(array &$state): void {
        $current = $state['current_check'];
        $current['source']['summary'] = AvailabilityEngine::summary($current['series'], $state['report']['from'], $state['report']['to']);
        $state['current_host']['series'][] = $current['series'];
        $state['current_host']['sources'][] = $current['source'];
        $state['progress']['checks_done']++;
        unset($state['current_check']);
        $state['check_index']++;
        $task = $state['tasks'][$state['task_index']];
        $state['phase'] = $state['check_index'] < count($task['config']['checks']) ? 'check' : 'host';
    }

    private function finishHost(array &$state): void {
        $task = &$state['tasks'][$state['task_index']];
        $host = $task['scope_hosts'][$state['host_index']];
        $series = $this->combine($state['current_host']['series'], 'any_down', $state['report']);
        $task['host_series'][] = $series;
        $task['result']['hosts'][] = ['hostid' => (string) $host['hostid'], 'name' => $host['name'],
            'sources' => $state['current_host']['sources'], 'warnings' => $host['warnings'],
            'summary' => AvailabilityEngine::summary($series, $state['report']['from'], $state['report']['to'])];
        unset($state['current_host'], $task['scope_hosts'][$state['host_index']]);
        $state['progress']['hosts_done']++;
        $state['host_index']++;
        $state['check_index'] = 0;
        $state['phase'] = 'check';
    }

    private function finishTechnology(array &$state): void {
        $task = &$state['tasks'][$state['task_index']];
        $series = $this->combine($task['host_series'], $task['config']['mode'], $state['report']);
        $task['result']['summary'] = AvailabilityEngine::summary($series, $state['report']['from'], $state['report']['to']);
        $task['result']['daily'] = $this->daily($series, $state['report']);
        $exceptions = array_values(array_filter($series, static function($i) { return $i[3] > 0 || $i[4] > 0; }));
        $task['result']['interval_count'] = count($exceptions);
        $task['result']['intervals'] = array_slice($exceptions, 0, 200);
        $state['report']['departments'][$task['department']]['technologies'][] = $task['result'];
        $task['series'] = $series;
        unset($task['scope_hosts'], $task['host_series'], $task['result']);
        $state['task_index']++;
        $state['host_index'] = 0;
        $state['check_index'] = 0;
        $state['phase'] = $state['task_index'] < count($state['tasks']) ? 'check' : 'department';
    }

    private function finishDepartment(array &$state): void {
        $series = []; $weights = [];
        foreach ($state['tasks'] as $task) {
            if ($task['department'] === $state['department_index']) {
                $series[] = $task['series'];
                $weights[] = $task['config']['weight'];
            }
        }
        $combined = $this->combine($series, 'mean', $state['report'], $weights);
        $node = &$state['report']['departments'][$state['department_index']];
        $node['summary'] = AvailabilityEngine::summary($combined, $state['report']['from'], $state['report']['to']);
        $node['daily'] = $this->daily($combined, $state['report']);
        foreach ($state['tasks'] as &$task) {
            if ($task['department'] === $state['department_index']) { unset($task['series']); }
        }
        unset($task);
        $state['department_index']++;
        if ($state['department_index'] >= count($state['report']['departments'])) { $state['phase'] = 'finish'; }
    }

    private function finish(array &$state): void {
        if ($state['progress']['hosts_done'] !== $state['progress']['hosts_total']
                || $state['progress']['checks_done'] !== $state['progress']['checks_total']) {
            throw new RuntimeException('Scope not fully evaluated; no final indicator available / Escopo não totalmente avaliado; indicador final indisponível.');
        }
        $state['status'] = 'complete';
        $state['phase'] = 'complete';
        $state['report']['rows'] = $state['progress']['rows'];
        $state['report']['processing'] = ['method' => 'checkpointed-history', 'version' => '1.7.0',
            'started_at' => $state['started_at'], 'completed_at' => time(),
            'elapsed_seconds' => max(0, time() - $state['started_at']), 'scope_frozen_at' => $state['scope_frozen_at'],
            'hosts_total' => $state['progress']['hosts_total'], 'hosts_done' => $state['progress']['hosts_done'],
            'checks_total' => $state['progress']['checks_total'], 'checks_done' => $state['progress']['checks_done'],
            'api_calls' => $state['progress']['calls']];
        unset($state['tasks']);
    }

    private function progress(array &$state): void {
        $p = &$state['progress'];
        $p['stage'] = $state['phase'];
        $index = strpos($state['phase'], 'scope_') === 0 ? $state['scope_index'] : $state['task_index'];
        $task = $state['tasks'][$index] ?? null;
        if ($task) {
            $p['department'] = $state['report']['departments'][$task['department']]['name'];
            $p['technology'] = $task['config']['name'];
            $p['host'] = $task['scope_hosts'][$state['host_index']]['name'] ?? '';
        }
        else { $p['host'] = ''; }
        if ($state['status'] === 'complete') { $p['percent'] = 100; return; }
        if (!isset($state['scope_frozen_at'])) {
            $p['percent'] = count($state['tasks']) ? 5 * $state['scope_index'] / count($state['tasks']) : 0;
            return;
        }
        $fraction = 0;
        if (isset($state['current_check'])) {
            $fraction = max(0, ($state['current_check']['cursor'] - $state['report']['from'])
                / ($state['report']['to'] - $state['report']['from']));
        }
        $p['percent'] = min(99, 5 + 90 * ($p['checks_total'] ? ($p['checks_done'] + $fraction) / $p['checks_total'] : 1));
    }

    private function query(array &$state, string $endpoint, array $options): array {
        $state['progress']['calls']++;
        try { $rows = API::$endpoint()->get($options); }
        catch (\Throwable $e) {
            throw new RuntimeException('Zabbix API query failed (' . $endpoint . '); calculation interrupted / Consulta à API Zabbix falhou (' . $endpoint . '); cálculo interrompido.');
        }
        if (!is_array($rows)) {
            throw new RuntimeException('Zabbix API returned no valid response (' . $endpoint . ') / API Zabbix retornou resposta inválida (' . $endpoint . ').');
        }
        return array_values($rows);
    }

    private function combine(array $series, string $mode, array $period, array $weights = []): array {
        $intervals = 0;
        foreach ($series as $timeline) { $intervals += count($timeline); }
        if ($intervals > self::MAX_INTERVALS) {
            throw new RuntimeException('Timeline complexity limit reached; narrow the scope / Limite de complexidade temporal atingido; reduza o escopo.');
        }
        if (count($series) === 1 && $series[0]) { return $series[0]; }
        $this->guard($intervals * 2048);
        return AvailabilityEngine::combine($series, $mode, $period['from'], $period['to'], $weights);
    }

    private function daily(array $series, array $period): array {
        $days = [];
        $day = (new DateTimeImmutable('@' . $period['from']))->setTimezone(new DateTimeZone($period['timezone']));
        while ($day->getTimestamp() < $period['to']) {
            $end = min($period['to'], $day->modify('+1 day')->getTimestamp());
            $days[] = ['day' => $day->format('Y-m-d'), 'summary' => AvailabilityEngine::summary($series, $day->getTimestamp(), $end)];
            $day = $day->modify('+1 day');
        }
        return $days;
    }

    private function guard(int $additionalBytes = 0): void {
        if ($additionalBytes > $this->memoryAvailable()) {
            throw new RuntimeException('Safe memory budget reached; narrow the scope / Limite seguro de memória atingido; reduza o escopo.');
        }
    }

    private function memoryAvailable(): int {
        $limit = self::MAX_MEMORY;
        if (preg_match('/^(\d+)\s*([kmg]?)$/iD', trim((string) ini_get('memory_limit')), $parts)) {
            $sizes = ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824];
            $setting = (float) $parts[1] * $sizes[strtolower($parts[2])];
            if ($setting > 0) { $limit = (int) min($limit, $setting); }
        }
        return $limit - memory_get_usage(true) - self::MEMORY_RESERVE;
    }
}
