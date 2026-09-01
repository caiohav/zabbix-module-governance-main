<?php
// php -d extension=mbstring tests/availability-calculation.php
require dirname(__DIR__) . '/AvailabilityEngine.php';
require dirname(__DIR__) . '/AvailabilityConfig.php';
require dirname(__DIR__) . '/AvailabilityFreshness.php';
require dirname(__DIR__) . '/AvailabilityCalculation.php';

use Modules\Governance\AvailabilityEngine as Engine;
use Modules\Governance\AvailabilityCalculation as Calculation;

$assertions = 0;
function verify($condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
}
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (RuntimeException $e) { verify(true, $message); return; }
    verify(false, $message);
}
function equalSummary(array $actual, array $expected, string $label): void {
    foreach ($expected as $key => $value) {
        verify($value === null ? $actual[$key] === null
            : is_numeric($actual[$key]) && abs($actual[$key] - $value) < 1e-7,
            $label . '.' . $key . ': ' . json_encode([$actual[$key], $value]));
    }
}
function checkpoint(array $state): array {
    $json = json_encode($state);
    verify(is_string($json), 'checkpoint is serializable');
    return json_decode($json, true);
}
function finishCalculation(array $state, int $operations = 4): array {
    $runner = new Calculation();
    $requests = 0;
    while ($state['status'] === 'running' && ++$requests < 10000) {
        $previous = $state['progress'];
        $state = checkpoint($runner->advance($state, $operations));
        verify($state['progress']['hosts_done'] >= $previous['hosts_done'], 'host progress monotonic');
        verify($state['progress']['checks_done'] >= $previous['checks_done'], 'check progress monotonic');
        verify($state['progress']['percent'] >= $previous['percent'], 'percentage monotonic');
        verify($state['status'] === 'complete' || $state['progress']['percent'] < 100, 'no premature 100%');
    }
    verify($requests < 10000, 'calculation terminates');
    return $state;
}

