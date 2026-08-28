<?php
// Pure, synthetic SLA API fixtures. No credentials, database, or production API calls.
// Run: php tests/availability-sla.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../AvailabilitySla.php';
use Modules\Governance\AvailabilitySla as Sla;

set_error_handler(static function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$assertions = 0;
function check($truth, $message) {
    global $assertions;
    $assertions++;
    if (!$truth) { throw new RuntimeException($message); }
}
function near($actual, $expected, $message) {
    check(is_numeric($actual) && abs($actual - $expected) < 1e-9, $message . ': ' . var_export($actual, true));
}
function stamp($value, $timezone = 'America/Cuiaba') {
    return (new DateTimeImmutable($value, new DateTimeZone($timezone)))->getTimestamp();
}
function report($month = '2026-07', $timezone = 'America/Cuiaba') {
    $start = new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone($timezone));
    $end = $start->modify('+1 month')->getTimestamp();
    return ['month' => $month, 'timezone' => $timezone, 'from' => $start->getTimestamp(), 'to' => $end,
        'generated_at' => $end + 86400, 'partial' => false];
}
function fixtures($timezone = 'America/Cuiaba') {
    return [
        ['source' => 'sla', 'slaid' => '56', 'serviceid' => '73'],
        ['slaid' => '56', 'name' => 'Banco de dados', 'period' => '2', 'slo' => '99.9',
            'effective_date' => (string) stamp('2025-01-01 00:00:00', 'UTC'), 'timezone' => $timezone,
            'status' => '1', 'schedule' => [], 'excluded_downtimes' => []],
        ['serviceid' => '73', 'name' => 'PostgreSQL', 'created_at' => (string) stamp('2025-01-01 00:00:00')]
    ];
}
function prepared($slaChanges = [], $serviceChanges = [], $report = null, $systemTimezone = null) {
    list($technology, $sla, $service) = fixtures();
    return Sla::prepare($technology, $report ?? report(), [array_replace($sla, $slaChanges)],
        [array_replace($service, $serviceChanges)], $systemTimezone);
}
function response($prepared, $down = 0) {
    $total = $prepared['metadata']['basis_seconds'];
    $up = $total - $down;
    return ['periods' => [['period_from' => $prepared['metadata']['period_from'],
        'period_to' => $prepared['metadata']['period_to']]], 'serviceids' => [$prepared['metadata']['serviceid']],
        'sli' => [[['uptime' => $up, 'downtime' => $down, 'sli' => $total ? 100 * $up / $total : -1,
            'error_budget' => 0, 'excluded_downtimes' => []]]]];
}
function unavailable($result, $message) {
    check($result['summary']['score'] === null, $message . ': no final score');
    check($result['eligible_for_aggregation'] === false, $message . ': cannot contaminate aggregation');
    check(count($result['warnings']) > 0, $message . ': reason is explained');
}

$p = prepared();
check($p['ready'] && $p['eligible_for_aggregation'], 'monthly closed SLA is ready');
check($p['metadata']['period_seconds'] === 2678400, 'July has 31 civil days in Cuiaba');
check($p['metadata']['basis_seconds'] === 2678400, '24x7 contracted denominator');
check($p['metadata']['coverage_kind'] === 'scheduled', 'coverage is explicitly scheduled');
check($p['metadata']['schedule_kind'] === '24x7', 'empty native schedule means 24x7');
check($p['request']['period_from'] === report()['from'], 'request starts at native month boundary');
check($p['request']['period_to'] === report()['to'] - 1, 'request end is inclusive, not next month');
check($p['request']['periods'] === 1, 'request is bounded to one native month');
check($p['request']['serviceids'] === ['73'], 'explicit single-service request');
check($p['metadata']['calendar_key'] === Sla::calendarKey(report()['from'], report()['to']),
    'unexcluded 24x7 SLA is comparable to item-based calendar');

