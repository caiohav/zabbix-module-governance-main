<?php
$base = 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/assets/';
$this->addCssFile($base . 'css/governance.css?v=1.22.0');
$this->addCssFile($base . 'css/quality-pages.css?v=1.22.0');
$this->addCssFile($base . 'css/native-layout.css?v=1.22.0');
$this->includeJsFile('governance.quality.view.js.php');
$pt = $data['is_pt'];
$t = static function($a, $b) use ($pt) { return $pt ? $a : $b; };
$e = static function($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$url = static function(string $action, string $page, array $groups = []): string {
    $args = ['action' => $action, 'page' => $page];
    if ($groups) { $args['groupids'] = $groups; }
    return 'zabbix.php?' . http_build_query($args);
};
$metrics = [
    'monitored' => $t('Ativos / monitorados', 'Active / monitored'),
    'disabled' => $t('Inativos / desabilitados', 'Inactive / disabled'),
    'unavailable' => $t('Falha de interface', 'Interface failure'),
    'high_problems' => $t('Problemas altos/críticos', 'High/critical problems'),
    'unsupported_items' => $t('Itens não suportados', 'Unsupported items')
];
ob_start();
?>
<nav class="gov-page-actions <?= !empty($data['is_dark']) ? 'gov-theme-dark' : '' ?>" aria-label="<?= $t('Ações da página', 'Page actions') ?>">
    <a class="btn-alt gov-action-link gqp-config-link" href="<?= $e($url('governance.quality.config', $data['selected_page'])) ?>"><?= $t('Configurar páginas e cards', 'Configure pages and cards') ?></a>
</nav>
<?php
$pageControls = new CObject(ob_get_clean());
ob_start();
?>
<div class="gov-container gqp <?= $data['is_dark'] ? 'gov-theme-dark' : '' ?>" id="gqp-dashboard" data-lang="<?= $pt ? 'pt' : 'en' ?>" data-echarts="<?= $e($base) ?>js/echarts.min.js?v=1.8.0">
    <div class="gqp-pages-toolbar">
        <nav class="gqp-pages" aria-label="<?= $t('Páginas de qualidade', 'Quality pages') ?>">
            <?php foreach ($data['pages'] as $page): ?>
            <a class="gqp-page-link" href="<?= $e($url('governance.quality.view', $page['id'], $data['groupids'])) ?>" <?= $page['id'] === $data['selected_page'] ? 'aria-current="page"' : '' ?>><?= $e($page['name'] !== '' ? $page['name'] : $t('Qualidade', 'Quality')) ?></a>
            <?php endforeach ?>
        </nav>
    </div>
    <?php echo (new CForm())->setId('gqp-token')->setAction('zabbix.php?action=governance.quality.run')->setAttribute('hidden', 'hidden'); ?>
    <section class="gqp-load-panel" aria-label="<?= $t('Carregamento da qualidade', 'Quality loading') ?>">
        <div class="gqp-load-actions"><p id="gqp-message" role="status" aria-live="polite" aria-atomic="true"><?= $e($data['error'] ?? $t('Carregando indicadores… Você pode continuar navegando.', 'Loading indicators… You can continue navigating.')) ?></p>
            <button type="button" id="gqp-retry" hidden><?= $t('Tentar novamente', 'Retry') ?></button>
            <button type="button" id="gqp-refresh" disabled><?= $t('Atualizar', 'Refresh') ?></button></div>
        <div id="gqp-progress-wrap"><progress id="gqp-progress" aria-label="<?= $t('Hosts analisados', 'Hosts analyzed') ?>"></progress><span id="gqp-progress-text"></span></div>
        <details class="gov-diagnostics" id="gqp-diagnostics"><summary><?= $t('Detalhes da consulta', 'Query details') ?></summary>
            <p class="gqp-muted" id="gqp-timing"></p>
            <p><?= $t('Leitura do estado atual ao longo da consulta, não um retrato transacional. As regras e os hosts do início orientam esta análise. Sair interrompe novas etapas, não a consulta já enviada.', 'Current state read over the query interval, not a transactional snapshot. Initial rules and hosts define this analysis. Leaving stops new stages, not an already submitted query.') ?></p>
        </details>
    </section>
    <noscript><p class="gqp-notice gqp-error"><?= $t('Ative o JavaScript para consultar os indicadores. Nenhuma análise é executada ao abrir esta página.', 'Enable JavaScript to load indicators. Opening this page does not run the analysis.') ?></p></noscript>
    <div class="gov-summary-banner gov-status-neutral" id="gqp-summary" aria-busy="true">
        <div class="gov-summary-main"><div class="gov-summary-label"><?= $t('Índice da página', 'Page score') ?></div><div class="gov-summary-score" id="gqp-score">—</div><div class="gov-summary-meta" id="gqp-hosts"><?= $t('Aguardando análise', 'Waiting for analysis') ?></div></div>
        <div class="gov-overview-grid"><?php foreach ($metrics as $key => $label): ?>
            <div class="gov-overview-item gov-overview-neutral" id="gqp-metric-<?= $key ?>" aria-busy="true"><div class="gov-overview-label" title="<?= $e($label) ?>"><?= $label ?></div><div class="gov-overview-value">—</div><div class="gov-overview-hint"><?= $t('Aguardando', 'Waiting') ?></div></div>
        <?php endforeach ?></div>
    </div>
    <p class="gqp-score-help" id="gqp-score-help"><?= $t('O índice será exibido após concluir todos os cards participantes.', 'The score will appear after all participating cards are complete.') ?></p>
    <div class="gov-section-heading"><h2><?= $e($data['page_name'] !== '' ? $data['page_name'] : $t('Qualidade', 'Quality')) ?></h2><span><?= $data['cards_count'] ?> <?= $t('indicadores configurados', 'configured indicators') ?></span></div>
    <div class="gov-kpi-grid" id="gqp-cards" aria-busy="true">
        <?php foreach ($data['cards'] as $card): ?>
        <article class="gov-kpi-card gqp-card-loading" data-card-id="<?= $e($card['id']) ?>"><div class="gov-card-header"><h3 title="<?= $e($card['title']) ?>"><?= $e($card['title']) ?></h3><p class="gov-card-desc" title="<?= $e($card['description']) ?>"><?= $e($card['description']) ?></p></div>
            <div class="gov-card-body"><div class="gov-card-chart gqp-chart-placeholder" role="img" aria-label="<?= $t('Aguardando resultado', 'Waiting for result') ?>">—</div><div class="gov-card-score-box"><div class="gov-card-score-sub"><?= $t('Carregando…', 'Loading…') ?></div><div class="gov-card-score-missing"></div></div></div><div class="gov-card-exceptions"></div></article>
        <?php endforeach ?>
    </div>
    <p class="gov-no-data" id="gqp-empty" <?= $data['cards_count'] ? 'hidden' : '' ?>><?= $t('Esta página ainda não possui cards. Abra a configuração para adicionar indicadores.', 'This page has no cards yet. Open configuration to add indicators.') ?></p>
    <script type="application/json" id="gqp-input"><?= json_encode(['page' => $data['selected_page'], 'revision' => $data['revision'], 'groupids' => array_values($data['groupids']), 'error' => $data['error']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</div>
<?php
(new CWidget())->setTitle($data['page_title'])->setControls($pageControls)->addItem(new CObject(ob_get_clean()))->show();
