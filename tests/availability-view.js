/* Local-only UI regression test: no browser, packages, network or Zabbix credentials. */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');

const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'availability-view.js'), 'utf8');
const jobId = 'a'.repeat(64);
const snapshot = {month: '2026-05', department: 0, department_name: 'Banco de Dados', timezone: 'America/Cuiaba',
    from: 1777608000, to: 1780286400, generated_at: 1780286400};
const projection = (sequence = 0, extra = {}) => ({job: jobId, sequence, status: 'running', snapshot,
    progress: {hosts_done: sequence, hosts_total: 10, checks_done: sequence, checks_total: 20,
        rows: 500, calls: 3, percent: 5, stage: 'history', department: 'Banco de Dados', technology: 'PostgreSQL', host: 'server'}, ...extra});
const flush = () => new Promise(resolve => setImmediate(resolve));
const deferred = () => {
    let resolve;
    const promise = new Promise(done => { resolve = done; });
    return {promise, resolve};
};

function page({saved = null, report = false, configured = true, language = 'pt', replies = [], sid = 'native-test-sid'} = {}) {
    const nodes = {}, calls = [], timers = new Map(), navigations = [], downloads = [], events = {};
    let timerId = 0, prints = 0;
    class Element {
        constructor(id = null) {
            this.id = id; this.textContent = ''; this.value = ''; this.disabled = false; this.hidden = false;
            this.events = {}; this.dataset = {}; this.classList = {add() {}, toggle() {}};
            if (id) nodes[id] = this;
        }
        addEventListener(name, fn) { (this.events[name] ||= []).push(fn); }
        fire(name) { for (const fn of this.events[name] || []) fn({preventDefault() {}}); }
        removeAttribute(name) { delete this[name]; }
        querySelector() { return null; }
        querySelectorAll() { return []; }
        closest() { return null; }
        click() { downloads.push(this); }
    }
    for (const id of ['gav-dashboard', 'gav-filters', 'gav-job-token', 'gav-job', 'gav-calculate', 'gav-job-pause',
        'gav-job-resume', 'gav-job-new', 'gav-job-progress', 'gav-job-state', 'gav-job-message', 'gav-job-stage',
        'gav-job-percent', 'gav-job-hosts', 'gav-job-checks', 'gav-job-rows', 'gav-job-calls', 'gav-job-context',
        'gav-job-snapshot', 'gav-job-period', 'gav-job-snapshot-note', 'gav-idle-help', 'gav-job-data']) new Element(id);
    if (report) {
        for (const id of ['gav-report', 'gav-report-data', 'gav-filter-actions', 'gav-export', 'gav-print']) new Element(id);
        nodes['gav-report-data'].textContent = JSON.stringify({month: '2026-04', departments: []});
    }
    nodes['gav-dashboard'].dataset = {lang: language, timezone: 'America/Cuiaba'};
    const month = new Element('month'); month.value = '2026-05';
    const department = new Element('department'); department.value = configured ? '0' : '-1';
    department.selectedIndex = configured ? 1 : 0;
    department.options = [{value: '-1', textContent: 'Todos os departamentos'}];
    if (configured) department.options.push({value: '0', textContent: 'Banco de Dados'});
    department.add = option => department.options.push(option);
    const token = sid === null ? null : new Element('sid');
    if (token) token.value = sid;
    nodes['gav-filters'].querySelector = selector => selector.includes('month') ? month : department;
    nodes['gav-filters'].reportValidity = () => true;
    nodes['gav-job-token'].querySelector = () => token;
    nodes['gav-job-token'].action = 'http://local.test/zabbix.php?action=governance.availability.run';
    nodes['gav-job-data'].textContent = JSON.stringify(saved);
    const location = {href: 'http://local.test/zabbix.php?action=governance.availability.view', origin: 'http://local.test',
        assign: url => navigations.push(url)};
    const fetch = (url, options) => {
        const request = {url, body: Object.fromEntries(new URLSearchParams(options.body)), options};
        calls.push(request);
        const reply = replies.shift();
        return new Promise((resolve, reject) => {
            const abort = () => reject(Object.assign(new Error('Aborted test request'), {name: 'AbortError'}));
            options.signal.addEventListener('abort', abort, {once: true});
            const finish = () => options.signal.removeEventListener('abort', abort);
            if (reply instanceof Error || reply === undefined) {
                finish(); reject(reply || new Error('Missing test response')); return;
            }
            Promise.resolve(typeof reply === 'function' ? reply(request) : reply).then(payload => {
                finish();
                resolve({ok: true, json: async () => {
                    if (payload.invalidJson) throw new SyntaxError('Truncated JSON response');
                    return payload;
                }});
            }, error => { finish(); reject(error); });
        });
    };
    const window = {location, fetch, AbortController, crypto: webcrypto,
        history: {replaceState(_state, _title, url) { location.href = url; }},
        addEventListener(name, fn) { (events[name] ||= []).push(fn); }, print() { prints++; }};
    class UiURL extends URL {}
    UiURL.createObjectURL = () => 'blob:http://local.test/test';
    UiURL.revokeObjectURL = () => {};
    vm.runInNewContext(source, {document: {readyState: 'complete', getElementById: id => nodes[id] || null,
        createElement: () => new Element()}, window, fetch, URL: UiURL, URLSearchParams, AbortController, Uint8Array,
        Intl, Date, Blob, setTimeout(fn, ms) { timers.set(++timerId, {fn, ms}); return timerId; }, clearTimeout(id) { timers.delete(id); }});
    const schedule = async ms => {
        const entry = Array.from(timers).find(([, value]) => value.ms === ms);
        assert.ok(entry, 'Expected scheduled delay: ' + ms);
        timers.delete(entry[0]); entry[1].fn(); await flush();
    };
    return {nodes, calls, timers, navigations, downloads, month, department, location, replies, schedule,
        get prints() { return prints; },
        fireWindow: name => { for (const fn of events[name] || []) fn(); },
        countTimers: delay => Array.from(timers.values()).filter(timer => timer.ms === delay).length,
        submit: () => nodes['gav-filters'].fire('submit'), resume: () => nodes['gav-job-resume'].fire('click'),
        pause: () => nodes['gav-job-pause'].fire('click')};
}

