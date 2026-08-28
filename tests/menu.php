<?php

namespace Core {
    class CModule {}
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('USER_TYPE_SUPER_ADMIN', 3);
    class CWebUser {
        public static $language = 'pt_BR';
        public static $type = 3;
        public static function getLang() { return self::$language; }
        public static function getType() { return self::$type; }
    }
    class CMenu {
        public $items;
        public function __construct($items = []) { $this->items = $items; }
        public function add($item) { $this->items[] = $item; return $this; }
    }
    class CMenuItem {
        public $title, $action, $icon, $submenu;
        public function __construct($title) { $this->title = $title; }
        public function setAction($value) { $this->action = $value; return $this; }
        public function setIcon($value) { $this->icon = $value; return $this; }
        public function setSubMenu($value) { $this->submenu = $value; return $this; }
    }
    class MenuComponents {
        public $menu;
        public function __construct() { $this->menu = new CMenu(); }
        public function get($name) {
            if ($name !== 'menu.main') { throw new \RuntimeException('Unexpected component'); }
            return $this->menu;
        }
    }
    class APP {
        public static $components;
        public static function Component() { return self::$components; }
    }
    require __DIR__ . '/../Module.php';
    $checks = 0;
    $check = static function($condition, $message) use (&$checks) {
        $checks++;
        if (!$condition) { throw new \RuntimeException($message); }
    };
    foreach ([
        'pt_BR' => ['Governança', 'Qualidade', 'Disponibilidade', 'Configurar qualidade', 'Configurar disponibilidade'],
        'en_GB' => ['Governance', 'Quality', 'Availability', 'Configure quality', 'Configure availability']
    ] as $language => $labels) {
        APP::$components = new MenuComponents();
        CWebUser::$language = $language;
        (new \Modules\Governance\Module())->init();
        $menu = APP::$components->menu;
        $check(count($menu->items) === 1, 'One governance menu');
        $root = $menu->items[0];
        $check($root->title === array_shift($labels), 'Localized governance label');
        $check($root->icon === 'icon-dashboard', 'Keep the selected native icon');
        $check(array_column($root->submenu->items, 'title') === $labels, 'Localized section and configuration labels');
        $check(array_column($root->submenu->items, 'action') === [
            'governance.quality.view', 'governance.availability.view',
            'governance.quality.config', 'governance.availability.config'
        ], 'Separate links to both configuration pages');
    }
    foreach ([0, 1, 2] as $type) {
        APP::$components = new MenuComponents();
        CWebUser::$type = $type;
        (new \Modules\Governance\Module())->init();
        $check(APP::$components->menu->items === [], 'No governance menu for non-super-admin users');
    }
    echo 'PASS: ' . $checks . ' menu assertions' . PHP_EOL;
}
