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
    protected function init(): void {
        if (method_exists($this, 'disableCsrfValidation')) {
            $this->disableCsrfValidation();
        }
        else {
            $this->disableSIDvalidation();
        }
    }
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
        $rulesChanged = false;
        $month = '';
        $department = (int) $this->getInput('department', -1);
        try {
            $modules = API::Module()->get(['output' => ['config'], 'filter' => ['id' => 'zabbix_module_governance']]);
            if (!is_array($modules) || !$modules) { throw new \RuntimeException('Module unavailable.'); }
            $config = AvailabilityConfig::validate($modules[0]['config']['availability'] ?? $config);
            if ($this->hasInput('job')) {
                $stored = $this->jobStore()->read($this->getInput('job'), (string) (CWebUser::$data['userid'] ?? ''));
                $state = $stored['state'];
                $month = $state['report']['month'] ?? '';
                $department = (int) ($state['department_filter'] ?? -1);
                $rulesChanged = !isset($state['source_config'])
                    || json_encode($config) !== json_encode($state['source_config']);
                if (!$rulesChanged) {
                    $job = AvailabilityJobStore::projection($stored);
                    if ($state['status'] === 'complete') { $report = AvailabilityCalculation::result($state); }
                    elseif ($state['status'] === 'failed') { $error = $job['error']; }
                }
            }
            else { $month = $this->getInput('month', ''); }
            if ($department !== -1 && !isset($config['departments'][$department])) {
                if (!$rulesChanged) { $error = 'Invalid department / Departamento inválido.'; }
                $department = -1;
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
            'config' => $config, 'report' => $report, 'job' => $job, 'error' => $error,
            'rules_changed' => $rulesChanged, 'month' => $month, 'department' => $department
        ]);
        $response->setTitle($response->getData()['page_title']);
        $this->setResponse($response);
    }

    protected function jobStore(): AvailabilityJobStore { return new AvailabilityJobStore(); }
}
