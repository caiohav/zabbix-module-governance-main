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
use Modules\Governance\AvailabilityCalculation;
use Modules\Governance\AvailabilityJobException;
use Modules\Governance\AvailabilityJobStore;

class AvailabilityView extends CController {
    protected function init(): void { $this->disableSIDvalidation(); }
    protected function checkPermissions(): bool { return $this->getUserType() == USER_TYPE_SUPER_ADMIN; }
    protected function checkInput(): bool {
        $valid = $this->validateInput(['month' => 'string', 'department' => 'int32', 'job' => 'string']);
        if ($valid && $this->hasInput('job')) {
            $valid = (bool) preg_match('/^[a-f0-9]{64}$/D', $this->getInput('job'));
        }
        if (!$valid) { $this->setResponse(new CControllerResponseFatal()); }
        return $valid;
    }
    protected function doAction(): void {
        $isPt = strpos(strtolower(CWebUser::getLang()), 'pt') === 0;
        $config = AvailabilityConfig::defaults();
        $report = null;
        $job = null;
        $error = null;
        $month = '';
        $department = (int) $this->getInput('department', -1);
        try {
            if ($this->hasInput('job')) {
                $stored = $this->jobStore()->read($this->getInput('job'), (string) (CWebUser::$data['userid'] ?? ''));
                $state = $stored['state'];
                $job = AvailabilityJobStore::projection($stored);
                // A reopened report uses its frozen full configuration, not today's database rules.
                if (!empty($state['source_config'])) { $config = $state['source_config']; }
                $month = $state['report']['month'] ?? '';
                $department = (int) ($state['department_filter'] ?? -1);
                if ($state['status'] === 'complete') { $report = AvailabilityCalculation::result($state); }
                elseif ($state['status'] === 'failed') { $error = $job['error']; }
            }
            else {
                $modules = API::Module()->get(['output' => ['config'], 'filter' => ['id' => 'zabbix_module_governance']]);
                $config = AvailabilityConfig::validate($modules[0]['config']['availability'] ?? $config);
                $month = $this->getInput('month', '');
                if ($department !== -1 && !isset($config['departments'][$department])) {
                    $error = 'Invalid department / Departamento inválido.';
                    $department = -1;
                }
            }
        }
        catch (AvailabilityJobException $e) {
            $error = $e->getMessage();
            if ($e->getCode() === AvailabilityJobStore::BUSY && $this->hasInput('job')) {
                $job = ['job' => $this->getInput('job'), 'sequence' => 0, 'status' => 'busy',
                    'progress' => [], 'retryable' => true];
            }
        }
        catch (\Throwable $e) {
            $error = 'Cannot load calculation or rules / Não foi possível carregar o cálculo ou as regras.';
        }
        if ($month === '') {
            $date = new DateTimeImmutable('now', new DateTimeZone($config['timezone']));
            foreach ($config['departments'] as $index => $node) {
                if ($department !== -1 && $department !== $index) { continue; }
                foreach ($node['technologies'] as $technology) {
                    if (($technology['source'] ?? 'items') === 'sla') {
                        $date = $date->modify('first day of previous month');
                        break 2;
                    }
                }
            }
            $month = $date->format('Y-m');
        }
        $response = new CControllerResponseData([
            'page_title' => $isPt ? 'Disponibilidade por departamento' : 'Department availability',
            'is_pt' => $isPt, 'is_dark' => strpos(strtolower(getUserTheme(CWebUser::$data)), 'dark') !== false,
            'config' => $config, 'report' => $report, 'job' => $job, 'error' => $error, 'month' => $month, 'department' => $department
        ]);
        $response->setTitle($response->getData()['page_title']);
        $this->setResponse($response);
    }

    protected function jobStore(): AvailabilityJobStore { return new AvailabilityJobStore(); }
}