$ok = Sla::interpret($p, response($p));
check($ok['summary']['score'] === 100.0, 'native 100 percent is exact');
check($ok['summary']['coverage'] === 100.0 && $ok['summary']['unknown'] === 0.0, 'full scheduled coverage');
check($ok['eligible_for_aggregation'] && $ok['metadata']['state'] === 'complete', 'valid native result is eligible');
check(!array_key_exists('series', $ok) && !array_key_exists('daily', $ok), 'no fabricated timeline or daily distribution');
check($ok['metadata']['slo'] === 99.9, 'native SLO retained separately from measured SLI');
$oneSecond = Sla::interpret($p, response($p, 1));
near($oneSecond['summary']['down'], 1, 'one-second native outage preserved');
near($oneSecond['summary']['score'], 100 * (1 - 1 / 2678400), 'score uses full precision durations');
check($oneSecond['summary']['score'] < 100 && $oneSecond['summary']['score'] !== round($oneSecond['summary']['score'], 4),
    'display rounding does not become weighted input');
$down = Sla::interpret($p, response($p, 2678400));
check($down['summary']['score'] === 0.0 && $down['summary']['unknown'] === 0.0, 'confirmed fully down is valid zero');
$mixed = Sla::interpret($p, response($p, 3270));
near($mixed['summary']['score'], 99.87791218637993, 'SQL-like monthly indicator');
near(($ok['summary']['score'] * 4 + $mixed['summary']['score'] * 2 + $ok['summary']['score']) / 7,
    99.96511776753713, '4-2-1 weighted input preserves native precision');

// Neither API lists nor preservekeys maps imply the selected ID's position.
list($technology, $sla, $service) = fixtures();
$pById = Sla::prepare($technology, report(), [9 => array_replace($sla, ['slaid' => '99']), 21 => $sla],
    [31 => array_replace($service, ['serviceid' => '88']), 47 => $service]);
check($pById['ready'] && $pById['metadata']['serviceid'] === '73', 'get metadata selected by ID fields');
$reordered = response($p, 1);
$goodCell = $reordered['sli'][0][0];
$badCell = $goodCell; $badCell['uptime'] = 0; $badCell['downtime'] = 2678400; $badCell['sli'] = 0;
$previous = ['period_from' => stamp('2026-06-01 00:00:00'), 'period_to' => stamp('2026-07-01 00:00:00')];
$reordered['serviceids'] = [12 => '91', 8 => '73'];
$reordered['periods'] = [7 => $reordered['periods'][0], 3 => $previous];
$reordered['sli'] = [3 => [12 => $badCell, 8 => $badCell], 7 => [12 => $badCell, 8 => $goodCell]];
near(Sla::interpret($p, $reordered)['summary']['score'], $oneSecond['summary']['score'],
    'SLI matrix mapped by returned IDs and period limits, not assumed zero indexes');
$largeService = array_replace($service, ['serviceid' => '9223372036854775806']);
$largeTechnology = array_replace($technology, ['serviceid' => '9223372036854775806']);
$large = Sla::prepare($largeTechnology, report(), [$sla], [$largeService]);
$largeResponse = response($large); $largeResponse['serviceids'][0] = 9223372036854775806;
check(Sla::interpret($large, $largeResponse)['summary']['score'] === 100.0, 'large ID is not a floating-point number');
$leadingIds = Sla::prepare(array_replace($technology, ['slaid' => '00056']), report(), [$sla], [$service]);
check($leadingIds['ready'] && $leadingIds['metadata']['slaid'] === '56', 'numeric IDs canonicalized losslessly');

