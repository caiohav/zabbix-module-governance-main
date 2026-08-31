/* Observed-policy chart/export regression tests over reports from the real synthetic PHP runner. */
'use strict';
const assert = require('node:assert/strict');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {page} = require('./availability-sla-view.js');

const php = process.env.GOVERNANCE_PHP || 'php';
const phpArgs = process.env.GOVERNANCE_PHP_EXT
    ? ['-d', 'extension_dir=' + process.env.GOVERNANCE_PHP_EXT, '-d', 'extension=mbstring'] : [];
phpArgs.push(path.join(__dirname, 'availability-observed-view.php'), '--fixtures-json');
const reports = JSON.parse(execFileSync(php, phpArgs, {encoding: 'utf8', maxBuffer: 16 * 1024 * 1024}));

const values = option => Array.from(option.series[0].data, value => value && typeof value === 'object' ? value.value : value);
const dailySeries = option => ({
    score: Array.from(option.series[0].data),
    target: Array.from(option.series[1].data),
    coverage: Array.from(option.series[2].data)
});
const changeDaily = (presentation, value) => {
    presentation.selections[0].value = String(value);
    presentation.selections[0].fire('change');
    return presentation.option('daily');
};

const tests = [
    ['monthly comparison reads observed scores and keeps null distinct from confirmed values', async () => {
        const report = reports.mixed, presentation = page(report), option = presentation.option('monthly');
        assert.equal(report.departments[0].technologies[0].summary.score, null);
        assert.equal(report.departments[0].technologies[0].observation.score, 90);
        assert.deepEqual(values(option), [90, 100]);
        assert.doesNotMatch(option.yAxis.axisLabel.formatter('Item service', 0), / —$/);
        assert.match(option.yAxis.axisLabel.formatter('Native service', 1), /^(?!.* —$)/);
        assert.deepEqual(Array.from(option.series[1].data, row => Array.from(row)), [[99.9, 0], [99.9, 1]]);
        assert.deepEqual(Array.from(option.series[2].data, row => Array.from(row)), [[99, 1]],
            'the native SLO is shown separately and never replaces the module target');
        const missing = page(reports.mixed_unknown).option('monthly');
        assert.deepEqual(values(missing), [null, 100]);
        assert.equal(missing.series[0].data[0].itemStyle.color, '#8c9baa');
        assert.equal(missing.series[0].label.formatter({value: null}), '—');
        assert.match(missing.yAxis.axisLabel.formatter('Item service', 0), / —$/);
        assert.equal(presentation.network, 0);
    }],
    ['any-down daily graph uses observation scores and coverage, not the retained strict timeline', async () => {
        const observed = reports.observed90.departments[0];
        const presentation = page(reports.observed90), option = presentation.option('daily');
        assert.equal(presentation.selections[0].value, '-1');
        assert.deepEqual(Array.from(option.xAxis[0].data), observed.observation.daily.map(day => day.day));
        assert.deepEqual(dailySeries(option), {
            score: observed.observation.daily.map(day => day.score),
            target: observed.observation.daily.map(() => observed.target),
            coverage: observed.observation.daily.map(day => day.coverage)
        });
        assert.ok(option.series[2].data.every(coverage => coverage === 50),
            'the blind host stays represented in coverage even though its unknown intervals are excluded from score');
        assert.equal(observed.daily[0].score, null); assert.equal(option.series[0].data[0], 100);
        assert.match(presentation.contexts[0].textContent, /média simples dos dias pode diferir do indicador mensal/);
        const technology = changeDaily(presentation, 0);
        assert.deepEqual(dailySeries(technology), dailySeries(option), 'single technology and department daily indicators agree');
        assert.equal(presentation.network, 0);
    }],
    ['mean and configured weights are reapplied to each daily score and coverage point', async () => {
        const meanReport = reports.mean.departments[0], mean = page(reports.mean).option('daily');
        assert.equal(meanReport.observation.score, 50);
        assert.notEqual(meanReport.observation.score, meanReport.observation.summary.observed);
        assert.deepEqual(dailySeries(mean), {score: meanReport.observation.daily.map(day => day.score),
            target: meanReport.observation.daily.map(() => meanReport.target),
            coverage: meanReport.observation.daily.map(day => day.coverage)});
        assert.ok(mean.series[0].data.includes(50), 'two observed hosts preserve their mean score');
        assert.ok(mean.series[0].data.includes(100), 'a later blind host is excluded from the daily score');
        assert.ok(mean.series[2].data.includes(50) && mean.series[2].data.includes(100), 'coverage still exposes participation changes');
        const simpleDailyMean = mean.series[0].data.reduce((sum, score) => sum + score, 0) / mean.series[0].data.length;
        assert.notEqual(simpleDailyMean, meanReport.observation.score, 'the graph does not imply that daily averaging equals the monthly indicator');
        const report = reports.weights.departments[0], presentation = page(reports.weights);
        const department = presentation.option('daily');
        assert.deepEqual(dailySeries(department), {score: report.observation.daily.map(day => day.score),
            target: report.observation.daily.map(() => report.target),
            coverage: report.observation.daily.map(day => day.coverage)});
        assert.ok(department.series[0].data.every(score => score === 80));
        assert.ok(department.series[2].data.every(coverage => Math.abs(coverage - 500 / 7) < 1e-9));
        const known = changeDaily(presentation, 0);
        assert.ok(known.series[0].data.every(score => score === 100));
        assert.ok(known.series[2].data.every(coverage => coverage === 100));
        const blind = changeDaily(presentation, 1);
        assert.ok(blind.series[0].data.every(score => score === null));
        assert.ok(blind.series[2].data.every(coverage => coverage === 0), 'blind technology remains visible as zero coverage');
        const down = changeDaily(presentation, 2);
        assert.ok(down.series[0].data.every(score => score === 0));
        assert.ok(down.series[2].data.every(coverage => coverage === 100));
        assert.match(presentation.contexts[0].textContent, /lacunas não viram disponibilidade/);
    }],
    ['seeds, flexible validity, all-unknown data and report timezone stay exact in daily series', async () => {
        const allUnknown = page(reports.allunknown).option('daily');
        assert.ok(allUnknown.series[0].data.every(score => score === null));
        assert.ok(allUnknown.series[2].data.every(coverage => coverage === 0));
        const seed = page(reports.seed).option('daily');
        assert.equal(seed.series[0].data[0], 100); assert.equal(seed.series[2].data[0], 1800 / 86400 * 100);
        assert.ok(seed.series[0].data.slice(1).every(score => score === null));
        assert.ok(seed.series[2].data.slice(1).every(coverage => coverage === 0));
        const flexible = page(reports.flexible).option('daily');
        assert.equal(flexible.series[0].data[0], 100); assert.equal(flexible.series[2].data[0], 240 / 86400 * 100);
        assert.ok(flexible.series[2].data.slice(1).every(coverage => coverage === 0));
        const timezoneReport = reports.item_timezone.departments[0], timezone = page(reports.item_timezone).option('daily');
        assert.deepEqual(Array.from(timezone.xAxis[0].data), timezoneReport.observation.daily.map(day => day.day));
        assert.equal(timezone.xAxis[0].data[0], '2026-07-01');
        assert.equal(timezone.xAxis[0].data.at(-1), '2026-07-31');
        assert.equal(timezone.xAxis[0].data.length, 31, 'daily buckets use report timezone month boundaries');
    }],
    ['JSON v3 preserves policy, observations, nulls and full precision without network access', async () => {
        for (const name of ['observed90', 'observed100', 'allunknown', 'mean', 'weights', 'mixed', 'mixed_unknown',
            'calendar', 'timezone', 'item_timezone', 'notqueried', 'seed', 'flexible', 'precision', 'native_observed']) {
            const report = reports[name], presentation = page(report);
            presentation.nodes['gav-export'].fire('click');
            const payload = JSON.parse(await presentation.blobs[0].text());
            assert.equal(payload.format, 'governance-availability-v3');
            assert.equal(payload.module_version, '1.11.0');
            assert.equal(payload.assumptions.data_policy, 'observed');
            assert.match(payload.assumptions.items.unknown_policy, /ignore unknown intervals and hosts/);
            assert.equal(payload.assumptions.items.reported_score, 'observation.score');
            assert.match(payload.assumptions.items.observed_aggregation, /exclude null indicators from score, not coverage/);
            assert.equal(payload.assumptions.items.strict_summary_preserved, true);
            assert.match(payload.assumptions.items.daily_indicator, /each civil day reapplies the same host and technology hierarchy/);
            assert.match(payload.assumptions.items.host_daily_format, /\[score, coverage\].*parent technology daily calendar/);
            assert.deepEqual(payload.report, report, name + ': exported report preserves every source field');
            assert.equal(presentation.network, 0);
        }
        const precise = page(reports.precision); precise.nodes['gav-export'].fire('click');
        const precisePayload = JSON.parse(await precise.blobs[0].text());
        assert.equal(precisePayload.report.departments[0].observation.score,
            reports.precision.departments[0].observation.score);
        assert.ok(precisePayload.report.departments[0].observation.score < 100);
        const missing = page(reports.mixed_unknown); missing.nodes['gav-export'].fire('click');
        const missingPayload = JSON.parse(await missing.blobs[0].text());
        assert.equal(missingPayload.report.departments[0].technologies[0].observation.score, null);
    }],
    ['strict and native presentations remain unchanged', async () => {
        for (const name of ['strict', 'legacy']) {
            const report = reports[name], presentation = page(report), option = presentation.option('daily');
            assert.deepEqual(dailySeries(option), {score: report.departments[0].daily.map(day => day.score),
                target: report.departments[0].daily.map(() => report.departments[0].target),
                coverage: report.departments[0].daily.map(day => day.coverage)});
            assert.equal(presentation.hosts.length, 2, 'item hosts keep their lazy daily graph under strict policy');
            presentation.nodes['gav-export'].fire('click');
            const payload = JSON.parse(await presentation.blobs[0].text());
            assert.equal(payload.assumptions.data_policy, 'strict');
            assert.equal(payload.assumptions.items.reported_score, 'summary.score');
            assert.deepEqual(payload.report, report);
        }
        const native = page(reports.native);
        assert.equal(native.daily.length, 0); assert.equal(native.monthly.length, 1);
        assert.equal(native.hosts.length, 0);
        assert.deepEqual(values(native.option('monthly')), [100]);
        const nativeObserved = page(reports.native_observed);
        assert.equal(nativeObserved.daily.length, 0); assert.equal(nativeObserved.hosts.length, 0);
        assert.deepEqual(values(nativeObserved.option('monthly')), [100]);
        assert.equal(native.network, 0); assert.equal(nativeObserved.network, 0);
    }],
    ['host charts render lazily, use compact aligned points and dispose bounded siblings', async () => {
        const report = reports.observed90, technology = report.departments[0].technologies[0];
        const presentation = page(report);
        assert.equal(presentation.hosts.length, 2); assert.equal(presentation.activeChart('host'), undefined);
        assert.equal(presentation.charts.filter(chart => chart.node.kind === 'host').length, 0,
            'no per-host ECharts instance exists before expansion');
        presentation.technologyDetails[0].open = true; presentation.technologyDetails[0].fire('toggle');
        const firstNode = presentation.hosts[0]; firstNode.details.open = true; firstNode.details.fire('toggle');
        const firstChart = presentation.activeChart('host', 0, 0, 0), first = presentation.option('host', 0, 0, 0);
        assert.ok(firstChart);
        assert.deepEqual(Array.from(first.xAxis[0].data), technology.observation.daily.map(day => day.day));
        assert.deepEqual(dailySeries(first), {score: technology.hosts[0].daily.map(point => point[0]),
            target: technology.hosts[0].daily.map(() => technology.target),
            coverage: technology.hosts[0].daily.map(point => point[1])});
        assert.match(first.tooltip.formatter([{dataIndex: 0}]), /2026-07-01\nDisponibilidade: 100%\nCobertura: 100%/);
        assert.equal(first.backgroundColor, 'transparent'); assert.equal(first.tooltip.renderMode, 'richText');

        const secondNode = presentation.hosts[1]; secondNode.details.open = true; secondNode.details.fire('toggle');
        assert.equal(firstNode.details.open, false); assert.equal(firstChart.disposed, true);
        assert.equal(presentation.activeChart('host', 0, 0, 0), undefined);
        const secondChart = presentation.activeChart('host', 0, 0, 1), second = presentation.option('host', 0, 0, 1);
        assert.ok(secondChart); assert.ok(second.series[0].data.every(score => score === null));
        assert.ok(second.series[2].data.every(coverage => coverage === 0));
        assert.match(firstNode.textContent, /Abra para carregar/);

        secondNode.details.open = false; secondNode.details.fire('toggle');
        assert.equal(secondChart.disposed, true); assert.equal(presentation.activeChart('host'), undefined);
        firstNode.details.open = true; firstNode.details.fire('toggle');
        const reopened = presentation.activeChart('host', 0, 0, 0), beforeResize = reopened.resizeCalls;
        presentation.fireWindow('resize'); assert.ok(reopened.resizeCalls > beforeResize);
        presentation.technologyDetails[0].open = false; presentation.technologyDetails[0].fire('toggle');
        assert.equal(reopened.disposed, true); assert.equal(presentation.activeChart('host'), undefined);
        assert.equal(firstNode.details.open, true, 'closing the technology preserves the chosen disclosure state');
        presentation.technologyDetails[0].open = true; presentation.technologyDetails[0].fire('toggle');
        assert.ok(presentation.activeChart('host', 0, 0, 0), 'reopening the technology restores only its open host chart');
        assert.equal(presentation.network, 0);

        const globallyBounded = page(reports.weights), firstTechnologyHost = globallyBounded.hosts[0],
            secondTechnologyHost = globallyBounded.hosts[1];
        firstTechnologyHost.details.open = true; firstTechnologyHost.details.fire('toggle');
        const otherTechnologyChart = globallyBounded.activeChart('host', 0, 0, 0);
        secondTechnologyHost.details.open = true; secondTechnologyHost.details.fire('toggle');
        assert.equal(firstTechnologyHost.details.open, false);
        assert.equal(otherTechnologyChart.disposed, true, 'opening a host in another technology releases the previous canvas');
        assert.equal(globallyBounded.charts.filter(chart => chart.node.kind === 'host' && !chart.disposed).length, 1,
            'only one live host ECharts instance is retained for the whole report');
    }],
    ['observed charts keep transparent dark/light presentation and rich-text tooltips', async () => {
        for (const dark of [true, false]) {
            const presentation = page(reports.mixed, {dark});
            presentation.hosts[0].details.open = true; presentation.hosts[0].details.fire('toggle');
            for (const kind of ['monthly', 'daily', 'host']) {
                const option = presentation.option(kind);
                assert.equal(option.backgroundColor, 'transparent');
                assert.equal(option.tooltip.renderMode, 'richText');
                assert.equal(option.tooltip.backgroundColor, presentation.colors.panel);
                assert.equal(option.tooltip.textStyle.color, presentation.colors.text);
            }
            assert.equal(presentation.network, 0);
        }
    }]
];

(async () => {
    for (const [name, test] of tests) {
        try { await test(); }
        catch (error) { error.message = name + ': ' + error.message; throw error; }
    }
    console.log('PASS: ' + tests.length + ' observed report chart/export scenarios (real synthetic runner reports).');
})().catch(error => { console.error(error); process.exitCode = 1; });
