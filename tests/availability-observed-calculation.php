<?php
// Synthetic end-to-end item history and checkpoint tests; no real Zabbix or file writes.
// Run: php -d extension=mbstring tests/availability-observed-calculation.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Reuse the legacy API fixture and run its existing regressions before observed cases.
require __DIR__ . '/availability-calculation.php';
require_once __DIR__ . '/../AvailabilitySla.php';
require_once __DIR__ . '/../AvailabilityJobStore.php';
use Modules\Governance\AvailabilityCalculation as ObservedCalculation;
use Modules\Governance\AvailabilityConfig as ObservedConfig;
use Modules\Governance\AvailabilityEngine as ObservedEngine;
use Modules\Governance\AvailabilityJobStore as ObservedJobStore;
use Modules\Governance\AvailabilitySla as ObservedSla;

$legacyAssertions = $assertions;
set_error_handler(static function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function observedConfig(string $mode = 'any_down', int $age = 100): array {
    $config = configuration($mode, $age);
    $config['data_policy'] = 'observed';
    return $config;
}
function observedApprox($actual, float $expected, string $message, float $tolerance = 1e-8): void {
    verify(is_numeric($actual) && is_finite((float) $actual) && abs($actual - $expected) <= $tolerance,
        $message . ': ' . var_export($actual, true) . ' != ' . $expected);
}
function observedResult(array $config, int $seconds = 100, int $operations = 4): array {
    $from = strtotime('2026-05-01 UTC');
    $state = finishCalculation(ObservedCalculation::create($config, '2026-05', -1, $from + $seconds), $operations);
    verify($state['status'] === 'complete', 'observed calculation completes: ' . ($state['error'] ?? $state['phase']));
    return ObservedCalculation::result($state);
}
function oneCheck(array $config, string $key = 'ping', int $age = 100): array {
    $check = $config['departments'][0]['technologies'][0]['checks'][0];
    $check['key'] = $key; $check['max_age'] = $age;
    $config['departments'][0]['technologies'][0]['checks'] = [$check];
    return $config;
}
function dailyConserves(array $daily, array $summary, string $label): void {
    foreach (['up', 'down', 'unknown'] as $field) {
        $sum = 0.0;
        foreach ($daily as $day) { $sum += $day['summary'][$field]; }
        observedApprox($sum, $summary[$field], $label . ' daily ' . $field, 1e-6);
    }
}
function observedCheckpointBytes(array $state, int $sequence): int {
    $job = ['id' => str_repeat('a', 64), 'owner' => '1', 'sequence' => $sequence,
        'created_at' => 1, 'updated_at' => 1, 'state' => $state];
    $metadata = $job; $metadata['state'] = null; $metadata['status'] = $state['status'];
    $header = json_encode($metadata);
    $payload = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    verify(is_string($header) && is_string($payload), 'job checkpoint is serializable');
    return strlen($header) + strlen($payload) + 10;
}
function currentHostOracle(array $checks, string $hostid, int $from, int $seconds): array {
    $states = [];
    for ($second = 0; $second < $seconds; $second++) {
        $clock = $from + $second; $checkStates = [];
        foreach ($checks as $check) {
            $last = null;
            foreach (API::$history[$hostid . '-' . $check['key']] ?? [] as $row) {
                if ($row['clock'] <= $clock && ($last === null || [$row['clock'], $row['ns']] > [$last['clock'], $last['ns']])) {
                    $last = $row;
                }
            }
            if ($last === null || $last['clock'] + $check['max_age'] <= $clock) { $checkStates[] = -1; }
            else { $checkStates[] = $last['value'] === '1' ? 1 : ($last['value'] === '0' ? 0 : -1); }
        }
        $states[] = in_array(0, $checkStates, true) ? 0 : (in_array(-1, $checkStates, true) ? -1 : 1);
    }
    $up = count(array_filter($states, static function($state) { return $state === 1; }));
    $down = count(array_filter($states, static function($state) { return $state === 0; }));
    return ['states' => $states, 'score' => $up + $down ? 100 * $up / ($up + $down) : null,
        'coverage' => 100 * ($up + $down) / $seconds];
}

$formatState = ObservedCalculation::create(observedConfig(), '2026-05', -1, strtotime('2026-05-01 UTC') + 100);
verify($formatState['format'] === 3, 'trend-aware daily host checkpoints use state format 3');
$formatState['format'] = 1;
rejects(static function() use ($formatState) { (new ObservedCalculation())->advance($formatState); },
    'pre-host-daily checkpoints are rejected instead of being mixed into a new report');

$from = strtotime('2026-05-01 UTC');
fixture(2); $config = observedConfig();
API::$history['1-ping'] = [sample($from, 1)];
API::$history['1-service'] = [sample($from, 1), sample($from + 50, 0), sample($from + 60, 1)];
$report = observedResult($config, 100, 1);
$department = $report['departments'][0]; $technology = $department['technologies'][0];
verify($report['data_policy'] === 'observed' && $report['processing']['data_policy'] === 'observed', 'policy is explicit in report and audit');
verify($report['partial'] && $report['to'] === $from + 100, 'observed policy does not change the frozen report cutoff');
observedApprox($technology['observation']['score'], 90, 'known 90/10 host plus blind host gives 90 percent');
observedApprox($technology['observation']['coverage'], 50, 'source coverage includes both scoped hosts');
observedApprox($technology['observation']['temporal_coverage'], 100, 'cohort temporal coverage is separately identified');
verify(!$technology['observation']['complete'] && $technology['observation']['participants'] === 1
    && $technology['observation']['total_sources'] === 2, 'one observed participant does not hide two-host scope');
verify($technology['summary']['score'] === null, 'strict monthly indicator remains inconclusive');
observedApprox($technology['summary']['observed'], 0, 'strict any-down descriptive ratio is preserved');
observedApprox($technology['summary']['unknown'], 90, 'strict unknown period is preserved');
observedApprox($department['observation']['score'], 90, 'single-technology department uses observed indicator');
observedApprox($department['observation']['coverage'], 50, 'department retains source rather than cohort coverage');
verify(!$department['observation']['complete'], 'department cannot be complete with a blind host');
verify($technology['observation']['aggregation'] === 'any_down_observed', 'cohort aggregation is explicit');
verify($department['observation']['aggregation'] === 'weighted_technology_indicators', 'department aggregation is explicit');
verify($technology['data_quality'] === ['hosts_with_data' => 1, 'hosts_without_data' => 1,
    'checks_total' => 4, 'checks_not_queried' => 0, 'checks_without_known_time' => 2], 'diagnostics distinguish queried blind sources');
foreach ($technology['hosts'] as $host) {
    foreach ($host['sources'] as $source) { verify($source['history_queried'] === true, 'empty history was actually queried'); }
}
verify($technology['observation']['evidence_from'] === $from && $technology['observation']['evidence_to'] === $from + 100,
    'cohort evidence boundaries use the observed period');
verify($technology['observation']['interval_count'] === 1
    && $technology['observation']['intervals'][0][0] === $from + 50
    && $technology['observation']['intervals'][0][1] === $from + 60, 'cohort interval list contains only the real outage');
$hostDaily = array_column($technology['hosts'], 'daily');
verify(count($hostDaily[0]) === 1 && count($hostDaily[1]) === 1
    && array_keys($hostDaily[0][0]) === [0, 1], 'host daily output is one compact positional point per report day');
observedApprox($hostDaily[0][0][0], 90, 'known host daily score uses its observed evidence');
observedApprox($hostDaily[0][0][1], 100, 'known host daily coverage');
verify($hostDaily[1][0][0] === null, 'blind host daily score remains N/A');
observedApprox($hostDaily[1][0][1], 0, 'blind host daily coverage remains zero');
verify($technology['observation']['daily'][0]['day'] === '2026-05-01', 'technology calendar labels compact host points');
observedApprox($technology['observation']['daily'][0]['score'], 90, 'daily cohort score ignores only the blind host');
observedApprox($technology['observation']['daily'][0]['coverage'], 50, 'daily cohort coverage includes the blind host');
verify($technology['observation']['daily'][0]['participants'] === 1
    && $technology['observation']['daily'][0]['total_sources'] === 2, 'daily cohort participation retains full host scope');
observedApprox($department['observation']['daily'][0]['score'], 90, 'department daily score follows the technology indicator');
observedApprox($department['observation']['daily'][0]['coverage'], 50, 'department daily coverage follows full configured scope');
dailyConserves($technology['observation']['daily'], $technology['observation']['summary'], 'cohort');
dailyConserves($technology['daily'], $technology['summary'], 'strict');
dailyConserves($department['observation']['daily'], $department['observation']['summary'], 'department observation');

// Identical historical input and rules keep every legacy duration, daily value and interval unchanged.
$observedCalls = API::$calls; API::$calls = [];
$strictConfig = $config; unset($strictConfig['data_policy']);
$strictReport = observedResult($strictConfig, 100, 1);
$strictTechnology = $strictReport['departments'][0]['technologies'][0];
verify($strictReport['data_policy'] === 'strict', 'legacy configurations default to strict');
verify(!isset($strictTechnology['observation'], $strictTechnology['hosts'][0]['observation'],
    $strictReport['departments'][0]['observation']), 'strict policy has no observed replacement result');
foreach (['summary', 'daily', 'intervals', 'interval_count'] as $field) {
    verify($strictTechnology[$field] === $technology[$field], 'legacy field unchanged: ' . $field);
}
foreach ($strictTechnology['hosts'] as $index => $host) {
    verify($host['summary'] === $technology['hosts'][$index]['summary'], 'legacy host summary unchanged');
    verify($host['sources'] === $technology['hosts'][$index]['sources'], 'legacy item evidence unchanged');
}
observedApprox($strictTechnology['hosts'][0]['daily'][0][0], 90, 'complete strict host day retains its final score');
verify($strictTechnology['hosts'][1]['daily'][0][0] === null
    && $strictTechnology['hosts'][1]['daily'][0][1] == 0, 'strict blind host day remains N/A with zero coverage');
verify($strictReport['departments'][0]['summary'] === $department['summary'], 'legacy department summary unchanged');
verify(API::$calls === $observedCalls, 'observed policy does not silently change the history query scope');

// No known state anywhere never becomes a perfect observed score.
fixture(2); $report = observedResult(observedConfig());
$department = $report['departments'][0]; $technology = $department['technologies'][0];
verify($technology['observation']['score'] === null && $department['observation']['score'] === null, 'all unknown remains N/A');
observedApprox($technology['observation']['coverage'], 0, 'all unknown source coverage');
verify($technology['observation']['participants'] === 0 && $technology['observation']['total_sources'] === 2,
    'all excluded hosts remain visible in scope');
verify($department['observation']['participating_weight'] == 0 && $department['observation']['total_weight'] == 4,
    'all excluded technology retains its configured weight');
verify($technology['data_quality']['hosts_without_data'] === 2 && $technology['data_quality']['checks_not_queried'] === 0,
    'empty queried histories differ from unqueried checks');
verify($technology['observation']['evidence_from'] === null && $technology['observation']['evidence_to'] === null,
    'no evidence does not fabricate evidence boundaries');
foreach ($technology['hosts'] as $host) {
    verify($host['daily'][0][0] === null && $host['daily'][0][1] == 0,
        'all-unknown host day is compact N/A with zero coverage');
}
verify($technology['observation']['daily'][0]['score'] === null
    && $technology['observation']['daily'][0]['coverage'] == 0, 'all-unknown cohort day remains N/A');
dailyConserves($technology['observation']['daily'], $technology['observation']['summary'], 'all unknown');

// An unknown required service cannot be replaced by an available ICMP check.
fixture(1); $config = observedConfig();
API::$items = [API::$items[0]];
API::$history['1-ping'] = [sample($from, 1)];
API::$history['1-service'] = [sample($from, 1)]; // Deliberately unreachable: the configured item is absent.
$technology = observedResult($config)['departments'][0]['technologies'][0];
verify($technology['observation']['score'] === null && $technology['hosts'][0]['observation']['score'] === null,
    'missing required service never turns into host UP');
verify($technology['hosts'][0]['sources'][0]['history_queried'] === true
    && $technology['hosts'][0]['sources'][1]['history_queried'] === false, 'queried and missing item distinguished');
verify($technology['data_quality']['checks_not_queried'] === 1 && $technology['data_quality']['hosts_without_data'] === 1,
    'unqueried required source explains a host without known state');
verify($technology['hosts'][0]['daily'][0][0] === null && $technology['hosts'][0]['daily'][0][1] == 0,
    'missing required service keeps the host day unknown');
API::$history['1-ping'] = [sample($from, 0)];
$technology = observedResult($config)['departments'][0]['technologies'][0];
observedApprox($technology['observation']['score'], 0, 'known ICMP DOWN dominates missing service check');
observedApprox($technology['observation']['coverage'], 100, 'known DOWN determines the host state throughout');
verify($technology['data_quality']['checks_not_queried'] === 1 && $technology['data_quality']['hosts_with_data'] === 1,
    'known host DOWN does not conceal the unqueried service diagnostic');
observedApprox($technology['hosts'][0]['daily'][0][0], 0, 'known DOWN wins over a missing required check in the host chart');
observedApprox($technology['hosts'][0]['daily'][0][1], 100, 'known DOWN covers the whole host day');

// A checked source with unclassifiable numeric values is not a source that was never queried.
fixture(1); $config = oneCheck(observedConfig());
$config['departments'][0]['technologies'][0]['checks'][0]['down'] = ['op' => 'eq', 'a' => 0];
API::$history['1-ping'] = [sample($from, 2)];
$technology = observedResult($config)['departments'][0]['technologies'][0];
$source = $technology['hosts'][0]['sources'][0];
verify($source['sample_count'] === 1 && $source['history_queried'] === true && $source['summary']['observed'] === null,
    'received but unclassified value is genuine unknown evidence');
verify($technology['data_quality']['checks_without_known_time'] === 1 && $technology['data_quality']['checks_not_queried'] === 0,
    'unclassifiable data diagnosis is distinct from missing query');
verify($technology['observation']['score'] === null, 'sample count does not decide participation');
fixture(1); $config = oneCheck(observedConfig());
$config['departments'][0]['technologies'][0]['checks'][0]['max_age'] = null;
API::$items[0]['delay'] = '{$UNRESOLVED}';
API::$history['1-ping'] = [sample($from, 1)];
$technology = observedResult($config)['departments'][0]['technologies'][0];
verify($technology['hosts'][0]['sources'][0]['history_queried'] === false
    && $technology['data_quality']['checks_not_queried'] === 1, 'unresolved validity explicitly reports an unqueried history');
verify($technology['observation']['score'] === null, 'observed policy does not bypass unresolved validity');

// A valid seed contributes only its remaining lifetime, even with zero samples inside the month.
fixture(1); $config = oneCheck(observedConfig(), 'ping', 60);
API::$history['1-ping'] = [sample($from - 30, 1)];
$technology = observedResult($config)['departments'][0]['technologies'][0];
$source = $technology['hosts'][0]['sources'][0];
verify($source['sample_count'] === 0 && $source['seed_clock'] === $from - 30 && $source['history_queried'] === true,
    'pre-period seed is queried but not counted as an in-period sample');
observedApprox($technology['observation']['score'], 100, 'seed provides a valid observed indicator');
observedApprox($technology['observation']['coverage'], 30, 'seed lifetime bounds coverage');
verify($technology['data_quality']['hosts_with_data'] === 1 && $technology['data_quality']['hosts_without_data'] === 0,
    'seed evidence prevents a false host-without-data diagnosis');
verify($technology['observation']['evidence_to'] === $from + 30 && !$technology['observation']['complete'],
    'seed cannot be extrapolated after its validity');
observedApprox($technology['hosts'][0]['daily'][0][0], 100, 'observed host day scores only its real seed evidence');
observedApprox($technology['hosts'][0]['daily'][0][1], 30, 'observed host day exposes partial seed coverage');
$strictSeedConfig = $config; unset($strictSeedConfig['data_policy']);
$strictSeedHost = observedResult($strictSeedConfig)['departments'][0]['technologies'][0]['hosts'][0];
verify($strictSeedHost['daily'][0][0] === null, 'strict host day vetoes the same real gap');
observedApprox($strictSeedHost['daily'][0][1], 30, 'strict and observed host days report identical source coverage');
verify($strictSeedHost['summary'] === $technology['hosts'][0]['summary'], 'policy selection does not alter host durations');
fixture(1); $config = oneCheck(observedConfig(), 'ping', 10);
API::$history['1-ping'] = [sample($from, 1), sample($from + 100, 0), sample($from + 200, 0)];
$technology = observedResult($config)['departments'][0]['technologies'][0];
observedApprox($technology['observation']['coverage'], 10, 'last known value is not forward-filled through missing history');
observedApprox($technology['observation']['summary']['unknown'], 90, 'unknown tail remains descriptive evidence');
verify($technology['hosts'][0]['sources'][0]['sample_count'] === 1
    && $technology['observation']['evidence_to'] === $from + 10, 'samples at and beyond the exclusive cutoff are not consulted');

// The production-style flexible interval can resolve independently for ICMP and PostgreSQL.
fixture(1); $config = observedConfig();
$pgKey = 'pgsql.ping["{$PG.URI}","{$PG.USER}","{$PG.PASSWORD}"]';
foreach ($config['departments'][0]['technologies'][0]['checks'] as &$check) { $check['max_age'] = null; }
unset($check);
$config['departments'][0]['technologies'][0]['checks'][0]['key'] = 'icmpping';
$config['departments'][0]['technologies'][0]['checks'][1]['key'] = $pgKey;
API::$items[0]['key_'] = 'icmpping'; API::$items[1]['key_'] = $pgKey;
API::$items[0]['delay'] = API::$items[1]['delay'] = '1m;30s/1-7,00:00-24:00';
API::$items[1]['preprocessing'] = [['type' => 20, 'params' => '1h']];
for ($second = 0; $second < 7300; $second += 60) { API::$history['1-ping'][] = sample($from + $second, 1); }
API::$history['1-service'] = [sample($from, 1), sample($from + 3600, 1), sample($from + 7200, 1)];
$technology = observedResult($config, 7300)['departments'][0]['technologies'][0];
observedApprox($technology['observation']['score'], 100, 'flexible polling and hourly heartbeat yield a fully observed available period');
observedApprox($technology['observation']['coverage'], 100, 'supported flexible interval no longer skips the actual history');
$sources = $technology['hosts'][0]['sources'];
verify($sources[0]['max_age'] === 3600 && $sources[1]['max_age'] === 3720,
    'ICMP uses the hourly floor while PostgreSQL retains heartbeat tolerance');
verify($sources[0]['history_queried'] && $sources[1]['history_queried']
    && $technology['data_quality']['checks_not_queried'] === 0, 'both flexible item histories are actually queried');
verify($sources[1]['key'] === $pgKey && $sources[1]['sample_count'] === 3, 'macro-bearing key is matched exactly without expanding secrets');
API::$history['1-ping'] = [sample($from, 1)];
$technology = observedResult($config, 7300)['departments'][0]['technologies'][0];
observedApprox($technology['observation']['score'], 100, 'available observed evidence is separate from the missing ICMP tail');
observedApprox($technology['observation']['coverage'], 100 * 3600 / 7300,
    'PostgreSQL heartbeat never extends ICMP beyond its own hourly window');
observedApprox($technology['observation']['summary']['unknown'], 3700,
    'ICMP becomes unknown after its own hourly evidence expires');
verify(!$technology['observation']['complete'], 'sparse ICMP history cannot certify the whole period');

// Host mean preserves equal votes, not sample count or observed duration as hidden weights.
fixture(2); $config = oneCheck(observedConfig('mean'), 'ping', 10);
for ($second = 0; $second < 100; $second += 10) { API::$history['1-ping'][] = sample($from + $second, 1); }
API::$history['2-ping'] = [sample($from, 0)];
$meanReport = observedResult($config); $technology = $meanReport['departments'][0]['technologies'][0];
observedApprox($technology['observation']['score'], 50, 'two hosts keep equal votes despite 100/10 coverage');
observedApprox($technology['observation']['coverage'], 55, 'host mean coverage uses every scoped host');
observedApprox($technology['summary']['observed'], 1000 / 11, 'legacy pooled descriptive ratio is not overwritten');
observedApprox($technology['observation']['summary']['observed'], 1000 / 11, 'observed durations are descriptive, not the score denominator');
verify($technology['observation']['aggregation'] === 'mean_host_indicators' && !$technology['observation']['complete'],
    'host indicator aggregation is explicit and incomplete');
observedApprox($technology['hosts'][0]['daily'][0][0], 100, 'fully observed host daily vote');
observedApprox($technology['hosts'][0]['daily'][0][1], 100, 'fully observed host daily coverage');
observedApprox($technology['hosts'][1]['daily'][0][0], 0, 'short observed outage keeps a full host vote');
observedApprox($technology['hosts'][1]['daily'][0][1], 10, 'short observed outage exposes only ten percent coverage');
observedApprox($technology['observation']['daily'][0]['score'], 50, 'daily host mean is unbiased by unequal coverage');
observedApprox($technology['observation']['daily'][0]['coverage'], 55, 'daily host mean coverage includes both hosts');
observedApprox($technology['observation']['daily'][0]['summary']['observed'], 1000 / 11,
    'daily descriptive durations intentionally differ from the 50 percent indicator');
observedApprox($meanReport['departments'][0]['observation']['daily'][0]['score'], 50,
    'single-technology department keeps the daily host indicator');
dailyConserves($technology['observation']['daily'], $technology['observation']['summary'], 'host mean');

// Department 4/2 keeps configured weights when technologies cover different durations.
fixture(1); $config = oneCheck(observedConfig(), 'ping', 100);
$secondTechnology = $config['departments'][0]['technologies'][0];
$secondTechnology['name'] = 'SQL'; $secondTechnology['weight'] = 2;
$secondTechnology['checks'][0]['key'] = 'service'; $secondTechnology['checks'][0]['max_age'] = 10;
$config['departments'][0]['technologies'][] = $secondTechnology;
API::$history['1-ping'] = [sample($from, 1)]; API::$history['1-service'] = [sample($from, 0)];
$department = observedResult($config)['departments'][0];
observedApprox($department['observation']['score'], 200 / 3, 'department 4/2 observed score is 66.6667, not pooled 95.238');
observedApprox($department['observation']['coverage'], 70, 'department source coverage is 70 percent');
observedApprox($department['observation']['summary']['observed'], 2000 / 21, 'descriptive pooled duration remains separate');
observedApprox($department['observation']['daily'][0]['score'], 200 / 3,
    'daily department applies 4/2 weights to child indicators');
observedApprox($department['observation']['daily'][0]['coverage'], 70,
    'daily department coverage keeps both configured weights');
observedApprox($department['observation']['daily'][0]['summary']['observed'], 2000 / 21,
    'daily equivalent duration is not mislabeled as the weighted score');
verify($department['summary']['score'] === null && !$department['observation']['complete'], 'observed indicator does not certify a complete monthly result');
verify($department['observation']['participants'] === 2 && $department['observation']['total_sources'] === 2,
    'both technologies participate despite their different coverage');
dailyConserves($department['observation']['daily'], $department['observation']['summary'], 'department mean');
$blankTechnology = $secondTechnology; $blankTechnology['name'] = 'Empty'; $blankTechnology['weight'] = 1;
$blankTechnology['groups'] = 'Equipes externas'; // Existing group with no hosts.
$config['departments'][0]['technologies'][] = $blankTechnology;
$department = observedResult($config)['departments'][0];
observedApprox($department['observation']['score'], 200 / 3, 'technology with no evidence is explicitly excluded from observed score');
observedApprox($department['observation']['coverage'], 60, 'empty technology keeps its configured coverage weight');
verify($department['observation']['participating_weight'] == 6 && $department['observation']['total_weight'] == 7
    && $department['observation']['participants'] === 2 && $department['observation']['total_sources'] === 3,
    'excluded technology and weight share remain explicit');
verify($department['technologies'][2]['observation']['score'] === null
    && $department['technologies'][2]['data_quality']['hosts_with_data'] === 0, 'empty group does not become a 100 percent technology');
observedApprox($department['observation']['daily'][0]['score'], 200 / 3,
    'blank daily technology is excluded from the score denominator');
observedApprox($department['observation']['daily'][0]['coverage'], 60,
    'blank daily technology retains its configured coverage share');

// Tiny weighted gaps remain incomplete even when the participating indicators are all 100.
fixture(1); $config = oneCheck(observedConfig(), 'ping', 86400);
$config['departments'][0]['technologies'][0]['weight'] = 100000;
$small = $config['departments'][0]['technologies'][0]; $small['name'] = 'Tiny weight'; $small['weight'] = 0.001;
$small['checks'][0]['key'] = 'service'; $config['departments'][0]['technologies'][] = $small;
$monthSeconds = 31 * 86400;
for ($second = 0; $second < $monthSeconds; $second += 86400) { API::$history['1-ping'][] = sample($from + $second, 1); }
for ($second = 0; $second < $monthSeconds - 86401; $second += 86400) { API::$history['1-service'][] = sample($from + $second, 1); }
API::$history['1-service'][] = sample($from + $monthSeconds - 86401, 1);
$report = observedResult($config, $monthSeconds);
$department = $report['departments'][0];
verify(!$report['partial'] && $department['observation']['score'] == 100, 'closed month may be perfect only within the observed evidence');
verify($department['observation']['coverage'] < 100 && !$department['observation']['complete'],
    'one-second gap at tiny weight cannot round into complete source coverage');
verify($department['summary']['score'] === null && $department['summary']['unknown'] > 0,
    'strict weighted gap remains positive and blocks a final indicator');
dailyConserves($department['observation']['daily'], $department['observation']['summary'], 'tiny-weight month');
API::$history['1-service'][] = sample($from + $monthSeconds - 1, 0);
$department = observedResult($config, $monthSeconds)['departments'][0];
verify($department['observation']['score'] < 100 && $department['observation']['coverage'] == 100
    && $department['observation']['complete'], 'tiny confirmed outage is retained separately from coverage');
verify($department['observation']['summary']['down'] > 0, 'tiny confirmed outage retains descriptive duration');

// UTC midnight boundary, a gap on the second day, and a later observed host.
fixture(2); $config = oneCheck(observedConfig(), 'ping', 86400);
API::$history['1-ping'] = [sample($from, 1)];
API::$history['2-ping'] = [sample($from + 86400 + 1800, 0)];
$technology = observedResult($config, 2 * 86400)['departments'][0]['technologies'][0];
verify(count($technology['observation']['daily']) === 2, 'observed daily breakdown preserves both civil days');
observedApprox($technology['observation']['daily'][0]['summary']['up'], 86400, 'first day has observed uptime');
observedApprox($technology['observation']['daily'][1]['summary']['unknown'], 1800, 'second-day leading gap is not backfilled');
observedApprox($technology['observation']['daily'][1]['summary']['down'], 84600, 'second-day real outage begins only at its sample');
verify(count($technology['hosts'][0]['daily']) === 2 && count($technology['hosts'][1]['daily']) === 2,
    'every host has one compact point for each civil day');
observedApprox($technology['hosts'][0]['daily'][0][0], 100, 'first host is available on its evidenced day');
verify($technology['hosts'][0]['daily'][1][0] === null, 'expired first host is N/A on the following day');
verify($technology['hosts'][1]['daily'][0][0] === null, 'later host is N/A before its first evidence');
observedApprox($technology['hosts'][1]['daily'][1][0], 0, 'later host reports its real second-day outage');
observedApprox($technology['hosts'][1]['daily'][1][1], 100 * 84600 / 86400,
    'later host daily coverage excludes its leading gap');
observedApprox($technology['observation']['daily'][0]['score'], 100, 'daily cohort follows the only known first-day host');
observedApprox($technology['observation']['daily'][0]['coverage'], 50, 'first-day source coverage includes both hosts');
observedApprox($technology['observation']['daily'][1]['score'], 0, 'daily cohort follows the known second-day outage');
observedApprox($technology['observation']['daily'][1]['coverage'], 50 * 84600 / 86400,
    'second-day source coverage averages both scoped hosts');
dailyConserves($technology['observation']['daily'], $technology['observation']['summary'], 'midnight cohort');
dailyConserves($technology['daily'], $technology['summary'], 'midnight strict');

// Monthly host votes are not reconstructed by averaging daily scores: a host that
// has no evidence on day two still keeps its one monthly vote from day one.
fixture(2); $config = oneCheck(observedConfig('mean'), 'ping', 86400);
API::$history['1-ping'] = [sample($from, 1), sample($from + 86400, 1)];
API::$history['2-ping'] = [sample($from, 0)];
$twoDay = observedResult($config, 2 * 86400)['departments'][0];
$technology = $twoDay['technologies'][0];
observedApprox($technology['observation']['score'], 50, 'monthly mean keeps one vote for each participating host');
observedApprox($technology['observation']['coverage'], 75, 'monthly coverage averages full and half-month hosts');
observedApprox($technology['observation']['summary']['observed'], 200 / 3,
    'monthly equivalent durations remain descriptive');
observedApprox($technology['observation']['daily'][0]['score'], 50, 'day one includes both host votes');
observedApprox($technology['observation']['daily'][0]['coverage'], 100, 'day one has full source coverage');
observedApprox($technology['observation']['daily'][1]['score'], 100, 'day two excludes only the host without evidence');
observedApprox($technology['observation']['daily'][1]['coverage'], 50, 'day two still counts the blind host in coverage');
observedApprox($twoDay['observation']['daily'][0]['score'], 50, 'department day one follows child indicator hierarchy');
observedApprox($twoDay['observation']['daily'][1]['score'], 100, 'department day two follows child indicator hierarchy');
verify($technology['observation']['score'] != ($technology['observation']['daily'][0]['score']
    + $technology['observation']['daily'][1]['score']) / 2, 'monthly indicator is never fabricated from daily score averages');
verify($technology['hosts'][1]['daily'][0][0] == 0 && $technology['hosts'][1]['daily'][1][0] === null,
    'host chart exposes the exact day on which its evidence disappears');

// Daily observed durations follow local civil boundaries across a DST transition.
fixture(2); $config = oneCheck(observedConfig(), 'ping', 86400); $config['timezone'] = 'America/New_York';
$dstStart = new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone($config['timezone']));
$dstEnd = $dstStart->modify('+1 month')->getTimestamp();
for ($clock = $dstStart->getTimestamp(); $clock < $dstEnd; $clock += 3600) { API::$history['1-ping'][] = sample($clock, 1); }
$state = finishCalculation(ObservedCalculation::create($config, '2026-03', -1, $dstEnd), 1);
verify($state['status'] === 'complete', 'observed DST month completes');
$technology = ObservedCalculation::result($state)['departments'][0]['technologies'][0];
observedApprox($technology['observation']['score'], 100, 'known DST cohort remains available');
observedApprox($technology['observation']['coverage'], 50, 'blind DST host stays in source coverage');
$dstDay = array_values(array_filter($technology['observation']['daily'], static function($day) { return $day['day'] === '2026-03-08'; }))[0];
observedApprox($dstDay['summary']['up'], 23 * 3600, 'observed spring-transition day has 23 hours, not 24');
verify(count($technology['observation']['daily']) === 31 && $technology['summary']['score'] === null,
    'daily observed data does not replace the strict missing-host result');
