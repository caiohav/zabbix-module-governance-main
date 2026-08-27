<?php

namespace Modules\Governance\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CWebUser;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Governance\AvailabilityConfig;
use Modules\Governance\AvailabilityReport;

class AvailabilityView extends CController {
    protected function init(): void { $this->disableSIDvalidation(); }
    protected function checkPermissions(): bool { return $this->getUserType() == USER_TYPE_SUPER_ADMIN; }
    protected function checkInput(): bool {
        $valid = $this->validateInput(['month' => 'string', 'department' => 'int32']);
        if (!$valid) { $this->setResponse(new CControllerResponseFatal()); }
        return $valid;
    }
    protected function doAction(): void {
        $isPt = strpos(strtolower(CWebUser::getLang()), 'pt') === 0;
        $config = AvailabilityConfig::defaults();
        $report = null;
        $error = null;
        $month = '';
        $department = (int) $this->getInput('department', -1);
        try {
            $modules = API::Module()->get(['output' => ['config'], 'filter' => ['id' => 'zabbix_module_governance']]);
            $config = AvailabilityConfig::validate($modules[0]['config']['availability'] ?? $config);
            $month = $this->getInput('month', (new DateTimeImmutable('now', new DateTimeZone($config['timezone'])))->format('Y-m'));
            $selected = $config;
            if ($department !== -1) {
                if (!isset($config['departments'][$department])) {
                    throw new \RuntimeException('Invalid department / Departamento inválido.');
                }
                $selected['departments'] = [$config['departments'][$department]];
            }
            if ($selected['departments']) { $report = (new AvailabilityReport())->build($selected, $month); }
        }
        catch (\Exception $e) { $error = $e->getMessage(); }
        $this->setResponse(new CControllerResponseData([
            'page_title' => $isPt ? 'Disponibilidade por departamento' : 'Department availability',
            'is_pt' => $isPt, 'is_dark' => strpos(CWebUser::$data['theme'] ?? '', 'dark') !== false,
            'config' => $config, 'report' => $report, 'error' => $error, 'month' => $month, 'department' => $department
        ]));
    }
}
