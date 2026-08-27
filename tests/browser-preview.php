<?php
// Local-only development harness. No Zabbix access, persistence or credentials.
// php -S 127.0.0.1:8768 tests/browser-preview.php
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true)) { http_response_code(404); exit; }
$root = dirname(__DIR__);
$url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/modules/[^/]+/assets/(.+)$~', $url, $matches) || preg_match('~^/assets/(.+)$~', $url, $matches)) {
    $path = realpath($root . '/assets/' . $matches[1]);
    if (!$path || strpos($path, realpath($root . '/assets') . DIRECTORY_SEPARATOR) !== 0) { http_response_code(404); exit; }
    header('Content-Type: ' . (substr($path, -4) === '.css' ? 'text/css' : 'application/javascript'));
    readfile($path); exit;
}
if ($url === '/preview-native.css') {
    $path = sys_get_temp_dir() . '/governance-zabbix6-css/' . (isset($_GET['light']) ? 'blue-theme.css' : 'dark-theme.css');
    header('Content-Type: text/css');
    if (is_file($path)) { readfile($path); }
    exit;
}
if (strpos($url, '/assets/') === 0 || strpos($url, '/img/') === 0) { http_response_code(404); exit; }
require $root . '/AvailabilityEngine.php';
require $root . '/AvailabilityConfig.php';
use Modules\Governance\AvailabilityEngine as Engine;
use Modules\Governance\AvailabilityConfig as Config;
class CObject { private $value; public function __construct($value) { $this->value = $value; } public function __toString() { return $this->value; } }
class CWidget { private $items = []; public function setTitle($title) { return $this; } public function addItem($item) { $this->items[] = $item; return $this; } public function show() { echo '<main>' . implode('', $this->items) . '</main>'; } }
class CForm {
    private $items = [];
    public function setId($id) { return $this; } public function setAction($action) { return $this; }
    public function addItem($item) { $this->items[] = $item; return $this; }
    public function __toString() { return '<form id="gav-config-form" method="post" action="/zabbix.php?action=governance.availability.save"><input type="hidden" name="sid" value="preview-only">' . implode('', $this->items) . '</form>'; }
}
class PreviewView {
    public $css = [], $js = [];
    public function addCssFile($file) { $this->css[] = '/' . $file; }
    public function includeJsFile($file) { $this->js[] = $file; }
    public function render($file, $data) { ob_start(); include dirname(__DIR__) . '/views/' . $file . '.php'; return ob_get_clean(); }
    public function scripts() { foreach ($this->js as $file) { include dirname(__DIR__) . '/views/js/' . $file; } }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try { echo json_encode(Config::validate(json_decode($_POST['availability_json'], true)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); }
    catch (Exception $e) { http_response_code(422); echo json_encode(['error' => $e->getMessage()]); }
    exit;
}
$dark = !isset($_GET['light']); $pt = !isset($_GET['en']);
$editing = isset($_GET['edit']) || ($_GET['action'] ?? '') === 'governance.availability.config';
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
if (isset($_GET['empty'])) { $config['departments'] = []; $report = null; }
$data = ['is_pt' => $pt, 'is_dark' => $dark, 'page_title' => 'Preview', 'config' => $config, 'revision' => 'preview',
    'report' => $report, 'error' => null, 'month' => '2026-05', 'department' => -1, 'conflict' => isset($_GET['conflict'])];
$view = new PreviewView();
$body = $view->render($editing ? 'governance.availability.config' : 'governance.availability.view', $data);
?><!doctype html><html lang="<?= $pt ? 'pt' : 'en' ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Governance preview</title>
<link rel="stylesheet" href="/preview-native.css<?= $dark ? '' : '?light' ?>">
<?php foreach ($view->css as $css): ?><link rel="stylesheet" href="<?= htmlspecialchars($css, ENT_QUOTES, 'UTF-8') ?>"><?php endforeach ?>
<style>body{min-width:0!important}.preview-nav{padding:18px;display:flex;flex-direction:column;gap:22px;flex:0 0 180px;border-right:1px solid #768d9944;font-size:12px}.preview-main{padding:18px 22px;min-width:0}.preview-main main{padding:0;margin:0}body{background:<?= $dark ? '#1f2326' : '#f4f6f8' ?>;color:<?= $dark ? '#eee' : '#243442' ?>}@media(max-width:760px){.preview-nav{display:none}.preview-main{padding:12px}}</style>
</head><body><nav class="preview-nav"><b>DADOS FICTÍCIOS · TESTE LOCAL</b><a href="/">Painel escuro</a><a href="/?light">Painel claro</a><a href="/?en">English</a><a href="/?edit">Editor</a><a href="/?edit&light">Editor claro</a><a href="/?edit&en">Editor English</a><a href="/?edit&empty">Editor vazio</a><a href="/?partial">Parcial</a><a href="/?edit&conflict">Conflito</a></nav>
<div class="preview-main wrapper"><?= $body ?><?php if ($editing): ?><div style="padding:16px"><button type="button" id="preview-check-draft">Verificar rascunho de teste</button><pre id="preview-draft" style="white-space:pre-wrap;overflow-wrap:anywhere"></pre></div><?php endif ?></div><?php $view->scripts(); ?>
<?php if ($editing): ?><script>document.getElementById('preview-check-draft').addEventListener('click',function(){document.getElementById('preview-draft').textContent=document.getElementById('gav-payload').value;});</script><?php endif ?>
</body></html>
