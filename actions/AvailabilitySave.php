<?php

namespace Modules\Governance\Actions;

use API;
use CController;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use CWebUser;
use Modules\Governance\AvailabilityConfig;
use Modules\Governance\GovernanceConfig;

class AvailabilitySave extends CController {
    // Keep the native Zabbix 6 SID validation enabled for this mutation.
    protected function checkPermissions(): bool { return $this->getUserType() == USER_TYPE_SUPER_ADMIN; }
    protected function checkInput(): bool {
        $valid = $this->validateInput(['availability_json' => 'required|string', 'config_revision' => 'required|string']);
        if (!$valid) { $this->setResponse(new CControllerResponseFatal()); }
        return $valid;
    }
    protected function doAction(): void {
        $isPt = strpos(strtolower(CWebUser::getLang()), 'pt') === 0;
        $redirect = new CControllerResponseRedirect((new CUrl('zabbix.php'))->setArgument('action', 'governance.availability.config'));
        $json = $this->getInput('availability_json');
        try {
            if (strlen($json) > 300000) { throw new \InvalidArgumentException('Configuration too large / Configuração muito grande.'); }
            $config = AvailabilityConfig::validate(json_decode($json, true));
            $modules = API::Module()->get(['output' => ['moduleid', 'config'], 'filter' => ['id' => 'zabbix_module_governance']]);
            if (!$modules) { throw new \RuntimeException('Module not found / Módulo não encontrado.'); }
            $merged = $modules[0]['config'];
            $revision = hash('sha256', json_encode($merged['availability'] ?? AvailabilityConfig::defaults()));
            if (!hash_equals($revision, $this->getInput('config_revision'))) {
                throw new \RuntimeException('Rules changed in another session. Review before saving / Regras alteradas em outra sessão. Revise antes de salvar.');
            }
            $merged['availability'] = $config;
            GovernanceConfig::assertModuleConfigSize($merged);
            if (!API::Module()->update([['moduleid' => $modules[0]['moduleid'], 'config' => $merged]])) {
                throw new \RuntimeException('Could not save / Não foi possível salvar.');
            }
            CMessageHelper::setSuccessTitle($isPt ? 'Regras de disponibilidade salvas.' : 'Availability rules saved.');
        }
        catch (\Exception $e) {
            CMessageHelper::setErrorTitle($isPt ? 'As regras não foram salvas.' : 'Rules were not saved.');
            CMessageHelper::addError($e->getMessage());
            $redirect->setFormData(['availability_json' => $json, 'config_revision' => $this->getInput('config_revision')]);
        }
        $this->setResponse($redirect);
    }
}
