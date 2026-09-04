<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../GovernanceConfig.php';

use Modules\Governance\GovernanceConfig;

$assertions = 0;
function qualityAssert($condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
}
function qualityReject($input, string $message): void {
    try {
        GovernanceConfig::validateQualityPages($input);
    }
    catch (InvalidArgumentException $e) {
        qualityAssert(true, $message);
        return;
    }
    qualityAssert(false, $message);
}
function qualityCard(string $id = 'department', string $type = 'tag'): array {
    return ['id' => $id, 'type' => $type, 'title' => 'Departamento', 'description' => 'Teste de qualidade',
        'tag_names' => 'department,departamento', 'tag_values' => '', 'group_names' => 'Equipes', 'include_score' => 1];
}
function qualityPage(array $cards = [], string $id = 'main', string $name = ''): array {
    return ['id' => $id, 'name' => $name, 'cards' => $cards];
}

// Migration preserves the legacy view, including its implicit default cards.
$legacy = ['cards' => [qualityCard()], 'availability' => ['timezone' => 'UTC'], 'other' => 42];
$migrated = GovernanceConfig::getQualityPages($legacy);
qualityAssert($migrated === [qualityPage([qualityCard()])], 'legacy card migrates into main');
qualityAssert(!array_key_exists('quality_pages', $legacy), 'reading does not mutate stored configuration');
qualityAssert(count(GovernanceConfig::getQualityPages([])[0]['cards']) === 6, 'unconfigured installation retains defaults');
qualityAssert(count(GovernanceConfig::getQualityPages(['cards' => []])[0]['cards']) === 6, 'legacy implicit defaults remain visible');
qualityAssert(GovernanceConfig::getQualityPages(['quality_pages' => [], 'cards' => [qualityCard()]]) === [], 'explicit empty page list does not restore legacy cards');
qualityAssert(GovernanceConfig::getQualityPages(['quality_pages' => [qualityPage()], 'cards' => [qualityCard()]]) === [qualityPage()], 'empty page stays empty');
$legacyDuplicates = [qualityCard('duplicate'), qualityCard('duplicate_1'), qualityCard('duplicate'), qualityCard(str_repeat('a', 90)), qualityCard('Keep_This-ID')];
$normalizedLegacy = GovernanceConfig::getQualityPages(['cards' => $legacyDuplicates]);
qualityAssert(count($normalizedLegacy[0]['cards']) === 5, 'legacy normalization preserves all valid cards');
qualityAssert(count(array_unique(array_column($normalizedLegacy[0]['cards'], 'id'))) === 5, 'legacy card IDs remain distinct');
qualityAssert(GovernanceConfig::validateQualityPages($normalizedLegacy) === $normalizedLegacy, 'legacy IDs can be persisted in the new schema');
qualityAssert($normalizedLegacy[0]['cards'][4] === $legacyDuplicates[4], 'valid legacy IDs and card fields remain unchanged');
qualityAssert($normalizedLegacy === GovernanceConfig::getQualityPages(['cards' => $legacyDuplicates]), 'repaired legacy identifiers are deterministic');
foreach ($legacyDuplicates as $index => $legacyCard) {
    unset($legacyCard['id']);
    $normalizedCard = $normalizedLegacy[0]['cards'][$index]; unset($normalizedCard['id']);
    qualityAssert($legacyCard === $normalizedCard, 'legacy ID repair does not lose metric names or fields');
}
qualityAssert(count(GovernanceConfig::getQualityPages(['cards' => array_fill(0, 31, qualityCard())])[0]['cards']) === 31, 'legacy excess cards remain visible instead of being silently truncated');

$pages = [qualityPage([qualityCard()]), qualityPage([qualityCard('groups', 'hostgroups')], 'groups', 'Equipes'),
    qualityPage([], 'empty', 'Sem cards')];
