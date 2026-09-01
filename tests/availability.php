<?php
// Run with: php tests/availability.php (mbstring required). Never expose tests as web endpoints.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../AvailabilityConfig.php';
require __DIR__ . '/../AvailabilityEngine.php';
require __DIR__ . '/../AvailabilityFreshness.php';
require __DIR__ . '/../AvailabilityReport.php';
use Modules\Governance\AvailabilityConfig as Config;
use Modules\Governance\AvailabilityEngine as Engine;
use Modules\Governance\AvailabilityFreshness as Freshness;
use Modules\Governance\AvailabilityReport as Report;
$assertions = 0;
function check($truth, $message) { global $assertions; $assertions++; if (!$truth) { throw new RuntimeException($message); } }
function near($a, $b, $message) { check(abs($a - $b) < 0.000001, $message . ': ' . $a . ' != ' . $b); }
function rejects($fn, $message) { try { $fn(); } catch (Exception $e) { check(true, $message); return; } check(false, $message); }
$binary = ['key' => 'ping', 'up' => ['op' => 'eq', 'a' => 1], 'down' => null];
$sample = static function($time, $value, $ns = 0) { return ['clock' => $time, 'value' => $value, 'ns' => $ns]; };
$summary = Engine::summary(Engine::samples([$sample(0, 1)], $binary, 60, 0, 120), 0, 120);
near($summary['up'], 60, 'sample freshness'); near($summary['unknown'], 60, 'expired sample');
check($summary['score'] === null, 'unknown is not silently up');
near($summary['coverage'], 50, 'coverage');
$summary = Engine::summary(Engine::samples([$sample(-30, 1)], $binary, 60, 0, 60), 0, 60);
near($summary['up'], 30, 'sample before month boundary');
near(Engine::summary(Engine::samples([$sample(-100, 1)], $binary, 60, 0, 60), 0, 60)['unknown'], 60, 'stale seed');
$summary = Engine::summary(Engine::samples([$sample(0, 0, 20), $sample(0, 1, 10)], $binary, 60, 0, 60), 0, 60);
near($summary['down'], 60, 'latest nanosecond wins at same second');
check(Engine::state('x', $binary) === -1, 'non-numeric unknown');
check(Engine::state(0, $binary) === 0, 'binary failure');
foreach (['eq' => [2, true], 'ne' => [2, false], 'gt' => [3, true], 'ge' => [2, true], 'lt' => [1, true], 'le' => [2, true], 'range' => [3, true]] as $op => $case) {
    check(Engine::matches($case[0], ['op' => $op, 'a' => 2, 'b' => 4]) === $case[1], 'operator ' . $op);
}
$explicit = ['up' => ['op' => 'eq', 'a' => 1], 'down' => ['op' => 'eq', 'a' => 0]];
check(Engine::state(2, $explicit) === -1, 'neither predicate matches');
check(Engine::state(1, ['up' => $explicit['up'], 'down' => $explicit['up']]) === -1, 'overlapping predicates');
$a = Engine::samples([$sample(0, 1), $sample(600, 0), $sample(1200, 1)], $binary, 3600, 0, 1800);
$b = Engine::samples([$sample(0, 1), $sample(900, 0), $sample(1500, 1)], $binary, 3600, 0, 1800);
near(Engine::summary(Engine::combine([$a, $b], 'any_down', 0, 1800), 0, 1800)['down'], 900, 'overlap union = 15 minutes');
near(Engine::summary(Engine::combine([$a, $b], 'mean', 0, 1800), 0, 1800)['down'], 600, 'mean = 10 minutes');
$masked = Engine::combine([[[0, 60, 0, 1, 0]], Engine::unknown(0, 60)], 'any_down', 0, 60);
near(Engine::summary($masked, 0, 60)['down'], 60, 'confirmed failure dominates missing check');
$up = [[0, 60, 1, 0, 0]]; $unknown = Engine::unknown(0, 60); $down = [[0, 60, 0, 1, 0]];
check(Engine::summary(Engine::combine([$up, $up], 'any_down', 0, 60), 0, 60)['up'] === 60.0,
    'host is UP only when both required checks are UP');
check(Engine::summary(Engine::combine([$up, $unknown], 'any_down', 0, 60), 0, 60)['unknown'] === 60.0,
    'UP plus UNKNOWN keeps the host UNKNOWN');
check(Engine::summary(Engine::combine([$down, $unknown], 'any_down', 0, 60), 0, 60)['down'] === 60.0,
    'DOWN plus UNKNOWN keeps the host DOWN');
