<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/availability-sla-fixture.php';
use Modules\Governance\AvailabilityCalculation as Calculation;
use Modules\Governance\AvailabilityEngine as Engine;
use Modules\Governance\AvailabilityJobStore as Store;

set_error_handler(static function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$assertions = 0;
function verifySla($condition, string $message): void {
    global $assertions; $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
}
function slaReport(array $state): array {
    verifySla($state['status'] === 'complete', 'Calculation must finish: ' . ($state['error'] ?? $state['phase']));
    return Calculation::result($state);
}
function approximate($actual, float $expected, string $message): void {
    verifySla(is_numeric($actual) && abs($actual - $expected) < 1e-8, $message . ': ' . var_export($actual, true));
}
API::reset();
$state = Calculation::create(API::$config, '2026-07', -1, strtotime('2026-08-28 UTC'));
$runner = new Calculation();
$previous = 0;
for ($step = 0; $state['status'] === 'running' && $step < 100; $step++) {
    $before = count(API::$calls);
    $state = $runner->advance(json_decode(json_encode($state), true), 1);
    verifySla(count(API::$calls) - $before <= 1, 'A stage makes at most one API query');
    verifySla($state['progress']['percent'] >= $previous, 'SLA progress remains monotonic');
    $previous = $state['progress']['percent'];
}
$report = slaReport($state); $department = $report['departments'][0];
verifySla($state['progress']['slas_total'] === 3 && $state['progress']['slas_done'] === 3, 'All three SLAs are assessed');
verifySla($state['progress']['checks_total'] === 0 && $state['progress']['hosts_total'] === 0, 'Native SLA does not pretend to assess item hosts');
verifySla(count(API::$calls) === 12, 'Bounded native pipeline: definition, service, SLI, definition verification per source');
verifySla(!array_intersect(array_column(API::$calls, 0), ['HostGroup', 'Host', 'Item', 'History']), 'Native-only scope never queries item history');
verifySla($department['aggregation_compatible'] === true, 'Identical monthly calendars are comparable');
approximate($department['summary']['score'], 99.96511776753713, 'Weights 4/2/1 preserve the true SLI, not dashboard rounding');
approximate($department['summary']['down'], 3270 * 2 / 7, 'Equivalent downtime is weighted, not a union');
verifySla($department['daily'] === [] && !$department['daily_available'], 'No monthly-to-daily fabrication');
foreach ($department['technologies'] as $tech) {
    verifySla($tech['source'] === 'sla' && $tech['interval_count'] === null && !$tech['daily_available'], 'Explicit native source has no interval count');
    verifySla($tech['native_sla']['timezone'] === 'UTC', 'System timezone uses native resolver');
}
verifySla($report['processing']['method'] === 'checkpointed-sla', 'Audit declares native method');

API::reset(); $config = API::$config; $config['timezone'] = 'America/Cuiaba';
$report = slaReport(slaFixtureCalculation($config));
verifySla(!$report['departments'][0]['aggregation_compatible'], 'Different month boundaries cannot be weighted');
verifySla($report['departments'][0]['summary']['score'] === null && $report['departments'][0]['summary']['down'] === null, 'Incompatible aggregate has neither a fake score nor a fake zero duration');
approximate($report['departments'][0]['technologies'][0]['summary']['score'], 100, 'Native individual 100 remains visible across timezone mismatch');

API::reset(); $config = API::$config;
$config['departments'][0]['technologies'] = [$config['departments'][0]['technologies'][0], API::itemTechnology()];
$report = slaReport(slaFixtureCalculation($config));
verifySla($report['departments'][0]['aggregation_compatible'], 'Unexcluded 24x7 SLA and items may share a department');
approximate($report['departments'][0]['summary']['score'], 100, 'Mixed sources preserve available month');
verifySla(!$report['departments'][0]['daily_available'] && count($report['departments'][0]['technologies'][1]['daily']) === 31, 'Mixed department hides its daily aggregate but retains item daily data');
verifySla($report['processing']['method'] === 'checkpointed-items-and-sla', 'Mixed method is auditable');
API::$missingHistory = true;
$report = slaReport(slaFixtureCalculation($config));
verifySla($report['departments'][0]['summary']['score'] === null, 'Native SLI does not fill missing item history');
approximate($report['departments'][0]['summary']['coverage'], 80, 'Missing source keeps its weight');

API::reset();
$schedule = [];
for ($day = 1; $day <= 5; $day++) { $schedule[] = ['period_from' => $day * 86400 + 9 * 3600, 'period_to' => $day * 86400 + 17 * 3600]; }
API::$slas['1']['schedule'] = $schedule; API::$basis['1'] = 23 * 8 * 3600;
$report = slaReport(slaFixtureCalculation());
verifySla($report['departments'][0]['aggregation_compatible'], 'Identical custom schedules are supported');
approximate($report['departments'][0]['basis_seconds'], 662400, 'July weekday schedule basis is explicit');
API::$config['departments'][0]['technologies'][] = API::itemTechnology();
$report = slaReport(slaFixtureCalculation());
verifySla(!$report['departments'][0]['aggregation_compatible'], 'Custom SLA cannot silently mix with 24x7 items');

API::reset();
$start = strtotime('2026-07-02 09:00:00 UTC');
API::$slas['1']['excluded_downtimes'] = [['name' => 'Planned <test>', 'period_from' => $start, 'period_to' => $start + 3600]];
API::$basis['1'] = 2678400 - 3600;
$report = slaReport(slaFixtureCalculation());
verifySla($report['departments'][0]['aggregation_compatible'], 'Identical planned exclusions can be aggregated');
approximate($report['departments'][0]['basis_seconds'], 2674800, 'Exclusions reduce the contractual denominator');
API::$config['departments'][0]['technologies'][] = API::itemTechnology();
$report = slaReport(slaFixtureCalculation());
verifySla(!$report['departments'][0]['aggregation_compatible'], 'Exclusions cannot silently remove item time');

foreach (['missingCell', 'badDuration'] as $flag) {
    API::reset(); API::$$flag = true;
    $state = slaFixtureCalculation();
    verifySla($state['status'] === 'failed', $flag . ' is a protocol failure, not a measured lack of data');
    verifySla(!empty($state['error']), $flag . ' explains the processing failure');
}
API::reset(); API::$missingService = true;
$report = slaReport(slaFixtureCalculation());
verifySla($report['departments'][0]['summary']['score'] === null, 'Unavailable service cannot become available');
verifySla($report['departments'][0]['technologies'][0]['summary']['score'] === null, 'Unavailable service keeps individual source inconclusive');
verifySla(count($report['departments'][0]['technologies'][0]['warnings']) > 0, 'Unavailable service explains the cause');
API::reset(); API::$slas['1']['status'] = '0';
$report = slaReport(slaFixtureCalculation());
verifySla($report['departments'][0]['summary']['score'] === null, 'Disabled SLA is not valid');
verifySla(!array_filter(API::$calls, static function($call) { return $call[1] === 'getSli'; }), 'Disabled SLA is not queried for score');
API::reset();
$report = slaReport(slaFixtureCalculation(null, '2026-08'));
verifySla($report['departments'][0]['summary']['score'] === null, 'Open month is explicitly unavailable for native SLA');
verifySla(strpos(implode(' ', $report['departments'][0]['technologies'][0]['warnings']), 'closed month') !== false, 'Open month limitation explained');
API::reset(); API::$slas['1']['period'] = '0';
$report = slaReport(slaFixtureCalculation());
verifySla($report['departments'][0]['summary']['score'] === null, 'Daily SLA cannot stand in for a monthly SLA');

API::reset(); API::$changeDefinition = true;
$state = slaFixtureCalculation();
verifySla($state['status'] === 'failed' && strpos($state['error'], 'changed') !== false, 'Concurrent definition edit invalidates calculation');
API::reset(); API::$fail = 'Sla.getSli';
$state = slaFixtureCalculation();
verifySla($state['status'] === 'failed', 'API failure is processing failure, not measured unknown');
verifySla(strpos($state['error'], 'Private SQL') === false, 'API errors do not leak private backend details');

$up = ['up' => 2678400.0, 'down' => 0.0, 'unknown' => 0.0, 'score' => 100.0];
$gap = ['up' => 2678399.0, 'down' => 0.0, 'unknown' => 1.0, 'score' => null];
$summary = Engine::weightedSummaries([$up, $gap], [100000, 0.001], 2678400);
verifySla($summary['score'] === null && $summary['unknown'] > 0 && $summary['coverage'] < 100, 'Low-weight real gap cannot round away');
try { Engine::weightedSummaries([$up], [1], 100); verifySla(false, 'Mismatched denominator must throw'); }
catch (InvalidArgumentException $exception) { verifySla(true, 'Mismatched denominator rejected'); }

// A month of mean-mode item data can accumulate tiny floating-point residue.
// Nine hosts are up, one down, and one changes state every minute.
$monthSeconds = 31 * 86400;
$hosts = array_fill(0, 9, [[0, $monthSeconds, 1, 0, 0]]);
$hosts[] = [[0, $monthSeconds, 0, 1, 0]];
$alternating = [];
for ($second = 0; $second < $monthSeconds; $second += 60) {
    $available = (int) (($second / 60) % 2 === 0);
    $alternating[] = [$second, $second + 60, $available, 1 - $available, 0];
}
$hosts[] = $alternating;
$mean = Engine::summary(Engine::combine($hosts, 'mean', 0, $monthSeconds), 0, $monthSeconds);
$summary = Engine::weightedSummaries([$mean, $up], [1, 1], $monthSeconds);
approximate($summary['score'], (100 * 9.5 / 11 + 100) / 2, 'Fractional item means can be combined with monthly SLA');
verifySla($summary['unknown'] === 0.0 && $summary['coverage'] === 100.0, 'Floating-point residue does not invent data gaps');
$microGap = ['up' => $monthSeconds - 0.000001, 'down' => 0.0, 'unknown' => 0.000001, 'score' => null];
$summary = Engine::weightedSummaries([$microGap, $up], [1, 1], $monthSeconds);
verifySla($summary['score'] === null && $summary['unknown'] > 0, 'Comparison tolerance never discards a real microsecond gap');
unset($hosts, $alternating, $mean);

API::reset(); $state = slaFixtureCalculation();
$projection = Store::projection(['state' => $state, 'id' => str_repeat('a', 64), 'sequence' => 20, 'created_at' => time()]);
verifySla($projection['progress']['slas_total'] === 3 && $projection['progress']['slas_done'] === 3, 'Progress projection exposes only safe SLA counts');
verifySla(!isset($projection['native_sla'], $projection['tasks']), 'Native metadata stays in the owned final report');
restore_error_handler();
echo 'PASS: ' . $assertions . " native SLA integration assertions.\n";
