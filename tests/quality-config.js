/* Local DOM regression: execute the actual editor, without a Zabbix connection. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
    constructor(tag) { Object.assign(this, {tag, children: [], dataset: {}, events: {}, value: '', className: '', textContent: '', disabled: false}); }
    append(...nodes) { for (const node of nodes) { node.parent = this; this.children.push(node); } }
    replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
    remove() { this.parent.children = this.parent.children.filter(n => n !== this); }
    setAttribute(key, value) { this[key] = value; }
    get textContent() { return (this._text || '') + this.children.map(n => n.textContent).join(''); }
    set textContent(value) { this._text = String(value); this.children = []; }
    matches(selector) {
        if (selector.startsWith('#')) return this.id === selector.slice(1);
        if (selector.startsWith('.')) return this.className.split(' ').includes(selector.slice(1));
        if (selector === '[name="sid"]') return this.name === 'sid';
        const data = selector.match(/^\[data-([a-z-]+)(?:="([^"]*)")?\]$/);
        if (data) { const key = data[1].replace(/-([a-z])/g, (_, c) => c.toUpperCase()); return key in this.dataset && (data[2] === undefined || this.dataset[key] === data[2]); }
        return this.tag === selector;
    }
    querySelectorAll(selector) {
        const found = [];
        for (const child of this.children) {
            if (selector.split(',').some(s => child.matches(s.trim()))) found.push(child);
            found.push(...child.querySelectorAll(selector));
        }
        return found;
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    closest(selector) { for (let n = this; n; n = n.parent) if (n.matches(selector)) return n; return null; }
    contains(node) { return node === this || this.children.some(c => c.contains(node)); }
    addEventListener(type, fn) { (this.events[type] ||= []).push(fn); }
    fire(type) { const e = {target: this, preventDefault() { this.prevented = true; }}; for (let n = this; n; n = n.parent) for (const fn of n.events[type] || []) fn(e); return e; }
    setCustomValidity(message) { this.validationMessage = message; }
    checkValidity() { return this.disabled || (!this.validationMessage && (!this.required || !!this.value)); }
    reportValidity() {}
    focus() {}
    select() {}
    scrollIntoView() {}
}
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/config.js'), 'utf8');
(async () => {
for (const lang of ['pt', 'en']) {
    const form = new Element('form'); form.id = 'gov-config-form';
    const root = new Element('div'); root.id = 'gov-config'; root.dataset.lang = lang; form.append(root);
    const sid = new Element('input'); sid.name = 'sid'; sid.value = 'synthetic-token'; form.append(sid);
    const ids = ['pages', 'panels', 'status', 'error', 'empty'];
    for (const id of ids) { const node = new Element('div'); node.id = 'gov-config-' + id; root.append(node); }
    for (const id of ['gov-quality-payload', 'gov-quality-page', 'gov-draft-copy', 'gov-draft-backup', 'gov-quality-data']) {
        const node = new Element('input'); node.id = id; root.append(node);
    }
    for (const id of ['gov-add-page', 'gov-add-card', 'gov-save']) { const node = new Element('button'); node.id = id; root.append(node); }
    const find = id => root.querySelector('#' + id);
    find('gov-quality-page').value = 'main';
    find('gov-quality-data').textContent = JSON.stringify([{id: 'main', name: '', cards: [{id: 'old', type: 'inventory', title: 'Old', description: '', tag_names: '', tag_values: '', group_names: '', include_score: 1, scope_tag_name:'Departamento', scope_tag_value:'DBD', scope_group_names:'DBD,Other', scope_include_subgroups:0}]}]);
    const calls = []; let delayed = false, resolveDelayed;
    const response = {job: 'a'.repeat(64), sequence: 1, status: 'complete', page: 'preview', progress: {}, result: {
        total_hosts: 2, kpis: [{total_count: 2, valid_count: 1}], preview_hosts: [{hostid:'1', name:'<unsafe host>', compliant:true}, {hostid:'2',name:'Host 2',compliant:false}]
    }};
    const fetch = async (url, options) => {
        calls.push({url, body: Object.fromEntries(new URLSearchParams(options.body))});
        if (calls.at(-1).body.card_json && response.result.kpis[0]) response.result.kpis[0].id = JSON.parse(calls.at(-1).body.card_json).id;
        if (delayed) await new Promise(resolve => { resolveDelayed = resolve; });
        return {ok:true, json:async()=>response};
    };
    vm.runInNewContext(source, {document: {readyState: 'complete', getElementById: id => id === form.id ? form : id === root.id ? root : find(id), createElement: tag => new Element(tag)},
        window: {confirm: () => true, addEventListener() {}, fetch, crypto:require('node:crypto').webcrypto}, URLSearchParams, Uint8Array, AbortController, setTimeout, clearTimeout, console});
    assert.equal(find('gov-save').disabled, false, 'Editor initializes');
    const payload = () => JSON.parse(find('gov-quality-payload').value);
    assert.equal(payload()[0].cards[0].display_mode, 'conformity', 'Legacy display preserved');
    assert.equal(payload()[0].cards[0].selection.mode, 'all', 'Legacy intersection preserved');
    assert.equal(payload()[0].cards[0].selection.conditions[1].value, 'DBD,Other', 'Legacy OR group list remains a single condition');
    assert.equal(payload()[0].cards[0].selection.conditions[1].subgroups, 0, 'Legacy exact group option preserved');
    find('gov-add-card').fire('click');
    assert.equal(payload()[0].cards.length, 2, 'Add card works');
    const cards = root.querySelectorAll('.gqp-card');
    const field = key => cards[1].querySelector('[data-field="' + key + '"]');
    const set = (key, value) => { field(key).value = value; field(key).fire('change'); };
    set('title', 'DBD without OS template'); set('type', 'templates');
    cards[1].querySelector('.gqp-add-condition').fire('click');
    const row1 = cards[1].querySelector('.gqp-conditions').children[0];
    const conditionSet = (row, key, value) => { const control = row.querySelector('[data-field="condition_' + key + '"]'); control.value = value; control.fire('change'); };
    conditionSet(row1, 'name', 'Departamento'); conditionSet(row1, 'value', 'DBD');
    cards[1].querySelector('.gqp-add-condition').fire('click');
    const row2 = cards[1].querySelector('.gqp-conditions').children[1];
    conditionSet(row2, 'type', 'group'); conditionSet(row2, 'value', 'DBD');
    set('template_names', 'Linux,Windows'); set('template_mode', 'any');
    let saved = payload()[0].cards[1];
    assert.equal(saved.selection.conditions[0].value, 'DBD'); assert.equal(saved.selection.conditions[1].type, 'group'); assert.equal(saved.template_names, 'Linux,Windows');
    assert.equal(cards[1].querySelector('.gqp-expression').textContent, lang === 'pt' ? 'A E B' : 'A AND B');
    set('selection_mode', 'any');
    assert.equal(cards[1].querySelector('.gqp-expression').textContent, lang === 'pt' ? 'A OU B' : 'A OR B');
    set('selection_mode', 'all');
    set('selection_mode', 'custom');
    set('selection_formula', '(A or B)');
    assert.ok(!form.fire('submit').prevented, 'Valid custom expression submits');
    assert.equal(payload()[0].cards[1].selection.formula,'(A or B)');
    set('selection_formula','A');
    assert.ok(form.fire('submit').prevented, 'Unused conditions rejected');
    set('selection_formula','A or Z');
    assert.ok(form.fire('submit').prevented, 'Unknown condition label rejected');
    set('selection_formula','(A or B)');
    cards[1].querySelector('.gqp-add-condition').fire('click');
    assert.equal(field('selection_formula').value,'','Adding condition invalidates formula');
    cards[1].querySelector('.gqp-conditions').children[2].querySelector('.gqp-remove-condition').fire('click');
    assert.equal(field('selection_formula').value,'','Removing condition never reinterprets shifted labels');
    set('selection_mode','all');
    assert.equal(saved.display_mode, 'conformity');
    assert.equal(field('template_names').disabled, false);
    assert.equal(field('inventory_field').disabled, true);
    assert.ok(!form.fire('submit').prevented, 'Valid crossed filters submit');
    conditionSet(row1, 'name', '');
    assert.ok(form.fire('submit').prevented, 'Tag value without name blocked');
    conditionSet(row1, 'name', 'Departamento'); set('type', 'inventory'); set('inventory_field', 'os');
    assert.equal(field('template_names').disabled, true);
    assert.equal(field('inventory_field').disabled, false);
    assert.ok(!form.fire('submit').prevented, 'Inventory scope submits');
    assert.equal(payload()[0].cards[1].inventory_field, 'os');
    assert.equal(calls.length, 0, 'No network on load or edits');
    const preview = cards[1].querySelector('.gqp-preview');
    preview.querySelector('button').fire('click');
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(calls.length, 1, 'Preview runs only on click');
    assert.equal(calls[0].body.operation, 'preview_start');
    assert.equal(calls[0].body.sid, 'synthetic-token', 'Native CSRF token sent');
    assert.equal(JSON.parse(calls[0].body.card_json).selection.conditions.length, 2, 'Unsaved conditions sent');
    assert.ok(preview.querySelector('.gqp-preview-output').textContent.includes('<unsafe host>'), 'Host name rendered as text');
    set('title', 'Edited');
    assert.equal(preview.querySelector('.gqp-preview-output').textContent, '', 'Editing clears obsolete preview');
    delayed = true; preview.querySelector('button').fire('click');
    set('title', 'Changed during request'); resolveDelayed();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(preview.querySelector('.gqp-preview-output').textContent, '', 'Late response cannot repopulate stale preview');
    preview.querySelector('button').fire('click');
    preview.querySelector('.gqp-cancel-preview').fire('click'); resolveDelayed();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(preview.querySelector('.gqp-preview-output').textContent, lang === 'pt' ? 'Prévia cancelada.' : 'Preview cancelled.');
    delayed = false;
    response.result.kpis = [];
    preview.querySelector('button').fire('click'); await new Promise(resolve => setImmediate(resolve));
    assert.ok(preview.querySelector('.gqp-preview-output').textContent.includes(lang === 'pt' ? 'Não foi possível testar' : 'Could not test'), 'Missing KPI never becomes a zero-host success');
    find('gov-add-page').fire('click');
    assert.equal(payload().length, 2, 'Page creation preserves first page filters');
    assert.equal(payload()[0].cards[1].selection.conditions[0].value, 'DBD');
}
console.log('PASS: quality editor crossed filters, add card, pages and validation (PT/EN, local DOM).');
})().catch(error => { console.error(error); process.exitCode = 1; });