qualityAssert(GovernanceConfig::validateQualityPages($pages) === $pages, 'valid pages round trip');
qualityAssert(GovernanceConfig::validateQualityPages([]) === [], 'empty page list is valid');
qualityAssert(GovernanceConfig::validateQualityPages([qualityPage([], 'main', 'Renomeada')])[0]['name'] === 'Renomeada', 'main page may be renamed');
$trimmed = [qualityPage([array_replace(qualityCard(), ['title' => '  Métrica  ', 'include_score' => false])], 'custom_1', '  São Paulo  ')];
$trimmedResult = GovernanceConfig::validateQualityPages($trimmed);
qualityAssert($trimmedResult[0]['name'] === 'São Paulo' && $trimmedResult[0]['cards'][0]['title'] === 'Métrica', 'text is trimmed with Unicode preserved');
qualityAssert($trimmedResult[0]['cards'][0]['include_score'] === 0, 'boolean score selection normalizes');
qualityAssert(count(GovernanceConfig::validateQualityPages([qualityPage([qualityCard()]), qualityPage([qualityCard()], 'second', 'Segunda')])) === 2, 'card IDs are scoped per page');
$limitPages = [];
for ($p = 0; $p < 12; $p++) {
    $limitCards = [];
    for ($c = 0; $c < 30; $c++) { $limitCards[] = qualityCard('card_' . $c); }
    $limitPages[] = qualityPage($limitCards, 'page_' . $p, 'Página ' . $p);
}
qualityAssert(GovernanceConfig::validateQualityPages($limitPages) === $limitPages, '12 pages with 30 cards each are supported');

foreach ([null, false, 'pages', ['named' => qualityPage()], ['not-a-page']] as $invalid) {
    qualityReject($invalid, 'invalid page list is rejected');
}
qualityReject(array_merge($limitPages, [qualityPage([], 'extra', 'Extra')]), 'more than 12 pages is rejected without truncation');
foreach (['', 'with space', '../path', str_repeat('x', 65), null, []] as $id) {
    qualityReject([array_replace(qualityPage(), ['id' => $id])], 'invalid page ID is rejected');
}
qualityReject([qualityPage(), qualityPage()], 'duplicate page IDs are rejected');
foreach (['', '  ', str_repeat('a', 101), "line\nbreak", "nul\0byte", [], null] as $name) {
    qualityReject([array_replace(qualityPage([], 'named', 'valid'), ['name' => $name])], 'invalid page name is rejected');
}
foreach ([null, ['named' => qualityCard()], array_fill(0, 31, qualityCard())] as $cards) {
    qualityReject([array_replace(qualityPage(), ['cards' => $cards])], 'invalid card list is rejected');
}
qualityReject([qualityPage(['not-a-card'])], 'non-object card is rejected');
qualityReject([qualityPage([qualityCard(), qualityCard()])], 'duplicate card IDs in one page are rejected');
foreach ([['id' => ''], ['id' => 'x.y'], ['id' => []], ['type' => 'unsupported'], ['type' => []], ['title' => ''],
        ['title' => str_repeat('x', 101)], ['title' => []], ['description' => str_repeat('x', 256)],
        ['tag_names' => ' , '], ['tag_names' => []], ['tag_values' => []], ['include_score' => 2],
        ['include_score' => []], ['group_names' => str_repeat('x', 256)]] as $override) {
    qualityReject([qualityPage([array_replace(qualityCard(), $override)])], 'invalid card field is rejected');
}
qualityReject([qualityPage([array_replace(qualityCard('group', 'hostgroups'), ['group_names' => ', '])])], 'host group metric requires an actual group');
qualityAssert(GovernanceConfig::validateQualityPages([qualityPage([array_replace(qualityCard(), ['description' => "First line\nSecond line"])])])[0]['cards'][0]['description'] === "First line\nSecond line", 'description allows multiple lines');

$revision = GovernanceConfig::qualityRevision($legacy);
qualityAssert($revision === GovernanceConfig::qualityRevision(array_replace($legacy, ['availability' => ['timezone' => 'Europe/Lisbon']])), 'availability change does not conflict with quality');
qualityAssert($revision === GovernanceConfig::qualityRevision(['quality_pages' => $migrated]), 'migration alone preserves the reviewed revision');
qualityAssert($revision !== GovernanceConfig::qualityRevision(['quality_pages' => [qualityPage([qualityCard()], 'main', 'Renomeada')]]), 'renaming a page changes its revision');
qualityAssert($revision === GovernanceConfig::qualityRevision(['quality_pages' => $migrated, 'cards' => []]), 'unused legacy backup does not cause false conflicts');

