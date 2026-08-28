<?php
// Pure observed-policy regression tests; no Zabbix API, files, or configuration writes.
// Run: php tests/availability-observed.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../AvailabilityEngine.php';
use Modules\Governance\AvailabilityEngine as Engine;

set_error_handler(static function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$assertions = 0;
function observedCheck($condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
}
function observedNear($actual, float $expected, string $message, float $tolerance = 1e-10): void {
    observedCheck(is_numeric($actual) && is_finite((float) $actual)
        && abs($actual - $expected) <= $tolerance, $message . ': ' . var_export($actual, true));
}
function observedReject(callable $operation, string $message): void {
    try { $operation(); }
    catch (InvalidArgumentException $exception) { observedCheck(true, $message); return; }
    observedCheck(false, $message . ': accepted invalid input');
}
function indicator(?float $score, float $coverage): array {
    return ['score' => $score, 'coverage' => $coverage];
}
function observedTimeline(array $states): array {
    $series = [];
    foreach ($states as $second => $state) {
        Engine::append($series, [$second, $second + 1, $state === 1 ? 1.0 : 0.0,
            $state === 0 ? 1.0 : 0.0, $state === -1 ? 1.0 : 0.0]);
    }
    return $series;
}

// One blind host cannot erase the actual evidence from the observed cohort.
$known = [[0, 50, 1.0, 0.0, 0.0], [50, 60, 0.0, 1.0, 0.0], [60, 100, 1.0, 0.0, 0.0]];
$blind = Engine::unknown(0, 100);
$strict = Engine::summary(Engine::combine([$known, $blind], 'any_down', 0, 100), 0, 100);
observedCheck($strict['score'] === null, 'legacy any-down still requires the missing host');
observedNear($strict['observed'], 0, 'legacy observed ratio is not silently changed');
observedNear($strict['unknown'], 90, 'legacy unknown duration preserved');
$cohort = Engine::summary(Engine::combine([$known, $blind], 'any_down_observed', 0, 100), 0, 100);
observedNear($cohort['observed'], 90, 'observed any-down is 90 percent, not zero');
observedNear($cohort['up'], 90, 'observed uptime retained');
observedNear($cohort['down'], 10, 'observed downtime retained');
observedNear($cohort['unknown'], 0, 'there is some cohort evidence throughout this period');
$hostCoverage = Engine::weightedIndicators([indicator(90, 100), indicator(null, 0)]);
observedNear($hostCoverage['coverage'], 50, 'source coverage includes the blind host');
observedCheck(!$hostCoverage['complete'], 'cohort evidence does not imply complete source coverage');
observedNear($cohort['coverage'], 100, 'cohort temporal coverage differs from source coverage');

foreach ([[], [$blind], [$blind, $blind], [[], []]] as $series) {
    $summary = Engine::summary(Engine::combine($series, 'any_down_observed', 0, 100), 0, 100);
    observedCheck($summary['score'] === null && $summary['observed'] === null, 'all missing sources have no indicator');
    observedNear($summary['unknown'], 100, 'all missing sources retain the whole unknown period');
    observedNear($summary['coverage'], 0, 'all missing sources have zero coverage');
}
$emptyPeriod = Engine::summary(Engine::combine([$known], 'any_down_observed', 0, 0), 0, 0);
observedCheck($emptyPeriod['observed'] === null && $emptyPeriod['score'] === null, 'empty period is not available');

// Required checks remain strict before their host enters an observed group.
$up = [[0, 100, 1.0, 0.0, 0.0]];
$down = [[0, 100, 0.0, 1.0, 0.0]];
$hostWithoutService = Engine::combine([$up, $blind], 'any_down', 0, 100);
$summary = Engine::summary(Engine::combine([$hostWithoutService], 'any_down_observed', 0, 100), 0, 100);
observedCheck($summary['observed'] === null, 'ICMP up does not prove a missing service check is up');
$hostDown = Engine::combine([$down, $blind], 'any_down', 0, 100);
$summary = Engine::summary(Engine::combine([$hostDown, $blind], 'any_down_observed', 0, 100), 0, 100);
observedNear($summary['observed'], 0, 'a real down result is a valid zero indicator');
observedNear($summary['down'], 100, 'confirmed down dominates missing checks and hosts');

// Different observed hosts can cover different portions of the month.
$firstHalf = [[0, 50, 1.0, 0.0, 0.0], [50, 100, 0.0, 0.0, 1.0]];
$secondHalf = [[0, 50, 0.0, 0.0, 1.0], [50, 100, 0.0, 1.0, 0.0]];
$summary = Engine::summary(Engine::combine([$firstHalf, $secondHalf], 'any_down_observed', 0, 100), 0, 100);
observedNear($summary['observed'], 50, 'cohort membership may change at exact boundaries');
observedNear($summary['unknown'], 0, 'no fabricated gap when another host has evidence');
observedNear(Engine::weightedIndicators([indicator(100, 50), indicator(0, 50)])['coverage'], 50,
    'disjoint cohort still has only half of source exposure covered');
$sparse = [[10, 20, 1.0, 0.0, 0.0], [40, 50, 0.0, 1.0, 0.0]];
$summary = Engine::summary(Engine::combine([$sparse], 'any_down_observed', 0, 100), 0, 100);
observedNear($summary['observed'], 50, 'absent leading, internal and trailing intervals stay outside observed denominator');
observedNear($summary['unknown'], 80, 'missing intervals are not invented uptime');

// Seeds carry evidence only until their actual configured expiration.
$binary = ['up' => ['op' => 'eq', 'a' => 1], 'down' => null];
$seed = Engine::samples([['clock' => -30, 'value' => 1]], $binary, 60, 0, 100);
$seedSummary = Engine::summary($seed, 0, 100);
observedNear($seedSummary['observed'], 100, 'valid pre-period seed provides observed data without a new sample');
observedNear($seedSummary['coverage'], 30, 'seed cannot cover the entire month');
observedNear($seedSummary['unknown'], 70, 'seed expiration remains unknown');
$unclassified = Engine::samples([['clock' => 0, 'value' => 'invalid']], $binary, 60, 0, 100);
observedCheck(Engine::summary($unclassified, 0, 100)['observed'] === null, 'received samples are not necessarily classifiable evidence');

$month = 31 * 86400;
$oneSecondDown = [[0, $month - 1, 1.0, 0.0, 0.0], [$month - 1, $month, 0.0, 1.0, 0.0]];
$summary = Engine::summary(Engine::combine([$oneSecondDown, Engine::unknown(0, $month)],
    'any_down_observed', 0, $month), 0, $month);
observedNear($summary['down'], 1, 'one-second observed outage is never rounded away');
observedNear($summary['observed'], 100 * (1 - 1 / $month), 'one-second monthly availability preserves precision');
observedCheck($summary['observed'] < 100, 'small observed outage cannot become 100 percent');

// Independent second-by-second oracle for the two any-down policies.
mt_srand(20260828);
for ($trial = 0; $trial < 120; $trial++) {
    $seconds = mt_rand(20, 150); $hostCount = mt_rand(1, 12); $hosts = []; $states = [];
    for ($host = 0; $host < $hostCount; $host++) {
        $states[$host] = [];
        for ($second = 0; $second < $seconds; $second++) { $states[$host][] = mt_rand(-1, 1); }
        $hosts[] = observedTimeline($states[$host]);
    }
    foreach (['any_down', 'any_down_observed'] as $mode) {
        $expected = [0, 0, 0];
        for ($second = 0; $second < $seconds; $second++) {
            $current = [];
            foreach ($states as $hostStates) { $current[] = $hostStates[$second]; }
            if (in_array(0, $current, true)) { $expected[1]++; }
            elseif ($mode === 'any_down_observed' ? !in_array(1, $current, true) : in_array(-1, $current, true)) { $expected[2]++; }
            else { $expected[0]++; }
        }
        $summary = Engine::summary(Engine::combine($hosts, $mode, 0, $seconds), 0, $seconds);
        foreach (['up', 'down', 'unknown'] as $index => $field) {
            observedNear($summary[$field], $expected[$index], $mode . ' oracle trial ' . $trial . ' ' . $field);
        }
        $knownSeconds = $expected[0] + $expected[1];
        if ($knownSeconds === 0) { observedCheck($summary['observed'] === null, $mode . ' oracle empty denominator'); }
        else { observedNear($summary['observed'], 100 * $expected[0] / $knownSeconds, $mode . ' oracle observed ratio'); }
        observedNear($summary['coverage'], 100 * $knownSeconds / $seconds, $mode . ' oracle temporal coverage');
    }
}

// Explicit weights are preserved despite different observation durations.
$weighted = Engine::weightedIndicators([indicator(100, 100), indicator(0, 10)], [4, 2]);
observedNear($weighted['score'], 100 * 4 / 6, '4/2 weights are not multiplied by coverage');
observedNear($weighted['coverage'], 70, 'all source weights remain in coverage');
observedNear($weighted['participating_weight'], 6, 'both observed scores participate');
observedNear($weighted['total_weight'], 6, 'total configured weight retained');
observedCheck($weighted['participants'] === 2 && $weighted['total_sources'] === 2 && !$weighted['complete'],
    'coverage below 100 is explicitly incomplete');
$weighted = Engine::weightedIndicators([indicator(100, 100), indicator(null, 0), indicator(0, 100)], [4, 2, 1]);
observedNear($weighted['score'], 80, 'null indicator excluded, valid zero retained');
observedNear($weighted['coverage'], 100 * 5 / 7, 'excluded score does not erase uncovered source weight');
observedNear($weighted['participating_weight'], 5, 'participating weight is explicit');
observedNear($weighted['total_weight'], 7, 'full configured weight is explicit');
observedCheck($weighted['participants'] === 2 && $weighted['total_sources'] === 3 && !$weighted['complete'],
    'missing participation cannot be declared complete');
$weighted = Engine::weightedIndicators([indicator(100, 100), indicator(0, 10), indicator(null, 0)]);
observedNear($weighted['score'], 50, 'default weights give equal score participation');
observedNear($weighted['coverage'], 110 / 3, 'default coverage counts all sources');

foreach ([[], [indicator(null, 0)], [indicator(null, 0), indicator(null, 0)]] as $indicators) {
    $weighted = Engine::weightedIndicators($indicators);
    observedCheck($weighted['score'] === null && $weighted['coverage'] === 0.0, 'no observed scores cannot invent availability');
    observedCheck($weighted['participants'] === 0 && $weighted['participating_weight'] === 0.0 && !$weighted['complete'],
        'empty participation remains explicit');
    observedCheck($weighted['total_sources'] === count($indicators), 'empty participation still reports scope size');
    observedNear($weighted['total_weight'], count($indicators), 'empty participation still reports configured weight');
}
$weighted = Engine::weightedIndicators([indicator(null, 50), indicator(100, 100)], [3, 1]);
observedNear($weighted['score'], 100, 'coverage on a missing score cannot become a score');
observedNear($weighted['coverage'], 62.5, 'available coverage remains independent of score participation');
observedCheck(!$weighted['complete'], 'missing score prevents complete even with some source coverage');
$weighted = Engine::weightedIndicators([indicator(null, 50), indicator(null, 100)], [3, 1]);
observedCheck($weighted['score'] === null && !$weighted['complete'] && $weighted['participants'] === 0
    && $weighted['participating_weight'] === 0.0, 'all missing scores have no score participation');
observedNear($weighted['coverage'], 62.5, 'all missing scores still preserve supplied source coverage');
observedNear($weighted['total_weight'], 4, 'all missing scores retain their configured coverage denominator');
$weighted = Engine::weightedIndicators([indicator(99, 100), indicator(25, 100)], [1, 2]);
observedCheck($weighted['complete'], 'complete means all evidence, not all sources meeting their target');
observedNear($weighted['coverage'], 100, 'full source coverage is exactly 100');
$numeric = Engine::weightedIndicators([['score' => '100', 'coverage' => '50'], ['score' => 0, 'coverage' => 100]], ['2', 1]);
observedNear($numeric['score'], 200 / 3, 'finite numeric API-style values are accepted');

// Small positive weights cannot erase a real gap or outage near 100.
$weighted = Engine::weightedIndicators([indicator(100, 100), indicator(99.99, 99.99)], [100000, 0.001]);
observedCheck($weighted['score'] < 100 && $weighted['coverage'] < 100 && !$weighted['complete'],
    'small child loss and data gap survive dominant weight');
observedNear($weighted['score'], 100 - 0.01 * 0.001 / 100000.001, 'tiny weighted score deficit', 2e-14);
$below100 = 99.99999999999999;
$weighted = Engine::weightedIndicators([indicator(100, 100), indicator($below100, $below100)], [100000, 0.001]);
observedCheck($weighted['score'] < 100 && $weighted['coverage'] < 100 && !$weighted['complete'],
    'sub-ULP weighted losses cannot round into perfect availability or coverage');
$weighted = Engine::weightedIndicators([indicator(0, 0), indicator(1e-12, 1e-12)], [100000, 0.001]);
$tinyPositive = 1e-12 * (0.001 / 100000.001);
observedCheck($weighted['score'] > 0 && $weighted['coverage'] > 0, 'tiny observed positives are not clamped to zero');
observedNear($weighted['score'], $tinyPositive, 'tiny positive score precision', $tinyPositive * 1e-12);
observedNear($weighted['coverage'], $tinyPositive, 'tiny positive coverage precision', $tinyPositive * 1e-12);
$weighted = Engine::weightedIndicators([indicator(100, 100), indicator(null, 0)], [100000, 0.001]);
observedCheck($weighted['score'] === 100.0 && $weighted['coverage'] < 100 && !$weighted['complete'],
    'perfect participating score does not hide the low-weight missing source');
observedCheck($weighted['participants'] === 1 && $weighted['total_sources'] === 2, 'low-weight exclusion remains countable');
$weights = [];
for ($i = 0; $i < 200; $i++) { $weights[] = mt_rand(1, 100000000) / 1000; }
$weighted = Engine::weightedIndicators(array_fill(0, 200, indicator(100, 100)), $weights);
observedCheck($weighted['score'] === 100.0 && $weighted['coverage'] === 100.0 && $weighted['complete'],
    'many fully observed perfect sources do not acquire rounding gaps');
$weighted = Engine::weightedIndicators(array_fill(0, 200, indicator(0, 100)), $weights);
observedCheck($weighted['score'] === 0.0 && $weighted['coverage'] === 100.0 && $weighted['complete'],
    'fully observed unavailability remains exact zero');

// Independent numerator/denominator oracle for monthly indicator means.
for ($trial = 0; $trial < 300; $trial++) {
    $count = mt_rand(1, 30); $indicators = []; $weights = [];
    $scoreNumerator = 0.0; $coverageNumerator = 0.0; $participatingWeight = 0.0; $totalWeight = 0.0;
    $participants = 0; $complete = true;
    for ($source = 0; $source < $count; $source++) {
        $weight = mt_rand(1, 100000) / 1000;
        $score = mt_rand(0, 3) ? mt_rand(0, 10000) / 100 : null;
        $coverage = $trial % 7 ? mt_rand(0, 10000) / 100 : 100.0;
        $indicators[] = indicator($score, $coverage); $weights[] = $weight;
        $totalWeight += $weight; $coverageNumerator += $coverage * $weight;
        if ($score !== null) { $participants++; $participatingWeight += $weight; $scoreNumerator += $score * $weight; }
        if ($score === null || $coverage !== 100.0) { $complete = false; }
    }
    $weighted = Engine::weightedIndicators($indicators, $weights);
    if ($participants) {
        observedNear($weighted['score'], $scoreNumerator / $participatingWeight, 'weighted score oracle ' . $trial);
    }
    else { observedCheck($weighted['score'] === null, 'weighted oracle has no participant'); }
    observedNear($weighted['coverage'], $coverageNumerator / $totalWeight, 'weighted coverage oracle ' . $trial);
    observedNear($weighted['participating_weight'], $participatingWeight, 'participating weight oracle ' . $trial);
    observedNear($weighted['total_weight'], $totalWeight, 'total weight oracle ' . $trial);
    observedCheck($weighted['participants'] === $participants && $weighted['total_sources'] === $count,
        'source counts oracle ' . $trial);
    observedCheck($weighted['complete'] === $complete, 'completeness oracle ' . $trial);
    $reversed = Engine::weightedIndicators(array_reverse($indicators), array_reverse($weights));
    if ($participants) {
        observedNear($reversed['score'], $weighted['score'], 'indicator order does not change score ' . $trial);
        observedNear($reversed['coverage'], $weighted['coverage'], 'indicator order does not change coverage ' . $trial);
    }
}

foreach ([0, -1, NAN, INF, -INF, null, true, false, '', 'invalid', []] as $invalid) {
    observedReject(static function() use ($invalid) { Engine::weightedIndicators([indicator(100, 100)], [$invalid]); }, 'invalid weight rejected');
}
foreach ([null, true, false, NAN, INF, -INF, -0.01, 100.01, '', 'invalid', []] as $invalid) {
    observedReject(static function() use ($invalid) { Engine::weightedIndicators([['score' => 100, 'coverage' => $invalid]]); }, 'invalid coverage rejected');
}
foreach ([true, false, NAN, INF, -INF, -0.01, 100.01, '', 'invalid', []] as $invalid) {
    observedReject(static function() use ($invalid) { Engine::weightedIndicators([['score' => $invalid, 'coverage' => 100]]); }, 'invalid score rejected');
}
foreach ([false, null, [], ['coverage' => 100], ['score' => 100]] as $invalid) {
    observedReject(static function() use ($invalid) { Engine::weightedIndicators([$invalid]); }, 'malformed indicator rejected');
}
observedReject(static function() { Engine::weightedIndicators([indicator(100, 100)], [1, 2]); }, 'extra weight rejected');
observedReject(static function() { Engine::weightedIndicators([], [1]); }, 'weight without source rejected');
observedReject(static function() {
    Engine::weightedIndicators([indicator(100, 100), indicator(100, 100)], [PHP_FLOAT_MAX, PHP_FLOAT_MAX]);
}, 'overflowing total weight rejected');

restore_error_handler();
echo 'Availability observed: ' . $assertions . " assertions passed.\n";
