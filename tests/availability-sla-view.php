<?php
// Synthetic runner + real PHP view; no production API, database or filesystem writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/availability-sla-fixture.php';

use Modules\Governance\AvailabilityCalculation as Calculation;

class CObject {
    private $value;
    public function __construct($value) { $this->value = $value; }
    public function __toString() { return $this->value; }
}
class CWidget {
    private $items = [];
    public function setTitle($value) { return $this; }
    public function addItem($value) { $this->items[] = $value; return $this; }
    public function show() { echo implode('', $this->items); }
}
class CForm {
    public function setId($value) { return $this; }
    public function setAction($value) { return $this; }
    public function setAttribute($name, $value) { return $this; }
    public function __toString() { return '<form><input name="sid" value="test-only"></form>'; }
}
class SlaViewRenderer {
    public function addCssFile($value) {}
    public function includeJsFile($value) {}
    public function render(array $report, bool $pt = true, bool $dark = true): string {
        $data = ['is_pt' => $pt, 'is_dark' => $dark, 'page_title' => 'Test report',
            'config' => $report['configuration'], 'report' => $report, 'job' => null,
            'error' => null, 'month' => $report['month'], 'department' => -1];
        $level = ob_get_level();
        ob_start();
        try { require __DIR__ . '/../views/governance.availability.view.php'; return ob_get_contents(); }
        finally { while (ob_get_level() > $level) { ob_end_clean(); } }
    }
}
function slaViewReport(): array {
    $state = slaFixtureCalculation();
    if ($state['status'] !== 'complete') { throw new RuntimeException('Fixture did not finish: ' . ($state['error'] ?? $state['status'])); }
    return Calculation::result($state);
}
function slaViewFixtures(): array {
    $reports = [];
    API::reset(); $reports['native'] = slaViewReport();
    API::reset();
    API::$config['departments'][0]['technologies'][] = API::itemTechnology();
    $reports['mixed'] = slaViewReport();
    API::$missingHistory = true;
    $reports['item_unknown'] = slaViewReport();
    API::reset();
    API::$slas['1']['timezone'] = 'America/Cuiaba';
    $reports['timezone'] = slaViewReport();
    API::reset();
    API::$slas['2'] = array_replace(API::$slas['1'], ['slaid' => '2', 'name' => 'Office hours SLA', 'schedule' => []]);
    for ($day = 1; $day <= 5; $day++) {
        API::$slas['2']['schedule'][] = ['period_from' => $day * 86400 + 9 * 3600, 'period_to' => $day * 86400 + 18 * 3600];
    }
    API::$config['departments'][0]['technologies'][1]['slaid'] = '2';
    API::$basis['2'] = 23 * 9 * 3600;
    $reports['calendar'] = slaViewReport();
    API::reset(); API::$missingService = true;
    $reports['unavailable'] = slaViewReport();
    API::reset(); API::$down['11'] = 31 * 86400;
    $reports['zero'] = slaViewReport();
    API::reset();
    API::$config['departments'][0]['technologies'] = [API::itemTechnology()];
    $reports['items_only'] = slaViewReport();
    API::reset();
    API::$config['departments'][0]['name'] = '<script>alert("department")</script>';
    API::$config['departments'][0]['technologies'][0]['name'] = '<img src=x onerror="technology">';
    API::$slas['1']['name'] = 'Native <svg onload="sla"> & name';
    API::$services['11']['name'] = 'Service </script><script>service</script>';
    API::$slas['1']['excluded_downtimes'] = [['name' => 'Maintenance <img src=x onerror="excluded">',
        'period_from' => strtotime('2026-07-01 12:00:00 UTC'), 'period_to' => strtotime('2026-07-01 13:00:00 UTC')]];
    API::$basis['1'] = 31 * 86400 - 3600;
    $reports['escaped'] = slaViewReport();
    $reports['escaped']['departments'][0]['warnings'][] = 'Warning <img src=x onerror="warning"> / Aviso <img src=x onerror="warning">';
    $reports['escaped']['departments'][0]['technologies'][0]['warnings'][] = 'Native <script>warning</script> / Nativo <script>warning</script>';
    return $reports;
}

