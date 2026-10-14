<?php
// Two technologies, two hosts, three minute-level checks and a partial month.
// Exercises the safe-memory guard under a constrained PHP worker.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('memory_limit', '72M');
foreach (['AvailabilityConfig', 'AvailabilityFreshness', 'AvailabilityEngine', 'AvailabilityCalculation'] as $name) {
    require dirname(__DIR__) . '/' . $name . '.php';
}

use Modules\Governance\AvailabilityCalculation;
use Modules\Governance\AvailabilityEngine;

// The summary shortcut must preserve weighted outages and genuine unknown time.
$timelines = [
    [[0, 1200, 1.0, 0.0, 0.0], [1200, 1800, 0.0, 0.0, 1.0], [1800, 3600, 0.0, 1.0, 0.0]],
    [[0, 900, 0.0, 1.0, 0.0], [900, 3600, 1.0, 0.0, 0.0]]
];
$weights = [4, 1];
$combined = AvailabilityEngine::combine($timelines, 'mean', 0, 3600, $weights);
foreach ([[0, 3600], [0, 1800], [1800, 3600]] as [$start, $end]) {
    $reference = AvailabilityEngine::summary($combined, $start, $end);
    $totals = AvailabilityEngine::weightedSummaries(array_map(
        static function($series) use ($start, $end) { return AvailabilityEngine::summary($series, $start, $end); },
        $timelines), $weights, $end - $start);
    foreach ($reference as $key => $value) {
        if ($value === null ? $totals[$key] !== null : abs($value - $totals[$key]) > 0.000001) {
            throw new RuntimeException('Weighted total mismatch: ' . $key . ' ' . json_encode([$value, $totals[$key]]));
        }
    }
}

class API {
    public static $from;
    public static function __callStatic($name, $arguments) { return new DepartmentMemoryEndpoint($name); }
}

class DepartmentMemoryEndpoint {
    private $name;
    public function __construct($name) { $this->name = $name; }
    public function get(array $options): array {
        if ($this->name === 'HostGroup') {
            return [['groupid' => '1', 'name' => 'Phone'], ['groupid' => '2', 'name' => 'Provider']];
        }
        if ($this->name === 'Host') {
            $id = (string) $options['groupids'][0];
            return [['hostid' => $id, 'host' => 'host-' . $id, 'name' => 'Host ' . $id, 'status' => '0']];
        }
        if ($this->name === 'Item') {
            $items = [];
            foreach ($options['filter']['key_'] as $key) {
                $items[] = ['itemid' => (string) (count($items) + 1 + (int) $options['hostids'][0] * 10),
                    'hostid' => (string) $options['hostids'][0], 'key_' => $key,
                    'value_type' => '3', 'status' => '0', 'delay' => '60', 'type' => '3', 'preprocessing' => []];
            }
            return $items;
        }
        if ($this->name === 'History') {
            $item = (int) $options['itemids'][0];
            $period = $item === 21 ? 120 : 60;
            $start = (int) (ceil(($options['time_from'] - API::$from) / $period) * $period);
            $rows = [];
            for ($offset = $start; API::$from + $offset <= $options['time_till']
                    && count($rows) < $options['limit']; $offset += $period) {
                $rows[] = ['clock' => (string) (API::$from + $offset), 'ns' => '0',
                    'value' => (string) ($item === 11 ? 1 : ((int) ($offset / $period) % 2))];
            }
            return $rows;
        }
        throw new RuntimeException('Unexpected API endpoint ' . $this->name);
    }
}

API::$from = strtotime('2026-10-01 00:00:00 UTC');
$to = API::$from + 14 * 86400;
$check = static function(string $key): array {
    return ['key' => $key, 'max_age' => 3600, 'up' => ['op' => 'eq', 'a' => 1], 'down' => null];
};
$config = ['timezone' => 'UTC', 'data_policy' => 'observed', 'departments' => [[
    'name' => 'Department', 'target' => 99.9, 'technologies' => [
        ['name' => 'Phone', 'weight' => 1, 'target' => 99.9, 'groups' => 'Phone',
            'mode' => 'any_down', 'checks' => [$check('ping'), $check('web')]],
        ['name' => 'Provider', 'weight' => 1, 'target' => 99.9, 'groups' => 'Provider',
            'mode' => 'any_down', 'checks' => [$check('provider')]]
    ]
]]];
$state = AvailabilityCalculation::create($config, '2026-10', -1, $to);
$runner = new AvailabilityCalculation();
$steps = 0;
while ($state['status'] === 'running' && ++$steps < 5000) {
    $state = $runner->advance($state, 4, 5.0);
}
if ($state['status'] !== 'complete') {
    throw new RuntimeException(($state['error'] ?? 'Incomplete') . ' at ' . $state['phase']
        . ', rows=' . $state['progress']['rows'] . ', peak=' . round(memory_get_peak_usage(true) / 1048576, 1) . ' MiB');
}
$report = AvailabilityCalculation::result($state);
$node = $report['departments'][0];
if ($report['rows'] < 45000 || count($node['technologies']) !== 2 || $node['summary']['unknown'] !== 0.0
        || abs($node['summary']['score'] - 50.0) > 0.000001
        || abs($node['observation']['score'] - 50.0) > 0.000001
        || count($node['daily']) !== 14 || count($node['observation']['daily']) !== 14) {
    throw new RuntimeException('Incorrect aggregate: ' . json_encode($node['summary']));
}
foreach ($node['daily'] as $day) {
    if (abs($day['score'] - 50.0) > 0.000001 || $day['coverage'] !== 100.0) {
        throw new RuntimeException('Incorrect daily aggregate: ' . json_encode($day));
    }
}
echo 'PASS: ' . $report['rows'] . ' history rows, ' . $steps . ' stages, '
    . round(memory_get_peak_usage(true) / 1048576, 1) . " MiB peak.\n";
