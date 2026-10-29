<?php

namespace Modules\Governance;

use APP;

/** Read the enabled, role-authorized module instance; module.get is Super-admin-only. */
final class GovernanceModuleConfig {
    private const MODULE_ID = 'zabbix_module_governance';

    public static function read(): array {
        $module = APP::ModuleManager()->getModules()[self::MODULE_ID] ?? null;
        if ($module === null || !method_exists($module, 'getConfig')) {
            throw new \RuntimeException('Governance module unavailable / Módulo de governança indisponível.');
        }

        $config = $module->getConfig();
        if (!is_array($config)) {
            throw new \RuntimeException('Governance module configuration unavailable / Configuração de governança indisponível.');
        }

        return $config;
    }
}
