<?php
// Local schema, real configuration view and save actions with the synthetic Module API only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/actions.php';

use Modules\Governance\AvailabilityConfig as Config;

$observedAssertions = 0;
function observedConfigCheck(bool $ok, string $label): void {
    global $observedAssertions;
    $observedAssertions++;
    if (!$ok) { throw new RuntimeException($label); }
}
function observedConfigReject(array $config, string $label, bool $policyError = false): void {
    try { Config::validate($config); }
    catch (InvalidArgumentException $e) {
        observedConfigCheck(!$policyError || strpos($e->getMessage(), 'Invalid data policy / Política de dados inválida.') !== false, $label);
        return;
    }
    observedConfigCheck(false, $label);
}
function observedConfigFixture(): array {
    return ['timezone' => 'America/Cuiaba', 'departments' => [['name' => 'Test department', 'target' => 99.9,
        'technologies' => [
            ['name' => 'Legacy items', 'weight' => 2, 'target' => 99.5, 'groups' => 'Team/Database',
                'mode' => 'any_down', 'max_age' => 3600, 'checks' => [
                    ['key' => 'pgsql.ping["{$PG.URI}"]', 'up' => ['op' => 'eq', 'a' => 1], 'down' => null],
                    ['key' => 'icmpping', 'max_age' => null, 'up' => ['op' => 'range', 'a' => 0.5, 'b' => 1],
                        'down' => ['op' => 'lt', 'a' => 0.5]]]],
            ['name' => 'Native SLA', 'weight' => 4, 'target' => 99.9, 'source' => 'sla',
                'slaid' => '9007199254740993', 'serviceid' => '9223372036854775807']
        ]]]];
}

class CObject {
    private $value;
    public function __construct($value) { $this->value = $value; }
    public function __toString() { return $this->value; }
}
class CForm {
    private $items = [];
    public function setId($value) { return $this; }
    public function setAction($value) { return $this; }
    public function addItem($value) { $this->items[] = $value; return $this; }
    public function __toString() { return '<form>' . implode('', $this->items) . '</form>'; }
}
class CWidget {
    private $items = [];
    public function setTitle($value) { return $this; }
    public function addItem($value) { $this->items[] = $value; return $this; }
    public function show() { echo implode('', $this->items); }
}
class ObservedConfigRenderer {
    public function addCssFile($value) {}
    public function includeJsFile($value) {}
    public function render(array $config, bool $pt = true, string $revision = 'reviewed-revision', bool $conflict = false): string {
        $data = ['config' => $config, 'is_pt' => $pt, 'is_dark' => $pt, 'page_title' => 'Test config',
            'revision' => $revision, 'conflict' => $conflict];
        $level = ob_get_level();
        ob_start();
        try { require __DIR__ . '/../views/governance.availability.config.php'; return ob_get_contents(); }
        finally { while (ob_get_level() > $level) { ob_end_clean(); } }
    }
}
function observedConfigSelect(string $html): string {
    if (!preg_match('~<select id="gav-data-policy"[^>]*>(.*?)</select>~s', $html, $match)) {
        throw new RuntimeException('Missing global policy select');
    }
    return $match[1];
}

