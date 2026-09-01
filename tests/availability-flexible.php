<?php
// Pure fixtures for the numeric subset of Zabbix 6.0 flexible update intervals.
// No Zabbix API calls, production access, item changes, or cadence inferred from history.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../AvailabilityFreshness.php';
require __DIR__ . '/../AvailabilityEngine.php';
use Modules\Governance\AvailabilityFreshness as Freshness;
use Modules\Governance\AvailabilityEngine as Engine;

$assertions = 0;
set_error_handler(static function($severity, $message, $file, $line) {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
function verify($ok, $message) {
    global $assertions;
    $assertions++;
    if (!$ok) { throw new RuntimeException($message); }
}
function policy($delay, $heartbeat = null, $manual = null) {
    return Freshness::resolve(['type' => '3', 'key_' => 'icmpping', 'delay' => $delay,
        'preprocessing' => $heartbeat === null ? [] : [['type' => '20', 'params' => $heartbeat]]], $manual);
}

// Exact failing production syntax, with a synthetic item and no production identifiers.
$delay = '30s;1m/6-7,00:00-24:00';
$value = policy($delay);
verify($value['interval_seconds'] === 60, 'weekend interval supplies conservative 60-second polling bound');
verify($value['max_age'] === 3600, 'frequent flexible item keeps the one-hour evidence floor');
verify($value['automatic_minimum_seconds'] === 3600, 'automatic floor remains auditable');
verify($value['freshness_mode'] === 'auto' && $value['freshness_source'] === 'flexible_interval',
    'numeric flexible policy is auditable and automatic');
verify($value['heartbeat_seconds'] === null && $value['warnings'] === [], 'ordinary flexible item needs no heartbeat or warning');
$withHeartbeat = policy($delay, '1h');
verify($withHeartbeat['max_age'] === 3720, 'heartbeat plus two 60-second polls is 3720 seconds');
verify($withHeartbeat['interval_seconds'] === 60 && $withHeartbeat['heartbeat_seconds'] === 3600,
    'heartbeat and conservative polling metadata retained separately');
verify($withHeartbeat['freshness_source'] === 'heartbeat_flexible_interval', 'combined source is auditable');
verify(policy($delay, '30s')['max_age'] === 3600, 'short heartbeat cannot reduce the one-hour evidence floor');

foreach ([
    ['60;30/1-5,09:00-18:00', 60],
    ['30;60/6-7,0:00-24:00', 60],
    ['1m;30s/1-7,00:00-24:00', 60],
    ['30s;2m/1-5,9:00-18:00;5m/7,00:00-24:00', 300],
    ['30s;5m/1-7,00:00-24:00;1m/1-7,00:00-24:00', 300],
    ['10;1m/1-1,0:00-1:00', 60],
    ['10;2m/7,23:59-24:00', 120],
    ['10;1/1,0:00-0:01', 10],
    ['1h;60s/1-5,9:00-18:00', 3600]
] as $case) {
    $result = policy($case[0]);
    verify($result['interval_seconds'] === $case[1]
        && $result['max_age'] === max(Freshness::MIN_AUTOMATIC_AGE, 3 * $case[1]),
        'all positive base/flexible intervals bound cadence: ' . $case[0]);
    verify($result['freshness_source'] === 'flexible_interval', 'flexible source retained for valid case');
}

// Legacy simple intervals and manual validity retain their established behavior.
verify(policy('30s')['max_age'] === 3600 && policy('30s')['freshness_source'] === 'interval',
    'simple polling receives the one-hour evidence floor');
verify(policy('1m', '1h')['max_age'] === 3720 && policy('1m', '1h')['freshness_source'] === 'heartbeat',
    'simple heartbeat policy unchanged');
$manual = policy($delay, null, 600);
verify($manual['max_age'] === 600 && $manual['freshness_mode'] === 'manual' && $manual['freshness_source'] === 'manual',
    'explicit manual age is not replaced by inferred policy');
verify($manual['interval_seconds'] === 60 && count($manual['warnings']) === 1,
    'manual audit retains polling metadata and warns below the automatic hourly window');
$shortManual = policy($delay, null, 60);
verify($shortManual['max_age'] === 60 && count($shortManual['warnings']) === 1, 'short manual validity warns without changing it');
$shortHeartbeat = policy($delay, '1h', 180);
verify($shortHeartbeat['max_age'] === 180 && count($shortHeartbeat['warnings']) === 1,
    'manual heartbeat warning uses the conservative flexible estimate');
verify(policy($delay, '1h', 4000)['warnings'] === [], 'sufficient manual heartbeat validity does not warn');
foreach ([0, -1, 86401] as $age) {
    $invalid = policy($delay, null, $age);
    verify($invalid['max_age'] === null && $invalid['freshness_mode'] === 'manual', 'manual validity guard remains enforced');
}

// Every segment must be understood; a valid base or earlier segment cannot mask a bad tail.
$invalidDelays = [
    null, false, [], '', '0', '0;1m/1-7,00:00-24:00', '0s;1m/6-7,00:00-24:00',
    '30s;0/6-7,00:00-24:00', '30s;0s/6-7,00:00-24:00',
    '30s;1m/1-7,00:00-24:00;0/6-7,00:00-24:00',
    '{$UPDATE.INTERVAL}', '{$UPDATE.INTERVAL};1m/6-7,00:00-24:00',
    '30s;{$FLEX_INTERVAL}/6-7,00:00-24:00', '30s;1m/{$FLEX_PERIOD}', '30s;{$I}/{$P}',
    '30s;{#INTERVAL}/6-7,00:00-24:00', '30s;1m/{#PERIOD}',
    '30s;wd1-5h9', '30s;m/5', '30s;1m/6-7,00:00-24:00;wd1-5h9',
    '30s;', '30s;;1m/6-7,00:00-24:00', '30s;1m/6-7,00:00-24:00;',
    '30s;1m', '30s;1m/', '30s;/6-7,00:00-24:00',
    '30s;1m/6-7,00:00-24:00/extra', '30s;1m/6-7,00:00-24:00junk',
    '30s;1m/0-7,00:00-24:00', '30s;1m/1-8,00:00-24:00', '30s;1m/7-1,00:00-24:00',
    '30s;1m/01-07,00:00-24:00', '30s;1m/6,7,00:00-24:00',
    '30s;1m/1-7,00:00-24:01', '30s;1m/1-7,00:00-25:00',
    '30s;1m/1-7,09:60-18:00', '30s;1m/1-7,09:00-18:60',
    '30s;1m/1-7,09:00-09:00', '30s;1m/1-7,18:00-09:00',
    '30s;1m/1-7,24:00-24:00', '30s;1m/1-7,9:0-18:00',
    '30s;1m/1-7,009:00-18:00', '30s;1m/1-7,09:00:00-18:00:00',
    '30s;1m/ 1-7,09:00-18:00', '30s; 1m/1-7,09:00-18:00',
    '30s;1m /1-7,09:00-18:00', '30s;1m/1-7,09:00 -18:00',
    '30s;1m/6-7,00:00-24:00' . "\nextra",
    '30s;1M/6-7,00:00-24:00', '30s;1y/6-7,00:00-24:00', '30s;1.5m/6-7,00:00-24:00',
    '30s;1e2/6-7,00:00-24:00', '30s;-1m/6-7,00:00-24:00', '30s;+1m/6-7,00:00-24:00',
    '030s;1m/6-7,00:00-24:00', '30s;01m/6-7,00:00-24:00',
    '30s;99999999999999999999999999999w/1-7,00:00-24:00',
    str_repeat('1', Freshness::MAX_DELAY_LENGTH + 1),
    '30' . str_repeat(';1/1,0:00-0:01', Freshness::MAX_FLEXIBLE_INTERVALS + 1)
];
foreach ($invalidDelays as $invalidDelay) {
    $invalid = policy($invalidDelay);
    verify($invalid['max_age'] === null && $invalid['freshness_source'] === 'unresolved',
        'unsupported/malformed cadence cannot invent freshness');
    verify($invalid['interval_seconds'] === null && count($invalid['warnings']) > 0,
        'invalid segment cannot leave an apparently verified partial polling bound');
    verify(policy($invalidDelay, null, 600)['max_age'] === 600,
        'unsupported custom cadence can still use explicit manual policy');
}
$atSegmentLimit = policy('30' . str_repeat(';1/1,0:00-0:01', Freshness::MAX_FLEXIBLE_INTERVALS));
verify($atSegmentLimit['max_age'] === 3600, 'bounded parser accepts exactly its segment limit and applies the floor');

// The existing maximum prevents a long positive interval from inflating validity indefinitely.
verify(policy('30s;28800s/1-7,00:00-24:00')['max_age'] === 86400, 'maximum automatic age is inclusive');
$tooLong = policy('30s;28801s/1-7,00:00-24:00');
verify($tooLong['max_age'] === null && $tooLong['interval_seconds'] === 28801,
    'over-limit cadence is audited but not used as an automatic age');
verify(policy('28801;1m/1-7,00:00-24:00')['max_age'] === null, 'unreachable long base is not silently excluded from conservative policy');
verify(policy($delay, '86280s')['max_age'] === 86400, 'heartbeat plus polling at maximum is allowed');
verify(policy($delay, '86281s')['max_age'] === null, 'heartbeat maximum guard remains enforced');
verify(policy($delay, '{$HEARTBEAT}')['max_age'] === null, 'flexible polling cannot resolve a heartbeat macro');
verify(policy($delay, '0')['max_age'] === null, 'zero heartbeat remains unsupported');

$item = ['type' => '3', 'key_' => 'icmpping', 'delay' => $delay, 'preprocessing' => []];
foreach (['2', '17', '18', '999'] as $type) {
    verify(Freshness::resolve(array_replace($item, ['type' => $type]), null)['max_age'] === null,
        'flexible syntax does not bypass independent polling type guard');
}
verify(Freshness::resolve(array_replace($item, ['type' => '7', 'key_' => 'mqtt.get[broker,topic]']), null)['max_age'] === null,
    'MQTT push item does not gain a polling cadence');
foreach ([null, [['type' => 19, 'params' => '']], [['type' => 20, 'params' => '1h'], ['type' => 20, 'params' => '1h']]] as $preprocessing) {
    verify(Freshness::resolve(array_replace($item, ['preprocessing' => $preprocessing]), null)['max_age'] === null,
        'invalid or unbounded preprocessing remains unresolved');
}
$noPreprocessing = $item; unset($noPreprocessing['preprocessing']);
verify(Freshness::resolve($noPreprocessing, null)['max_age'] === null, 'missing preprocessing metadata remains unresolved');

// Parsing does not mutate metadata or use the current value/history to inflate validity.
$item['key_'] = 'pgsql.ping["{$PG.URI}","{$PG.USER}","{$PG.PASSWORD}"]';
$item['lastvalue'] = '1';
$item['history'] = '365d';
$original = serialize($item);
$unchanged = Freshness::resolve($item, null);
verify(serialize($item) === $original, 'source item metadata is unchanged');
verify($unchanged['max_age'] === 3600, 'key macros and history retention do not affect the hourly polling policy');
verify(strpos(json_encode($unchanged), '{$PG.') === false, 'no secret macro metadata is copied into audit policy');
$rule = ['up' => ['op' => 'eq', 'a' => 1], 'down' => null];
$samples = [['clock' => 0, 'value' => 1], ['clock' => 60, 'value' => 1], ['clock' => 120, 'value' => 1]];
$summary = Engine::summary(Engine::samples($samples, $rule, $value['max_age'], 0, 300), 0, 300);
verify($summary['up'] === 300.0 && $summary['score'] === 100.0, 'resolved flexible policy allows observed samples to cover their validity');
$summary = Engine::summary(Engine::samples([['clock' => 0, 'value' => 1]], $rule, $value['max_age'], 0, 2678400), 0, 2678400);
verify($summary['up'] === 3600.0 && $summary['unknown'] === 2674800.0,
    'one sample covers one hour but is not extrapolated across July');

// Conservative cadence is deterministic and independent of flexible interval order.
mt_srand(60828);
for ($trial = 0; $trial < 50; $trial++) {
    $base = mt_rand(1, 3600);
    $intervals = []; $maximum = $base;
    for ($i = 0; $i < 5; $i++) {
        $seconds = mt_rand(1, 3600); $maximum = max($maximum, $seconds);
        $fromDay = mt_rand(1, 7); $toDay = mt_rand($fromDay, 7);
        $fromMinute = mt_rand(0, 1439); $toMinute = mt_rand($fromMinute + 1, 1440);
        $intervals[] = $seconds . '/' . $fromDay . '-' . $toDay . ','
            . sprintf('%02d:%02d-%02d:%02d', intdiv($fromMinute, 60), $fromMinute % 60, intdiv($toMinute, 60), $toMinute % 60);
    }
    $result = policy($base . ';' . implode(';', $intervals));
    verify($result['interval_seconds'] === $maximum
        && $result['max_age'] === max(Freshness::MIN_AUTOMATIC_AGE, 3 * $maximum),
        'random positive intervals retain their maximum bound');
    verify($result === policy($base . ';' . implode(';', array_reverse($intervals))),
        'interval ordering does not change the conservative policy');
}

restore_error_handler();
echo 'Availability flexible intervals: ' . $assertions . " assertions passed.\n";
