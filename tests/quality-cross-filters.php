<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/quality-fixture.php';
use Modules\Governance\GovernanceConfig as Config;
use Modules\Governance\QualityCalculation as Calculation;
set_error_handler(static function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$checks = 0;
function crossCheck($ok, $message) { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
$fixture = new QualityFixture(5);
foreach ($fixture->rows as $id => &$host) {
    if ($host['status']) continue;
    $host['tags'] = [['tag' => 'Departamento', 'value' => $id == 5 ? 'OTHER' : 'DBD']];
    $host['groups'] = [['groupid' => '10', 'name' => $id == 4 ? 'DBDOutra' : ($id == 3 ? 'DBD/MSSQL' : 'DBD/PostgreSQL')]];
    $host['parentTemplates'] = $id == 1 ? [['templateid' => '100', 'name' => 'Linux', 'host' => 'linux-technical'], ['templateid' => '200', 'name' => 'Postgres']]
        : ($id == 3 ? [['templateid' => '101', 'name' => 'Windows']] : []);
    $host['inventory'] = ['os' => $id == 1 ? 'Linux' : ' ', 'location' => 'Filled'];
}
unset($host);
$base = ['title' => 'Test', 'description' => '', 'tag_names' => '', 'tag_values' => '', 'group_names' => '', 'include_score' => 1,
    'display_mode' => 'non_conformity'];
$department = ['scope_tag_name' => ' departamento ', 'scope_tag_value' => 'dbd'];
$cards = [
    ['id' => 'group', 'type' => 'hostgroups', 'group_names' => 'DBD'] + $department + $base,
    ['id' => 'os_template', 'type' => 'templates', 'template_names' => 'Linux,Windows'] + $department + $base,
    ['id' => 'pg', 'type' => 'templates', 'template_names' => '200', 'scope_group_names' => 'DBD/PostgreSQL'] + $base,
    ['id' => 'sql', 'type' => 'templates', 'template_names' => 'SQL Server', 'scope_group_names' => 'DBD/MSSQL'] + $base,
    ['id' => 'inventory', 'type' => 'inventory', 'inventory_field' => 'os'] + $department + $base,
    ['id' => 'empty', 'type' => 'inventory', 'scope_group_names' => 'Absent'] + $base,
    ['id' => 'and', 'type' => 'templates', 'template_names' => 'linux-technical,200', 'template_mode' => 'all', 'scope_group_names' => 'DBD/PostgreSQL'] + $department + $base,
    ['id' => 'exact', 'type' => 'inventory', 'scope_group_names' => 'DBD', 'scope_include_subgroups' => 0] + $base,
    ['id' => 'exact_rule', 'type' => 'hostgroups', 'group_names' => 'DBD', 'group_include_subgroups' => 0] + $department + $base
];
$config = ['quality_pages' => [['id' => 'main', 'name' => '', 'cards' => $cards]]];
$normalized = Config::getQualityPages($config);
crossCheck(Config::validateQualityPages($normalized) === $normalized, 'Cross filters round trip without loss');
$state = Calculation::create($config, 'main', [], Config::qualityRevision($config));
$engine = new Calculation([$fixture, 'get']);
for ($i = 0; $i < 20 && $state['status'] === 'running'; $i++) $state = $engine->advance($state);
crossCheck($state['status'] === 'complete', 'Calculation completed');
$kpis = array_column($state['result']['kpis'], null, 'id');
foreach (['group' => [3,4,75], 'os_template' => [2,4,50], 'pg' => [1,3,33.3], 'sql' => [0,1,0],
    'inventory' => [1,4,25], 'empty' => [0,0,null], 'and' => [1,2,50], 'exact' => [0,0,null], 'exact_rule' => [0,4,0]] as $id => $expected) {
    $card = $kpis[$id];
    crossCheck([$card['valid_count'], $card['total_count'], $card['score']] == $expected, 'Expected card totals: ' . $id);
    crossCheck($card['display_mode'] === 'non_conformity', 'Display mode retained');
}
crossCheck($state['result']['overall_score'] === 33.3, 'Overall averages conformity and excludes empty scopes');
crossCheck(count($fixture->calls) === 4, 'No API call per card or host');
crossCheck($fixture->calls[1][1]['selectParentTemplates'] === ['templateid', 'host', 'name'], 'Real template fields queried');
crossCheck($kpis['group']['non_compliant'][0]['hostid'] === '4', 'Prefix collision is not subgroup membership');
foreach ([['scope_tag_value' => 'DBD'], ['inventory_field' => 'bogus'], ['template_mode' => 'bogus'], ['display_mode' => 'bogus'],
    ['scope_include_subgroups' => 9], ['template_names' => ',,'], ['scope_group_names' => ',,'], ['scope_tag_name' => []]] as $invalid) {
    try { Config::crossFilters($invalid); crossCheck(false, 'Invalid filter accepted'); }
    catch (InvalidArgumentException $e) { crossCheck(true, 'Invalid filter rejected'); }
}
echo "PASS: $checks cross-filter assertions\n";
