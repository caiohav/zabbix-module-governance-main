<?php

namespace Modules\Governance\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CWebUser;
use Modules\Governance\AvailabilityConfig;

class AvailabilityConfigView extends CController {
    protected function init(): void { $this->disableSIDvalidation(); }
    protected function checkPermissions(): bool { return $this->getUserType() == USER_TYPE_SUPER_ADMIN; }
    protected function checkInput(): bool {
        $valid = $this->validateInput(['availability_json' => 'string']);
        if (!$valid) { $this->setResponse(new CControllerResponseFatal()); }
        return $valid;
    }
    protected function doAction(): void {
        $isPt = strpos(strtolower(CWebUser::getLang()), 'pt') === 0;
        $modules = API::Module()->get(['output' => ['config'], 'filter' => ['id' => 'zabbix_module_governance']]);
        $stored = $modules[0]['config']['availability'] ?? AvailabilityConfig::defaults();
        $config = $stored;
        if ($this->hasInput('availability_json')) {
            $submitted = json_decode($this->getInput('availability_json'), true);
            if (is_array($submitted) && isset($submitted['departments'], $submitted['timezone'])) { $config = $submitted; }
        }
        $this->setResponse(new CControllerResponseData([
            'page_title' => $isPt ? 'Regras de disponibilidade' : 'Availability rules',
            'is_pt' => $isPt, 'is_dark' => strpos(CWebUser::$data['theme'] ?? '', 'dark') !== false,
            'config' => $config, 'revision' => hash('sha256', json_encode($stored))
        ]));
    }
}
