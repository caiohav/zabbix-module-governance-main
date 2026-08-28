<?php

namespace Modules\Governance\Actions;

use API;
use CController;
use CControllerResponseData;
use CWebUser;
use Modules\Governance\AvailabilityCalculation;
use Modules\Governance\AvailabilityConfig;
use Modules\Governance\AvailabilityJobException;
use Modules\Governance\AvailabilityJobStore;

/** One bounded checkpoint per authenticated POST; native Zabbix 6 SID validation stays enabled. */
class AvailabilityRun extends CController {
    protected function checkPermissions(): bool { return $this->getUserType() == USER_TYPE_SUPER_ADMIN; }

    protected function checkInput(): bool {
        $valid = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && $this->validateInput([
                'operation' => 'required|string', 'month' => 'string', 'department' => 'int32',
                'request_id' => 'string', 'job' => 'string', 'sequence' => 'int32'
            ]);
        if ($valid) {
            $operation = $this->getInput('operation');
            $valid = in_array($operation, ['start', 'step', 'status', 'cancel'], true);
            if ($operation === 'start') {
                $valid = $valid && preg_match('/^[a-f0-9]{64}$/D', $this->getInput('request_id', ''))
                    && preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $this->getInput('month', ''))
                    && (int) $this->getInput('department', -1) >= -1;
            }
            else {
                $valid = $valid && preg_match('/^[a-f0-9]{64}$/D', $this->getInput('job', ''));
                if ($operation === 'step' || $operation === 'cancel') {
                    $valid = $valid && $this->hasInput('sequence') && (int) $this->getInput('sequence', -1) >= 0;
                }
            }
        }
        if (!$valid) {
            $this->respond(['status' => 'failed', 'retryable' => false,
                'error' => 'Invalid POST calculation request / Solicitação POST de cálculo inválida.']);
        }
        return (bool) $valid;
    }

    protected function doAction(): void {
        $owner = (string) (CWebUser::$data['userid'] ?? '');
        $operation = $this->getInput('operation');
        $id = $this->getInput('job', '');
        $sequence = (int) $this->getInput('sequence', 0);
        try {
            $store = $this->jobStore();
            if ($operation === 'start') {
                $requestId = $this->getInput('request_id');
                $id = hash('sha256', $owner . ':' . $requestId);
                $month = $this->getInput('month');
                $department = (int) $this->getInput('department', -1);
                $job = $store->create($owner, $requestId, static function() use ($month, $department): array {
                    // Rules are loaded once from the server. Neither checkpoints nor rules come from the client.
                    $modules = API::Module()->get(['output' => ['config'], 'filter' => ['id' => 'zabbix_module_governance']]);
                    if (!is_array($modules) || !$modules) { throw new \RuntimeException('Module unavailable.'); }
                    $config = AvailabilityConfig::validate($modules[0]['config']['availability'] ?? AvailabilityConfig::defaults());
                    return AvailabilityCalculation::create($config, $month, $department);
                });
            }
            elseif ($operation === 'step') {
                $job = $store->step($id, $owner, $sequence, static function(array $state): array {
                    return (new AvailabilityCalculation())->advance($state);
                });
            }
            elseif ($operation === 'cancel') {
                $job = $store->cancel($id, $owner, $sequence);
            }
            else { $job = $store->read($id, $owner); }
            $this->respond(AvailabilityJobStore::projection($job));
        }
        catch (AvailabilityJobException $e) {
            $busy = $e->getCode() === AvailabilityJobStore::BUSY;
            $this->respond(['job' => $id, 'sequence' => $sequence,
                'status' => $busy ? 'busy' : 'failed', 'retryable' => $busy, 'error' => $e->getMessage()]);
        }
        catch (\Throwable $e) {
            // Class and validated operation only: exception text may contain SQL, paths or credentials.
            error_log('[Governance availability] Unexpected ' . $operation . ' failure (' . get_class($e) . ').');
            $this->respond(['job' => $id, 'sequence' => $sequence, 'status' => 'failed', 'retryable' => false,
                'error' => 'Cannot process calculation. Check the frontend log / Não foi possível processar o cálculo. Consulte o log do frontend.']);
        }
    }

    protected function jobStore(): AvailabilityJobStore { return new AvailabilityJobStore(); }

    private function respond(array $payload): void {
        // The manifest must use layout.json: a null layout does not emit main_block in Zabbix 6.
        $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($json === false) {
            $json = '{"status":"failed","error":"Cannot encode calculation response / Resposta de cálculo inválida.","retryable":false}';
        }
        $this->setResponse(new CControllerResponseData(['main_block' => $json]));
    }
}
