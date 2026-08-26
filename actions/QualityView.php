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
        // No Zabbix 6.0 a proteção de ações é chamada de validação de SID.
        // disableCsrfValidation() só existe em versões posteriores.
        $this->disableSIDvalidation();
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
        $userTheme = CWebUser::$data['theme'] ?? '';
        $isDark = (strpos(strtolower($userTheme), 'dark') !== false);

        // Captura filtros opcionais
        $groupids = $this->getInput('groupids', []);

        // Opções de busca na API de Hosts do Zabbix
        $options = [
            'output' => ['hostid', 'name', 'status'],
            'selectTags' => 'extend',
            'selectInventory' => ['os', 'serialno_a', 'location', 'type', 'software'],
            'selectParentTemplates' => ['templateid', 'name'],
            'selectInterfaces' => ['interfaceid', 'useip', 'ip', 'dns'],
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
                'is_dark' => $isDark,
                'page_title' => $isPt ? 'Qualidade de Governança' : 'Governance Quality',
                'total_hosts' => 0,
                'overall_score' => 100,
                'kpis' => []
            ]));
            return;
        }

        // --- Contadores de Conformidade ---
        $countTagDepartment = 0;
        $countTagEnvironment = 0;
        $countTagOwner = 0;
        $countInventoryComplete = 0;
        $countTemplateBound = 0;
        $countInterfaceConfigured = 0;

        $nonCompliantDeptTag = [];
        $nonCompliantEnvironmentTag = [];
        $nonCompliantOwnerTag = [];
        $nonCompliantInventory = [];
        $nonCompliantTemplate = [];
        $nonCompliantInterface = [];

        // Uma tag só é considerada válida quando também possui valor.
        $hasTag = static function(array $tags, array $acceptedNames): bool {
            foreach ($tags as $tag) {
                $tagName = strtolower(trim($tag['tag'] ?? ''));
                $tagValue = trim($tag['value'] ?? '');

                if (in_array($tagName, $acceptedNames, true) && $tagValue !== '') {
                    return true;
                }
            }

            return false;
        };

        foreach ($hosts as $hostid => $host) {
            // Não dependa de preservekeys para obter o ID correto do host.
            $hostid = $host['hostid'];
            // 1. KPI: Tag de Departamento.
            $hasDeptTag = $hasTag($host['tags'] ?? [], ['department', 'departamento', 'dept']);

            if ($hasDeptTag) {
                $countTagDepartment++;
            } else {
                $nonCompliantDeptTag[] = ['hostid' => $hostid, 'name' => $host['name']];
            }

            // 2. KPI: Tag de Ambiente.
            $hasEnvironmentTag = $hasTag($host['tags'] ?? [], ['environment', 'ambiente', 'env']);

            if ($hasEnvironmentTag) {
                $countTagEnvironment++;
            } else {
                $nonCompliantEnvironmentTag[] = ['hostid' => $hostid, 'name' => $host['name']];
            }

            // 3. KPI: Tag de responsável ou equipe.
            $hasOwnerTag = $hasTag(
                $host['tags'] ?? [],
                ['owner', 'responsavel', 'responsável', 'responsible', 'team', 'equipe']
            );

            if ($hasOwnerTag) {
                $countTagOwner++;
            } else {
                $nonCompliantOwnerTag[] = ['hostid' => $hostid, 'name' => $host['name']];
            }

            // 4. KPI: Preenchimento de Inventário.
            $hasInventory = false;
            if (!empty($host['inventory']) && is_array($host['inventory'])) {
                foreach (['os', 'serialno_a', 'location', 'type', 'software'] as $inventoryField) {
                    if (trim($host['inventory'][$inventoryField] ?? '') !== '') {
                        $hasInventory = true;
                        break;
                    }
                }
            }

            if ($hasInventory) {
                $countInventoryComplete++;
            } else {
                $nonCompliantInventory[] = ['hostid' => $hostid, 'name' => $host['name']];
            }

            // 5. KPI: Vínculo de Templates.
            $hasTemplate = !empty($host['parentTemplates']);
            if ($hasTemplate) {
                $countTemplateBound++;
            } else {
                $nonCompliantTemplate[] = ['hostid' => $hostid, 'name' => $host['name']];
            }

            // 6. KPI: Pelo menos uma interface com IP ou DNS preenchido.
            $hasInterface = false;
            foreach ($host['interfaces'] ?? [] as $interface) {
                $address = ((int) ($interface['useip'] ?? 1) === 1)
                    ? trim($interface['ip'] ?? '')
                    : trim($interface['dns'] ?? '');

                if ($address !== '') {
                    $hasInterface = true;
                    break;
                }
            }

            if ($hasInterface) {
                $countInterfaceConfigured++;
            } else {
                $nonCompliantInterface[] = ['hostid' => $hostid, 'name' => $host['name']];
            }
        }

        // --- Cálculos de Porcentagem e Score Geral ---
        $pctDeptTag = round(($countTagDepartment / $totalHosts) * 100, 1);
        $pctEnvironmentTag = round(($countTagEnvironment / $totalHosts) * 100, 1);
        $pctOwnerTag = round(($countTagOwner / $totalHosts) * 100, 1);
        $pctInventory = round(($countInventoryComplete / $totalHosts) * 100, 1);
        $pctTemplate = round(($countTemplateBound / $totalHosts) * 100, 1);
        $pctInterface = round(($countInterfaceConfigured / $totalHosts) * 100, 1);

        $overallScore = round(
            ($pctDeptTag + $pctEnvironmentTag + $pctOwnerTag + $pctInventory + $pctTemplate + $pctInterface) / 6,
            1
        );

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
            'tag_environment' => [
                'id' => 'kpi_tag_environment',
                'title' => $isPt ? 'Tag de Ambiente' : 'Environment Tag',
                'description' => $isPt
                    ? 'Hosts com tag "ambiente", "environment" ou "env" e valor preenchido.'
                    : 'Hosts with a populated "environment" or "env" tag.',
                'score' => $pctEnvironmentTag,
                'valid_count' => $countTagEnvironment,
                'total_count' => $totalHosts,
                'status' => $getStatus($pctEnvironmentTag),
                'non_compliant' => array_slice($nonCompliantEnvironmentTag, 0, 10)
            ],
            'tag_owner' => [
                'id' => 'kpi_tag_owner',
                'title' => $isPt ? 'Tag de Responsável/Equipe' : 'Owner/Team Tag',
                'description' => $isPt
                    ? 'Hosts com tag de responsável ou equipe e valor preenchido.'
                    : 'Hosts with a populated owner, responsible or team tag.',
                'score' => $pctOwnerTag,
                'valid_count' => $countTagOwner,
                'total_count' => $totalHosts,
                'status' => $getStatus($pctOwnerTag),
                'non_compliant' => array_slice($nonCompliantOwnerTag, 0, 10)
            ],
            'inventory_fill' => [
                'id' => 'kpi_inventory',
                'title' => $isPt ? 'Preenchimento de Inventário' : 'Inventory Coverage',
                'description' => $isPt 
                    ? 'Hosts com ao menos um campo essencial de inventário preenchido.'
                    : 'Hosts with at least one essential inventory field populated.',
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
            ],
            'interface_configured' => [
                'id' => 'kpi_interface',
                'title' => $isPt ? 'Interface Configurada' : 'Configured Interface',
                'description' => $isPt
                    ? 'Hosts com ao menos uma interface contendo IP ou DNS válido.'
                    : 'Hosts with at least one interface containing an IP or DNS address.',
                'score' => $pctInterface,
                'valid_count' => $countInterfaceConfigured,
                'total_count' => $totalHosts,
                'status' => $getStatus($pctInterface),
                'non_compliant' => array_slice($nonCompliantInterface, 0, 10)
            ]
        ];

        // Monta a resposta do Controller
        $this->setResponse(new CControllerResponseData([
            'is_pt' => $isPt,
            'is_dark' => $isDark,
            'page_title' => $isPt ? 'Qualidade da Governança' : 'Governance Quality',
            'total_hosts' => $totalHosts,
            'overall_score' => $overallScore,
            'kpis' => $kpis
        ]));
    }
}
