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
        $isPt = (strpos(strtolower(CWebUser::getLang()), 'pt') === 0);
        $modules = API::Module()->get([
            'output' => ['moduleid', 'config'],
            'filter' => ['id' => 'zabbix_module_governance']
        ]);

        $storedCards = $modules ? ($modules[0]['config']['cards'] ?? []) : [];

        $this->setResponse(new CControllerResponseData([
            'page_title' => $isPt ? 'Configuração dos Cards de Governança' : 'Governance Card Configuration',
            'cards' => GovernanceConfig::normalizeCards($storedCards),
            'is_pt' => $isPt,
            'is_dark' => self::isDarkTheme()
        ]));
    }

    private static function isDarkTheme(): bool {
        return (strpos(strtolower(CWebUser::$data['theme'] ?? ''), 'dark') !== false);
    }
}
