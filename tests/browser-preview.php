<?php
// Local-only development harness. No Zabbix access or credentials. Temporary fake jobs only.
// php -S 127.0.0.1:8768 tests/browser-preview.php
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true)) { http_response_code(404); exit; }
if (in_array($_GET['action'] ?? '', ['governance.quality.view', 'governance.quality.run'], true)
        || (isset($_GET['quality']) && !isset($_GET['edit']) && !isset($_GET['action']))) {
    require __DIR__ . '/quality-preview.php';
    exit;
}
$root = dirname(__DIR__);
$url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/modules/[^/]+/assets/(.+)$~', $url, $matches) || preg_match('~^/assets/(.+)$~', $url, $matches)) {
    $path = realpath($root . '/assets/' . $matches[1]);
    if (!$path || strpos($path, realpath($root . '/assets') . DIRECTORY_SEPARATOR) !== 0) { http_response_code(404); exit; }
    header('Content-Type: ' . (substr($path, -4) === '.css' ? 'text/css' : 'application/javascript'));
    readfile($path); exit;
}
if (in_array($url, ['/preview-native.css', '/native.css'], true)) {
    $path = sys_get_temp_dir() . '/governance-zabbix6-css/' . (isset($_GET['light']) ? 'blue-theme.css' : 'dark-theme.css');
    header('Content-Type: text/css');
    if (is_file($path)) { readfile($path); }
    exit;
}
if (strpos($url, '/assets/') === 0 || strpos($url, '/img/') === 0) { http_response_code(404); exit; }
require $root . '/AvailabilityEngine.php';
require $root . '/AvailabilityConfig.php';
require $root . '/AvailabilityFreshness.php';
require $root . '/AvailabilityCalculation.php';
require $root . '/AvailabilityJobStore.php';
require $root . '/GovernanceConfig.php';
use Modules\Governance\AvailabilityEngine as Engine;
use Modules\Governance\AvailabilityConfig as Config;
use Modules\Governance\GovernanceConfig as QualityConfig;
use Modules\Governance\AvailabilityCalculation as Calculation;
use Modules\Governance\AvailabilityJobStore as JobStore;

