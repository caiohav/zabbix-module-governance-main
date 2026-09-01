<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/quality-render-fixture.php';
$assertions = 0;
function viewCheck($ok, string $why) { global $assertions; $assertions++; if (!$ok) throw new RuntimeException($why); }
set_error_handler(static function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
foreach ([[true, true], [false, false]] as $settings) {
    [$pt, $dark] = $settings;
    $data = qualityRenderData($pt, $dark); $renderer = new QualityRenderer();
    $html = $renderer->render($data);
    viewCheck(strpos($html, $pt ? 'Carregando indicadores' : 'Loading indicators') !== false, 'localized loading state');
    viewCheck((strpos($html, 'gov-theme-dark') !== false) === $dark, 'theme class');
    viewCheck(strpos($html, 'name="sid"') !== false, 'native form supplies token');
    viewCheck(strpos($html, 'id="gqp-score">—') !== false && strpos($html, '100%') === false, 'initial state has no invented score');
    viewCheck(substr_count($html, 'data-card-id=') === 5, 'all card structures present immediately');
    viewCheck(strpos($html, 'groupids%5B0%5D=10') !== false, 'page tabs retain scope');
    viewCheck(strpos($html, '<noscript>') !== false && strpos($html, 'aria-live="polite"') !== false, 'accessibility and JS fallback');
    viewCheck(strpos($html, '<h2>' . htmlspecialchars($data['page_title'], ENT_QUOTES, 'UTF-8') . '</h2>') === false,
        'dashboard body does not repeat the widget title');
    viewCheck(strpos($html, 'Indicadores por página') === false && strpos($html, 'Indicators by page') === false,
        'redundant quality heading description is omitted');
    viewCheck(strpos($html, 'governance.quality.config') !== false, 'configuration link remains available beside the tabs');
    $data['cards'][0]['title'] = '<img src=x onerror=alert(1)>';
    $data['page_name'] = '</script><script>alert(1)</script>';
    $html = $renderer->render($data);
    viewCheck(strpos($html, '<img src=x') === false && strpos($html, '&lt;img') !== false, 'card titles escaped');
    viewCheck(strpos($html, '<script>alert') === false, 'page label escaped');
}
$data = qualityRenderData(); $data['error'] = 'Rules unavailable';
viewCheck(strpos((new QualityRenderer())->render($data), 'Rules unavailable') !== false, 'config failure is visible');
$data = qualityRenderData(true, true, 'empty');
viewCheck(strpos((new QualityRenderer())->render($data), 'Esta página ainda não possui cards') !== false, 'empty page distinct from loading');
restore_error_handler(); echo "PASS: $assertions quality PHP view assertions\n";
