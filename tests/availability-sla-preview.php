<?php
// Loopback-only visual QA. All endpoints below return synthetic data; no Zabbix access.
// php -S 127.0.0.1:8771 tests/availability-sla-preview.php
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true)) {
    http_response_code(404); exit;
}
$root = dirname(__DIR__);
$url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/modules/[^/]+/assets/(.+)$~', $url, $matches)) {
    $path = realpath($root . '/assets/' . $matches[1]);
    if (!$path || strpos($path, realpath($root . '/assets') . DIRECTORY_SEPARATOR) !== 0) { http_response_code(404); exit; }
    header('Content-Type: ' . (substr($path, -4) === '.css' ? 'text/css' : 'application/javascript'));
    readfile($path); exit;
}
if ($url === '/preview-native.css') {
    header('Content-Type: text/css');
    $path = sys_get_temp_dir() . '/governance-zabbix6-css/' . (isset($_GET['light']) ? 'blue-theme.css' : 'dark-theme.css');
    if (is_file($path)) { readfile($path); }
    exit;
}
if (strpos($url, '/assets/') === 0 || strpos($url, '/img/') === 0) { http_response_code(404); exit; }
require __DIR__ . '/availability-sla-fixture.php';
use Modules\Governance\AvailabilityCalculation as Calculation;
use Modules\Governance\AvailabilityConfig as Config;
use Modules\Governance\AvailabilityJobStore as Store;

