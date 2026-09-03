/* Local DOM regression: execute the actual editor, without a Zabbix connection. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
    constructor(tag) { Object.assign(this, {tag, children: [], dataset: {}, events: {}, value: '', className: '', textContent: '', disabled: false}); }
    append(...nodes) { for (const node of nodes) { node.parent = this; this.children.push(node); } }
    setAttribute(key, value) { this[key] = value; }
    matches(selector) {
        if (selector.startsWith('#')) return this.id === selector.slice(1);
        if (selector.startsWith('.')) return this.className.split(' ').includes(selector.slice(1));
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
for (const lang of ['pt', 'en']) {
    const form = new Element('form'); form.id = 'gov-config-form';
    const root = new Element('div'); root.id = 'gov-config'; root.dataset.lang = lang; form.append(root);
    const ids = ['pages', 'panels', 'status', 'error', 'empty'];
    for (const id of ids) { const node = new Element('div'); node.id = 'gov-config-' + id; root.append(node); }
    for (const id of ['gov-quality-payload', 'gov-quality-page', 'gov-draft-copy', 'gov-draft-backup', 'gov-quality-data']) {
        const node = new Element('input'); node.id = id; root.append(node);
    }
    for (const id of ['gov-add-page', 'gov-add-card', 'gov-save']) { const node = new Element('button'); node.id = id; root.append(node); }
    const find = id => root.querySelector('#' + id);
    find('gov-quality-page').value = 'main';
    find('gov-quality-data').textContent = JSON.stringify([{id: 'main', name: '', cards: [{id: 'old', type: 'inventory', title: 'Old', description: '', tag_names: '', tag_values: '', group_names: '', include_score: 1}]}]);
    vm.runInNewContext(source, {document: {readyState: 'complete', getElementById: id => id === form.id ? form : id === root.id ? root : find(id), createElement: tag => new Element(tag)}, window: {confirm: () => true}, console});
    assert.equal(find('gov-save').disabled, false, 'Editor initializes');
    const payload = () => JSON.parse(find('gov-quality-payload').value);
    assert.equal(payload()[0].cards[0].display_mode, 'conformity', 'Legacy display preserved');
    find('gov-add-card').fire('click');
    assert.equal(payload()[0].cards.length, 2, 'Add card works');
    const cards = root.querySelectorAll('.gqp-card');
    const field = key => cards[1].querySelector('[data-field="' + key + '"]');
    const set = (key, value) => { field(key).value = value; field(key).fire('change'); };
    set('title', 'DBD without OS template'); set('type', 'templates');
    set('scope_tag_name', 'Departamento'); set('scope_tag_value', 'DBD');
    set('scope_group_names', 'DBD'); set('scope_include_subgroups', '1');
    set('template_names', 'Linux,Windows'); set('template_mode', 'any');
    let saved = payload()[0].cards[1];
    assert.equal(saved.scope_tag_value, 'DBD'); assert.equal(saved.template_names, 'Linux,Windows');
    assert.equal(saved.display_mode, 'non_conformity');
    assert.equal(field('template_names').disabled, false);
    assert.equal(field('inventory_field').disabled, true);
    assert.ok(!form.fire('submit').prevented, 'Valid crossed filters submit');
    set('scope_tag_name', '');
    assert.ok(form.fire('submit').prevented, 'Tag value without name blocked');
    set('scope_tag_name', 'Departamento'); set('type', 'inventory'); set('inventory_field', 'os');
    assert.equal(field('template_names').disabled, true);
    assert.equal(field('inventory_field').disabled, false);
    assert.ok(!form.fire('submit').prevented, 'Inventory scope submits');
    assert.equal(payload()[0].cards[1].inventory_field, 'os');
    find('gov-add-page').fire('click');
    assert.equal(payload().length, 2, 'Page creation preserves first page filters');
    assert.equal(payload()[0].cards[1].scope_tag_value, 'DBD');
}
console.log('PASS: quality editor crossed filters, add card, pages and validation (PT/EN, local DOM).');
