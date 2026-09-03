<?php
// php -S 127.0.0.1:8770 tests/quality-preview.php — ONLY synthetic data, never a live Zabbix API.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true)) { http_response_code(404); exit; }
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/modules/[^/]+/assets/(.+)$~', $path, $match)) {
    $file = realpath($root . '/assets/' . $match[1]);
    if (!$file || strpos($file, realpath($root . '/assets') . DIRECTORY_SEPARATOR) !== 0) { http_response_code(404); exit; }
    header('Content-Type: ' . (substr($file, -4) === '.css' ? 'text/css' : 'application/javascript'));
    readfile($file); exit;
}
if ($path === '/native.css') {
    header('Content-Type: text/css');
    $file = sys_get_temp_dir() . '/governance-zabbix6-css/' . (isset($_GET['light']) ? 'blue-theme.css' : 'dark-theme.css');
    if (is_file($file)) readfile($file);
    exit;
}
if (!in_array($path, ['/', '/zabbix.php'], true)) { http_response_code(404); exit; }
require_once __DIR__ . '/quality-fixture.php';
use Modules\Governance\QualityCalculation as Calculation;
use Modules\Governance\QualityJobStore as Store;
use Modules\Governance\GovernanceConfig as Config;
$scenario = $_GET['preview_case'] ?? 'normal';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!in_array($_POST['sid'] ?? '', ['local-fixture-only', 'preview-only'], true)) { http_response_code(403); exit; }
    if (($_POST['operation'] ?? '') === 'lookup') {
        require_once __DIR__ . '/../QualityCatalog.php';
        echo json_encode(Modules\Governance\QualityCatalog::search($_POST['lookup_type'], $_POST['query'], static function($service) {
            return $service === 'HostGroup' ? [['groupid'=>'10','name'=>'Equipes']] : [['templateid'=>'10001','name'=>'Linux by Zabbix agent','host'=>'Linux by Zabbix agent']];
        })); exit;
    }
    $fixture = new QualityFixture($scenario === 'empty' ? 0 : 501);
    $fixture->delay = $scenario === 'slow' ? 800000 : 80000;
    $fixture->fail = $scenario === 'failure' ? 'Item' : '';
    foreach ($fixture->rows as &$row) {
        if (isset($row['parentTemplates']) && !is_array($row['parentTemplates'])) {
            $row['parentTemplates'] = $row['parentTemplates'] === '1' ? [['templateid' => '10001', 'host' => 'Linux by Zabbix agent', 'name' => 'Linux by Zabbix agent']] : [];
        }
    }
    unset($row);
    $engine = new Calculation([$fixture, 'get']);
    $store = new Store(sys_get_temp_dir() . '/governance-quality-preview-' . substr(hash('sha256', __DIR__), 0, 16));
    try {
        if ($_POST['operation'] === 'preview_start') {
            $pages = Config::validateQualityPages([['id'=>'preview','name'=>'Preview','cards'=>[json_decode($_POST['card_json'], true)]]]);
            $job = $store->create('1', $_POST['request_id'], static function() use ($pages) {
                $config=['quality_pages'=>$pages];
                $state=Calculation::create($config,'preview',[],Config::qualityRevision($config));
                $state['preview']=true; $state['preview_hosts']=[]; return $state;
            });
        }
        elseif ($_POST['operation'] === 'start') {
            $job = $store->create('1', $_POST['request_id'], static function() {
                return Calculation::create(fixtureConfig(), $_POST['page'], $_POST['groupids'] ?? [], $_POST['revision']);
            });
        }
        elseif ($_POST['operation'] === 'step') {
            $job = $store->step($_POST['job'], '1', (int) $_POST['sequence'], [$engine, 'advance']);
        }
        elseif ($_POST['operation'] === 'cancel') { $job=$store->cancel($_POST['job'],'1',(int)$_POST['sequence']); }
        else { $job = $store->read($_POST['job'], '1'); }
        echo json_encode(Store::projection($job));
    }
    catch (Throwable $e) { echo json_encode(['status' => 'failed', 'error' => $e->getMessage()]); }
    exit;
}
require __DIR__ . '/quality-render-fixture.php';
$pt = !isset($_GET['en']); $dark = !isset($_GET['light']);
$data = qualityRenderData($pt, $dark, $_GET['page'] ?? 'main');
$renderer = new QualityRenderer(); $html = $renderer->render($data);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Qualidade — teste local</title>
<link rel="stylesheet" href="/native.css<?= $dark ? '' : '?light=1' ?>">
<?php foreach ($renderer->css as $css): ?><link rel="stylesheet" href="/<?= htmlspecialchars($css) ?>"><?php endforeach ?>
<style>body{display:block;margin:0;padding:22px;background:<?= $dark ? '#1f2224' : '#f4f6f7' ?>;color:<?= $dark ? '#eef1f3' : '#28343d' ?>;font:14px Arial}main{padding:0;min-width:0}.preview-nav{display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap}</style></head><body>
<nav class="preview-nav" aria-label="Teste local"><strong>DADOS SIMULADOS</strong><a href="/zabbix.php?preview_case=slow">Carregamento lento</a><a href="/zabbix.php?light=1&en=1">Light / English</a><a href="/zabbix.php?preview_case=failure">Falha operacional</a><a href="/zabbix.php?preview_case=empty">Sem hosts</a></nav>
<?= $html ?><?php $renderer->scripts(); ?></body></html>
