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
    // Version of the resumable server-side state, not the exported report JSON.
    // v3 adds the conservative hourly-trend fallback. Older checkpoints must
    // restart instead of mixing detailed and reduced source semantics.
    const FORMAT = 3;
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
        $hasItems = false; $hasSla = false;
        foreach ($selected['departments'] as $node) {
            foreach ($node['technologies'] as $technology) {
                if (($technology['source'] ?? 'items') === 'sla') { $hasSla = true; }
                else { $hasItems = true; }
            }
        }
        $state = ['format' => self::FORMAT, 'status' => 'running', 'phase' => $hasItems ? 'groups' : 'scope_hosts',
            'source_config' => $config, 'department_filter' => $department,
            'started_at' => time(), 'working_seconds' => 0.0,
            'report' => ['month' => $month, 'timezone' => $config['timezone'], 'from' => $from, 'to' => $to,
                'generated_at' => $now, 'partial' => $to < $monthEnd, 'configuration' => $selected,
                'data_policy' => $config['data_policy'] ?? 'strict',
                'has_sla' => $hasSla, 'has_items' => $hasItems,
                'departments' => [], 'rows' => 0],
            'progress' => ['hosts_total' => 0, 'hosts_done' => 0, 'checks_total' => 0, 'checks_done' => 0,
                'slas_total' => 0, 'slas_done' => 0,
                'rows' => 0, 'calls' => 0, 'percent' => 0, 'stage' => $hasItems ? 'groups' : 'scope_hosts',
                'department' => '', 'technology' => '', 'host' => ''],
            'tasks' => [], 'scope_index' => 0, 'task_index' => 0, 'host_index' => 0, 'check_index' => 0,
            'department_index' => 0];
        foreach ($selected['departments'] as $d => $node) {
            $state['report']['departments'][] = ['name' => $node['name'], 'target' => $node['target'],
                'technologies' => [], 'warnings' => []];
            foreach ($node['technologies'] as $technology) {
                $source = $technology['source'] ?? 'items';
                if ($source === 'sla') { $state['progress']['slas_total']++; }
                $state['tasks'][] = ['department' => $d, 'config' => $technology,
                    'result' => ['name' => $technology['name'], 'weight' => $technology['weight'],
                        'target' => $technology['target'], 'source' => $source,
                        'mode' => $source === 'items' ? $technology['mode'] : null,
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
            case 'scope_sla': $this->scopeSla($state); break;
            case 'scope_sla_service': $this->scopeSlaService($state); break;
            case 'sla': $this->readSla($state); break;
            case 'sla_verify': $this->verifySla($state); break;
            case 'check': $this->startCheck($state); break;
            case 'history': $this->historyPage($state); break;
            case 'trend': $this->trendFallback($state); break;
            case 'host': $this->finishHost($state); break;
            case 'technology': $this->finishTechnology($state); break;
            case 'department': $this->finishDepartment($state); break;
            case 'finish': $this->finish($state); break;
            default: throw new RuntimeException('Invalid processing stage / Etapa de processamento inválida.');
        }
    }

    private function scopeHosts(array &$state): void {
        $task = &$state['tasks'][$state['scope_index']];
        if (($task['config']['source'] ?? 'items') === 'sla') {
            $state['phase'] = 'scope_sla';
            return;
        }
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
                    'seed_clock' => null, 'history_queried' => false,
                    'up_sample_count' => 0, 'down_sample_count' => 0, 'unknown_sample_count' => 0,
                    'data_source' => 'history', 'resolution_seconds' => 1,
                    'trend_fallback_attempted' => false,
                    'trend_row_count' => 0, 'trend_up_hour_count' => 0,
                    'trend_down_hour_count' => 0, 'trend_mixed_hour_count' => 0,
                    'trend_unknown_hour_count' => 0];
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
        if (($task['config']['source'] ?? 'items') === 'sla') {
            $state['phase'] = 'sla';
            return;
        }
        if ($state['host_index'] >= $task['result']['hosts_total']) { $state['phase'] = 'technology'; return; }
        if (!isset($state['current_host'])) { $state['current_host'] = ['series' => [], 'sources' => []]; }
        $check = $task['scope_hosts'][$state['host_index']]['checks'][$state['check_index']];
        $state['current_check'] = $check + ['last' => null, 'series' => [],
            'cursor' => max(0, $state['report']['from'] - ($check['source']['max_age'] ?? 0))];
        if ($check['source']['max_age'] === null) {
            $state['current_check']['series'] = AvailabilityEngine::unknown($state['report']['from'], $state['report']['to']);
            if (!$state['report']['partial'] && $check['source']['itemid'] !== null) {
                $state['current_check']['source']['trend_fallback_attempted'] = true;
                $state['phase'] = 'trend';
            }
            else { $this->finishCheck($state); }
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
        $current['source']['history_queried'] = true;
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
                $sampleState = AvailabilityEngine::state($sample['value'], $current['rule']);
                $current['source'][$sampleState === 1 ? 'up_sample_count'
                    : ($sampleState === 0 ? 'down_sample_count' : 'unknown_sample_count')]++;
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
        if ($end >= $to) {
            $historySummary = AvailabilityEngine::summary($current['series'], $from, $to);
            if (!$state['report']['partial'] && $historySummary['coverage'] < 100.0) {
                $current['source']['trend_fallback_attempted'] = true;
                $state['phase'] = 'trend';
            }
            else { $this->finishCheck($state); }
        }
    }

    /**
     * Trends retain only hourly min/max/average/count. For binary states a mixed
     * hour cannot recover outage duration or overlap, so it is conservatively
     * classified as fully DOWN. The complete trend month replaces history only
     * when it has strictly more known coverage; sources are never spliced.
     */
    private function trendFallback(array &$state): void {
        $current = &$state['current_check'];
        $from = $state['report']['from'];
        $to = $state['report']['to'];
        try {
            $rows = $this->query($state, 'Trend', [
                'output' => ['itemid', 'clock', 'num', 'value_min', 'value_avg', 'value_max'],
                'itemids' => [$current['source']['itemid']],
                // Include the UTC trend hour overlapping a non-hour-aligned report boundary.
                'time_from' => max(0, $from - 3599), 'time_till' => $to - 1, 'limit' => 1001
            ]);
        }
        catch (RuntimeException $e) {
            $current['source']['warnings'][] = 'Trend fallback unavailable; detailed history result retained / '
                . 'Fallback por trends indisponível; resultado do histórico detalhado mantido. Detalhe: '
                . $e->getMessage();
            $this->finishCheck($state);
            return;
        }
        $state['progress']['rows'] += count($rows);
        if ($state['progress']['rows'] > self::MAX_ROWS) {
            throw new RuntimeException('Calculation exceeds 20 million source rows; narrow the scope / Cálculo excede 20 milhões de linhas de origem; reduza o escopo.');
        }
        if (count($rows) > 1000) {
            $current['source']['warnings'][] = 'Trend row limit exceeded; detailed history result retained / Limite de linhas de trends excedido; resultado do histórico detalhado mantido.';
            $this->finishCheck($state);
            return;
        }
        usort($rows, static function(array $a, array $b): int {
            return ((int) ($a['clock'] ?? 0)) <=> ((int) ($b['clock'] ?? 0));
        });
        $series = [];
        $cursor = $from;
        $previousClock = null;
        foreach ($rows as $row) {
            if (!isset($row['clock'], $row['num']) || !is_numeric($row['clock']) || !is_numeric($row['num'])
                    || (int) $row['num'] < 1 || !array_key_exists('value_min', $row)
                    || !array_key_exists('value_max', $row) || !is_numeric($row['value_min'])
                    || !is_numeric($row['value_max'])) {
                $current['source']['warnings'][] = 'Invalid trend row; detailed history result retained / Linha de trend inválida; resultado do histórico detalhado mantido.';
                $this->finishCheck($state);
                return;
            }
            $clock = (int) $row['clock'];
            if ($previousClock !== null && $clock <= $previousClock) {
                $current['source']['warnings'][] = 'Duplicate or unordered trend hour; detailed history result retained / Hora de trend duplicada ou desordenada; resultado do histórico detalhado mantido.';
                $this->finishCheck($state);
                return;
            }
            $previousClock = $clock;
            $start = max($from, $clock);
            $end = min($to, $clock + 3600);
            if ($end <= $start) { continue; }
            if ($start < $cursor) {
                $current['source']['warnings'][] = 'Overlapping trend hours; detailed history result retained / Horas de trend sobrepostas; resultado do histórico detalhado mantido.';
                $this->finishCheck($state);
                return;
            }
            AvailabilityEngine::append($series, [$cursor, $start, 0.0, 0.0, 1.0]);
            [$trendState, $mixed] = self::trendState($row, $current['rule']);
            $current['source']['trend_row_count']++;
            $current['source'][$mixed ? 'trend_mixed_hour_count'
                : ($trendState === 1 ? 'trend_up_hour_count'
                    : ($trendState === 0 ? 'trend_down_hour_count' : 'trend_unknown_hour_count'))]++;
            AvailabilityEngine::append($series, [$start, $end, $trendState === 1 ? 1.0 : 0.0,
                $trendState === 0 ? 1.0 : 0.0, $trendState === -1 ? 1.0 : 0.0]);
            $cursor = $end;
        }
        AvailabilityEngine::append($series, [$cursor, $to, 0.0, 0.0, 1.0]);
        $historySummary = AvailabilityEngine::summary($current['series'], $from, $to);
        $trendSummary = AvailabilityEngine::summary($series, $from, $to);
        if ($current['source']['trend_row_count'] > 0
                && $trendSummary['coverage'] > $historySummary['coverage'] + 0.0000001) {
            $current['series'] = $series;
            $current['source']['data_source'] = 'trends_conservative';
            $current['source']['resolution_seconds'] = 3600;
            $current['source']['warnings'][] = 'Hourly trends replaced incomplete detailed history; every mixed hour is fully DOWN / Trends horárias substituíram o histórico detalhado incompleto; cada hora mista conta integralmente como DOWN.';
        }
        $this->finishCheck($state);
    }

    /** Return [state, mixed]. A mixed hour with any confirmed DOWN endpoint is DOWN. */
    private static function trendState(array $row, array $rule): array {
        $minimum = (float) $row['value_min'];
        $maximum = (float) $row['value_max'];
        $minimumState = AvailabilityEngine::state($minimum, $rule);
        $maximumState = AvailabilityEngine::state($maximum, $rule);
        if ($minimum == $maximum) { return [$minimumState, false]; }
        if ($minimumState === 0 || $maximumState === 0) { return [0, true]; }
        return [-1, true];
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
        $result = ['hostid' => (string) $host['hostid'], 'name' => $host['name'],
            'sources' => $state['current_host']['sources'], 'warnings' => $host['warnings'],
            'summary' => AvailabilityEngine::summary($series, $state['report']['from'], $state['report']['to'])];
        // Thirty-one compact summaries are enough for a faithful host chart. Never
        // expose the raw timeline or create one ECharts instance during page load.
        // Host chart points are positional [score, coverage]. Day labels come from
        // the technology calendar, avoiding repeated date/field names in checkpoints.
        $result['daily'] = array_map(static function(array $day): array {
            return [$day['score'], $day['coverage']];
        }, $this->scoredDaily($series, $state['report'], self::observed($state)));
        if (self::observed($state)) {
            // A missing required check never proves this host UP. Only the consolidation
            // across hosts may ignore an unknown host in the explicitly selected policy.
            $result['observation'] = AvailabilityEngine::weightedIndicators([
                ['score' => $result['summary']['observed'], 'coverage' => $result['summary']['coverage']]
            ]);
        }
        $task['result']['hosts'][] = $result;
        unset($state['current_host'], $task['scope_hosts'][$state['host_index']]);
        $state['progress']['hosts_done']++;
        $state['host_index']++;
        $state['check_index'] = 0;
        $state['phase'] = 'check';
    }

    private function finishTechnology(array &$state): void {
        $task = &$state['tasks'][$state['task_index']];
        if (($task['config']['source'] ?? 'items') === 'sla') {
            $this->finishSlaTechnology($state);
            return;
        }
        $series = $this->combine($task['host_series'], $task['config']['mode'], $state['report']);
        $task['result']['summary'] = AvailabilityEngine::summary($series, $state['report']['from'], $state['report']['to']);
        $task['result']['daily'] = $this->scoredDaily($series, $state['report'], false);
        $exceptions = array_values(array_filter($series, static function($i) { return $i[3] > 0 || $i[4] > 0; }));
        $task['result']['interval_count'] = count($exceptions);
        $task['result']['intervals'] = array_slice($exceptions, 0, 200);
        $task['result']['daily_available'] = true;
        $task['result']['eligible_for_aggregation'] = true;
        $task['result']['basis_seconds'] = $state['report']['to'] - $state['report']['from'];
        $diagnostics = ['hosts_with_data' => 0, 'hosts_without_data' => 0,
            'checks_total' => 0, 'checks_not_queried' => 0, 'checks_without_known_time' => 0];
        foreach ($task['result']['hosts'] as $host) {
            $diagnostics[$host['summary']['up'] + $host['summary']['down'] > 0
                ? 'hosts_with_data' : 'hosts_without_data']++;
            foreach ($host['sources'] as $source) {
                $diagnostics['checks_total']++;
                if (isset($source['history_queried']) && !$source['history_queried']) { $diagnostics['checks_not_queried']++; }
                if ($source['summary']['up'] + $source['summary']['down'] <= 0) { $diagnostics['checks_without_known_time']++; }
            }
        }
        $task['result']['data_quality'] = $diagnostics;
        if (self::observed($state)) {
            $indicators = [];
            foreach ($task['result']['hosts'] as $host) { $indicators[] = $host['observation']; }
            $observation = AvailabilityEngine::weightedIndicators($indicators);
            $observedSeries = $task['config']['mode'] === 'any_down'
                ? $this->combine($task['host_series'], 'any_down_observed', $state['report']) : $series;
            $this->observationTimeline($observation, $observedSeries, $state['report']);
            $observation['daily'] = $this->aggregateObservedDaily($observation['daily'],
                array_column($task['result']['hosts'], 'daily'), [], $task['config']['mode'] === 'any_down');
            if ($task['config']['mode'] === 'any_down') {
                $observation['score'] = $observation['summary']['observed'];
            }
            // In mean mode each host keeps one vote, independent of its history coverage.
            // Coverage always includes ALL scoped hosts, not just the participating cohort.
            $observation['aggregation'] = $task['config']['mode'] === 'any_down'
                ? 'any_down_observed' : 'mean_host_indicators';
            $task['result']['observation'] = $observation;
            $task['observed_series'] = $observedSeries;
        }
        $state['report']['departments'][$task['department']]['technologies'][] = $task['result'];
        $task['series'] = $series;
        unset($task['scope_hosts'], $task['host_series'], $task['result']);
        $state['task_index']++;
        $state['host_index'] = 0;
        $state['check_index'] = 0;
        $state['phase'] = $state['task_index'] < count($state['tasks']) ? 'check' : 'department';
    }

    private function finishDepartment(array &$state): void {
        $node = &$state['report']['departments'][$state['department_index']];
        $hasSla = false;
        foreach ($node['technologies'] as $technology) {
            if (($technology['source'] ?? 'items') === 'sla') { $hasSla = true; break; }
        }
        if ($hasSla) {
            $this->finishMixedDepartment($state);
            return;
        }
        $series = []; $weights = [];
        foreach ($state['tasks'] as $task) {
            if ($task['department'] === $state['department_index']) {
                $series[] = $task['series'];
                $weights[] = $task['config']['weight'];
            }
        }
        $combined = $this->combine($series, 'mean', $state['report'], $weights);
        $node['summary'] = AvailabilityEngine::summary($combined, $state['report']['from'], $state['report']['to']);
        $node['daily'] = $this->scoredDaily($combined, $state['report'], false);
        $node['daily_available'] = true;
        $node['aggregation_compatible'] = true;
        if (self::observed($state)) {
            $indicators = array_column($node['technologies'], 'observation');
            $node['observation'] = AvailabilityEngine::weightedIndicators($indicators, $weights);
            $observedSeries = [];
            foreach ($state['tasks'] as $task) {
                if ($task['department'] === $state['department_index']) { $observedSeries[] = $task['observed_series']; }
            }
            // Durations are equivalent time across the full configured scope. They are
            // descriptive, not the denominator of the weighted observed percentages.
            $observedCombined = $this->combine($observedSeries, 'mean', $state['report'], $weights);
            $this->observationTimeline($node['observation'], $observedCombined, $state['report'], false);
            $technologyDaily = [];
            foreach ($node['technologies'] as $technology) { $technologyDaily[] = $technology['observation']['daily']; }
            $node['observation']['daily'] = $this->aggregateObservedDaily(
                $node['observation']['daily'], $technologyDaily, $weights, false);
            $node['observation']['aggregation'] = 'weighted_technology_indicators';
        }
        foreach ($state['tasks'] as &$task) {
            if ($task['department'] === $state['department_index']) { unset($task['series'], $task['observed_series']); }
        }
        unset($task);
        $state['department_index']++;
        if ($state['department_index'] >= count($state['report']['departments'])) { $state['phase'] = 'finish'; }
    }

    private function finish(array &$state): void {
        if ($state['progress']['hosts_done'] !== $state['progress']['hosts_total']
                || $state['progress']['checks_done'] !== $state['progress']['checks_total']
                || ($state['progress']['slas_done'] ?? 0) !== ($state['progress']['slas_total'] ?? 0)) {
            throw new RuntimeException('Scope not fully evaluated; no final indicator available / Escopo não totalmente avaliado; indicador final indisponível.');
        }
        $state['status'] = 'complete';
        $state['phase'] = 'complete';
        $state['report']['rows'] = $state['progress']['rows'];
        $method = empty($state['report']['has_sla']) ? 'checkpointed-items'
            : (empty($state['report']['has_items']) ? 'checkpointed-sla' : 'checkpointed-items-and-sla');
        $state['report']['processing'] = ['method' => $method, 'version' => '1.13.0',
            'data_policy' => $state['report']['data_policy'] ?? 'strict',
            'started_at' => $state['started_at'], 'completed_at' => time(),
            'elapsed_seconds' => max(0, time() - $state['started_at']), 'scope_frozen_at' => $state['scope_frozen_at'],
            'hosts_total' => $state['progress']['hosts_total'], 'hosts_done' => $state['progress']['hosts_done'],
            'checks_total' => $state['progress']['checks_total'], 'checks_done' => $state['progress']['checks_done'],
            'slas_total' => $state['progress']['slas_total'] ?? 0, 'slas_done' => $state['progress']['slas_done'] ?? 0,
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
        $total = $p['checks_total'] + ($p['slas_total'] ?? 0);
        $done = $p['checks_done'] + ($p['slas_done'] ?? 0);
        $p['percent'] = min(99, 5 + 90 * ($total ? ($done + $fraction) / $total : 1));
    }

    private function query(array &$state, string $endpoint, array $options, string $method = 'get'): array {
        $state['progress']['calls']++;
        try { $rows = API::$endpoint()->$method($options); }
        catch (\Throwable $e) {
            throw new RuntimeException('Zabbix API query failed (' . $endpoint . '); calculation interrupted / Consulta à API Zabbix falhou (' . $endpoint . '); cálculo interrompido.');
        }
        if (!is_array($rows)) {
            throw new RuntimeException('Zabbix API returned no valid response (' . $endpoint . ') / API Zabbix retornou resposta inválida (' . $endpoint . ').');
        }
        // getSli returns an object-like array: periods, serviceids and sli must retain their keys.
        return $method === 'getSli' ? $rows : array_values($rows);
    }

    private static function slaOptions(array $technology): array {
        return ['slaids' => [$technology['slaid']],
            'output' => ['slaid', 'name', 'period', 'slo', 'effective_date', 'timezone', 'status'],
            'selectSchedule' => ['period_from', 'period_to'],
            'selectExcludedDowntimes' => ['name', 'period_from', 'period_to'],
            'selectServiceTags' => ['tag', 'operator', 'value']];
    }

    private function scopeSla(array &$state): void {
        $task = &$state['tasks'][$state['scope_index']];
        $task['sla_definition'] = $this->query($state, 'Sla', self::slaOptions($task['config']));
        $state['phase'] = 'scope_sla_service';
    }

    private function scopeSlaService(array &$state): void {
        $task = &$state['tasks'][$state['scope_index']];
        $services = $this->query($state, 'Service', [
            'serviceids' => [$task['config']['serviceid']], 'slaids' => [$task['config']['slaid']],
            'output' => ['serviceid', 'name', 'created_at']]);
        // This is exactly the resolver used by CSla in Zabbix 6.0; user/profile timezone is not equivalent.
        $systemTimezone = class_exists('CTimezoneHelper') ? \CTimezoneHelper::getSystemTimezone() : null;
        $task['sla_prepared'] = AvailabilitySla::prepare($task['config'], $state['report'],
            $task['sla_definition'], $services, $systemTimezone);
        $this->nextScope($state);
    }

    private function readSla(array &$state): void {
        $task = &$state['tasks'][$state['task_index']];
        if (!$task['sla_prepared']['ready']) {
            $task['sla_result'] = AvailabilitySla::interpret($task['sla_prepared'], null);
            $state['phase'] = 'technology';
            return;
        }
        $task['sla_response'] = $this->query($state, 'Sla', $task['sla_prepared']['request'], 'getSli');
        $state['phase'] = 'sla_verify';
    }

    private function verifySla(array &$state): void {
        $task = &$state['tasks'][$state['task_index']];
        $current = $this->query($state, 'Sla', self::slaOptions($task['config']));
        if (self::canonical($current) !== self::canonical($task['sla_definition'])) {
            throw new RuntimeException('The SLA definition changed during calculation; start again / A definição do SLA mudou durante o cálculo; inicie novamente.');
        }
        $task['sla_result'] = AvailabilitySla::interpret($task['sla_prepared'], $task['sla_response']);
        if (isset($task['sla_result']['processing_error'])) {
            throw new RuntimeException($task['sla_result']['processing_error']);
        }
        $task['sla_result']['metadata']['queried_at'] = time();
        $state['phase'] = 'technology';
    }

    private static function canonical(array $value): string {
        $normalize = static function(array $node) use (&$normalize): array {
            foreach ($node as &$child) { if (is_array($child)) { $child = $normalize($child); } }
            unset($child);
            if (array_keys($node) === range(0, count($node) - 1)) {
                usort($node, static function($a, $b) { return strcmp(json_encode($a), json_encode($b)); });
            }
            else { ksort($node); }
            return $node;
        };
        return json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function finishSlaTechnology(array &$state): void {
        $task = &$state['tasks'][$state['task_index']];
        $result = $task['sla_result'];
        $task['result']['summary'] = $result['summary'];
        $task['result']['native_sla'] = $result['metadata'];
        $task['result']['eligible_for_aggregation'] = $result['eligible_for_aggregation'];
        $task['result']['warnings'] = $result['warnings'];
        $task['result']['daily_available'] = false;
        $task['result']['daily'] = [];
        $task['result']['interval_count'] = null;
        $task['result']['intervals'] = [];
        $task['result']['basis_seconds'] = $result['metadata']['basis_seconds'] ?? null;
        $state['report']['departments'][$task['department']]['technologies'][] = $task['result'];
        unset($task['scope_hosts'], $task['host_series'], $task['result'], $task['sla_prepared'],
            $task['sla_response'], $task['sla_definition'], $task['sla_result']);
        $state['progress']['slas_done']++;
        $state['task_index']++;
        $state['host_index'] = 0;
        $state['check_index'] = 0;
        $state['phase'] = $state['task_index'] < count($state['tasks']) ? 'check' : 'department';
    }

    private function finishMixedDepartment(array &$state): void {
        $node = &$state['report']['departments'][$state['department_index']];
        $key = null; $basis = null; $compatible = true; $summaries = []; $weights = [];
        foreach ($node['technologies'] as $technology) {
            $native = ($technology['source'] ?? 'items') === 'sla';
            $currentKey = $native ? ($technology['native_sla']['calendar_key'] ?? null)
                : AvailabilitySla::calendarKey($state['report']['from'], $state['report']['to']);
            $currentBasis = $technology['basis_seconds'] ?? null;
            if (empty($technology['eligible_for_aggregation']) || !is_string($currentKey)
                    || !is_numeric($currentBasis) || $currentBasis <= 0) { $compatible = false; }
            if ($key !== null && ($key !== $currentKey || (float) $basis !== (float) $currentBasis)) { $compatible = false; }
            $key = $currentKey; $basis = $currentBasis;
            $summaries[] = $technology['summary'];
            $weights[] = $technology['weight'];
        }
        $node['aggregation_compatible'] = $compatible;
        $node['daily_available'] = false;
        $node['daily'] = [];
        $node['basis_seconds'] = $compatible ? $basis : null;
        if ($compatible) {
            $node['summary'] = AvailabilityEngine::weightedSummaries($summaries, $weights, (float) $basis);
            if (self::observed($state)) {
                $indicators = []; $observedSummaries = [];
                foreach ($node['technologies'] as $technology) {
                    $indicators[] = $technology['observation'] ?? [
                        'score' => $technology['summary']['score'], 'coverage' => $technology['summary']['coverage']
                    ];
                    $observedSummaries[] = $technology['observation']['summary'] ?? $technology['summary'];
                }
                $node['observation'] = AvailabilityEngine::weightedIndicators($indicators, $weights);
                $node['observation']['summary'] = AvailabilityEngine::weightedSummaries($observedSummaries, $weights, (float) $basis);
                $node['observation']['temporal_coverage'] = $node['observation']['summary']['coverage'];
                $node['observation']['daily'] = [];
                $node['observation']['aggregation'] = 'weighted_technology_indicators';
            }
        }
        else {
            $node['summary'] = array_fill_keys(['up', 'down', 'unknown', 'score', 'observed', 'coverage', 'lower', 'upper'], null);
            $node['warnings'][] = 'Department index not calculated: every source must cover the same report period, schedule and exclusions. Check the SLA details and align the report timezone; individual results are preserved / Índice departamental não calculado: todas as fontes devem cobrir o mesmo período, calendário e exclusões. Confira os detalhes dos SLAs e alinhe o fuso do relatório; os resultados individuais foram preservados.';
        }
        foreach ($state['tasks'] as &$task) {
            if ($task['department'] === $state['department_index']) { unset($task['series'], $task['observed_series']); }
        }
        unset($task);
        $state['department_index']++;
        if ($state['department_index'] >= count($state['report']['departments'])) { $state['phase'] = 'finish'; }
    }

    private static function observed(array $state): bool {
        return ($state['report']['data_policy'] ?? 'strict') === 'observed';
    }

    /** Keep evidence durations separate from averages of observed percentages. */
    private function observationTimeline(array &$observation, array $series, array $period, bool $includeIntervals = true): void {
        $observation['summary'] = AvailabilityEngine::summary($series, $period['from'], $period['to']);
        $observation['temporal_coverage'] = $observation['summary']['coverage'];
        $observation['daily'] = $this->scoredDaily($series, $period, true);
        $observation['evidence_from'] = null;
        $observation['evidence_to'] = null;
        foreach ($series as $interval) {
            if ($interval[2] > 0 || $interval[3] > 0) {
                if ($observation['evidence_from'] === null) { $observation['evidence_from'] = $interval[0]; }
                $observation['evidence_to'] = $interval[1];
            }
        }
        if ($includeIntervals) {
            $exceptions = array_values(array_filter($series, static function($i) { return $i[3] > 0 || $i[4] > 0; }));
            $observation['interval_count'] = count($exceptions);
            $observation['intervals'] = array_slice($exceptions, 0, 200);
        }
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

    private function scoredDaily(array $series, array $period, bool $observed): array {
        $days = $this->daily($series, $period);
        foreach ($days as &$day) {
            $indicator = AvailabilityEngine::weightedIndicators([[
                'score' => $observed ? $day['summary']['observed'] : $day['summary']['score'],
                'coverage' => $day['summary']['coverage']
            ]]);
            $day += $indicator;
        }
        unset($day);
        return $days;
    }

    /**
     * Daily charts use the same hierarchy as the monthly indicator. Pooling child
     * durations would give extra weight to a host/technology merely because it has
     * more history. The descriptive timeline remains in each day's summary.
     */
    private function aggregateObservedDaily(array $timeline, array $children, array $weights, bool $anyDown): array {
        foreach ($timeline as $index => &$day) {
            $indicators = [];
            foreach ($children as $child) {
                $childDay = $child[$index] ?? null;
                $compact = is_array($childDay) && array_keys($childDay) === [0, 1];
                if (!is_array($childDay) || !$compact && ($childDay['day'] ?? null) !== $day['day']) {
                    $indicators[] = ['score' => null, 'coverage' => 0.0];
                }
                else {
                    $indicators[] = ['score' => $compact ? $childDay[0] : ($childDay['score'] ?? null),
                        'coverage' => $compact ? $childDay[1] : ($childDay['coverage'] ?? 0.0)];
                }
            }
            $indicator = AvailabilityEngine::weightedIndicators($indicators, $weights);
            if ($anyDown) { $indicator['score'] = $day['summary']['observed']; }
            foreach ($indicator as $field => $value) { $day[$field] = $value; }
        }
        unset($day);
        return $timeline;
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