$weighted = Engine::combine([[[0, 1000000, 1, 0, 0]], [[0, 1000000, .999984, .000016, 0]], [[0, 1000000, 1, 0, 0]]], 'mean', 0, 1000000, [4, 2, 1]);
near(Engine::summary($weighted, 0, 1000000)['score'], (100 * 4 + 99.9984 * 2 + 100) / 7, '4-2-1 weighting');
check(Engine::summary([], 0, 0)['score'] === null, 'empty period is not 100');
$zeroLength = Engine::combine([[[0, 10, 1, 0, 0], [10, 10, 0, 1, 0], [10, 20, 1, 0, 0]]], 'mean', 0, 20);
near(Engine::summary($zeroLength, 0, 20)['coverage'], 100, 'zero-length interval cannot corrupt coverage');
near(Engine::summary($zeroLength, 0, 20)['down'], 0, 'zero-length outage has no duration');

// Weighted intervals must not turn arithmetic residue into data gaps, or erase real small gaps.
$precisionEnd = 2678400;
$precisionSeries = [];
$precisionGaps = [];
foreach ([[195563, 195847], [1009565, 1009686], [1587501, 1588095]] as $outage) {
    $precisionSeries[] = [[0, $outage[0], 1, 0, 0], [$outage[0], $outage[1], 0, 1, 0], [$outage[1], $precisionEnd, 1, 0, 0]];
    $precisionGaps[] = [[0, $outage[0], 1, 0, 0], [$outage[0], $outage[1], 0, 0, 1], [$outage[1], $precisionEnd, 1, 0, 0]];
}
$precisionSummary = Engine::summary(Engine::combine($precisionSeries, 'mean', 0, $precisionEnd, [4, 2, 1]), 0, $precisionEnd);
check($precisionSummary['coverage'] === 100.0 && $precisionSummary['unknown'] === 0.0, 'complete weighted coverage is exactly 100 percent');
near($precisionSummary['score'], 99.98948199351423, 'weighted score keeps full precision');
check($precisionSummary['lower'] === $precisionSummary['upper'] && $precisionSummary['score'] === $precisionSummary['lower'], 'complete bounds and score agree');
$precisionSummary = Engine::summary(Engine::combine($precisionGaps, 'mean', 0, $precisionEnd, [4, 2, 1]), 0, $precisionEnd);
check($precisionSummary['upper'] === 100.0 && $precisionSummary['down'] === 0.0, 'no confirmed outage means an exact 100 percent upper bound');
check($precisionSummary['score'] === null && $precisionSummary['coverage'] < 100.0, 'weighted gaps remain incomplete');
$alwaysUp = [[0, $precisionEnd, 1, 0, 0]];
foreach (['unknown' => [0, .005], 'down' => [.005, 0]] as $kind => $fractions) {
    // One affected server out of 200, in a technology of weight .001 against weight 100000.
    $tinySeries = [[0, 1, 1, 0, 0], [1, 2, .995, $fractions[0], $fractions[1]], [2, $precisionEnd, 1, 0, 0]];
    $tinyCombined = Engine::combine([$tinySeries, $alwaysUp], 'mean', 0, $precisionEnd, [.001, 100000]);
    $tinySummary = Engine::summary($tinyCombined, 0, $precisionEnd);
    check($tinySummary[$kind] > 0 && $tinySummary[$kind] < 1e-6, 'a tiny real weighted ' . $kind . ' interval is preserved');
    check($kind === 'unknown' ? $tinySummary['score'] === null && $tinySummary['coverage'] < 100.0 : $tinySummary['score'] < 100.0,
        'a tiny ' . $kind . ' cannot silently publish 100 percent');
    $afterTiny = Engine::summary($tinyCombined, 2, $precisionEnd);
    check($afterTiny['unknown'] === 0.0 && $afterTiny['down'] === 0.0 && $afterTiny['score'] === 100.0,
        'after the ' . $kind . ' interval there is no floating point residue');
}
$fractionalSeries = [];
foreach ([1, 2, 3] as $end) { $fractionalSeries[] = [[0, $end, 0, 0, 1], [$end, 10, 1, 0, 0]]; }
$fractionalCombined = Engine::combine($fractionalSeries, 'mean', 0, 10, [.1, .2, .3]);
$knownTail = Engine::summary($fractionalCombined, 3, 10);
check($knownTail['unknown'] === 0.0 && $knownTail['score'] === 100.0, 'fractional weight cancellations do not invalidate a known later period');
check(Engine::summary(Engine::combine([$alwaysUp, Engine::unknown(0, $precisionEnd)], 'mean', 0, $precisionEnd, [1, 0]), 0, $precisionEnd)['score'] === 100.0,
    'zero-weight children cannot introduce residual gaps');
