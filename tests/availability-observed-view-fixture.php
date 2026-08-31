<?php
// Reusable synthetic reports and rendering stubs for CLI tests and loopback visual QA.
// Does not run assertions, query Zabbix, save configuration or create checkpoint files.
require_once __DIR__ . '/../AvailabilityConfig.php';
require_once __DIR__ . '/../AvailabilityEngine.php';
require_once __DIR__ . '/../AvailabilityFreshness.php';
require_once __DIR__ . '/../AvailabilitySla.php';
require_once __DIR__ . '/../AvailabilityCalculation.php';

use Modules\Governance\AvailabilityCalculation as ObservedViewCalculation;

class CTimezoneHelper {
    public static function getSystemTimezone(): string { return 'UTC'; }
}
class API {
    public static $config = [], $groups = [], $hosts = [], $items = [], $history = [], $calls = [], $sla = [], $service = [];
    public static function __callStatic($name, $arguments) { return new ObservedViewEndpoint($name); }
}
class ObservedViewEndpoint {
    private $name;
    public function __construct(string $name) { $this->name = $name; }
    public function get(array $options): array {
        API::$calls[] = [$this->name, 'get', $options];
        switch ($this->name) {
            case 'Module': return [['config' => ['availability' => API::$config]]];
            case 'HostGroup': return API::$groups;
            case 'Host':
                $hosts = array_filter(API::$hosts, static function($host) use ($options) {
                    return in_array($host['groupid'], array_map('strval', $options['groupids']), true);
                });
                $hosts = array_values($hosts);
                usort($hosts, static function($a, $b) { return strcmp($a['name'], $b['name']); });
                return array_slice($hosts, 0, $options['limit']);
            case 'Item':
                return array_values(array_filter(API::$items, static function($item) use ($options) {
                    return in_array($item['hostid'], array_map('strval', $options['hostids']), true)
                        && in_array($item['key_'], $options['filter']['key_'], true);
                }));
            case 'History':
                $rows = array_values(array_filter(API::$history[(string) $options['itemids'][0]] ?? [], static function($row) use ($options) {
                    return (int) $row['clock'] >= $options['time_from'] && (int) $row['clock'] <= $options['time_till'];
                }));
                usort($rows, static function($a, $b) { return [(int) $a['clock'], (int) $a['ns']] <=> [(int) $b['clock'], (int) $b['ns']]; });
                return array_slice($rows, 0, $options['limit']);
            case 'Sla': return (string) $options['slaids'][0] === API::$sla['slaid'] ? [API::$sla] : [];
            case 'Service': return (string) $options['serviceids'][0] === API::$service['serviceid'] ? [API::$service] : [];
        }
        throw new RuntimeException('Unexpected synthetic endpoint: ' . $this->name);
    }
    public function getSli(array $options): array {
        API::$calls[] = [$this->name, 'getSli', $options];
        if ($this->name !== 'Sla') { throw new RuntimeException('Unexpected synthetic SLI endpoint'); }
        $from = $options['period_from']; $to = $options['period_to'] + 1;
        $basis = $to - $from;
        if (API::$sla['schedule']) {
            $basis = 0;
            $date = (new DateTimeImmutable('@' . $from))->setTimezone(new DateTimeZone(API::$sla['timezone']));
            while ($date->getTimestamp() < $to) {
                if ((int) $date->format('N') <= 5) { $basis += 9 * 3600; }
                $date = $date->modify('+1 day');
            }
        }
        return ['periods' => [['period_from' => $from, 'period_to' => $to]], 'serviceids' => [API::$service['serviceid']],
            'sli' => [[['uptime' => $basis, 'downtime' => 0, 'sli' => 100, 'error_budget' => 0, 'excluded_downtimes' => []]]]];
    }
}

