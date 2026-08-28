<?php
// Synthetic fixtures only. Never include real credentials or connect to a Zabbix API.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }
require_once __DIR__ . '/../GovernanceConfig.php';
require_once __DIR__ . '/../QualityCalculation.php';
require_once __DIR__ . '/../QualityJobStore.php';
foreach (['HOST_STATUS_MONITORED' => 0, 'HOST_STATUS_NOT_MONITORED' => 1,
    'HOST_MAINTENANCE_STATUS_ON' => 1, 'INTERFACE_AVAILABLE_FALSE' => 2, 'INTERFACE_AVAILABLE_TRUE' => 1,
    'TRIGGER_SEVERITY_HIGH' => 4, 'TRIGGER_SEVERITY_DISASTER' => 5, 'ITEM_STATE_NOTSUPPORTED' => 1] as $key => $value) {
    if (!defined($key)) { define($key, $value); }
}
function fixtureConfig(): array {
    $cards = [];
    foreach (['tag', 'inventory', 'templates', 'hostgroups', 'interface'] as $type) {
        $cards[] = ['id' => $type, 'type' => $type, 'title' => ucfirst($type), 'description' => 'Qualidade de ' . $type,
            'tag_names' => 'departamento,department', 'tag_values' => 'Banco de Dados', 'group_names' => 'Equipes', 'include_score' => 1];
    }
    return ['quality_pages' => [['id' => 'main', 'name' => 'Infraestrutura', 'cards' => $cards],
        ['id' => 'empty', 'name' => 'Nova página', 'cards' => []]]];
}
final class QualityFixture {
    public $rows = [], $calls = [], $fail = '', $delay = 0;
    public function __construct(int $count = 201) {
        for ($i = 1; $i <= $count; $i++) {
            $yes = $i % 2 === 1;
            $this->rows[(string) $i] = ['hostid' => (string) $i, 'name' => sprintf('Servidor %04d', $i), 'status' => 0,
                'maintenance_status' => $i === 1 ? 1 : 0,
                'interfaces' => [['available' => $yes ? 1 : 2, 'useip' => 1, 'ip' => $yes ? '127.0.0.1' : '', 'dns' => '']],
                'tags' => [['tag' => ' Departamento ', 'value' => $yes ? ' Banco de Dados ' : '']],
                'inventory' => ['location' => $yes ? 'Local' : ''], 'parentTemplates' => $yes ? '1' : '0',
                'groups' => [['groupid' => $yes ? '10' : '11', 'name' => $yes ? 'Equipes/Banco de Dados' : 'EquipesOutra']]];
        }
        $this->rows['999999'] = ['hostid' => '999999', 'name' => 'Desabilitado', 'status' => 1];
    }
    public function get(string $service, array $options) {
        $this->calls[] = [$service, $options];
        if ($this->delay) { usleep($this->delay); }
        if ($this->fail === $service) { throw new RuntimeException('PRIVATE SQL OR CREDENTIAL MUST NOT LEAK'); }
        if ($service === 'Problem') { return '3'; }
        if ($service === 'Item') { return count($options['hostids']); }
        if ($service === 'Host') {
            $rows = array_filter($this->rows, static function($row) use ($options) {
                return (!isset($options['hostids']) || in_array($row['hostid'], $options['hostids'], true))
                    && (!isset($options['filter']['status']) || $row['status'] === $options['filter']['status']);
            });
            if (isset($options['limit'])) { $rows = array_slice($rows, 0, $options['limit'], true); }
            if ($options['output'] === ['hostid', 'status']) {
                return array_map(static function($row) { return array_intersect_key($row, array_flip(['hostid', 'status'])); }, $rows);
            }
            return $rows;
        }
        throw new RuntimeException('Unexpected service');
    }
}