check(Engine::summary([], 0, 60)['score'] === null && Engine::summary([], 0, 60)['unknown'] === 60.0,
    'missing timeline coverage stays unknown rather than becoming a final score');
$lowScore = Engine::summary([[0, 1, 1e-16, 1 - 1e-16, 0]], 0, 1)['score'];
check($lowScore > 0 && $lowScore < 1e-12, 'very low nonzero availability is preserved');

// Randomized one-second oracle checks both aggregation modes and total duration conservation.
mt_srand(42);
for ($trial = 0; $trial < 80; $trial++) {
    $series = []; $states = [];
    for ($h = 0; $h < 3; $h++) {
        $series[$h] = [];
        for ($s = 0; $s < 30; $s++) {
            $state = mt_rand(0, 2); $states[$h][$s] = $state;
            Engine::append($series[$h], [$s, $s + 1, $state === 0 ? 1 : 0, $state === 1 ? 1 : 0, $state === 2 ? 1 : 0]);
        }
    }
    $expected = [0, 0, 0]; $mean = [0, 0, 0];
    for ($s = 0; $s < 30; $s++) {
        $values = [$states[0][$s], $states[1][$s], $states[2][$s]];
        $expected[in_array(1, $values) ? 1 : (in_array(2, $values) ? 2 : 0)]++;
        foreach ($values as $state) { $mean[$state] += 1 / 3; }
    }
    foreach (['any_down' => $expected, 'mean' => $mean] as $mode => $wanted) {
        $stats = Engine::summary(Engine::combine($series, $mode, 0, 30), 0, 30);
        foreach (['up', 'down', 'unknown'] as $i => $key) { near($stats[$key], $wanted[$i], $mode . ' oracle ' . $key); }
    }
}
$technology = ['name' => 'PostgreSQL', 'target' => 99.9, 'weight' => 4, 'groups' => 'Equipes', 'mode' => 'any_down', 'max_age' => 3600, 'checks' => [$binary, array_replace($binary, ['key' => 'service'])]];
$config = ['timezone' => 'UTC', 'departments' => [['name' => 'Banco de Dados', 'target' => 99.9, 'technologies' => [$technology]]]];
check(Config::validate($config)['departments'][0]['technologies'][0]['checks'][0]['key'] === 'ping', 'valid configuration');
foreach (['weight' => 0, 'target' => 101, 'max_age' => 0, 'groups' => ',,', 'mode' => 'invalid', 'checks' => []] as $field => $value) {
    $invalid = $config; $invalid['departments'][0]['technologies'][0][$field] = $value;
    rejects(static function() use ($invalid) { Config::validate($invalid); }, 'reject invalid ' . $field);
}
$invalid = $config; $invalid['timezone'] = 'not/timezone';
rejects(static function() use ($invalid) { Config::validate($invalid); }, 'timezone validation');
check(Config::groups('Equipes/, Área, área') === ['equipes', 'área'], 'unicode, trailing slash, unique groups');

// Per-check validity and migration: opening/saving old rules must not silently change policy.
$normalized = Config::validate($config);
check($normalized['departments'][0]['technologies'][0]['checks'][0]['max_age'] === 3600, 'legacy validity becomes per-check manual policy');
check(Config::validate($normalized) === $normalized, 'legacy configuration roundtrip is stable');
$mixed = $config;
$mixed['departments'][0]['technologies'][0]['checks'][0]['max_age'] = null;
$mixed['departments'][0]['technologies'][0]['checks'][1]['max_age'] = 180;
$mixed = Config::validate($mixed);
check($mixed['departments'][0]['technologies'][0]['checks'][0]['max_age'] === null, 'explicit auto overrides legacy technology policy');
check($mixed['departments'][0]['technologies'][0]['checks'][1]['max_age'] === 180, 'manual per-check override');
unset($mixed['departments'][0]['technologies'][0]['max_age']);
check(Config::validate($mixed) === $mixed, 'new per-check configuration needs no technology-wide validity');
$newConfig = $config;
unset($newConfig['departments'][0]['technologies'][0]['max_age']);
check(Config::validate($newConfig)['departments'][0]['technologies'][0]['checks'][0]['max_age'] === null, 'new rule defaults to auto');
foreach ([0, -1, 0.5, 180.1, 86401, '', true, []] as $value) {
    $invalid = $config;
    $invalid['departments'][0]['technologies'][0]['checks'][0]['max_age'] = $value;
    rejects(static function() use ($invalid) { Config::validate($invalid); }, 'invalid per-check validity');
}
$invalid = $config; $invalid['departments'][0]['technologies'][0]['max_age'] = 180.5;
rejects(static function() use ($invalid) { Config::validate($invalid); }, 'fractional legacy validity rejected');

