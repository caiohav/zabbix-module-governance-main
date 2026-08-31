<?php
// Production PHP view rendered from the real runner over an in-memory synthetic API.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/availability-observed-view-fixture.php';

$observedViewAssertions = 0;
function observedViewCheck(bool $ok, string $message): void {
    global $observedViewAssertions;
    $observedViewAssertions++;
    if (!$ok) { throw new RuntimeException($message); }
}
function observedViewNear($actual, float $expected, string $message): void {
    observedViewCheck(is_numeric($actual) && abs($actual - $expected) < 1e-9, $message . ': ' . var_export($actual, true));
}
function observedViewExtract(string $html, string $pattern): string {
    if (!preg_match($pattern, $html, $match)) { throw new RuntimeException('Missing expected rendered section: ' . $pattern); }
    return $match[1];
}
function observedViewScore(string $html): string { return observedViewExtract($html, '~<div class="gav-score">(.*?)</div>~s'); }
function observedViewMetrics(string $html): string { return observedViewExtract($html, '~<div class="gav-metrics">(.*?)</div>\s*</div>~s'); }
function observedViewTable(string $html): string {
    return observedViewExtract($html, '~<div class="gav-table-scroll"><table class="gav-table"><thead>.*?</thead><tbody>(.*?)</tbody></table>~s');
}

