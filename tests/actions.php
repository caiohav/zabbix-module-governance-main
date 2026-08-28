<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../AvailabilityConfig.php';
require __DIR__ . '/../GovernanceConfig.php';
define('USER_TYPE_SUPER_ADMIN', 3);
class CController {
    public $input = [], $response, $type = 3;
    protected function getUserType() { return $this->type; }
    // Match Zabbix 6: null is not a fallback for an absent input.
    protected function getInput($key, $default = null) {
        return $default === null ? $this->input[$key]
            : (array_key_exists($key, $this->input) ? $this->input[$key] : $default);
    }
    protected function hasInput($key) { return array_key_exists($key, $this->input); }
    protected function setResponse($value) { $this->response = $value; }
    protected function validateInput($rules) { return true; }
}
class CControllerResponseFatal {}
class CControllerResponseData { public $data; public function __construct($data) { $this->data = $data; } }
class CControllerResponseRedirect { public $data; public function __construct($url) {} public function setFormData($d) { $this->data = $d; } }
class CUrl { public function __construct($url) {} public function setArgument($a, $b) { return $this; } }
class CWebUser { public static $data = ['theme' => 'blue-theme']; public static function getLang() { return 'pt_BR'; } }
function getUserTheme($data) { return $data['theme'] ?? 'blue-theme'; }
class CMessageHelper {
    public static $error = '', $success = '';
    public static function setErrorTitle($v) { self::$error = $v; }
    public static function addError($v) { self::$error .= $v; }
    public static function setSuccessTitle($v) { self::$success = $v; }
}
class API { public static $module; public static function Module() { return self::$module; } }
class TestModule {
    public $config, $writes = 0, $fail = false;
    public function get($options) { return [['moduleid' => '1', 'config' => $this->config]]; }
    public function update($values) { if ($this->fail) { return false; } $this->writes++; $this->config = $values[0]['config']; return ['moduleids' => ['1']]; }
}
require __DIR__ . '/../actions/AvailabilitySave.php';
require __DIR__ . '/../actions/AvailabilityConfigView.php';
require __DIR__ . '/../actions/QualityConfigUpdate.php';
class SaveHarness extends Modules\Governance\Actions\AvailabilitySave { public function run() { if ($this->checkPermissions()) { $this->doAction(); } } }
class ConfigHarness extends Modules\Governance\Actions\AvailabilityConfigView { public function run() { if ($this->checkPermissions()) { $this->doAction(); } } }
class QualityHarness extends Modules\Governance\Actions\QualityConfigUpdate { public function run() { if ($this->checkPermissions()) { $this->doAction(); } } }
$count = 0;
set_error_handler(static function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING | E_NOTICE);
function assertAction($value, $message) { global $count; $count++; if (!$value) { throw new RuntimeException($message); } }
API::$module = new TestModule();
API::$module->config = ['cards' => [['name' => 'Existing card']], 'other_setting' => 42];
$defaults = Modules\Governance\AvailabilityConfig::defaults();
$save = new SaveHarness();
$save->input = ['availability_json' => json_encode($defaults), 'config_revision' => hash('sha256', json_encode($defaults))];
$save->run();
assertAction(API::$module->writes === 1, 'valid save');
assertAction(API::$module->config['cards'][0]['name'] === 'Existing card', 'availability preserves cards');
assertAction(API::$module->config['other_setting'] === 42, 'availability preserves unrelated config');
assertAction(API::$module->config['availability'] === $defaults, 'availability persisted');
$save->input['config_revision'] = 'stale'; $save->run();
assertAction(API::$module->writes === 1, 'stale save is rejected');
assertAction($save->response->data['availability_json'] === json_encode($defaults), 'failed draft returned');
$save->input['config_revision'] = hash('sha256', json_encode($defaults));
$save->input['availability_json'] = '{}'; $save->run();
assertAction(API::$module->writes === 1, 'invalid payload cannot overwrite config');
$save->input['availability_json'] = json_encode($defaults); $save->type = 1; $save->run();
assertAction(API::$module->writes === 1, 'non-admin rejected');
$save->type = 3; API::$module->fail = true; $save->run();
assertAction(API::$module->writes === 1, 'API failure does not write');
assertAction(strpos(CMessageHelper::$error, 'Could not save') !== false, 'API failure is surfaced');
API::$module->fail = false;
$quality = new QualityHarness(); $quality->input = ['cards' => []]; $quality->run();
assertAction(API::$module->writes === 2, 'quality save works');
assertAction(API::$module->config['availability'] === $defaults, 'quality save preserves availability');
assertAction(API::$module->config['other_setting'] === 42, 'quality save preserves other settings');