$pollItem = ['type' => '3', 'delay' => '1m', 'key_' => 'icmpping', 'preprocessing' => []];
$pgKey = 'pgsql.ping["{$PG.URI}","{$PG.USER}","{$PG.PASSWORD}"]';
$pgItem = array_replace($pollItem, ['type' => '0', 'key_' => $pgKey,
    'preprocessing' => [['type' => '20', 'params' => '1h']]]);
$pollAge = Freshness::resolve($pollItem, null);
$pgAge = Freshness::resolve($pgItem, null);
check($pollAge['max_age'] === 3600 && $pollAge['freshness_source'] === 'interval',
    '60-second ICMP keeps an hourly evidence window');
check($pgAge['max_age'] === 3720 && $pgAge['freshness_source'] === 'heartbeat', 'hourly heartbeat accounts for next and delayed collection');
check($pgAge['interval_seconds'] === 60 && $pgAge['heartbeat_seconds'] === 3600, 'numeric audit metadata');
check($pollAge['automatic_minimum_seconds'] === 3600 && $pgAge['automatic_minimum_seconds'] === 3600,
    'hourly automatic minimum is exported for both sources');
check($pgAge['warnings'] === [], 'ordinary heartbeat is resolvable');
$override = Freshness::resolve($pgItem, 180);
check($override['max_age'] === 180 && $override['freshness_mode'] === 'manual', 'legacy or explicit override is preserved');
check(count($override['warnings']) === 1, 'short manual policy warns about heartbeat');
check(Freshness::resolve($pgItem, 4000)['warnings'] === [], 'long enough manual override does not warn');
check(count(Freshness::resolve(array_replace($pgItem, ['delay' => '{$PG.UPDATE.INTERVAL}']), 180)['warnings']) === 1,
    'known heartbeat warns about short manual age even when polling is a macro');
check(Freshness::resolve(array_replace($pgItem, ['delay' => '300']), null)['max_age'] === 4200, 'heartbeat margin follows actual polling interval');
check(Freshness::resolve(array_replace($pollItem, ['delay' => '2h']), null)['max_age'] === 21600, 'time suffix accepted');
check(Freshness::resolve(array_replace($pgItem, ['preprocessing' => [['type' => 20, 'params' => '30s']]]), null)['max_age'] === 3600,
    'short heartbeat cannot shorten the hourly evidence floor');
foreach ([
    array_replace($pollItem, ['delay' => '{$UPDATE.INTERVAL}']),
    array_replace($pollItem, ['delay' => '60;0/1-5,09:00-18:00']),
    array_replace($pollItem, ['delay' => '0']),
    array_replace($pollItem, ['delay' => '1d']),
    array_replace($pollItem, ['delay' => '1000000000000000w']),
    array_replace($pollItem, ['type' => '2']),
    array_replace($pollItem, ['type' => '17']),
    array_replace($pollItem, ['type' => '18']),
    array_replace($pollItem, ['type' => '999']),
    array_replace($pollItem, ['type' => '7', 'key_' => 'mqtt.get[broker,topic]']),
    array_replace($pgItem, ['preprocessing' => [['type' => 20, 'params' => '{$HEARTBEAT}']]]),
    array_replace($pgItem, ['preprocessing' => [['type' => 20, 'params' => '0']]]),
    array_replace($pollItem, ['preprocessing' => [['type' => 19, 'params' => '']]]),
    array_replace($pollItem, ['preprocessing' => null]),
    ['delay' => '60', 'type' => 0]
] as $unsafe) {
    $result = Freshness::resolve($unsafe, null);
    check($result['max_age'] === null && $result['freshness_source'] === 'unresolved' && count($result['warnings']) > 0,
        'unsupported cadence must not invent validity');
    check(Freshness::resolve($unsafe, 600)['max_age'] === 600, 'explicit manual policy can resolve unsupported cadence');
}