set_error_handler(static function($severity, $message, $file, $line) {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
try {
    $fixtures = []; $reports = [];
    foreach (observedViewCases() as $case) {
        $fixtures[$case] = observedViewFixture($case);
        $reports[$case] = $fixtures[$case]['report'];
    }
    if (in_array('--fixtures-json', $argv, true)) {
        echo json_encode($reports, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); exit;
    }
    $renderer = new ObservedViewRenderer();
    $callsBefore = count(API::$calls);
    foreach ($reports as $case => $report) {
        foreach ([true, false] as $pt) {
            foreach ([true, false] as $dark) {
                $html = $renderer->render($report, $pt, $dark);
                observedViewCheck(strpos($html, 'id="gav-report"') !== false, $case . ': completed report renders');
                observedViewCheck((strpos($html, 'gov-theme-dark') !== false) === $dark, $case . ': theme follows the requested host theme');
                observedViewCheck(strpos($html, 'data-lang="' . ($pt ? 'pt' : 'en') . '"') !== false, $case . ': language follows the user');
                $embedded = json_decode(observedViewExtract($html, '~<script type="application/json" id="gav-report-data">(.*?)</script>~s'), true, 512, JSON_THROW_ON_ERROR);
                observedViewCheck($embedded == $report, $case . ': embedded JSON retains the exact real runner values and nulls');
            }
        }
    }
    observedViewCheck(count(API::$calls) === $callsBefore, 'rendering completed reports never makes a source query');

    foreach ($reports as $case => $report) {
        $expectedHostCharts = 0;
        $html = $renderer->render($report, true, false);
        foreach ($report['departments'] as $di => $department) {
            foreach ($department['technologies'] as $ti => $technology) {
                if (($technology['source'] ?? 'items') === 'sla') { continue; }
                $days = []; $daysCoherent = true; $previousDay = null;
                foreach ($technology['daily'] ?? [] as $day) {
                    $label = is_array($day) ? ($day['day'] ?? null) : null;
                    if (!is_string($label) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $label) !== 1
                            || ($previousDay !== null && strcmp($label, $previousDay) <= 0)) {
                        $daysCoherent = false;
                    }
                    $days[] = $label; $previousDay = $label;
                }
                observedViewCheck($daysCoherent && $days !== [], $case . ': item technology provides ordered daily labels for compact host points');
                foreach ($technology['hosts'] as $hi => $host) {
                    $expectedHostCharts++;
                    $hostDaily = $host['daily'] ?? null;
                    observedViewCheck(is_array($hostDaily) && count($hostDaily) === count($days), $case . ': compact host points align one-to-one with technology days: ' . $host['name']);
                    $compactAndValid = is_array($hostDaily);
                    foreach (is_array($hostDaily) ? $hostDaily : [] as $point) {
                        $compactAndValid = $compactAndValid && is_array($point) && array_keys($point) === [0, 1];
                        if (!$compactAndValid) { break; }
                        [$score, $coverage] = $point;
                        $compactAndValid = ($score === null || (is_numeric($score) && (float) $score >= 0 && (float) $score <= 100))
                            && is_numeric($coverage) && (float) $coverage >= 0 && (float) $coverage <= 100;
                        if (!$compactAndValid) { break; }
                    }
                    observedViewCheck($compactAndValid, $case . ': each compact host point is exactly [score|null, coverage] in percentage bounds: ' . $host['name']);

                    $coordinates = 'class="gav-host-chart" data-department="' . $di . '" data-technology="' . $ti . '" data-host="' . $hi . '"';
                    observedViewCheck(substr_count($html, $coordinates) === 1, $case . ': item host has exactly one chart bound to its report coordinates: ' . $host['name']);
                    $escapedHost = htmlspecialchars($host['name'], ENT_QUOTES, 'UTF-8');
                    observedViewCheck(strpos($html, '<summary>' . $escapedHost . ' · gráfico diário</summary>') !== false,
                        $case . ': host chart summary renders the escaped host name: ' . $host['name']);
                }
            }
        }
        observedViewCheck(substr_count($html, 'class="gav-host-chart"') === $expectedHostCharts, $case . ': item reports render one host chart per item host only');
        observedViewCheck(substr_count($html, '<tr class="gav-host-chart-row"><td colspan="6"><details class="gav-host-chart-details">') === $expectedHostCharts,
            $case . ': every host chart occupies a six-column row and starts in a details element');
        preg_match_all('~<details class="gav-host-chart-details"([^>]*)>~', $html, $hostDetails);
        $allInitiallyClosed = count($hostDetails[0]) === $expectedHostCharts;
        foreach ($hostDetails[1] as $attributes) {
            if (preg_match('~(?:^|\s)open(?:\s|=|$)~i', trim($attributes))) { $allInitiallyClosed = false; break; }
        }
        observedViewCheck($allInitiallyClosed, $case . ': host chart details are initially closed');
    }

    foreach ([true, false] as $pt) {
        $html = $renderer->render($reports['observed90'], $pt, false);
        $chartCount = count($reports['observed90']['departments'][0]['technologies'][0]['hosts']);
        $deferred = $pt
            ? 'Carregado somente ao abrir. Ao abrir outro host, este gráfico é liberado para preservar memória.'
            : 'Loaded only when opened. Opening another host releases this chart to preserve memory.';
        $placeholder = $pt ? 'Abra para carregar o gráfico deste host.' : 'Open to load this host chart.';
        observedViewCheck(substr_count($html, $deferred) === $chartCount, 'lazy host-chart memory guidance is translated for every host');
        observedViewCheck(substr_count($html, $placeholder) === $chartCount, 'lazy host-chart placeholder is translated for every host');
    }

    $department = $reports['observed90']['departments'][0];
    $technology = $department['technologies'][0];
    observedViewCheck($reports['observed90']['data_policy'] === 'observed' && !$reports['observed90']['partial'], '90/50 fixture uses explicit observed policy in a closed month');
    observedViewCheck($department['summary']['score'] === null && $technology['summary']['score'] === null, 'observed mode retains the incomplete strict summaries');
    observedViewNear($department['observation']['score'], 90, 'department score is the observed 90 percent');
    observedViewNear($department['observation']['coverage'], 50, 'coverage includes the blind host');
    observedViewNear($technology['observation']['temporal_coverage'], 100, 'cohort temporal coverage is distinct from source exposure coverage');
    observedViewCheck($technology['summary'] == $reports['strict']['departments'][0]['technologies'][0]['summary'], 'strict calculation is not overwritten by observed results');
    foreach ([true, false] as $pt) {
        $html = $renderer->render($reports['observed90'], $pt);
        observedViewCheck(strpos(observedViewScore($html), '<strong>90%</strong>') !== false, 'score card uses observation, not null strict score');
        observedViewCheck(strpos(observedViewMetrics($html), '<strong>50%</strong>') !== false, 'coverage card uses source coverage, not the 100 percent cohort timeline');
        observedViewCheck(strpos($html, $pt ? 'Cobertura parcial' : 'Partial coverage') !== false, '90 percent result retains an incomplete-coverage qualification');
        observedViewCheck(strpos($html, $pt ? '1 / 2 hosts com dados' : '1 / 2 hosts with data') !== false, 'technology table identifies the observed host cohort');
        observedViewCheck(strpos($html, $pt ? 'Dentro de cada host, todas as verificações continuam obrigatórias' : 'Within each host, all checks remain required') !== false, 'required checks remain explicit in the report explanation');
        observedViewCheck(strpos($html, $pt ? 'não significa 100% de todo o mês' : 'does not mean 100% for the entire month') !== false, 'the policy note does not equate observed percentage with full-month availability');
        observedViewCheck(strpos(observedViewTable($html), '>90%<small>') !== false, 'technology row uses observation score');
        observedViewCheck(strpos(observedViewMetrics($html), '<strong>0h 00m 00s</strong>') !== false, 'observed cohort has no unknown temporal interval despite source gaps');
    }
    foreach (['strict', 'legacy'] as $case) {
        $html = $renderer->render($reports[$case]);
        observedViewCheck($reports[$case]['data_policy'] === 'strict' && !isset($reports[$case]['departments'][0]['observation']), $case . ': strict policy remains default and has no observation override');
        observedViewCheck(strpos(observedViewScore($html), '<strong>—</strong>') !== false && strpos(observedViewScore($html), 'Dados incompletos') !== false, $case . ': strict null remains inconclusive');
        observedViewCheck(strpos($html, 'id="gav-observed-policy"') === false, $case . ': strict report is not relabeled as observed');
        observedViewCheck(strpos($html, 'Índice inconclusivo. Faixa possível:') !== false, $case . ': strict unknown bounds remain available');
    }

    foreach ([true, false] as $pt) {
        $html = $renderer->render($reports['observed100'], $pt);
        $score = observedViewScore($html);
        observedViewCheck(strpos($score, '<strong>100%</strong>') !== false, '100 observed is a valid score, not a missing value');
        observedViewCheck(strpos($score, $pt ? 'Na meta nos dados disponíveis' : 'On target in available data') !== false, '100 partial claims target only within available data');
        observedViewCheck(strpos($score, $pt ? 'Cobertura parcial' : 'Partial coverage') !== false, '100 observed cannot hide incomplete coverage');
        observedViewCheck(strpos($score, $pt ? 'Meta atingida' : 'Target met') === false && strpos($score, $pt ? 'Cobertura completa' : 'Complete coverage') === false, '100 partial does not claim full-month target or complete evidence');
        observedViewCheck(strpos($html, ($pt ? 'Departamentos com dados incompletos' : 'Departments with incomplete data') . '</span><strong>1</strong>') !== false, 'overview still counts the 100 percent department as incomplete');
    }
    $allUnknown = $reports['allunknown']['departments'][0];
    observedViewCheck($allUnknown['observation']['score'] === null && $allUnknown['observation']['participants'] === 0, 'all-unknown fixture has no participating indicator');
    observedViewNear($allUnknown['observation']['coverage'], 0, 'all-unknown coverage is zero');
    $html = $renderer->render($reports['allunknown']);
    observedViewCheck(strpos(observedViewScore($html), '<strong>—</strong>') !== false && strpos(observedViewScore($html), 'Sem estado conhecido') !== false, 'all unknown renders a missing score, not zero or 100');
    observedViewCheck(strpos($html, 'Não há dados classificáveis suficientes') !== false, 'all unknown explains absence of an indicator');
    observedViewCheck(substr_count($html, 'Amostras no período: 0') === 2, 'zero samples are shown only for actual empty history queries');
    observedViewCheck(strpos($html, 'Histórico não consultado:') === false, 'empty queried histories are not mislabeled as skipped queries');

    $mean = $reports['mean']['departments'][0]['technologies'][0];
    observedViewNear($mean['observation']['score'], 50, 'each observed host has one vote regardless of history length');
    observedViewNear($mean['observation']['coverage'], 55, 'mean coverage still counts all host exposure');
    observedViewCheck(abs($mean['observation']['score'] - $mean['observation']['summary']['observed']) > 40, 'fixture distinguishes mean of percentages from a duration-weighted percentage');
    foreach ([true, false] as $pt) {
        $html = $renderer->render($reports['mean'], $pt);
        observedViewCheck(strpos(observedViewScore($html), '<strong>50%</strong>') !== false, 'mean score card selects observation.score, not its timeline ratio');
        observedViewCheck(strpos(observedViewMetrics($html), '<strong>55%</strong>') !== false, 'mean coverage card retains source exposure');
        observedViewCheck(strpos($html, $pt ? 'As durações descrevem o tempo equivalente consolidado; o gráfico diário reaplica a hierarquia do indicador dentro de cada dia.'
            : 'Durations describe combined equivalent time; the daily chart reapplies the indicator hierarchy within each day.') !== false,
            'durations and daily graphs are qualified separately from observed monthly means');
    }

    $weights = $reports['weights']['departments'][0]['observation'];
    observedViewNear($weights['score'], 80, '4 and 1 weights produce 80 after the blind weight 2 is excluded');
    observedViewNear($weights['coverage'], 100 * 5 / 7, 'blind weight remains in coverage denominator');
    observedViewCheck((float) $weights['participating_weight'] === 5.0 && (float) $weights['total_weight'] === 7.0, 'participating and configured weights are separately available');
    foreach ([true, false] as $pt) {
        $html = $renderer->render($reports['weights'], $pt); $table = observedViewTable($html);
        observedViewCheck(strpos(observedViewScore($html), '<strong>80%</strong>') !== false, 'rebalanced score appears on the card');
        observedViewCheck(strpos($html, '<strong>2 / 3</strong>') !== false && strpos($html, '<strong>5 / 7</strong>') !== false, 'missing participation and changed denominator are explicit');
        observedViewCheck(strpos($table, $pt ? '4 / 80,00%' : '4 / 80.00%') !== false && strpos($table, $pt ? '1 / 20,00%' : '1 / 20.00%') !== false, 'table shows actual participating shares');
        observedViewCheck(strpos($table, $pt ? 'Não participa' : 'Not participating') !== false, 'blind technology is explicitly excluded from score');
        $formula = observedViewExtract($html, '~<p class="gav-formula">(.*?)</p>~s');
        observedViewCheck(strpos($formula, 'Known technology × 4') !== false && strpos($formula, 'Down technology × 1') !== false
            && strpos($formula, 'Blind technology') === false && substr($formula, -3) === '/ 5', 'formula shows participating terms and denominator only');
    }

    $html = $renderer->render($reports['mixed']);
    observedViewNear($reports['mixed']['departments'][0]['observation']['score'], 290 / 3, 'mixed monthly mean uses observed item score plus native score');
    observedViewCheck(strpos(observedViewScore($html), '<strong>96,666667%</strong>') !== false, 'mixed card uses observed monthly mean');
    observedViewCheck(strpos($html, 'class="gav-monthly-chart"') !== false, 'mixed report retains native monthly comparison');
    $selection = observedViewExtract($html, '~<select class="gav-chart-selection"[^>]*>(.*?)</select>~s');
    observedViewCheck(substr_count($selection, '<option ') === 1 && strpos($selection, 'value="0"') !== false, 'only the real item timeline is offered in a mixed report');
    foreach (['calendar', 'timezone'] as $case) {
        $department = $reports[$case]['departments'][0]; $html = $renderer->render($reports[$case]);
        observedViewCheck(!$department['aggregation_compatible'] && $department['summary']['score'] === null && !isset($department['observation']), $case . ': observed policy never overrides incompatible native calendar');
        observedViewCheck(strpos(observedViewScore($html), '<strong>—</strong>') !== false && strpos(observedViewScore($html), 'Fontes não comparáveis') !== false, $case . ': blocked department stays visibly blocked');
        observedViewCheck(substr_count(observedViewMetrics($html), '<strong>—</strong>') === 3, $case . ': unavailable aggregate coverage/durations remain null, not zero');
        observedViewCheck(strpos(observedViewTable($html), '>90%<small>') !== false && strpos(observedViewTable($html), '>100%<small>') !== false, $case . ': valid individual observed/native scores remain visible');
    }

    $notQueried = $reports['notqueried']['departments'][0]['technologies'][0];
    observedViewCheck($notQueried['data_quality']['checks_not_queried'] === 2, 'missing and unresolved checks are both marked not queried');
    observedViewCheck(count(array_filter($fixtures['notqueried']['calls'], static function($call) { return $call[0] === 'History'; })) === 0, 'not-queried fixture genuinely issued no history calls');
    foreach ([true, false] as $pt) {
        $html = $renderer->render($reports['notqueried'], $pt);
        observedViewCheck(substr_count($html, $pt ? 'Histórico não consultado: revise o item e a validade.' : 'History not queried: review the item and validity.') === 2, 'each skipped history query is disclosed');
        observedViewCheck(strpos($html, $pt ? 'Amostras no período:' : 'Samples in period:') === false, 'skipped queries do not claim zero samples');
        observedViewCheck(strpos($html, $pt ? 'Isso é diferente de consultar o histórico' : 'This differs from querying history') !== false, 'skipped queries are explicitly distinguished from empty history');
    }
    $seed = $reports['seed']['departments'][0]['technologies'][0]; $source = $seed['hosts'][0]['sources'][0];
    observedViewCheck($source['history_queried'] && $source['sample_count'] === 0 && $source['seed_clock'] === $reports['seed']['from'] - 1800, 'seed fixture has valid prior evidence and no in-month sample');
    observedViewNear($seed['observation']['score'], 100, 'seed is observed availability until its actual expiry');
    observedViewNear($source['summary']['up'], 1800, 'seed expires after 1800 in-period seconds');
    foreach ([true, false] as $pt) {
        $html = $renderer->render($reports['seed'], $pt);
        observedViewCheck(strpos($html, $pt ? 'Amostra anterior ao início, válida apenas até expirar:' : 'Pre-period sample, valid only until expiry:') !== false, 'seed is disclosed separately from in-period samples');
        observedViewCheck(strpos($html, $pt ? '30/06/2026 23:30:00' : '2026-06-30 23:30:00') !== false, 'seed time remains exact');
        observedViewCheck(strpos($html, $pt ? 'Amostras no período: 0' : 'Samples in period: 0') !== false, 'real zero in-month samples can coexist with a valid seed');
    }
    $source = $reports['flexible']['departments'][0]['technologies'][0]['hosts'][0]['sources'][0];
    observedViewCheck($source['max_age'] === 180 && $source['interval_seconds'] === 60 && $source['freshness_source'] === 'flexible_interval', 'real flexible cadence resolves to the conservative 180 second validity');
    foreach ([true, false] as $pt) {
        $html = $renderer->render($reports['flexible'], $pt);
        observedViewCheck(strpos($html, $pt ? 'Validade automática: 180s' : 'Automatic validity: 180s') !== false, 'resolved automatic validity is visible');
        observedViewCheck(strpos($html, $pt ? 'Intervalos flexíveis: validade calculada pelo maior intervalo de coleta.' : 'Flexible intervals: validity calculated from the longest polling interval.') !== false, 'flexible cadence derivation is explained');
    }

    foreach (['native', 'native_observed'] as $case) {
        $report = $reports[$case]; $tech = $report['departments'][0]['technologies'][0]; $html = $renderer->render($report);
        observedViewCheck((float) $tech['summary']['score'] === 100.0 && !isset($tech['observation']) && $report['rows'] === 0, $case . ': native SLI is neither recomputed nor replaced');
        observedViewCheck(strpos($html, 'class="gav-chart"') === false && strpos($html, 'class="gav-monthly-chart"') !== false, $case . ': no native daily timeline is fabricated');
        observedViewCheck(strpos($html, 'class="gav-host-chart"') === false && strpos($html, 'class="gav-host-chart-details"') === false,
            $case . ': native SLA does not fabricate per-host charts or lazy host details');
    }
    $html = $renderer->render($reports['precision']);
    observedViewCheck($reports['precision']['departments'][0]['observation']['score'] < 100, 'one-second observed outage retains full numeric precision');
    observedViewCheck(strpos(observedViewScore($html), '<strong>99,999963%</strong>') !== false, 'small real outage is not displayed as a perfect 100');
    $html = $renderer->render($reports['escaped']);
    foreach (['Department <script>alert("department")</script>', '<img src=x onerror="technology">',
        'Host </script><script>host</script>', 'Aviso <img src=x onerror="warning">'] as $value) {
        observedViewCheck(strpos($html, htmlspecialchars($value, ENT_QUOTES, 'UTF-8')) !== false && strpos($html, $value) === false, 'untrusted label/warning is escaped: ' . $value);
    }
    observedViewCheck(substr_count($html, '</script>') === 2, 'untrusted names cannot terminate either JSON script');
}
finally { restore_error_handler(); }
echo 'PASS: ' . $observedViewAssertions . " observed report view assertions (real synthetic runner).\n";
