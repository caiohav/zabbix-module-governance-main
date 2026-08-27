<?php
// Regression for the previous HTTP 500: alternating minute samples exhausted 128 MiB before the row cap.
// Run: php -d memory_limit=128M tests/availability-memory.php [technology|host|department]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../AvailabilityConfig.php';
require __DIR__ . '/../AvailabilityEngine.php';
require __DIR__ . '/../AvailabilityFreshness.php';
require __DIR__ . '/../AvailabilityReport.php';
use Modules\Governance\AvailabilityConfig as Config;
use Modules\Governance\AvailabilityReport as Report;
ini_set('memory_limit', '128M');

$scenario = $argv[1] ?? 'technology';
if (!in_array($scenario, ['technology', 'host', 'department'], true)) { throw new RuntimeException('Unknown scenario'); }
$from = strtotime('2026-05-01 UTC');
$to = strtotime('2026-06-01 UTC');
$changing = false;
$hostCount = $scenario === 'technology' ? 12 : 1;
class API {
    public static function __callStatic($name, $arguments) { return new MemoryEndpoint($name); }
}
class MemoryEndpoint {
    private $name;
    public function __construct($name) { $this->name = $name; }
    public function get(array $options): array {
        global $hostCount, $from, $changing;
        if ($this->name === 'HostGroup') { return [['groupid' => '1', 'name' => 'Database']]; }
        if ($this->name === 'Host') {
            $hosts = [];
            for ($i = 1; $i <= $hostCount; $i++) { $hosts[] = ['hostid' => (string) $i, 'name' => 'Host ' . $i, 'status' => '0']; }
            return $hosts;
        }
        if ($this->name === 'Item') {
            $items = [];
            foreach ($options['hostids'] as $hostid) {
                foreach ($options['filter']['key_'] as $key) {
                    $items[] = ['hostid' => $hostid, 'itemid' => $hostid . '-' . $key, 'key_' => $key,
                        'value_type' => '3', 'status' => '0', 'type' => '3', 'delay' => '60', 'preprocessing' => []];
                }
            }
            return $items;
        }
        if ($this->name === 'History') {
            $samples = [];
            $start = (int) (ceil($options['time_from'] / 60) * 60);
            for ($clock = $start; $clock <= $options['time_till'] && count($samples) < $options['limit']; $clock += 60) {
                $samples[] = ['clock' => (string) $clock, 'ns' => '0',
                    'value' => $changing && ((int) (($clock - $from) / 60)) % 2 === 0 ? '0' : '1'];
            }
            return $samples;
        }
        throw new RuntimeException('Unexpected API endpoint');
    }
}
$check = ['key' => 'icmpping', 'max_age' => null, 'up' => ['op' => 'eq', 'a' => 1], 'down' => null];
$technology = ['name' => 'PostgreSQL', 'weight' => 1, 'target' => 99.9, 'groups' => 'Database',
    'mode' => 'any_down', 'checks' => [$check]];
if ($scenario === 'host') { $technology['checks'][] = array_replace($check, ['key' => 'pgsql.ping']); }
$config = ['timezone' => 'UTC', 'departments' => [['name' => 'Database', 'target' => 99.9, 'technologies' => [$technology]]]];
if ($scenario === 'department') {
    $technology['name'] = 'Second technology';
    $technology['checks'][0]['key'] = 'pgsql.ping';
    $config['departments'][0]['technologies'][] = $technology;
}
$config = Config::validate($config);
$report = (new Report())->build($config, '2026-05', $to);
if ($report['departments'][0]['summary']['score'] !== 100.0) { throw new RuntimeException('Stable minute history must still calculate completely.'); }
unset($report);
$changing = true;
$report = (new Report())->build($config, '2026-05', $to);
$department = $report['departments'][0];
if ($department['summary']['score'] !== null) { throw new RuntimeException('Over-budget complex history must not report a final percentage.'); }
$warnings = $department['warnings'];
foreach ($department['technologies'] as $technology) { $warnings = array_merge($warnings, $technology['warnings']); }
if (!preg_grep('/memory|complexity/i', $warnings)) { throw new RuntimeException('Budget failure must explain the memory/complexity limit.'); }
if ($scenario === 'department' && !$department['warnings']) { throw new RuntimeException('Department-level combine must be guarded.'); }
echo 'PASS: ' . $scenario . ' budget guard; peak ' . round(memory_get_peak_usage(true) / 1048576, 1) . ' MiB; no final score.' . PHP_EOL;