verify(count($technology['hosts'][0]['daily']) === 31 && count($technology['hosts'][1]['daily']) === 31,
    'host chart aligns one compact point to every DST calendar day');
$dstIndex = array_search('2026-03-08', array_column($technology['observation']['daily'], 'day'), true);
verify($dstIndex !== false, 'DST host point has a shared technology day label');
observedApprox($technology['hosts'][0]['daily'][$dstIndex][0], 100, 'DST host score is not affected by the 23-hour day');
observedApprox($technology['hosts'][0]['daily'][$dstIndex][1], 100, 'DST host coverage uses the actual civil-day duration');
verify($technology['hosts'][1]['daily'][$dstIndex][0] === null, 'blind DST host remains N/A');
dailyConserves($technology['observation']['daily'], $technology['observation']['summary'], 'DST cohort');

// Checkpoints may cut every stage; each resumed result uses the frozen observed policy.
fixture(2); $config = observedConfig();
API::$history['1-ping'] = [sample($from, 1)];
API::$history['1-service'] = [sample($from, 1), sample($from + 50, 0), sample($from + 60, 1)];
$initial = ObservedCalculation::create($config, '2026-05', -1, $from + 100);
$config['data_policy'] = 'strict';
$runner = new ObservedCalculation(); $state = $initial; $phases = []; $slices = 0;
while ($state['status'] === 'running' && $slices++ < 200) {
    $phases[$state['phase']] = true;
    rejects(static function() use ($state) { ObservedCalculation::result($state); }, 'no report from an intermediate observed checkpoint');
    $state = checkpoint($runner->advance(checkpoint($state), 1));
}
verify($state['status'] === 'complete' && $slices < 200, 'single-operation observed checkpoints terminate');
$reportOne = ObservedCalculation::result($state);
$stateMany = finishCalculation(checkpoint($initial), 8);
$reportMany = ObservedCalculation::result($stateMany);
verify($reportOne['data_policy'] === 'observed' && $state['source_config']['data_policy'] === 'observed',
    'editing outside the checkpoint does not change its policy');