class API {
    public static $handlers = [];
    public static function __callStatic($name, $arguments) { return new TestEndpoint($name); }
}
class TestEndpoint {
    private $name;
    public function __construct($name) { $this->name = $name; }
    public function __call($method, $args) { return call_user_func(API::$handlers[$this->name . '.' . $method], $args[0]); }
}
$from = strtotime('2026-05-01 UTC');
API::$handlers['HostGroup.get'] = static function($o) { return [['groupid' => '1', 'name' => 'Equipes'], ['groupid' => '2', 'name' => 'Equipes/Banco'], ['groupid' => '3', 'name' => 'Equipes Externas']]; };
API::$handlers['Host.get'] = static function($o) {
    check(!in_array('3', $o['groupids']), 'parent prefix respects slash boundary');
    return in_array('2', $o['groupids']) ? [['hostid' => '1', 'name' => 'A', 'status' => 0], ['hostid' => '2', 'name' => 'B', 'status' => 0]] : [];
};
API::$handlers['Item.get'] = static function($o) {
    $items = [];
    foreach (['1', '2'] as $host) { foreach (['ping', 'service'] as $key) { $items[] = ['itemid' => $host . $key, 'hostid' => $host, 'key_' => $key, 'value_type' => 3, 'status' => 0]; } }
    return $items;
};
$calls = 0;
API::$handlers['History.get'] = static function($o) use ($from, &$calls, $sample) {
    $calls++;
    $item = $o['itemids'][0];
    $samples = [$sample($from, 1)];
    if ($item === '1ping') { $samples[] = $sample($from + 600, 0); $samples[] = $sample($from + 1200, 1); }
    if ($item === '2service') { $samples[] = $sample($from + 900, 0); $samples[] = $sample($from + 1500, 1); }
    return array_values(array_filter($samples, static function($s) use ($o) { return $s['clock'] >= $o['time_from'] && $s['clock'] <= $o['time_till']; }));
};
$config['departments'][0]['technologies'][] = array_replace($technology, ['mode' => 'mean', 'weight' => 2, 'name' => 'Mean']);
$report = (new Report())->build(Config::validate($config), '2026-05', $from + 1800);
near($report['departments'][0]['technologies'][0]['summary']['down'], 900, 'adapter temporal union');
near($report['departments'][0]['technologies'][1]['summary']['down'], 600, 'adapter mean');
near($report['departments'][0]['summary']['score'], (50 * 4 + (100 * 2 / 3) * 2) / 6, 'department weighting');
check($calls === 4, 'identical item conditions share cache');
check($report['partial'] === true, 'current partial month');
$config['departments'][0]['technologies'][0]['groups'] = 'missing';
$report = (new Report())->build(Config::validate($config), '2026-05', $from + 1800);
check($report['departments'][0]['summary']['score'] === null, 'unresolved group makes parent incomplete');
$config['departments'][0]['technologies'][0]['groups'] = '1';
$report = (new Report())->build(Config::validate($config), '2026-05', $from + 1800);
check(!$report['departments'][0]['technologies'][0]['hosts'], 'ID uses exact group only');
rejects(static function() use ($config, $from) { (new Report())->build($config, '2026-13', $from); }, 'invalid month');
rejects(static function() use ($config, $from) { (new Report())->build($config, '2026-06', $from); }, 'future month');
$config['departments'][0]['technologies'][0]['groups'] = 'Equipes';
API::$handlers['History.get'] = static function($o) use ($from, $sample) {
    $samples = [];
    $boundary = $from + 7 * 86400;
    for ($clock = $from - 1800; $clock <= $from + 8 * 86400; $clock += 1800) {
        if ($clock < $boundary - 300 || $clock >= $boundary + 300) { $samples[] = $sample($clock, 1); }
    }
    $samples[] = $sample($boundary - 300, 0); $samples[] = $sample($boundary + 300, 1);
    return array_values(array_filter($samples, static function($s) use ($o) { return $s['clock'] >= $o['time_from'] && $s['clock'] <= $o['time_till']; }));
};
$report = (new Report())->build(Config::validate($config), '2026-05', $from + 8 * 86400);
near($report['departments'][0]['technologies'][0]['summary']['down'], 600, 'outage crosses seven-day API boundary');
near($report['departments'][0]['summary']['coverage'], 100, 'chunk boundary maintains sample continuity');
API::$handlers['History.get'] = static function($o) { throw new Exception('Simulated API failure'); };
$report = (new Report())->build(Config::validate($config), '2026-05', $from + 1800);
check($report['departments'][0]['summary']['score'] === null, 'API exception makes score incomplete');
check($report['departments'][0]['technologies'][0]['warnings'][0] === 'Simulated API failure', 'API exception visible in warnings');

