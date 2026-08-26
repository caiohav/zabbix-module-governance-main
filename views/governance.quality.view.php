<?php

/**
 * @var CView $this
 * @var array $data
 */

$moduleWebPath = 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/assets/';
$assetVersion = '?v=1.3.4';
$this->addCssFile($moduleWebPath . 'css/governance.css' . $assetVersion);
$this->includeJsFile('governance.quality.view.js.php');

$widget = (new CWidget())
    ->setTitle($data['page_title']);

$isPt = $data['is_pt'];
$overallScore = $data['overall_score'];
$totalHosts = $data['total_hosts'];
$kpis = $data['kpis'];
$overview = $data['overview'];

// Determina o status geral para o banner principal
$overallStatus = ($overallScore >= 90) ? 'good' : (($overallScore >= 70) ? 'warning' : 'critical');

// Resumo geral e indicadores operacionais compactos.
$overviewItems = [
    [
        'label' => $isPt ? 'Ativos / monitorados' : 'Active / monitored',
        'value' => $overview['monitored'],
        'hint' => $overview['maintenance'] . ' ' . ($isPt ? 'em manutenção' : 'in maintenance'),
        'status' => 'good'
    ],
    [
        'label' => $isPt ? 'Inativos / desabilitados' : 'Inactive / disabled',
        'value' => $overview['disabled'],
        'hint' => $isPt ? 'Desabilitados' : 'Disabled',
        'status' => 'neutral'
    ],
    [
        'label' => $isPt ? 'Falha de interface' : 'Interface failure',
        'value' => $overview['unavailable'],
        'hint' => $isPt ? 'Hosts afetados' : 'Affected hosts',
        'status' => $overview['unavailable'] > 0 ? 'critical' : 'good'
    ],
    [
        'label' => $isPt ? 'Problemas altos/críticos' : 'High/critical problems',
        'value' => $overview['high_problems'],
        'hint' => $isPt ? 'Abertos e não suprimidos' : 'Open and unsuppressed',
        'status' => $overview['high_problems'] > 0 ? 'critical' : 'good'
    ],
    [
        'label' => $isPt ? 'Itens não suportados' : 'Unsupported items',
        'value' => $overview['unsupported_items'],
        'hint' => $isPt ? 'Itens monitorados' : 'Monitored items',
        'status' => $overview['unsupported_items'] > 0 ? 'warning' : 'good'
    ]
];

$overviewGrid = (new CDiv())->addClass('gov-overview-grid');
foreach ($overviewItems as $item) {
    $overviewGrid->addItem(
        (new CDiv([
            (new CDiv($item['label']))->addClass('gov-overview-label'),
            (new CDiv((string) $item['value']))->addClass('gov-overview-value'),
            (new CDiv($item['hint']))->addClass('gov-overview-hint')
        ]))
            ->addClass('gov-overview-item')
            ->addClass('gov-overview-' . $item['status'])
    );
}

$summaryBanner = (new CDiv())
    ->addClass('gov-summary-banner')
    ->addClass('gov-status-' . $overallStatus)
    ->addItem([
        (new CDiv([
            (new CDiv($isPt ? 'Índice geral' : 'Overall score'))->addClass('gov-summary-label'),
            (new CDiv($overallScore . '%'))->addClass('gov-summary-score'),
            (new CDiv(
                $totalHosts . ' / ' . $overview['registered'] . ' '
                . ($isPt ? 'hosts analisados' : 'hosts analyzed')
            ))->addClass('gov-summary-meta')
        ]))->addClass('gov-summary-main'),
        $overviewGrid
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

            $missingCount = $kpi['total_count'] - $kpi['valid_count'];
            $exceptionsDiv = (new CTag('details', true, [
                new CTag('summary', true,
                    $missingCount . ' ' . ($isPt ? 'não conformes — ver amostra' : 'non-compliant — view sample')
                ),
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

$sectionHeading = (new CDiv([
    (new CTag('h2', true, $isPt ? 'Indicadores de conformidade' : 'Compliance indicators')),
    (new CTag('span', true, count($kpis) . ' ' . ($isPt ? 'regras ativas' : 'active rules')))
]))->addClass('gov-section-heading');

// Monta o layout final dentro do widget do Zabbix
$content = (new CDiv([$summaryBanner, $sectionHeading, $cardsGrid]))->addClass('gov-container');

if (!empty($data['is_dark'])) {
    $content->addClass('gov-theme-dark');
}

$widget->addItem($content)->show();
