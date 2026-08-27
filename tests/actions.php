<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../AvailabilityConfig.php';
require __DIR__ . '/../GovernanceConfig.php';
define('USER_TYPE_SUPER_ADMIN', 3);
class CController {
    public $input = [], $response, $type = 3;
    protected function getUserType() { return $this->type; }
    protected function getInput($key, $default = null) { return $this->input[$key] ?? $default; }
    protected function setResponse($value) { $this->response = $value; }
    protected function validateInput($rules) { return true; }
}
class CControllerResponseFatal {}
class CControllerResponseRedirect { public $data; public function __construct($url) {} public function setFormData($d) { $this->data = $d; } }
class CUrl { public function __construct($url) {} public function setArgument($a, $b) { return $this; } }
class CWebUser { public static function getLang() { return 'pt_BR'; } }
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
require __DIR__ . '/../actions/QualityConfigUpdate.php';
class SaveHarness extends Modules\Governance\Actions\AvailabilitySave { public function run() { if ($this->checkPermissions()) { $this->doAction(); } } }
class QualityHarness extends Modules\Governance\Actions\QualityConfigUpdate { public function run() { if ($this->checkPermissions()) { $this->doAction(); } } }
$count = 0;
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
echo 'PASS: ' . $count . " action assertions\n";
