<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/quality-fixture.php';
use Modules\Governance\GovernanceConfig as Config;
use Modules\Governance\QualityCalculation as Calculation;
$config = fixtureConfig(); $base = $config['quality_pages'][0]['cards'];
$config['quality_pages'][0]['cards'] = [];
for ($i = 0; $i < 30; $i++) {
    $card = $base[$i % 5]; $card['id'] .= '-' . $i; $config['quality_pages'][0]['cards'][] = $card;
}
if (in_array('--custom', $argv, true)) {
    foreach ($config['quality_pages'][0]['cards'] as &$card) {
        $card['selection'] = ['version'=>1,'mode'=>'custom','formula'=>'(A or not A) and B','conditions'=>[
            ['type'=>'tag','operator'=>'exists','name'=>'Departamento'],
            ['type'=>'group','operator'=>'not_equals','value'=>'Missing group']
        ]];
    }
    unset($card);
}
$total = 12001; $calls = 0;
$engine = new Calculation(static function($service, $options) use ($total, &$calls) {
    $calls++;
    if ($service === 'Problem') {
        if (count($options['hostids']) !== $total) throw new RuntimeException('Problem scope truncated');
        return 1;
    }
    if ($service === 'Item') return count($options['hostids']);
    if ($options['output'] === ['hostid', 'status']) {
        $rows = [];
        for ($id = 1; $id <= $total; $id++) $rows[] = ['hostid' => (string) $id, 'status' => 0];
        return $rows;
    }
    if (count($options['hostids']) > Calculation::BATCH_SIZE) throw new RuntimeException('Unbounded batch');
    $rows = [];
    foreach ($options['hostids'] as $id) {
        $yes = (int) $id % 2 === 1;
        $rows[] = ['hostid' => $id, 'status' => 0, 'name' => 'Host ' . $id, 'maintenance_status' => 0,
            'tags' => [['tag' => 'Departamento', 'value' => $yes ? 'Banco de Dados' : '']],
            'inventory' => ['os' => $yes ? 'Linux' : ''], 'parentTemplates' => $yes ? '1' : '0',
            'interfaces' => [['available' => 1, 'useip' => 1, 'ip' => $yes ? '127.0.0.1' : '']],
            'groups' => [['groupid' => '10', 'name' => $yes ? 'Equipes/Banco de Dados' : 'Other']]];
    }
    return $rows;
});
$begin = microtime(true);
$state = Calculation::create($config, 'main', [], Config::qualityRevision($config)); $steps = 0;
while ($state['status'] === 'running' && $steps < 1000) { $state = $engine->advance($state); $steps++; }
if ($state['status'] !== 'complete' || $state['result']['metrics']['unsupported_items']['value'] !== $total) throw new RuntimeException('Incomplete result');
foreach ($state['result']['kpis'] as $kpi) {
    if ($kpi['valid_count'] !== 6001 || $kpi['total_count'] !== $total || count($kpi['non_compliant']) !== 10) throw new RuntimeException('Card mismatch');
}
$peak = memory_get_peak_usage(true);
if ($peak > 64 * 1048576) throw new RuntimeException('Excessive memory');
printf("PASS: quality scale, %d hosts, 30 cards, %d calls, %d MiB peak, %.2fs\n", $total, $calls, $peak / 1048576, microtime(true) - $begin);