class API {
    public static $groups = [];
    public static $hosts = [];
    public static $items = [];
    public static $history = [];
    public static $trends = [];
    public static $calls = [];
    public static $failItem = null;
    public static function __callStatic($name, $arguments) { return new CalculationEndpoint($name); }
}
class CalculationEndpoint {
    private $name;
    public function __construct(string $name) { $this->name = $name; }
    public function get(array $options): array {
        API::$calls[] = [$this->name, $options];
        if ($this->name === 'HostGroup') { return API::$groups; }
        if ($this->name === 'Host') {
            $hosts = array_values(array_filter(API::$hosts, static function($host) use ($options) {
                return (bool) array_intersect($host['groups'], $options['groupids']);
            }));
            usort($hosts, static function($a, $b) { return strcmp($a['name'], $b['name']); });
            return array_slice($hosts, 0, $options['limit']);
        }
        if ($this->name === 'Item') {
            return array_values(array_filter(API::$items, static function($item) use ($options) {
                return in_array($item['hostid'], $options['hostids']) && in_array($item['key_'], $options['filter']['key_']);
            }));
        }
        verify(in_array($this->name, ['History', 'Trend'], true), 'known endpoint');
        if ($this->name === 'Trend') {
            verify(count($options['itemids']) === 1 && $options['limit'] === 1001, 'bounded trend query per item');
            $id = $options['itemids'][0];
            $rows = array_values(array_filter(API::$trends[$id] ?? [], static function($row) use ($options) {
                return $row['clock'] >= $options['time_from'] && $row['clock'] <= $options['time_till'];
            }));
            // trend.get does not promise an order; exercise the runner's own sorting.
            return array_slice(array_reverse($rows), 0, $options['limit']);
        }
        verify(count($options['itemids']) === 1, 'history page scoped to one item');
        verify($options['limit'] <= Calculation::PAGE_ROWS + 1, 'bounded page');
        verify($options['sortfield'] === 'clock', 'only a supported Zabbix6 history sort field');
        $id = $options['itemids'][0];
        if ($id === API::$failItem) { throw new RuntimeException('Pretend database outage: do not leak this text.'); }
        $rows = array_values(array_filter(API::$history[$id] ?? [], static function($row) use ($options) {
            return $row['clock'] >= $options['time_from'] && $row['clock'] <= $options['time_till'];
        }));
        // Zabbix has no ns sort support: reverse ties intentionally, including pagination ties.
        usort($rows, static function($a, $b) {
            return $a['clock'] === $b['clock'] ? ($b['ns'] <=> $a['ns']) : ($a['clock'] <=> $b['clock']);
        });
        return array_slice($rows, 0, $options['limit']);
    }
}
function fixture(int $hostCount = 2): void {
    API::$calls = []; API::$items = []; API::$history = []; API::$trends = []; API::$hosts = []; API::$failItem = null;
    API::$groups = [['groupid' => '1', 'name' => 'Equipes'], ['groupid' => '2', 'name' => 'Equipes/Banco'],
        ['groupid' => '3', 'name' => 'Equipes externas']];
    for ($i = 1; $i <= $hostCount; $i++) {
        API::$hosts[] = ['hostid' => (string) $i, 'name' => sprintf('Host %03d', $i), 'status' => 0, 'groups' => ['2']];
        foreach (['ping', 'service'] as $key) {
            API::$items[] = ['itemid' => $i . '-' . $key, 'hostid' => (string) $i, 'key_' => $key,
                'value_type' => '3', 'status' => '0', 'delay' => '1m', 'type' => '3', 'preprocessing' => []];
        }
    }
}
function configuration(string $mode = 'any_down', int $age = 180): array {
    return ['timezone' => 'UTC', 'departments' => [['name' => 'Banco', 'target' => 99.9,
        'technologies' => [['name' => 'PostgreSQL', 'target' => 99.9, 'weight' => 4, 'mode' => $mode, 'groups' => 'Equipes',
            'checks' => [['key' => 'ping', 'max_age' => $age, 'up' => ['op' => 'eq', 'a' => 1], 'down' => null],
                ['key' => 'service', 'max_age' => $age, 'up' => ['op' => 'eq', 'a' => 1], 'down' => null]]]]]]];
}
function sample(int $clock, $value, int $ns = 0): array { return ['clock' => $clock, 'ns' => $ns, 'value' => (string) $value]; }
function trendRow(int $clock, $minimum, $maximum, int $num = 60): array {
    return ['clock' => (string) $clock, 'num' => (string) $num,
        'value_min' => (string) $minimum, 'value_avg' => (string) $minimum,
        'value_max' => (string) $maximum];
}
function oracle(array $config, int $from, int $to): array {
    $techs = []; $weights = [];
    foreach ($config['departments'][0]['technologies'] as $tech) {
        $hosts = [];
        foreach (API::$hosts as $host) {
            $checks = [];
            foreach ($tech['checks'] as $check) {
                $checks[] = Engine::samples(API::$history[$host['hostid'] . '-' . $check['key']] ?? [], $check, $check['max_age'], $from, $to);
            }
            $hosts[] = Engine::combine($checks, 'any_down', $from, $to);
        }
        $techs[] = Engine::combine($hosts, $tech['mode'], $from, $to);
        $weights[] = $tech['weight'];
    }
    return Engine::summary(Engine::combine($techs, 'mean', $from, $to, $weights), $from, $to);
}