verify($reportOne['departments'] === $reportMany['departments'], 'stage slicing and JSON roundtrips do not change any result');
verify($reportOne['rows'] === $reportMany['rows'], 'stage slicing does not lose or repeat logical history samples');
foreach (['groups', 'scope_hosts', 'scope_items', 'check', 'history', 'host', 'technology', 'department', 'finish'] as $phase) {
    verify(isset($phases[$phase]), 'stage boundary exercised: ' . $phase);
}
verify($initial['status'] === 'running' && $initial['progress']['hosts_done'] === 0, 'advancing does not mutate caller checkpoint');

// The largest serialized checkpoint for the supported 25-host monthly scenario
// remains below the exact 16 MiB JobStore payload limit after adding 31 host points.
fixture(25); $monthSeconds = 31 * 86400; $config = observedConfig('any_down', 86400);
foreach (API::$items as $item) {
    for ($dayOffset = 0; $dayOffset < $monthSeconds; $dayOffset += 86400) {
        API::$history[$item['itemid']][] = sample($from + $dayOffset, 1);
    }
}
$scaleState = ObservedCalculation::create($config, '2026-05', -1, $from + $monthSeconds);
$scaleRunner = new ObservedCalculation(); $scaleSequence = 0; $maxCheckpointBytes = observedCheckpointBytes($scaleState, 0);
while ($scaleState['status'] === 'running' && ++$scaleSequence < 1000) {
    $scaleState = $scaleRunner->advance($scaleState, 1);
    $maxCheckpointBytes = max($maxCheckpointBytes, observedCheckpointBytes($scaleState, $scaleSequence));
    $scaleState = checkpoint($scaleState);
}
verify($scaleState['status'] === 'complete' && $scaleSequence < 1000, '25-host monthly checkpoint calculation completes');
verify($maxCheckpointBytes < ObservedJobStore::MAX_JOB_BYTES,
    '25-host monthly checkpoints remain below JobStore 16 MiB limit: ' . $maxCheckpointBytes);
