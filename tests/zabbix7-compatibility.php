<?php

namespace Zabbix\Core {
    class CModule {}
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

    define('USER_TYPE_SUPER_ADMIN', 3);

    class CController {
        private $csrfDisabled = false;
        protected function disableCsrfValidation(): void { $this->csrfDisabled = true; }
        public function csrfIsDisabled(): bool { return $this->csrfDisabled; }
    }
    class CControllerResponseData {}
    class CControllerResponseFatal {}
    class CWebUser {
        public static function getLang() { return 'pt_BR'; }
        public static function getType() { return USER_TYPE_SUPER_ADMIN; }
    }
    class CMenu {
        public $items;
        public function __construct(array $items = []) { $this->items = $items; }
        public function add(CMenuItem $item): self { $this->items[] = $item; return $this; }
    }
    class CMenuItem {
        public $label, $action, $icon, $submenu;
        public function __construct(string $label) { $this->label = $label; }
        public function setAction(string $action): self { $this->action = $action; return $this; }
        public function setIcon(string $icon): self { $this->icon = $icon; return $this; }
        public function setSubMenu(CMenu $submenu): self { $this->submenu = $submenu; return $this; }
    }
    class CompatibilityComponents {
        public $menu;
        public function __construct() { $this->menu = new CMenu(); }
        public function get($name) {
            if ($name !== 'menu.main') { throw new \RuntimeException('Unexpected component.'); }
            return $this->menu;
        }
    }
    class APP {
        public static $components;
        public static function Component() { return self::$components; }
    }

    $root = dirname(__DIR__);
    $checks = 0;
    $check = static function($condition, string $message) use (&$checks): void {
        $checks++;
        if (!$condition) { throw new \RuntimeException($message); }
    };

    $manifest6 = json_decode(file_get_contents($root . '/platforms/zabbix-6.0/manifest.json'), true);
    $manifest7 = json_decode(file_get_contents($root . '/platforms/zabbix-7.0/manifest.json'), true);
    $check($manifest6['manifest_version'] === 1.0, 'Zabbix 6 manifest must remain at 1.0.');
    $check($manifest7['manifest_version'] === 2.0, 'Zabbix 7 manifest must use 2.0.');
    $check($manifest6['id'] === $manifest7['id'], 'Both packages must preserve the module identity.');
    $check($manifest6['actions'] === $manifest7['actions'], 'Both packages must expose the same actions.');

    require $root . '/platforms/zabbix-7.0/Module.php';
    APP::$components = new CompatibilityComponents();
    $module = new \Modules\Governance\Module();
    $check($module instanceof \Zabbix\Core\CModule, 'Zabbix 7 module must extend its namespaced base class.');
    $module->init();
    $check(count(APP::$components->menu->items) === 1, 'Zabbix 7 menu must be registered.');
    $check(APP::$components->menu->items[0]->icon === 'zi-dashboards',
        'Zabbix 7 menu must use its native dashboard icon class.');
    $check(APP::$components->menu->items[0]->submenu->items[1]->action === 'governance.availability.view',
        'Availability action must remain in the Zabbix 7 menu.');

    foreach (['AvailabilityView', 'AvailabilityConfigView', 'QualityView', 'QualityConfig'] as $controller) {
        require $root . '/actions/' . $controller . '.php';
        $class = 'Modules\\Governance\\Actions\\' . $controller;
        $instance = new $class();
        $init = new \ReflectionMethod($class, 'init');
        $init->setAccessible(true);
        $init->invoke($instance);
        $check($instance->csrfIsDisabled(), $controller . ' must use Zabbix 7 read-only CSRF handling.');
    }

    foreach (glob($root . '/views/*.php') as $view) {
        $source = file_get_contents($view);
        $check(strpos($source, "class_exists('CHtmlPage')") !== false,
            basename($view) . ' must select the Zabbix 7 page container.');
    }
    foreach (['governance.availability.view.php', 'governance.quality.view.php',
        'governance.availability.config.php', 'governance.quality.config.php'] as $view) {
        $source = file_get_contents($root . '/views/' . $view);
        $check(strpos($source, 'CCsrfTokenHelper::get(') !== false,
            $view . ' must emit action-specific Zabbix 7 CSRF tokens.');
    }

    echo 'PASS: ' . $checks . ' Zabbix 7 compatibility assertions' . PHP_EOL;
}
