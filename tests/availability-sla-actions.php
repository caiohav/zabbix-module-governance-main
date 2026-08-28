<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Reuse the native-controller input semantics and fake Module API; no external access.
require __DIR__ . '/actions.php';
require __DIR__ . '/../AvailabilityJobStore.php';
require __DIR__ . '/../actions/AvailabilityView.php';
class AvailabilityViewHarness extends Modules\Governance\Actions\AvailabilityView {
    public function run(): void { if ($this->checkPermissions()) { $this->doAction(); } }
}
$count = 0;
$item = ['name' => 'Item legacy', 'weight' => 1, 'target' => 99.9, 'mode' => 'any_down', 'groups' => 'Test',
    'checks' => [['key' => 'icmpping', 'max_age' => null, 'up' => ['op' => 'eq', 'a' => 1], 'down' => null]]];
$sla = ['name' => 'Native', 'weight' => 1, 'target' => 99.9, 'source' => 'sla', 'slaid' => '1', 'serviceid' => '11'];
$config = ['timezone' => 'UTC', 'departments' => [
    ['name' => 'Items', 'target' => 99.9, 'technologies' => [$item]],
    ['name' => 'SLA', 'target' => 99.9, 'technologies' => [$sla]]]];
API::$module = new TestModule();
API::$module->config = ['availability' => $config];
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$currentMonth = $now->format('Y-m');
$closedMonth = $now->modify('first day of previous month')->format('Y-m');
foreach ([[-1, $closedMonth], [0, $currentMonth], [1, $closedMonth]] as $case) {
    $view = new AvailabilityViewHarness();
    $view->input = ['department' => $case[0]];
    $view->run();
    assertAction($view->response->data['error'] === null, 'Valid selection loads without error');
    assertAction($view->response->data['month'] === $case[1], 'Default month follows only selected sources');
    assertAction($view->response->data['report'] === null, 'Opening selection does not calculate');
}
$view = new AvailabilityViewHarness();
$view->input = ['department' => 1, 'month' => '2026-07'];
$view->run();
assertAction($view->response->data['month'] === '2026-07', 'Explicit native month is never replaced');
$view->input = ['department' => 0, 'month' => '2024-02']; $view->run();
assertAction($view->response->data['month'] === '2024-02', 'Explicit item month is never replaced');
API::$module->config['availability']['departments'] = [$config['departments'][0]];
$view->input = []; $view->run();
assertAction($view->response->data['month'] === $currentMonth, 'Pure legacy configuration keeps current-month default');
assertAction(API::$module->writes === 0, 'Loading source-aware defaults never writes configuration');
$view->type = 1; $view->response = null; $view->run();
assertAction($view->response === null, 'Source-aware view remains restricted to Super Admin');
echo 'PASS: ' . $count . " source-aware view action assertions.\n";