function observedViewCases(): array {
    return ['observed90', 'strict', 'legacy', 'observed100', 'allunknown', 'mean', 'weights', 'mixed',
        'mixed_unknown', 'calendar', 'timezone', 'item_timezone', 'notqueried', 'seed', 'flexible', 'precision', 'native', 'native_observed', 'escaped'];
}
function observedViewItem(string $name = 'Item service', string $group = '1', float $weight = 1): array {
    return ['name' => $name, 'source' => 'items', 'weight' => $weight, 'target' => 99.9, 'groups' => $group,
        'mode' => 'any_down', 'checks' => [['key' => 'ping', 'max_age' => 3600,
            'up' => ['op' => 'eq', 'a' => 1], 'down' => ['op' => 'eq', 'a' => 0]]]];
}
function observedViewSla(): array {
    return ['name' => 'Native service', 'source' => 'sla', 'weight' => 2, 'target' => 99.9,
        'slaid' => '9007199254740993', 'serviceid' => '9223372036854775807'];
}
function observedViewRow(int $clock, $value): array {
    return ['clock' => (string) $clock, 'ns' => '0', 'value' => (string) $value];
}
function observedViewSamples(int $from, int $to, int $downFrom, int $step = 3600): array {
    $clocks = [];
    for ($clock = $from; $clock < $to; $clock += $step) { $clocks[$clock] = true; }
    if ($downFrom >= $from && $downFrom < $to) { $clocks[$downFrom] = true; }
    ksort($clocks, SORT_NUMERIC);
    $rows = [];
    foreach ($clocks as $clock => $_) { $rows[] = observedViewRow($clock, $clock < $downFrom ? 1 : 0); }
    return $rows;
}
function observedViewHost(string $id, string $group, string $name): void {
    API::$hosts[] = ['hostid' => $id, 'groupid' => $group, 'name' => $name, 'status' => '0'];
    API::$items[] = ['itemid' => '20' . $id, 'hostid' => $id, 'key_' => 'ping', 'value_type' => '3',
        'status' => '0', 'delay' => '1h', 'type' => '3', 'preprocessing' => []];
    API::$history['20' . $id] = [];
}
function observedViewFixture(string $case): array {
    if (!in_array($case, observedViewCases(), true)) { throw new InvalidArgumentException('Unknown synthetic case'); }
    $from = strtotime('2026-07-01 00:00:00 UTC'); $to = strtotime('2026-08-01 00:00:00 UTC');
    $duration = $to - $from;
    API::$groups = [['groupid' => '1', 'name' => 'Test services'], ['groupid' => '2', 'name' => 'No evidence'], ['groupid' => '3', 'name' => 'Confirmed down']];
    API::$hosts = API::$items = API::$history = API::$calls = [];
    API::$sla = ['slaid' => '9007199254740993', 'name' => 'Synthetic monthly SLA', 'period' => '2', 'status' => '1',
        'slo' => '99', 'timezone' => 'UTC', 'effective_date' => (string) strtotime('2025-01-01 UTC'),
        'schedule' => [], 'excluded_downtimes' => [], 'service_tags' => []];
    API::$service = ['serviceid' => '9223372036854775807', 'name' => 'Synthetic native service',
        'created_at' => (string) strtotime('2025-01-01 UTC')];
    observedViewHost('1', '1', 'Host with evidence');
    observedViewHost('2', '1', 'Host without evidence');
    $tech = observedViewItem();
    API::$history['201'] = observedViewSamples($from, $to, $from + (int) ($duration * 0.9));
    API::$config = ['timezone' => 'UTC', 'data_policy' => 'observed',
        'departments' => [['name' => 'Synthetic department', 'target' => 99.9, 'technologies' => [$tech]]]];
    if ($case === 'strict' || $case === 'native') { API::$config['data_policy'] = 'strict'; }
    if ($case === 'legacy') { unset(API::$config['data_policy']); }
    if ($case === 'observed100') { API::$history['201'] = observedViewSamples($from, $to, $to); }
    if ($case === 'allunknown' || $case === 'mixed_unknown') { API::$history['201'] = []; }
    if ($case === 'mean') {
        API::$config['departments'][0]['technologies'][0]['mode'] = 'mean';
        API::$history['201'] = observedViewSamples($from, $to, $to);
        $end = $from + (int) ($duration * 0.1);
        API::$history['202'] = observedViewSamples($from, $end, $from);
        API::$history['202'][] = observedViewRow($end, 'unclassified');
    }
    if ($case === 'weights') {
        API::$hosts = API::$items = API::$history = [];
        observedViewHost('1', '1', 'Known host'); observedViewHost('2', '2', 'Blind host'); observedViewHost('3', '3', 'Down host');
        API::$history['201'] = observedViewSamples($from, $to, $to);
        API::$history['203'] = observedViewSamples($from, $to, $from);
        API::$config['departments'][0]['technologies'] = [observedViewItem('Known technology', '1', 4),
            observedViewItem('Blind technology', '2', 2), observedViewItem('Down technology', '3', 1)];
    }
    if (in_array($case, ['mixed', 'mixed_unknown', 'calendar', 'timezone'], true)) {
        API::$config['departments'][0]['technologies'][] = observedViewSla();
        if ($case === 'timezone') { API::$sla['timezone'] = 'America/Cuiaba'; }
        if ($case === 'calendar') {
            for ($day = 1; $day <= 5; $day++) {
                API::$sla['schedule'][] = ['period_from' => $day * 86400 + 9 * 3600, 'period_to' => $day * 86400 + 18 * 3600];
            }
        }
    }
    if ($case === 'native' || $case === 'native_observed') { API::$config['departments'][0]['technologies'] = [observedViewSla()]; }
    if (in_array($case, ['notqueried', 'seed', 'flexible'], true)) {
        API::$hosts = [API::$hosts[0]]; API::$items = [API::$items[0]]; API::$history = ['201' => []];
        if ($case === 'notqueried') {
            API::$config['departments'][0]['technologies'][0]['checks'][0]['max_age'] = null;
            API::$items[0]['delay'] = '{$UNRESOLVED_INTERVAL}';
            API::$config['departments'][0]['technologies'][0]['checks'][] = ['key' => 'missing.key', 'max_age' => null,
                'up' => ['op' => 'eq', 'a' => 1], 'down' => null];
        }
        if ($case === 'seed') { API::$history['201'] = [observedViewRow($from - 1800, 1)]; }
        if ($case === 'flexible') {
            API::$config['departments'][0]['technologies'][0]['checks'][0]['max_age'] = null;
            API::$items[0]['delay'] = '30s;1m/6-7,00:00-24:00';
            API::$history['201'] = [observedViewRow($from, 1), observedViewRow($from + 60, 1)];
        }
    }
    if ($case === 'item_timezone') {
        API::$config['timezone'] = 'America/Cuiaba';
        $localFrom = strtotime('2026-07-01 04:00:00 UTC'); $localTo = strtotime('2026-08-01 04:00:00 UTC');
        API::$history['201'] = observedViewSamples($localFrom, $localTo, $localTo);
    }
    if ($case === 'precision') { API::$history['201'] = observedViewSamples($from, $to, $to - 1); }
    if ($case === 'escaped') {
        API::$config['departments'][0]['name'] = 'Department <script>alert("department")</script>';
        API::$config['departments'][0]['technologies'][0]['name'] = '<img src=x onerror="technology">';
        API::$hosts[0]['name'] = 'Host </script><script>host</script>';
    }
    $runner = new ObservedViewCalculation();
    $state = ObservedViewCalculation::create(API::$config, '2026-07', -1, strtotime('2026-08-28 12:00:00 UTC'));
    $steps = 0;
    while ($state['status'] === 'running' && $steps++ < 1000) {
        $state = $runner->advance(json_decode(json_encode($state, JSON_THROW_ON_ERROR), true), 1);
    }
    if ($state['status'] !== 'complete') { throw new RuntimeException($case . ': ' . ($state['error'] ?? 'Synthetic fixture did not complete')); }
    $report = ObservedViewCalculation::result($state);
    if ($case === 'escaped') {
        $report['departments'][0]['warnings'][] = 'Warning <img src=x onerror="warning"> / Aviso <img src=x onerror="warning">';
    }
    return ['case' => $case, 'synthetic_only' => true, 'persisted' => false, 'report' => $report,
        'calls' => API::$calls, 'checkpoints' => $steps];
}

