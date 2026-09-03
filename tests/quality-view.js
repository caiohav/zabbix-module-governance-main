/* Deterministic local UI tests. No network/browser dependencies. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/quality.js'), 'utf8');
let assertions = 0;
const check = (ok, message) => { assertions++; assert.ok(ok, message); };
const flush = () => new Promise(resolve => setImmediate(resolve));
const id = 'a'.repeat(64), revision = 'b'.repeat(64);
const result = () => ({overall_score: 50, total_hosts: 2,
    overview: {registered: 3, monitored: 2, disabled: 1, maintenance: 1, unavailable: 1},
    kpis: [{id: 'tag', score: 50, valid_count: 1, total_count: 2, non_compliant: [{hostid: '2', name: '<img onerror=alert(1)>'}]}],
    metrics: {high_problems: {status: 'pending', value: null}, unsupported_items: {status: 'pending', value: null}}});
const payload = (sequence = 0, extra = {}) => ({job: id, sequence, status: 'running', page: 'main', revision,
    started_at: 1787920000, finished_at: null, progress: {stage: 'scope', hosts_total: null, hosts_done: 0, calls: 0}, result: null, ...extra});
function page({replies = [], language = 'pt', configError = null, sid = true} = {}) {
    const nodes = {}, calls = [], timers = new Map(), events = {}, scripts = [];
    let timerId = 0;
    class Element {
        constructor(name = '') {
            this.name = name; this.textContent = ''; this.children = []; this.dataset = {}; this.attrs = {};
            this.className = ''; this.events = {}; this.hidden = false; this.disabled = false;
            this.classList = {add: name => { this.className += ' ' + name; }, remove: name => { this.className = this.className.split(' ').filter(x => x !== name).join(' '); }};
        }
        setAttribute(name, value) { this.attrs[name] = value; }
        removeAttribute(name) { delete this.attrs[name]; delete this[name]; }
        addEventListener(name, fn) { (this.events[name] ||= []).push(fn); }
        fire(name) { for (const fn of this.events[name] || []) fn({}); }
        appendChild(child) { this.children.push(child); return child; }
        append(...children) { this.children.push(...children); }
        replaceChildren(...children) { this.children = children; this.textContent = ''; }
        querySelector(selector) { return this.selectors?.[selector] ?? null; }
        querySelectorAll() { return []; }
        closest() { return null; }
    }
    const names = ['dashboard', 'input', 'token', 'message', 'retry', 'refresh', 'progress-wrap', 'progress', 'progress-text',
        'summary', 'cards', 'score', 'score-help', 'hosts', 'timing', 'empty'];
    names.forEach(name => { nodes[name] = new Element(name); });
    nodes.dashboard.dataset = {lang: language, echarts: '/echarts.js'};
    nodes.input.textContent = JSON.stringify({page: 'main', revision, groupids: ['10'], error: configError});
    nodes.token.action = 'http://local.test/zabbix.php?action=governance.quality.run';
    nodes.token.selectors = {'[name="sid"]': sid ? {value: 'test-sid'} : null};
    const card = new Element('card'); card.dataset.cardId = 'tag'; card.selectors = {};
    ['h3', '.gov-card-chart', '.gov-card-score-sub', '.gov-card-score-missing', '.gov-card-exceptions'].forEach(selector => { card.selectors[selector] = new Element(selector); });
    card.selectors.h3.textContent = 'Tag';
    const metricKeys = ['monitored', 'disabled', 'unavailable', 'high_problems', 'unsupported_items'];
    const hints = [];
    for (const key of metricKeys) {
        const metric = new Element(key); nodes['metric-' + key] = metric;
        metric.selectors = {'.gov-overview-value': new Element(), '.gov-overview-hint': new Element()};
        hints.push(metric.selectors['.gov-overview-hint']);
    }
    nodes.dashboard.querySelectorAll = selector => selector === '[data-card-id]' ? [card]
        : selector === '.gov-overview-hint' ? hints : Object.values(nodes).filter(node => node.attrs['aria-busy'] === 'true');
    const document = {readyState: 'complete', getElementById: id => nodes[id.replace('gqp-', '')],
        createElement: tag => new Element(tag), head: {appendChild: script => scripts.push(script)}};
    const fetch = (url, options) => {
        const body = Object.fromEntries(new URLSearchParams(options.body)); calls.push({url, options, body});
        const response = replies.shift();
        return new Promise((resolve, reject) => {
            const abort = () => reject(Object.assign(new Error('Aborted'), {name: 'AbortError'}));
            options.signal.addEventListener('abort', abort, {once: true});
            if (response === 'hang') return;
            Promise.resolve(response).then(data => {
                options.signal.removeEventListener('abort', abort);
                if (data instanceof Error) reject(data);
                else resolve({ok: !data?.httpError, json: async () => {
                    if (data?.invalidJson) throw new SyntaxError('bad');
                    return data;
                }});
            });
        });
    };
    const window = {location: {href: 'http://local.test/zabbix.php', origin: 'http://local.test'}, fetch,
        AbortController, crypto: webcrypto, addEventListener: (name, fn) => { (events[name] ||= []).push(fn); }};
    const setTimeout = (fn, delay) => { const key = ++timerId; timers.set(key, {fn, delay}); return key; };
    const clearTimeout = key => timers.delete(key);
    const context = {document, window, fetch, URL, URLSearchParams, AbortController, Uint8Array, console,
        setTimeout, clearTimeout, getComputedStyle: () => ({getPropertyValue: () => 'transparent'})};
    vm.runInNewContext(source, context);
    const tick = async delay => {
        const entry = [...timers].find(([, timer]) => timer.delay === delay);
        if (entry) { timers.delete(entry[0]); entry[1].fn(); }
        await flush();
    };
    return {nodes, calls, card, timers, scripts, replies, tick, fire: (name, event = {}) => { for (const fn of events[name] || []) fn(event); }};
}
(async () => {
    const stagedResult = result();
    const completedResult = result(); completedResult.metrics.high_problems = {status: 'complete', value: 3};
    completedResult.metrics.unsupported_items = {status: 'complete', value: 2};
    const p = page({replies: [payload(), payload(1, {progress: {stage: 'hosts', hosts_total: 2, hosts_done: 1, calls: 1}}),
        payload(2, {result: stagedResult, progress: {stage: 'problems', hosts_total: 2, hosts_done: 2, calls: 2}}),
        payload(3, {status: 'complete', result: completedResult, finished_at: 1787920010, progress: {stage: 'complete', calls: 4}})]});
    check(p.calls.length === 0, 'document can paint before automatic query');
    await p.tick(0);
    check(p.calls[0].body.operation === 'start' && p.calls[0].body.sid === 'test-sid', 'automatic authenticated start');
    check(p.calls[0].body['groupids[]'] === '10' && p.calls[0].body.revision === revision, 'scope and revision transmitted');
    check(p.nodes.score.textContent === '—' && p.scripts.length === 0, 'no fake score or chart download before data');
    await p.tick(75);
    check(p.nodes['progress-text'].textContent.includes('1 / 2'), 'real host progress');
    check(p.nodes.score.textContent === '—', 'no partial-host score');
    await p.tick(75);
    check(p.nodes.score.textContent === '50%' && p.nodes.refresh.disabled, 'cards visible before operational counters');
    check(p.nodes['metric-high_problems'].selectors['.gov-overview-value'].textContent === '—', 'pending operational counter not zero');
    check(p.scripts.length === 1, 'ECharts loads only after results');
    const link = p.card.selectors['.gov-card-exceptions'].children[0].children[1].children[0].children[0];
    check(link.textContent === '<img onerror=alert(1)>' && link.rel === 'noopener', 'host names rendered safely as text');
    await p.tick(75);
    check(p.nodes.message.textContent === 'Indicadores atualizados.' && !p.nodes.refresh.disabled, 'completion without page reload');
    check(p.nodes['progress-wrap'].hidden && p.nodes['metric-unsupported_items'].selectors['.gov-overview-value'].textContent === '2', 'all counters complete');
    check(p.card.selectors['.gov-card-chart'].textContent === '50%', 'numeric fallback without ECharts');
    p.replies.push(payload()); p.nodes.refresh.fire('click'); await flush();
    check(p.nodes.score.textContent === '—' && p.calls.at(-1).body.request_id !== p.calls[0].body.request_id, 'refresh hides old score and creates new calculation');

    const failMetric = result(); failMetric.metrics.high_problems = {status: 'failed', value: null}; failMetric.metrics.unsupported_items = {status: 'complete', value: 2};
    const f = page({replies: [payload(1, {status: 'complete', result: failMetric})]}); await f.tick(0);
    check(f.nodes.score.textContent === '50%' && f.nodes.message.textContent.includes('falharam'), 'failed operational metric preserves cards');
    check(f.nodes['metric-high_problems'].selectors['.gov-overview-hint'].textContent === 'Falha na consulta', 'failed counter explicit');
    const e = page({language: 'en', replies: [{status: 'failed', error: 'Cannot load / Não carrega'}]}); await e.tick(0);
    check(e.nodes.message.textContent === 'Cannot load' && !e.nodes.retry.hidden, 'localized terminal failure');
    check(e.nodes.score.textContent === '—' && e.nodes.cards.attrs['aria-busy'] === 'false', 'failure clears loading without score');
    const bad = page({replies: [{invalidJson: true}, payload()]}); await bad.tick(0);
    check(!bad.nodes.retry.hidden && bad.nodes.message.textContent.includes('Resposta inválida'), 'invalid JSON has recovery');
    bad.nodes.retry.fire('click'); await flush();
    check(bad.calls[0].body.request_id === bad.calls[1].body.request_id, 'lost start preserves idempotency nonce');
    const timeout = page({replies: [payload(), 'hang', payload(1)]}); await timeout.tick(0); await timeout.tick(75); await timeout.tick(25000);
    check(timeout.nodes.message.textContent.includes('tempo de espera') && timeout.nodes.refresh.disabled, 'timeout offers status retry, not duplicate start');
    timeout.nodes.retry.fire('click'); await flush();
    check(timeout.calls.at(-1).body.operation === 'status', 'timeout recovery reads confirmed checkpoint');
    const busy = page({replies: Array(4).fill({status: 'busy'})}); await busy.tick(0);
    await busy.tick(1500); await busy.tick(1500); await busy.tick(1500);
    check(busy.calls.length === 4 && !busy.nodes.retry.hidden, 'busy retries are bounded');
    const mismatch = page({replies: [payload(0, {revision: 'c'.repeat(64)})]}); await mismatch.tick(0);
    check(mismatch.nodes.message.textContent.includes('incompatível') && mismatch.nodes.score.textContent === '—', 'foreign revision cannot render');
    const leave = page({replies: [payload(), 'hang']}); await leave.tick(0); await leave.tick(75);
    leave.fire('pagehide'); await flush(); await leave.tick(75);
    check(leave.calls.length === 2 && ![...leave.timers.values()].some(timer => timer.delay === 75), 'navigation stops subsequent steps');
    const missing = page({sid: false}); await missing.tick(0);
    check(missing.calls.length === 0 && missing.nodes.refresh.disabled, 'missing SID never starts request');
    const brokenConfig = page({configError: 'Rules unavailable'}); await brokenConfig.tick(0);
    check(brokenConfig.calls.length === 0 && brokenConfig.nodes.message.textContent === 'Rules unavailable', 'invalid config has no background work');
    const emptyResult = result(); emptyResult.total_hosts = 0; emptyResult.overall_score = null; emptyResult.kpis = [];
    emptyResult.overview = {registered: 1, monitored: 0, disabled: 1, maintenance: 0, unavailable: 0};
    emptyResult.metrics = {high_problems: {status: 'complete', value: 0}, unsupported_items: {status: 'complete', value: 0}};
    const empty = page({replies: [payload(1, {status: 'complete', result: emptyResult})]}); await empty.tick(0);
    check(empty.nodes.score.textContent === '—' && !empty.nodes.empty.hidden && empty.nodes.cards.hidden, 'empty scope is not a successful 100 percent');
    check(empty.scripts.length === 0, 'empty scope does not load charts');
    const scopedResult = result();
    scopedResult.kpis[0] = {id: 'tag', score: 0, valid_count: 0, total_count: 1, display_mode: 'non_conformity', non_compliant: [{hostid: '2', name: 'Host'}]};
    const scoped = page({replies: [payload(1, {result: scopedResult})]}); await scoped.tick(0);
    check(scoped.card.selectors['.gov-card-chart'].textContent === '100%', 'Nonconformity percentage displayed');
    check(scoped.card.selectors['.gov-card-score-sub'].textContent === '1 / 1 não conformes', 'Card denominator, not page denominator');
    check(scoped.card.className.includes('critical'), 'Bad conformity stays critical despite inverted display');
    const noScope = result(); noScope.overall_score = null;
    noScope.kpis[0] = {id: 'tag', score: null, valid_count: 0, total_count: 0, non_compliant: []};
    const noScopePage = page({replies: [payload(1, {result: noScope})]}); await noScopePage.tick(0);
    check(noScopePage.card.selectors['.gov-card-chart'].textContent === '—', 'Empty card scope not zero or 100 percent');
    check(noScopePage.card.selectors['.gov-card-score-sub'].textContent === 'Nenhum host no escopo', 'Empty card scope explicit');
    check(noScopePage.scripts.length === 0, 'Empty cards do not load chart library');
    console.log(`PASS: ${assertions} quality JS assertions`);
})().catch(error => { console.error(error); process.exitCode = 1; });
