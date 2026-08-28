<?php
// Monthly 25-host fixture, generated a page at a time. No network or Zabbix data.
ini_set('memory_limit', '128M');
foreach (['AvailabilityConfig', 'AvailabilityFreshness', 'AvailabilityEngine', 'AvailabilityCalculation', 'AvailabilityJobStore'] as $class) {
    require dirname(__DIR__) . '/' . $class . '.php';
}
use Modules\Governance\AvailabilityCalculation as Calculation;
use Modules\Governance\AvailabilityJobStore as Store;

class API {
    public static $from;
    public static function __callStatic($name, $args) { return new ScaleEndpoint($name); }
}
class ScaleEndpoint {
    private $name;
    public function __construct($name) { $this->name = $name; }
    public function get(array $options): array {
        if ($this->name === 'HostGroup') { return [['groupid' => '1', 'name' => 'DABD/PostgreSQL']]; }
        if ($this->name === 'Host') {
            $hosts = [];
            for ($id = 1; $id <= 25; $id++) { $hosts[] = ['hostid' => (string) $id, 'name' => sprintf('Fixture %02d', $id), 'status' => '0']; }
            return $hosts;
        }
        if ($this->name === 'Item') {
            $items = [];
            foreach ($options['hostids'] as $hostid) {
                foreach (['icmpping', 'pgsql.ping["{$PG.URI}","{$PG.USER}","{$PG.PASSWORD}"]'] as $i => $key) {
                    $items[] = ['itemid' => (string) (10 * (int) $hostid + $i), 'hostid' => $hostid, 'key_' => $key,
                        'value_type' => '3', 'status' => '0', 'delay' => $i ? '1m' : '30s;1m/6-7,00:00-24:00',
                        'type' => '3', 'preprocessing' => $i ? [['type' => 20, 'params' => '1h']] : []];
                }
            }
            return $items;
        }
        $pg = (int) $options['itemids'][0] % 10 === 1;
        $interval = $pg ? 3600 : 30;
        $first = (int) floor(($options['time_from'] - API::$from) / $interval) - 1;
        $last = (int) ceil(($options['time_till'] - API::$from) / $interval);
        $rows = [];
        for ($index = $first; $index <= $last && count($rows) < $options['limit']; $index++) {
            $clock = API::$from + $index * $interval;
            if ($pg && $index === 241) { $clock += 150; } // A real heartbeat gap, not missing processing.
            if ($clock < $options['time_from'] || $clock > $options['time_till']) { continue; }
            $rows[] = ['clock' => $clock, 'ns' => 0, 'value' => !$pg && $clock === API::$from + 3600 ? '0' : '1'];
        }
        return $rows;
    }
}
API::$from = strtotime('2026-05-01 UTC');
$config = ['timezone' => 'UTC', 'departments' => [['name' => 'DABD', 'target' => 99.9, 'technologies' => [
    ['name' => 'PostgreSQL', 'groups' => 'DABD/PostgreSQL', 'weight' => 1, 'target' => 99.9, 'mode' => 'any_down',
        'checks' => [
            ['key' => 'icmpping', 'max_age' => 3600, 'up' => ['op' => 'eq', 'a' => 1], 'down' => null],
            ['key' => 'pgsql.ping["{$PG.URI}","{$PG.USER}","{$PG.PASSWORD}"]', 'max_age' => 3600,
                'up' => ['op' => 'eq', 'a' => 1], 'down' => null]
        ]]
]]]];
$directory = sys_get_temp_dir() . '/governance-scale-' . bin2hex(random_bytes(12));
$started = microtime(true);
$steps = 0;
try {
    $store = new Store($directory);
    $job = $store->create('1', hash('sha256', 'monthly-scale'), static function() use ($config) {
        return Calculation::create($config, '2026-05', -1, strtotime('2026-06-01 UTC'));
    });
    // The per-request budget yields normally even when accumulated logical runtime exceeds18s.
    $logicalClock = 0.0;
    $runner = new Calculation(static function() use (&$logicalClock) { return ++$logicalClock; });
    while ($job['state']['status'] === 'running' && ++$steps < 3000) {
        $job = $store->step($job['id'], '1', $job['sequence'], static function($state) use ($runner) { return $runner->advance($state); });
    }
    if ($job['state']['status'] !== 'complete') { throw new RuntimeException($job['state']['error'] ?? 'Scale calculation did not finish.'); }
    $report = Calculation::result($store->read($job['id'], '1')['state']);
    $summary = $report['departments'][0]['summary'];
    if ($report['processing']['hosts_done'] !== 25 || $report['processing']['checks_done'] !== 50
            || $report['processing']['working_seconds'] <= 18 || $report['rows'] < 2000000
            || $summary['score'] !== null || $summary['unknown'] != 150 || $summary['down'] != 30) {
        throw new RuntimeException('Incorrect scope, time accounting or gap handling: ' . json_encode($summary));
    }
    foreach ($report['departments'][0]['technologies'][0]['hosts'] as $host) {
        if ($host['sources'][0]['max_age'] !== 3600 || $host['sources'][1]['max_age'] !== 3600
                || $host['sources'][0]['sample_count'] !== 89280 || $host['sources'][1]['sample_count'] !== 744) {
            throw new RuntimeException('Manual policy changed or samples dropped.');
        }
    }
    echo 'PASS: monthly25hosts, ' . $report['rows'] . ' rows, ' . $steps . ' checkpoints, '
        . round(memory_get_peak_usage(true) / 1048576, 1) . ' MiB peak, '
        . round(microtime(true) - $started, 2) . "s; real150s gaps preserved.\n";
}
finally {
    $resolved = realpath($directory);
    $temp = realpath(sys_get_temp_dir());
    if ($resolved && dirname($resolved) === $temp && strpos(basename($resolved), 'governance-scale-') === 0) {
        foreach (scandir($resolved) as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $file = $resolved . DIRECTORY_SEPARATOR . $entry;
            if (is_file($file) || is_link($file)) { unlink($file); }
        }
        rmdir($resolved);
    }
}
