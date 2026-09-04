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
        $valid = $this->validateInput(['availability_json' => 'string', 'config_revision' => 'string']);
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
        $response = new CControllerResponseData([
            'page_title' => $isPt ? 'Regras de disponibilidade' : 'Availability rules',
            'is_pt' => $isPt, 'is_dark' => strpos(strtolower(getUserTheme(CWebUser::$data)), 'dark') !== false,
            'config' => $config, 'revision' => $this->getInput('config_revision', hash('sha256', json_encode($stored))),
            'conflict' => $this->hasInput('config_revision') && !hash_equals(hash('sha256', json_encode($stored)), $this->getInput('config_revision'))
        ]);
        $response->setTitle($response->getData()['page_title']);
        $this->setResponse($response);
    }
}