// Native storage limits apply to the complete JSON document, not only the new pages.
class DB {
    public static $limit = 65535;
    public static function getFieldLength($table, $field) {
        if ($table !== 'module' || $field !== 'config') { throw new RuntimeException('Unexpected storage field'); }
        return self::$limit;
    }
}
function qualitySizeReject(array $config, string $message): void {
    try {
        GovernanceConfig::assertModuleConfigSize($config);
    }
    catch (InvalidArgumentException $e) {
        qualityAssert(strpos($e->getMessage(), 'exceeds the Zabbix storage limit') !== false, $message);
        return;
    }
    qualityAssert(false, $message);
}
$exactSize = ['padding' => str_repeat('a', DB::$limit - strlen(json_encode(['padding' => ''])))];
GovernanceConfig::assertModuleConfigSize($exactSize);
qualityAssert(strlen(json_encode($exactSize)) === DB::$limit, 'configuration at the native byte limit is accepted');
$exactSize['padding'] .= 'a';
qualitySizeReject($exactSize, 'one byte beyond the native storage limit is rejected');
$unicodeSize = ['padding' => str_repeat('á', 12000)];
qualityAssert(strlen(json_encode($unicodeSize, JSON_UNESCAPED_UNICODE)) < DB::$limit, 'Unicode fixture is small before native JSON escaping');
qualitySizeReject($unicodeSize, 'size check matches native JSON Unicode escaping');
DB::$limit = 2048;
qualitySizeReject(['quality_pages' => $pages, 'availability' => ['padding' => str_repeat('x', 2048)]], 'database-specific smaller field length is respected');
DB::$limit = 65535;
try {
    GovernanceConfig::assertModuleConfigSize(['invalid_number' => INF]);
    qualityAssert(false, 'non-encodable configuration cannot be saved');
}
catch (InvalidArgumentException $e) {
    qualityAssert(strpos($e->getMessage(), 'cannot be encoded') !== false, 'non-encodable configuration returns a clear error');
}