$invalid = response($p); $invalid['serviceids'] = ['999'];
unavailable(Sla::interpret($p, $invalid), 'different service cannot substitute selected service');
$invalid = response($p); $invalid['serviceids'] = ['73', '73'];
unavailable(Sla::interpret($p, $invalid), 'duplicate service cannot resolve ambiguously');
check(isset(Sla::interpret($p, $invalid)['processing_error']), 'duplicate service ID is invalid protocol');
$invalid = response($p); $invalid['periods'][0]['period_to']--;
unavailable(Sla::interpret($p, $invalid), 'inclusive response end cannot masquerade as exclusive');
$invalid = response($p); $invalid['periods'][0]['period_from']++;
unavailable(Sla::interpret($p, $invalid), 'partial month cannot masquerade as whole month');
$invalid = response($p); $invalid['periods'][] = $invalid['periods'][0];
unavailable(Sla::interpret($p, $invalid), 'duplicate exact period is ambiguous');
check(isset(Sla::interpret($p, $invalid)['processing_error']), 'duplicate period is invalid protocol');
$invalid = response($p); $invalid['sli'] = [1 => [0 => $goodCell]];
unavailable(Sla::interpret($p, $invalid), 'missing exact matrix row is not another row');
check(isset(Sla::interpret($p, $invalid)['processing_error']), 'missing selected cell is invalid protocol');
$invalid = response($p); $invalid['sli'][0] = ['73' => $goodCell];
unavailable(Sla::interpret($p, $invalid), 'SLI cell uses returned positional index, not serviceid key');
$stringTimes = response($p);
$stringTimes['periods'][0] = array_map('strval', $stringTimes['periods'][0]);
$stringTimes['sli'][0][0] = array_replace($stringTimes['sli'][0][0], ['uptime' => '2678400', 'downtime' => '0',
    'sli' => '100.000000', 'error_budget' => '-50']);
check(Sla::interpret($p, $stringTimes)['summary']['score'] === 100.0, 'native API numeric strings are accepted');
check(Sla::interpret($p, $stringTimes)['metadata']['error_budget'] === -50, 'negative native error budget retained');

// Policy rejection cannot manufacture an unknown 24x7 duration without its schedule.
foreach ([['status' => '0'], ['period' => '0'], ['period' => '1'], ['period' => '3'], ['period' => '4'],
        ['timezone' => 'invalid/zone'], ['timezone' => 'system'], ['schedule' => null], ['excluded_downtimes' => null],
        ['effective_date' => report()['from'] + 1]] as $change) {
    $failed = prepared($change);
    check(!$failed['ready'] && $failed['request'] === null, 'unsupported SLA does not produce a query: ' . json_encode($change));
    unavailable(Sla::interpret($failed, false), 'unsupported SLA policy');
    check($failed['summary']['unknown'] === null, 'unresolved calendar does not assume 24x7 unknown hours');
}
$lateService = prepared([], ['created_at' => report()['from'] + 1]);
check(!$lateService['ready'], 'service created one second after month start cannot supply whole month');
unavailable(Sla::interpret($lateService, false), 'late service creation');
check(prepared(['effective_date' => report()['from']], ['created_at' => report()['from']])['ready'],
    'service creation and effective date exactly at inclusive month start are valid');
foreach ([[], false, null, ['invalid']] as $missing) {
    $failed = Sla::prepare($technology, report(), $missing, [$service]);
    unavailable(Sla::interpret($failed, false), 'missing or invalid SLA metadata');
    $failed = Sla::prepare($technology, report(), [$sla], $missing);
    unavailable(Sla::interpret($failed, false), 'missing or invalid service metadata');
}
foreach ([null, false, 0, '0', '-1', '1.5', '1e3', [], '9223372036854775808'] as $badId) {
    $failed = Sla::prepare(array_replace($technology, ['serviceid' => $badId]), report(), [$sla], [$service]);
    unavailable(Sla::interpret($failed, false), 'invalid source ID');
}
$current = report(); $current['to'] -= 3600; $current['generated_at'] = $current['to']; $current['partial'] = true;
$failed = prepared([], [], $current);
check(!$failed['ready'] && strpos(implode(' ', $failed['warnings']), 'encerrado') !== false,
    'current month unsupported reason is explicit');
$future = report(); $future['generated_at'] = $future['from'] - 1;
check(!prepared([], [], $future)['ready'], 'future native month cannot produce a query');
$badReport = report(); $badReport['from']++;
check(!prepared([], [], $badReport)['ready'], 'malformed report boundary rejected');
$badReport = report(); $badReport['month'] = '2026-13';
check(!prepared([], [], $badReport)['ready'], 'invalid month rejected');
$badReport = report(); $badReport['generated_at'] = NAN;
check(!prepared([], [], $badReport)['ready'], 'non-finite report cutoff rejected');

