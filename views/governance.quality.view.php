<?php

/**
 * @var CView $this
 * @var array $data
 */

$moduleWebPath = 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/assets/';
$assetVersion = '?v=1.7.0';
$this->addCssFile($moduleWebPath . 'css/governance.css' . $assetVersion);
$this->addCssFile($moduleWebPath . 'css/quality-pages.css' . $assetVersion);
$this->includeJsFile('governance.quality.view.js.php');

$widget = (new CWidget())
    ->setTitle($data['page_title']);

$isPt = $data['is_pt'];
$overallScore = $data['overall_score'];
$totalHosts = $data['total_hosts'];
$kpis = $data['kpis'];
$overview = $data['overview'];
$pages = $data['pages'];
$selectedPage = $data['selected_page'];
$pageName = $isPt ? 'Qualidade' : 'Quality';
$pageTabs = (new CTag('nav', true))->addClass('gqp-pages')
    ->setAttribute('aria-label', $isPt ? 'Páginas de qualidade' : 'Quality pages');
foreach ($pages as $page) {
    $name = $page['name'] !== '' ? $page['name'] : ($isPt ? 'Qualidade' : 'Quality');
    $url = (new CUrl('zabbix.php'))->setArgument('action', 'governance.quality.view')->setArgument('page', $page['id']);
    if (!empty($data['groupids'])) { $url->setArgument('groupids', $data['groupids']); }
    $link = (new CLink($name, $url->getUrl()))->addClass('gqp-page-link');
    if ($page['id'] === $selectedPage) { $link->setAttribute('aria-current', 'page'); $pageName = $name; }
    $pageTabs->addItem($link);
}
$configUrl = (new CUrl('zabbix.php'))->setArgument('action', 'governance.quality.config')->setArgument('page', $selectedPage)->getUrl();
$pageHeading = (new CDiv([
    (new CDiv([
        new CTag('h2', true, $isPt ? 'Qualidade do monitoramento' : 'Monitoring quality'),
        new CTag('p', true, $isPt ? 'Organize os indicadores em páginas, cada uma com seus próprios cards e índice.' : 'Organize indicators into pages, each with its own cards and score.')
    ]))->addClass('gqp-heading-text'),
    (new CLink($isPt ? 'Configurar páginas e cards' : 'Configure pages and cards', $configUrl))->addClass('btn-alt')
]))->addClass('gqp-page-heading');

// Determina o status geral para o banner principal
$overallStatus = $overallScore === null ? 'neutral' : (($overallScore >= 90) ? 'good' : (($overallScore >= 70) ? 'warning' : 'critical'));

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
            (new CDiv($isPt ? 'Índice da página' : 'Page score'))->addClass('gov-summary-label'),
            (new CDiv($overallScore === null ? '—' : $overallScore . '%'))->addClass('gov-summary-score'),
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
    $noDataMsg = empty($data['cards_count'])
        ? ($isPt ? 'Esta página ainda não possui cards. Abra a configuração para adicionar indicadores.' : 'This page has no cards yet. Open configuration to add indicators.')
        : ($isPt ? 'Nenhum host monitorado encontrado para análise.' : 'No monitored hosts found for analysis.');
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

$cardCount = (int) $data['cards_count'];
$cardCountLabel = $isPt
    ? ($cardCount === 1 ? 'indicador configurado' : 'indicadores configurados')
    : ($cardCount === 1 ? 'configured indicator' : 'configured indicators');
$sectionHeading = (new CDiv([
    (new CTag('h2', true, $pageName)),
    (new CTag('span', true, $cardCount . ' ' . $cardCountLabel))
]))->addClass('gov-section-heading');

// Monta o layout final dentro do widget do Zabbix
$scoreHelpText = $isPt
    ? 'O índice considera somente os cards desta página marcados para participar. O resumo operacional representa os hosts do escopo atual.'
    : 'The score uses only the participating cards on this page. The operational summary represents hosts in the current scope.';
if ($overallScore === null) {
    if ($cardCount === 0) {
        $scoreHelpText = $isPt ? 'Adicione cards para calcular o índice desta página.' : 'Add cards to calculate this page score.';
    }
    elseif ($totalHosts === 0) {
        $scoreHelpText = $isPt ? 'O índice não é calculado sem hosts monitorados no escopo.' : 'The score is not calculated without monitored hosts in scope.';
    }
    else {
        $scoreHelpText = $isPt ? 'Nenhum card desta página está marcado para participar do índice.' : 'No card on this page is marked to participate in the score.';
    }
}
$scoreHelp = (new CTag('p', true, $scoreHelpText))->addClass('gqp-score-help');
$content = (new CDiv([$pageHeading, $pageTabs, $summaryBanner, $scoreHelp, $sectionHeading, $cardsGrid]))->addClass('gov-container')->addClass('gqp');

if (!empty($data['is_dark'])) {
    $content->addClass('gov-theme-dark');
}

$widget->addItem($content)->show();
