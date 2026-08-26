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
            'groupids' => 'array_db hstgrp.groupid'
        ]);
    }

    protected function doAction(): void {
        $isPt = (strpos(strtolower(CWebUser::getLang()), 'pt') === 0);
        $isDark = (strpos(strtolower(CWebUser::$data['theme'] ?? ''), 'dark') !== false);
        $groupids = $this->getInput('groupids', []);

        $cards = $this->loadCards();
        $needsTags = (bool) array_filter($cards, static function(array $card): bool {
            return $card['type'] === 'tag';
        });
        $needsInventory = (bool) array_filter($cards, static function(array $card): bool {
            return $card['type'] === 'inventory';
        });
        $needsTemplates = (bool) array_filter($cards, static function(array $card): bool {
            return $card['type'] === 'templates';
        });
        $needsInterfaces = (bool) array_filter($cards, static function(array $card): bool {
            return $card['type'] === 'interface';
        });

        $options = [
            'output' => ['hostid', 'name', 'status'],
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
        if ($needsInterfaces) {
            $options['selectInterfaces'] = ['interfaceid', 'useip', 'ip', 'dns'];
        }
        if ($groupids) {
            $options['groupids'] = $groupids;
        }

        $hosts = API::Host()->get($options);
        $totalHosts = count($hosts);
        $kpis = [];
        $scoreValues = [];

        foreach ($cards as $card) {
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

            $score = ($totalHosts > 0) ? round(($validCount / $totalHosts) * 100, 1) : 100.0;

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
            : 100.0;

        $this->setResponse(new CControllerResponseData([
            'is_pt' => $isPt,
            'is_dark' => $isDark,
            'page_title' => $isPt ? 'Qualidade da Governança' : 'Governance Quality',
            'total_hosts' => $totalHosts,
            'overall_score' => $overallScore,
            'kpis' => $kpis
        ]));
    }

    private function loadCards(): array {
        $modules = API::Module()->get([
            'output' => ['config'],
            'filter' => ['id' => 'zabbix_module_governance']
        ]);

        return GovernanceConfig::normalizeCards($modules ? ($modules[0]['config']['cards'] ?? []) : []);
    }

    private function isHostCompliant(array $host, array $card): bool {
        switch ($card['type']) {
            case 'tag':
                $acceptedNames = GovernanceConfig::splitList($card['tag_names']);
                $acceptedValues = GovernanceConfig::splitList($card['tag_values']);

                foreach ($host['tags'] ?? [] as $tag) {
                    $name = strtolower(trim($tag['tag'] ?? ''));
                    $value = strtolower(trim($tag['value'] ?? ''));

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