const tests = [
    ['initial page never starts a calculation', async () => {
        const p = page();
        assert.equal(p.calls.length, 0);
        assert.equal(p.nodes['gav-calculate'].disabled, false);
        assert.equal(p.nodes['gav-job'].hidden, true);
        assert.equal(page({configured: false}).nodes['gav-calculate'].disabled, true);
        assert.equal(page({sid: null}).nodes['gav-calculate'].disabled, true);
    }],
    ['start captures native SID and filters, invalidates old report, and stays sequential', async () => {
        const p = page({report: true, replies: [projection()]});
        p.submit(); await flush();
        const request = p.calls[0];
        assert.equal(new URL(request.url).searchParams.get('action'), 'governance.availability.run');
        assert.equal(request.options.method, 'POST');
        assert.equal(request.options.credentials, 'same-origin');
        assert.equal(request.body.sid, 'native-test-sid');
        assert.equal(request.body.month, '2026-05');
        assert.equal(request.body.department, '0');
        assert.equal(request.body.operation, 'start');
        assert.equal(request.body.action, undefined, 'GET view action must not leak into POST body');
        assert.match(request.body.request_id, /^[a-f0-9]{64}$/);
        assert.equal(p.month.disabled, true);
        assert.equal(p.department.disabled, true);
        assert.equal(p.nodes['gav-report'].hidden, true);
        assert.equal(p.nodes['gav-filter-actions'].hidden, true);
        assert.equal(p.nodes['gav-export'].disabled, true);
        assert.equal(p.nodes['gav-print'].disabled, true);
        p.nodes['gav-export'].fire('click'); p.nodes['gav-print'].fire('click');
        assert.equal(p.downloads.length, 0); assert.equal(p.prints, 0);
        assert.equal(new URL(p.location.href).searchParams.get('job'), jobId);
        assert.equal(p.countTimers(100), 1);
        p.submit(); assert.equal(p.calls.length, 1);
    }],
    ['pause lets an in-flight checkpoint finish and resumes with status', async () => {
        const stage = deferred();
        const p = page({replies: [projection(), () => stage.promise, projection(1)]});
        p.submit(); await flush(); await p.schedule(100); p.pause();
        assert.match(p.nodes['gav-job-message'].textContent, /Pausa solicitada/);
        assert.equal(p.calls[1].options.signal.aborted, false, 'Pause must not pretend to cancel PHP');
        stage.resolve(projection(1)); await flush();
        assert.equal(p.countTimers(100), 0);
        assert.equal(p.nodes['gav-job-resume'].hidden, false);
        p.resume(); await flush();
        assert.equal(p.calls[2].body.operation, 'status');
        assert.equal(p.calls[2].body.sequence, '1');
    }],
    ['reopen is passive and consults status before another step', async () => {
        const p = page({saved: projection(7), replies: [projection(8), projection(9)]});
        assert.equal(p.calls.length, 0); assert.equal(p.month.disabled, true);
        assert.equal(p.nodes['gav-job-resume'].hidden, false);
        p.resume(); await flush();
        assert.equal(p.calls[0].body.operation, 'status'); assert.equal(p.calls[0].body.sequence, '7');
        await p.schedule(100);
        assert.equal(p.calls[1].body.operation, 'step'); assert.equal(p.calls[1].body.sequence, '8');
    }],
    ['network and invalid JSON preserve the last checkpoint and never publish an index', async () => {
        for (const failure of [new Error('network unavailable'), {invalidJson: true}]) {
            const p = page({saved: projection(7), replies: [projection(8), failure, projection(9)]});
            p.resume(); await flush(); await p.schedule(100);
            assert.equal(p.nodes['gav-job-hosts'].textContent, '8 / 10');
            assert.match(p.nodes['gav-job-message'].textContent, /não é um resultado de disponibilidade/);
            assert.equal(p.countTimers(100), 0); assert.equal(p.navigations.length, 0);
            p.resume(); await flush();
            assert.equal(p.calls[2].body.operation, 'status'); assert.equal(p.calls[2].body.sequence, '8');
        }
    }],
    ['25-second timeout remains resumable without assuming the server stopped', async () => {
        const stage = deferred();
        const p = page({saved: projection(4), replies: [projection(4), () => stage.promise, projection(5)]});
        p.resume(); await flush(); await p.schedule(100); await p.schedule(25000);
        assert.equal(p.calls[1].options.signal.aborted, true);
        assert.match(p.nodes['gav-job-message'].textContent, /consulta pode continuar no servidor/);
        assert.equal(p.nodes['gav-job-hosts'].textContent, '4 / 10');
        stage.resolve(projection(5)); await flush();
        assert.equal(p.nodes['gav-job-hosts'].textContent, '4 / 10', 'Late response cannot overwrite confirmed state');
        p.resume(); await flush();
        assert.equal(p.calls[2].body.operation, 'status'); assert.equal(p.calls[2].body.sequence, '4');
    }],
    ['lost start acknowledgement reuses its nonce', async () => {
        const p = page({replies: [new Error('lost acknowledgement'), projection()]});
        p.submit(); await flush();
        const nonce = p.calls[0].body.request_id;
        assert.equal(p.nodes['gav-job-resume'].textContent, 'Tentar novamente');
        p.resume(); await flush();
        assert.equal(p.calls[1].body.operation, 'start'); assert.equal(p.calls[1].body.request_id, nonce);
    }],
    ['terminal start failure unlocks filters and a new calculation uses a new nonce', async () => {
        for (const failure of [{status: 'failed', error: 'Invalid month / Mês inválido', retryable: false},
            projection(0, {status: 'failed', error: 'Invalid month / Mês inválido'})]) {
            const p = page({replies: [failure, projection()]});
            p.submit(); await flush();
            assert.equal(p.nodes['gav-calculate'].disabled, false); assert.equal(p.month.disabled, false);
            assert.equal(p.nodes['gav-job-resume'].hidden, true);
            assert.match(p.nodes['gav-job-message'].textContent, /Mês inválido/);
            const nonce = p.calls[0].body.request_id;
            p.submit(); await flush(); assert.notEqual(p.calls[1].body.request_id, nonce);
        }
        const early = page({saved: projection(0, {status: 'failed', error: 'Cannot start / Não foi possível iniciar',
            snapshot: {month: '', department: -1, department_name: '', timezone: '', from: null, to: null}})});
        assert.equal(early.nodes['gav-job-snapshot'].textContent, 'Período não confirmado');
        assert.doesNotMatch(early.nodes['gav-job-snapshot-note'].textContent, /fixados/);
    }],
    ['busy responses are bounded retries and cannot replace confirmed sequence or counts', async () => {
        const p = page({saved: projection(2), replies: Array(4).fill({status: 'busy', retryable: true, job: jobId, sequence: 999})});
        p.resume(); await flush(); for (let i = 0; i < 3; i++) await p.schedule(1500);
        assert.equal(p.calls.length, 4); assert.equal(p.countTimers(1500), 0);
        assert.equal(p.nodes['gav-job-hosts'].textContent, '2 / 10');
        assert.equal(p.nodes['gav-job-resume'].hidden, false); assert.equal(p.nodes['gav-job-pause'].hidden, true);
        assert.ok(p.calls.every(call => call.body.operation === 'status' && call.body.sequence === '2'));
    }],
    ['busy GET bootstrap can resume with only an owned job ID', async () => {
        const p = page({configured: false, saved: {job: jobId, sequence: 0, status: 'busy', retryable: true, progress: []},
            replies: [projection(17, {snapshot: {...snapshot, department: 2, department_name: 'Saved department'}})]});
        assert.equal(p.calls.length, 0); assert.equal(p.nodes['gav-job-resume'].hidden, false);
        assert.match(p.nodes['gav-job-snapshot'].textContent, /Aguardando/);
        assert.equal(p.nodes['gav-job-period'].textContent, '', 'Unknown snapshot must not show a guessed timezone');
        p.resume(); await flush();
        assert.equal(p.calls[0].body.operation, 'status'); assert.equal(p.calls[0].body.job, jobId);
        assert.equal(p.month.value, '2026-05'); assert.equal(String(p.department.value), '2');
        assert.equal(p.department.options[1].textContent, 'Saved department');
        assert.match(p.nodes['gav-job-snapshot'].textContent, /Saved department/);
    }],
    ['only a validated completed result can navigate', async () => {
        const good = 'zabbix.php?action=governance.availability.view&job=' + jobId;
        const p = page({replies: [projection(1, {status: 'complete', result_url: good})]});
        p.submit(); await flush(); assert.equal(p.navigations.length, 1);
        for (const result_url of ['https://external.test/' + good, 'zabbix.php?action=other&job=' + jobId,
            good + '&job=bad', good + '&action=other', '/other.php?action=governance.availability.view&job=' + jobId,
            'zabbix.php?action=governance.availability.view&job=' + 'b'.repeat(64), 'javascript:alert(1)']) {
            const bad = page({replies: [projection(1, {status: 'complete', result_url})]});
            bad.submit(); await flush();
            assert.equal(bad.navigations.length, 0); assert.match(bad.nodes['gav-job-message'].textContent, /não pôde ser validado/);
        }
    }],
    ['unexpected job IDs and stale projections preserve the known checkpoint', async () => {
        for (const reply of [projection(8, {job: 'b'.repeat(64)}), projection(3)]) {
            const p = page({saved: projection(7), replies: [reply]});
            p.resume(); await flush();
            assert.equal(p.nodes['gav-job-hosts'].textContent, '7 / 10');
            assert.equal(p.countTimers(100), 0); assert.equal(p.nodes['gav-job-resume'].hidden, false);
        }
    }],
    ['leaving stops the next stage and returning does not silently restart', async () => {
        const p = page({replies: [projection(), projection(1)]});
        p.submit(); await flush(); assert.equal(p.countTimers(100), 1);
        p.fireWindow('pagehide'); assert.equal(p.countTimers(100), 0);
        p.fireWindow('pageshow'); assert.equal(p.calls.length, 1);
        assert.equal(p.nodes['gav-job-resume'].hidden, false);
        p.resume(); await flush(); assert.equal(p.calls[1].body.operation, 'status');
    }],
    ['completed report loads without network and English labels remain available', async () => {
        const p = page({saved: projection(1, {status: 'complete', result_url: 'zabbix.php?action=governance.availability.view&job=' + jobId}), report: true});
        assert.equal(p.calls.length, 0); assert.equal(p.navigations.length, 0);
        assert.equal(p.nodes['gav-job'].hidden, true); assert.equal(p.nodes['gav-export'].disabled, false);
        assert.equal(p.nodes['gav-print'].disabled, false); assert.equal(p.nodes['gav-calculate'].disabled, false);
        const english = page({saved: projection(1), language: 'en'});
        assert.equal(english.nodes['gav-job-resume'].textContent, 'Resume calculation');
        assert.equal(english.nodes['gav-job-stage'].textContent, 'Reading history');
        assert.match(english.nodes['gav-job-message'].textContent, /^Saved calculation/);
    }]
];

(async () => {
    for (const [name, run] of tests) {
        try { await run(); }
        catch (error) { error.message = name + ': ' + error.message; throw error; }
    }
    console.log('Availability UI: ' + tests.length + ' workflow regression tests passed (local mocks only).');
})().catch(error => { console.error(error); process.exitCode = 1; });