// Controller fixtures exercise request/response behavior without a live Zabbix.
define('USER_TYPE_SUPER_ADMIN', 3);
define('HOST_STATUS_MONITORED', 0);
define('HOST_STATUS_NOT_MONITORED', 1);
define('HOST_MAINTENANCE_STATUS_OFF', 0);
define('HOST_MAINTENANCE_STATUS_ON', 1);
define('INTERFACE_AVAILABLE_UNKNOWN', 0);
define('INTERFACE_AVAILABLE_TRUE', 1);
define('INTERFACE_AVAILABLE_FALSE', 2);
define('TRIGGER_SEVERITY_HIGH', 4);
define('TRIGGER_SEVERITY_DISASTER', 5);
define('ITEM_STATE_NOTSUPPORTED', 1);
class CController {
    public $input = [], $response, $type = 3, $sidValidation = true;
    protected function init(): void {}
    protected function getUserType() { return $this->type; }
    // Match Zabbix 6: null is not a fallback for an absent input.
    protected function getInput($key, $default = null) {
        return $default === null ? $this->input[$key]
            : (array_key_exists($key, $this->input) ? $this->input[$key] : $default);
    }
    protected function hasInput($key) { return array_key_exists($key, $this->input); }
    protected function setResponse($value) { $this->response = $value; }
    protected function validateInput($rules) { return true; }
    protected function disableSIDvalidation() { $this->sidValidation = false; }
}
class CControllerResponseFatal {}
class CControllerResponseData { public $title; public function getData() { return $this->data; } public function setTitle($title) { $this->title = $title; } public $data; public function __construct($data) { $this->data = $data; } }
class CControllerResponseRedirect {
    public $data = [], $url;
    public function __construct($url) { $this->url = $url; }
    public function setFormData($data) { $this->data = $data; }
}
class CUrl {
    public $args = [];
    public function __construct($url) {}
    public function setArgument($name, $value) { $this->args[$name] = $value; return $this; }
}
class CWebUser {
    public static $data = ['theme' => 'default'], $lang = 'pt_BR';
    public static function getLang() { return self::$lang; }
}
function getUserTheme($data) { return $data['theme'] === 'default' ? 'dark-theme' : $data['theme']; }
class CMessageHelper {
    public static $error = '', $success = '';
    public static function setErrorTitle($value) { self::$error = $value; }
    public static function addError($value) { self::$error .= $value; }
    public static function setSuccessTitle($value) { self::$success = $value; }
}
class API {
    public static $module, $host, $problem, $item;
    public static function Module() { return self::$module; }
    public static function Host() { return self::$host; }
    public static function Problem() { return self::$problem; }
    public static function Item() { return self::$item; }
}
class QualityTestModule {
    public $config = [], $writes = 0, $reads = 0, $fail = false, $exists = true;
    public function get($options) { $this->reads++; return $this->exists ? [['moduleid' => '1', 'config' => $this->config]] : []; }
    public function update($values) {
        if ($this->fail) { return false; }
        $this->writes++;
        $this->config = $values[0]['config'];
        return ['moduleids' => ['1']];
    }
}
class QualityTestHost {
    public $hosts = [], $calls = [], $disabled = 1;
    public function get($options) { $this->calls[] = $options; return !empty($options['countOutput']) ? $this->disabled : $this->hosts; }
}
class QualityTestCount {
    public $calls = [], $count = 2;
    public function get($options) { $this->calls[] = $options; return $this->count; }
}
require __DIR__ . '/../actions/QualityConfig.php';
require __DIR__ . '/../actions/QualityConfigUpdate.php';
require __DIR__ . '/../actions/QualityView.php';
class QualityConfigHarness extends Modules\Governance\Actions\QualityConfig {
    public function run() { $this->init(); if ($this->checkInput() && $this->checkPermissions()) { $this->doAction(); } }
}
class QualitySaveHarness extends Modules\Governance\Actions\QualityConfigUpdate {
    public function run() { $this->init(); if ($this->checkInput() && $this->checkPermissions()) { $this->doAction(); } }
}
class QualityViewHarness extends Modules\Governance\Actions\QualityView {
    public function run() { $this->init(); if ($this->checkInput() && $this->checkPermissions()) { $this->doAction(); } }
}
API::$module = new QualityTestModule();
API::$module->config = $legacy;
API::$host = new QualityTestHost();
API::$problem = new QualityTestCount();
API::$item = new QualityTestCount();
API::$host->hosts = [
    '1' => ['hostid' => '1', 'name' => 'Host A', 'maintenance_status' => 0,
        'interfaces' => [['available' => 1, 'useip' => 1, 'ip' => '127.0.0.1']],
        'tags' => [['tag' => 'department', 'value' => 'Database']],
        'groups' => [['groupid' => '10', 'name' => 'Equipes/Banco de Dados']]],
    '2' => ['hostid' => '2', 'name' => 'Host B', 'maintenance_status' => 1,
        'interfaces' => [['available' => 2, 'useip' => 1, 'ip' => '127.0.0.2']],
        'tags' => [['tag' => 'department', 'value' => '']],
        'groups' => [['groupid' => '11', 'name' => 'Equipes/Conectividade']]]
];

