<?php

namespace Modules\Governance\Actions;

use API;
use CController;
use CControllerResponseData;
use CWebUser;
use Modules\Governance\QualityCalculation;
use Modules\Governance\QualityJobException;
use Modules\Governance\QualityJobStore;

/** Native Zabbix SID validation remains enabled for every authenticated POST. */
class QualityRun extends CController {
    protected function checkPermissions(): bool { return $this->getUserType() == USER_TYPE_SUPER_ADMIN; }
    protected function checkInput(): bool {
        $valid = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $this->validateInput([
            'operation' => 'required|string', 'page' => 'string', 'revision' => 'string',
            'groupids' => 'array_db hstgrp.groupid', 'request_id' => 'string', 'job' => 'string', 'sequence' => 'int32'
        ]);
        if ($valid) {
            $operation = $this->getInput('operation');
            $valid = in_array($operation, ['start', 'step', 'status', 'cancel'], true);
            if ($operation === 'start') {
                $valid = $valid && preg_match('/^[a-f0-9]{64}$/D', $this->getInput('request_id', ''))
                    && preg_match('/^[a-f0-9]{64}$/D', $this->getInput('revision', ''))
                    && strlen($this->getInput('page', '')) <= 100 && count($this->getInput('groupids', [])) <= 1000;
            }
            else {
                $valid = $valid && preg_match('/^[a-f0-9]{64}$/D', $this->getInput('job', ''));
                if ($operation === 'step' || $operation === 'cancel') {
                    $valid = $valid && $this->hasInput('sequence') && (int) $this->getInput('sequence', -1) >= 0;
                }
            }
        }
        if (!$valid) { $this->respond(['status' => 'failed', 'error' => 'Invalid quality request / Solicitação de qualidade inválida.']); }
        return (bool) $valid;
    }
    protected function doAction(): void {
        $owner = (string) (CWebUser::$data['userid'] ?? '');
        $operation = $this->getInput('operation');
        $id = $this->getInput('job', '');
        try {
            $store = $this->jobStore();
            if ($operation === 'start') {
                $request = $this->getInput('request_id');
                $id = hash('sha256', $owner . ':' . $request);
                $page = $this->getInput('page', ''); $groups = $this->getInput('groupids', []);
                $revision = $this->getInput('revision');
                $job = $store->create($owner, $request, static function() use ($page, $groups, $revision): array {
                    $modules = API::Module()->get(['output' => ['config'], 'filter' => ['id' => 'zabbix_module_governance']]);
                    if (!is_array($modules) || !$modules) { throw new \RuntimeException('Module unavailable.'); }
                    return QualityCalculation::create($modules[0]['config'], $page, $groups, $revision);
                });
            }
            elseif ($operation === 'step') {
                $job = $store->step($id, $owner, (int) $this->getInput('sequence'), static function(array $state): array {
                    return (new QualityCalculation())->advance($state);
                });
            }
            elseif ($operation === 'cancel') { $job = $store->cancel($id, $owner, (int) $this->getInput('sequence')); }
            else { $job = $store->read($id, $owner); }
            $this->respond(QualityJobStore::projection($job));
        }
        catch (QualityJobException $e) {
            $busy = $e->getCode() === QualityJobStore::BUSY;
            $this->respond(['job' => $id, 'status' => $busy ? 'busy' : 'failed', 'error' => $e->getMessage()]);
        }
        catch (\Throwable $e) {
            error_log('[Governance quality] Unexpected ' . $operation . ' failure (' . get_class($e) . ').');
            $this->respond(['status' => 'failed', 'error' => 'Cannot process quality. Check the frontend log / Não foi possível processar a qualidade. Consulte o log do frontend.']);
        }
    }
    protected function jobStore(): QualityJobStore { return new QualityJobStore(); }
    private function respond(array $data): void {
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $this->setResponse(new CControllerResponseData(['main_block' => $json === false
            ? '{"status":"failed","error":"Cannot encode response / Resposta inválida."}' : $json]));
    }
}
