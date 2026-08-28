<?php
// Render failure states with PHP warnings promoted to errors (no live Zabbix required).
require dirname(__DIR__) . '/AvailabilityConfig.php';
require dirname(__DIR__) . '/AvailabilityJobStore.php';
use Modules\Governance\AvailabilityConfig as Config;
use Modules\Governance\AvailabilityJobStore as Store;

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
    public function __toString() { return '<form><input name="sid" value="fake-test-only"></form>'; }
}
class AvailabilityViewRenderer {
    public function addCssFile($value) {}
    public function includeJsFile($value) {}
    public function render(array $data): string {
        ob_start();
        try { require dirname(__DIR__) . '/views/governance.availability.view.php'; return ob_get_contents(); }
        finally { ob_end_clean(); }
    }
}
$assertions = 0;
function renderCheck(bool $ok, string $message): void {
    global $assertions;
    $assertions++;
    if (!$ok) { throw new RuntimeException($message); }
}
$directory = sys_get_temp_dir() . '/governance-view-' . bin2hex(random_bytes(12));
set_error_handler(static function($severity, $message, $file, $line) {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
try {
    $data = ['is_pt' => true, 'is_dark' => true, 'page_title' => 'Test', 'config' => Config::defaults(),
        'report' => null, 'job' => null, 'error' => null, 'month' => '2026-05', 'department' => -1];
    $renderer = new AvailabilityViewRenderer();
    $html = $renderer->render($data);
    renderCheck(strpos($html, 'Seu primeiro indicador') !== false, 'empty fresh configuration has its own CTA');
    $store = new Store($directory);
    $job = $store->create('1', hash('sha256', 'failed-start'), static function() {
        throw new RuntimeException('Pretend invalid month or unavailable rules.');
    });
    $data['job'] = Store::projection($job);
    $data['error'] = $data['job']['error'];
    renderCheck($data['job']['snapshot']['timezone'] === '', 'actual failed initializer has no confirmed timezone');
    $html = $renderer->render($data);
    renderCheck(strpos($html, 'Confira o mês') !== false, 'failed start renders error without an HTTP500');
    renderCheck(strpos($html, 'Seu primeiro indicador') === false, 'unconfirmed rules do not imply no configuration');
    renderCheck(strpos($html, 'id="gav-report"') === false, 'no partial report rendered for failure');
    renderCheck(strpos($html, 'id="gav-job-data"') !== false, 'failure projection available to the workflow');
    $data['is_pt'] = false; $data['is_dark'] = false;
    $html = $renderer->render($data);
    renderCheck(strpos($html, 'Cannot start calculation.') !== false, 'failed start translated to English');
    $data['job'] = ['job' => str_repeat('a', 64), 'status' => 'busy', 'sequence' => 0, 'progress' => [], 'retryable' => true];
    $data['error'] = 'Calculation busy / Cálculo ocupado';
    $html = $renderer->render($data);
    renderCheck(strpos($html, 'Calculation busy') !== false, 'busy bootstrap renders without a snapshot timezone');
    renderCheck(strpos($html, 'Your first indicator') === false, 'busy bootstrap does not show misleading setup CTA');
    $data['job']['snapshot'] = ['timezone' => '', 'month' => ''];
    $renderer->render($data);
    renderCheck(true, 'explicit empty timezone handled as unconfirmed');
    $data['job'] = null;
    $data['error'] = 'Calculation unavailable or expired / Cálculo indisponível ou expirado';
    $html = $renderer->render($data);
    renderCheck(strpos($html, 'Return to period selection') !== false, 'expired job offers a direct restart path');
    renderCheck(strpos($html, 'Your first indicator') === false, 'expired job does not imply rules were lost');
}
finally {
    restore_error_handler();
    $resolved = realpath($directory);
    if ($resolved && dirname($resolved) === realpath(sys_get_temp_dir()) && strpos(basename($resolved), 'governance-view-') === 0) {
        foreach (scandir($resolved) as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $file = $resolved . DIRECTORY_SEPARATOR . $entry;
            if (is_file($file) || is_link($file)) { unlink($file); }
        }
        rmdir($resolved);
    }
}
echo 'PASS: ' . $assertions . " availability PHP view assertions.\n";