// A failed save must retain the revision that was actually reviewed. Replacing it
// with the current stored revision would silently authorize a stale draft on retry.
API::$module = new TestModule();
API::$module->config = ['cards' => [['name' => 'Existing card']], 'availability' => $defaults, 'other_setting' => 42];
$tabA = new ConfigHarness(); $tabA->run();
$tabB = new ConfigHarness(); $tabB->run();
$initialRevision = $tabA->response->data['revision'];
assertAction($tabB->response->data['revision'] === $initialRevision, 'both tabs start from the same revision');
$draftA = array_replace($defaults, ['timezone' => 'UTC']);
$draftB = array_replace($defaults, ['timezone' => 'Europe/Lisbon']);
$saveA = new SaveHarness();
$saveA->input = ['availability_json' => json_encode($draftA), 'config_revision' => $initialRevision];
$saveA->run();
assertAction(API::$module->writes === 1 && API::$module->config['availability'] === $draftA, 'first tab saves its own revision');
$saveB = new SaveHarness();
$saveB->input = ['availability_json' => json_encode($draftB), 'config_revision' => $initialRevision];
$saveB->run();
assertAction(API::$module->writes === 1, 'second tab cannot overwrite the first tab');
assertAction($saveB->response->data['availability_json'] === json_encode($draftB), 'conflicting draft is preserved for recovery');
assertAction(($saveB->response->data['config_revision'] ?? null) === $initialRevision, 'conflict redirect preserves the reviewed revision');
$conflictView = new ConfigHarness();
$conflictView->input = $saveB->response->data;
$conflictView->run();
assertAction($conflictView->response->data['config'] === $draftB, 'conflict view retains unsaved changes');
assertAction($conflictView->response->data['revision'] === $initialRevision, 'conflict view does not promote a stale draft to the latest revision');
$retry = new SaveHarness();
$retry->input = ['availability_json' => json_encode($conflictView->response->data['config']),
    'config_revision' => $conflictView->response->data['revision']];
$retry->run();
assertAction(API::$module->writes === 1, 'clicking save again after a conflict remains blocked');
assertAction(API::$module->config['availability'] === $draftA, 'first tab data survives repeated stale submissions');
$reload = new ConfigHarness(); $reload->run();
assertAction($reload->response->data['config'] === $draftA, 'fresh navigation loads the actual stored configuration');
assertAction($reload->response->data['revision'] === hash('sha256', json_encode($draftA)), 'fresh navigation obtains the current revision');
assertAction($reload->response->data['revision'] !== $initialRevision, 'fresh revision differs after a rules change');
// Changes to unrelated module features must not be lost while availability rules
// are being edited, and must not cause false availability conflicts.
API::$module->config['cards'][0]['name'] = 'Concurrent card change';
$reviewed = new SaveHarness();
$reviewed->input = ['availability_json' => json_encode($draftB), 'config_revision' => $reload->response->data['revision']];
$reviewed->run();
assertAction(API::$module->writes === 2 && API::$module->config['availability'] === $draftB, 'reviewed current revision can be saved');
assertAction(API::$module->config['cards'][0]['name'] === 'Concurrent card change', 'availability save preserves newly updated quality cards');
assertAction(API::$module->config['other_setting'] === 42, 'concurrent availability save preserves unrelated settings');

// All features share the native module.config column. A valid availability
// document may still exceed that limit once merged with saved quality pages.
API::$module = new TestModule();
API::$module->config = ['availability' => $defaults,
    'quality_pages' => [['id' => 'main', 'name' => '', 'cards' => Modules\Governance\GovernanceConfig::getDefaultCards()]],
    'other_setting' => 42, 'padding' => ''];
API::$module->config['padding'] = str_repeat('x', 65535 - 512 - strlen(json_encode(API::$module->config)));
$nearCapacityConfig = API::$module->config;
assertAction(strlen(json_encode($nearCapacityConfig)) === 65535 - 512, 'shared-storage fixture fits the existing native column');
$largerAvailability = $defaults;
$largerAvailability['departments'] = [['name' => 'Database', 'target' => 99.9, 'technologies' => [[
    'name' => 'PostgreSQL', 'weight' => 1, 'target' => 99.9, 'mode' => 'any_down', 'groups' => 'Equipes',
    'checks' => [['key' => str_repeat('k', 2048), 'max_age' => null, 'up' => ['op' => 'eq', 'a' => 1], 'down' => null]]
]]]];
$oversizeSave = new SaveHarness();
$oversizeSave->input = ['availability_json' => json_encode($largerAvailability), 'config_revision' => hash('sha256', json_encode($defaults))];
$oversizeSave->run();
assertAction(API::$module->writes === 0 && API::$module->config === $nearCapacityConfig, 'oversized merged availability cannot truncate stored quality or issue a write');
assertAction(strpos(CMessageHelper::$error, 'share this limit') !== false, 'availability storage error explains shared capacity');
assertAction($oversizeSave->response->data['availability_json'] === json_encode($largerAvailability), 'storage failure preserves the availability draft');
assertAction($oversizeSave->response->data['config_revision'] === hash('sha256', json_encode($defaults)), 'storage failure preserves the availability revision');
echo 'PASS: ' . $count . " action assertions\n";