class CObject {
    private $value;
    public function __construct($value) { $this->value = $value; }
    public function __toString() { return $this->value; }
}
class CWidget {
    private $items = [];
    public function setTitle($title) { return $this; }
    public function addItem($item) { $this->items[] = $item; return $this; }
    public function show() { echo '<main>' . implode('', $this->items) . '</main>'; }
}
class CForm {
    private $attributes = ['method' => 'post'], $items = [];
    public function setId($id) { return $this->setAttribute('id', $id); }
    public function setAction($action) { return $this->setAttribute('action', $action); }
    public function setAttribute($key, $value) { $this->attributes[$key] = $value; return $this; }
    public function addItem($value) { $this->items[] = $value; return $this; }
    public function __toString() {
        $html = '<form';
        foreach ($this->attributes as $key => $value) { $html .= ' ' . $key . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"'; }
        return $html . '><input type="hidden" name="sid" value="preview-only">' . implode('', $this->items) . '</form>';
    }
}
class ObservedViewRenderer {
    public $css = [], $js = [];
    public function addCssFile($file) { $this->css[] = '/' . $file; }
    public function includeJsFile($file) { $this->js[] = $file; }
    public function render(array $report, bool $pt = true, bool $dark = true, bool $config = false): string {
        $data = ['is_pt' => $pt, 'is_dark' => $dark, 'page_title' => 'Synthetic observed report',
            'config' => $report['configuration'], 'report' => $report, 'job' => null, 'error' => null,
            'revision' => 'preview-only', 'conflict' => false, 'month' => $report['month'], 'department' => -1];
        $level = ob_get_level(); ob_start();
        try {
            require __DIR__ . '/../views/governance.availability.' . ($config ? 'config' : 'view') . '.php';
            return ob_get_contents();
        }
        finally { while (ob_get_level() > $level) { ob_end_clean(); } }
    }
    public function scripts(): void {
        foreach ($this->js as $file) { require __DIR__ . '/../views/js/' . $file; }
    }
}