$defaults = Config::defaults();
observedConfigCheck($defaults === ['timezone' => 'America/Cuiaba', 'data_policy' => 'strict', 'departments' => []], 'new defaults preserve strict criteria');
observedConfigCheck(Config::validate($defaults) === $defaults, 'defaults are valid and idempotent');
$legacy = observedConfigFixture(); $unchangedLegacy = $legacy;
$strict = Config::validate($legacy);
observedConfigCheck($strict['data_policy'] === 'strict', 'omitted legacy policy normalizes to strict');
observedConfigCheck($legacy === $unchangedLegacy && !array_key_exists('data_policy', $legacy), 'normalization never mutates stored legacy input');
$items = $strict['departments'][0]['technologies'][0];
observedConfigCheck($items['source'] === 'items' && $items['mode'] === 'any_down' && $items['groups'] === 'Team/Database', 'legacy source and host selection remain unchanged');
observedConfigCheck($items['max_age'] === 3600 && $items['checks'][0]['max_age'] === 3600 && $items['checks'][1]['max_age'] === null, 'manual legacy validity and explicit automatic validity remain distinct');
observedConfigCheck($items['checks'][0]['key'] === 'pgsql.ping["{$PG.URI}"]' && $items['checks'][1]['up'] === ['op' => 'range', 'a' => 0.5, 'b' => 1.0], 'keys, macros and conditions are not changed by data policy');
foreach (['strict', 'observed'] as $policy) {
    $normalized = Config::validate(array_replace($legacy, ['data_policy' => $policy]));
    observedConfigCheck($normalized === array_replace($strict, ['data_policy' => $policy]), 'policy changes only its own root field: ' . $policy);
    observedConfigCheck(Config::validate(json_decode(json_encode($normalized), true)) === $normalized, 'policy survives JSON normalization roundtrip: ' . $policy);
    $native = $normalized['departments'][0]['technologies'][1];
    observedConfigCheck($native['slaid'] === '9007199254740993' && $native['serviceid'] === '9223372036854775807', 'native IDs stay exact strings under ' . $policy);
    observedConfigCheck(!isset($native['groups'], $native['checks'], $native['data_policy']), 'native source does not acquire item fields or a local data policy');
    $onlySla = $legacy; $onlySla['data_policy'] = $policy;
    $onlySla['departments'][0]['technologies'] = [$legacy['departments'][0]['technologies'][1]];
    observedConfigCheck(Config::validate($onlySla)['data_policy'] === $policy, 'global preference may be retained with only native sources');
}
foreach ([null, '', true, false, 0, 1, 1.0, [], ['strict'], (object) ['value' => 'observed'],
    'STRICT', 'Observed', 'available', 'ignore', 'auto', 'strict ', ' observed', "observed\n", "strict\0"] as $invalid) {
    observedConfigReject(array_replace($legacy, ['data_policy' => $invalid]), 'invalid explicit policy rejected: ' . json_encode($invalid), true);
}
$nested = $legacy;
$nested['departments'][0]['data_policy'] = 'observed';
$nested['departments'][0]['technologies'][0]['data_policy'] = 'observed';
observedConfigCheck(Config::validate($nested) === $strict, 'nested policy cannot silently opt a legacy configuration into observation mode');
foreach (['groups' => '', 'checks' => [], 'mode' => 'ignore', 'max_age' => 0] as $field => $value) {
    $invalid = array_replace($legacy, ['data_policy' => 'observed']);
    $invalid['departments'][0]['technologies'][0][$field] = $value;
    observedConfigReject($invalid, 'observed policy keeps existing item requirement: ' . $field);
}
$invalid = array_replace($legacy, ['data_policy' => 'observed']);
$invalid['departments'][0]['technologies'][0]['checks'][0]['key'] = '';
observedConfigReject($invalid, 'observed policy does not waive required keys');
$invalid = array_replace($legacy, ['data_policy' => 'observed']);
$invalid['departments'][0]['technologies'][1]['serviceid'] = 42;
observedConfigReject($invalid, 'observed policy does not relax native source validation');
$invalid = array_replace($legacy, ['data_policy' => 'observed']);
$invalid['departments'][0]['technologies'] = [];
observedConfigReject($invalid, 'observed policy still requires a technology in each department');

$renderer = new ObservedConfigRenderer();
foreach ([null, 'strict', 'observed'] as $policy) {
    $config = $legacy;
    if ($policy !== null) { $config['data_policy'] = $policy; }
    foreach ([true, false] as $pt) {
        $html = $renderer->render($config, $pt);
        $select = observedConfigSelect($html);
        observedConfigCheck(strpos($select, 'value="' . ($policy ?? 'strict') . '" selected') !== false, 'view selects the existing policy or explicit legacy default');
        observedConfigCheck(substr_count($select, ' selected') === 1, 'view has only one selected policy');
        observedConfigCheck(strpos($select, $pt ? 'Exigir cobertura completa' : 'Require complete coverage') !== false, 'strict label is localized');
        observedConfigCheck(strpos($select, $pt ? 'Calcular sobre dados disponíveis' : 'Calculate from available data') !== false, 'observed label is localized');
        foreach ($pt ? ['períodos e hosts sem evidência', 'a cobertura permanece visível', 'verificações continuam obrigatórias em cada host',
            'Chaves ausentes ou validade não resolvida', 'O SLA nativo não é alterado']
            : ['periods and hosts without state evidence', 'coverage remains visible', 'Every check remains required on each host',
                'Missing keys or unresolved validity', 'Native SLA is not changed'] as $text) {
            observedConfigCheck(strpos($html, $text) !== false, 'policy help explains its exact limits: ' . $text);
        }
        observedConfigCheck(strpos($html, 'name="config_revision" value="reviewed-revision"') !== false, 'render preserves the reviewed revision');
    }
}
foreach ([null, 'ignore', ['observed']] as $invalidPolicy) {
    $html = $renderer->render(array_replace($legacy, ['data_policy' => $invalidPolicy]));
    $select = observedConfigSelect($html);
    observedConfigCheck(strpos($select, 'value="" selected disabled') !== false, 'invalid draft is shown as requiring an explicit policy choice');
    observedConfigCheck(strpos($select, 'value="strict" selected') === false && strpos($select, 'value="observed" selected') === false, 'invalid draft is not silently assigned valid criteria');
}

