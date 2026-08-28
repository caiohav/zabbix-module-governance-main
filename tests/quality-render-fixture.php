<?php
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }
require_once __DIR__ . '/quality-fixture.php';
class CObject {
    private $value;
    public function __construct($value) { $this->value = $value; }
    public function __toString() { return $this->value; }
}
class CWidget {
    private $items = [];
    public function setTitle($title) { return $this; }
    public function addItem($item) { $this->items[] = $item; return $this; }
    public function show() { echo '<main>' . implode('', $this->items) . '</main>'; }
}
class CForm {
    private $id, $action;
    public function setId($id) { $this->id = $id; return $this; }
    public function setAction($action) { $this->action = $action; return $this; }
    public function setAttribute($name, $value) { return $this; }
    public function __toString() {
        $action = $this->action . '&preview_case=' . rawurlencode($_GET['preview_case'] ?? 'normal');
        return '<form hidden id="' . htmlspecialchars($this->id) . '" action="' . htmlspecialchars($action)
            . '"><input name="sid" value="local-fixture-only"></form>';
    }
}
class QualityRenderer {
    public $css = [], $js = [];
    public function addCssFile($file) { $this->css[] = $file; }
    public function includeJsFile($file) { $this->js[] = $file; }
    public function render(array $data): string {
        ob_start();
        try { require __DIR__ . '/../views/governance.quality.view.php'; return ob_get_contents(); }
        finally { ob_end_clean(); }
    }
    public function scripts(): void { foreach ($this->js as $file) { require __DIR__ . '/../views/js/' . $file; } }
}
function qualityRenderData(bool $pt = true, bool $dark = true, string $pageId = 'main'): array {
    $config = fixtureConfig();
    $page = $config['quality_pages'][$pageId === 'empty' ? 1 : 0];
    return ['page_title' => $pt ? 'Qualidade do monitoramento' : 'Monitoring quality', 'is_pt' => $pt, 'is_dark' => $dark,
        'pages' => $config['quality_pages'], 'selected_page' => $page['id'], 'page_name' => $page['name'],
        'cards' => $page['cards'], 'cards_count' => count($page['cards']), 'groupids' => ['10'],
        'revision' => Modules\Governance\GovernanceConfig::qualityRevision($config), 'error' => null];
}
