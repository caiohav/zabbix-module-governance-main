<?php
// Synthetic API only. This fixture never connects to Zabbix.
require_once __DIR__ . '/../AvailabilityConfig.php';
require_once __DIR__ . '/../AvailabilityEngine.php';
require_once __DIR__ . '/../AvailabilityFreshness.php';
require_once __DIR__ . '/../AvailabilitySla.php';
require_once __DIR__ . '/../AvailabilityCalculation.php';
require_once __DIR__ . '/../AvailabilityJobStore.php';

class CTimezoneHelper {
    public static $timezone = 'UTC';
    public static function getSystemTimezone(): string { return self::$timezone; }
}
class API {
    public static $config, $slas, $services, $calls, $down, $basis, $fail, $changeDefinition, $definitionReads;
    public static $missingCell, $missingService, $badDuration, $missingHistory;
    public static function __callStatic($name, $arguments) { return new SlaIntegrationEndpoint($name); }
    public static function reset(): void {
        self::$config = self::configuration();
        self::$slas = ['1' => ['slaid' => '1', 'name' => 'SLA mensal de teste', 'period' => '2', 'status' => '1',
            'slo' => '99', 'timezone' => 'system', 'effective_date' => (string) strtotime('2025-01-01 UTC'),
            'schedule' => [], 'excluded_downtimes' => [], 'service_tags' => [['tag' => 'scope', 'operator' => '0', 'value' => 'test']]]];
        self::$services = [];
        foreach (['11' => 'PostgreSQL', '12' => 'SQL Server', '13' => 'Qlik'] as $id => $name) {
            self::$services[$id] = ['serviceid' => (string) $id, 'name' => $name,
                'created_at' => (string) strtotime('2025-01-01 UTC')];
        }
        self::$calls = []; self::$down = ['11' => 0, '12' => 3270, '13' => 0];
        self::$basis = []; self::$fail = null; self::$changeDefinition = false; self::$definitionReads = [];
        self::$missingCell = self::$missingService = self::$badDuration = self::$missingHistory = false;
        CTimezoneHelper::$timezone = 'UTC';
    }
    public static function configuration(): array {
        $technologies = [];
        foreach ([['PostgreSQL', '11', 4], ['SQL Server', '12', 2], ['Qlik', '13', 1]] as $entry) {
            $technologies[] = ['name' => $entry[0], 'source' => 'sla', 'slaid' => '1', 'serviceid' => $entry[1],
                'weight' => $entry[2], 'target' => 99.9];
        }
        return ['timezone' => 'UTC', 'departments' => [['name' => 'Banco de Dados', 'target' => 99.9,
            'technologies' => $technologies]]];
    }
    public static function itemTechnology(): array {
        return ['name' => 'Item ping', 'source' => 'items', 'weight' => 1, 'target' => 99.9,
            'groups' => 'Testes', 'mode' => 'any_down',
            'checks' => [['key' => 'icmpping', 'max_age' => 3600, 'up' => ['op' => 'eq', 'a' => 1], 'down' => null]]];
    }
}
class SlaIntegrationEndpoint {
    private $endpoint;
    public function __construct(string $endpoint) { $this->endpoint = $endpoint; }
    private function called(string $method, array $options): void {
        API::$calls[] = [$this->endpoint, $method, $options];
        if (API::$fail === $this->endpoint . '.' . $method) { throw new RuntimeException('Private SQL / credentials must not escape.'); }
    }
    public function get(array $options): array {
        $this->called('get', $options);
        if ($this->endpoint === 'Module') { return [['config' => ['availability' => API::$config]]]; }
        if ($this->endpoint === 'Sla') {
            $id = (string) $options['slaids'][0];
            API::$definitionReads[$id] = (API::$definitionReads[$id] ?? 0) + 1;
            if (!isset(API::$slas[$id])) { return []; }
            $sla = API::$slas[$id];
            if (API::$changeDefinition && API::$definitionReads[$id] > 1) { $sla['slo'] = '98'; }
            return [$sla];
        }
        if ($this->endpoint === 'Service') {
            $id = (string) $options['serviceids'][0];
            return API::$missingService || !isset(API::$services[$id]) ? [] : [API::$services[$id]];
        }
        if ($this->endpoint === 'HostGroup') { return [['groupid' => '1', 'name' => 'Testes']]; }
        if ($this->endpoint === 'Host') { return [['hostid' => '21', 'name' => 'Host de teste', 'status' => '0']]; }
        if ($this->endpoint === 'Item') {
            return [['itemid' => '31', 'hostid' => '21', 'key_' => 'icmpping', 'value_type' => '3',
                'status' => '0', 'delay' => '1h', 'type' => '3', 'preprocessing' => []]];
        }
        if ($this->endpoint === 'History') {
            if (API::$missingHistory) { return []; }
            $rows = [];
            $clock = (int) (ceil($options['time_from'] / 3600) * 3600);
            while ($clock <= $options['time_till'] && count($rows) < $options['limit']) {
                $rows[] = ['clock' => (string) $clock, 'ns' => '0', 'value' => '1'];
                $clock += 3600;
            }
            return $rows;
        }
        throw new RuntimeException('Unexpected test endpoint: ' . $this->endpoint);
    }
    public function getSli(array $options): array {
        $this->called('getSli', $options);
        $id = (string) $options['serviceids'][0];
        $from = $options['period_from']; $to = $options['period_to'] + 1;
        $basis = API::$basis[$options['slaid']] ?? ($to - $from);
        $down = API::$down[$id] ?? 0;
        $cell = ['uptime' => $basis - $down + (API::$badDuration ? 1 : 0), 'downtime' => $down,
            'sli' => $basis ? 100 * (1 - $down / $basis) : -1, 'error_budget' => 0,
            'excluded_downtimes' => API::$slas[$options['slaid']]['excluded_downtimes']];
        // Deliberately put an unrelated service first. The selected ID is not offset zero.
        return ['periods' => [['period_from' => $from, 'period_to' => $to]], 'serviceids' => ['999', $id],
            'sli' => [API::$missingCell ? [] : [array_replace($cell, ['uptime' => 0, 'downtime' => $basis, 'sli' => 0]), $cell]]];
    }
}

function slaFixtureCalculation(?array $config = null, string $month = '2026-07'): array {
    $runner = new \Modules\Governance\AvailabilityCalculation();
    $state = $runner::create($config ?? API::$config, $month, -1, strtotime('2026-08-28 12:00:00 UTC'));
    for ($count = 0; $count < 1000 && $state['status'] === 'running'; $count++) {
        $state = $runner->advance(json_decode(json_encode($state), true), 1);
    }
    return $state;
}
