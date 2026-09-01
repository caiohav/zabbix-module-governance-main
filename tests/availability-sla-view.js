/* Real synthetic PHP runner reports + the production chart/export script. No browser or network.
 * Optional: GOVERNANCE_PHP=/path/to/php and GOVERNANCE_PHP_EXT=/path/to/ext.
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {execFileSync} = require('node:child_process');

const php = process.env.GOVERNANCE_PHP || 'php';
const args = process.env.GOVERNANCE_PHP_EXT
    ? ['-d', 'extension_dir=' + process.env.GOVERNANCE_PHP_EXT, '-d', 'extension=mbstring'] : [];
args.push(path.join(__dirname, 'availability-sla-view.php'), '--fixtures-json');
const reports = JSON.parse(execFileSync(php, args, {encoding: 'utf8', maxBuffer: 16 * 1024 * 1024}));
const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'availability-view.js'), 'utf8');

function page(report, {language = 'pt', dark = true, chartsAvailable = true} = {}) {
    const nodes = {}, events = {}, charts = [], downloads = [], blobs = [], revoked = [], timers = [];
    const daily = [], monthly = [], hosts = [], technologyDetails = [], selections = {}, contexts = {}, links = [];
    let network = 0, prints = 0;
    class Element {
        constructor(id = null) {
            this.id = id; this.dataset = {}; this.style = {}; this.events = {}; this.clientWidth = 900;
            this.open = true; this.disabled = true; this.textContent = 'Values remain in the table.'; this.value = '';
            this.classList = {add() {}};
            if (id) nodes[id] = this;
        }
        closest(selector) {
            if (selector === '.gav-chart-details' || selector === '.gav-monthly-details'
                || selector === '.gav-host-chart-details') return this.details;
            return selector === '.gav-tech-detail' ? this.technologyDetails : null;
        }
        addEventListener(type, callback) { (this.events[type] ||= []).push(callback); }
        fire(type) { for (const callback of this.events[type] || []) callback({target: this}); }
        click() { downloads.push({href: this.href, download: this.download}); }
    }
    const root = new Element('gav-dashboard'); root.dataset.lang = language;
    new Element('gav-report-data').textContent = JSON.stringify(report);
    new Element('gav-export'); new Element('gav-print');
    report.departments.forEach((department, index) => {
        if (department.technologies.some(technology => technology.source === 'sla')) {
            const node = new Element(); node.dataset.department = String(index); node.kind = 'monthly';
            node.details = new Element(); monthly.push(node);
        }
        const options = [];
        if (Array.isArray(department.daily) && department.daily.length) options.push(-1);
        department.technologies.forEach((technology, technologyIndex) => {
            if (Array.isArray(technology.daily) && technology.daily.length) options.push(technologyIndex);
            const link = new Element(); link.hash = '#tech-' + index + '-' + technologyIndex;
            const technologyDetail = new Element(link.hash.slice(1)); technologyDetail.open = false;
            technologyDetails.push(technologyDetail); links.push(link);
            (technology.hosts || []).forEach((host, hostIndex) => {
                if (!Array.isArray(host.daily) || !host.daily.length) return;
                const node = new Element(); node.dataset.department = String(index);
                node.dataset.technology = String(technologyIndex); node.dataset.host = String(hostIndex); node.kind = 'host';
                node.details = new Element(); node.details.open = false; node.technologyDetails = technologyDetail;
                node.textContent = language === 'pt' ? 'Abra para carregar o gráfico deste host.' : 'Open to load this host chart.';
                hosts.push(node);
            });
        });
        if (options.length) {
            const node = new Element(); node.dataset.department = String(index); node.kind = 'daily';
            node.details = new Element(); node.details.open = index === 0;
            const select = new Element(); select.value = String(options[0]); select.options = options;
            daily.push(node); selections[index] = select; contexts[index] = new Element();
        }
    });
    root.querySelectorAll = selector => ({'.gav-chart': daily, '.gav-monthly-chart': monthly,
        '.gav-host-chart': hosts, '.gav-tech-detail': technologyDetails, '.gav-open-tech': links}[selector] || []);
    root.querySelector = selector => {
        const match = selector.match(/data-department="(\d+)"/);
        if (!match) return null;
        return selector.includes('gav-chart-selection') ? selections[match[1]] : contexts[match[1]];
    };
    const fireWindow = type => { for (const callback of events[type] || []) callback(); };
    const window = {addEventListener(type, callback) { (events[type] ||= []).push(callback); }, print() { prints++; }};
    const echarts = {init(node, theme, options) {
        assert.equal(theme, null); assert.equal(options.renderer, 'canvas');
        const chart = {node, disposed: false, resizeCalls: 0, options: [],
            setOption(option, replace) { assert.equal(replace, true); this.options.push(option); },
            resize() { this.resizeCalls++; }, dispose() { this.disposed = true; }};
        charts.push(chart); return chart;
    }};
    class TestURL extends URL {}
    TestURL.createObjectURL = blob => { blobs.push(blob); return 'blob:local-test/' + blobs.length; };
    TestURL.revokeObjectURL = url => revoked.push(url);
    const colors = dark ? {text: '#eef1f3', muted: '#b2bdc4', panel: '#2b2f32'} : {text: '#253342', muted: '#586e7b', panel: '#f5f7f9'};
    const context = {document: {readyState: 'complete', getElementById: id => nodes[id] || null,
        createElement: () => new Element()}, window, URL: TestURL, Blob,
        getComputedStyle: () => ({fontFamily: 'Test Sans', color: colors.text,
            getPropertyValue: name => ({'--gov-muted': colors.muted, '--gav-panel': colors.panel}[name] || '')}),
        setTimeout(callback, milliseconds) { timers.push({callback, milliseconds}); return timers.length; },
        fetch() { network++; throw new Error('Report presentation must not query a live source'); }};
    if (chartsAvailable) context.echarts = echarts;
    vm.runInNewContext(source, context);
    const activeChart = (kind, index = 0, technology, host) => charts.filter(chart => !chart.disposed
        && chart.node.kind === kind && chart.node.dataset.department === String(index)
        && (technology === undefined || chart.node.dataset.technology === String(technology))
        && (host === undefined || chart.node.dataset.host === String(host))).at(-1);
    return {root, nodes, charts, daily, monthly, hosts, technologyDetails, selections, contexts, links,
        downloads, blobs, revoked, colors, activeChart, fireWindow,
        option: (kind, index, technology, host) => activeChart(kind, index, technology, host).options.at(-1),
        runTimers: milliseconds => timers.filter(timer => timer.milliseconds === milliseconds).forEach(timer => timer.callback()),
        get network() { return network; }, get prints() { return prints; }};
}

const tests = [
    ['native monthly bars retain full-precision scores and independent module targets', async () => {
        const report = reports.native, p = page(report), option = p.option('monthly');
        assert.equal(p.daily.length, 0); assert.equal(p.monthly.length, 1); assert.equal(p.charts.length, 1);
        assert.deepEqual(Array.from(option.series[0].data, row => row.value), report.departments[0].technologies.map(tech => tech.summary.score));
        assert.equal(option.series[0].data[0].value, 100); assert.equal(option.series[0].data[2].value, 100);
        assert.notEqual(option.series[0].data[1].value, 99.877912, 'chart does not replace the precise SLI with displayed rounding');
        assert.deepEqual(Array.from(option.series[1].data, row => Array.from(row)), report.departments[0].technologies.map((tech, index) => [tech.target, index]));
        assert.notEqual(option.series[1].data[0][0], report.departments[0].technologies[0].native_sla.slo, 'module target is not silently replaced by native SLO');
        assert.deepEqual(Array.from(option.series[2].data, row => Array.from(row)),
            report.departments[0].technologies.map((tech, index) => [tech.native_sla.slo, index]));
        assert.equal(option.series[2].name, 'SLO nativo'); assert.equal(option.series[2].symbol, 'diamond');
        const scaleMinimum = Math.min(...report.departments[0].technologies.flatMap(tech =>
            [tech.summary.score, tech.target, tech.native_sla.slo]));
        const expectedFloor = Math.max(0, Math.floor((scaleMinimum - Math.max(.1, (100 - scaleMinimum) * .15)) * 10) / 10);
        assert.equal(option.xAxis.min, expectedFloor); assert.equal(option.xAxis.max, 100);
        assert.equal(option.series[0].label.formatter({value: 100}), '100%');
        assert.equal(option.series[0].label.formatter({value: 99.99999999999999}), '<100%');
        assert.equal(p.network, 0);
    }],
    ['missing scores stay null, while confirmed zero and 100 remain distinct', async () => {
        const absent = page(reports.unavailable), option = absent.option('monthly');
        assert.deepEqual(Array.from(option.series[0].data, row => row.value), [null, null, null]);
        assert.equal(option.series[0].label.formatter({value: null}), '—');
        assert.equal(option.tooltip.valueFormatter(null), '—');
        assert.match(option.yAxis.axisLabel.formatter('PostgreSQL', 0), / —$/);
        assert.equal(option.series[0].data[0].itemStyle.color, '#8c9baa');
        const zero = page(reports.zero).option('monthly');
        assert.equal(zero.series[0].data[0].value, 0); assert.equal(zero.series[0].data[2].value, 100);
        assert.equal(zero.series[0].label.formatter({value: 0}), '0%');
        assert.equal(zero.series[0].data[0].itemStyle.color, '#df6969');
        assert.doesNotMatch(zero.yAxis.axisLabel.formatter('PostgreSQL', 0), / —$/);
        const unknown = page(reports.item_unknown).option('monthly');
        assert.equal(unknown.series[0].data[0].value, 100); assert.equal(unknown.series[0].data[3].value, null);
    }],
    ['mixed daily chart uses item availability and coverage and refuses native or department daily fabrication', async () => {
        const p = page(reports.mixed);
        assert.equal(p.daily.length, 1); assert.equal(p.monthly.length, 1);
        assert.deepEqual(p.selections[0].options, [3]);
        const daily = p.option('daily'), item = reports.mixed.departments[0].technologies[3];
        assert.deepEqual(Array.from(daily.xAxis[0].data), item.daily.map(day => day.day));
        assert.deepEqual(Array.from(daily.xAxis[1].data), item.daily.map(day => day.day));
        assert.deepEqual(Array.from(daily.series[0].data), item.daily.map(day => day.score));
        assert.deepEqual(Array.from(daily.series[1].data), item.daily.map(() => item.target));
        assert.deepEqual(Array.from(daily.series[2].data), item.daily.map(day => day.coverage));
        assert.equal(daily.series[0].name, 'Disponibilidade'); assert.equal(daily.series[1].name, 'Meta');
        assert.equal(daily.series[2].name, 'Cobertura'); assert.equal(daily.series[2].yAxisIndex, 1);
        const original = p.activeChart('daily');
        p.selections[0].value = '0'; p.selections[0].fire('change');
        assert.equal(original.disposed, true); assert.equal(p.activeChart('daily'), undefined);
        assert.match(p.daily[0].textContent, /não fornece distribuição diária/);
        p.selections[0].value = '-1'; p.selections[0].fire('change');
        assert.equal(p.activeChart('daily'), undefined, 'weighted monthly summary has no invented daily series');
        p.selections[0].value = '3'; p.selections[0].fire('change');
        assert.ok(p.activeChart('daily')); assert.equal(p.activeChart('monthly').disposed, false);
        const unknown = page(reports.item_unknown).option('daily');
        assert.ok(unknown.series[0].data.every(score => score === null), 'a gap is not fabricated as availability');
        assert.ok(unknown.series[2].data.every(coverage => coverage === 0), 'actual daily item gaps remain visible as zero coverage');
    }],
    ['incompatible source calendars retain only individual monthly values', async () => {
        for (const name of ['timezone', 'calendar']) {
            const report = reports[name], p = page(report), option = p.option('monthly');
            assert.equal(report.departments[0].summary.score, null);
            assert.deepEqual(Array.from(option.series[0].data, row => row.value), report.departments[0].technologies.map(tech => tech.summary.score));
            assert.equal(option.series[0].data.length, 3, 'no extra invented department bar');
            assert.equal(p.daily.length, 0);
        }
    }],
    ['native and mixed chart backgrounds follow the host theme transparently', async () => {
        for (const dark of [true, false]) {
            const p = page(reports.mixed, {dark});
            for (const kind of ['monthly', 'daily']) {
                const option = p.option(kind);
                assert.equal(option.backgroundColor, 'transparent');
                assert.equal(option.tooltip.backgroundColor, p.colors.panel);
                assert.equal(option.tooltip.textStyle.color, p.colors.text);
                assert.equal(option.tooltip.renderMode, 'richText', 'labels are not inserted as HTML tooltips');
                assert.equal(option.textStyle.fontFamily, 'Test Sans');
            }
            assert.equal(p.option('monthly').yAxis.axisLabel.color, p.colors.muted);
        }
    }],
    ['untrusted names remain canvas text and cannot become HTML tooltips', async () => {
        const report = reports.escaped, p = page(report), option = p.option('monthly');
        assert.equal(option.yAxis.data[0], report.departments[0].technologies[0].name);
        assert.match(option.yAxis.data[0], /^<img/);
        assert.equal(option.tooltip.renderMode, 'richText');
        assert.equal(p.network, 0);
    }],
    ['JSON export v3 preserves explicit sources, null durations and original precision', async () => {
        for (const name of ['native', 'mixed', 'unavailable', 'timezone', 'calendar', 'item_unknown', 'escaped', 'items_only']) {
            const report = reports[name], p = page(report);
            assert.equal(p.nodes['gav-export'].disabled, false);
            p.nodes['gav-export'].fire('click');
            assert.equal(p.blobs.length, 1); assert.equal(p.blobs[0].type, 'application/json');
            const payload = JSON.parse(await p.blobs[0].text());
            assert.equal(payload.format, 'governance-availability-v3'); assert.equal(payload.module_version, '1.13.0');
            assert.equal(payload.assumptions.automatic_source_fallback, false);
            assert.equal(payload.assumptions.immutable_close, false);
            assert.equal(payload.assumptions.sla.daily_timeline_available, false);
            assert.equal(payload.assumptions.sla.method, 'Zabbix sla.getsli');
            assert.match(payload.assumptions.sla.period, /monthly.*closed/);
            assert.equal(payload.assumptions.items.schedule, '24x7');
            assert.equal(payload.assumptions.items.maintenance_excluded, false);
            assert.deepEqual(payload.report, report, name + ': export does not alter report values');
            for (const tech of payload.report.departments[0].technologies.filter(technology => technology.source === 'sla')) {
                assert.equal(tech.daily_available, false); assert.deepEqual(tech.daily, []);
                assert.equal(tech.interval_count, null); assert.equal(typeof tech.native_sla.slaid, 'string');
                assert.equal(typeof tech.native_sla.serviceid, 'string');
            }
            assert.equal(p.downloads[0].download, 'governance-availability-2026-07.json');
            assert.equal(p.network, 0);
            p.runTimers(1000); assert.deepEqual(p.revoked, ['blob:local-test/1']);
        }
    }],
    ['printing supports both chart kinds and restores disclosure state', async () => {
        const p = page(reports.mixed);
        assert.equal(p.hosts.length, 1); assert.equal(p.activeChart('host'), undefined, 'host charts remain lazy before printing');
        p.daily[0].details.open = false; p.monthly[0].details.open = true;
        p.fireWindow('beforeprint');
        assert.equal(p.daily[0].details.open, true); assert.equal(p.monthly[0].details.open, true);
        assert.equal(p.activeChart('host'), undefined, 'printing does not instantiate every host chart');
        p.fireWindow('afterprint');
        assert.equal(p.daily[0].details.open, false); assert.equal(p.monthly[0].details.open, true);
        p.nodes['gav-print'].fire('click'); assert.equal(p.prints, 1);
        const resizeBefore = p.activeChart('monthly').resizeCalls;
        p.fireWindow('resize'); assert.ok(p.activeChart('monthly').resizeCalls > resizeBefore);
        p.links[0].fire('click'); assert.equal(p.nodes[p.links[0].hash.slice(1)].open, true);
        p.root.dataset.reportStale = '1';
        p.nodes['gav-export'].fire('click'); p.nodes['gav-print'].fire('click');
        assert.equal(p.blobs.length, 0); assert.equal(p.prints, 1, 'stale report cannot be exported or printed');
    }],
    ['English monthly labels and no-chart export fallback remain available', async () => {
        const english = page(reports.native, {language: 'en'}).option('monthly');
        assert.equal(english.series[0].name, 'Availability'); assert.equal(english.series[1].name, 'Indicator target');
        assert.equal(english.series[2].name, 'Native SLO');
        assert.equal(english.series[0].label.formatter({value: 99.5}), '99.5%');
        const p = page(reports.native, {chartsAvailable: false});
        assert.equal(p.charts.length, 0); assert.match(p.monthly[0].textContent, /table/);
        assert.equal(p.nodes['gav-export'].disabled, false);
        p.nodes['gav-export'].fire('click'); assert.equal(p.blobs.length, 1);
        const payload = JSON.parse(await p.blobs[0].text());
        assert.deepEqual(payload.report, reports.native);
    }]
];

(async () => {
    for (const [name, test] of tests) {
        try { await test(); }
        catch (error) { error.message = name + ': ' + error.message; throw error; }
    }
    console.log('PASS: ' + tests.length + ' SLA report chart/export scenarios (real synthetic runner reports).');
})().catch(error => { console.error(error); process.exitCode = 1; });

// Shared local-only presentation harness for policy-specific report tests.
module.exports = {page};