// Integration: PostgreSQL's heartbeat fixes only its own timeline, never the ICMP freshness.
$config = ['timezone' => 'UTC', 'departments' => [['name' => 'Database', 'target' => 99.9, 'technologies' => [
    array_replace($technology, ['max_age' => 180, 'checks' => [
        array_replace($binary, ['key' => $pgKey, 'max_age' => null]),
        array_replace($binary, ['key' => 'icmpping', 'max_age' => null])]])]]]];
API::$handlers['Host.get'] = static function($o) { return [['hostid' => '1', 'name' => 'Database A', 'status' => '0']]; };
$actualPgItem = array_replace($pgItem, ['itemid' => 'pg', 'hostid' => '1', 'value_type' => '3', 'status' => '0']);
$actualPollItem = array_replace($pollItem, ['itemid' => 'icmp', 'hostid' => '1', 'value_type' => '3', 'status' => '0']);
API::$handlers['Item.get'] = static function($o) use ($pgKey, &$actualPgItem, $actualPollItem) {
    check($o['filter']['key_'] === [$pgKey, 'icmpping'], 'item key macros and quotes remain exact, never expanded');
    check(in_array('delay', $o['output'], true) && in_array('type', $o['output'], true)
        && $o['selectPreprocessing'] === ['type', 'params'], 'only cadence metadata is requested');
    check(!in_array('password', $o['output'], true), 'no credential fields requested');
    return [$actualPgItem, $actualPollItem];
};
$historyIds = []; $withGap = false;
API::$handlers['History.get'] = static function($o) use ($from, $sample, &$historyIds, &$withGap) {
    $id = $o['itemids'][0]; $historyIds[] = $id; $samples = [];
    for ($clock = $from; $clock < $from + 7200; $clock += $id === 'pg' ? 3600 : 60) {
        if ($withGap && $id === 'icmp' && $clock >= $from + 1800 && $clock < $from + 2400) { continue; }
        $samples[] = $sample($clock, $withGap && $id === 'icmp' && $clock === $from + 3000 ? 0 : 1);
    }
    return array_values(array_filter($samples, static function($s) use ($o) { return $s['clock'] >= $o['time_from'] && $s['clock'] <= $o['time_till']; }));
};
$report = (new Report())->build(Config::validate($config), '2026-05', $from + 7200);
$tech = $report['departments'][0]['technologies'][0];
near($tech['summary']['score'], 100, 'hourly PG plus minute ICMP remain fully available');
near($tech['summary']['unknown'], 0, 'hourly heartbeat does not create artificial gaps');
check($tech['hosts'][0]['sources'][0]['max_age'] === 3720 && $tech['hosts'][0]['sources'][1]['max_age'] === 3600,
    'PostgreSQL exports heartbeat tolerance and ICMP exports the hourly floor');
$withGap = true;
$report = (new Report())->build(Config::validate($config), '2026-05', $from + 7200);
$summary = $report['departments'][0]['summary'];
near($summary['unknown'], 0, 'an ICMP gap shorter than one hour remains covered by the last real sample');
near($summary['down'], 60, 'confirmed ICMP outage is preserved');
near($summary['score'], 100 * 7140 / 7200, 'confirmed ICMP outage changes the score without an artificial gap');
$actualPgItem['delay'] = '{$PG.UPDATE.INTERVAL}'; $withGap = false; $historyIds = [];
$report = (new Report())->build(Config::validate($config), '2026-05', $from + 7200);
$tech = $report['departments'][0]['technologies'][0];
near($tech['summary']['unknown'], 7200, 'unresolved automatic policy stays unknown');
check($historyIds === ['icmp'], 'unresolved cadence never fetches history under invented TTL');
check($tech['hosts'][0]['sources'][0]['max_age'] === null && count($tech['hosts'][0]['sources'][0]['warnings']) === 1,
    'unresolved source carries actionable warning');
$config['departments'][0]['technologies'][0]['checks'][0]['max_age'] = 4000;
$report = (new Report())->build(Config::validate($config), '2026-05', $from + 7200);
near($report['departments'][0]['summary']['score'], 100, 'explicit manual check resolves a macro-driven cadence');

$config['data_policy'] = 'observed';
rejects(static function() use ($config, $from) { (new Report())->build($config, '2026-05', $from + 7200); },
    'legacy adapter cannot silently apply strict math to observed policy');
echo 'PASS: ' . $assertions . " assertions\n";