$assertions = 0;
function slaViewCheck(bool $ok, string $label): void {
    global $assertions;
    $assertions++;
    if (!$ok) { throw new RuntimeException($label); }
}
function slaViewEmbeddedReport(string $html): array {
    if (!preg_match('~<script type="application/json" id="gav-report-data">(.*?)</script>~s', $html, $match)) {
        throw new RuntimeException('Missing embedded report');
    }
    $report = json_decode($match[1], true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($report)) { throw new RuntimeException('Invalid embedded report JSON'); }
    return $report;
}
function slaViewMetrics(string $html): string {
    if (!preg_match('~<div class="gav-metrics">(.*?)</div>\s*</div>~s', $html, $match)) {
        throw new RuntimeException('Missing metrics');
    }
    return $match[1];
}

set_error_handler(static function($severity, $message, $file, $line) {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
try {
    $reports = slaViewFixtures();
    if (in_array('--fixtures-json', $argv, true)) {
        echo json_encode($reports, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        exit;
    }
    $renderer = new SlaViewRenderer();
    $callsBefore = count(API::$calls);
    foreach ($reports as $name => $report) {
        foreach ([true, false] as $pt) {
            $html = $renderer->render($report, $pt, $pt);
            slaViewCheck(strpos($html, 'id="gav-report"') !== false, $name . ': complete report renders');
            slaViewCheck(slaViewEmbeddedReport($html) == $report, $name . ': embedded report retains the exact real runner result');
            slaViewCheck((strpos($html, 'gov-theme-dark') !== false) === $pt, $name . ': effective theme marker preserved');
        }
    }
    slaViewCheck(count(API::$calls) === $callsBefore, 'rendering reports never queries API or recomputes a source');

    $native = $reports['native'];
    $html = $renderer->render($native);
    slaViewCheck($native['has_sla'] && !$native['has_items'] && $native['rows'] === 0, 'native fixture has no item history');
    slaViewCheck(strpos($html, 'Calendário por fonte') !== false, 'native report identifies source-specific calendar');
    slaViewCheck(substr_count($html, 'class="gav-monthly-chart"') === 1, 'native-only report has monthly comparison');
    slaViewCheck(strpos($html, 'class="gav-chart"') === false && strpos($html, 'gav-chart-selection') === false, 'native-only report has no fabricated daily chart');
    slaViewCheck(strpos($html, 'Intervalos com queda ou lacuna') === false, 'native summaries do not imply reconstructed outage intervals');
    slaViewCheck(strpos($html, 'Amostras no período:') === false, 'native service is not labeled as an item sample source');
    slaViewCheck(substr_count($html, 'Fonte explícita: SLA nativo.') === 3, 'every native technology explicitly identifies its source');
    slaViewCheck(strpos($html, 'Tempo programado avaliado; não é cobertura de amostras.') !== false, 'native coverage is qualified as scheduled coverage');
    slaViewCheck(strpos($html, 'SLI nativo / meta do SLA') !== false && strpos($html, '100% / 99%') !== false, 'native SLO shown separately from the module target');
    slaViewCheck(strpos($html, '99,965118%') !== false, 'department weighted result uses real native runner score');
    preg_match_all('~href="([^"]*slareport\.list[^"]*)"~', $html, $links);
    slaViewCheck(count($links[1]) === 3, 'one inspect-native link per SLA technology');
    foreach ($links[1] as $index => $link) {
        $url = html_entity_decode($link, ENT_QUOTES, 'UTF-8');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        slaViewCheck(strpos($url, 'zabbix.php?') === 0 && $query['action'] === 'slareport.list', 'native link is local and has the correct action');
        slaViewCheck($query['filter_slaid'] === '1' && $query['filter_serviceid'] === (string) (11 + $index), 'native link preserves explicit SLA and service IDs');
    }

    $mixed = $reports['mixed']; $html = $renderer->render($mixed);
    slaViewCheck($mixed['has_sla'] && $mixed['has_items'], 'mixed fixture actually has both sources');
    slaViewCheck(strpos($html, 'class="gav-monthly-chart"') !== false && substr_count($html, 'class="gav-chart"') === 1, 'mixed report includes monthly comparison and actual item daily chart');
    preg_match('~<select class="gav-chart-selection"[^>]*>(.*?)</select>~s', $html, $selection);
    slaViewCheck(isset($selection[1]) && preg_match('~<option value="3">Item ping</option>~', $selection[1]) === 1, 'daily chart offers the actual item technology');
    slaViewCheck(substr_count($selection[1], '<option ') === 1 && strpos($selection[1], 'value="-1"') === false, 'no native technology or mixed department is offered as a daily timeline');
    slaViewCheck(strpos($html, 'Amostras no período:') !== false, 'item diagnostics remain present in a mixed report');

    foreach (['timezone', 'calendar'] as $name) {
        $report = $reports[$name]; $html = $renderer->render($report);
        slaViewCheck($report['departments'][0]['aggregation_compatible'] === false, $name . ': real runner blocks aggregation');
        slaViewCheck($report['departments'][0]['summary']['score'] === null && $report['departments'][0]['summary']['down'] === null, $name . ': incompatible department has no invented total');
        slaViewCheck(strpos($html, 'Fontes não comparáveis') !== false && strpos($html, 'Índice inconclusivo. Faixa possível:') === false, $name . ': incompatibility is distinguished from missing samples');
        $metrics = slaViewMetrics($html);
        slaViewCheck(substr_count($metrics, '<strong>—</strong>') === 3 && strpos($metrics, '0h 00m 00s') === false, $name . ': null coverage/durations render as dashes, not zero');
        slaViewCheck(strpos($html, '100%') !== false && strpos($html, 'class="gav-monthly-chart"') !== false, $name . ': valid individual SLIs remain visible');
    }
    $html = $renderer->render($reports['timezone']);
    preg_match('~<dt>Período nativo \(fim exclusivo\)</dt><dd>(.*?)</dd>~s', $html, $nativePeriod);
    slaViewCheck(strpos($html, 'America/Cuiaba') !== false
        && ($nativePeriod[1] ?? '') === '01/07/2026 00:00:00 → 01/08/2026 00:00:00', 'native period is rendered in its own timezone, not relabeled as UTC');
    slaViewCheck(strpos($renderer->render($reports['calendar']), 'Personalizado no SLA') !== false, 'native custom calendar is identified');

    $html = $renderer->render($reports['unavailable']);
    slaViewCheck($reports['unavailable']['departments'][0]['technologies'][0]['summary']['score'] === null, 'unavailable source fixture has no score');
    slaViewCheck(strpos($html, '<td>—</td><td>—</td>') !== false, 'unavailable native durations remain null in the technology table');
    slaViewCheck(strpos($html, '<dt>Base após exclusões</dt><dd>— <small>Tempo excluído: —</small>') !== false, 'unavailable native basis and excluded duration remain null, not zero');
    slaViewCheck(strpos($html, 'Fonte explícita: SLA nativo.') !== false && strpos($html, 'class="gav-source"') === false, 'unavailable native source does not fall back to item diagnostics');
    $html = $renderer->render($reports['item_unknown']);
    slaViewCheck($reports['item_unknown']['departments'][0]['aggregation_compatible'] === true, 'real item gap retains compatible calendar');
    slaViewCheck(strpos($html, 'Índice inconclusivo. Faixa possível:') !== false, 'real unknown time is still shown as incomplete, not incompatible');

    $html = $renderer->render($reports['escaped']);
    foreach (['<script>alert("department")</script>', '<img src=x onerror="technology">',
        'Native <svg onload="sla"> & name', 'Service </script><script>service</script>',
        'Maintenance <img src=x onerror="excluded">', 'Aviso <img src=x onerror="warning">', 'Nativo <script>warning</script>'] as $value) {
        slaViewCheck(strpos($html, htmlspecialchars($value, ENT_QUOTES, 'UTF-8')) !== false, 'untrusted label/warning is HTML-escaped: ' . $value);
        slaViewCheck(strpos($html, $value) === false, 'untrusted label/warning is never raw markup: ' . $value);
    }
    slaViewCheck(substr_count($html, '</script>') === 2, 'untrusted service names cannot terminate embedded JSON script');
    slaViewCheck(strpos($html, 'Exclusões do SLA neste mês') !== false && strpos($html, 'Tempo excluído:') !== false, 'native exclusion details are rendered');
    $itemsHtml = $renderer->render($reports['items_only']);
    slaViewCheck(strpos($itemsHtml, 'class="gav-monthly-chart"') === false && strpos($itemsHtml, 'class="gav-chart"') !== false, 'item-only daily report remains supported');
}
finally { restore_error_handler(); }
echo 'PASS: ' . $assertions . " availability SLA view assertions (real synthetic runner).\n";