// Native notices must fail these tests instead of being hidden by a permissive mock.
set_error_handler(static function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING | E_NOTICE);
$editor = new QualityConfigHarness(); $editor->run();
qualityAssert($editor->response->title === $editor->response->data['page_title'], 'editor sets browser title through native response');
qualityAssert($editor->response->data['saved_page_ids'] === array_column($migrated, 'id'), 'editor identifies only saved pages for return navigation');
qualityAssert($editor->response->data['pages'] === $migrated && $editor->response->data['selected_page'] === 'main', 'editor loads the migrated main page');
qualityAssert($editor->response->data['revision'] === $revision && !$editor->response->data['conflict'], 'editor starts with the reviewed revision');
qualityAssert($editor->response->data['is_dark'] === true, 'editor respects the inherited default dark theme');
qualityAssert(API::$module->writes === 0, 'opening editor does not write a migration');
qualityAssert($editor->response->data['draft_json'] === null, 'GET without a submitted draft returns null without reading a missing input');
$revisionOnly = new QualityConfigHarness(); $revisionOnly->input = ['quality_revision' => $revision]; $revisionOnly->run();
qualityAssert($revisionOnly->response->data['draft_json'] === null && !$revisionOnly->response->data['conflict'], 'optional revision does not require a draft');
$pageOnly = new QualityConfigHarness(); $pageOnly->input = ['page' => 'main']; $pageOnly->run();
qualityAssert($pageOnly->response->data['selected_page'] === 'main' && $pageOnly->response->data['draft_json'] === null, 'opening a page without draft or revision is safe');
$draftView = new QualityConfigHarness();
$draftView->input = ['quality_json' => json_encode($pages), 'quality_revision' => $revision, 'page' => 'groups']; $draftView->run();
qualityAssert($draftView->response->data['draft_json'] === json_encode($pages) && $draftView->response->data['pages'] === $pages, 'submitted draft is preserved verbatim');
qualityAssert($draftView->response->data['saved_page_ids'] === array_column($migrated, 'id'), 'returned draft cannot claim unsaved pages already exist');
qualityAssert($draftView->response->data['selected_page'] === 'groups', 'draft retains selected page');
$draftView->input = ['quality_json' => json_encode($pages)]; $draftView->run();
qualityAssert($draftView->response->data['conflict'] && $draftView->response->data['revision'] === '', 'draft without revision remains blocked without an undefined input');
qualityAssert(API::$module->writes === 0 && API::$module->config === $legacy, 'all editor openings preserve stored cards and settings');

$save = new QualitySaveHarness();
$save->input = ['quality_json' => json_encode($pages), 'quality_revision' => $revision, 'page' => 'groups'];
API::$module->config['availability']['timezone'] = 'Europe/Lisbon';
$save->run();
qualityAssert($save->sidValidation === true, 'save does not disable native SID validation');
qualityAssert(API::$module->writes === 1 && API::$module->config['quality_pages'] === $pages, 'new page editor persists all pages');
qualityAssert(API::$module->config['availability'] === ['timezone' => 'Europe/Lisbon'], 'quality save merges the latest availability configuration');
qualityAssert(API::$module->config['other'] === 42 && !array_key_exists('cards', API::$module->config), 'quality save preserves unrelated settings without duplicating legacy storage');
qualityAssert($save->response->url->args['page'] === 'groups', 'save redirects to the selected page');

$view = new QualityViewHarness(); $view->input = ['page' => 'groups', 'groupids' => ['10']]; $view->run();
$viewData = $view->response->data;
qualityAssert($viewData['selected_page'] === 'groups' && $viewData['page_name'] === 'Equipes', 'dashboard selects the requested page');
qualityAssert(array_column($viewData['cards'], 'id') === ['groups'] && $viewData['cards_count'] === 1, 'shell contains only selected page cards');
qualityAssert(!isset($viewData['kpis'], $viewData['overall_score']), 'GET never pretends metrics have been calculated');
qualityAssert(API::$host->calls === [] && API::$problem->calls === [] && API::$item->calls === [], 'GET performs zero heavy metric API calls');
qualityAssert($viewData['groupids'] === ['10'], 'group scope preserved for asynchronous request');
qualityAssert($viewData['revision'] === GovernanceConfig::qualityRevision(API::$module->config), 'shell pins configuration revision');
qualityAssert($viewData['is_dark'] === true, 'dashboard respects inherited default dark theme');
$view->input = ['page' => 'missing']; $view->run();
qualityAssert($view->response->data['selected_page'] === 'main', 'removed or unknown page falls back to first page');
$view->input = ['page' => 'empty']; $view->run();
qualityAssert($view->response->data['cards_count'] === 0 && $view->response->data['cards'] === [], 'empty page shell stays empty');
API::$module->config['quality_pages'][0]['cards'][0]['include_score'] = 0;
$view->input = ['page' => 'main']; $view->run();
qualityAssert($view->response->data['cards'][0]['include_score'] === 0, 'shell preserves score participation');
API::$host->hosts = [];
$view->run();
qualityAssert($view->response->data['cards_count'] === 1, 'shell is independent of host availability');
qualityAssert(API::$problem->calls === [] && API::$item->calls === [], 'GET cannot accidentally query all items/problems');
API::$module->config['quality_pages'] = [];
$view->run();
qualityAssert($view->response->data['pages'] === [] && $view->response->data['selected_page'] === '', 'zero pages does not restore defaults');

