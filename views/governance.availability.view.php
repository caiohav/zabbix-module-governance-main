<?php
$base = 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/assets/';
$this->addCssFile($base . 'css/governance.css?v=1.5.0');
$this->addCssFile($base . 'css/availability.css?v=1.5.0');
$this->includeJsFile('governance.availability.view.js.php');
$pt = $data['is_pt'];
$t = static function($a, $b) use ($pt) { return $pt ? $a : $b; };
$e = static function($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$percent = static function($value) use ($pt) {
    if ($value === null) { return '—'; }
    if ($value < 100 && round($value, 6) >= 100) { return '<100%'; }
    return number_format($value, 6, $pt ? ',' : '.', '') . '%';
};
$duration = static function($value) {
    $seconds = (int) floor($value);
    return sprintf('%dh %02dm %02ds', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
};
$status = static function($summary, $target) {
    return $summary['score'] === null ? 'unknown' : ($summary['score'] >= $target ? 'good' : 'bad');
};
$report = $data['report'];
$timezone = new DateTimeZone($data['config']['timezone']);
$date = static function($clock) use ($timezone) {
    return (new DateTimeImmutable('@' . $clock))->setTimezone($timezone)->format('d/m/Y H:i:s');
};
ob_start();
?>
<div class="gov-container gav <?= !empty($data['is_dark']) ? 'gov-theme-dark' : '' ?>" id="gav-dashboard" data-lang="<?= $pt ? 'pt' : 'en' ?>">
    <div class="gav-toolbar gav-no-print">
        <div><span class="gav-eyebrow"><?= $t('GOVERNANÇA / INDICADORES MENSAIS', 'GOVERNANCE / MONTHLY INDICATORS') ?></span>
            <h2><?= $t('Disponibilidade dos serviços', 'Service availability') ?></h2></div>
        <a class="btn-alt" href="zabbix.php?action=governance.availability.config"><?= $t('Configurar indicadores', 'Configure indicators') ?></a>
    </div>
    <form method="get" action="zabbix.php" class="gav-filters gav-no-print">
        <input type="hidden" name="action" value="governance.availability.view">
        <label class="gav-field"><span><?= $t('Competência', 'Month') ?></span><input type="month" name="month" required value="<?= $e($data['month']) ?>"></label>
        <label class="gav-field"><span><?= $t('Departamento', 'Department') ?></span><select name="department">
            <option value="-1"><?= $t('Todos os departamentos', 'All departments') ?></option>
            <?php foreach ($data['config']['departments'] as $id => $department): ?>
            <option value="<?= $id ?>" <?= $data['department'] === $id ? 'selected' : '' ?>><?= $e($department['name']) ?></option>
            <?php endforeach ?>
        </select></label>
        <button type="submit"><?= $t('Calcular mês', 'Calculate month') ?></button>
        <?php if ($report): ?>
        <button type="button" id="gav-export" class="btn-alt" disabled><?= $t('Baixar memória de cálculo', 'Download calculation details') ?></button>
        <button type="button" id="gav-print" class="btn-alt" disabled><?= $t('Imprimir / PDF', 'Print / PDF') ?></button>
        <?php endif ?>
    </form>
    <?php if ($data['error']): ?><div class="gav-notice gav-error" role="alert"><?= $e($data['error']) ?></div><?php endif ?>
    <?php if (!$data['config']['departments']): ?>
    <div class="gav-empty"><h3><?= $t('Seu primeiro indicador começa pelas regras', 'Your first indicator starts with its rules') ?></h3>
        <p><?= $t('Cadastre um departamento, suas tecnologias e os itens que representam host e serviço disponíveis.', 'Add a department, its technologies and the items that represent host and service availability.') ?></p>
        <a href="zabbix.php?action=governance.availability.config"><?= $t('Criar indicadores de disponibilidade', 'Create availability indicators') ?></a></div>
    <?php endif ?>
    <?php if ($report): ?>
    <div class="gav-period"><strong><?= $e($report['month']) ?></strong>
        <span><?= $e($report['timezone']) ?> · 24×7 · <?= $e($date($report['from'])) ?> → <?= $e($date($report['to'])) ?></span>
        <span class="gav-badge <?= $report['partial'] ? 'gav-unknown' : '' ?>"><?= $report['partial'] ? $t('Mês em andamento', 'Month in progress') : $t('Mês encerrado · recalculado', 'Past month · recalculated') ?></span>
    </div>
    <div class="gav-notice"><?= $t('Lacunas não viram disponibilidade: resultados incompletos mostram a cobertura e o intervalo possível. A média ponderada representa um índice, não o tempo em que todos os serviços funcionaram juntos.',
        'Data gaps are not counted as availability: incomplete results show coverage and a possible range. The weighted mean is an index, not the time during which all services worked together.') ?></div>
    <?php foreach ($report['departments'] as $di => $department): $ds = $department['summary']; ?>
    <section class="gav-department gav-<?= $status($ds, $department['target']) ?>">
        <div class="gav-dept-header">
            <div><span class="gav-eyebrow"><?= $t('ÍNDICE DO DEPARTAMENTO', 'DEPARTMENT INDEX') ?></span><h2><?= $e($department['name']) ?></h2>
                <span class="gav-muted"><?= $t('Meta', 'Target') ?> <?= $e($percent($department['target'])) ?> · <?= $t('Média ponderada das tecnologias', 'Weighted mean of technologies') ?></span></div>
            <div class="gav-score"><strong><?= $e($percent($ds['score'])) ?></strong><span><?= $ds['score'] === null ? $t('Dados incompletos', 'Incomplete data') : ($ds['score'] >= $department['target'] ? $t('Meta atingida', 'Target met') : $t('Abaixo da meta', 'Below target')) ?></span></div>
        </div>
        <div class="gav-metrics">
            <div><span><?= $t('Cobertura temporal', 'Time coverage') ?></span><strong><?= $e($percent($ds['coverage'])) ?></strong></div>
            <div><span><?= $t('Indisponibilidade ponderada', 'Weighted downtime') ?></span><strong><?= $e($duration($ds['down'])) ?></strong></div>
            <div><span><?= $t('Tempo desconhecido ponderado', 'Weighted unknown time') ?></span><strong><?= $e($duration($ds['unknown'])) ?></strong></div>
            <div><span><?= $t('Faixa possível do índice', 'Possible index range') ?></span><strong><?= $e($percent($ds['lower'])) ?> – <?= $e($percent($ds['upper'])) ?></strong></div>
        </div>
        <div class="gav-chart-header"><h3><?= $t('Interrupções ao longo do mês', 'Interruptions during the month') ?></h3>
            <label class="gav-field gav-no-print"><span><?= $t('Detalhar', 'Show') ?></span><select class="gav-chart-selection" data-department="<?= $di ?>">
                <option value="-1"><?= $t('Departamento (tempo ponderado)', 'Department (weighted time)') ?></option>
                <?php foreach ($department['technologies'] as $ti => $tech): ?><option value="<?= $ti ?>"><?= $e($tech['name']) ?></option><?php endforeach ?>
            </select></label></div>
        <div class="gav-chart" data-department="<?= $di ?>" role="img" aria-label="<?= $t('Minutos diários indisponíveis e desconhecidos', 'Daily unavailable and unknown minutes') ?>">
            <p class="gav-muted"><?= $t('Os valores permanecem disponíveis nas tabelas abaixo se o gráfico não carregar.', 'Values remain available in the tables below if the chart does not load.') ?></p>
        </div>
        <div class="gav-table-scroll"><table class="gav-table"><thead><tr>
            <th><?= $t('Tecnologia', 'Technology') ?></th><th><?= $t('Peso / participação', 'Weight / share') ?></th>
            <th><?= $t('Disponibilidade', 'Availability') ?></th><th><?= $t('Meta', 'Target') ?></th><th><?= $t('Cobertura', 'Coverage') ?></th>
            <th><?= $t('Tempo indisponível¹', 'Downtime¹') ?></th><th><?= $t('Desconhecido¹', 'Unknown¹') ?></th>
        </tr></thead><tbody>
        <?php $sumWeights = array_sum(array_column($department['technologies'], 'weight')); foreach ($department['technologies'] as $ti => $tech): $s = $tech['summary']; ?>
            <tr><th><a href="#gav-tech-<?= $di ?>-<?= $ti ?>" class="gav-open-tech"><?= $e($tech['name']) ?></a><small><?= $tech['mode'] === 'any_down' ? $t('Qualquer servidor fora', 'Any host down') : $t('Média dos servidores', 'Mean of hosts') ?></small></th>
                <td><?= $e($tech['weight']) ?> / <?= $e(number_format(100 * $tech['weight'] / $sumWeights, 2, $pt ? ',' : '.', '')) ?>%</td>
                <td class="gav-value gav-<?= $status($s, $tech['target']) ?>"><?= $e($percent($s['score'])) ?><?= $s['score'] === null ? '<small>' . $t('Incompleto', 'Incomplete') . '</small>' : '' ?></td>
                <td><?= $e($percent($tech['target'])) ?></td><td><?= $e($percent($s['coverage'])) ?></td>
                <td><?= $e($duration($s['down'])) ?></td><td><?= $e($duration($s['unknown'])) ?></td></tr>
        <?php endforeach ?>
        </tbody></table></div>
        <p class="gav-muted gav-footnote"><?= $t('¹ Na consolidação por média, as durações são médias por servidor. Não são a soma das quedas nem a união dos intervalos. Durações exibidas em segundos inteiros; cálculo preserva frações.',
            '¹ In mean aggregation, durations are averages per host, not summed outages or the union of intervals. Displayed durations use whole seconds; calculations preserve fractions.') ?></p>
        <details class="gav-details"><summary><?= $t('Fórmula e memória de cálculo', 'Formula and calculation details') ?></summary>
            <p class="gav-formula"><?php
                $terms = [];
                foreach ($department['technologies'] as $tech) { $terms[] = '(' . $tech['name'] . ' × ' . $tech['weight'] . ')'; }
                echo $e('(' . implode(' + ', $terms) . ') / ' . $sumWeights);
            ?></p>
            <p class="gav-muted"><?= $t('Sem arredondamento intermediário. O índice final só é publicado quando não há tempo desconhecido em nenhum filho de peso positivo.',
                'No intermediate rounding. The final index is published only when no positive-weight child has unknown time.') ?></p>
        </details>
        <?php foreach ($department['technologies'] as $ti => $tech): ?>
        <details class="gav-details gav-tech-detail" id="gav-tech-<?= $di ?>-<?= $ti ?>"><summary><?= $e($tech['name']) ?> · <?= count($tech['hosts']) ?> hosts</summary>
            <p class="gav-muted"><?= $t('Grupos resolvidos:', 'Resolved groups:') ?> <?= $e(implode(', ', $tech['groups'])) ?></p>
            <?php foreach ($tech['warnings'] as $warning): ?><p class="gav-notice gav-error"><?= $e($warning) ?></p><?php endforeach ?>
            <div class="gav-table-scroll"><table class="gav-table"><thead><tr><th>Host</th><th><?= $t('Disponibilidade', 'Availability') ?></th><th><?= $t('Indisponível', 'Down') ?></th><th><?= $t('Desconhecido', 'Unknown') ?></th><th><?= $t('Itens / observações', 'Items / notes') ?></th></tr></thead><tbody>
                <?php foreach ($tech['hosts'] as $host): ?><tr><th><?= $e($host['name']) ?></th><td><?= $e($percent($host['summary']['score'])) ?></td><td><?= $e($duration($host['summary']['down'])) ?></td><td><?= $e($duration($host['summary']['unknown'])) ?></td><td>
                    <?php foreach ($host['sources'] as $source): ?><div><code><?= $e($source['key']) ?></code> · ID <?= $e($source['itemid'] ?? '—') ?></div><?php endforeach ?>
                    <?php foreach ($host['warnings'] as $warning): ?><small class="gav-warning"><?= $e($warning) ?></small><?php endforeach ?>
                </td></tr><?php endforeach ?>
            </tbody></table></div>
            <h4><?= $t('Intervalos com queda ou lacuna', 'Intervals with downtime or gaps') ?></h4>
            <p class="gav-muted"><?= $t('Fim exclusivo. Frações representam a parcela dos servidores afetada no modo média.', 'End is exclusive. Fractions represent the share of affected hosts in mean mode.') ?></p>
            <?php if (!$tech['interval_count']): ?><p><?= $t('Nenhum intervalo de queda ou lacuna neste período.', 'No downtime or gaps in this period.') ?></p><?php else: ?>
            <div class="gav-table-scroll gav-interval-table"><table class="gav-table"><thead><tr><th><?= $t('Início', 'Start') ?></th><th><?= $t('Fim', 'End') ?></th><th><?= $t('Fração indisponível', 'Down fraction') ?></th><th><?= $t('Fração desconhecida', 'Unknown fraction') ?></th></tr></thead><tbody>
                <?php foreach ($tech['intervals'] as $interval): ?><tr><td><?= $e($date($interval[0])) ?></td><td><?= $e($date($interval[1])) ?></td><td><?= $e($percent(100 * $interval[3])) ?></td><td><?= $e($percent(100 * $interval[4])) ?></td></tr><?php endforeach ?>
            </tbody></table></div>
            <?php if ($tech['interval_count'] > count($tech['intervals'])): ?><p class="gav-warning"><?= $t('Mostrando os primeiros 200 intervalos; os totais consideram todos. A exportação também limita a lista a 200.', 'Showing the first 200 intervals; totals include all intervals. Exported interval lists are also limited to 200.') ?></p><?php endif ?>
            <?php endif ?>
        </details>
        <?php endforeach ?>
    </section>
    <?php endforeach ?>
    <p class="gav-muted"><?= $t('Calculado em', 'Calculated at') ?> <?= $e($date($report['generated_at'])) ?> · <?= $e($report['rows']) ?> <?= $t('amostras lidas', 'samples read') ?>.
        <?= $t('Histórico bruto, resolução de 1 segundo, sem fallback para trends. Configuração e composição atuais; não é um fechamento imutável. Selecione um departamento para reduzir o processamento.',
            'Raw history, 1-second resolution, no trends fallback. Current rules and membership; not an immutable monthly close. Select one department to reduce processing.') ?></p>
    <script type="application/json" id="gav-report-data"><?= json_encode($report, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php endif ?>
</div>
<?php (new CWidget())->setTitle($data['page_title'])->addItem(new CObject(ob_get_clean()))->show();
