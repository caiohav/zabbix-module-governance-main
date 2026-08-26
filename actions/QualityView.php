<?php

namespace Modules\Governance\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use API;

class QualityView extends CController {

    /**
     * Inicializa a ação e desabilita validações desnecessárias para views.
     */
    protected function init(): void {
        $this->disableCsrfValidation();
    }

    /**
     * Valida se o usuário logado possui perfil de Super Admin.
     */
    protected function checkPermissions(): bool {
        return ($this->getUserType() == USER_TYPE_SUPER_ADMIN);
    }

    /**
     * Valida os parâmetros de requisição (HTTP GET/POST).
     */
    protected function checkInput(): bool {
        $fields = [
            'groupids' => 'array_db hstgrp.groupid'
        ];

        return $this->validateInput($fields);
    }

    /**
     * Lógica principal: realiza consultas na API do Zabbix e calcula os KPIs.
     */
    protected function doAction(): void {
        $userLang = CWebUser::getLang();
        $isPt = (strpos(strtolower($userLang), 'pt') === 0);

        // Captura filtros opcionais
        $groupids = $this->getInput('groupids', []);

        // Opções de busca na API de Hosts do Zabbix
        $options = [
            'output' => ['hostid', 'name', 'status'],
            'selectTags' => 'extend',
            'selectInventory' => ['os', 'serialno_a', 'location', 'type', 'software'],
            'selectParentTemplates' => ['templateid', 'name'],
            'filter' => ['status' => HOST_STATUS_MONITORED],
            'preservekeys' => true
        ];

        if (!empty($groupids)) {
            $options['groupids'] = $groupids;
        }

        // Executa a consulta via API nativa do Zabbix
        $hosts = API::Host()->get($options);
        $totalHosts = count($hosts);

        // Tratamento para ambientes sem hosts monitorados
        if ($totalHosts === 0) {
            $this->setResponse(new CControllerResponseData([
                'is_pt' => $isPt,
                'page_title' => $isPt ? 'Qualidade de Governança' : 'Governance Quality',
                'total_hosts' => 0,
                'overall_score' => 100,
                'kpis' => []
            ]));
            return;
        }

        // --- Contadores de Conformidade ---
        $countTagDepartment = 0;
        $countInventoryComplete = 0;
        $countTemplateBound = 0;

        $nonCompliantDeptTag = [];
        $nonCompliantInventory = [];
        $nonCompliantTemplate = [];

        foreach ($hosts as $hostid => $host) {
            // Não dependa de preservekeys para obter o ID correto do host.
            $hostid = $host['hostid'];
            // 1. KPI: Tag de Departamento
            $hasDeptTag = false;
            if (!empty($host['tags'])) {
                foreach ($host['tags'] as $tag) {
                    $tagName = strtolower(trim($tag['tag']));
                    if (in_array($tagName, ['department', 'departamento', 'dept'])) {
                        $hasDeptTag = true;
                        break;
                    }
                }
            }

            if ($hasDeptTag) {
                $countTagDepartment++;
            } else {
                $nonCompliantDeptTag[] = ['hostid' => $hostid, 'name' => $host['name']];
            }

            // 2. KPI: Preenchimento de Inventário (S.O. ou Número de Série)
            $hasInventory = false;
            if (!empty($host['inventory']) && is_array($host['inventory'])) {
                $os = trim($host['inventory']['os'] ?? '');
                $serial = trim($host['inventory']['serialno_a'] ?? '');
                if ($os !== '' || $serial !== '') {
                    $hasInventory = true;
                }
            }

            if ($hasInventory) {
                $countInventoryComplete++;
            } else {
                $nonCompliantInventory[] = ['hostid' => $hostid, 'name' => $host['name']];
            }

            // 3. KPI: Vínculo de Templates
            $hasTemplate = !empty($host['parentTemplates']);
            if ($hasTemplate) {
                $countTemplateBound++;
            } else {
                $nonCompliantTemplate[] = ['hostid' => $hostid, 'name' => $host['name']];
            }
        }

        // --- Cálculos de Porcentagem e Score Geral ---
        $pctDeptTag = round(($countTagDepartment / $totalHosts) * 100, 1);
        $pctInventory = round(($countInventoryComplete / $totalHosts) * 100, 1);
        $pctTemplate = round(($countTemplateBound / $totalHosts) * 100, 1);

        $overallScore = round(($pctDeptTag + $pctInventory + $pctTemplate) / 3, 1);

        // Função auxiliar para definir severidade visual (CSS status)
        $getStatus = function(float $pct): string {
            if ($pct >= 90.0) return 'good';
            if ($pct >= 70.0) return 'warning';
            return 'critical';
        };

        // --- Estrutura de KPIs enviada para a View ---
        $kpis = [
            'tag_department' => [
                'id' => 'kpi_tag_dept',
                'title' => $isPt ? 'Coincidência de Tag de Departamento' : 'Department Tag Coverage',
                'description' => $isPt 
                    ? 'Porcentagem de hosts com a tag "departamento" ou "department".' 
                    : 'Percentage of hosts tagged with "department".',
                'score' => $pctDeptTag,
                'valid_count' => $countTagDepartment,
                'total_count' => $totalHosts,
                'status' => $getStatus($pctDeptTag),
                'non_compliant' => array_slice($nonCompliantDeptTag, 0, 10)
            ],
            'inventory_fill' => [
                'id' => 'kpi_inventory',
                'title' => $isPt ? 'Preenchimento de Inventário' : 'Inventory Coverage',
                'description' => $isPt 
                    ? 'Hosts com dados de inventário (S.O. ou N/S) preenchidos.' 
                    : 'Hosts with basic inventory data (O.S. or Serial No) filled.',
                'score' => $pctInventory,
                'valid_count' => $countInventoryComplete,
                'total_count' => $totalHosts,
                'status' => $getStatus($pctInventory),
                'non_compliant' => array_slice($nonCompliantInventory, 0, 10)
            ],
            'template_binding' => [
                'id' => 'kpi_templates',
                'title' => $isPt ? 'Vínculo de Templates' : 'Template Linkage',
                'description' => $isPt 
                    ? 'Porcentagem de hosts vinculados a pelo menos um template.' 
                    : 'Percentage of hosts linked to at least one template.',
                'score' => $pctTemplate,
                'valid_count' => $countTemplateBound,
                'total_count' => $totalHosts,
                'status' => $getStatus($pctTemplate),
                'non_compliant' => array_slice($nonCompliantTemplate, 0, 10)
            ]
        ];

        // Monta a resposta do Controller
        $this->setResponse(new CControllerResponseData([
            'is_pt' => $isPt,
            'page_title' => $isPt ? 'Qualidade da Governança' : 'Governance Quality',
            'total_hosts' => $totalHosts,
            'overall_score' => $overallScore,
            'kpis' => $kpis
        ]));
    }
}