// These endpoints generate ONLY synthetic data. They cannot access any Zabbix server.
class API {
    public static $fixture = [];
    public static function __callStatic($name, $arguments) { return new PreviewAvailabilityApi($name); }
}
class PreviewAvailabilityApi {
    private $endpoint;
    public function __construct($endpoint) { $this->endpoint = $endpoint; }
    public function get($options) {
        $groups = []; $hosts = []; $items = [];
        $index = 0;
        foreach (API::$fixture['departments'] as $department) {
            foreach ($department['technologies'] as $tech) {
                $index++;
                $groups[] = ['groupid' => (string) $index, 'name' => $tech['groups']];
                if ($department['name'] === 'Infraestrutura') { continue; }
                $hosts[] = ['hostid' => (string) (100 + $index), 'name' => 'Servidor de teste ' . $index,
                    'status' => '0', 'groupid' => (string) $index];
                foreach ($tech['checks'] as $i => $check) {
                    $heartbeat = strpos($check['key'], 'pgsql.') === 0;
                    $items[] = ['itemid' => (string) (1000 + 10 * $index + $i), 'hostid' => (string) (100 + $index),
                        'key_' => $check['key'], 'value_type' => '3', 'status' => '0', 'delay' => '1m', 'type' => '3',
                        'preprocessing' => $heartbeat ? [['type' => 20, 'params' => '1h']] : [],
                        '_step' => $heartbeat ? 3600 : 60, '_outage' => $index === 2 && $i === 1];
                }
            }
        }
        if ($this->endpoint === 'HostGroup') { return $groups; }
        if ($this->endpoint === 'Host') {
            return array_values(array_filter($hosts, static function($host) use ($options) {
                return in_array($host['groupid'], $options['groupids']);
            }));
        }
        if ($this->endpoint === 'Item') {
            return array_values(array_filter($items, static function($item) use ($options) {
                return in_array($item['hostid'], $options['hostids']) && in_array($item['key_'], $options['filter']['key_']);
            }));
        }
        if ($this->endpoint === 'History') {
            if (isset($_GET['preview_slow'])) { usleep(500000); }
            foreach ($items as $item) {
                if ($item['itemid'] !== $options['itemids'][0]) { continue; }
                $rows = [];
                for ($clock = (int) ceil($options['time_from'] / $item['_step']) * $item['_step'];
                        $clock <= $options['time_till'] && count($rows) < $options['limit']; $clock += $item['_step']) {
                    $down = $item['_outage'] && $clock % 86400 >= 43200 && $clock % 86400 < 44400;
                    $rows[] = ['clock' => $clock, 'ns' => 0, 'value' => $down ? '0' : '1'];
                }
                return $rows;
            }
            return [];
        }
        throw new RuntimeException('Unsupported local fixture endpoint.');
    }
}
class CObject { private $value; public function __construct($value) { $this->value = $value; } public function __toString() { return $this->value; } }
require_once __DIR__ . '/widget-fixture.php';
class CTag {
    private $tag, $paired, $items = [], $attributes = [];
    public function __construct($tag, $paired = true, $items = null) { $this->tag = $tag; $this->paired = $paired; $this->addItem($items); }
    public function addItem($item) { if (is_array($item)) { foreach ($item as $child) { $this->addItem($child); } } elseif ($item !== null) { $this->items[] = $item; } return $this; }
    public function setAttribute($key, $value) { $this->attributes[$key] = $value; return $this; }
    public function setId($id) { return $this->setAttribute('id', $id); }
    public function setTitle($title) { return $this->setAttribute('title', $title); }
    public function addClass($class) { $this->attributes['class'] = trim(($this->attributes['class'] ?? '') . ' ' . $class); return $this; }
    public function __toString() {
        $html = '<' . $this->tag;
        foreach ($this->attributes as $key => $value) { $html .= ' ' . $key . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"'; }
        $html .= '>';
        foreach ($this->items as $item) { $html .= is_object($item) ? (string) $item : htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8'); }
        return $html . ($this->paired ? '</' . $this->tag . '>' : '');
    }
}
class CDiv extends CTag { public function __construct($items = null) { parent::__construct('div', true, $items); } }
class CLink extends CTag { public function __construct($label, $url) { parent::__construct('a', true, $label); $this->setAttribute('href', $url); } }
class CForm extends CTag {
    public function __construct() { parent::__construct('form'); $this->setAttribute('method', 'post'); $this->addItem(new CObject('<input type="hidden" name="sid" value="preview-only">')); }
    public function setAction($action) { return $this->setAttribute('action', $action); }
}
class CUrl {
    private $url, $args = [];
    public function __construct($url) { $this->url = $url; }
    public function setArgument($name, $value) { $this->args[$name] = $value; return $this; }
    public function getUrl() { return $this->url . ($this->args ? '?' . http_build_query($this->args) : ''); }
}
class PreviewView {
    public $css = [], $js = [];
    public function addCssFile($file) { $this->css[] = '/' . $file; }
    public function includeJsFile($file) { $this->js[] = $file; }
    public function render($file, $data) { ob_start(); include dirname(__DIR__) . '/views/' . $file . '.php'; return ob_get_clean(); }
    public function scripts() { foreach ($this->js as $file) { include dirname(__DIR__) . '/views/js/' . $file; } }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') !== 'governance.availability.run') {
    header('Content-Type: text/plain; charset=utf-8');
    error_log('Governance local preview: validation POST received.');
    try {
        $validated = isset($_POST['quality_json']) ? QualityConfig::validateQualityPages(json_decode($_POST['quality_json'], true)) : Config::validate(json_decode($_POST['availability_json'], true));
        error_log('Governance local preview: validation passed; no persistence.');
        echo json_encode($validated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    catch (Exception $e) { http_response_code(422); echo json_encode(['error' => $e->getMessage()]); }
    exit;
}
$dark = !isset($_GET['light']); $pt = !isset($_GET['en']);
$isQuality = isset($_GET['quality']) || strpos($_GET['action'] ?? '', 'governance.quality.') === 0;
$editing = isset($_GET['edit']) || in_array($_GET['action'] ?? '', ['governance.availability.config', 'governance.quality.config'], true);
$from = strtotime('2026-05-01T00:00:00-04:00');
$to = strtotime(isset($_GET['partial']) ? '2026-05-16T12:00:00-04:00' : '2026-06-01T00:00:00-04:00');
$weights = [4, 2, 1]; $techs = []; $timelines = []; $configTechs = [];
$dayStats = static function($timeline) use ($from, $to) {
    $daily = []; for ($day = $from; $day < $to; $day += 86400) { $daily[] = ['day' => gmdate('Y-m-d', $day), 'summary' => Engine::summary($timeline, $day, min($to, $day + 86400))]; } return $daily;
};
foreach (['PostgreSQL', 'SQL Server', 'Qlik Sense'] as $index => $name) {
    $down = $index === 1 ? 1200 : 0;
    $timeline = [[$from, $from + 43200, 1, 0, 0], [$from + 43200, $from + 43200 + $down, 0, 1, 0], [$from + 43200 + $down, $to, 1, 0, 0]];
    $summary = Engine::summary($timeline, $from, $to);
    $key = $index === 0 ? 'pgsql.ping["{$PG.URI}","{$PG.USER}","{$PG.PASSWORD}"]' : 'service.up';
    $configTechs[] = ['name' => $name, 'weight' => $weights[$index], 'target' => 99.99, 'max_age' => 180,
        'mode' => 'any_down', 'groups' => 'Equipes/Banco de Dados/' . $name,
        'checks' => [['key' => 'icmpping', 'up' => ['op' => 'eq', 'a' => 1], 'down' => null], ['key' => $key, 'up' => ['op' => 'eq', 'a' => 1], 'down' => ['op' => 'eq', 'a' => 0]]]];
    $techs[] = ['name' => $name, 'weight' => $weights[$index], 'target' => 99.99, 'mode' => 'any_down',
        'groups' => ['Equipes/Banco de Dados/' . $name], 'warnings' => [], 'summary' => $summary, 'daily' => $dayStats($timeline),
        'hosts' => [['hostid' => (string) $index, 'name' => 'Servidor de demonstração ' . ($index + 1), 'summary' => $summary, 'warnings' => [],
            'sources' => [['key' => 'icmpping', 'itemid' => '1', 'max_age' => 180, 'freshness_mode' => 'auto', 'interval_seconds' => 60, 'heartbeat_seconds' => null],
                ['key' => $key, 'itemid' => '2', 'max_age' => 3720, 'freshness_mode' => 'auto', 'interval_seconds' => 60, 'heartbeat_seconds' => 3600]]]],
        'interval_count' => $down ? 1 : 0, 'intervals' => $down ? [[$from + 43200, $from + 43200 + $down, 0, 1, 0]] : []];
    $timelines[] = $timeline;
}
$combined = Engine::combine($timelines, 'mean', $from, $to, $weights);
$unknown = Engine::unknown($from, $to);
$config = ['timezone' => 'America/Cuiaba', 'departments' => [['name' => 'Banco de Dados', 'target' => 99.99, 'technologies' => $configTechs]]];
$report = ['month' => '2026-05', 'timezone' => 'America/Cuiaba', 'from' => $from, 'to' => $to, 'generated_at' => $to,
    'partial' => isset($_GET['partial']), 'rows' => 150000, 'configuration' => $config,
    'departments' => [['name' => 'Banco de Dados', 'target' => 99.99, 'summary' => Engine::summary($combined, $from, $to), 'daily' => $dayStats($combined), 'technologies' => $techs, 'warnings' => []]]];
$unknownTech = $techs[0]; $unknownTech['name'] = 'Conectividade'; $unknownTech['hosts'] = [];
$unknownTech['groups'] = ['Equipes/Infraestrutura/Conectividade'];
$unknownTech['summary'] = Engine::summary($unknown, $from, $to); $unknownTech['daily'] = $dayStats($unknown);
$unknownTech['warnings'] = ['No hosts in the selected groups / Nenhum host nos grupos selecionados.'];
$unknownTech['interval_count'] = 1; $unknownTech['intervals'] = $unknown;
$report['departments'][] = ['name' => 'Infraestrutura', 'target' => 99.9, 'summary' => $unknownTech['summary'], 'daily' => $unknownTech['daily'], 'technologies' => [$unknownTech], 'warnings' => []];
$config['departments'][] = ['name' => 'Infraestrutura', 'target' => 99.9, 'technologies' => [array_replace($configTechs[0], ['name' => 'Conectividade', 'groups' => 'Equipes/Infraestrutura/Conectividade'])]];
$report['configuration'] = $config;
// Exercise the actual runner/store through the real view scripts, but with the synthetic API above.
$job = null; $jobError = null;
$previewContext = '';
foreach (['light', 'en', 'preview_interrupt', 'preview_slow'] as $flag) { if (isset($_GET[$flag])) { $previewContext .= '&' . $flag . '=1'; } }
if (($_GET['action'] ?? '') === 'governance.availability.run' || isset($_GET['job'])) {
    try {
        $store = new JobStore(sys_get_temp_dir() . '/governance-browser-preview-jobs');
        $owner = '1000001';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            if (($_POST['sid'] ?? '') !== 'preview-only') { throw new RuntimeException('Local preview SID missing.'); }
            $operation = $_POST['operation'] ?? '';
            if ($operation === 'start') {
                $envelope = $store->create($owner, $_POST['request_id'] ?? '', static function() use ($config) {
                    foreach ($config['departments'] as &$node) {
                        foreach ($node['technologies'] as &$tech) {
                            foreach ($tech['checks'] as &$check) { $check['max_age'] = strpos($check['key'], 'pgsql.') === 0 ? 3720 : 180; }
                            unset($check);
                        }
                        unset($tech);
                    }
                    unset($node);
                    return Calculation::create($config, $_POST['month'] ?? '2026-05', (int) ($_POST['department'] ?? -1));
                });
            }
            elseif ($operation === 'step') {
                $envelope = $store->step($_POST['job'] ?? '', $owner, (int) ($_POST['sequence'] ?? -1), static function($state) {
                    API::$fixture = $state['source_config'];
                    return (new Calculation())->advance($state);
                });
                if (isset($_GET['preview_interrupt']) && $envelope['sequence'] === 8) {
                    // A committed response deliberately lost once, to test status/resume idempotency.
                    http_response_code(503); echo 'Simulated lost response after saving a checkpoint.'; exit;
                }
            }
            elseif ($operation === 'cancel') { $envelope = $store->cancel($_POST['job'] ?? '', $owner, (int) ($_POST['sequence'] ?? -1)); }
            elseif ($operation === 'status') { $envelope = $store->read($_POST['job'] ?? '', $owner); }
            else { throw new RuntimeException('Unsupported local preview operation.'); }
            $projection = JobStore::projection($envelope);
            if (isset($projection['result_url'])) { $projection['result_url'] .= $previewContext; }
            echo json_encode($projection); exit;
        }
        $envelope = $store->read($_GET['job'], $owner);
        $job = JobStore::projection($envelope);
        if (isset($job['result_url'])) { $job['result_url'] .= $previewContext; }
        $config = !empty($envelope['state']['source_config']) ? $envelope['state']['source_config'] : Config::defaults();
        $report = $envelope['state']['status'] === 'complete' ? Calculation::result($envelope['state']) : null;
    }
    catch (Exception $exception) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            http_response_code(400); echo json_encode(['error' => $exception->getMessage()]); exit;
        }
        $jobError = $exception->getMessage(); $report = null;
    }
}
elseif (!isset($_GET['sample'])) { $report = null; }
if (isset($_GET['empty'])) { $config['departments'] = []; $report = null; }
$data = ['is_pt' => $pt, 'is_dark' => $dark, 'page_title' => 'Preview', 'config' => $config, 'revision' => 'preview',
    'report' => $report, 'job' => $job, 'error' => $jobError, 'month' => $job['snapshot']['month'] ?? '2026-05',
    'department' => $job['snapshot']['department'] ?? -1, 'conflict' => isset($_GET['conflict'])];
if ($isQuality) {
    $qualityPages = [
        ['id' => 'main', 'name' => '', 'cards' => QualityConfig::getDefaultCards()],
        ['id' => 'network', 'name' => 'Conectividade', 'cards' => [[
            'id' => 'network_group', 'type' => 'hostgroups', 'title' => 'Grupos da equipe', 'description' => 'Hosts associados aos grupos da equipe de conectividade.',
            'tag_names' => '', 'tag_values' => '', 'group_names' => 'Equipes/Conectividade', 'include_score' => 1
        ]]],
        ['id' => 'draft', 'name' => 'Rascunho', 'cards' => []]
    ];
    if (isset($_GET['empty'])) { $qualityPages = []; }
    $selected = $_GET['page'] ?? ($qualityPages[0]['id'] ?? '');
    $qualityCards = [];
    foreach ($qualityPages as $page) { if ($page['id'] === $selected) { $qualityCards = $page['cards']; } }
    $qualityKpis = []; $scores = []; $hostCount = isset($_GET['nohosts']) ? 0 : 100;
    if ($hostCount) {
        foreach ($qualityCards as $index => $card) {
            $score = 93 - 6 * $index;
            if ($card['include_score']) { $scores[] = $score; }
            $qualityKpis[] = ['id' => 'kpi_' . $card['id'], 'title' => $card['title'], 'description' => $card['description'],
                'score' => $score, 'valid_count' => $score, 'total_count' => $hostCount,
                'status' => $score >= 90 ? 'good' : ($score >= 70 ? 'warning' : 'critical'),
                'non_compliant' => [['hostid' => '1', 'name' => 'Servidor de demonstração']]];
        }
    }
    $data = ['is_pt' => $pt, 'is_dark' => $dark, 'page_title' => 'Preview', 'pages' => $qualityPages,
        'selected_page' => $selected, 'revision' => 'preview', 'conflict' => isset($_GET['conflict']), 'groupids' => [],
        'cards_count' => count($qualityCards), 'total_hosts' => $hostCount, 'overall_score' => $scores ? round(array_sum($scores) / count($scores), 1) : null,
        'overview' => ['registered' => $hostCount + 3, 'monitored' => $hostCount, 'disabled' => 3, 'maintenance' => 2, 'unavailable' => 1, 'high_problems' => 0, 'unsupported_items' => 4],
        'kpis' => $qualityKpis];
}
$view = new PreviewView();
$body = $view->render('governance.' . ($isQuality ? 'quality' : 'availability') . ($editing ? '.config' : '.view'), $data);
$body = str_replace('zabbix.php?action=governance.availability.run',
    'zabbix.php?action=governance.availability.run' . htmlspecialchars($previewContext, ENT_QUOTES, 'UTF-8'), $body);
?><!doctype html><html lang="<?= $pt ? 'pt' : 'en' ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Governance preview</title>
<link rel="stylesheet" href="/preview-native.css<?= $dark ? '' : '?light' ?>">
<?php foreach ($view->css as $css): ?><link rel="stylesheet" href="<?= htmlspecialchars($css, ENT_QUOTES, 'UTF-8') ?>"><?php endforeach ?>
<style>body{min-width:0!important}.preview-nav{padding:18px;display:flex;flex-direction:column;gap:22px;flex:0 0 180px;border-right:1px solid #768d9944;font-size:12px}.preview-main{padding:18px 22px;min-width:0}.preview-main main{padding:0;margin:0}body{background:<?= $dark ? '#1f2326' : '#f4f6f8' ?>;color:<?= $dark ? '#eee' : '#243442' ?>}@media(max-width:760px){.preview-nav{display:none}.preview-main{padding:12px}}</style>
</head><body><nav class="preview-nav"><b>DADOS FICTÍCIOS · TESTE LOCAL</b><a href="/">Painel escuro</a><a href="/?light">Painel claro</a><a href="/?en">English</a><a href="/?preview_interrupt=1">Testar retomada</a><a href="/?sample">Relatório fictício</a><a href="/?edit">Editor</a><a href="/?edit&light">Editor claro</a><a href="/?edit&en">Editor English</a><a href="/?edit&empty">Editor vazio</a><a href="/?sample&partial">Parcial</a><a href="/?edit&conflict">Conflito</a><a href="/?quality">Qualidade</a><a href="/?quality&light">Qualidade clara</a><a href="/?quality&en">Quality English</a><a href="/?quality&edit">Editor de páginas</a><a href="/?quality&edit&light">Editor de páginas claro</a><a href="/?quality&edit&en">Page editor English</a><a href="/?quality&edit&empty">Sem páginas</a></nav>
<div class="preview-main wrapper"><?= $body ?><?php if ($editing): ?><div style="padding:16px"><button type="button" id="preview-check-draft">Verificar rascunho de teste</button><pre id="preview-draft" style="white-space:pre-wrap;overflow-wrap:anywhere"></pre></div><?php endif ?></div><?php $view->scripts(); ?>
<?php if ($editing): ?><script>document.getElementById('preview-check-draft').addEventListener('click',function(){document.getElementById('preview-draft').textContent=document.querySelector('input[name="quality_json"],input[name="availability_json"]').value;});</script><?php endif ?>
</body></html>