// Use the actual revision-aware actions, with in-memory API storage, to retain both policy and draft.
API::$module = new TestModule();
API::$module->config = ['availability' => $legacy, 'cards' => [['name' => 'Keep card']], 'other_setting' => 42];
$view = new ConfigHarness(); $view->run();
$initialRevision = $view->response->data['revision'];
observedConfigCheck(API::$module->writes === 0 && API::$module->config['availability'] === $legacy, 'opening legacy configuration never writes or migrates it');
observedConfigCheck($initialRevision === hash('sha256', json_encode($legacy)), 'legacy revision remains the stored raw revision, not a normalized hash');
$observedDraft = array_replace($legacy, ['data_policy' => 'observed']);
$save = new SaveHarness();
$save->input = ['availability_json' => json_encode($observedDraft), 'config_revision' => $initialRevision];
$save->run();
$savedObserved = Config::validate($observedDraft);
observedConfigCheck(API::$module->writes === 1 && API::$module->config['availability'] === $savedObserved, 'explicit observed choice persists through the real save action');
observedConfigCheck(API::$module->config['cards'] === [['name' => 'Keep card']] && API::$module->config['other_setting'] === 42, 'saving policy preserves unrelated module settings');
$reload = new ConfigHarness(); $reload->run();
$observedRevision = $reload->response->data['revision'];
observedConfigCheck($reload->response->data['config']['data_policy'] === 'observed' && $observedRevision !== $initialRevision, 'fresh view retains observed choice and a new reviewed revision');
$strictDraft = array_replace($legacy, ['data_policy' => 'strict']);
$conflicting = new SaveHarness();
$conflicting->input = ['availability_json' => json_encode($strictDraft), 'config_revision' => $initialRevision];
$conflicting->run();
observedConfigCheck(API::$module->writes === 1 && API::$module->config['availability'] === $savedObserved, 'stale strict choice cannot overwrite a saved observed policy');
observedConfigCheck($conflicting->response->data === $conflicting->input, 'conflict keeps the exact policy draft and reviewed revision');
$conflictView = new ConfigHarness(); $conflictView->input = $conflicting->response->data; $conflictView->run();
observedConfigCheck($conflictView->response->data['conflict'] && $conflictView->response->data['config'] === $strictDraft, 'conflict view keeps the strict draft instead of replacing it with stored observed choice');
$conflictHtml = $renderer->render($conflictView->response->data['config'], true,
    $conflictView->response->data['revision'], $conflictView->response->data['conflict']);
observedConfigCheck(strpos(observedConfigSelect($conflictHtml), 'value="strict" selected') !== false && strpos($conflictHtml, 'Outra sessão alterou as regras.') !== false, 'conflict render identifies unsaved policy and existing recovery warning');
observedConfigCheck(strpos($conflictHtml, 'name="config_revision" value="' . $initialRevision . '"') !== false, 'conflict render does not grant authority to overwrite latest rules');
$conflicting->run();
observedConfigCheck(API::$module->writes === 1, 'repeated stale policy submit remains blocked');
$invalidDraft = array_replace($legacy, ['data_policy' => null]);
$save->input = ['availability_json' => json_encode($invalidDraft), 'config_revision' => $observedRevision];
$save->run();
observedConfigCheck(API::$module->writes === 1 && $save->response->data === $save->input, 'invalid policy save preserves all draft fields without a write');
observedConfigCheck(strpos(CMessageHelper::$error, 'Invalid data policy') !== false, 'invalid save exposes a clear data-policy error');
$invalidView = new ConfigHarness(); $invalidView->input = $save->response->data; $invalidView->run();
observedConfigCheck($invalidView->response->data['config'] === $invalidDraft && !$invalidView->response->data['conflict'], 'invalid draft can be repaired without inventing a revision conflict');
API::$module->fail = true;
$save->input = ['availability_json' => json_encode($strictDraft), 'config_revision' => $observedRevision];
$save->run();
observedConfigCheck(API::$module->writes === 1 && $save->response->data === $save->input, 'API failure retains the explicit policy draft and revision');
API::$module->fail = false;
$save->run();
observedConfigCheck(API::$module->writes === 2 && API::$module->config['availability'] === $strict, 'explicit return to strict criteria succeeds with the current reviewed revision');

echo 'PASS: ' . $observedAssertions . " observed data-policy configuration assertions (local schema/view/actions).\n";