$system = prepared(['timezone' => 'system'], [], null, 'America/Cuiaba');
check($system['ready'] && $system['metadata']['timezone_configured'] === 'system', 'system timezone explicitly resolved');
check($system['metadata']['calendar_key'] === $p['metadata']['calendar_key'], 'resolved system zone preserves compatible calendar');
$oldDefault = date_default_timezone_get();
date_default_timezone_set('Asia/Tokyo');
check(!prepared(['timezone' => 'system'])['ready'], 'PHP default timezone is not silently substituted for Zabbix system');
date_default_timezone_set($oldDefault);
$foreign = prepared(['timezone' => 'UTC']);
check($foreign['ready'] && !$foreign['eligible_for_aggregation'], 'different month boundary preserves native query but blocks weighting');
$foreignResult = Sla::interpret($foreign, response($foreign));
check($foreignResult['summary']['score'] === 100.0 && !$foreignResult['eligible_for_aggregation'],
    'foreign-timezone individual native SLI remains visible');
check(count($foreignResult['warnings']) > 0, 'incompatible calendar has a user-visible reason');
check($foreign['request']['period_from'] === stamp('2026-07-01 00:00:00', 'UTC'), 'native month not relabeled as report timezone');

// Native custom calendars/exclusions define the denominator; off-hours are not unknown.
$office = [];
for ($day = 1; $day <= 5; $day++) {
    $office[] = ['period_from' => $day * 86400 + 9 * 3600, 'period_to' => $day * 86400 + 18 * 3600];
}
$officePrepared = prepared(['schedule' => $office]);
check($officePrepared['metadata']['scheduled_seconds'] === 23 * 9 * 3600, '23 workdays, nine hours each in July');
check($officePrepared['metadata']['basis_seconds'] < $officePrepared['metadata']['period_seconds'], 'scheduled time differs from civil period');
check($officePrepared['metadata']['calendar_key'] !== $p['metadata']['calendar_key'], 'office hours cannot be weighted with 24x7');
$officeResult = Sla::interpret($officePrepared, response($officePrepared));
check($officeResult['summary']['coverage'] === 100.0 && $officeResult['summary']['unknown'] === 0.0,
    'off-hours are not missing evidence');
near($officeResult['summary']['up'], 745200, 'native scheduled seconds are retained');
$exclude = [
    ['name' => 'Maintenance A', 'period_from' => stamp('2026-07-01 12:00:00'), 'period_to' => stamp('2026-07-01 13:00:00')],
    ['name' => 'Maintenance B', 'period_from' => stamp('2026-07-01 12:30:00'), 'period_to' => stamp('2026-07-01 14:00:00')],
    ['name' => 'Weekend', 'period_from' => stamp('2026-07-04 00:00:00'), 'period_to' => stamp('2026-07-05 23:00:00')]
];
$officeExcluded = prepared(['schedule' => $office, 'excluded_downtimes' => $exclude]);
check($officeExcluded['metadata']['excluded_seconds'] === 7200, 'overlapping exclusions union; weekend excludes no scheduled seconds');
check($officeExcluded['metadata']['basis_seconds'] === 745200 - 7200, 'only contracted excluded hours removed');
check(Sla::interpret($officeExcluded, response($officeExcluded))['summary']['score'] === 100.0,
    'custom schedule and exclusions give valid native availability');
$fullWeek = prepared(['schedule' => [['period_from' => 0, 'period_to' => 300000],
    ['period_from' => 290000, 'period_to' => 604800]]]);
check($fullWeek['metadata']['schedule'] === [] && $fullWeek['metadata']['calendar_key'] === $p['metadata']['calendar_key'],
    'full-week union normalizes to native 24x7 convention');
$equivalent = array_reverse($office);
$equivalent[] = ['period_from' => 86400 + 10 * 3600, 'period_to' => 86400 + 12 * 3600];
check(Sla::calendarKey(report()['from'], report()['to'], $equivalent, [], 'America/Cuiaba')
    === $officePrepared['metadata']['calendar_key'], 'ordering and covered schedule overlap do not change compatibility');
$renamed = $exclude; $renamed[0]['name'] = 'Renamed'; $renamed = array_reverse($renamed);
check(Sla::calendarKey(report()['from'], report()['to'], $office, $renamed, 'America/Cuiaba')
    === $officeExcluded['metadata']['calendar_key'], 'exclusion names and order do not change compatibility');
