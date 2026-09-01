<?php
$base = 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/assets/';
$this->addCssFile($base . 'css/governance.css?v=1.13.0');
$this->addCssFile($base . 'css/availability.css?v=1.13.0');
$this->includeJsFile('governance.availability.config.js.php');
$pt = $data['is_pt'];
$t = static function($ptText, $enText) use ($pt) { return $pt ? $ptText : $enText; };
$e = static function($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$dataPolicy = array_key_exists('data_policy', $data['config']) ? $data['config']['data_policy'] : 'strict';
ob_start();
?>
<div class="gov-container gav <?= !empty($data['is_dark']) ? 'gov-theme-dark' : '' ?>" id="gav-config" data-lang="<?= $pt ? 'pt' : 'en' ?>">
    <div class="gav-toolbar gav-page-heading">
        <div><span class="gav-eyebrow"><?= $t('GOVERNANÇA / DISPONIBILIDADE', 'GOVERNANCE / AVAILABILITY') ?></span>
            <h2><?= $t('Regras dos indicadores', 'Indicator rules') ?></h2>
            <p class="gav-muted"><?= $t('Defina os serviços, seus pesos e a fonte de disponibilidade: histórico de itens ou SLA nativo mensal.', 'Define services, their weights and their availability source: item history or native monthly SLA.') ?></p></div>
        <a class="btn-alt" href="zabbix.php?action=governance.availability.view"><?= $t('Voltar ao painel', 'Back to dashboard') ?></a>
    </div>
    <section class="gav-report-settings">
        <label class="gav-field gav-timezone"><span><?= $t('Fuso horário do relatório', 'Report time zone') ?></span>
            <input type="text" id="gav-timezone" required maxlength="80" list="gav-timezones" value="<?= $e($data['config']['timezone']) ?>" aria-describedby="gav-timezone-help">
            <small id="gav-timezone-help"><?= $t('Define os limites mensais da fonte por itens. Com SLA, alinhe este fuso ao do SLA para uma média departamental comparável.', 'Defines monthly boundaries for the item source. With SLA, align this time zone with the SLA for a comparable departmental mean.') ?></small>
        </label>
        <div class="gav-setting-context"><strong><?= $t('Calendário por fonte', 'Calendar by source') ?></strong><p class="gav-muted"><?= $t('Itens usam calendário 24×7; SLA segue seu próprio calendário, fuso e exclusões. Os pesos do módulo se aplicam entre tecnologias do mesmo departamento.', 'Items use a 24×7 calendar; SLA follows its own schedule, time zone and exclusions. Module weights apply to technologies in the same department.') ?></p></div>
    </section>
    <section class="gav-report-settings">
        <label class="gav-field gav-timezone"><span><?= $t('Tratamento de dados ausentes (itens)', 'Missing data policy (items)') ?></span>
            <select id="gav-data-policy" required aria-describedby="gav-data-policy-help gav-data-policy-scope">
                <?php if (!in_array($dataPolicy, ['strict', 'observed'], true)): ?><option value="" selected disabled><?= $t('Selecione uma política válida', 'Select a valid policy') ?></option><?php endif ?>
                <option value="strict"<?= $dataPolicy === 'strict' ? ' selected' : '' ?>><?= $t('Exigir cobertura completa', 'Require complete coverage') ?></option>
                <option value="observed"<?= $dataPolicy === 'observed' ? ' selected' : '' ?>><?= $t('Calcular sobre dados disponíveis', 'Calculate from available data') ?></option>
            </select>
            <small id="gav-data-policy-scope"><?= $t('Política global apenas para a fonte por itens. O SLA nativo não é alterado.', 'Global policy for the item source only. Native SLA is not changed.') ?></small>
        </label>
        <div class="gav-setting-context"><strong><?= $t('Critério explícito, cobertura visível', 'Explicit criteria, visible coverage') ?></strong>
            <p class="gav-muted" id="gav-data-policy-help"><?= $t('Exigir cobertura completa mantém o critério atual: lacunas deixam o resultado incompleto. Calcular sobre dados disponíveis exclui períodos e hosts sem evidência de estado; a cobertura permanece visível.', 'Require complete coverage preserves the current criteria: gaps leave the result incomplete. Calculate from available data excludes periods and hosts without state evidence; coverage remains visible.') ?></p>
            <p class="gav-muted"><?= $t('Todas as verificações continuam obrigatórias em cada host. Chaves ausentes ou validade não resolvida ainda exigem atenção. Ausência de dados não vira disponibilidade nem queda.', 'Every check remains required on each host. Missing keys or unresolved validity still require attention. Missing data is not treated as availability or downtime.') ?></p>
        </div>
    </section>
    <datalist id="gav-timezones"><option value="America/Cuiaba"></option><option value="America/Sao_Paulo"></option><option value="America/Manaus"></option><option value="UTC"></option><option value="Europe/Lisbon"></option></datalist>
    <details class="gav-help"><summary><?= $t('Como o cálculo funciona e quais são os limites', 'How the calculation works and its limits') ?></summary>
        <ul>
            <li><?= $t('Na fonte por itens, uma falha de host ou serviço deixa o host indisponível; sobreposições não são contadas duas vezes. Todas as verificações precisam existir em cada host selecionado.', 'With the item source, a host or service failure makes the host unavailable; overlaps are not counted twice. Every check must exist on each selected host.') ?></li>
            <li><?= $t('Na fonte por itens, grupo por nome inclui subgrupos; ID seleciona apenas o grupo exato. São usados os hosts cadastrados hoje, inclusive desabilitados com histórico.', 'With the item source, group names include subgroups; IDs select the exact group. Current hosts are used, including disabled hosts with history.') ?></li>
            <li><?= $t('A janela automática mantém cada amostra real por no mínimo uma hora e considera o intervalo de coleta e o heartbeat de cada item. Um novo valor substitui o anterior imediatamente; após a janela sem nova amostra, o estado fica desconhecido. Se não for possível interpretar a cadência, configure a janela manualmente.', 'The automatic window keeps each real sample for at least one hour and considers each item’s polling interval and heartbeat. A new value replaces the previous one immediately; after the window without a new sample, the state becomes unknown. If cadence cannot be interpreted, set the window manually.') ?></li>
            <li><?= $t('Dentro de cada host: todas as verificações em 1 confirmam disponibilidade; qualquer 0 confirma indisponibilidade; se não houver queda e alguma verificação estiver sem evidência, o host permanece desconhecido.', 'Within each host: all checks at 1 confirm availability; any 0 confirms downtime; if there is no outage and any check lacks evidence, the host remains unknown.') ?></li>
            <li><?= $t('Na fonte por itens, a política estrita exige cobertura completa. A política sobre dados disponíveis calcula apenas sobre estados conhecidos. Em mês encerrado com histórico incompleto, trends horárias podem substituir a fonte inteira quando aumentarem a cobertura: hora mista conta integralmente como DOWN e hora ausente fica UNKNOWN. Manutenções não são descontadas.', 'With the item source, strict policy requires complete coverage. Available-data policy calculates only from known states. For a closed month with incomplete history, hourly trends may replace the whole source when they increase coverage: a mixed hour counts fully as DOWN and a missing hour remains UNKNOWN. Maintenance is not excluded.') ?></li>
            <li><?= $t('A fonte SLA exige um SLA mensal e um serviço selecionado no relatório nativo. Nesta versão, aceita apenas meses encerrados e usa o calendário, o fuso e as exclusões do SLA. Seu resumo mensal não fornece linha do tempo diária.', 'The SLA source requires a monthly SLA and one service selected in the native report. This version accepts closed months only and uses the SLA schedule, time zone and exclusions. Its monthly summary does not provide a daily timeline.') ?></li>
            <li><?= $t('Os pesos continuam no módulo. Não há substituição automática entre itens e SLA se a fonte escolhida estiver indisponível. Alterar regras pode mudar meses anteriores; esta versão não realiza fechamento imutável.', 'Weights remain in the module. There is no automatic fallback between items and SLA if the selected source is unavailable. Rule changes may affect previous months; this version does not create immutable monthly closes.') ?></li>
            <li><?= $t('Trocar a fonte preserva os dois rascunhos enquanto você edita. Ao salvar, somente os campos da fonte selecionada são guardados. Mantenha uma cópia das regras anteriores se desejar voltar a elas.', 'Switching sources keeps both drafts while editing. Saving stores only the selected source fields. Keep a copy of previous rules if you want to restore them later.') ?></li>
        </ul>
    </details>
    <div class="gav-notice" id="gav-legacy-notice" hidden>
        <?= $t('As janelas antigas foram mantidas como manuais. Para aplicar o mínimo de uma hora ao ICMP e usar o heartbeat do PostgreSQL, selecione “Automática” em cada verificação e salve.', 'Existing windows were preserved as manual. To apply the one-hour minimum to ICMP and use the PostgreSQL heartbeat, select “Automatic” on each check and save.') ?>
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
