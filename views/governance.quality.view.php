<?php

/**
 * @var CView $this
 * @var array $data
 */

$moduleWebPath = 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/assets/';
$assetVersion = '?v=1.1.2';
$this->addCssFile($moduleWebPath . 'css/governance.css' . $assetVersion);
$this->addJsFile($moduleWebPath . 'js/echarts.min.js' . $assetVersion);
$this->addJsFile($moduleWebPath . 'js/quality.js' . $assetVersion);

$widget = (new CWidget())
    ->setTitle($data['page_title']);

$isPt = $data['is_pt'];
$overallScore = $data['overall_score'];
$totalHosts = $data['total_hosts'];
$kpis = $data['kpis'];

// Determina o status geral para o banner principal
$overallStatus = ($overallScore >= 90) ? 'good' : (($overallScore >= 70) ? 'warning' : 'critical');

// Banner de Resumo (Score Geral)
$summaryBanner = (new CDiv())
    ->addClass('gov-summary-banner')
    ->addClass('gov-status-' . $overallStatus)
    ->addItem([
        (new CDiv([
            (new CTag('h2', true, $isPt ? 'Índice Geral de Governança' : 'Overall Governance Score')),
            (new CDiv($overallScore . '%'))->addClass('gov-summary-score')
        ]))->addClass('gov-summary-main'),
        (new CDiv([
            (new CTag('span', true, ($isPt ? 'Total de Hosts Monitorados: ' : 'Total Monitored Hosts: '))),
            (new CTag('strong', true, (string)$totalHosts))
        ]))->addClass('gov-summary-meta')
    ]);

// Grid Container para os Cards de KPI
$cardsGrid = (new CDiv())->addClass('gov-kpi-grid');

if (empty($kpis)) {
    $noDataMsg = $isPt ? 'Nenhum host monitorado encontrado para análise.' : 'No monitored hosts found for analysis.';
    $cardsGrid->addItem((new CDiv($noDataMsg))->addClass('gov-no-data'));
} else {
    foreach ($kpis as $kpi) {
        $cardId = $kpi['id'];
        $status = $kpi['status'];

        // Cabeçalho do Card
        $cardHeader = (new CDiv([
            (new CTag('h3', true, $kpi['title'])),
            (new CTag('p', true, $kpi['description']))->addClass('gov-card-desc')
        ]))->addClass('gov-card-header');

        // Container do indicador circular renderizado pelo Apache ECharts.
        $chartDiv = (new CDiv())
            ->setId('chart-' . $cardId)
            ->addClass('gov-card-chart')
            ->setAttribute('data-score', $kpi['score'])
            ->setAttribute('data-status', $status);

        $scoreDisplay = (new CDiv([
            (new CDiv($kpi['score'] . '%'))->addClass('gov-card-score-value'),
            (new CDiv($kpi['valid_count'] . ' / ' . $kpi['total_count'] . ' ' . ($isPt ? 'em conformidade' : 'compliant')))
                ->addClass('gov-card-score-sub'),
            (new CDiv(round(100 - $kpi['score'], 1) . '% ' . ($isPt ? 'não conformes' : 'non-compliant')))
                ->addClass('gov-card-score-missing')
        ]))->addClass('gov-card-score-box');

        $cardBody = (new CDiv([$chartDiv, $scoreDisplay]))->addClass('gov-card-body');

        // Lista de Exceções (Amostra de Hosts Não Conformes)
        if (!empty($kpi['non_compliant'])) {
            $listItems = [];
            foreach ($kpi['non_compliant'] as $item) {
                // Link direto para a tela nativa de edição do Host no Zabbix
                $hostLink = (new CLink($item['name'], 'zabbix.php?action=host.edit&hostid=' . $item['hostid']))
                    ->setAttribute('target', '_blank')
                    ->setTitle($isPt ? 'Editar Host' : 'Edit Host');

                $listItems[] = (new CTag('li', true, $hostLink));
            }

            $exceptionsDiv = (new CDiv([
                (new CTag('strong', true, $isPt ? 'Amostra de não conformes:' : 'Non-compliant sample:'))->addClass('gov-nc-title'),
                (new CTag('ul', true, $listItems))->addClass('gov-nc-list')
            ]))->addClass('gov-card-exceptions');
        } else {
            $exceptionsDiv = (new CDiv(
                (new CTag('span', true, $isPt ? '100% de conformidade!' : '100% compliant!'))->addClass('gov-nc-success')
            ))->addClass('gov-card-exceptions');
        }

        // Estrutura do Card individual
        $card = (new CDiv([$cardHeader, $cardBody, $exceptionsDiv]))
            ->addClass('gov-kpi-card')
            ->addClass('gov-card-status-' . $status);

        $cardsGrid->addItem($card);
    }
}

// Monta o layout final dentro do widget do Zabbix
$content = (new CDiv([$summaryBanner, $cardsGrid]))->addClass('gov-container');

$widget->addItem($content)->show();