$from = strtotime('2026-05-01 UTC');
fixture();
$config = configuration('any_down', 180);
foreach (API::$items as $item) {
    for ($second = -60; $second <= 7200; $second += 60) {
        $down = $item['itemid'] === '1-ping' && $second >= 900 && $second < 1080
            || $item['itemid'] === '1-service' && $second >= 960 && $second < 1200
            || $item['itemid'] === '2-service' && $second >= 1140 && $second < 1320;
        API::$history[$item['itemid']][] = sample($from + $second, $down ? 0 : 1);
    }
}
$original = Calculation::create($config, '2026-05', -1, $from + 7200);
verify(API::$calls === [], 'create performs no API reads');
rejects(static function() use ($original) { Calculation::result($original); }, 'running calculation cannot be published');
$state = finishCalculation($original, 1);
verify($state['status'] === 'complete', 'basic calculation complete');
$report = Calculation::result($state);
equalSummary($report['departments'][0]['summary'], oracle($config, $from, $from + 7200), 'any-down oracle');
verify($report['departments'][0]['summary']['down'] == 420, 'overlaps counted once across services and hosts');
verify($state['progress']['hosts_done'] === 2 && $state['progress']['checks_done'] === 4, 'all hosts/checks completed');
verify($report['to'] === $from + 7200 && $report['partial'], 'period end frozen across requests');
verify($report['processing']['hosts_total'] === 2 && $report['processing']['api_calls'] === count(API::$calls), 'audit totals');
verify(Calculation::result(checkpoint($state)) == $report, 'final result survives serialization');
verify((new Calculation())->advance($state) === $state, 'completed job cannot change');
verify($original['phase'] === 'groups' && $original['progress']['hosts_done'] === 0, 'advance does not mutate caller checkpoint');

// Any-down versus the mean of host availability: do not average time twice.
$config['departments'][0]['technologies'][0]['mode'] = 'mean';
$mean = Calculation::result(finishCalculation(Calculation::create($config, '2026-05', -1, $from + 7200)));
equalSummary($mean['departments'][0]['summary'], oracle($config, $from, $from + 7200), 'host mean oracle');
verify($mean['departments'][0]['summary']['down'] == 240, 'mean weights hosts equally');

// Snapshot membership and exact macro item keys, not later changes during history processing.
fixture(1);
$config = configuration('any_down', 3600);
$pgKey = 'pgsql.ping["{$PG.URI}","{$PG.USER}","{$PG.PASSWORD}"]';
$config['departments'][0]['technologies'][0]['checks'][1]['key'] = $pgKey;
API::$items[1]['key_'] = $pgKey;
API::$items[1]['preprocessing'] = [['type' => 20, 'params' => '1h']];
API::$history['1-ping'] = [sample($from, 1), sample($from + 3600, 1), sample($from + 7200, 1)];
API::$history['1-service'] = [sample($from, 1), sample($from + 3601, 1), sample($from + 7274, 1)];
$state = Calculation::create($config, '2026-05', -1, $from + 7300);
while (!isset($state['scope_frozen_at'])) { $state = (new Calculation())->advance($state, 1); }
API::$items = []; API::$hosts = [];
$config['departments'][0]['name'] = 'Edited elsewhere';
$state = finishCalculation(checkpoint($state));
$report = Calculation::result($state);
$sources = $report['departments'][0]['technologies'][0]['hosts'][0]['sources'];
verify($report['departments'][0]['name'] === 'Banco', 'configuration snapshot immutable');
verify($sources[1]['itemid'] === '1-service' && $sources[1]['key'] === $pgKey, 'exact macro key frozen before reads');
verify($sources[1]['max_age'] === 3600 && $sources[1]['freshness_mode'] === 'manual', 'manual heartbeat tolerance preserved');
verify($sources[1]['summary']['unknown'] == 74 && $sources[1]['summary']['score'] === null, 'heartbeat gaps not reclassified as up');
verify($sources[1]['max_gap_seconds'] === 3673 && $sources[1]['sample_count'] === 3, 'per-item gap diagnostics');
verify($sources[1]['up_sample_count'] === 3 && $sources[1]['down_sample_count'] === 0
    && $sources[1]['unknown_sample_count'] === 0, 'raw sample classifications are audited');
verify($report['departments'][0]['summary']['unknown'] == 74, 'source gaps survive host and department consolidation');

