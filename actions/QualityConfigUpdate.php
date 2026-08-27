<?php

namespace Modules\Governance\Actions;

use API;
use CController;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use CWebUser;
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
        $isPt = (strpos(strtolower(CWebUser::getLang()), 'pt') === 0);
        $redirect = new CControllerResponseRedirect(
            (new CUrl('zabbix.php'))->setArgument('action', 'governance.quality.config')
        );

        $modules = API::Module()->get([
            'output' => ['moduleid', 'config'],
            'filter' => ['id' => 'zabbix_module_governance']
        ]);

        if (!$modules) {
            CMessageHelper::setErrorTitle($isPt
                ? 'Não foi possível localizar o módulo de governança.'
                : 'The governance module could not be found.'
            );
            $this->setResponse($redirect);
            return;
        }

        $cards = GovernanceConfig::normalizeCards($this->getInput('cards', []));
        $config = $modules[0]['config'];
        $config['cards'] = $cards;
        $result = API::Module()->update([[
            'moduleid' => $modules[0]['moduleid'],
            'config' => $config
        ]]);

        if ($result) {
            CMessageHelper::setSuccessTitle($isPt
                ? 'Configuração dos cards atualizada.'
                : 'Card configuration updated.'
            );
        } else {
            CMessageHelper::setErrorTitle($isPt
                ? 'Não foi possível atualizar a configuração dos cards.'
                : 'The card configuration could not be updated.'
            );
        }

        $this->setResponse($redirect);
    }
}
