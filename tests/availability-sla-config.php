<?php
// Local-only schema tests: no Zabbix API, filesystem writes or credentials are used.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/AvailabilityConfig.php';

use Modules\Governance\AvailabilityConfig as Config;

$assertions = 0;
function slaConfigCheck(bool $ok, string $label): void {
    global $assertions;
    $assertions++;
    if (!$ok) { throw new RuntimeException($label); }
}
function slaConfigReject(array $config, string $label): void {
    try { Config::validate($config); }
    catch (InvalidArgumentException $e) { slaConfigCheck(true, $label); return; }
    slaConfigCheck(false, $label);
}
function slaConfigFixture(array $technology): array {
    return ['timezone' => 'America/Cuiaba', 'departments' => [
        ['name' => 'Test department', 'target' => 99.9, 'technologies' => [$technology]]
    ]];
}
function slaTechnology(): array {
    return ['name' => 'Monthly service', 'weight' => 4, 'target' => 99.9,
        'source' => 'sla', 'slaid' => '7', 'serviceid' => '42'];
}

set_error_handler(static function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING | E_NOTICE);
try {
    $legacy = ['name' => 'Legacy item service', 'weight' => 2, 'target' => 99.5,
        'groups' => 'Team/Database', 'mode' => 'any_down', 'max_age' => 3600,
        'checks' => [['key' => 'pgsql.ping["{$PG.URI}","{$PG.USER}","{$PG.PASSWORD}"]',
            'up' => ['op' => 'eq', 'a' => 1], 'down' => null]]];
    $normalized = Config::validate(slaConfigFixture($legacy));
    $item = $normalized['departments'][0]['technologies'][0];
    slaConfigCheck($item['source'] === 'items', 'legacy technology defaults to item history');
    slaConfigCheck($item['groups'] === $legacy['groups'] && $item['mode'] === 'any_down', 'legacy scope and aggregation unchanged');
    slaConfigCheck($item['max_age'] === 3600 && $item['checks'][0]['max_age'] === 3600, 'legacy validity remains manual per item');
    slaConfigCheck($item['checks'][0]['key'] === $legacy['checks'][0]['key'], 'exact macro key is not expanded or altered');
    slaConfigCheck(Config::validate($normalized) === $normalized, 'normalized legacy schema is idempotent');

    $legacy['checks'][0]['max_age'] = null;
    $legacy['checks'][] = ['key' => 'icmpping', 'max_age' => 180,
        'up' => ['op' => 'range', 'a' => 0.5, 'b' => 1], 'down' => ['op' => 'lt', 'a' => 0.5]];
    $item = Config::validate(slaConfigFixture($legacy))['departments'][0]['technologies'][0];
    slaConfigCheck($item['checks'][0]['max_age'] === null, 'explicit auto still overrides legacy validity');
    slaConfigCheck($item['checks'][1]['max_age'] === 180, 'per-check manual override preserved');
    slaConfigCheck($item['checks'][1]['up'] === ['op' => 'range', 'a' => 0.5, 'b' => 1.0]
        && $item['checks'][1]['down'] === ['op' => 'lt', 'a' => 0.5], 'threshold semantics preserved');

    $sla = slaTechnology();
    $normalized = Config::validate(slaConfigFixture($sla));
    $technology = $normalized['departments'][0]['technologies'][0];
    slaConfigCheck($technology === ['name' => 'Monthly service', 'weight' => 4.0, 'target' => 99.9,
        'source' => 'sla', 'slaid' => '7', 'serviceid' => '42'], 'SLA needs only common fields and its two string IDs');
    slaConfigCheck(Config::validate($normalized) === $normalized, 'SLA schema roundtrip stable');
    $sla += ['groups' => [], 'mode' => 'invalid', 'checks' => 'inactive draft', 'max_age' => -1,
        'sla_url' => 'https://example.invalid/zabbix.php?unused=1'];
    slaConfigCheck(Config::validate(slaConfigFixture($sla)) === $normalized, 'inactive item fields and optional URL are not validated or persisted');
    $legacy['slaid'] = 'not-an-id';
    $legacy['serviceid'] = [];
    $item = Config::validate(slaConfigFixture($legacy))['departments'][0]['technologies'][0];
    slaConfigCheck(!isset($item['slaid'], $item['serviceid']), 'inactive SLA fields do not leak into item configuration');

    foreach (['1', '9007199254740993', '9223372036854775807'] as $id) {
        $sla = array_replace(slaTechnology(), ['slaid' => $id, 'serviceid' => $id]);
        $roundtrip = Config::validate(json_decode(json_encode(slaConfigFixture($sla)), true));
        $technology = $roundtrip['departments'][0]['technologies'][0];
        slaConfigCheck($technology['slaid'] === $id && $technology['serviceid'] === $id,
            'canonical DB IDs survive JSON exactly as strings: ' . $id);
    }
    $invalidIds = [null, 0, 1, 1.0, true, [], '', '0', '01', '-1', '+1', '1.0', '1e3',
        ' 1', '1 ', "1\n", "1\r\n", '１２', '9223372036854775808', '18446744073709551615', str_repeat('9', 40)];
    foreach (['slaid', 'serviceid'] as $field) {
        foreach ($invalidIds as $invalid) {
            $sla = array_replace(slaTechnology(), [$field => $invalid]);
            slaConfigReject(slaConfigFixture($sla), $field . ' rejects noncanonical/out-of-range value: ' . json_encode($invalid));
        }
        $sla = slaTechnology(); unset($sla[$field]);
        slaConfigReject(slaConfigFixture($sla), $field . ' is required for SLA');
    }
    foreach (['', 'auto', 'SLA', 'native', null, 0, []] as $source) {
        slaConfigReject(slaConfigFixture(array_replace(slaTechnology(), ['source' => $source])), 'invalid source rejected');
    }
    foreach (['name' => '', 'weight' => 0, 'target' => 101] as $field => $value) {
        slaConfigReject(slaConfigFixture(array_replace(slaTechnology(), [$field => $value])), 'SLA retains common validation: ' . $field);
    }
    $empty = slaConfigFixture(slaTechnology()); $empty['departments'][0]['technologies'] = [];
    slaConfigReject($empty, 'departments still require a technology');
    $items = $legacy; $items['source'] = 'items'; $items['checks'] = [];
    slaConfigReject(slaConfigFixture($items), 'item source still requires checks');
    $items = $legacy; $items['groups'] = ',,';
    slaConfigReject(slaConfigFixture($items), 'item source still requires usable groups');

    $mixed = slaConfigFixture($legacy);
    $mixed['departments'][0]['technologies'][] = slaTechnology();
    $mixed = Config::validate($mixed);
    slaConfigCheck(array_column($mixed['departments'][0]['technologies'], 'source') === ['items', 'sla'], 'both sources can coexist in one department');
    slaConfigCheck(array_column($mixed['departments'][0]['technologies'], 'weight') === [2.0, 4.0], 'weights remain in the module for both sources');
    $many = slaConfigFixture(slaTechnology());
    $many['departments'][0]['technologies'] = array_fill(0, 30, slaTechnology());
    slaConfigCheck(count(Config::validate($many)['departments'][0]['technologies']) === 30, '30 SLA technologies accepted within existing limit');
    $many['departments'][] = ['name' => 'Another', 'target' => 99.9, 'technologies' => [slaTechnology()]];
    slaConfigReject($many, 'SLA respects existing total technology limit');
}
finally { restore_error_handler(); }
echo 'PASS: ' . $assertions . " availability SLA configuration assertions.\n";