// Dense data requires several pages. Ties at the page boundary must be read completely.
fixture(1);
$config = configuration('any_down', 20);
$config['departments'][0]['technologies'][0]['checks'] = [$config['departments'][0]['technologies'][0]['checks'][0]];
$rows = [sample($from - 10, 1)];
for ($i = 0; $i < 12000; $i++) { $rows[] = sample($from + $i, $i % 97 ? 1 : 0); }
foreach ([4998, 9996] as $i) {
    for ($ns = 1; $ns <= 12; $ns++) { $rows[] = sample($from + $i, $ns === 12 ? 0 : 1, $ns); }
}
API::$history['1-ping'] = $rows;
$expected = oracle($config, $from, $from + 12000);
$state = finishCalculation(Calculation::create($config, '2026-05', -1, $from + 12000), 2);
$report = Calculation::result($state);
equalSummary($report['departments'][0]['summary'], $expected, 'pagination + nanoseconds oracle');
$source = $report['departments'][0]['technologies'][0]['hosts'][0]['sources'][0];
verify($source['sample_count'] === 12024, 'no dropped or double counted boundary samples');
verify($source['up_sample_count'] + $source['down_sample_count'] + $source['unknown_sample_count']
    === $source['sample_count'], 'every in-period sample has exactly one audit classification');
verify($report['rows'] > count($rows), 'raw rows honestly include boundary re-reads');
verify($source['seed_clock'] === $from - 10 && $source['first_clock'] === $from, 'seed is not counted as a monthly sample');

// Gaps with no seed, and continuity across sparse seven-day query windows.
fixture(1); $config = configuration('any_down', 86400);
$config['departments'][0]['technologies'][0]['checks'] = [$config['departments'][0]['technologies'][0]['checks'][0]];
API::$history['1-ping'] = [sample($from + 500, 1), sample($from + 7 * 86400 - 100, 0), sample($from + 8 * 86400, 1)];
$report = Calculation::result(finishCalculation(Calculation::create($config, '2026-05', -1, $from + 9 * 86400)));
equalSummary($report['departments'][0]['summary'], oracle($config, $from, $from + 9 * 86400), 'sparse chunks oracle');
verify($report['departments'][0]['summary']['score'] === null, 'no backward extrapolation into missing seed');

// Closed months with incomplete detailed history may use a complete conservative
// hourly trend series. Mixed binary hours are fully DOWN; absent hours stay UNKNOWN.
fixture(1); $config = configuration('any_down', 3600);
$monthTo = strtotime('2026-06-01 UTC');
for ($clock = $from; $clock < $monthTo; $clock += 3600) {
    $offset = intdiv($clock - $from, 3600);
    API::$trends['1-ping'][] = trendRow($clock, $offset === 10 ? 0 : 1, 1, 60);
    if ($offset !== 20) { API::$trends['1-service'][] = trendRow($clock, 1, 1, 1); }
}
$report = Calculation::result(finishCalculation(Calculation::create($config, '2026-05', -1, $monthTo + 86400)));
$host = $report['departments'][0]['technologies'][0]['hosts'][0];
$sources = $host['sources'];
verify(!$report['partial'] && $sources[0]['trend_fallback_attempted'] && $sources[1]['trend_fallback_attempted'],
    'only a closed incomplete month attempts the fallback');
verify($sources[0]['data_source'] === 'trends_conservative'
    && $sources[0]['resolution_seconds'] === 3600
    && $sources[0]['trend_row_count'] === 744 && $sources[0]['trend_mixed_hour_count'] === 1
    && $sources[0]['trend_down_hour_count'] === 0 && $sources[0]['trend_up_hour_count'] === 743,
    'mixed trend hour is audited separately and conservatively classified DOWN');
verify($sources[1]['data_source'] === 'trends_conservative'
    && $sources[1]['resolution_seconds'] === 3600
    && $sources[1]['trend_row_count'] === 743 && $sources[1]['trend_mixed_hour_count'] === 0,
    'missing trend hour does not invent a row');
