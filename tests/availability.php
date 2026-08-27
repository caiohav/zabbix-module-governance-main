<?php
// Run with: php tests/availability.php (mbstring required). Never expose tests as web endpoints.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../AvailabilityConfig.php';
require __DIR__ . '/../AvailabilityEngine.php';
require __DIR__ . '/../AvailabilityReport.php';
use Modules\Governance\AvailabilityConfig as Config;
use Modules\Governance\AvailabilityEngine as Engine;
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
$weighted = Engine::combine([[[0, 1000000, 1, 0, 0]], [[0, 1000000, .999984, .000016, 0]], [[0, 1000000, 1, 0, 0]]], 'mean', 0, 1000000, [4, 2, 1]);
near(Engine::summary($weighted, 0, 1000000)['score'], (100 * 4 + 99.9984 * 2 + 100) / 7, '4-2-1 weighting');
check(Engine::summary([], 0, 0)['score'] === null, 'empty period is not 100');
$zeroLength = Engine::combine([[[0, 10, 1, 0, 0], [10, 10, 0, 1, 0], [10, 20, 1, 0, 0]]], 'mean', 0, 20);
near(Engine::summary($zeroLength, 0, 20)['coverage'], 100, 'zero-length interval cannot corrupt coverage');
near(Engine::summary($zeroLength, 0, 20)['down'], 0, 'zero-length outage has no duration');
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
echo 'PASS: ' . $assertions . " assertions\n";
