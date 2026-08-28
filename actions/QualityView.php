<?php

namespace Modules\Governance\Actions;

use API;
use CController;
use CControllerResponseData;
use CWebUser;
use Modules\Governance\GovernanceConfig;

class QualityView extends CController {

    protected function init(): void {
        $this->disableSIDvalidation();
    }

    protected function checkPermissions(): bool {
        return ($this->getUserType() == USER_TYPE_SUPER_ADMIN);
    }

    protected function checkInput(): bool {
        return $this->validateInput([
            'groupids' => 'array_db hstgrp.groupid',
            'page' => 'string'
        ]);
    }

    protected function doAction(): void {
        $isPt = (strpos(strtolower(CWebUser::getLang()), 'pt') === 0);
        $isDark = (strpos(strtolower(getUserTheme(CWebUser::$data)), 'dark') !== false);
        $groupids = $this->getInput('groupids', []);

        $pages = $this->loadPages();
        $selected = $pages[0] ?? ['id' => '', 'name' => '', 'cards' => []];
        $requestedPage = $this->getInput('page', '');
        foreach ($pages as $page) {
            if ($page['id'] === $requestedPage) {
                $selected = $page;
                break;
            }
        }
        $cards = $selected['cards'];
        $needsTags = (bool) array_filter($cards, static function(array $card): bool {
            return $card['type'] === 'tag';
        });
        $needsInventory = (bool) array_filter($cards, static function(array $card): bool {
            return $card['type'] === 'inventory';
        });
        $needsTemplates = (bool) array_filter($cards, static function(array $card): bool {
            return $card['type'] === 'templates';
        });
        $needsGroups = (bool) array_filter($cards, static function(array $card): bool {
            return $card['type'] === 'hostgroups';
        });
        $options = [
            'output' => ['hostid', 'name', 'status', 'maintenance_status'],
            'selectInterfaces' => ['interfaceid', 'type', 'available', 'useip', 'ip', 'dns'],
            'filter' => ['status' => HOST_STATUS_MONITORED],
            'preservekeys' => true
        ];

        if ($needsTags) {
            $options['selectTags'] = 'extend';
        }
        if ($needsInventory) {
            $options['selectInventory'] = ['os', 'serialno_a', 'location', 'type', 'software'];
        }
        if ($needsTemplates) {
            $options['selectParentTemplates'] = ['templateid', 'name'];
        }
        if ($needsGroups) {
            $options['selectGroups'] = ['groupid', 'name'];
        }
        if ($groupids) {
            $options['groupids'] = $groupids;
        }

        $hosts = API::Host()->get($options);
        $disabledOptions = [
            'countOutput' => true,
            'filter' => ['status' => HOST_STATUS_NOT_MONITORED]
        ];
        if ($groupids) {
            $disabledOptions['groupids'] = $groupids;
        }
        $disabledHosts = (int) API::Host()->get($disabledOptions);
        $maintenanceHosts = 0;
        $availableHosts = 0;
        $unavailableHosts = 0;

        foreach ($hosts as $host) {
            if ((int) ($host['maintenance_status'] ?? HOST_MAINTENANCE_STATUS_OFF)
                    === HOST_MAINTENANCE_STATUS_ON) {
                $maintenanceHosts++;
            }

            $interfaceAvailability = [];
            foreach ($host['interfaces'] ?? [] as $interface) {
                $interfaceAvailability[] = (int) ($interface['available'] ?? INTERFACE_AVAILABLE_UNKNOWN);
            }

            // Mesmo critério conservador do widget nativo Host availability.
            if (in_array(INTERFACE_AVAILABLE_FALSE, $interfaceAvailability, true)) {
                $unavailableHosts++;
            } elseif (in_array(INTERFACE_AVAILABLE_TRUE, $interfaceAvailability, true)) {
                $availableHosts++;
            }
        }

        $totalHosts = count($hosts);
        $kpis = [];
        $scoreValues = [];

        // No hosts is an empty scope, not evidence of 100% compliance.
        foreach ($totalHosts ? $cards : [] as $card) {
            $validCount = 0;
            $nonCompliant = [];

            foreach ($hosts as $host) {
                if ($this->isHostCompliant($host, $card)) {
                    $validCount++;
                } elseif (count($nonCompliant) < 10) {
                    $nonCompliant[] = [
                        'hostid' => $host['hostid'],
                        'name' => $host['name']
                    ];
                }
            }

            $score = round(($validCount / $totalHosts) * 100, 1);

            if ($card['include_score']) {
                $scoreValues[] = $score;
            }

            $kpis[$card['id']] = [
                'id' => 'kpi_' . $card['id'],
                'title' => $card['title'],
                'description' => $card['description'],
                'score' => $score,
                'valid_count' => $validCount,
                'total_count' => $totalHosts,
                'status' => self::getStatus($score),
                'non_compliant' => $nonCompliant
            ];
        }

        $overallScore = $scoreValues
            ? round(array_sum($scoreValues) / count($scoreValues), 1)
            : null;

        $hostids = array_keys($hosts);
        $highProblems = $hostids ? (int) API::Problem()->get([
            'countOutput' => true,
            'hostids' => $hostids,
            'recent' => false,
            'suppressed' => false,
            'severities' => [TRIGGER_SEVERITY_HIGH, TRIGGER_SEVERITY_DISASTER]
        ]) : 0;

        $unsupportedItems = $hostids ? (int) API::Item()->get([
            'countOutput' => true,
            'hostids' => $hostids,
            'monitored' => true,
            'filter' => ['state' => ITEM_STATE_NOTSUPPORTED]
        ]) : 0;

        $this->setResponse(new CControllerResponseData([
            'is_pt' => $isPt,
            'is_dark' => $isDark,
            'page_title' => $isPt ? 'Qualidade do monitoramento' : 'Monitoring quality',
            'pages' => $pages,
            'selected_page' => $selected['id'],
            'page_name' => $selected['name'] !== '' ? $selected['name'] : ($isPt ? 'Qualidade' : 'Quality'),
            'cards_count' => count($cards),
            'groupids' => $groupids,
            'total_hosts' => $totalHosts,
            'overall_score' => $overallScore,
            'overview' => [
                'registered' => $totalHosts + $disabledHosts,
                'monitored' => $totalHosts,
                'disabled' => $disabledHosts,
                'available' => $availableHosts,
                'unavailable' => $unavailableHosts,
                'maintenance' => $maintenanceHosts,
                'high_problems' => $highProblems,
                'unsupported_items' => $unsupportedItems
            ],
            'kpis' => $kpis
        ]));
    }