class CObject {
    private $value;
    public function __construct($value) { $this->value = $value; }
    public function __toString() { return $this->value; }
}
require_once __DIR__ . '/widget-fixture.php';
class CForm {
    private $attributes = ['method' => 'post'], $items = [];
    public function setId($id) { return $this->setAttribute('id', $id); }
    public function setAction($action) { return $this->setAttribute('action', $action); }
    public function setAttribute($name, $value) { $this->attributes[$name] = $value; return $this; }
    public function addItem($item) { $this->items[] = $item; return $this; }
    public function __toString() {
        $html = '<form';
        foreach ($this->attributes as $key => $value) { $html .= ' ' . $key . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"'; }
        return $html . '><input type="hidden" name="sid" value="preview-only">' . implode('', $this->items) . '</form>';
    }
}
class SlaPreviewRenderer {
    public $css = [], $js = [];
    public function addCssFile($file) { $this->css[] = '/' . $file; }
    public function includeJsFile($file) { $this->js[] = $file; }
    public function render(string $name, array $data): string {
        ob_start();
        try { require __DIR__ . '/../views/' . $name . '.php'; return ob_get_contents(); }
        finally { ob_end_clean(); }
    }
    public function scripts(): void { foreach ($this->js as $file) { require __DIR__ . '/../views/js/' . $file; } }
}
API::reset();
$case = $_GET['case'] ?? 'native';
$dark = !isset($_GET['light']); $pt = !isset($_GET['en']);
$month = $case === 'open' ? '2026-08' : '2026-07';
if ($case === 'mixed') { API::$config['departments'][0]['technologies'][] = API::itemTechnology(); }
if ($case === 'mismatch') { API::$config['timezone'] = 'America/Cuiaba'; }
if ($case === 'unavailable') { API::$missingService = true; }
if ($case === 'invalid') { API::$missingCell = true; }
if ($case === 'custom') {
    for ($day = 1; $day <= 5; $day++) {
        API::$slas['1']['schedule'][] = ['period_from' => $day * 86400 + 9 * 3600, 'period_to' => $day * 86400 + 17 * 3600];
    }
    API::$basis['1'] = 23 * 8 * 3600;
}
if ($case === 'exclusions') {
    $start = strtotime('2026-07-02 09:00:00 UTC');
    API::$slas['1']['excluded_downtimes'] = [['name' => 'Manutenção planejada <teste>', 'period_from' => $start, 'period_to' => $start + 3600]];
    API::$basis['1'] = 2678400 - 3600;
}
$config = API::$config;
$context = ['case' => $case];
if (!$dark) { $context['light'] = 1; }
if (!$pt) { $context['en'] = 1; }
$contextQuery = '&' . http_build_query($context);
$action = $_GET['action'] ?? '';
$editing = isset($_GET['edit']) || $action === 'governance.availability.config';
$report = null; $job = null; $error = null; $department = -1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'governance.availability.run') {
    header('Content-Type: text/html; charset=utf-8');
    try {
        $validated = Config::validate(json_decode($_POST['availability_json'] ?? '', true));
        error_log('SLA local preview: configuration validated; nothing persisted.');
        echo '<!doctype html><title>Local validation only</title><pre>' . htmlspecialchars(json_encode([
            'synthetic_preview_only' => true, 'persisted' => false, 'validated' => $validated
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    catch (Throwable $exception) { http_response_code(422); echo json_encode(['error' => $exception->getMessage()]); }
    exit;
}
if ($action === 'governance.availability.run' || isset($_GET['job'])) {
    try {
        $store = new Store(sys_get_temp_dir() . '/governance-sla-preview-jobs');
        $owner = '1000002';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
            if (($_POST['sid'] ?? '') !== 'preview-only') { throw new RuntimeException('Local preview SID missing.'); }
            $operation = $_POST['operation'] ?? '';
            if ($operation === 'start') {
                $envelope = $store->create($owner, $_POST['request_id'] ?? '', static function() use ($config, $month) {
                    return Calculation::create($config, $_POST['month'] ?? $month, (int) ($_POST['department'] ?? -1), strtotime('2026-08-28 12:00:00 UTC'));
                });
            }
            elseif ($operation === 'step') {
                $envelope = $store->step($_POST['job'] ?? '', $owner, (int) ($_POST['sequence'] ?? -1), static function($state) {
                    API::$config = $state['source_config'];
                    return (new Calculation())->advance($state, 1);
                });
            }
            elseif ($operation === 'status') { $envelope = $store->read($_POST['job'] ?? '', $owner); }
            elseif ($operation === 'cancel') { $envelope = $store->cancel($_POST['job'] ?? '', $owner, (int) ($_POST['sequence'] ?? -1)); }
            else { throw new RuntimeException('Unsupported local preview operation.'); }
            $projection = Store::projection($envelope);
            if (isset($projection['result_url'])) { $projection['result_url'] .= $contextQuery; }
            echo json_encode($projection); exit;
        }
        $envelope = $store->read($_GET['job'], $owner);
        $state = $envelope['state'];
        $job = Store::projection($envelope);
        if (isset($job['result_url'])) { $job['result_url'] .= $contextQuery; }
        $config = $state['source_config'];
        $month = $state['report']['month']; $department = $state['department_filter'];
        $report = $state['status'] === 'complete' ? Calculation::result($state) : null;
        if ($state['status'] === 'failed') { $error = $state['error']; }
    }
    catch (Throwable $exception) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { http_response_code(400); echo json_encode(['error' => $exception->getMessage()]); exit; }
        $error = $exception->getMessage();
    }
}
elseif (isset($_GET['sample']) && !$editing) {
    $state = slaFixtureCalculation($config, $month);
    if ($state['status'] === 'complete') { $report = Calculation::result($state); }
    else { $error = $state['error'] ?? 'Synthetic calculation did not finish.'; }
}
$data = ['is_pt' => $pt, 'is_dark' => $dark, 'page_title' => 'Synthetic SLA preview', 'config' => $config,
    'revision' => 'preview', 'conflict' => false, 'report' => $report, 'job' => $job, 'error' => $error,
    'month' => $month, 'department' => $department];
$view = new SlaPreviewRenderer();
$body = $view->render('governance.availability.' . ($editing ? 'config' : 'view'), $data);
$body = str_replace('zabbix.php?action=governance.availability.run',
    'zabbix.php?action=governance.availability.run' . htmlspecialchars($contextQuery, ENT_QUOTES, 'UTF-8'), $body);
?><!doctype html><html lang="<?= $pt ? 'pt' : 'en' ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Local SLA preview · synthetic data only</title>
<link rel="stylesheet" href="/preview-native.css<?= $dark ? '' : '?light' ?>">
<?php foreach ($view->css as $css): ?><link rel="stylesheet" href="<?= htmlspecialchars($css, ENT_QUOTES, 'UTF-8') ?>"><?php endforeach ?>
<style>body{min-width:0!important;display:flex;background:<?= $dark ? '#1f2326' : '#f4f6f8' ?>;color:<?= $dark ? '#eee' : '#243442' ?>}.preview-nav{padding:18px;display:flex;flex-direction:column;gap:18px;flex:0 0 175px;border-right:1px solid #768d9944;font-size:12px}.preview-main{flex:1;min-width:0;padding:18px 22px}.preview-main main{padding:0;margin:0}@media(max-width:760px){.preview-nav{display:none}.preview-main{padding:12px}}</style>
</head><body><nav class="preview-nav"><b>DADOS FICTÍCIOS<br>TESTE LOCAL</b><a href="/">Calcular mês</a><a href="/?sample=1">SLA mensal</a><a href="/?sample=1&light=1&en=1">Light / English</a><a href="/?sample=1&case=mixed">Fontes mistas</a><a href="/?sample=1&case=mismatch">Fusos diferentes</a><a href="/?sample=1&case=unavailable">Serviço ausente</a><a href="/?sample=1&case=custom">Calendário útil</a><a href="/?sample=1&case=exclusions">Exclusões</a><a href="/?sample=1&case=open">Mês em andamento</a><a href="/?case=invalid">Erro de consulta</a><a href="/?edit=1">Editor escuro</a><a href="/?edit=1&light=1&en=1">Editor light / EN</a></nav>
<div class="preview-main wrapper"><?= $body ?></div><?php $view->scripts(); ?></body></html>