$unionExcluded = [['period_from' => stamp('2026-07-01 12:00:00'), 'period_to' => stamp('2026-07-01 14:00:00')], $exclude[2]];
check(Sla::calendarKey(report()['from'], report()['to'], $office, $unionExcluded, 'America/Cuiaba')
    === $officeExcluded['metadata']['calendar_key'], 'equivalent union of exclusions has same key');
check(Sla::calendarKey(report()['from'], report()['to'], $office, [], 'UTC')
    !== $officePrepared['metadata']['calendar_key'], 'custom calendar identity includes actual timezone');
$outside = [['period_from' => report()['from'] - 86400, 'period_to' => report()['from']]];
check(Sla::calendarKey(report()['from'], report()['to'], [], $outside) === $p['metadata']['calendar_key'],
    'exclusion ending at inclusive month start is outside');
$outside = [['period_from' => report()['to'], 'period_to' => report()['to'] + 86400]];
check(Sla::calendarKey(report()['from'], report()['to'], [], $outside) === $p['metadata']['calendar_key'],
    'exclusion starting at exclusive month end is outside');
$edgeExclusion = [['name' => 'Crossing boundary', 'period_from' => report()['from'] - 3600,
    'period_to' => report()['from'] + 3600]];
$edge = prepared(['excluded_downtimes' => $edgeExclusion]);
check($edge['metadata']['excluded_seconds'] === 3600 && $edge['metadata']['excluded_downtimes'][0]['period_from'] === report()['from'],
    'boundary-crossing exclusion clipped to native month');
$excludedAll = prepared(['excluded_downtimes' => [['period_from' => report()['from'], 'period_to' => report()['to']]]]);
check($excludedAll['metadata']['basis_seconds'] === 0, 'full excluded month has zero denominator');
$empty = Sla::interpret($excludedAll, response($excludedAll));
unavailable($empty, 'zero denominator and native SLI minus one');
check($empty['summary']['lower'] === null && $empty['summary']['upper'] === null && $empty['summary']['coverage'] === 0.0,
    'zero denominator does not create 0 or 100 bounds/coverage');

// Sunday-indexed civil schedule expansion must follow DST rather than fixed 86400s arithmetic.
$sunday = [['period_from' => 0, 'period_to' => 4 * 3600]];
$march = prepared(['timezone' => 'America/New_York', 'schedule' => $sunday], [], report('2026-03', 'America/New_York'));
check($march['ready'] && $march['metadata']['basis_seconds'] === 19 * 3600, 'five Sundays lose one hour at spring DST');
check($march['metadata']['period_seconds'] === 31 * 86400 - 3600, 'March native month has DST-adjusted elapsed duration');
check(Sla::interpret($march, response($march))['summary']['score'] === 100.0, 'DST scheduled SLI validated');
$november = prepared(['timezone' => 'America/New_York', 'schedule' => $sunday], [], report('2026-11', 'America/New_York'));
check($november['ready'] && $november['metadata']['basis_seconds'] === 21 * 3600, 'five Sundays gain one hour at autumn DST');
check($november['metadata']['period_seconds'] === 30 * 86400 + 3600, 'November native month has DST-adjusted elapsed duration');
check(Sla::interpret($november, response($november))['summary']['score'] === 100.0, 'autumn DST native SLI validated');
$dstSlots = [['period_from' => 3600, 'period_to' => 9000], ['period_from' => 10800, 'period_to' => 14400]];
$springOverlap = prepared(['timezone' => 'America/New_York', 'schedule' => $dstSlots], [], report('2026-03', 'America/New_York'));
check($springOverlap['metadata']['basis_seconds'] === 45000 && $springOverlap['metadata']['calendar_overlap_seconds'] === 1800,
    'spring nonexistent 02:30 normalizes to 03:30; native CSla counts both overlapping slots');
check(Sla::interpret($springOverlap, response($springOverlap))['summary']['score'] === 100.0,
    'native DST-overlap denominator is not rejected or silently reinterpreted');