$scaleReport = ObservedCalculation::result($scaleState);
$scaleTechnology = $scaleReport['departments'][0]['technologies'][0];
verify(count($scaleTechnology['hosts']) === 25 && count($scaleTechnology['observation']['daily']) === 31,
    '25-host monthly result retains its complete scope and shared calendar');
foreach ($scaleTechnology['hosts'] as $host) {
    verify(count($host['daily']) === 31, 'each scale host has 31 compact daily points');
    foreach ($host['daily'] as $point) {
        verify(array_keys($point) === [0, 1] && $point[0] == 100 && $point[1] == 100,
            'scale host point is compact and preserves score/coverage');
    }
}
observedApprox($scaleTechnology['observation']['score'], 100, '25-host cohort monthly score');
observedApprox($scaleTechnology['observation']['coverage'], 100, '25-host cohort monthly coverage');

// Independent raw-sample oracle: required check truth table, group cohort, host votes, technology weights.
mt_srand(9812026);
for ($trial = 0; $trial < 24; $trial++) {
    fixture(3); $seconds = 120; $config = observedConfig($trial % 2 ? 'mean' : 'any_down', 15);
    foreach ($config['departments'][0]['technologies'][0]['checks'] as &$check) { $check['down'] = ['op' => 'eq', 'a' => 0]; }
    unset($check);
    $second = $config['departments'][0]['technologies'][0]; $second['name'] = 'Other'; $second['weight'] = 2;
    $second['mode'] = $trial % 2 ? 'any_down' : 'mean'; $second['checks'] = [$second['checks'][1]];
    $config['departments'][0]['technologies'][] = $second;
    foreach (API::$items as $item) {
        for ($offset = -5; $offset < $seconds; $offset += mt_rand(1, 28)) {
            API::$history[$item['itemid']][] = sample($from + $offset, mt_rand(0, 2), mt_rand(0, 100));
        }
    }
    $department = observedResult($config, $seconds, $trial % 8 + 1)['departments'][0];
    $expectedDepartmentScore = 0.0; $expectedCoverage = 0.0; $participating = 0.0;
    foreach ($config['departments'][0]['technologies'] as $index => $techConfig) {
        $hosts = []; $coverage = 0.0; $scoreSum = 0.0; $hostParticipants = 0;
        foreach (API::$hosts as $host) {
            $hostOracle = currentHostOracle($techConfig['checks'], $host['hostid'], $from, $seconds);
            $hosts[] = $hostOracle; $coverage += $hostOracle['coverage'];
            if ($hostOracle['score'] !== null) { $scoreSum += $hostOracle['score']; $hostParticipants++; }
        }
        $expectedScore = $hostParticipants ? $scoreSum / $hostParticipants : null;
        if ($techConfig['mode'] === 'any_down') {
            $cohortUp = 0; $cohortDown = 0;
            for ($second = 0; $second < $seconds; $second++) {
                $values = [];
                foreach ($hosts as $hostOracle) { $values[] = $hostOracle['states'][$second]; }
                if (in_array(0, $values, true)) { $cohortDown++; }
                elseif (in_array(1, $values, true)) { $cohortUp++; }
            }
            $expectedScore = $cohortUp + $cohortDown ? 100 * $cohortUp / ($cohortUp + $cohortDown) : null;
        }
        $observation = $department['technologies'][$index]['observation'];
        if ($expectedScore === null) { verify($observation['score'] === null, 'oracle no technology denominator'); }
        else { observedApprox($observation['score'], $expectedScore, 'oracle technology score ' . $trial . ':' . $index); }
        observedApprox($observation['coverage'], $coverage / count($hosts), 'oracle technology source coverage');
        verify($observation['participants'] === $hostParticipants && $observation['total_sources'] === count($hosts),
            'oracle host participation');
        if ($expectedScore !== null) { $expectedDepartmentScore += $expectedScore * $techConfig['weight']; $participating += $techConfig['weight']; }
        $expectedCoverage += $coverage / count($hosts) * $techConfig['weight'];
        dailyConserves($observation['daily'], $observation['summary'], 'oracle technology');
    }
    observedApprox($department['observation']['score'], $expectedDepartmentScore / $participating, 'oracle weighted department score');
    observedApprox($department['observation']['coverage'], $expectedCoverage / 6, 'oracle weighted department coverage');
    equalSummary($department['summary'], oracle($config, $from, $from + $seconds), 'observed policy retains strict oracle');
}