// Two tabs must not overwrite each other, including after a failed-save redirect.
API::$module = new QualityTestModule(); API::$module->config = ['quality_pages' => $pages, 'availability' => ['timezone' => 'UTC']];
$tabA = new QualityConfigHarness(); $tabA->run();
$tabB = new QualityConfigHarness(); $tabB->run();
$reviewed = $tabA->response->data['revision'];
$draftA = $pages; $draftA[0]['name'] = 'Inventário';
$draftB = $pages; $draftB[0]['name'] = 'Tags';
$saveA = new QualitySaveHarness(); $saveA->input = ['quality_json' => json_encode($draftA), 'quality_revision' => $reviewed, 'page' => 'main']; $saveA->run();
$saveB = new QualitySaveHarness(); $saveB->input = ['quality_json' => json_encode($draftB), 'quality_revision' => $reviewed, 'page' => 'groups']; $saveB->run();
qualityAssert(API::$module->writes === 1 && API::$module->config['quality_pages'] === $draftA, 'second tab cannot overwrite a reviewed newer revision');
qualityAssert($saveB->response->data['quality_revision'] === $reviewed && $saveB->response->data['quality_json'] === json_encode($draftB), 'conflict preserves original revision and full draft');
$conflict = new QualityConfigHarness(); $conflict->input = $saveB->response->data; $conflict->run();
qualityAssert($conflict->response->data['pages'] === $draftB && $conflict->response->data['revision'] === $reviewed
    && $conflict->response->data['conflict'] === true && $conflict->response->data['selected_page'] === 'groups', 'conflict editor preserves draft, selected page and stale revision');
$saveB->input = ['quality_json' => json_encode($conflict->response->data['pages']), 'quality_revision' => $conflict->response->data['revision']]; $saveB->run();
qualityAssert(API::$module->writes === 1, 'repeated stale submit remains blocked');
$reload = new QualityConfigHarness(); $reload->run();
qualityAssert($reload->response->data['pages'] === $draftA && !$reload->response->data['conflict']
    && $reload->response->data['revision'] !== $reviewed, 'fresh navigation loads the actual current revision');
$saveB->input = ['quality_json' => json_encode($draftB), 'quality_revision' => $reload->response->data['revision']]; $saveB->run();
qualityAssert(API::$module->writes === 2 && API::$module->config['quality_pages'] === $draftB, 'reviewed current revision can be saved');

