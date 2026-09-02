<?php
$base = 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/assets/';
$this->addCssFile($base . 'css/governance.css?v=1.7.0');
$this->addCssFile($base . 'css/availability.css?v=1.13.2');
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
    if ($value === null) { return '—'; }
    if ($value > 0 && $value < 1) { return '<1s'; }
    $seconds = (int) floor($value);
    return sprintf('%dh %02dm %02ds', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
};
$status = static function($summary, $target) {
    return $summary['score'] === null ? 'unknown' : ($summary['score'] >= $target ? 'good' : 'bad');
};
$report = $data['report'];
$observedPolicy = ($report['data_policy'] ?? 'strict') === 'observed';
$metric = static function(array $node) use ($observedPolicy): array {
    if (!$observedPolicy || !isset($node['observation'])) { return $node['summary']; }
    return array_replace($node['summary'], $node['observation']['summary'] ?? [],
        ['score' => $node['observation']['score'], 'coverage' => $node['observation']['coverage']]);
};
$timeline = static function(array $node) use ($observedPolicy): array {
    return $observedPolicy && isset($node['observation']['intervals']) ? $node['observation'] : $node;
};
$job = $data['job'] ?? null;
$message = static function($value) use ($pt) {
    $parts = explode(' / ', $value, 2);
    return $pt && count($parts) === 2 ? $parts[1] : $parts[0];
};
$timezoneName = $report['timezone'] ?? ($job['snapshot']['timezone'] ?? '');
// A rejected start or a busy GET has no confirmed snapshot timezone yet.
$timezone = new DateTimeZone($timezoneName !== '' ? $timezoneName : $data['config']['timezone']);
$date = static function($clock, ?string $zone = null) use ($timezone, $pt) {
    if ($clock === null) { return '—'; }
    return (new DateTimeImmutable('@' . $clock))->setTimezone($zone === null ? $timezone : new DateTimeZone($zone))->format($pt ? 'd/m/Y H:i:s' : 'Y-m-d H:i:s');
};
ob_start();
?>
<div class="gov-container gav <?= !empty($data['is_dark']) ? 'gov-theme-dark' : '' ?>" id="gav-dashboard" data-lang="<?= $pt ? 'pt' : 'en' ?>" data-timezone="<?= $e($data['config']['timezone']) ?>">
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
        <div class="gav-filter-actions" id="gav-filter-actions">
        <a class="btn-alt" href="zabbix.php?action=governance.availability.config"><?= $t('Configurar', 'Configure') ?></a>
        <?php if ($report): ?><button type="button" id="gav-export" class="btn-alt" disabled><?= $t('Exportar JSON', 'Export JSON') ?></button>
        <button type="button" id="gav-print" class="btn-alt" disabled><?= $t('Imprimir / PDF', 'Print / PDF') ?></button><?php endif ?>
        </div>
    </form>
    <noscript><p class="gav-notice gav-error"><?= $t('Ative o JavaScript para iniciar ou continuar o cálculo em etapas. Abrir esta página não inicia consultas ao histórico.', 'Enable JavaScript to start or resume the staged calculation. Opening this page does not start history queries.') ?></p></noscript>
    <?php if ($data['error']): ?><div class="gav-notice gav-error" id="gav-page-error" role="alert"><?= $e($message($data['error'])) ?>
        <?php if (!$job): ?><a href="zabbix.php?action=governance.availability.view"><?= $t('Voltar à seleção do período', 'Return to period selection') ?></a><?php endif ?>
    </div><?php endif ?>
    <p class="gav-notice gav-no-print" id="gav-idle-help" <?= $report || $job || !$data['config']['departments'] ? 'hidden' : '' ?>><?= $t('Selecione a competência e clique em Calcular mês. As fontes serão consultadas em etapas curtas. Para SLA nativo, selecione um mês encerrado; o relatório só aparece após a conclusão.', 'Select the month and click Calculate month. Sources will be queried in short stages. For native SLA, select a closed month; the report only appears after completion.') ?></p>
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
            <div id="gav-job-slas-count" hidden><dt><?= $t('SLAs concluídos / total', 'SLAs completed / total') ?></dt><dd id="gav-job-slas">—</dd></div>
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
        <p><?= $t('Cadastre um departamento, suas tecnologias e a fonte de cada indicador: itens ou SLA nativo mensal.', 'Add a department, its technologies and the source of each indicator: items or native monthly SLA.') ?></p>
        <a href="zabbix.php?action=governance.availability.config"><?= $t('Criar indicadores de disponibilidade', 'Create availability indicators') ?></a></div>
    <?php endif ?>
    <?php if ($report): ?>
    <div class="gav-report" id="gav-report">
    <div class="gav-period"><strong><?= $e($report['month']) ?></strong>
        <span><?= $e($report['timezone']) ?> · <?= empty($report['has_sla']) ? '24×7' : $t('Calendário por fonte', 'Calendar per source') ?> · <?= $e($date($report['from'])) ?> → <?= $e($date($report['to'])) ?></span>
        <span class="gav-badge <?= $report['partial'] ? 'gav-unknown' : '' ?>"><?= $report['partial'] ? $t('Mês em andamento', 'Month in progress') : $t('Mês encerrado · recalculado', 'Past month · recalculated') ?></span>
        <?php if (!empty($report['has_items']) || empty($report['has_sla'])): ?><span class="gav-badge"><?= $observedPolicy ? $t('Dados disponíveis', 'Available data') : $t('Cobertura completa exigida', 'Complete coverage required') ?></span><?php endif ?>
    </div>
    <?php
    $atTarget = 0; $incomplete = 0; $technologyCount = 0;
    foreach ($report['departments'] as $d) {
        $technologyCount += count($d['technologies']);
        $display = $metric($d);
        if ($display['score'] !== null && $display['score'] >= $d['target']) { $atTarget++; }
        if ($display['score'] === null || ($observedPolicy && isset($d['observation']) && !$d['observation']['complete'])) { $incomplete++; }
    }
    ?>
    <div class="gav-overview" aria-label="<?= $t('Resumo do período selecionado', 'Selected period summary') ?>">
        <div><span><?= $t('Departamentos no relatório', 'Departments in report') ?></span><strong><?= count($report['departments']) ?></strong></div>
        <div><span><?= $t('Tecnologias no relatório', 'Technologies in report') ?></span><strong><?= $technologyCount ?></strong></div>
        <div><span><?= $observedPolicy ? $t('Na meta nos dados disponíveis', 'On target in available data') : ($report['partial'] ? $t('Departamentos na meta até agora', 'Departments on target so far') : $t('Departamentos na meta', 'Departments on target')) ?></span><strong><?= $atTarget ?></strong></div>
        <div><span><?= $observedPolicy ? $t('Departamentos com dados incompletos', 'Departments with incomplete data') : $t('Departamentos sem índice final', 'Departments without a final index') ?></span><strong><?= $incomplete ?></strong></div>
    </div>
    <details class="gav-help gav-info-panel"<?= $observedPolicy ? ' id="gav-observed-policy"' : '' ?>><summary><span class="gav-info-icon" aria-hidden="true">i</span><?= $t('Entenda o cálculo', 'Understand the calculation') ?></summary><div class="gav-info-content">
        <?php if ($observedPolicy): ?><p><strong><?= $t('Política: calcular sobre dados disponíveis.', 'Policy: calculate from available data.') ?></strong> <?= $t('Nos itens, intervalos sem estado conhecido não entram no percentual. A cobertura e as exclusões continuam visíveis: 100% observado não significa 100% de todo o mês. Nenhum dado ausente é presumido disponível.', 'For items, periods with unknown state are excluded from the percentage. Coverage and exclusions remain visible: 100% observed does not mean 100% for the entire month. Missing data is never presumed available.') ?></p><?php endif ?>
        <ul>
        <li><?= $t('O índice do departamento é a média ponderada das tecnologias; não é a disponibilidade simultânea de todos os serviços.', 'The department index is a weighted mean of technologies, not the simultaneous availability of all services.') ?></li>
        <li><?= $observedPolicy
            ? $t('Cobertura dos itens é a média do tempo com estado conhecido de todos os hosts, inclusive os sem dados. No departamento, aplica os pesos das tecnologias. Não é a mesma medida que o tempo em que ao menos um host foi observado.', 'Item coverage averages the known-state time of all hosts, including hosts without data. Department coverage applies technology weights. It is not the same as the time when at least one host was observed.')
            : $t('Cobertura ponderada indica a parcela do período com estado conhecido, considerando os pesos. Qualquer lacuna impede publicar um índice final.', 'Weighted coverage is the share of the period with a known state, taking weights into account. Any gap prevents a final index from being published.') ?></li>
        <li><?= $t('Itens: calendário 24×7, sem desconto de manutenções e com a composição atual dos grupos. O cálculo tenta o histórico detalhado; em mês encerrado com cobertura incompleta, pode substituí-lo integralmente por trends horárias quando elas tiverem maior cobertura. Uma hora com valores UP e DOWN conta integralmente como DOWN; hora sem trend fica desconhecida.', 'Items: 24×7 calendar, no maintenance exclusions, and current group membership. The calculation first tries detailed history; for a closed month with incomplete coverage, it may replace the whole source with hourly trends when they provide greater coverage. An hour containing both UP and DOWN values counts fully as DOWN; an hour without a trend remains unknown.') ?></li>
        <?php if ($observedPolicy): ?><li><?= $t('Qualquer servidor fora: uma queda confirmada basta; hosts desconhecidos são ignorados enquanto outro tem estado conhecido. Média dos servidores: média dos percentuais observados de cada host com dados. Dentro de cada host, todas as verificações continuam obrigatórias para confirmar disponibilidade.', 'Any host down: one confirmed outage is enough; unknown hosts are ignored while another has a known state. Mean of hosts: mean of the observed percentages of hosts with data. Within each host, all checks remain required to confirm availability.') ?></li><?php endif ?>
        <li><?= $t('SLA nativo: usa o SLI mensal do serviço, seu fuso, calendário e exclusões. Cobertura representa o tempo programado avaliado pelo SLA, não a completude das amostras dos itens. Não reconstrói quedas diárias.', 'Native SLA: uses the service monthly SLI, timezone, schedule and exclusions. Coverage represents scheduled time assessed by the SLA, not completeness of item samples. It does not reconstruct daily outages.') ?></li>
        <li><?= $t('A média entre itens e SLA exige o mesmo período absoluto, calendário e exclusões. Resultados individuais são preservados se houver incompatibilidade; o fallback interno de histórico para trends não troca itens por SLA e não cria fechamento imutável.', 'A mean across items and SLA requires the same absolute period, schedule and exclusions. Individual results are preserved when incompatible; the internal history-to-trends fallback does not replace items with SLA or create an immutable close.') ?></li>
        </ul></div></details>
    <?php foreach ($report['departments'] as $di => $department):
        $ds = $metric($department); $hasSla = false; $hasDaily = false; $hasItems = false;
        foreach ($department['technologies'] as $technology) {
            $hasSla = $hasSla || ($technology['source'] ?? 'items') === 'sla';
            $hasItems = $hasItems || ($technology['source'] ?? 'items') === 'items';
            $hasDaily = $hasDaily || !empty($technology['daily']);
        }
        $compatible = $department['aggregation_compatible'] ?? true;
    ?>
    <section class="gav-department gav-<?= $status($ds, $department['target']) ?>">
        <div class="gav-dept-header">
            <div><span class="gav-eyebrow"><?= $t('ÍNDICE MENSAL DO DEPARTAMENTO', 'MONTHLY DEPARTMENT INDEX') ?></span><h2><?= $e($department['name']) ?></h2>
                <span class="gav-muted"><?= $t('Meta', 'Target') ?> <?= $e($percent($department['target'])) ?> · <?= $t('Média ponderada das tecnologias', 'Weighted mean of technologies') ?></span></div>
            <div class="gav-score"><strong><?= $e($percent($ds['score'])) ?></strong><span><?= !$compatible ? $t('Fontes não comparáveis', 'Sources not comparable') : ($ds['score'] === null ? ($observedPolicy && $hasItems ? $t('Sem estado conhecido', 'No known state') : $t('Dados incompletos', 'Incomplete data')) : ($ds['score'] >= $department['target'] ? ($observedPolicy && $hasItems ? $t('Na meta nos dados disponíveis', 'On target in available data') : ($report['partial'] ? $t('Na meta até agora', 'On target so far') : $t('Meta atingida', 'Target met'))) : $t('Abaixo da meta', 'Below target'))) ?></span>
                <?php if ($observedPolicy && $hasItems && isset($department['observation'])): ?><small class="<?= $department['observation']['complete'] ? 'gav-muted' : 'gav-warning' ?>"><?= $department['observation']['complete'] ? $t('Cobertura completa', 'Complete coverage') : $t('Cobertura parcial', 'Partial coverage') ?></small><?php endif ?></div>
        </div>
        <div class="gav-metrics">
            <div><span><?= $hasSla ? $t('Cobertura ponderada da base', 'Weighted basis coverage') : $t('Cobertura ponderada dos dados', 'Weighted data coverage') ?></span><strong><?= $e($percent($ds['coverage'])) ?></strong><details class="gav-popover-info"><summary aria-label="<?= $t('Informações sobre a cobertura', 'Coverage information') ?>"><span aria-hidden="true">i</span></summary><small><?= $observedPolicy && $hasItems ? $t('Inclui todos os hosts e pesos, mesmo sem dados.', 'Includes all hosts and weights, even without data.') : ($hasSla ? $t('Tempo programado avaliado, considerando os pesos.', 'Scheduled time assessed, taking weights into account.') : $t('Tempo com estado conhecido, considerando os pesos.', 'Time with known state, taking weights into account.')) ?></small></details></div>
            <div><span><?= $t('Tempo equivalente indisponível', 'Equivalent downtime') ?></span><strong><?= $e($duration($ds['down'])) ?></strong><details class="gav-popover-info"><summary aria-label="<?= $t('Informações sobre o tempo indisponível', 'Downtime information') ?>"><span aria-hidden="true">i</span></summary><small><?= $t('Tempo confirmado, ponderado; não é a soma das quedas.', 'Confirmed, weighted duration; not the sum of outages.') ?></small></details></div>
            <div><span><?= $t('Tempo equivalente sem dados', 'Equivalent unknown time') ?></span><strong><?= $e($duration($ds['unknown'])) ?></strong><details class="gav-popover-info"><summary aria-label="<?= $t('Informações sobre o tempo sem dados', 'Unknown-time information') ?>"><span aria-hidden="true">i</span></summary><small><?= $observedPolicy && $hasItems ? $t('Lacunas na linha do tempo consolidada; excluídas do percentual observado.', 'Gaps in the combined timeline; excluded from observed percentages.') : ($ds['score'] === null ? $t('O índice não pode ser concluído.', 'The index cannot be finalized.') : $t('Não há lacunas no estado consolidado.', 'No gaps in the combined state.')) ?></small></details></div>
        </div>
        <?php if ($ds['score'] === null && $compatible): ?><p class="gav-notice"><?= $observedPolicy ? $t('Não há dados classificáveis suficientes para formar um indicador.', 'There is not enough classifiable data to form an indicator.') : $t('Índice inconclusivo. Faixa possível:', 'Inconclusive index. Possible range:') ?> <?php if (!$observedPolicy): ?><strong><?= $e($percent($ds['lower'])) ?> – <?= $e($percent($ds['upper'])) ?></strong>.<?php endif ?> <?= $t('Abra os detalhes das tecnologias para investigar as lacunas.', 'Open technology details to investigate data gaps.') ?>
            <?php if (!$observedPolicy && $hasItems): ?><span><?= $t('Para apurar apenas os dados existentes, selecione “Calcular sobre dados disponíveis” na configuração.', 'To assess only existing data, select “Calculate from available data” in configuration.') ?></span><?php endif ?></p><?php endif ?>
        <?php if ($observedPolicy && isset($department['observation']) && $department['observation']['participants'] < $department['observation']['total_sources']): $participation = $department['observation']; ?><p class="gav-notice"><?= $t('Tecnologias com indicador:', 'Technologies with an indicator:') ?> <strong><?= $e($participation['participants']) ?> / <?= $e($participation['total_sources']) ?></strong> · <?= $t('Peso participante / configurado:', 'Participating / configured weight:') ?> <strong><?= $e($participation['participating_weight']) ?> / <?= $e($participation['total_weight']) ?></strong>.
            <?= $t('Tecnologias sem dados ficam fora da média observada, mas continuam reduzindo a cobertura.', 'Technologies without data are excluded from the observed mean but still reduce coverage.') ?></p><?php endif ?>
        <?php foreach ($department['warnings'] ?? [] as $warning): ?><p class="gav-notice gav-error"><?= $e($message($warning)) ?></p><?php endforeach ?>
        <div class="gav-table-scroll"><table class="gav-table"><thead><tr>
            <th><?= $t('Tecnologia', 'Technology') ?></th><th><?= $t('Peso / participação', 'Weight / share') ?></th>
            <th><?= $t('Indicador mensal', 'Monthly indicator') ?></th><th><?= $t('Meta', 'Target') ?></th><th><?= $t('Cobertura', 'Coverage') ?></th>
            <th><?= $t('Tempo indisponível¹', 'Downtime¹') ?></th><th><?= $t('Desconhecido¹', 'Unknown¹') ?></th>
        </tr></thead><tbody>
        <?php $sumWeights = array_sum(array_column($department['technologies'], 'weight')); $participatingWeight = $observedPolicy && isset($department['observation']) ? $department['observation']['participating_weight'] : $sumWeights;
        foreach ($department['technologies'] as $ti => $tech): $s = $metric($tech); $isObservedItem = $observedPolicy && ($tech['source'] ?? 'items') === 'items'; ?>
            <tr><th><a href="#gav-tech-<?= $di ?>-<?= $ti ?>" class="gav-open-tech"><?= $e($tech['name']) ?></a><small><?= ($tech['source'] ?? 'items') === 'sla' ? $t('SLA nativo · mensal', 'Native SLA · monthly') : ($tech['mode'] === 'any_down' ? $t('Itens · qualquer servidor fora', 'Items · any host down') : $t('Itens · média dos servidores', 'Items · mean of hosts')) ?></small></th>
                <td><?= $e($tech['weight']) ?> / <?= !$compatible || !$participatingWeight || ($observedPolicy && $s['score'] === null) ? '—' : $e(number_format(100 * $tech['weight'] / $participatingWeight, 2, $pt ? ',' : '.', '')) . '%' ?><?php if ($observedPolicy && $s['score'] === null): ?><small><?= $t('Não participa', 'Not participating') ?></small><?php endif ?></td>
                <td class="gav-value gav-<?= $status($s, $tech['target']) ?>"><?= $e($percent($s['score'])) ?><small><?= $s['score'] === null ? ($isObservedItem ? $t('Sem estado conhecido', 'No known state') : $t('Incompleto', 'Incomplete')) : ($isObservedItem ? $t('Nos dados disponíveis', 'In available data') : ($s['score'] >= $tech['target'] ? ($report['partial'] ? $t('Na meta até agora', 'On target so far') : $t('Na meta', 'On target')) : $t('Abaixo da meta', 'Below target'))) ?></small></td>
                <td><?= $e($percent($tech['target'])) ?></td><td><?= $e($percent($s['coverage'])) ?><?php if (isset($tech['data_quality'])): ?><small><?= $e($tech['data_quality']['hosts_with_data']) ?> / <?= $e($tech['hosts_total']) ?> <?= $t('hosts com dados', 'hosts with data') ?></small><?php endif ?></td>
                <td><?= $e($duration($s['down'])) ?></td><td><?= $e($duration($s['unknown'])) ?></td></tr>
        <?php endforeach ?>
        </tbody></table></div>
        <details class="gav-compact-info gav-no-print"><summary><span class="gav-info-icon" aria-hidden="true">i</span><?= $t('Notas sobre os totais', 'Notes about totals') ?></summary><div class="gav-compact-info-content"><p class="gav-muted gav-footnote"><?= $hasSla
            ? $t('¹ SLA: tempos do serviço dentro de seu calendário. Itens no modo média: tempos médios por servidor. Durações exibidas em segundos inteiros; cálculo preserva frações.', '¹ SLA: service durations within its schedule. Items in mean mode: average durations per host. Displayed durations use whole seconds; calculations preserve fractions.')
            : $t('¹ Na consolidação por média, as durações são médias por servidor. Não são a soma das quedas nem a união dos intervalos. Durações exibidas em segundos inteiros; cálculo preserva frações.', '¹ In mean aggregation, durations are averages per host, not summed outages or the union of intervals. Displayed durations use whole seconds; calculations preserve fractions.') ?></p>
        <?php if ($observedPolicy && $hasItems): ?><p class="gav-muted gav-footnote"><?= $t('A média observada usa o percentual de cada participante, sem aumentar o peso de quem tem mais histórico. As durações descrevem o tempo equivalente consolidado; o gráfico diário reaplica a hierarquia do indicador dentro de cada dia.', 'The observed mean uses each participant’s percentage without giving extra weight to longer histories. Durations describe combined equivalent time; the daily chart reapplies the indicator hierarchy within each day.') ?></p><?php endif ?></div></details>
        <?php if ($hasSla): ?>
        <details class="gav-details gav-monthly-details" open><summary><?= $t('Comparativo mensal por tecnologia', 'Monthly comparison by technology') ?></summary>
            <div class="gav-monthly-chart" data-department="<?= $di ?>" role="img" aria-label="<?= $t('Disponibilidade mensal e meta por tecnologia', 'Monthly availability and target by technology') ?>"><p class="gav-muted"><?= $t('Os valores estão disponíveis na tabela acima.', 'Values are available in the table above.') ?></p></div>
        </details>
        <?php endif ?>
        <?php if ($hasDaily): ?>
        <details class="gav-details gav-chart-details" <?= $di === 0 ? 'open' : '' ?>><summary><?= $t('Disponibilidade e cobertura diárias', 'Daily availability and coverage') ?></summary>
            <div class="gav-chart-header"><div class="gav-chart-title"><h3><?= $t('Evolução do indicador no mês', 'Indicator evolution throughout the month') ?></h3><details class="gav-chart-info"><summary aria-label="<?= $t('Informações sobre o gráfico diário', 'Daily chart information') ?>"><span aria-hidden="true">i</span></summary><p class="gav-muted gav-chart-context" data-department="<?= $di ?>"></p></details></div>
                <label class="gav-field gav-no-print"><span><?= $t('Detalhar', 'Show') ?></span><select class="gav-chart-selection" data-department="<?= $di ?>">
                    <?php if (!empty($department['daily'])): ?><option value="-1"><?= $t('Departamento (ponderado)', 'Department (weighted)') ?></option><?php endif ?>
                    <?php foreach ($department['technologies'] as $ti => $tech): if (empty($tech['daily'])) { continue; } ?><option value="<?= $ti ?>"><?= $e($tech['name']) ?></option><?php endforeach ?>
                </select></label></div>
            <div class="gav-chart" data-department="<?= $di ?>" role="img" aria-label="<?= $t('Percentuais diários de disponibilidade, cobertura e meta', 'Daily availability, coverage and target percentages') ?>">
                <p class="gav-muted"><?= $t('Os totais permanecem disponíveis na tabela se o gráfico não carregar.', 'Totals remain available in the table if the chart does not load.') ?></p>
            </div>
        </details>
        <?php endif ?>
        <details class="gav-details"><summary><?= $t('Fórmula e memória de cálculo', 'Formula and calculation details') ?></summary>
            <p class="gav-formula"><?php
                $terms = [];
                foreach ($department['technologies'] as $tech) {
                    if (!$observedPolicy || $metric($tech)['score'] !== null) { $terms[] = '(' . $tech['name'] . ' × ' . $tech['weight'] . ')'; }
                }
                echo $e($terms && $compatible ? '(' . implode(' + ', $terms) . ') / ' . $participatingWeight : $t('Sem média calculável.', 'No calculable mean.'));
            ?></p>
            <p class="gav-muted"><?= $observedPolicy && $hasItems ? $t('Sem arredondamento intermediário. Nos itens: disponível ÷ (disponível + indisponível), somente no tempo conhecido. A média usa os pesos configurados das tecnologias com indicador; a cobertura mantém todos os pesos. Fontes ainda precisam ter períodos e calendários comparáveis.', 'No intermediate rounding. For items: up ÷ (up + down), using only known time. The mean uses configured weights of technologies with an indicator; coverage retains all weights. Sources must still have comparable periods and calendars.') : $t('Sem arredondamento intermediário. O índice exige fontes comparáveis e sem tempo desconhecido em nenhum filho de peso positivo.',
                'No intermediate rounding. The index requires comparable sources and no unknown time in any positive-weight child.') ?></p>
        </details>
        <?php foreach ($department['technologies'] as $ti => $tech): ?>
        <details class="gav-details gav-tech-detail" id="gav-tech-<?= $di ?>-<?= $ti ?>"><summary><?= $e($tech['name']) ?> · <?= ($tech['source'] ?? 'items') === 'sla' ? $t('SLA nativo', 'Native SLA') : count($tech['hosts']) . ' ' . (count($tech['hosts']) === 1 ? $t('host avaliado', 'host assessed') : $t('hosts avaliados', 'hosts assessed')) ?></summary>
            <?php if (($tech['source'] ?? 'items') === 'sla'): $native = $tech['native_sla'] ?? []; ?>
            <?php foreach ($tech['warnings'] as $warning): ?><p class="gav-notice gav-error"><?= $e($message($warning)) ?></p><?php endforeach ?>
            <dl class="gav-sla-details">
                <div><dt>SLA</dt><dd><?= $e($native['sla_name'] ?? '—') ?> <small>ID <?= $e($native['slaid'] ?? '—') ?></small></dd></div>
                <div><dt><?= $t('Serviço', 'Service') ?></dt><dd><?= $e($native['service_name'] ?? '—') ?> <small>ID <?= $e($native['serviceid'] ?? '—') ?></small></dd></div>
                <div><dt><?= $t('Fuso do SLA', 'SLA timezone') ?></dt><dd><?= $e($native['timezone'] ?? '—') ?><?= ($native['timezone_configured'] ?? '') === 'system' ? ' · ' . $t('padrão do sistema', 'system default') : '' ?></dd></div>
                <div><dt><?= $t('Calendário', 'Schedule') ?></dt><dd><?= ($native['schedule_kind'] ?? '') === '24x7' ? '24×7' : (($native['schedule_kind'] ?? '') === 'custom' ? $t('Personalizado no SLA', 'Custom SLA schedule') : '—') ?></dd></div>
                <div><dt><?= $t('Período nativo (fim exclusivo)', 'Native period (exclusive end)') ?></dt><dd><?= $e($date($native['period_from'] ?? null, $native['timezone'] ?? null)) ?> → <?= $e($date($native['period_to'] ?? null, $native['timezone'] ?? null)) ?></dd></div>
                <div><dt><?= $t('Base após exclusões', 'Basis after exclusions') ?></dt><dd><?= $e($duration($native['basis_seconds'] ?? null)) ?> <small><?= $t('Tempo excluído:', 'Excluded time:') ?> <?= $e($duration($native['excluded_seconds'] ?? null)) ?></small></dd></div>
                <div><dt><?= $t('SLI nativo / meta do SLA', 'Native SLI / SLA target') ?></dt><dd><?= $e($percent(($native['native_sli'] ?? -1) < 0 ? null : $native['native_sli'])) ?> / <?= $e($percent($native['slo'] ?? null)) ?></dd></div>
                <div><dt><?= $t('Cobertura do SLA', 'SLA coverage') ?></dt><dd><?= $e($percent($tech['summary']['coverage'])) ?> <small><?= $t('Tempo programado avaliado; não é cobertura de amostras.', 'Scheduled time assessed; not sample coverage.') ?></small></dd></div>
            </dl>
            <?php if (!empty($native['slaid']) && !empty($native['serviceid'])): ?>
            <p><a class="btn-alt" target="_blank" rel="noopener" href="<?= $e('zabbix.php?' . http_build_query(['action' => 'slareport.list', 'filter_slaid' => $native['slaid'], 'filter_serviceid' => $native['serviceid'], 'filter_set' => 1])) ?>"><?= $t('Conferir no relatório nativo', 'Check native report') ?></a></p>
            <?php endif ?>
            <p class="gav-muted"><?= $t('Fonte explícita: SLA nativo. O SLI é calculado pelo Zabbix a partir dos estados do serviço e suas regras. Não representa a reconstrução do histórico dos itens e não permite distribuir as quedas por dia.', 'Explicit source: native SLA. Zabbix calculates the SLI from service states and rules. It does not reconstruct item history or allow outages to be distributed by day.') ?></p>
            <?php if (!empty($native['excluded_downtimes'])): ?>
            <details class="gav-details"><summary><?= $t('Exclusões do SLA neste mês', 'SLA exclusions in this month') ?></summary><div class="gav-table-scroll"><table class="gav-table"><thead><tr><th><?= $t('Nome', 'Name') ?></th><th><?= $t('Início', 'Start') ?></th><th><?= $t('Fim', 'End') ?></th></tr></thead><tbody>
            <?php foreach ($native['excluded_downtimes'] as $excluded): ?><tr><td><?= $e($excluded['name'] ?? '') ?></td><td><?= $e($date($excluded['period_from'], $native['timezone'])) ?></td><td><?= $e($date($excluded['period_to'], $native['timezone'])) ?></td></tr><?php endforeach ?>
            </tbody></table></div></details>
            <?php endif ?>
            <?php else: ?>
            <p class="gav-muted"><?= $t('Grupos resolvidos:', 'Resolved groups:') ?> <?= $e(implode(', ', $tech['groups'])) ?></p>
            <?php if (isset($tech['data_quality'])): $quality = $tech['data_quality']; ?><p class="gav-data-quality"><?= $t('Hosts com estado conhecido:', 'Hosts with known state:') ?> <strong><?= $e($quality['hosts_with_data']) ?> / <?= $e($tech['hosts_total']) ?></strong> · <?= $t('Sem estado conhecido:', 'Without known state:') ?> <strong><?= $e($quality['hosts_without_data']) ?></strong>.</p>
                <?php if ($quality['checks_not_queried'] > 0): ?><p class="gav-notice gav-error"><?= $e($quality['checks_not_queried']) ?> <?= $t('verificação(ões) sem consulta ao histórico. Confira itens ausentes, tipo numérico e validade automática não resolvida nos detalhes abaixo. Isso é diferente de consultar o histórico e não encontrar amostras.', 'check(s) had no history query. Check missing items, numeric types and unresolved automatic validity below. This differs from querying history and finding no samples.') ?></p><?php endif ?>
            <?php endif ?>
            <?php if ($observedPolicy && isset($tech['observation'])): $observation = $tech['observation']; ?><p class="gav-muted"><?= $tech['mode'] === 'any_down'
                ? $t('Uma queda conhecida em qualquer host conta como indisponibilidade. Hosts sem estado conhecido são ignorados naquele instante; se todos forem desconhecidos, o intervalo fica fora do percentual.', 'A known outage in any host counts as downtime. Hosts with unknown state are ignored at that instant; if all are unknown, the interval is excluded from the percentage.')
                : $t('Cada host com estado conhecido participa uma vez: média de seus percentuais observados. Hosts totalmente desconhecidos não entram na média, mas continuam reduzindo a cobertura.', 'Each host with known state participates once: a mean of its observed percentage. Entirely unknown hosts are excluded from the mean but still reduce coverage.') ?></p>
                <?php if ($observation['evidence_from'] !== null): ?><p class="gav-muted"><?= $t('Primeiro / último limite com estado conhecido:', 'First / last known-state boundary:') ?> <?= $e($date($observation['evidence_from'])) ?> → <?= $e($date($observation['evidence_to'])) ?>. <?= $t('Pode haver lacunas entre esses limites; o fim é exclusivo.', 'Gaps may exist between these boundaries; the end is exclusive.') ?></p><?php endif ?>
            <?php endif ?>
            <?php foreach ($tech['warnings'] as $warning): ?><p class="gav-notice gav-error"><?= $e($message($warning)) ?></p><?php endforeach ?>
            <div class="gav-table-scroll"><table class="gav-table"><thead><tr><th>Host</th><th><?= $observedPolicy ? $t('Disponibilidade observada', 'Observed availability') : $t('Disponibilidade', 'Availability') ?></th><th><?= $t('Cobertura', 'Coverage') ?></th><th><?= $t('Indisponível', 'Down') ?></th><th><?= $t('Desconhecido', 'Unknown') ?></th><th><?= $t('Itens / observações', 'Items / notes') ?></th></tr></thead><tbody>
                <?php foreach ($tech['hosts'] as $hi => $host): $sourceWarnings = []; $hs = $metric($host); ?><tr><th><?= $e($host['name']) ?></th><td><?= $e($percent($hs['score'])) ?><?php if ($observedPolicy && $hs['score'] === null): ?><small><?= $t('Sem estado conhecido', 'No known state') ?></small><?php endif ?></td><td><?= $e($percent($hs['coverage'])) ?></td><td><?= $e($duration($hs['down'])) ?></td><td><?= $e($duration($hs['unknown'])) ?></td><td>
                    <?php foreach ($host['sources'] as $source): $trendSource = ($source['data_source'] ?? 'history') === 'trends_conservative'; ?><div class="gav-source"><code><?= $e($source['key']) ?></code><small><strong><?= $trendSource ? $t('Trends horárias conservadoras', 'Conservative hourly trends') : $t('Histórico detalhado', 'Detailed history') ?></strong> · <?= $t('resolução', 'resolution') ?> <?= $trendSource ? '1h' : '1s' ?> · ID <?= $e($source['itemid'] ?? '—') ?> · <?= ($source['freshness_mode'] ?? 'manual') === 'auto' ? $t('Janela automática', 'Automatic window') : $t('Janela manual', 'Manual window') ?>: <?= isset($source['max_age']) ? $e($source['max_age']) . 's' : $t('não resolvida', 'unresolved') ?><?php if (!empty($source['interval_seconds'])): ?> · <?= $t('coleta', 'polling') ?> <?= $e($source['interval_seconds']) ?>s<?php endif ?><?php if (!empty($source['heartbeat_seconds'])): ?> · heartbeat <?= $e($source['heartbeat_seconds']) ?>s<?php endif ?></small>
                        <?php if ($trendSource): ?><small class="gav-source-diagnostics"><?= $t('Horas com trend:', 'Trend hours:') ?> <?= $e($source['trend_row_count']) ?> · UP <?= $e($source['trend_up_hour_count']) ?> · DOWN <?= $e($source['trend_down_hour_count']) ?> · <?= $t('mistas como DOWN', 'mixed as DOWN') ?> <?= $e($source['trend_mixed_hour_count']) ?> · UNKNOWN <?= $e($source['trend_unknown_hour_count']) ?></small><?php elseif (!empty($source['trend_fallback_attempted'])): ?><small><?= $t('Trends consultadas, mas não substituíram o histórico porque não aumentaram a cobertura.', 'Trends were queried but did not replace history because they did not increase coverage.') ?></small><?php endif ?>
                        <?php if (strpos($source['freshness_source'] ?? '', 'flexible_interval') !== false): ?><small><?= $t('Intervalos flexíveis: janela calculada pelo maior intervalo de coleta e pelo mínimo horário.', 'Flexible intervals: window calculated from the longest polling interval and the hourly minimum.') ?></small><?php endif ?>
                        <?php if (isset($source['history_queried']) && !$source['history_queried']): ?><small class="gav-warning"><?= $t('Histórico não consultado: revise o item e a validade.', 'History not queried: review the item and validity.') ?></small><?php else: ?>
                        <?php if (isset($source['sample_count']) || array_key_exists('max_gap_seconds', $source)): ?><small class="gav-source-diagnostics"><?php if (isset($source['sample_count'])): ?><?= $t('Amostras no período:', 'Samples in period:') ?> <?= $e($source['sample_count']) ?><?php if (isset($source['up_sample_count'])): ?> · UP <?= $e($source['up_sample_count']) ?> · DOWN <?= $e($source['down_sample_count']) ?> · UNKNOWN <?= $e($source['unknown_sample_count']) ?><?php endif ?><?php endif ?><?php if (array_key_exists('max_gap_seconds', $source)): ?><?= isset($source['sample_count']) ? ' · ' : '' ?><?= $t('Maior intervalo entre amostras:', 'Longest interval between samples:') ?> <?= isset($source['max_gap_seconds']) ? $e($duration($source['max_gap_seconds'])) : '—' ?><?php endif ?></small><?php endif ?>
                        <?php if (($source['sample_count'] ?? 0) === 0 && empty($source['seed_clock'])): ?><small class="gav-warning"><?= $t('Histórico consultado, mas nenhuma amostra foi encontrada no período ou na janela anterior.', 'History was queried, but no sample was found in the period or preceding window.') ?></small><?php elseif (($source['unknown_sample_count'] ?? 0) > 0): ?><small class="gav-warning"><?= $t('Há valores encontrados que não correspondem exclusivamente às regras de UP ou DOWN.', 'Some returned values do not match exactly one of the UP or DOWN rules.') ?></small><?php endif ?>
                        <?php if (array_key_exists('first_clock', $source) || array_key_exists('last_clock', $source)): ?><small><?= $t('Primeira / última amostra:', 'First / last sample:') ?> <?= isset($source['first_clock']) ? $e($date($source['first_clock'])) : '—' ?> / <?= isset($source['last_clock']) ? $e($date($source['last_clock'])) : '—' ?></small><?php endif ?>
                        <?php if (isset($source['seed_clock'])): ?><small><?= $t('Amostra anterior ao início, válida apenas até expirar:', 'Pre-period sample, valid only until expiry:') ?> <?= $e($date($source['seed_clock'])) ?></small><?php endif ?>
                        <?php endif ?>
                        <?php if (isset($source['summary']['coverage']) || isset($source['summary']['unknown'])): ?><small><?= $t('Cobertura da fonte:', 'Source coverage:') ?> <?= $e($percent($source['summary']['coverage'] ?? null)) ?><?php if (isset($source['summary']['unknown'])): ?> · <?= $t('Tempo sem estado conhecido:', 'Time with unknown state:') ?> <?= $e($duration($source['summary']['unknown'])) ?><?php endif ?></small><?php endif ?>
                        <?php foreach ($source['warnings'] ?? [] as $warning): $sourceWarnings[$warning] = true; $sourceWarnings[$source['key'] . ': ' . $warning] = true; ?><small class="gav-warning"><?= $e($message($warning)) ?></small><?php endforeach ?>
                    </div><?php endforeach ?>
                    <?php foreach ($host['warnings'] as $warning): if (isset($sourceWarnings[$warning])) { continue; } ?><small class="gav-warning"><?= $e($message($warning)) ?></small><?php endforeach ?>
                </td></tr>
                <?php if (!empty($host['daily'])): ?><tr class="gav-host-chart-row"><td colspan="6"><details class="gav-host-chart-details"><summary><?= $e($host['name']) ?> · <?= $t('gráfico diário', 'daily chart') ?></summary>
                        <p class="gav-muted"><?= $t('Carregado somente ao abrir. Ao abrir outro host, este gráfico é liberado para preservar memória.', 'Loaded only when opened. Opening another host releases this chart to preserve memory.') ?></p>
                        <div class="gav-host-chart" data-department="<?= $di ?>" data-technology="<?= $ti ?>" data-host="<?= $hi ?>" role="img" aria-label="<?= $e($t('Disponibilidade e cobertura diárias do host ', 'Daily availability and coverage for host ') . $host['name']) ?>"><span class="gav-muted"><?= $t('Abra para carregar o gráfico deste host.', 'Open to load this host chart.') ?></span></div>
                    </details></td></tr><?php endif ?>
                <?php endforeach ?>
            </tbody></table></div>
            <h4><?= $t('Intervalos com queda ou lacuna', 'Intervals with downtime or gaps') ?></h4>
            <p class="gav-muted"><?= $t('Fim exclusivo. Frações representam a parcela dos servidores afetada no modo média.', 'End is exclusive. Fractions represent the share of affected hosts in mean mode.') ?></p>
            <?php $techTimeline = $timeline($tech); if (!$techTimeline['interval_count']): ?><p><?= $t('Nenhum intervalo de queda ou lacuna neste período.', 'No downtime or gaps in this period.') ?></p><?php else: ?>
            <div class="gav-table-scroll gav-interval-table"><table class="gav-table"><thead><tr><th><?= $t('Início', 'Start') ?></th><th><?= $t('Fim', 'End') ?></th><th><?= $t('Fração indisponível', 'Down fraction') ?></th><th><?= $t('Fração desconhecida', 'Unknown fraction') ?></th></tr></thead><tbody>
                <?php foreach ($techTimeline['intervals'] as $interval): ?><tr><td><?= $e($date($interval[0])) ?></td><td><?= $e($date($interval[1])) ?></td><td><?= $e($percent(100 * $interval[3])) ?></td><td><?= $e($percent(100 * $interval[4])) ?></td></tr><?php endforeach ?>
            </tbody></table></div>
            <?php if ($techTimeline['interval_count'] > count($techTimeline['intervals'])): ?><p class="gav-warning"><?= $t('Mostrando os primeiros 200 intervalos; os totais consideram todos. A exportação também limita a lista a 200.', 'Showing the first 200 intervals; totals include all intervals. Exported interval lists are also limited to 200.') ?></p><?php endif ?>
            <?php endif ?>
            <?php endif ?>
        </details>
        <?php endforeach ?>
    </section>
    <?php endforeach ?>
    <?php $processing = $report['processing'] ?? []; ?>
    <details class="gav-compact-info gav-processing-info gav-no-print"><summary><span class="gav-info-icon" aria-hidden="true">i</span><?= $t('Informações técnicas do processamento', 'Technical processing information') ?></summary><div class="gav-compact-info-content">
    <?php if ($processing): ?><p class="gav-muted gav-processing"><?php $processedHosts = $processing['hosts_done'] ?? null; ?>
        <?= $t('Processamento concluído', 'Processing completed') ?><?php if (isset($processing['completed_at'])): ?> <?= $e($date($processing['completed_at'])) ?><?php endif ?><?php if ($processedHosts !== null): ?> · <?= $e($processedHosts) ?> <?= $t('hosts avaliados', 'hosts assessed') ?><?php endif ?><?php if (!empty($processing['slas_done'])): ?> · <?= $e($processing['slas_done']) ?> <?= $t('SLAs avaliados', 'SLAs assessed') ?><?php endif ?><?php if (isset($processing['api_calls'])): ?> · <?= $e($processing['api_calls']) ?> <?= $t('chamadas à API', 'API calls') ?><?php endif ?><?php if (isset($processing['working_seconds'])): ?> · <?= $t('tempo ativo', 'active time') ?> <?= $e($duration($processing['working_seconds'])) ?><?php endif ?><?php if (isset($processing['elapsed_seconds'])): ?> · <?= $t('tempo total, incluindo pausas', 'total time, including pauses') ?> <?= $e($duration($processing['elapsed_seconds'])) ?><?php endif ?>.
    </p><?php endif ?>
    <p class="gav-muted gav-report-footer"><?= $t('Recorte fixado em', 'Cutoff fixed at') ?> <?= $e($date($report['generated_at'])) ?> · <?= $e($report['rows']) ?> <?= $t('linhas de histórico/trends lidas, incluindo sementes e repetições da paginação', 'history/trend rows read, including seeds and pagination repeats') ?>.
        <?= $t('Itens: histórico detalhado com resolução de 1 segundo ou fallback conservador de trends com resolução de 1 hora, sempre identificados por fonte. SLA nativo: resumo mensal com calendário próprio. Não há fallback entre itens e SLA nem fechamento imutável; alterações no Zabbix podem mudar uma nova apuração.',
            'Items: detailed history at one-second resolution or conservative trend fallback at one-hour resolution, always identified per source. Native SLA: monthly summary with its own calendar. There is no fallback between items and SLA or immutable close; Zabbix changes may affect a new calculation.') ?></p></div></details>
    <script type="application/json" id="gav-report-data"><?= json_encode($report, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    </div>
    <?php endif ?>
</div>
<?php (new CWidget())->setTitle($data['page_title'])->addItem(new CObject(ob_get_clean()))->show();
