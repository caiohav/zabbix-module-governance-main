<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

class CObject {
    protected $items = [];
    public function __construct($item = null) { if ($item !== null) { $this->addItem($item); } }
    public function addItem($item) { $this->items[] = $item; return $this; }
    public function __toString() { return implode('', $this->items); }
}
class CTag extends CObject {
    protected $tag, $attributes = [];
    public function __construct(string $tag, bool $paired = false, $body = null) {
        $this->tag = $tag;
        parent::__construct($body);
    }
    public function setAttribute($name, $value) { $this->attributes[$name] = $value; return $this; }
    public function setId($id) { return $this->setAttribute('id', $id); }
    public function __toString() {
        $attributes = '';
        foreach ($this->attributes as $name => $value) { $attributes .= ' ' . $name . '="' . htmlspecialchars((string) $value) . '"'; }
        return '<' . $this->tag . $attributes . '>' . parent::__toString() . '</' . $this->tag . '>';
    }
}
class CForm extends CTag {
    public function __construct() { parent::__construct('form', true); }
    public function setAction($action) { return $this->setAttribute('action', $action); }
    public function addVar($name, $value) {
        return $this->addItem('<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '">');
    }
}
class CCsrfTokenHelper {
    public const CSRF_TOKEN_NAME = '_csrf_token';
    public static function get(string $action): string { return 'token-for:' . $action; }
}
class CHtmlPage {
    private $title = '', $controls, $items = [];
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function setControls(?CTag $controls): self { $this->controls = $controls; return $this; }
    public function addItem($item): self { $this->items[] = $item; return $this; }
    public function show(): self {
        echo '<header><h1>' . htmlspecialchars($this->title) . '</h1>' . $this->controls . '</header>' . implode('', $this->items);
        return $this;
    }
}
class Zabbix7ViewRenderer {
    public function addCssFile($file): void {}
    public function includeJsFile($file): void {}
    public function render(array $data): string {
        ob_start();
        require dirname(__DIR__) . '/views/governance.quality.view.php';
        return ob_get_clean();
    }
}

$html = (new Zabbix7ViewRenderer())->render([
    'is_pt' => true,
    'is_dark' => true,
    'selected_page' => 'main',
    'pages' => [['id' => 'main', 'name' => 'Qualidade']],
    'groupids' => [],
    'cards' => [],
    'cards_count' => 0,
    'page_name' => 'Qualidade',
    'revision' => str_repeat('a', 64),
    'error' => null,
    'page_title' => 'Qualidade do monitoramento'
]);

if (strpos($html, '<h1>Qualidade do monitoramento</h1>') === false
        || strpos($html, 'name="_csrf_token"') === false
        || strpos($html, 'token-for:governance.quality.run') === false
        || strpos($html, 'id="gqp-dashboard"') === false) {
    throw new RuntimeException('Zabbix 7 view contract was not rendered correctly.');
}

echo 'PASS: Zabbix 7 typed page controls and action CSRF rendering' . PHP_EOL;
