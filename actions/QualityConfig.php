<?php

namespace Modules\Governance\Actions;

use API;
use CController;
use CControllerResponseData;
use CWebUser;
use Modules\Governance\GovernanceConfig;

class QualityConfig extends CController {

    protected function init(): void {
        $this->disableSIDvalidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return ($this->getUserType() == USER_TYPE_SUPER_ADMIN);
    }

    protected function doAction(): void {
        $modules = API::Module()->get([
            'output' => ['moduleid', 'config'],
            'filter' => ['id' => 'zabbix_module_governance']
        ]);

        $storedCards = $modules ? ($modules[0]['config']['cards'] ?? []) : [];

        $this->setResponse(new CControllerResponseData([
            'page_title' => 'Configuração dos Cards de Governança',
            'cards' => GovernanceConfig::normalizeCards($storedCards),
            'is_dark' => self::isDarkTheme()
        ]));
    }

    private static function isDarkTheme(): bool {
        return (strpos(strtolower(CWebUser::$data['theme'] ?? ''), 'dark') !== false);
    }
}