$dstExclude = [['name' => 'DST maintenance', 'period_from' => stamp('2026-03-08 03:00:00', 'America/New_York'),
    'period_to' => stamp('2026-03-08 03:30:00', 'America/New_York')]];
$springOverlapExcluded = prepared(['timezone' => 'America/New_York', 'schedule' => $dstSlots,
    'excluded_downtimes' => $dstExclude], [], report('2026-03', 'America/New_York'));
check($springOverlapExcluded['metadata']['basis_seconds'] === 41400 && $springOverlapExcluded['metadata']['excluded_seconds'] === 3600,
    'native exclusions are deducted per scheduled slot, including overlapping DST slots');
check(Sla::interpret($springOverlapExcluded, response($springOverlapExcluded))['summary']['score'] === 100.0,
    'native DST-overlap exclusions preserve the exact native denominator');

// Independent five-minute oracle for randomly overlapping weekly schedules and exclusions.
// July in Cuiaba has no DST transition, so civil weekday slots can be counted directly.
mt_srand(7242026);
for ($trial = 0; $trial < 20; $trial++) {
    $rows = [];
    for ($i = 0; $i < 6; $i++) {
        $startSlot = mt_rand(0, 2014);
        $endSlot = mt_rand($startSlot + 1, 2016);
        $rows[] = ['period_from' => $startSlot * 300, 'period_to' => $endSlot * 300];
    }
    $exclusions = [];
    for ($i = 0; $i < 3; $i++) {
        $startSlot = mt_rand(0, 8927);
        $endSlot = min(8928, $startSlot + mt_rand(1, 288));
        $exclusions[] = ['period_from' => report()['from'] + $startSlot * 300,
            'period_to' => report()['from'] + $endSlot * 300];
    }
    $expected = 0;
    for ($slot = 0; $slot < 8928; $slot++) {
        $weekly = (3 * 86400 + $slot * 300) % 604800; // July 1 is Wednesday.
        $clock = report()['from'] + $slot * 300;
        $scheduled = false;
        foreach ($rows as $row) {
            if ($weekly >= $row['period_from'] && $weekly < $row['period_to']) { $scheduled = true; break; }
        }
        if (!$scheduled) { continue; }
        $excluded = false;
        foreach ($exclusions as $row) {
            if ($clock >= $row['period_from'] && $clock < $row['period_to']) { $excluded = true; break; }
        }
        if (!$excluded) { $expected += 300; }
    }
    $random = prepared(['schedule' => $rows, 'excluded_downtimes' => $exclusions]);
    check($random['ready'] && $random['metadata']['basis_seconds'] === $expected, 'calendar oracle trial ' . $trial);
    check($random['metadata']['calendar_key'] === Sla::calendarKey(report()['from'], report()['to'],
        array_reverse($rows), array_reverse($exclusions), 'America/Cuiaba'), 'calendar identity oracle trial ' . $trial);
}

// Native numeric validation is strict: false, NaN, negative or malformed data never mean uptime.
foreach ([false, null, [], ['periods' => [], 'serviceids' => [], 'sli' => []],
        ['periods' => 'invalid', 'serviceids' => ['73'], 'sli' => []]] as $invalid) {
    $failed = Sla::interpret($p, $invalid);
    unavailable($failed, 'malformed/unavailable API response');
    check($failed['summary']['unknown'] === 2678400.0 && $failed['summary']['coverage'] === 0.0,
        'failed SLA query has no measured scheduled coverage');
}
foreach ([false, [], ['periods' => 'invalid', 'serviceids' => [], 'sli' => []]] as $malformed) {
    $failed = Sla::interpret($p, $malformed);
    check($failed['metadata']['state'] === 'invalid_response' && is_string($failed['processing_error'] ?? null),
        'invalid protocol explicitly fails processing instead of completing as Unknown');
}
$absent = Sla::interpret($p, ['periods' => [], 'serviceids' => [], 'sli' => []]);
check($absent['metadata']['state'] === 'unavailable' && !isset($absent['processing_error']),
    'well-formed empty native result is unavailable, not a protocol failure');
