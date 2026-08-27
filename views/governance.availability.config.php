<?php
$base = 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/assets/';
$this->addCssFile($base . 'css/governance.css?v=1.5.0');
$this->addCssFile($base . 'css/availability.css?v=1.5.0');
$this->includeJsFile('governance.availability.config.js.php');
$pt = $data['is_pt'];
$t = static function($ptText, $enText) use ($pt) { return $pt ? $ptText : $enText; };
$e = static function($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
ob_start();
?>
<div class="gov-container gav <?= !empty($data['is_dark']) ? 'gov-theme-dark' : '' ?>" id="gav-config" data-lang="<?= $pt ? 'pt' : 'en' ?>">
    <div class="gav-toolbar">
        <div><span class="gav-eyebrow"><?= $t('GOVERNANÇA / DISPONIBILIDADE', 'GOVERNANCE / AVAILABILITY') ?></span>
            <h2><?= $t('Defina como cada serviço é medido', 'Define how each service is measured') ?></h2></div>
        <a class="btn-alt" href="zabbix.php?action=governance.availability.view"><?= $t('Voltar ao painel', 'Back to dashboard') ?></a>
    </div>
    <div class="gav-notice">
        <?= $t('Mês em regime 24×7. Quedas de host e serviço são unidas, sem duplicar sobreposições. Grupo por nome inclui subgrupos; ID seleciona apenas o grupo exato.',
            '24×7 calendar month. Host and service outages are combined without double counting. Group names include subgroups; IDs select only the exact group.') ?>
        <br><?= $t('A seleção usa os hosts e grupos cadastrados hoje, inclusive hosts desabilitados com histórico. Manutenções não são excluídas automaticamente. Alterar regras recalcula os meses consultados.',
            'Selection uses current hosts and groups, including disabled hosts with history. Maintenance is not automatically excluded. Changing rules recalculates queried months.') ?>
    </div>
    <label class="gav-field gav-timezone"><span><?= $t('Fuso do relatório (IANA)', 'Report time zone (IANA)') ?></span>
        <input id="gav-timezone" required maxlength="80" list="gav-timezones" value="<?= $e($data['config']['timezone']) ?>">
    </label>
    <datalist id="gav-timezones"><option value="America/Cuiaba"><option value="America/Sao_Paulo"><option value="America/Manaus"><option value="UTC"><option value="Europe/Lisbon"></datalist>
    <p class="gav-muted"><?= $t('Hierarquia: departamento → tecnologias → servidores → verificações. Pesos se aplicam entre tecnologias do mesmo departamento.',
        'Hierarchy: department → technologies → hosts → checks. Weights apply to technologies within the same department.') ?></p>
    <div id="gav-departments"></div>
    <p id="gav-config-empty" class="gav-empty" hidden><?= $t('Adicione o primeiro departamento e configure suas tecnologias.', 'Add the first department and configure its technologies.') ?></p>
    <div class="gav-toolbar">
        <button type="button" id="gav-add-department" disabled><?= $t('Adicionar departamento', 'Add department') ?></button>
        <button type="submit" id="gav-save" disabled><?= $t('Salvar regras', 'Save rules') ?></button>
    </div>
    <p class="gav-muted"><?= $t('Sem dados, item ausente, valor ambíguo ou amostra expirada resultam em desconhecido. Um resultado com lacunas é exibido como incompleto, nunca como 100%.',
        'No data, missing items, ambiguous values or expired samples produce an unknown state. Results with gaps are incomplete, never assumed to be 100%.') ?></p>
    <p id="gav-config-status" role="status"><?= $t('Carregando editor… Se esta mensagem persistir, confira o carregamento do JavaScript.', 'Loading editor… If this message persists, check JavaScript loading.') ?></p>
    <input type="hidden" name="availability_json" id="gav-payload" value="">
    <input type="hidden" name="config_revision" value="<?= $e($data['revision']) ?>">
    <script type="application/json" id="gav-config-data"><?= json_encode($data['config'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</div>
<?php
$form = (new CForm())->setId('gav-config-form')
    ->setAction('zabbix.php?action=governance.availability.save')
    ->addItem(new CObject(ob_get_clean()));
(new CWidget())->setTitle($data['page_title'])->addItem($form)->show();
