<?php
// Loopback-only view/config QA. No production API, persistence or background jobs.
// php -S 127.0.0.1:8772 tests/availability-observed-preview.php
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404); exit;
}
$observedRoot = dirname(__DIR__);
$observedPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($observedPath) && preg_match('~^/modules/[^/]+/assets/(.+)$~', $observedPath, $matches)) {
    $assetRoot = realpath($observedRoot . '/assets');
    $asset = realpath($observedRoot . '/assets/' . $matches[1]);
    if (!$assetRoot || !$asset || !is_file($asset) || strpos($asset, $assetRoot . DIRECTORY_SEPARATOR) !== 0
        || !in_array(pathinfo($asset, PATHINFO_EXTENSION), ['css', 'js'], true)) { http_response_code(404); exit; }
    header('Content-Type: ' . (substr($asset, -4) === '.css' ? 'text/css' : 'application/javascript'));
    readfile($asset); exit;
}
if ($observedPath === '/preview-native.css') {
    header('Content-Type: text/css');
    $nativeCss = sys_get_temp_dir() . '/governance-zabbix6-css/' . (isset($_GET['light']) ? 'blue-theme.css' : 'dark-theme.css');
    if (is_file($nativeCss)) { readfile($nativeCss); }
    exit;
}
if (!in_array($observedPath, ['/', '/zabbix.php'], true)) { http_response_code(404); exit; }
require __DIR__ . '/availability-observed-view-fixture.php';
set_error_handler(static function($severity, $message, $file, $line) {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
header('Cache-Control: no-store');
$action = $_GET['action'] ?? 'governance.availability.view';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if ($action !== 'governance.availability.save' || ($_POST['sid'] ?? '') !== 'preview-only') {
        http_response_code(400);
        echo json_encode(['synthetic_preview_only' => true, 'persisted' => false,
            'error' => 'This preview renders ready-made cases; only local configuration validation is supported.']);
        exit;
    }
    try {
        $config = \Modules\Governance\AvailabilityConfig::validate(json_decode($_POST['availability_json'] ?? '', true));
        echo json_encode(['synthetic_preview_only' => true, 'persisted' => false, 'validated' => $config],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
    catch (Throwable $exception) { http_response_code(422); echo json_encode(['persisted' => false, 'error' => $exception->getMessage()]); }
    exit;
}
if (!in_array($action, ['governance.availability.view', 'governance.availability.config'], true)) { http_response_code(404); exit; }
$case = $_GET['case'] ?? 'observed90';
if (!is_string($case) || !in_array($case, observedViewCases(), true)) { http_response_code(404); exit; }
$fixture = observedViewFixture($case);
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); exit;
}
$pt = !isset($_GET['en']); $dark = !isset($_GET['light']);
$editing = $action === 'governance.availability.config' || isset($_GET['edit']);
$renderer = new ObservedViewRenderer();
$body = $renderer->render($fixture['report'], $pt, $dark, $editing);
$escape = static function($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); };
$context = ['case' => $case];
if (!$pt) { $context['en'] = 1; }
if (!$dark) { $context['light'] = 1; }
$contextQuery = '&' . http_build_query($context);
foreach (['config', 'view'] as $destination) {
    $body = str_replace('href="zabbix.php?action=governance.availability.' . $destination . '"',
        'href="zabbix.php?action=governance.availability.' . $destination . $escape($contextQuery) . '"', $body);
}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="<?= $pt ? 'pt' : 'en' ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Observed policy · local synthetic QA</title>
<link rel="stylesheet" href="/preview-native.css<?= $dark ? '' : '?light' ?>">
<?php foreach ($renderer->css as $css): ?><link rel="stylesheet" href="<?= $escape($css) ?>"><?php endforeach ?>
<style>body{min-width:0!important;margin:0;display:flex;background:<?= $dark ? '#1f2326' : '#f4f6f8' ?>;color:<?= $dark ? '#eee' : '#243442' ?>;font-family:Arial,sans-serif}.preview-nav{padding:18px;display:flex;flex-direction:column;gap:14px;flex:0 0 190px;border-right:1px solid #768d9944;font-size:12px}.preview-main{flex:1;min-width:0;padding:18px 22px}.preview-main main{padding:0;margin:0}.preview-nav p{line-height:1.5}@media(max-width:760px){.preview-nav{display:none}.preview-main{padding:12px}}</style>
</head><body><nav class="preview-nav"><b>DADOS FICTÍCIOS<br>TESTE LOCAL</b><p>Nenhuma produção é acessada. Salvar apenas valida; não persiste.</p>
<?php foreach (['observed90' => '90% · cobertura 50%', 'strict' => 'Mesmo caso estrito', 'observed100' => '100% · cobertura parcial', 'allunknown' => 'Tudo desconhecido',
    'mean' => 'Média dos hosts', 'weights' => 'Pesos 4 / 2 / 1', 'mixed' => 'Itens + SLA', 'calendar' => 'Calendários incompatíveis',
    'notqueried' => 'Histórico não consultado', 'seed' => 'Amostra anterior', 'flexible' => 'Validade flexível'] as $navCase => $label): ?>
    <a href="/zabbix.php?<?= $escape(http_build_query(array_replace($context, ['case' => $navCase]))) ?>"><?= $escape($label) ?></a>
<?php endforeach ?>
<a href="/zabbix.php?action=governance.availability.config<?= $escape($contextQuery) ?>">Editor do caso</a>
<a href="/zabbix.php?action=<?= $editing ? 'governance.availability.config' : 'governance.availability.view' ?>&amp;case=<?= $escape($case) ?>&amp;light=1&amp;en=1">Light / English</a>
<a href="/zabbix.php?action=<?= $editing ? 'governance.availability.config' : 'governance.availability.view' ?>&amp;case=<?= $escape($case) ?>">Dark / Português</a>
<a href="/?case=<?= $escape($case) ?>&amp;format=json">Metadados sintéticos</a>
</nav><div class="preview-main wrapper"><?= $body ?></div><?php $renderer->scripts(); ?></body></html>