$noMonth = response($p); $noMonth['periods'] = [$previous];
$absent = Sla::interpret($p, $noMonth);
check($absent['metadata']['state'] === 'unavailable' && !isset($absent['processing_error']),
    'well-formed response without selected period is unavailable');
$sentinel = response($p); $sentinel['sli'][0][0] = array_replace($sentinel['sli'][0][0],
    ['uptime' => 0, 'downtime' => 0, 'sli' => -1]);
$absent = Sla::interpret($p, $sentinel);
check($absent['metadata']['state'] === 'unavailable' && !isset($absent['processing_error'])
    && $absent['summary']['score'] === null, 'valid native no-SLI sentinel is unavailable');
check($empty['metadata']['state'] === 'unavailable' && !isset($empty['processing_error']),
    'zero contracted time with native no-SLI sentinel is unavailable');
foreach (['uptime' => [null, false, NAN, INF, -1, 0.5, '1.0', 'bad', [], '9223372036854775808'],
        'downtime' => [false, -1, '1e3', [], INF],
        'sli' => [null, false, NAN, INF, -2, -1, -0.5, 100.1, 'NaN', 'Infinity', '1e999', '100%', '', ' 100 ', []],
        'error_budget' => [false, INF, 'x', []]] as $field => $values) {
    foreach ($values as $value) {
        $invalid = response($p); $invalid['sli'][0][0][$field] = $value;
        $invalidResult = Sla::interpret($p, $invalid);
        unavailable($invalidResult, 'invalid cell field ' . $field);
        check($invalidResult['metadata']['state'] === 'invalid_response' && isset($invalidResult['processing_error']),
            'malformed or inconsistent cell ' . $field . ' is a processing failure');
    }
}
$invalid = response($p); $invalid['sli'][0][0]['uptime']--;
$invalidResult = Sla::interpret($p, $invalid);
unavailable($invalidResult, 'one missing second cannot be silently extrapolated');
check(isset($invalidResult['processing_error']), 'denominator mismatch is a processing failure, not observed unknown time');
$invalid = response($p); $invalid['sli'][0][0]['uptime']++;
unavailable(Sla::interpret($p, $invalid), 'one excess second cannot expand the month');
$invalid = response($p, 100); $invalid['sli'][0][0]['sli'] = 100;
unavailable(Sla::interpret($p, $invalid), 'uptime and SLI inconsistency rejected');
$invalid = response($p); $invalid['sli'][0][0]['excluded_downtimes'] = null;
unavailable(Sla::interpret($p, $invalid), 'missing response exclusion metadata rejected');
$invalid = response($p); $invalid['sli'][0][0]['excluded_downtimes'] = $edgeExclusion;
unavailable(Sla::interpret($p, $invalid), 'returned exclusion outside its own native period rejected');
foreach ([['schedule' => [['period_from' => 10, 'period_to' => 10]]],
        ['schedule' => [['period_from' => 0, 'period_to' => 604801]]],
        ['schedule' => [false]],
        ['excluded_downtimes' => [['period_from' => report()['from'], 'period_to' => report()['from']]]],
        ['excluded_downtimes' => [['period_from' => -1, 'period_to' => report()['from']]]],
        ['excluded_downtimes' => [['period_from' => report()['from'], 'period_to' => report()['to'], 'name' => []]]],
        ['schedule' => array_fill(0, Sla::MAX_CALENDAR_ROWS + 1, ['period_from' => 1, 'period_to' => 2])]] as $change) {
    $failed = prepared($change);
    unavailable(Sla::interpret($failed, false), 'invalid or excessive native calendar metadata');
}

// Projection only contains relevant metadata; arbitrary source objects are not retained.
$extra = prepared(['description' => 'unrelated description'], ['description' => 'unrelated service field']);
check(strpos(json_encode($extra), 'unrelated') === false, 'unrequested metadata is not persisted');
$source = file_get_contents(__DIR__ . '/../AvailabilitySla.php');
check(strpos($source, 'API::') === false && strpos($source, 'DBselect') === false, 'adapter remains entirely API/DB-free');
restore_error_handler();
echo 'Availability SLA: ' . $assertions . " assertions passed.\n";