verify($host['summary']['down'] == 3600.0 && $host['summary']['unknown'] == 3600.0
    && $host['summary']['score'] === null,
    'conservative outage and absent trend hour remain distinct in host totals');
verify($sources[0]['summary']['down'] == 3600.0 && $sources[1]['summary']['unknown'] == 3600.0,
    'source summaries use the selected trend series');
verify($report['rows'] === 1487, 'trend rows participate in processing audit totals');

// Missing/non-numeric/unresolved checks are real unknown data, not a failed execution.
fixture(1); $config = configuration();
API::$items[0]['value_type'] = '1';
API::$items[1]['delay'] = '{$UNRESOLVED}';
$config['departments'][0]['technologies'][0]['checks'][1]['max_age'] = null;
$state = finishCalculation(Calculation::create($config, '2026-05', -1, $from + 3600));
$report = Calculation::result($state);
verify($state['status'] === 'complete' && $state['progress']['checks_done'] === 2, 'unresolved checks still evaluated');
verify($report['departments'][0]['summary']['unknown'] == 3600, 'unknown entire interval');
verify(count($report['departments'][0]['technologies'][0]['hosts'][0]['warnings']) >= 2, 'missing and unresolved reasons visible');
verify(!array_filter(API::$calls, static function($call) { return $call[0] === 'History'; }), 'no unresolvable history read');

// Errors after a completed host must not turn an unfinished scope into a final report.
fixture(); $config = configuration();
API::$history['1-ping'] = API::$history['1-service'] = [sample($from, 1)];
API::$failItem = '2-ping';
$state = finishCalculation(Calculation::create($config, '2026-05', -1, $from + 100));
verify($state['status'] === 'failed' && $state['progress']['hosts_done'] === 1 && $state['progress']['hosts_total'] === 2,
    'processing failure retains completed/total progress');
rejects(static function() use ($state) { Calculation::result($state); }, 'failure cannot publish an unknown replacement indicator');
verify(strpos($state['error'], 'History') !== false && strpos($state['error'], 'Pretend') === false, 'API errors sanitized with endpoint context');

fixture(201); $state = finishCalculation(Calculation::create(configuration(), '2026-05', -1, $from + 100));
verify($state['status'] === 'failed', 'scope limit never drops host201 silently');
rejects(static function() use ($state) { Calculation::result($state); }, 'oversized scope cannot publish');

fixture(1); $config = configuration('any_down', 20);
for ($ns = 0; $ns < Calculation::PAGE_ROWS + 2; $ns++) { API::$history['1-ping'][] = sample($from, 1, $ns); }
$state = finishCalculation(Calculation::create($config, '2026-05', -1, $from + 100));
verify($state['status'] === 'failed' && strpos($state['error'], 'one second') !== false, 'overdense single second fails explicitly');
rejects(static function() use ($state) { Calculation::result($state); }, 'no invented value from a partial same-clock page');

// Groups are resolved case-insensitively, only descendants at slash boundaries.
fixture(1); $config = configuration();
$config['departments'][0]['technologies'][0]['groups'] = 'EQUIPES/, Equipes/Banco';
$state = finishCalculation(Calculation::create($config, '2026-05', -1, $from + 100));
verify($state['progress']['hosts_total'] === 1, 'overlapping parent and child do not count host twice');
foreach (API::$calls as $call) {
    if ($call[0] === 'Host') { verify(!in_array('3', $call[1]['groupids']), 'slash prefix boundary excludes similarly named groups'); }
}
$config['departments'][0]['technologies'][0]['groups'] = 'Equipes, Missing';
$report = Calculation::result(finishCalculation(Calculation::create($config, '2026-05', -1, $from + 100)));
verify($report['departments'][0]['summary']['unknown'] == 100, 'unresolved selected group cannot silently shrink scope');
verify($report['departments'][0]['technologies'][0]['hosts_total'] === 0, 'unresolved scope reports zero discovered hosts');