// API execution failures still fail the job; observed mode only excludes missing evidence.
fixture(2); $config = observedConfig();
API::$history['1-ping'] = API::$history['1-service'] = [sample($from, 1)]; API::$failItem = '2-ping';
$state = finishCalculation(ObservedCalculation::create($config, '2026-05', -1, $from + 100));
verify($state['status'] === 'failed', 'API failure is not an excluded host');
rejects(static function() use ($state) { ObservedCalculation::result($state); }, 'failed observed job cannot publish a partial indicator');
verify(strpos($state['error'], 'Pretend') === false, 'observed failures keep backend errors private');
fixture(201);
$state = finishCalculation(ObservedCalculation::create(observedConfig(), '2026-05', -1, $from + 100));
verify($state['status'] === 'failed', 'observed policy does not silently omit hosts above the processing limit');
rejects(static function() use ($state) { ObservedCalculation::result($state); }, 'oversized observed scope cannot publish a partial report');

// Mixed-source aggregation stage only: SLA API parsing has its own independent fixtures.
function mixedObservedFixture(string $timezone = 'UTC', bool $excluded = false): array {
    $config = oneCheck(observedConfig());
    $nativeConfig = ['source' => 'sla', 'slaid' => '7', 'serviceid' => '42', 'name' => 'Native', 'weight' => 2, 'target' => 99.9];
    $config['departments'][0]['technologies'][] = $nativeConfig;
    $from = strtotime('2026-05-01 UTC'); $to = strtotime('2026-06-01 UTC'); $basis = $to - $from;
    $state = ObservedCalculation::create($config, '2026-05', -1, $to + 86400);
    $nativeDefinition = ['slaid' => '7', 'name' => 'Native', 'period' => '2', 'slo' => '99.9',
        'effective_date' => '1704067200', 'timezone' => $timezone, 'status' => '1', 'schedule' => [],
        'excluded_downtimes' => $excluded ? [['name' => 'Maintenance', 'period_from' => $from + 86400, 'period_to' => $from + 90000]] : []];
    $prepared = ObservedSla::prepare($nativeConfig, $state['report'], [$nativeDefinition],
        [['serviceid' => '42', 'name' => 'Service', 'created_at' => '1704067200']]);
    verify($prepared['ready'], 'native closed-month fixture is valid');
    $native = ObservedSla::interpret($prepared, ['periods' => [['period_from' => $prepared['metadata']['period_from'],
        'period_to' => $prepared['metadata']['period_to']]], 'serviceids' => ['42'],
        'sli' => [[['uptime' => $prepared['metadata']['basis_seconds'], 'downtime' => 0, 'sli' => 100,
            'error_budget' => 0, 'excluded_downtimes' => $prepared['metadata']['excluded_downtimes']]]]]);
    verify(!isset($native['processing_error']), 'native fixture is not a malformed API result');
    $itemSeries = [[$from, $from + intdiv($basis, 4), 1.0, 0.0, 0.0],
        [$from + intdiv($basis, 4), $from + intdiv($basis, 2), 0.0, 1.0, 0.0],
        [$from + intdiv($basis, 2), $to, 0.0, 0.0, 1.0]];
    $itemSummary = ObservedEngine::summary($itemSeries, $from, $to);
    $itemObservation = ObservedEngine::weightedIndicators([['score' => $itemSummary['observed'], 'coverage' => $itemSummary['coverage']]]);
    $itemObservation['summary'] = $itemSummary;
    $state['report']['departments'][0]['technologies'] = [
        ['source' => 'items', 'name' => 'Item', 'weight' => 4, 'target' => 99.9, 'summary' => $itemSummary,
            'observation' => $itemObservation, 'basis_seconds' => $basis, 'eligible_for_aggregation' => true],
        ['source' => 'sla', 'name' => 'Native', 'weight' => 2, 'target' => 99.9, 'summary' => $native['summary'],
            'native_sla' => $native['metadata'], 'eligible_for_aggregation' => $native['eligible_for_aggregation'],
            'basis_seconds' => $native['metadata']['basis_seconds']]
    ];
    $state['phase'] = 'department'; $state['scope_frozen_at'] = $to;
    $state['progress']['slas_done'] = $state['progress']['slas_total'];
    $state = (new ObservedCalculation())->advance(checkpoint($state), 2);
    verify($state['status'] === 'complete', 'mixed aggregation checkpoint completes');
    return ObservedCalculation::result($state)['departments'][0];
}
$mixed = mixedObservedFixture();
verify($mixed['aggregation_compatible'], 'observed policy preserves compatible native calendar');
observedApprox($mixed['observation']['score'], 200 / 3, 'observed items and native SLA preserve technology weights');
observedApprox($mixed['observation']['coverage'], 200 / 3, 'mixed source coverage retains missing item evidence');
verify($mixed['observation']['daily'] === [] && $mixed['daily'] === [] && !$mixed['daily_available'],
    'native monthly totals are not turned into a daily timeline');
foreach ([mixedObservedFixture('America/Cuiaba'), mixedObservedFixture('UTC', true)] as $mixed) {
    verify(!$mixed['aggregation_compatible'] && $mixed['summary']['score'] === null && !isset($mixed['observation']),
        'observed policy does not bypass incompatible native boundaries or exclusions');
    verify($mixed['technologies'][1]['summary']['score'] == 100, 'incompatible native individual score is still preserved');
}

restore_error_handler();
echo 'Availability observed calculation: ' . ($assertions - $legacyAssertions) . " additional assertions passed.\n";
