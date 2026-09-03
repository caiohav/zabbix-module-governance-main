<?php

/** @var CView $this */
/** @var array $data */
$moduleWebPath = 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/assets/';
$this->addCssFile($moduleWebPath . 'css/governance.css?v=1.7.0');
$this->addCssFile($moduleWebPath . 'css/quality-pages.css?v=1.13.2');
$this->addCssFile($moduleWebPath . 'css/native-layout.css?v=1.18.0');
$this->includeJsFile('governance.quality.config.js.php');

$pt = $data['is_pt'];
$t = static function($ptText, $enText) use ($pt) { return $pt ? $ptText : $enText; };
$e = static function($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$selectedPage = (string) ($data['selected_page'] ?? 'main');
$dashboardUrl = 'zabbix.php?action=governance.quality.view&page=' . rawurlencode($selectedPage);
$draftBackup = is_string($data['draft_json'] ?? null)
    ? $data['draft_json'] : json_encode($data['pages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
ob_start();
?>
<div class="gov-container gqp gqp-editor <?= !empty($data['is_dark']) ? 'gov-theme-dark' : '' ?>" id="gov-config" data-lang="<?= $pt ? 'pt' : 'en' ?>" data-conflict="<?= !empty($data['conflict']) ? '1' : '0' ?>">
    <div class="gqp-heading">
        <div><span class="gqp-eyebrow"><?= $t('GOVERNANÇA / QUALIDADE', 'GOVERNANCE / QUALITY') ?></span>
            <h2><?= $t('Páginas e cards', 'Pages and cards') ?></h2>
            <p class="gqp-muted"><?= $t('Organize suas métricas em páginas nomeáveis. Cada página tem seus próprios cards e índice de qualidade.', 'Organize metrics into named pages. Each page has its own cards and quality score.') ?></p></div>
        <a class="btn-alt" href="<?= $e($dashboardUrl) ?>"><?= $t('Voltar ao painel', 'Back to dashboard') ?></a>
    </div>
    <?php if (!empty($data['conflict'])): ?>
    <div class="gqp-notice gqp-error" role="alert">
        <?= $t('Outra sessão alterou as páginas. Seu rascunho foi preservado e não pode sobrescrever a versão atual. Copie as alterações que deseja manter antes de recarregar.', 'Another session changed the pages. Your draft was preserved and cannot overwrite the current version. Copy any changes you want to keep before reloading.') ?>
        <a href="zabbix.php?action=governance.quality.config"><?= $t('Recarregar regras salvas', 'Reload saved rules') ?></a>
    </div>
    <?php endif ?>
    <details class="gqp-help"><summary><?= $t('Como configurar suas métricas', 'How to configure your metrics') ?></summary>
        <ul>
            <li><?= $t('Selecione os hosts na tabela de condições usando Todas (E) ou Qualquer (OU). Depois escolha o que medir nesses hosts. O botão Testar usa o rascunho sem salvar e mostra até 50 hosts; o total considera todos os selecionados.', 'Select hosts in the conditions table using All (AND) or Any (OR). Then choose what to measure in those hosts. Test uses the unsaved draft and shows up to 50 hosts; the total includes all selected hosts.') ?></li>
            <li><?= $t('Use as abas para separar os assuntos. Renomear uma página mantém seus cards e seu endereço; trocar de aba preserva as alterações ainda não salvas.', 'Use tabs to separate topics. Renaming a page keeps its cards and address; switching tabs preserves unsaved edits.') ?></li>
            <li><?= $t('Tags: informe nomes ou aliases separados por vírgula. Sem valores aceitos, qualquer valor não vazio é considerado conforme.', 'Tags: enter names or aliases separated by commas. Without accepted values, any non-empty value is considered compliant.') ?></li>
            <li><?= $t('Grupos: um nome de grupo pai inclui seus subgrupos. Exemplo: Equipes inclui Equipes/Banco de Dados. Um ID seleciona apenas o grupo exato.', 'Groups: a parent group name includes its subgroups. For example, Teams includes Teams/Database. An ID selects only the exact group.') ?></li>
            <li><?= $t('O índice de cada página usa somente os cards marcados para participar dele. Você pode deixar uma página sem cards e preenchê-la depois.', 'Each page score uses only cards marked for inclusion. You can leave a page empty and add cards later.') ?></li>
        </ul>
    </details>
    <div class="gqp-pages-toolbar">
        <nav class="gqp-pages" id="gov-config-pages" role="tablist" aria-label="<?= $t('Páginas de qualidade', 'Quality pages') ?>"></nav>
        <button type="button" id="gov-add-page" class="btn-alt" disabled><?= $t('Adicionar página', 'Add page') ?></button>
    </div>
    <div id="gov-config-panels"></div>
    <div id="gov-config-empty" class="gqp-empty" hidden>
        <h3><?= $t('Crie a primeira página', 'Create your first page') ?></h3>
        <p class="gqp-muted"><?= $t('Use “Adicionar página” para começar a organizar seus cards.', 'Use “Add page” to start organizing your cards.') ?></p>
    </div>
    <p id="gov-config-error" class="gqp-notice gqp-error" role="alert" hidden></p>
    <details id="gov-draft-backup" class="gqp-help gqp-draft-backup" <?= empty($data['conflict']) ? 'hidden' : '' ?>>
        <summary><?= $t('Cópia do rascunho (JSON)', 'Draft copy (JSON)') ?></summary>
        <label class="gqp-field"><span class="gqp-field-label"><?= $t('Copie este texto antes de recarregar se deseja preservar seu rascunho.', 'Copy this text before reloading if you want to keep your draft.') ?></span>
            <textarea id="gov-draft-copy" readonly rows="7" spellcheck="false"><?= $e($draftBackup) ?></textarea>
        </label>
    </details>
    <div class="gqp-editor-footer">
        <button type="button" id="gov-add-card" class="btn-alt" disabled><?= $t('Adicionar card', 'Add card') ?></button>
        <p id="gov-config-status" role="status" aria-live="polite"><?= $t('Carregando editor…', 'Loading editor…') ?></p>
        <button type="submit" id="gov-save" disabled><?= $t('Salvar todas as páginas', 'Save all pages') ?></button>
    </div>
    <noscript><p class="gqp-notice gqp-error"><?= $t('Ative o JavaScript para editar as páginas. Nenhuma configuração foi alterada.', 'Enable JavaScript to edit pages. No configuration was changed.') ?></p></noscript>
    <input type="hidden" id="gov-quality-payload" name="quality_json" value="">
    <input type="hidden" name="quality_revision" value="<?= $e($data['revision']) ?>">
    <input type="hidden" id="gov-quality-page" name="page" value="<?= $e($selectedPage) ?>">
    <script type="application/json" id="gov-quality-data"><?= json_encode($data['pages'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</div>
<?php
$form = (new CForm())->setId('gov-config-form')
    ->setAction('zabbix.php?action=governance.quality.config.update')
    ->addItem(new CObject(ob_get_clean()));
(new CWidget())->setTitle($data['page_title'])->addItem($form)->show();
