<?php
$base = 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/assets/';
$this->addCssFile($base . 'css/governance.css?v=1.7.0');
$this->addCssFile($base . 'css/availability.css?v=1.7.0');
$this->includeJsFile('governance.availability.view.js.php');
$pt = $data['is_pt'];
$t = static function($a, $b) use ($pt) { return $pt ? $a : $b; };
$e = static function($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$percent = static function($value) use ($pt) {
    if ($value === null) { return '—'; }
    if ($value < 100 && round($value, 6) >= 100) { return '<100%'; }
    return rtrim(rtrim(number_format($value, 6, $pt ? ',' : '.', ''), '0'), $pt ? ',' : '.') . '%';
};
$duration = static function($value) {
    if ($value > 0 && $value < 1) { return '<1s'; }
    $seconds = (int) floor($value);
    return sprintf('%dh %02dm %02ds', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
};
$status = static function($summary, $target) {
    return $summary['score'] === null ? 'unknown' : ($summary['score'] >= $target ? 'good' : 'bad');
};
$report = $data['report'];
$job = $data['job'] ?? null;
$message = static function($value) use ($pt) {
    $parts = explode(' / ', $value, 2);
    return $pt && count($parts) === 2 ? $parts[1] : $parts[0];
};
$timezoneName = $report['timezone'] ?? ($job['snapshot']['timezone'] ?? '');
// A rejected start or a busy GET has no confirmed snapshot timezone yet.
$timezone = new DateTimeZone($timezoneName !== '' ? $timezoneName : $data['config']['timezone']);
$date = static function($clock) use ($timezone, $pt) {
    return (new DateTimeImmutable('@' . $clock))->setTimezone($timezone)->format($pt ? 'd/m/Y H:i:s' : 'Y-m-d H:i:s');
};
ob_start();
?>
<div class="gov-container gav <?= !empty($data['is_dark']) ? 'gov-theme-dark' : '' ?>" id="gav-dashboard" data-lang="<?= $pt ? 'pt' : 'en' ?>" data-timezone="<?= $e($data['config']['timezone']) ?>">
    <div class="gav-toolbar gav-page-heading gav-no-print">
        <div><span class="gav-eyebrow"><?= $t('GOVERNANÇA / INDICADORES MENSAIS', 'GOVERNANCE / MONTHLY INDICATORS') ?></span>
            <h2><?= $t('Disponibilidade dos serviços', 'Service availability') ?></h2><p class="gav-muted"><?= $t('Indicadores mensais por departamento, ponderados por tecnologia.', 'Monthly department indicators, weighted by technology.') ?></p></div>
        <a class="btn-alt" href="zabbix.php?action=governance.availability.config"><?= $t('Configurar indicadores', 'Configure indicators') ?></a>
    </div>
    <?php // A separate native POST form supplies Zabbix 6's SID; never nest it in the GET filters.
    echo (new CForm())->setId('gav-job-token')->setAction('zabbix.php?action=governance.availability.run')->setAttribute('hidden', 'hidden'); ?>
    <form method="get" action="zabbix.php" class="gav-filters gav-no-print" id="gav-filters">
        <input type="hidden" name="action" value="governance.availability.view">
        <label class="gav-field"><span><?= $t('Competência', 'Month') ?></span><input type="month" name="month" required value="<?= $e($data['month']) ?>"></label>
        <label class="gav-field gav-department-filter"><span><?= $t('Departamento', 'Department') ?></span><select name="department">
            <option value="-1"><?= $t('Todos os departamentos', 'All departments') ?></option>
            <?php foreach ($data['config']['departments'] as $id => $department): ?>
            <option value="<?= $id ?>" <?= $data['department'] === $id ? 'selected' : '' ?>><?= $e($department['name']) ?></option>
            <?php endforeach ?>
        </select></label>
        <button type="submit" id="gav-calculate" disabled><?= $t('Calcular mês', 'Calculate month') ?></button>
        <?php if ($report): ?>
        <div class="gav-filter-actions" id="gav-filter-actions"><button type="button" id="gav-export" class="btn-alt" disabled><?= $t('Exportar memória (JSON)', 'Export details (JSON)') ?></button>
        <button type="button" id="gav-print" class="btn-alt" disabled><?= $t('Imprimir / PDF', 'Print / PDF') ?></button>
        </div>
        <?php endif ?>
    </form>
    <noscript><p class="gav-notice gav-error"><?= $t('Ative o JavaScript para iniciar ou continuar o cálculo em etapas. Abrir esta página não inicia consultas ao histórico.', 'Enable JavaScript to start or resume the staged calculation. Opening this page does not start history queries.') ?></p></noscript>
    <?php if ($data['error']): ?><div class="gav-notice gav-error" id="gav-page-error" role="alert"><?= $e($message($data['error'])) ?>
        <?php if (!$job): ?><a href="zabbix.php?action=governance.availability.view"><?= $t('Voltar à seleção do período', 'Return to period selection') ?></a><?php endif ?>
    </div><?php endif ?>
    <p class="gav-notice gav-no-print" id="gav-idle-help" <?= $report || $job || !$data['config']['departments'] ? 'hidden' : '' ?>><?= $t('Selecione a competência e clique em Calcular mês. O histórico será consultado em etapas curtas; o relatório só será exibido quando o processamento terminar.', 'Select the month and click Calculate month. History will be queried in short stages; the report will only appear after processing is complete.') ?></p>
    <section class="gav-job gav-no-print" id="gav-job" aria-labelledby="gav-job-title" hidden>
        <div class="gav-toolbar"><h3 id="gav-job-title"><?= $t('Cálculo em etapas', 'Staged calculation') ?></h3><span class="gav-badge" id="gav-job-state"></span></div>
        <div class="gav-job-snapshot"><span class="gav-eyebrow"><?= $t('RETRATO DESTE CÁLCULO', 'THIS CALCULATION SNAPSHOT') ?></span><strong id="gav-job-snapshot"></strong>
            <p class="gav-muted" id="gav-job-period"></p><p class="gav-muted" id="gav-job-snapshot-note"><?= $t('Regras e período fixados ao iniciar. Nenhum resultado parcial será publicado.', 'Rules and period are fixed at the start. No partial result will be published.') ?></p></div>
        <p class="gav-job-message" id="gav-job-message" role="status" aria-live="polite" aria-atomic="true"></p>
        <div class="gav-job-progress-heading" aria-live="polite" aria-atomic="true"><span id="gav-job-stage"></span><strong id="gav-job-percent">—</strong></div>
        <progress id="gav-job-progress" max="100" value="0" aria-label="<?= $t('Progresso do processamento', 'Processing progress') ?>" aria-describedby="gav-job-counts"></progress>
        <dl class="gav-job-counts" id="gav-job-counts">
            <div><dt><?= $t('Hosts concluídos / total', 'Hosts completed / total') ?></dt><dd id="gav-job-hosts">—</dd></div>
            <div><dt><?= $t('Verificações concluídas / total', 'Checks completed / total') ?></dt><dd id="gav-job-checks">—</dd></div>
            <div><dt><?= $t('Linhas de histórico lidas', 'History rows read') ?></dt><dd id="gav-job-rows">—</dd></div>
            <div><dt><?= $t('Chamadas à API', 'API calls') ?></dt><dd id="gav-job-calls">—</dd></div>
        </dl>
        <p class="gav-muted gav-job-context" id="gav-job-context"></p>
        <div class="gav-job-actions"><button type="button" id="gav-job-pause" class="btn-alt" hidden><?= $t('Pausar', 'Pause') ?></button>
            <button type="button" id="gav-job-resume" hidden><?= $t('Continuar cálculo', 'Resume calculation') ?></button>
            <a id="gav-job-new" href="zabbix.php?action=governance.availability.view" hidden><?= $t('Escolher outro período', 'Choose another period') ?></a></div>
        <p class="gav-muted gav-job-pause-help"><?= $t('Pausar ou sair impede novas etapas nesta aba, mas não interrompe uma consulta já enviada ao servidor. Reabra o endereço deste cálculo para continuar enquanto ele estiver disponível.', 'Pausing or leaving prevents new stages in this tab, but does not stop a query already sent to the server. Reopen this calculation’s address to resume while it remains available.') ?></p>
    </section>
    <script type="application/json" id="gav-job-data"><?= json_encode($job, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php if (!$data['config']['departments'] && !$job && !$data['error']): ?>
    <div class="gav-empty"><h3><?= $t('Seu primeiro indicador começa pelas regras', 'Your first indicator starts with its rules') ?></h3>
        <p><?= $t('Cadastre um departamento, suas tecnologias e os itens que representam host e serviço disponíveis.', 'Add a department, its technologies and the items that represent host and service availability.') ?></p>
        <a href="zabbix.php?action=governance.availability.config"><?= $t('Criar indicadores de disponibilidade', 'Create availability indicators') ?></a></div>
    <?php endif ?>
    <?php if ($report): ?>
    <div class="gav-report" id="gav-report">
    <div class="gav-period"><strong><?= $e($report['month']) ?></strong>
        <span><?= $e($report['timezone']) ?> · 24×7 · <?= $e($date($report['from'])) ?> → <?= $e($date($report['to'])) ?></span>
        <span class="gav-badge <?= $report['partial'] ? 'gav-unknown' : '' ?>"><?= $report['partial'] ? $t('Mês em andamento', 'Month in progress') : $t('Mês encerrado · recalculado', 'Past month · recalculated') ?></span>
    </div>
    <?php
    $atTarget = 0; $incomplete = 0; $technologyCount = 0;
    foreach ($report['departments'] as $d) {
        $technologyCount += count($d['technologies']);
        if ($d['summary']['score'] === null) { $incomplete++; }
        elseif ($d['summary']['score'] >= $d['target']) { $atTarget++; }
    }
    ?>
    <div class="gav-overview" aria-label="<?= $t('Resumo do período selecionado', 'Selected period summary') ?>">
        <div><span><?= $t('Departamentos no relatório', 'Departments in report') ?></span><strong><?= count($report['departments']) ?></strong></div>
        <div><span><?= $t('Tecnologias no relatório', 'Technologies in report') ?></span><strong><?= $technologyCount ?></strong></div>
        <div><span><?= $report['partial'] ? $t('Departamentos na meta até agora', 'Departments on target so far') : $t('Departamentos na meta', 'Departments on target') ?></span><strong><?= $atTarget ?></strong></div>
        <div><span><?= $t('Departamentos com dados incompletos', 'Departments with incomplete data') ?></span><strong><?= $incomplete ?></strong></div>
    </div>
    <details class="gav-help"><summary><?= $t('Como interpretar o relatório', 'How to interpret the report') ?></summary><ul>
        <li><?= $t('O índice do departamento é a média ponderada das tecnologias; não é a disponibilidade simultânea de todos os serviços.', 'The department index is a weighted mean of technologies, not the simultaneous availability of all services.') ?></li>
        <li><?= $t('Cobertura ponderada indica a parcela do período com estado conhecido, considerando os pesos. Qualquer lacuna impede publicar um índice final.', 'Weighted coverage is the share of the period with a known state, taking weights into account. Any gap prevents a final index from being published.') ?></li>
        <li><?= $t('Calendário 24×7, sem desconto de manutenções. Usa as regras do início do cálculo e a composição atual dos grupos; meses passados são recalculados, sem fechamento imutável.', '24×7 calendar, with no maintenance exclusions. Uses rules from the start of the calculation and current group membership; past months are recalculated, with no immutable monthly close.') ?></li>
        <li><?= $t('O histórico bruto deve cobrir todo o mês. Dados removidos pela retenção não podem ser recuperados das trends para reconstruir as quedas.', 'Raw history must cover the entire month. Data removed by retention cannot be recovered from trends to reconstruct outages.') ?></li>
    </ul></details>
    <?php foreach ($report['departments'] as $di => $department): $ds = $department['summary']; ?>
    <section class="gav-department gav-<?= $status($ds, $department['target']) ?>">
        <div class="gav-dept-header">
            <div><span class="gav-eyebrow"><?= $t('ÍNDICE DO DEPARTAMENTO', 'DEPARTMENT INDEX') ?></span><h2><?= $e($department['name']) ?></h2>
                <span class="gav-muted"><?= $t('Meta', 'Target') ?> <?= $e($percent($department['target'])) ?> · <?= $t('Média ponderada das tecnologias', 'Weighted mean of technologies') ?></span></div>
            <div class="gav-score"><strong><?= $e($percent($ds['score'])) ?></strong><span><?= $ds['score'] === null ? $t('Dados incompletos', 'Incomplete data') : ($ds['score'] >= $department['target'] ? ($report['partial'] ? $t('Na meta até agora', 'On target so far') : $t('Meta atingida', 'Target met')) : $t('Abaixo da meta', 'Below target')) ?></span></div>
        </div>
        <div class="gav-metrics">
            <div><span><?= $t('Cobertura ponderada dos dados', 'Weighted data coverage') ?></span><strong><?= $e($percent($ds['coverage'])) ?></strong><small><?= $t('Tempo com estado conhecido, considerando os pesos.', 'Time with known state, taking weights into account.') ?></small></div>
            <div><span><?= $t('Tempo equivalente indisponível', 'Equivalent downtime') ?></span><strong><?= $e($duration($ds['down'])) ?></strong><small><?= $t('Tempo confirmado, ponderado; não é a soma das quedas.', 'Confirmed, weighted duration; not the sum of outages.') ?></small></div>
            <div><span><?= $t('Tempo equivalente sem dados', 'Equivalent unknown time') ?></span><strong><?= $e($duration($ds['unknown'])) ?></strong><small><?= $ds['score'] === null ? $t('O índice não pode ser concluído.', 'The index cannot be finalized.') : $t('Não há lacunas no estado consolidado.', 'No gaps in the combined state.') ?></small></div>
        </div>
        <?php if ($ds['score'] === null): ?><p class="gav-notice"><?= $t('Índice inconclusivo. Faixa possível:', 'Inconclusive index. Possible range:') ?> <strong><?= $e($percent($ds['lower'])) ?> – <?= $e($percent($ds['upper'])) ?></strong>. <?= $t('Abra os detalhes das tecnologias para investigar as lacunas.', 'Open technology details to investigate data gaps.') ?></p><?php endif ?>
        <?php foreach ($department['warnings'] ?? [] as $warning): ?><p class="gav-notice gav-error"><?= $e($message($warning)) ?></p><?php endforeach ?>
        <div class="gav-table-scroll"><table class="gav-table"><thead><tr>
            <th><?= $t('Tecnologia', 'Technology') ?></th><th><?= $t('Peso / participação', 'Weight / share') ?></th>
            <th><?= $t('Disponibilidade', 'Availability') ?></th><th><?= $t('Meta', 'Target') ?></th><th><?= $t('Cobertura', 'Coverage') ?></th>
            <th><?= $t('Tempo indisponível¹', 'Downtime¹') ?></th><th><?= $t('Desconhecido¹', 'Unknown¹') ?></th>
        </tr></thead><tbody>
        <?php $sumWeights = array_sum(array_column($department['technologies'], 'weight')); foreach ($department['technologies'] as $ti => $tech): $s = $tech['summary']; ?>
            <tr><th><a href="#gav-tech-<?= $di ?>-<?= $ti ?>" class="gav-open-tech"><?= $e($tech['name']) ?></a><small><?= $tech['mode'] === 'any_down' ? $t('Qualquer servidor fora', 'Any host down') : $t('Média dos servidores', 'Mean of hosts') ?></small></th>
                <td><?= $e($tech['weight']) ?> / <?= $e(number_format(100 * $tech['weight'] / $sumWeights, 2, $pt ? ',' : '.', '')) ?>%</td>
                <td class="gav-value gav-<?= $status($s, $tech['target']) ?>"><?= $e($percent($s['score'])) ?><small><?= $s['score'] === null ? $t('Incompleto', 'Incomplete') : ($s['score'] >= $tech['target'] ? ($report['partial'] ? $t('Na meta até agora', 'On target so far') : $t('Na meta', 'On target')) : $t('Abaixo da meta', 'Below target')) ?></small></td>
                <td><?= $e($percent($tech['target'])) ?></td><td><?= $e($percent($s['coverage'])) ?></td>
                <td><?= $e($duration($s['down'])) ?></td><td><?= $e($duration($s['unknown'])) ?></td></tr>
        <?php endforeach ?>
        </tbody></table></div>
        <p class="gav-muted gav-footnote"><?= $t('¹ Na consolidação por média, as durações são médias por servidor. Não são a soma das quedas nem a união dos intervalos. Durações exibidas em segundos inteiros; cálculo preserva frações.',
            '¹ In mean aggregation, durations are averages per host, not summed outages or the union of intervals. Displayed durations use whole seconds; calculations preserve fractions.') ?></p>
        <details class="gav-details gav-chart-details" <?= $di === 0 ? 'open' : '' ?>><summary><?= $t('Gráfico diário de quedas e lacunas', 'Daily downtime and data gaps chart') ?></summary>
            <div class="gav-chart-header"><div><h3><?= $t('Distribuição ao longo do mês', 'Distribution throughout the month') ?></h3><p class="gav-muted gav-chart-context" data-department="<?= $di ?>"></p></div>
                <label class="gav-field gav-no-print"><span><?= $t('Detalhar', 'Show') ?></span><select class="gav-chart-selection" data-department="<?= $di ?>">
                    <option value="-1"><?= $t('Departamento (ponderado)', 'Department (weighted)') ?></option>
                    <?php foreach ($department['technologies'] as $ti => $tech): ?><option value="<?= $ti ?>"><?= $e($tech['name']) ?></option><?php endforeach ?>
                </select></label></div>
            <div class="gav-chart" data-department="<?= $di ?>" role="img" aria-label="<?= $t('Minutos diários indisponíveis e desconhecidos', 'Daily unavailable and unknown minutes') ?>">
                <p class="gav-muted"><?= $t('Os totais permanecem disponíveis na tabela se o gráfico não carregar.', 'Totals remain available in the table if the chart does not load.') ?></p>
            </div>
        </details>
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
        <details class="gav-details gav-tech-detail" id="gav-tech-<?= $di ?>-<?= $ti ?>"><summary><?= $e($tech['name']) ?> · <?= count($tech['hosts']) ?> <?= count($tech['hosts']) === 1 ? $t('host avaliado', 'host assessed') : $t('hosts avaliados', 'hosts assessed') ?></summary>
            <p class="gav-muted"><?= $t('Grupos resolvidos:', 'Resolved groups:') ?> <?= $e(implode(', ', $tech['groups'])) ?></p>
            <?php foreach ($tech['warnings'] as $warning): ?><p class="gav-notice gav-error"><?= $e($message($warning)) ?></p><?php endforeach ?>
            <div class="gav-table-scroll"><table class="gav-table"><thead><tr><th>Host</th><th><?= $t('Disponibilidade', 'Availability') ?></th><th><?= $t('Indisponível', 'Down') ?></th><th><?= $t('Desconhecido', 'Unknown') ?></th><th><?= $t('Itens / observações', 'Items / notes') ?></th></tr></thead><tbody>
                <?php foreach ($tech['hosts'] as $host): $sourceWarnings = []; ?><tr><th><?= $e($host['name']) ?></th><td><?= $e($percent($host['summary']['score'])) ?></td><td><?= $e($duration($host['summary']['down'])) ?></td><td><?= $e($duration($host['summary']['unknown'])) ?></td><td>
                    <?php foreach ($host['sources'] as $source): ?><div class="gav-source"><code><?= $e($source['key']) ?></code><small>ID <?= $e($source['itemid'] ?? '—') ?> · <?= ($source['freshness_mode'] ?? 'manual') === 'auto' ? $t('Validade automática', 'Automatic validity') : $t('Validade manual', 'Manual validity') ?>: <?= isset($source['max_age']) ? $e($source['max_age']) . 's' : $t('não resolvida', 'unresolved') ?><?php if (!empty($source['interval_seconds'])): ?> · <?= $t('coleta', 'polling') ?> <?= $e($source['interval_seconds']) ?>s<?php endif ?><?php if (!empty($source['heartbeat_seconds'])): ?> · heartbeat <?= $e($source['heartbeat_seconds']) ?>s<?php endif ?></small>
                        <?php if (isset($source['sample_count']) || array_key_exists('max_gap_seconds', $source)): ?><small class="gav-source-diagnostics"><?php if (isset($source['sample_count'])): ?><?= $t('Amostras no período:', 'Samples in period:') ?> <?= $e($source['sample_count']) ?><?php endif ?><?php if (array_key_exists('max_gap_seconds', $source)): ?><?= isset($source['sample_count']) ? ' · ' : '' ?><?= $t('Maior intervalo entre amostras:', 'Longest interval between samples:') ?> <?= isset($source['max_gap_seconds']) ? $e($duration($source['max_gap_seconds'])) : '—' ?><?php endif ?></small><?php endif ?>
                        <?php if (array_key_exists('first_clock', $source) || array_key_exists('last_clock', $source)): ?><small><?= $t('Primeira / última amostra:', 'First / last sample:') ?> <?= isset($source['first_clock']) ? $e($date($source['first_clock'])) : '—' ?> / <?= isset($source['last_clock']) ? $e($date($source['last_clock'])) : '—' ?></small><?php endif ?>
                        <?php if (isset($source['summary']['coverage']) || isset($source['summary']['unknown'])): ?><small><?= $t('Cobertura da fonte:', 'Source coverage:') ?> <?= $e($percent($source['summary']['coverage'] ?? null)) ?><?php if (isset($source['summary']['unknown'])): ?> · <?= $t('Tempo sem estado conhecido:', 'Time with unknown state:') ?> <?= $e($duration($source['summary']['unknown'])) ?><?php endif ?></small><?php endif ?>
                        <?php foreach ($source['warnings'] ?? [] as $warning): $sourceWarnings[$warning] = true; $sourceWarnings[$source['key'] . ': ' . $warning] = true; ?><small class="gav-warning"><?= $e($message($warning)) ?></small><?php endforeach ?>
                    </div><?php endforeach ?>
                    <?php foreach ($host['warnings'] as $warning): if (isset($sourceWarnings[$warning])) { continue; } ?><small class="gav-warning"><?= $e($message($warning)) ?></small><?php endforeach ?>
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
    <?php $processing = $report['processing'] ?? []; if ($processing): ?>
    <p class="gav-muted gav-processing"><?php $processedHosts = $processing['hosts_done'] ?? null; ?>
        <?= $t('Processamento concluído', 'Processing completed') ?><?php if (isset($processing['completed_at'])): ?> <?= $e($date($processing['completed_at'])) ?><?php endif ?><?php if ($processedHosts !== null): ?> · <?= $e($processedHosts) ?> <?= $t('hosts avaliados', 'hosts assessed') ?><?php endif ?><?php if (isset($processing['api_calls'])): ?> · <?= $e($processing['api_calls']) ?> <?= $t('chamadas à API', 'API calls') ?><?php endif ?><?php if (isset($processing['working_seconds'])): ?> · <?= $t('tempo ativo', 'active time') ?> <?= $e($duration($processing['working_seconds'])) ?><?php endif ?><?php if (isset($processing['elapsed_seconds'])): ?> · <?= $t('tempo total, incluindo pausas', 'total time, including pauses') ?> <?= $e($duration($processing['elapsed_seconds'])) ?><?php endif ?>.
    </p>
    <?php endif ?>
    <p class="gav-muted gav-report-footer"><?= $t('Recorte fixado em', 'Cutoff fixed at') ?> <?= $e($date($report['generated_at'])) ?> · <?= $e($report['rows']) ?> <?= $t('linhas lidas (incluindo amostras anteriores ao período e repetidas na paginação)', 'rows read (including pre-period samples and pagination duplicates)') ?>.
        <?= $t('Histórico bruto, resolução de 1 segundo, sem fallback para trends. Regras do início do cálculo e composição atual; não é um fechamento imutável. Selecione um departamento para reduzir o processamento.',
            'Raw history, 1-second resolution, no trends fallback. Rules from the start of the calculation and current membership; not an immutable monthly close. Select one department to reduce processing.') ?></p>
    <script type="application/json" id="gav-report-data"><?= json_encode($report, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    </div>
    <?php endif ?>
</div>
<?php (new CWidget())->setTitle($data['page_title'])->addItem(new CObject(ob_get_clean()))->show();
