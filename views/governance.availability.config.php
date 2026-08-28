<?php
$base = 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/assets/';
$this->addCssFile($base . 'css/governance.css?v=1.7.0');
$this->addCssFile($base . 'css/availability.css?v=1.7.0');
$this->includeJsFile('governance.availability.config.js.php');
$pt = $data['is_pt'];
$t = static function($ptText, $enText) use ($pt) { return $pt ? $ptText : $enText; };
$e = static function($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
ob_start();
?>
<div class="gov-container gav <?= !empty($data['is_dark']) ? 'gov-theme-dark' : '' ?>" id="gav-config" data-lang="<?= $pt ? 'pt' : 'en' ?>">
    <div class="gav-toolbar gav-page-heading">
        <div><span class="gav-eyebrow"><?= $t('GOVERNANÇA / DISPONIBILIDADE', 'GOVERNANCE / AVAILABILITY') ?></span>
            <h2><?= $t('Regras dos indicadores', 'Indicator rules') ?></h2>
            <p class="gav-muted"><?= $t('Defina os serviços, seus pesos e como cada item representa disponibilidade.', 'Define services, their weights and how each item represents availability.') ?></p></div>
        <a class="btn-alt" href="zabbix.php?action=governance.availability.view"><?= $t('Voltar ao painel', 'Back to dashboard') ?></a>
    </div>
    <section class="gav-report-settings">
        <label class="gav-field gav-timezone"><span><?= $t('Fuso horário do relatório', 'Report time zone') ?></span>
            <input type="text" id="gav-timezone" required maxlength="80" list="gav-timezones" value="<?= $e($data['config']['timezone']) ?>" aria-describedby="gav-timezone-help">
            <small id="gav-timezone-help"><?= $t('Define o início e o fim de cada mês.', 'Defines the beginning and end of each month.') ?></small>
        </label>
        <div class="gav-setting-context"><strong><?= $t('Calendário 24×7', '24×7 calendar') ?></strong><p class="gav-muted"><?= $t('Departamento → tecnologias → hosts → itens. Pesos se aplicam entre tecnologias do mesmo departamento.', 'Department → technologies → hosts → items. Weights apply to technologies in the same department.') ?></p></div>
    </section>
    <datalist id="gav-timezones"><option value="America/Cuiaba"></option><option value="America/Sao_Paulo"></option><option value="America/Manaus"></option><option value="UTC"></option><option value="Europe/Lisbon"></option></datalist>
    <details class="gav-help"><summary><?= $t('Como o cálculo funciona e quais são os limites', 'How the calculation works and its limits') ?></summary>
        <ul>
            <li><?= $t('Uma falha de host ou serviço deixa o host indisponível; sobreposições não são contadas duas vezes. Todas as verificações precisam existir em cada host selecionado.', 'A host or service failure makes the host unavailable; overlaps are not counted twice. Every check must exist on each selected host.') ?></li>
            <li><?= $t('Grupo por nome inclui subgrupos; ID seleciona apenas o grupo exato. São usados os hosts cadastrados hoje, inclusive desabilitados com histórico.', 'Group names include subgroups; IDs select the exact group. Current hosts are used, including disabled hosts with history.') ?></li>
            <li><?= $t('A validade automática considera o intervalo de coleta e o heartbeat de cada item. Se não for possível interpretar a cadência, configure a validade manualmente.', 'Automatic validity considers the collection interval and heartbeat of each item. If the cadence cannot be interpreted, set validity manually.') ?></li>
            <li><?= $t('Sem histórico ou amostras válidas, o resultado fica incompleto. É necessário reter o histórico bruto de todo o mês; trends não o substituem.', 'Without history or valid samples, the result is incomplete. Raw history must be retained for the entire month; trends do not replace it.') ?></li>
            <li><?= $t('Manutenções não são descontadas. Alterar regras recalcula meses anteriores; esta versão não realiza fechamento imutável.', 'Maintenance is not excluded. Rule changes recalculate previous months; this version does not create immutable monthly closes.') ?></li>
        </ul>
    </details>
    <div class="gav-notice" id="gav-legacy-notice" hidden>
        <?= $t('As validades antigas foram mantidas como manuais. Para usar o heartbeat do PostgreSQL sem afetar o ICMP, selecione “Automática por item” em cada verificação e salve.', 'Existing validity settings were preserved as manual. To use the PostgreSQL heartbeat without affecting ICMP, select “Automatic per item” on each check and save.') ?>
    </div>
    <?php if (!empty($data['conflict'])): ?>
    <div class="gav-notice gav-error" role="alert">
        <?= $t('Outra sessão alterou as regras. Seu rascunho foi preservado, mas não pode sobrescrever a versão atual. Copie as alterações que deseja manter antes de recarregar.', 'Another session changed the rules. Your draft was preserved, but cannot overwrite the current version. Copy any changes you want to keep before reloading.') ?>
        <a href="zabbix.php?action=governance.availability.config"><?= $t('Recarregar regras salvas', 'Reload saved rules') ?></a>
    </div>
    <?php endif ?>
    <div class="gav-toolbar gav-builder-heading"><h3><?= $t('Departamentos e tecnologias', 'Departments and technologies') ?></h3><span class="gav-muted" id="gav-config-count"></span></div>
    <div id="gav-departments"></div>
    <div id="gav-config-empty" class="gav-empty" hidden><h3><?= $t('Crie o primeiro departamento', 'Create the first department') ?></h3>
        <p><?= $t('Exemplo: Banco de Dados, com PostgreSQL (peso 4), SQL Server (2) e Qlik Sense (1).', 'Example: Database, with PostgreSQL (weight 4), SQL Server (2) and Qlik Sense (1).') ?></p></div>
    <div class="gav-editor-footer">
        <button type="button" id="gav-add-department" class="btn-alt" disabled><?= $t('Adicionar departamento', 'Add department') ?></button>
        <p id="gav-config-status" role="status"><?= $t('Carregando editor…', 'Loading editor…') ?></p>
        <button type="submit" id="gav-save" disabled><?= $t('Salvar regras', 'Save rules') ?></button>
    </div>
    <noscript><p class="gav-notice gav-error"><?= $t('Ative o JavaScript para editar as regras. Nenhuma configuração foi alterada.', 'Enable JavaScript to edit rules. No configuration was changed.') ?></p></noscript>
    <input type="hidden" name="availability_json" id="gav-payload" value="">
    <input type="hidden" name="config_revision" value="<?= $e($data['revision']) ?>">
    <script type="application/json" id="gav-config-data"><?= json_encode($data['config'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</div>
<?php
$form = (new CForm())->setId('gav-config-form')
    ->setAction('zabbix.php?action=governance.availability.save')
    ->addItem(new CObject(ob_get_clean()));
(new CWidget())->setTitle($data['page_title'])->addItem($form)->show();
