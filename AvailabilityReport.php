<?php

namespace Modules\Governance;

use API;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Read-only history adapter. No trends fallback: hourly averages cannot reconstruct outages. */
final class AvailabilityReport {
    const MAX_HOSTS = 200;
    const MAX_ROWS = 3000000;
    const CHUNK_ROWS = 60000;
    const MAX_SECONDS = 18;
    const MAX_INTERVALS = 200000;
    const MAX_CACHE_INTERVALS = 25000;
    const MAX_MEMORY = 268435456;
    const MEMORY_RESERVE = 16777216;
    private $rows = 0;
    private $started;
    private $cache = [];
    private $cacheIntervals = 0;
    private $memoryLimit;

    public function build(array $config, string $month, ?int $now = null): array {
        if (!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $month)) {
            throw new RuntimeException('Invalid month / Mês inválido.');
        }
        $this->started = microtime(true);
        $this->rows = 0;
        $this->clearCache();
        $this->memoryLimit = self::memoryCeiling();
        $timezone = new DateTimeZone($config['timezone']);
        $start = new DateTimeImmutable($month . '-01 00:00:00', $timezone);
        $from = $start->getTimestamp();
        $now = $now ?? time();
        $to = min($start->modify('+1 month')->getTimestamp(), $now);
        if ($from >= $to) { throw new RuntimeException('Choose a past or current month / Selecione o mês atual ou anterior.'); }
        $groups = API::HostGroup()->get(['output' => ['groupid', 'name']]);
        if (!is_array($groups)) { throw new RuntimeException('Cannot read host groups / Não foi possível consultar os grupos.'); }
        $report = ['month' => $month, 'timezone' => $config['timezone'], 'from' => $from, 'to' => $to,
            'generated_at' => $now, 'partial' => $to < $start->modify('+1 month')->getTimestamp(),
            'departments' => [], 'rows' => 0, 'configuration' => $config];
        foreach ($config['departments'] as $department) {
            $techs = [];
            $series = [];
            $weights = [];
            foreach ($department['technologies'] as $technology) {
                $tech = $this->technology($technology, $groups, $from, $to, $timezone);
                $series[] = $tech['series'];
                $weights[] = $technology['weight'];
                unset($tech['series']);
                $techs[] = $tech;
            }
            $warnings = [];
            try { $combined = $this->combine($series, 'mean', $from, $to, $weights); }
            catch (\Exception $e) {
                $this->clearCache();
                unset($series);
                $combined = AvailabilityEngine::unknown($from, $to);
                $warnings[] = $e->getMessage();
                // Keep the failure visible in existing renderers that only display technology warnings.
                foreach ($techs as &$technologyResult) { $technologyResult['warnings'][] = $e->getMessage(); }
                unset($technologyResult);
            }
            $report['departments'][] = ['name' => $department['name'], 'target' => $department['target'],
                'summary' => AvailabilityEngine::summary($combined, $from, $to),
                'daily' => $this->daily($combined, $from, $to, $timezone), 'technologies' => $techs, 'warnings' => $warnings];
            unset($combined, $series);
        }
        $report['rows'] = $this->rows;
        $this->clearCache();
        return $report;
    }

    private function technology(array $tech, array $groups, int $from, int $to, DateTimeZone $timezone): array {
        $result = ['name' => $tech['name'], 'weight' => $tech['weight'], 'target' => $tech['target'],
            'mode' => $tech['mode'], 'groups' => [], 'hosts' => [], 'warnings' => []];
        try {
            $this->guard();
            $ids = [];
            foreach (AvailabilityConfig::groups($tech['groups']) as $token) {
                $found = false;
                foreach ($groups as $group) {
                    $name = mb_strtolower($group['name'], 'UTF-8');
                    $matches = ctype_digit($token) ? (string) $group['groupid'] === $token
                        : ($name === $token || strpos($name, $token . '/') === 0);
                    if ($matches) {
                        $ids[$group['groupid']] = $group['name'];
                        $found = true;
                    }
                }
                if (!$found) { throw new RuntimeException('Group not found / Grupo não encontrado: ' . $token); }
            }
            $result['groups'] = array_values($ids);
            $hosts = API::Host()->get(['output' => ['hostid', 'name', 'status'], 'groupids' => array_keys($ids),
                'sortfield' => 'name', 'limit' => self::MAX_HOSTS + 1]);
            if (!is_array($hosts) || !$hosts) { throw new RuntimeException('No hosts in the selected groups / Nenhum host nos grupos selecionados.'); }
            if (count($hosts) > self::MAX_HOSTS) { throw new RuntimeException('Limit: 200 hosts per technology / Limite: 200 hosts por tecnologia.'); }
            $keys = array_values(array_unique(array_column($tech['checks'], 'key')));
            $items = API::Item()->get(['output' => ['itemid', 'hostid', 'key_', 'value_type', 'status', 'delay', 'type'],
                'selectPreprocessing' => ['type', 'params'],
                'hostids' => array_column($hosts, 'hostid'), 'filter' => ['key_' => $keys], 'webitems' => true]);
            if (!is_array($items)) { throw new RuntimeException('Cannot read items / Não foi possível consultar os itens.'); }
            $index = [];
            foreach ($items as $item) { $index[$item['hostid']][$item['key_']] = $item; }
            $hostSeries = [];
            foreach ($hosts as $host) {
                $checks = [];
                $sources = [];
                $warnings = [];
                if ((int) $host['status'] !== 0) {
                    $warnings[] = 'Host currently disabled; historical data included / Host atualmente desabilitado; histórico incluído.';
                }
                foreach ($tech['checks'] as $check) {
                    $item = $index[$host['hostid']][$check['key']] ?? null;
                    $source = ['key' => $check['key'], 'itemid' => $item ? $item['itemid'] : null];
                    if (!$item || !in_array((int) $item['value_type'], [0, 3], true)) {
                        $warning = 'Missing or non-numeric item / Item ausente ou não numérico: ' . $check['key'];
                        $warnings[] = $warning;
                        $sources[] = $source + ['max_age' => null, 'freshness_mode' =>
                            (array_key_exists('max_age', $check) ? $check['max_age'] : ($tech['max_age'] ?? null)) === null ? 'auto' : 'manual',
                            'freshness_source' => 'unresolved', 'interval_seconds' => null, 'heartbeat_seconds' => null,
                            'warnings' => [$warning]];
                        $checks[] = AvailabilityEngine::unknown($from, $to);
                        continue;
                    }
                    if ((int) $item['status'] !== 0) {
                        $warnings[] = 'Item currently disabled / Item atualmente desabilitado: ' . $check['key'];
                    }
                    $manualAge = array_key_exists('max_age', $check) ? $check['max_age'] : ($tech['max_age'] ?? null);
                    $freshness = AvailabilityFreshness::resolve($item, $manualAge);
                    $sources[] = $source + $freshness;
                    foreach ($freshness['warnings'] as $warning) { $warnings[] = $check['key'] . ': ' . $warning; }
                    $checks[] = $freshness['max_age'] === null ? AvailabilityEngine::unknown($from, $to)
                        : $this->history($item, $check, $freshness['max_age'], $from, $to);
                }
                $hostTimeline = $this->combine($checks, 'any_down', $from, $to);
                $hostSeries[] = $hostTimeline;
                $result['hosts'][] = ['hostid' => $host['hostid'], 'name' => $host['name'], 'sources' => $sources,
                    'summary' => AvailabilityEngine::summary($hostTimeline, $from, $to), 'warnings' => $warnings];
            }
            $result['series'] = $this->combine($hostSeries, $tech['mode'], $from, $to);
        }
        catch (\Exception $e) {
            $this->clearCache();
            unset($hostSeries, $checks, $hostTimeline);
            $result['warnings'][] = $e->getMessage();
            // A partially fetched technology must never be reported as a complete result.
            $result['series'] = AvailabilityEngine::unknown($from, $to);
        }
        $result['summary'] = AvailabilityEngine::summary($result['series'], $from, $to);
        $result['daily'] = $this->daily($result['series'], $from, $to, $timezone);
        $exceptions = array_values(array_filter($result['series'], static function($i) { return $i[3] > 1e-8 || $i[4] > 1e-8; }));
        $result['interval_count'] = count($exceptions);
        $result['intervals'] = array_slice($exceptions, 0, 200);
        return $result;
    }

    private function guard(int $additionalBytes = 0): void {
        if ($this->rows >= self::MAX_ROWS || microtime(true) - $this->started > self::MAX_SECONDS) {
            throw new RuntimeException('History processing limit reached; narrow the scope / Limite de processamento do histórico atingido; reduza o escopo.');
        }
        if ($additionalBytes > $this->memoryAvailable()) {
            throw new RuntimeException('Safe memory budget reached; narrow the scope / Limite seguro de memória atingido; reduza o escopo.');
        }
    }

    private static function memoryCeiling(): int {
        $setting = trim((string) ini_get('memory_limit'));
        if (preg_match('/^(\d+)\s*([kmg]?)$/iD', $setting, $parts)) {
            $sizes = ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824];
            $limit = (float) $parts[1] * $sizes[strtolower($parts[2])];
            if ($limit > 0) { return (int) min(self::MAX_MEMORY, $limit); }
        }
        return self::MAX_MEMORY;
    }

    private function memoryAvailable(): int {
        return $this->memoryLimit - memory_get_usage(true) - self::MEMORY_RESERVE;
    }

    private function clearCache(): void {
        $this->cache = [];
        $this->cacheIntervals = 0;
    }

    private function combine(array $series, string $mode, int $from, int $to, array $weights = []): array {
        $intervals = 0;
        foreach ($series as $timeline) { $intervals += count($timeline); }
        if ($intervals > self::MAX_INTERVALS) {
            throw new RuntimeException('Timeline complexity limit reached; narrow the scope / Limite de complexidade temporal atingido; reduza o escopo.');
        }
        // A single already-normalized timeline needs no sweep or second in-memory copy.
        if (count($series) === 1 && $series[0]) { $this->guard(); return $series[0]; }
        // PHP arrays, sweep boundaries and the resulting intervals all require headroom.
        $this->guard($intervals * 2048);
        return AvailabilityEngine::combine($series, $mode, $from, $to, $weights);
    }

    private function history(array $item, array $check, int $age, int $from, int $to): array {
        $key = $item['itemid'] . ':' . $age . ':' . json_encode($check);
        if (isset($this->cache[$key])) { return $this->cache[$key]; }
        $series = [];
        $last = null;
        for ($begin = $from; $begin < $to; $begin = $end) {
            $this->guard();
            // Bound the API's allocation *before* fetching; a row-count check after an OOM is too late.
            $fetchLimit = min(self::CHUNK_ROWS, (int) floor($this->memoryAvailable() / 1536) - 1);
            if ($fetchLimit < 1) { throw new RuntimeException('Insufficient memory for history / Memória insuficiente para o histórico.'); }
            $this->guard(($fetchLimit + 1) * 1536);
            $end = min($to, $begin + 7 * 86400);
            $samples = API::History()->get(['output' => ['clock', 'ns', 'value'], 'history' => (int) $item['value_type'],
                'itemids' => [$item['itemid']], 'time_from' => $begin === $from ? $from - $age : $begin,
                'time_till' => $end - 1, 'sortfield' => 'clock', 'sortorder' => 'ASC', 'limit' => $fetchLimit + 1]);
            if (!is_array($samples)) { throw new RuntimeException('Cannot read item history / Não foi possível consultar o histórico do item.'); }
            $this->rows += count($samples);
            if (count($samples) > $fetchLimit || $this->rows > self::MAX_ROWS) {
                throw new RuntimeException('History sample or memory limit exceeded; no truncated result is used / Limite de amostras ou memória excedido; resultado parcial não utilizado.');
            }
            if ($last !== null) { $samples[] = $last; }
            $this->guard(count($samples) * 1024);
            foreach (AvailabilityEngine::samples($samples, $check, $age, $begin, $end) as $interval) {
                AvailabilityEngine::append($series, $interval);
            }
            if (count($series) > self::MAX_INTERVALS) {
                throw new RuntimeException('Timeline complexity limit reached; narrow the scope / Limite de complexidade temporal atingido; reduza o escopo.');
            }
            foreach ($samples as $sample) {
                if ($last === null || [(int) $sample['clock'], (int) ($sample['ns'] ?? 0)]
                        > [(int) $last['clock'], (int) ($last['ns'] ?? 0)]) { $last = $sample; }
            }
            unset($samples);
        }
        if ($this->cacheIntervals + count($series) <= self::MAX_CACHE_INTERVALS) {
            $this->cacheIntervals += count($series);
            $this->cache[$key] = $series;
        }
        return $series;
    }

    private function daily(array $series, int $from, int $to, DateTimeZone $timezone): array {
        $days = [];
        $day = (new DateTimeImmutable('@' . $from))->setTimezone($timezone);
        while ($day->getTimestamp() < $to) {
            $end = min($to, $day->modify('+1 day')->getTimestamp());
            $days[] = ['day' => $day->format('Y-m-d'),
                'summary' => AvailabilityEngine::summary($series, $day->getTimestamp(), $end)];
            $day = $day->modify('+1 day');
        }
        return $days;
    }
}