$writes = API::$module->writes;
$currentRevision = GovernanceConfig::qualityRevision(API::$module->config);
foreach (['[]', '[{', '{}', json_encode([qualityPage([qualityCard(), qualityCard()])]), '[' . str_repeat(' ', 3000000) . ']'] as $json) {
    $invalid = new QualitySaveHarness();
    $invalid->input = ['quality_json' => $json, 'quality_revision' => $json === '[]' ? 'stale' : $currentRevision, 'page' => 'main'];
    $invalid->run();
    qualityAssert(API::$module->writes === $writes && $invalid->response->data['quality_json'] === $json, 'invalid or stale payload is rejected without losing the draft');
}
$missingRevision = new QualitySaveHarness(); $missingRevision->input = ['quality_json' => json_encode($pages)]; $missingRevision->run();
qualityAssert(API::$module->writes === $writes && $missingRevision->response->data['quality_revision'] === '', 'missing revision cannot save or receive an implicit current revision');
$missingRevisionView = new QualityConfigHarness(); $missingRevisionView->input = $missingRevision->response->data; $missingRevisionView->run();
qualityAssert($missingRevisionView->response->data['conflict'] === true && $missingRevisionView->response->data['revision'] === '', 'unreviewed draft stays blocked after redirect');
$apiFailure = new QualitySaveHarness(); $apiFailure->input = ['quality_json' => json_encode($pages), 'quality_revision' => $currentRevision];
API::$module->fail = true; $apiFailure->run(); API::$module->fail = false;
qualityAssert(API::$module->writes === $writes && strpos(CMessageHelper::$error, 'Could not save quality pages') !== false, 'API save failure is surfaced');
qualityAssert($apiFailure->response->data['quality_revision'] === $currentRevision, 'API failure preserves reviewed revision');
$nonAdmin = new QualitySaveHarness(); $nonAdmin->type = 1; $nonAdmin->input = $apiFailure->input; $nonAdmin->run();
qualityAssert(API::$module->writes === $writes && $nonAdmin->response === null, 'non-admin cannot save');
$oldForm = new QualitySaveHarness(); $oldForm->input = ['cards' => [qualityCard()]]; $oldForm->run();
qualityAssert(API::$module->writes === $writes && API::$module->config['quality_pages'] === $draftB, 'pre-upgrade form cannot overwrite new pages');

$configBeforeOversize = API::$module->config;
API::$module->config['availability']['padding'] = str_repeat('x', 65000);
$oversizeStored = API::$module->config;
$oversize = new QualitySaveHarness(); $oversize->input = ['quality_json' => json_encode($pages), 'quality_revision' => $currentRevision]; $oversize->run();
qualityAssert(API::$module->writes === $writes && API::$module->config === $oversizeStored, 'merged configuration exceeding shared storage does not issue a write');
qualityAssert(strpos(CMessageHelper::$error, 'share this limit') !== false && strpos(CMessageHelper::$error, 'nenhum conteúdo foi truncado') !== false, 'storage failure explains the shared limit without truncation');
qualityAssert($oversize->response->data['quality_json'] === json_encode($pages)
    && $oversize->response->data['quality_revision'] === $currentRevision, 'storage failure preserves full draft and reviewed revision');
API::$module->config = $configBeforeOversize;

API::$module = new QualityTestModule(); API::$module->config = $legacy;
$oldForm = new QualitySaveHarness(); $oldForm->input = ['cards' => [4 => qualityCard()]]; $oldForm->run();
qualityAssert(API::$module->writes === 1 && API::$module->config['quality_pages'] === $migrated, 'sparse legacy POST migrates once without dropping cards');
qualityAssert(API::$module->config['availability'] === $legacy['availability'], 'legacy migration preserves availability');
qualityAssert(!array_key_exists('cards', API::$module->config), 'successful migration removes only redundant legacy cards');
$oldForm->run();
qualityAssert(API::$module->writes === 1, 'legacy save cannot run again after migration');
API::$module = new QualityTestModule(); API::$module->config = $legacy;
$invalidLegacy = new QualitySaveHarness(); $invalidLegacy->input = ['cards' => [array_replace(qualityCard(), ['title' => ''])]]; $invalidLegacy->run();
qualityAssert(API::$module->writes === 0 && json_decode($invalidLegacy->response->data['quality_json'], true)[0]['cards'][0]['title'] === '', 'invalid legacy form returns its full draft to the new editor');
qualityAssert($invalidLegacy->response->data['quality_revision'] === GovernanceConfig::qualityRevision($legacy), 'failed legacy migration retains the revision originally read');
API::$module = new QualityTestModule(); API::$module->config = $legacy;
$oldForm = new QualitySaveHarness(); $oldForm->input = ['cards' => []]; $oldForm->run();
qualityAssert(API::$module->config['quality_pages'] === [qualityPage()], 'removing every legacy card migrates to a genuinely empty page');
API::$module->exists = false;
$notFound = new QualitySaveHarness(); $notFound->input = ['quality_json' => '[]', 'quality_revision' => 'reviewed']; $notFound->run();
qualityAssert(strpos(CMessageHelper::$error, 'Module not found') !== false, 'missing module returns an actionable error');

echo 'PASS: ' . $assertions . " quality-page assertions\n";
