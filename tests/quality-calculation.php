<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/quality-fixture.php';
use Modules\Governance\GovernanceConfig as Config;
use Modules\Governance\QualityCalculation as Calculation;
$assertions = 0;
function checkQuality($ok, string $why): void {
    global $assertions; $assertions++;
    if (!$ok) { throw new RuntimeException($why); }
}
set_error_handler(static function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$config = fixtureConfig();
$new = static function(array $settings = null, string $page = 'main'): array {
    $settings = $settings ?? fixtureConfig();
    return Calculation::create($settings, $page, ['10', '10'], Config::qualityRevision($settings));
};
$run = static function(QualityFixture $fixture, array $state) {
    $engine = new Calculation([$fixture, 'get']);
    for ($i = 0; $i < 2000 && $state['status'] === 'running'; $i++) { $state = $engine->advance($state); }
    return $state;
};
$fixture = new QualityFixture(); $engine = new Calculation([$fixture, 'get']); $state = $new();
checkQuality($fixture->calls === [] && $state['result'] === null, 'creation reads no metrics');
checkQuality($state['groupids'] === ['10'], 'duplicate filters canonicalized');
$state = $engine->advance($state);
checkQuality(count($fixture->calls) === 1 && $state['progress']['hosts_total'] === 201, 'scope acquired without relationships');
checkQuality($fixture->calls[0][1]['output'] === ['hostid', 'status'], 'scope query minimal');
checkQuality($fixture->calls[0][1]['limit'] === Calculation::MAX_HOSTS + 1, 'scope explicitly bounded, never truncated silently');
for ($batch = 1; $batch <= 3; $batch++) {
    $state = $engine->advance($state);
    checkQuality(count($fixture->calls) === $batch + 1, 'one request per host step');
    checkQuality(count(end($fixture->calls)[1]['hostids']) <= 100, 'bounded host batch');
    checkQuality(($state['result'] !== null) === ($batch === 3), 'publish only fully evaluated cards');
}
checkQuality($state['result']['overall_score'] === 50.2, 'rounded score equivalent to old controller');
foreach ($state['result']['kpis'] as $card) {
    checkQuality($card['valid_count'] === 101 && $card['total_count'] === 201, 'five card criteria preserved');
    checkQuality(count($card['non_compliant']) === 10 && $card['non_compliant'][0]['hostid'] === '2', 'bounded deterministic examples');
}
checkQuality($state['result']['overview']['registered'] === 202 && $state['result']['overview']['disabled'] === 1, 'disabled counted outside card denominator');
checkQuality($state['result']['overview']['maintenance'] === 1 && $state['result']['overview']['unavailable'] === 100, 'operational summary retained');
checkQuality($state['result']['metrics']['high_problems']['value'] === null, 'pending count is not zero');
$state = $engine->advance($state);
checkQuality(count(end($fixture->calls)[1]['hostids']) === 201, 'problem queried on full scope to prevent duplicate events');
checkQuality(end($fixture->calls)[1]['suppressed'] === false && end($fixture->calls)[1]['recent'] === false, 'problem filters retained');
$state = $run($fixture, $state);
checkQuality($state['status'] === 'complete' && $state['result']['metrics']['unsupported_items']['value'] === 201, 'items summed on disjoint host batches');
checkQuality(count($fixture->calls) === 8, 'bounded pipeline request count');
$projection = Calculation::projection($state);
checkQuality(!isset($projection['hostids'], $projection['cards'], $projection['groupids']) && !isset($state['hostids']), 'no unbounded scope exposed or retained after completion');
checkQuality($state['progress']['api_ms']['scope'] >= 0 && $state['finished_at'] >= $state['started_at'], 'diagnostic timings recorded');
checkQuality($engine->advance($state) === $state, 'completed state cannot advance');

foreach (['Problem' => 'high_problems', 'Item' => 'unsupported_items'] as $service => $key) {
    $broken = new QualityFixture(); $broken->fail = $service;
    $result = $run($broken, $new());
    checkQuality($result['status'] === 'complete' && $result['result']['overall_score'] === 50.2, 'counter failure does not hide finished cards');
    checkQuality($result['result']['metrics'][$key] === ['status' => 'failed', 'value' => null], 'counter failure never becomes zero');
    checkQuality(strpos(json_encode(Calculation::projection($result)), 'PRIVATE') === false, 'API exception private');
}
$broken = new QualityFixture(); $broken->fail = 'Host';
$result = $run($broken, $new());
checkQuality($result['status'] === 'failed' && $result['result'] === null, 'scope failure prevents misleading score');
$changed = new QualityFixture(); $engine = new Calculation([$changed, 'get']);
$state = $engine->advance($new()); unset($changed->rows['1']); $state = $engine->advance($state);
checkQuality($state['status'] === 'failed' && strpos($state['error'], 'escopo') !== false, 'missing host invalidates the frozen denominator');
$changed = new QualityFixture(); $engine = new Calculation([$changed, 'get']);
$state = $engine->advance($new()); $changed->rows['1']['status'] = 1; $state = $engine->advance($state);
checkQuality($state['status'] === 'failed', 'host disabled during batch is detected');
$empty = new QualityFixture(0); $result = $run($empty, $new());
checkQuality($result['result']['total_hosts'] === 0 && $result['result']['overall_score'] === null, 'empty scope is not 100 percent');
checkQuality(count($empty->calls) === 1 && $result['result']['kpis'] === [], 'empty scope cannot query all items by accident');
$result = $run(new QualityFixture(1), $new($config, 'empty'));
checkQuality($result['result']['overall_score'] === null && $result['result']['kpis'] === [], 'empty page retains overview without score');
foreach ($config['quality_pages'][0]['cards'] as &$card) { $card['include_score'] = 0; } unset($card);
$result = $run(new QualityFixture(1), $new($config));
checkQuality($result['result']['overall_score'] === null && count($result['result']['kpis']) === 5, 'excluded cards remain visible');
$result = $run(new QualityFixture(1), $new(['quality_pages' => []], ''));
checkQuality($result['result']['kpis'] === [], 'zero pages remains empty');
$minimal = fixtureConfig(); $minimal['quality_pages'][0]['cards'] = [$minimal['quality_pages'][0]['cards'][3]];
$fixture = new QualityFixture(1); $result = $run($fixture, $new($minimal));
checkQuality(isset($fixture->calls[1][1]['selectGroups']) && !isset($fixture->calls[1][1]['selectTags'], $fixture->calls[1][1]['selectInventory']), 'relationships restricted to selected page');
checkQuality($result['result']['overall_score'] === 100.0, 'named parent includes descendants');
$minimal['quality_pages'][0]['cards'][0]['group_names'] = '10';
checkQuality($run(new QualityFixture(1), $new($minimal))['result']['overall_score'] === 100.0, 'exact group ID preserved');
try { Calculation::create(fixtureConfig(), 'main', [], str_repeat('a', 64)); checkQuality(false, 'stale rules refused'); }
catch (RuntimeException $e) { checkQuality(true, 'stale rules refused'); }
try { $new(fixtureConfig(), 'missing'); checkQuality(false, 'missing page refused'); }
catch (RuntimeException $e) { checkQuality(true, 'missing page refused'); }
$limitEngine = new Calculation(static function() { return array_fill(0, Calculation::MAX_HOSTS + 1, ['hostid' => '1', 'status' => 0]); });
$result = $limitEngine->advance($new());
checkQuality($result['status'] === 'failed' && strpos($result['error'], '50.000') !== false, 'oversized scope fails explicitly');
restore_error_handler();
echo "PASS: $assertions quality calculation assertions\n";
