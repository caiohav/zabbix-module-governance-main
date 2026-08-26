<?php

namespace Modules\Governance\Actions;

use API;
use CController;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use Modules\Governance\GovernanceConfig;

class QualityConfigUpdate extends CController {

    protected function checkInput(): bool {
        $valid = $this->validateInput([
            'cards' => 'array'
        ]);

        if (!$valid) {
            $this->setResponse(new CControllerResponseFatal());
        }

        return $valid;
    }

    protected function checkPermissions(): bool {
        return ($this->getUserType() == USER_TYPE_SUPER_ADMIN);
    }

    protected function doAction(): void {
        $redirect = new CControllerResponseRedirect(
            (new CUrl('zabbix.php'))->setArgument('action', 'governance.quality.config')
        );

        $modules = API::Module()->get([
            'output' => ['moduleid'],
            'filter' => ['id' => 'zabbix_module_governance']
        ]);

        if (!$modules) {
            CMessageHelper::setErrorTitle('Não foi possível localizar o módulo de governança.');
            $this->setResponse($redirect);
            return;
        }

        $cards = GovernanceConfig::normalizeCards($this->getInput('cards', []));
        $result = API::Module()->update([[
            'moduleid' => $modules[0]['moduleid'],
            'config' => ['cards' => $cards]
        ]]);

        if ($result) {
            CMessageHelper::setSuccessTitle('Configuração dos cards atualizada.');
        } else {
            CMessageHelper::setErrorTitle('Não foi possível atualizar a configuração dos cards.');
        }

        $this->setResponse($redirect);
    }
}