// Department selection preserves full source config separately from the selected report config.
fixture(1); $config = configuration();
$config['departments'][] = $config['departments'][0]; $config['departments'][1]['name'] = 'Conectividade';
$state = finishCalculation(Calculation::create($config, '2026-05', 1, $from + 100));
verify(count($state['source_config']['departments']) === 2 && $state['department_filter'] === 1, 'full snapshot retained for filters');
$report = Calculation::result($state);
verify(count($report['departments']) === 1 && $report['departments'][0]['name'] === 'Conectividade', 'selected department only');
rejects(static function() use ($config, $from) { Calculation::create($config, '2026-05', 9, $from + 100); }, 'invalid department');
rejects(static function() use ($config, $from) { Calculation::create($config, '2026-06', -1, $from + 100); }, 'future month');
rejects(static function() use ($config) { Calculation::create($config, '2026-05-01'); }, 'invalid month syntax');

// Several technologies with unequal weights, tiny real gaps and randomized state changes.
mt_srand(7391);
for ($trial = 0; $trial < 12; $trial++) {
    fixture(3); $config = configuration($trial % 2 ? 'mean' : 'any_down', 20);
    $second = $config['departments'][0]['technologies'][0];
    $second['name'] = 'Other'; $second['mode'] = $trial % 2 ? 'any_down' : 'mean';
    $second['weight'] = $trial % 3 ? 2 : 0.001;
    $second['checks'] = [$second['checks'][1]];
    $config['departments'][0]['technologies'][0]['weight'] = $trial % 3 ? 4 : 100000;
    $config['departments'][0]['technologies'][] = $second;
    foreach (API::$items as $item) {
        for ($second = -5; $second < 150; $second += mt_rand(1, 25)) {
            API::$history[$item['itemid']][] = sample($from + $second, mt_rand(0, 4) ? 1 : 0, mt_rand(0, 100));
        }
    }
    $report = Calculation::result(finishCalculation(Calculation::create($config, '2026-05', -1, $from + 150), $trial % 7 + 1));
    equalSummary($report['departments'][0]['summary'], oracle($config, $from, $from + 150), 'random weighted oracle ' . $trial);
}

// Calendar days use the configured timezone, including a 23-hour daylight-saving day.
fixture(1); $config = configuration('any_down', 86400); $config['timezone'] = 'America/New_York';
$start = new DateTimeImmutable('2026-03-01', new DateTimeZone($config['timezone']));
$end = $start->modify('+1 month');
foreach (API::$items as $item) {
    for ($clock = $start->getTimestamp(); $clock < $end->getTimestamp(); $clock += 3600) {
        API::$history[$item['itemid']][] = sample($clock, 1);
    }
}
$report = Calculation::result(finishCalculation(Calculation::create($config, '2026-03', -1, $end->getTimestamp())));
verify(!$report['partial'] && count($report['departments'][0]['daily']) === 31, 'complete DST month has31 calendar days');
$day = array_values(array_filter($report['departments'][0]['daily'], static function($day) { return $day['day'] === '2026-03-08'; }))[0];
verify($day['summary']['up'] == 23 * 3600 && $day['summary']['score'] == 100, 'DST day duration not assumed86400');

// Time budget yields checkpoints instead of poisoning the rest of the scope.
fixture(1); $tick = 0;
$runner = new Calculation(static function() use (&$tick) { $tick += 0.5; return $tick; });
$state = $runner->advance(Calculation::create(configuration(), '2026-05', -1, $from + 100), 8, 0.1);
verify($state['status'] === 'running' && count(API::$calls) === 1, 'time budget returns a resumable state after one operation');
verify(Calculation::result(finishCalculation($state))['departments'][0]['summary']['score'] === null, 'resume completes with honest missing-data result');

echo 'Availability calculation: ' . $assertions . " assertions passed.\n";
