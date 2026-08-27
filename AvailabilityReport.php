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
    private $rows = 0;
    private $started;
    private $cache = [];

    public function build(array $config, string $month, ?int $now = null): array {
        if (!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $month)) {
            throw new RuntimeException('Invalid month / Mês inválido.');
        }
        $this->started = microtime(true);
        $this->rows = 0;
        $this->cache = [];
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
            $combined = AvailabilityEngine::combine($series, 'mean', $from, $to, $weights);
            $report['departments'][] = ['name' => $department['name'], 'target' => $department['target'],
                'summary' => AvailabilityEngine::summary($combined, $from, $to),
                'daily' => $this->daily($combined, $from, $to, $timezone), 'technologies' => $techs];
        }
        $report['rows'] = $this->rows;
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
            $items = API::Item()->get(['output' => ['itemid', 'hostid', 'key_', 'value_type', 'status'],
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
                    $sources[] = ['key' => $check['key'], 'itemid' => $item ? $item['itemid'] : null];
                    if (!$item || !in_array((int) $item['value_type'], [0, 3], true)) {
                        $warnings[] = 'Missing or non-numeric item / Item ausente ou não numérico: ' . $check['key'];
                        $checks[] = AvailabilityEngine::unknown($from, $to);
                        continue;
                    }
                    if ((int) $item['status'] !== 0) {
                        $warnings[] = 'Item currently disabled / Item atualmente desabilitado: ' . $check['key'];
                    }
                    $checks[] = $this->history($item, $check, $tech['max_age'], $from, $to);
                }
                $hostTimeline = AvailabilityEngine::combine($checks, 'any_down', $from, $to);
                $hostSeries[] = $hostTimeline;
                $result['hosts'][] = ['hostid' => $host['hostid'], 'name' => $host['name'], 'sources' => $sources,
                    'summary' => AvailabilityEngine::summary($hostTimeline, $from, $to), 'warnings' => $warnings];
            }
            $result['series'] = AvailabilityEngine::combine($hostSeries, $tech['mode'], $from, $to);
        }
        catch (\Exception $e) {
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

    private function guard(): void {
        if ($this->rows >= self::MAX_ROWS || microtime(true) - $this->started > self::MAX_SECONDS) {
            throw new RuntimeException('History processing limit reached; narrow the scope / Limite de processamento do histórico atingido; reduza o escopo.');
        }
    }

    private function history(array $item, array $check, int $age, int $from, int $to): array {
        $key = $item['itemid'] . ':' . $age . ':' . json_encode($check);
        if (isset($this->cache[$key])) { return $this->cache[$key]; }
        $series = [];
        $last = null;
        for ($begin = $from; $begin < $to; $begin = $end) {
            $this->guard();
            $end = min($to, $begin + 7 * 86400);
            $samples = API::History()->get(['output' => ['clock', 'ns', 'value'], 'history' => (int) $item['value_type'],
                'itemids' => [$item['itemid']], 'time_from' => $begin === $from ? $from - $age : $begin,
                'time_till' => $end - 1, 'sortfield' => 'clock', 'sortorder' => 'ASC', 'limit' => self::CHUNK_ROWS + 1]);
            if (!is_array($samples)) { throw new RuntimeException('Cannot read item history / Não foi possível consultar o histórico do item.'); }
            $this->rows += count($samples);
            if (count($samples) > self::CHUNK_ROWS || $this->rows > self::MAX_ROWS) {
                throw new RuntimeException('History sample limit exceeded; no truncated result is used / Limite de amostras excedido; resultado parcial não utilizado.');
            }
            if ($last !== null) { $samples[] = $last; }
            foreach (AvailabilityEngine::samples($samples, $check, $age, $begin, $end) as $interval) {
                AvailabilityEngine::append($series, $interval);
            }
            foreach ($samples as $sample) {
                if ($last === null || [(int) $sample['clock'], (int) ($sample['ns'] ?? 0)]
                        > [(int) $last['clock'], (int) ($last['ns'] ?? 0)]) { $last = $sample; }
            }
            unset($samples);
        }
        return $this->cache[$key] = $series;
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
