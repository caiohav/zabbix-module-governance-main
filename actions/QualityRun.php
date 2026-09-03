<?php

namespace Modules\Governance\Actions;

use API;
use CController;
use CControllerResponseData;
use CWebUser;
use Modules\Governance\QualityCalculation;
use Modules\Governance\QualityJobException;
use Modules\Governance\QualityJobStore;
use Modules\Governance\GovernanceConfig;
use Modules\Governance\QualityCatalog;

/** Native Zabbix SID validation remains enabled for every authenticated POST. */
class QualityRun extends CController {
    protected function checkPermissions(): bool { return $this->getUserType() == USER_TYPE_SUPER_ADMIN; }
    protected function checkInput(): bool {
        $valid = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $this->validateInput([
            'operation' => 'required|string', 'page' => 'string', 'revision' => 'string',
            'groupids' => 'array_db hstgrp.groupid', 'request_id' => 'string', 'job' => 'string', 'sequence' => 'int32', 'card_json' => 'string',
            'lookup_type' => 'string', 'query' => 'string'
        ]);
        if ($valid) {
            $operation = $this->getInput('operation');
            $valid = in_array($operation, ['lookup', 'start', 'preview_start', 'step', 'status', 'cancel'], true);
            if ($operation === 'lookup') {
                $valid = $valid && QualityCatalog::valid($this->getInput('lookup_type', ''), $this->getInput('query', ''));
            }
            elseif ($operation === 'preview_start') {
                $valid = $valid && preg_match('/^[a-f0-9]{64}$/D', $this->getInput('request_id', ''))
                    && strlen($this->getInput('card_json', '')) <= 20000 && $this->getInput('card_json', '') !== '';
            }
            elseif ($operation === 'start') {
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
            if ($operation === 'lookup') {
                $this->respond(QualityCatalog::search($this->getInput('lookup_type'), $this->getInput('query'), function($service, $options) { return $this->catalogGet($service, $options); }));
                return;
            }
            $store = $this->jobStore();
            if ($operation === 'preview_start') {
                $card = json_decode($this->getInput('card_json'), true, 32);
                $pages = GovernanceConfig::validateQualityPages([['id' => 'preview', 'name' => 'Preview', 'cards' => [$card]]]);
                $config = ['quality_pages' => $pages];
                $request = $this->getInput('request_id');
                $id = hash('sha256', $owner . ':' . $request);
                $job = $store->create($owner, $request, static function() use ($config): array {
                    $state = QualityCalculation::create($config, 'preview', [], GovernanceConfig::qualityRevision($config));
                    $state['preview'] = true; $state['preview_hosts'] = [];
                    return $state;
                });
            }
            elseif ($operation === 'start') {
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
        catch (\InvalidArgumentException $e) {
            $this->respond(['status' => 'failed', 'error' => 'Review the condition and indicator fields / Revise os campos das condições e do indicador.']);
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
    protected function catalogGet(string $service, array $options) { return API::$service()->get($options); }
    private function respond(array $data): void {
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $this->setResponse(new CControllerResponseData(['main_block' => $json === false
            ? '{"status":"failed","error":"Cannot encode response / Resposta inválida."}' : $json]));
    }
}