    private function loadPages(): array {
        $modules = API::Module()->get([
            'output' => ['config'],
            'filter' => ['id' => 'zabbix_module_governance']
        ]);

        return GovernanceConfig::getQualityPages($modules ? $modules[0]['config'] : []);
    }

    private function isHostCompliant(array $host, array $card): bool {
        switch ($card['type']) {
            case 'tag':
                $acceptedNames = GovernanceConfig::splitList($card['tag_names']);
                $acceptedValues = GovernanceConfig::splitList($card['tag_values']);

                foreach ($host['tags'] ?? [] as $tag) {
                    $name = mb_strtolower(trim($tag['tag'] ?? ''), 'UTF-8');
                    $value = mb_strtolower(trim($tag['value'] ?? ''), 'UTF-8');

                    if (in_array($name, $acceptedNames, true)
                            && $value !== ''
                            && (!$acceptedValues || in_array($value, $acceptedValues, true))) {
                        return true;
                    }
                }

                return false;

            case 'inventory':
                foreach (['os', 'serialno_a', 'location', 'type', 'software'] as $field) {
                    if (trim($host['inventory'][$field] ?? '') !== '') {
                        return true;
                    }
                }

                return false;

            case 'hostgroups':
                $acceptedGroups = GovernanceConfig::splitList($card['group_names']);

                foreach ($host['groups'] ?? [] as $group) {
                    $groupId = trim((string) ($group['groupid'] ?? ''));
                    $groupName = mb_strtolower(trim((string) ($group['name'] ?? '')), 'UTF-8');

                    foreach ($acceptedGroups as $acceptedGroup) {
                        $groupPrefix = rtrim($acceptedGroup, '/');

                        if ($groupId === $acceptedGroup
                                || $groupName === $groupPrefix
                                || ($groupPrefix !== ''
                                    && strpos($groupName, $groupPrefix . '/') === 0)) {
                            return true;
                        }
                    }
                }

                return false;

            case 'templates':
                return !empty($host['parentTemplates']);

            case 'interface':
                foreach ($host['interfaces'] ?? [] as $interface) {
                    $address = ((int) ($interface['useip'] ?? 1) === 1)
                        ? trim($interface['ip'] ?? '')
                        : trim($interface['dns'] ?? '');

                    if ($address !== '') {
                        return true;
                    }
                }

                return false;
        }

        return false;
    }

    private static function getStatus(float $percentage): string {
        if ($percentage >= 90.0) {
            return 'good';
        }
        if ($percentage >= 70.0) {
            return 'warning';
        }

        return 'critical';
    }
}
