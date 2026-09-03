/* Local editor regression tests. Minimal DOM, no packages, browser, network or Zabbix. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'availability-config.js'), 'utf8');
const decode = value => value.replace(/&(?:amp|lt|gt|quot|#39);/g,
    entity => ({'&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#39;': "'"}[entity]));

class Element {
    constructor(tag, attributes = {}) {
        this.tagName = tag.toUpperCase(); this.attributes = attributes; this.children = []; this.parentElement = null;
        this.dataset = {}; this.events = {}; this._text = ''; this._value = undefined;
        this.disabled = 'disabled' in attributes; this.required = 'required' in attributes; this.hidden = 'hidden' in attributes;
        this.open = 'open' in attributes; this.validationMessage = '';
        for (const [key, value] of Object.entries(attributes)) {
            if (key.startsWith('data-')) this.dataset[key.slice(5).replace(/-([a-z])/g, (_, char) => char.toUpperCase())] = value;
        }
        this.classList = {add() {}};
    }
    get id() { return this.attributes.id; }
    get type() { return this.attributes.type || ''; }
    get value() {
        if (this._value !== undefined) return this._value;
        if (this.tagName === 'SELECT') {
            const options = this.querySelectorAll('option');
            return (options.find(option => 'selected' in option.attributes) || options[0])?.value || '';
        }
        return this.attributes.value || '';
    }
    set value(value) { this._value = String(value); }
    get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
    set textContent(value) { this._text = String(value); this.children = []; }
    get lastElementChild() { return this.children[this.children.length - 1] || null; }
    append(child) { child.parentElement = this; this.children.push(child); return child; }
    remove() {
        if (this.parentElement) this.parentElement.children = this.parentElement.children.filter(child => child !== this);
        this.parentElement = null;
    }
    focus() { this.focused = true; }
    setCustomValidity(message) { this.validationMessage = message; }
    matches(selector) {
        const parts = selector.trim().split(/\s+/);
        const matchSimple = (node, token) => {
            if (token.startsWith('.')) return (node.attributes.class || '').split(/\s+/).includes(token.slice(1));
            if (token.startsWith('#')) return node.id === token.slice(1);
            if (token.startsWith('[')) {
                const match = token.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);
                return !!match && Object.prototype.hasOwnProperty.call(node.attributes, match[1])
                    && (match[2] === undefined || node.attributes[match[1]] === match[2]);
            }
            return node.tagName === token.toUpperCase();
        };
        if (!matchSimple(this, parts.pop())) return false;
        let ancestor = this.parentElement;
        while (parts.length) {
            const part = parts.pop();
            while (ancestor && !matchSimple(ancestor, part)) ancestor = ancestor.parentElement;
            if (!ancestor) return false;
            ancestor = ancestor.parentElement;
        }
        return true;
    }
    closest(selector) {
        for (let node = this; node; node = node.parentElement) if (node.matches(selector)) return node;
        return null;
    }
    querySelectorAll(selector) {
        const result = [], choices = selector.split(',');
        const visit = node => {
            for (const child of node.children) {
                if (choices.some(choice => child.matches(choice))) result.push(child);
                visit(child);
            }
        };
        visit(this); return result;
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    set innerHTML(html) { this.children = []; this._text = ''; this.insertAdjacentHTML('beforeend', html); }
    insertAdjacentHTML(position, html) {
        assert.equal(position, 'beforeend');
        const stack = [this];
        for (const token of html.match(/<[^>]+>|[^<]+/g) || []) {
            if (token.startsWith('</')) { stack.pop(); continue; }
            if (token.startsWith('<')) {
                const match = token.match(/^<([a-z][a-z0-9-]*)\b([^>]*)>/i);
                if (!match) continue;
                const attributes = {};
                for (const attribute of match[2].matchAll(/([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g)) {
                    attributes[attribute[1]] = decode(attribute[2] ?? attribute[3] ?? attribute[4] ?? '');
                }
                const element = stack[stack.length - 1].append(new Element(match[1], attributes));
                if (!['INPUT', 'BR', 'HR', 'META', 'LINK'].includes(element.tagName)) stack.push(element);
            }
            else stack[stack.length - 1]._text += decode(token);
        }
        assert.equal(stack.length, 1, 'generated markup is balanced');
    }
    addEventListener(type, callback) { (this.events[type] ||= []).push(callback); }
    fire(type) {
        const event = {target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }};
        for (let node = this; node; node = node.parentElement) {
            for (const callback of node.events[type] || []) callback(event);
        }
        return event;
    }
    isValid() {
        if (this.disabled) return true;
        if (this.validationMessage || (this.required && this.value === '')) return false;
        if (!this.value) return true;
        if (this.type === 'number') {
            const value = Number(this.value);
            if (!Number.isFinite(value) || ('min' in this.attributes && value < Number(this.attributes.min))
                || ('max' in this.attributes && value > Number(this.attributes.max))) return false;
        }
        if (this.attributes.pattern && !(new RegExp('^(?:' + this.attributes.pattern + ')$')).test(this.value)) return false;
        return true;
    }
}

const check = {key: 'ping', max_age: 180, up: {op: 'eq', a: 1}, down: null};
const item = {name: 'Item service', weight: 2, target: 99.9, mode: 'any_down', groups: 'Team/Database', checks: [check]};
const sla = {name: 'SLA service', weight: 4, target: 99.9, source: 'sla', slaid: '9007199254740993', serviceid: '9223372036854775807'};
const configuration = (...technologies) => ({timezone: 'America/Cuiaba', departments: [{name: 'Test department', target: 99.9, technologies}]});
const field = (node, name) => node.querySelector(`[data-field="${name}"]`);

function page(config, language = 'pt') {
    const nodes = {}, form = new Element('form', {id: 'gav-config-form'});
    nodes[form.id] = form;
    const add = (id, tag = 'div', parent = form, attrs = {}) => {
        const node = parent.append(new Element(tag, {id, ...attrs})); nodes[id] = node; return node;
    };
    const root = add('gav-config'); root.dataset.lang = language;
    add('gav-timezone', 'input', root, {value: config.timezone, type: 'text', required: ''});
    add('gav-data-policy', 'select', root, {required: ''}).innerHTML = '<option value="strict">Strict</option><option value="observed">Observed</option>';
    for (const id of ['gav-departments', 'gav-config-status', 'gav-config-empty', 'gav-config-count', 'gav-legacy-notice']) add(id, 'div', root);
    add('gav-payload', 'input', root, {type: 'hidden'});
    add('gav-config-revision', 'input', root, {type: 'hidden', name: 'config_revision', value: 'reviewed-revision'});
    add('gav-config-data', 'script', root).textContent = JSON.stringify(config);
    for (const id of ['gav-add-department', 'gav-save']) add(id, 'button', root, {disabled: ''});
    let network = 0, navigations = 0;
    const window = {confirm: () => true, location: {href: 'https://local.test/zabbix.php?action=governance.availability.config',
        assign() { navigations++; }}, open() { navigations++; }};
    const fetch = () => { network++; throw new Error('Editor must not use network'); };
    vm.runInNewContext(source, {window, document: {readyState: 'complete', getElementById: id => nodes[id] || null}, URL, fetch});
    assert.equal(nodes['gav-save'].disabled, false, nodes['gav-config-status'].textContent);
    const change = (node, value) => { node.value = value; node.fire('change'); };
    const submit = (native = true) => {
        if (native) {
            const invalid = form.querySelectorAll('input, select').find(node => !node.isValid());
            if (invalid) { invalid.fire('invalid'); return {defaultPrevented: true, invalid}; }
        }
        return form.fire('submit');
    };
    return {root, form, nodes, change, submit, techs: () => root.querySelectorAll('.gav-technology'),
        data: () => JSON.parse(nodes['gav-payload'].value), get network() { return network; }, get navigations() { return navigations; }};
}

const tests = [
    ['compact checks preserve rules, reveal invalid fields and keep help collapsed', () => {
        for (const language of ['pt', 'en']) {
            const p = page(configuration(item), language), tech = p.techs()[0], checkNode = tech.querySelector('.gav-check');
            const before = p.data();
            assert.equal(checkNode.tagName, 'DETAILS');
            assert.equal(checkNode.open, false);
            assert.match(checkNode.querySelector('.gav-check-caption').textContent, /1\. ping/);
            assert.match(checkNode.querySelector('.gav-check-meta').textContent, /180 s/);
            assert.equal(checkNode.querySelector('.gav-editor-help').open, false);
            checkNode.open = true; checkNode.open = false;
            assert.equal(p.submit().defaultPrevented, false);
            assert.deepEqual(p.data(), before, 'collapsing never changes saved rules');
            p.change(field(checkNode, 'key'), '');
            assert.equal(p.submit().defaultPrevented, true);
            assert.equal(checkNode.open, true, 'invalid nested check expands');
            assert.equal(tech.open, true);
            p.change(field(checkNode, 'key'), '<unsafe>');
            assert.match(checkNode.querySelector('.gav-check-caption').textContent, /<unsafe>/);
            assert.equal(checkNode.querySelector('.gav-check-caption').children.length, 0, 'key is plain text');
            tech.querySelector('[data-action="add-check"]').fire('click');
            assert.equal(tech.querySelectorAll('.gav-check')[1].open, true, 'new check opens for editing');
            assert.equal(p.network, 0);
        }
    }],
    ['weight participation is local to department and never persisted', () => {
        for (const language of ['pt', 'en']) {
            const config = configuration({...item,weight:4}, {...item,weight:2}, {...sla,weight:1});
            config.departments.push({name:'Other', target:99, technologies:[{...item,weight:10}]});
            const p = page(config, language), techs = p.techs();
            const fraction = language === 'pt' ? '57,14%' : '57.14%';
            assert.ok(techs[0].querySelector('.gav-summary-meta').textContent.includes(fraction));
            assert.match(techs[3].querySelector('.gav-summary-meta').textContent, /100%/);
            assert.ok(!JSON.stringify(p.data()).includes('share'), 'presentation only');
            p.change(field(techs[0], 'weight'), '3');
            assert.match(techs[0].querySelector('.gav-summary-meta').textContent, /50%/);
            p.change(field(techs[1], 'weight'), '');
            assert.match(techs[0].querySelector('.gav-summary-meta').textContent, /—/);
            assert.match(techs[3].querySelector('.gav-summary-meta').textContent, /100%/);
            assert.equal(p.network, 0);
        }
    }],
    ['global data policy defaults only absent legacy values to strict and roundtrips explicit choices', () => {
        for (const policy of [undefined, 'strict', 'observed']) {
            const config = configuration(item, sla);
            if (policy !== undefined) config.data_policy = policy;
            const before = JSON.stringify(config), p = page(config);
            assert.equal(p.nodes['gav-data-policy'].value, policy ?? 'strict');
            assert.equal(p.data().data_policy, policy ?? 'strict');
            assert.equal(JSON.stringify(config), before, 'opening the editor must not mutate the input');
            assert.equal(p.submit().defaultPrevented, false);
            assert.deepEqual(page(p.data()).data(), p.data(), 'the chosen policy survives a saved JSON roundtrip');
            assert.equal(p.data().departments[0].technologies[1].slaid, sla.slaid);
            assert.equal(p.data().departments[0].technologies[1].serviceid, sla.serviceid);
        }
        const empty = page({timezone: 'UTC', departments: []});
        assert.deepEqual(empty.data(), {timezone: 'UTC', data_policy: 'strict', departments: []});
    }],
    ['switching data policies preserves item and SLA drafts, validation, source and revision', () => {
        const p = page(configuration(item, sla)), tech = p.techs()[0], native = p.techs()[1];
        p.change(field(tech, 'max_age'), '456');
        p.change(field(tech, 'groups'), 'Changed group');
        p.change(field(tech, 'slaid'), '7'); p.change(field(tech, 'serviceid'), '42');
        p.change(field(native, 'sla_url'), 'unsaved optional helper draft');
        const original = p.data();
        for (const policy of ['observed', 'strict', 'observed']) {
            p.change(p.nodes['gav-data-policy'], policy);
            assert.deepEqual(p.data(), {...original, data_policy: policy});
            assert.equal(p.techs()[0], tech, 'policy switching does not rebuild item fields');
            assert.equal(p.techs()[1], native, 'policy switching does not rebuild SLA fields');
            assert.equal(field(tech, 'slaid').value, '7'); assert.equal(field(tech, 'serviceid').value, '42');
            assert.equal(field(tech, 'slaid').disabled, true); assert.equal(field(tech, 'slaid').required, false);
            assert.equal(field(native, 'sla_url').value, 'unsaved optional helper draft');
            assert.equal(field(native, 'groups').disabled, true);
            assert.equal(p.nodes['gav-config-revision'].value, 'reviewed-revision');
            assert.match(p.nodes['gav-config-status'].textContent, /Alterações não salvas/);
            assert.equal(p.submit().defaultPrevented, false);
        }
        p.change(field(tech, 'source'), 'sla');
        assert.equal(p.data().data_policy, 'observed');
        assert.equal(p.data().departments[0].technologies[0].slaid, '7');
        assert.equal(p.data().departments[0].technologies[0].serviceid, '42');
        p.change(p.nodes['gav-data-policy'], 'strict');
        p.change(field(tech, 'source'), 'items');
        assert.equal(p.data().data_policy, 'strict');
        assert.deepEqual(p.data().departments, original.departments);
        assert.equal(p.network, 0); assert.equal(p.navigations, 0);
    }],
    ['invalid explicit data policy stays invalid instead of silently selecting strict', () => {
        for (const policy of [null, '', 0, 1, true, false, [], ['strict'], {}, 'STRICT', 'available', 'strict ', ' observed']) {
            const p = page({...configuration(item, sla), data_policy: policy});
            assert.equal(p.nodes['gav-data-policy'].value, '', JSON.stringify(policy));
            assert.equal(p.data().data_policy, '', 'invalid drafts do not acquire a different valid policy');
            assert.notEqual(p.nodes['gav-data-policy'].validationMessage, '');
            assert.equal(p.submit().defaultPrevented, true);
            assert.equal(p.submit(false).defaultPrevented, true, 'the submit guard also rejects invalid policy');
            assert.match(p.nodes['gav-config-status'].textContent, /política de dados válida/);
            p.change(p.nodes['gav-data-policy'], 'observed');
            assert.equal(p.nodes['gav-data-policy'].validationMessage, '');
            assert.equal(p.submit().defaultPrevented, false, 'an explicit correction allows the retained draft to save');
            assert.equal(p.data().data_policy, 'observed');
            assert.deepEqual(p.data().departments[0].technologies[1], sla);
        }
    }],
    ['data policy remains validated when native form validation is bypassed', () => {
        const p = page({...configuration(item), data_policy: 'observed'}, 'en');
        p.nodes['gav-data-policy'].value = 'ignore';
        assert.equal(p.submit(false).defaultPrevented, true);
        assert.match(p.nodes['gav-config-status'].textContent, /Select a valid data policy/);
        p.change(p.nodes['gav-data-policy'], 'strict');
        assert.equal(p.submit().defaultPrevented, false);
        assert.equal(p.data().data_policy, 'strict');
    }],
    ['observed mode keeps required item fields, manual validity and native IDs mandatory', () => {
        const p = page({...configuration(item), data_policy: 'observed'}), tech = p.techs()[0];
        for (const [name, invalid, valid] of [['key', '', 'ping'], ['groups', '', item.groups], ['max_age', '0', '180']]) {
            const control = field(tech, name);
            assert.equal(control.required, true, name + ' remains required');
            p.change(control, invalid); assert.equal(p.submit().defaultPrevented, true);
            p.change(control, valid); assert.equal(p.submit().defaultPrevented, false);
        }
        tech.querySelector('[data-action="remove-check"]').fire('click');
        assert.equal(p.submit().defaultPrevented, true, 'observed mode still needs checks');
        p.change(field(tech, 'source'), 'sla');
        assert.equal(p.submit().defaultPrevented, true, 'SLA still needs both explicit IDs');
        p.change(field(tech, 'slaid'), sla.slaid); p.change(field(tech, 'serviceid'), sla.serviceid);
        assert.equal(p.submit().defaultPrevented, false);
        assert.equal(p.data().data_policy, 'observed');
        assert.equal(p.data().departments[0].technologies[0].source, 'sla');
    }],
    ['legacy defaults to items and preserves manual validity and thresholds', () => {
        const legacy = {...item, max_age: 3600, checks: [{key: 'pgsql.ping["{$PG.URI}"]', up: {op: 'eq', a: 1}, down: null}]};
        const p = page(configuration(legacy)), tech = p.techs()[0];
        const value = p.data().departments[0].technologies[0];
        assert.equal(value.source, 'items'); assert.equal(value.groups, legacy.groups);
        assert.equal(value.checks[0].max_age, 3600); assert.equal(value.checks[0].key, legacy.checks[0].key);
        assert.equal(p.nodes['gav-legacy-notice'].hidden, false);
        assert.equal(field(tech, 'slaid').disabled, true); assert.equal(field(tech, 'slaid').required, false);
        assert.equal(p.submit().defaultPrevented, false);
    }],
    ['SLA opens without checks and keeps large IDs as strings', () => {
        const p = page(configuration(sla)), tech = p.techs()[0];
        assert.deepEqual(p.data().departments[0].technologies[0], sla);
        assert.equal(field(tech, 'slaid').type, 'text'); assert.equal(field(tech, 'serviceid').type, 'text');
        assert.ok(Number(field(tech, 'slaid').attributes.maxlength) > 19, 'pasting an oversized ID must not truncate it into a valid ID');
        assert.ok(Number(field(tech, 'sla_url').attributes.maxlength) > 4096, 'oversized pasted URLs must reach the parser length rejection');
        assert.equal(field(tech, 'slaid').value, sla.slaid); assert.equal(field(tech, 'serviceid').value, sla.serviceid);
        const inactive = tech.querySelector('[data-source-panel="items"]');
        assert.equal(inactive.hidden, true);
        for (const control of inactive.querySelectorAll('input, select, button')) {
            assert.equal(control.disabled, true); if (control.tagName === 'INPUT') assert.equal(control.required, false);
        }
        assert.match(tech.querySelector('.gav-summary-meta').textContent, /SLA nativo mensal/);
        assert.equal(p.nodes['gav-legacy-notice'].hidden, true);
        assert.equal(p.submit().defaultPrevented, false);
    }],
    ['source switches preserve both drafts and exclude hidden validation and serialization', () => {
        const p = page(configuration(item)), tech = p.techs()[0], sourceControl = field(tech, 'source');
        const rule = tech.querySelector('[data-side="up"]');
        p.change(field(tech, 'groups'), 'Changed group'); p.change(field(tech, 'max_age'), '123');
        p.change(field(rule, 'op'), 'range'); p.change(field(rule, 'a'), '5'); p.change(field(rule, 'b'), '2');
        assert.notEqual(field(rule, 'b').validationMessage, ''); assert.equal(p.submit().defaultPrevented, true);
        p.change(sourceControl, 'sla'); p.change(field(tech, 'slaid'), sla.slaid); p.change(field(tech, 'serviceid'), sla.serviceid);
        p.change(field(tech, 'sla_url'), 'invalid optional helper text');
        assert.equal(field(rule, 'b').validationMessage, ''); assert.equal(p.submit().defaultPrevented, false);
        const savedSla = p.data().departments[0].technologies[0];
        assert.equal(savedSla.source, 'sla'); assert.equal(savedSla.slaid, sla.slaid);
        for (const name of ['groups', 'mode', 'checks', 'max_age', 'sla_url']) assert.equal(name in savedSla, false);
        p.change(sourceControl, 'items');
        assert.equal(field(tech, 'groups').value, 'Changed group'); assert.equal(field(tech, 'max_age').value, '123');
        assert.equal(field(rule, 'b').value, '2'); assert.notEqual(field(rule, 'b').validationMessage, '');
        assert.equal(p.submit().defaultPrevented, true);
        p.change(field(rule, 'b'), '6'); assert.equal(p.submit().defaultPrevented, false);
        const savedItems = p.data().departments[0].technologies[0];
        assert.deepEqual(savedItems.checks[0].up, {op: 'range', a: 5, b: 6});
        assert.equal(savedItems.checks[0].max_age, 123);
        for (const name of ['slaid', 'serviceid', 'sla_url']) assert.equal(name in savedItems, false);
        p.change(sourceControl, 'sla');
        assert.equal(field(tech, 'slaid').value, sla.slaid); assert.equal(field(tech, 'serviceid').value, sla.serviceid);
        assert.equal(field(tech, 'sla_url').value, 'invalid optional helper text');
        assert.equal(p.submit().defaultPrevented, false);
    }],
    ['IDs are validated as canonical signed-64 strings without numeric conversion', () => {
        const p = page(configuration(sla)), tech = p.techs()[0];
        for (const name of ['slaid', 'serviceid']) {
            const control = field(tech, name), original = control.value;
            for (const invalid of ['', '0', '01', '-1', '+1', '1.0', '1e3', '1 ', ' 1', '1\n', '１２', '9223372036854775808', '18446744073709551615']) {
                p.change(control, invalid);
                assert.equal(p.submit().defaultPrevented, true, name + ': ' + JSON.stringify(invalid));
                assert.equal(p.submit(false).defaultPrevented, true, 'submit guard: ' + name + ': ' + JSON.stringify(invalid));
            }
            p.change(control, original);
        }
        assert.equal(p.submit().defaultPrevented, false);
        const numeric = page(configuration({...sla, slaid: 1}));
        assert.equal(field(numeric.techs()[0], 'slaid').value, '', 'numeric JSON IDs must not silently convert');
        assert.equal(numeric.submit().defaultPrevented, true);
    }],
    ['optional URL parser fills exact IDs locally and never persists or visits the address', () => {
        const p = page(configuration({...sla, slaid: '7', serviceid: '42'})), tech = p.techs()[0];
        const prefix = 'https://local.test/zabbix.php?action=slareport.list&filter_set=1';
        p.change(field(tech, 'sla_url'), prefix + '&filter_slaid=' + sla.slaid + '&filter_serviceid=' + sla.serviceid);
        tech.querySelector('[data-action="import-sla-url"]').fire('click');
        assert.equal(field(tech, 'slaid').value, sla.slaid); assert.equal(field(tech, 'serviceid').value, sla.serviceid);
        assert.match(tech.querySelector('.gav-sla-import-status').textContent, /IDs copiados/);
        assert.equal(p.network, 0); assert.equal(p.navigations, 0);
        assert.equal('sla_url' in p.data().departments[0].technologies[0], false);
        p.change(field(tech, 'sla_url'), '?action=slareport.list&filter_slaid=%37&filter_serviceid=%34%32#report');
        tech.querySelector('[data-action="import-sla-url"]').fire('click');
        assert.equal(field(tech, 'slaid').value, '7'); assert.equal(field(tech, 'serviceid').value, '42');
        assert.equal(p.submit().defaultPrevented, false);
    }],
    ['malformed, foreign, ambiguous and out-of-range URLs cannot replace IDs', () => {
        const p = page(configuration(sla)), tech = p.techs()[0];
        const good = 'zabbix.php?action=slareport.list&filter_slaid=7&filter_serviceid=42';
        const invalid = ['', 'not a URL', 'javascript:alert(1)', 'data:text/plain,' + good, 'https://other.test/' + good,
            'https://user:password@local.test/' + good, '/other.php?action=slareport.list&filter_slaid=7&filter_serviceid=42',
            good + '&filter_slaid=8', good + '&filter_serviceid=43', good + '&action=slareport.list',
            good.replace('slareport.list', 'service.list'), good.replace('&filter_serviceid=42', ''),
            good.replace('filter_slaid=7', 'filter_slaid=01'), good.replace('filter_serviceid=42', 'filter_serviceid=0'),
            good.replace('filter_slaid=7', 'filter_slaid=9223372036854775808'),
            good.replace('filter_slaid=7', 'filter_slaid=7%0A'), good + '\n', 'x'.repeat(4097)];
        for (const url of invalid) {
            p.change(field(tech, 'sla_url'), url); tech.querySelector('[data-action="import-sla-url"]').fire('click');
            assert.match(tech.querySelector('.gav-sla-import-status').textContent, /Endereço inválido/);
            assert.equal(field(tech, 'slaid').value, sla.slaid); assert.equal(field(tech, 'serviceid').value, sla.serviceid);
        }
        assert.equal(p.network, 0); assert.equal(p.navigations, 0);
        assert.equal(p.submit().defaultPrevented, false, 'invalid optional helper text is not a saved field');
    }],
    ['requirements depend on source and a department still requires a technology', () => {
        const p = page(configuration(item)), tech = p.techs()[0];
        tech.querySelector('[data-action="remove-check"]').fire('click');
        assert.equal(p.submit().defaultPrevented, true);
        p.change(field(tech, 'source'), 'sla'); p.change(field(tech, 'slaid'), '7'); p.change(field(tech, 'serviceid'), '42');
        assert.equal(p.submit().defaultPrevented, false);
        tech.querySelector('[data-action="remove-technology"]').fire('click');
        assert.equal(p.submit().defaultPrevented, true);
        const mixed = page(configuration(item, sla));
        assert.deepEqual(mixed.data().departments[0].technologies.map(tech => tech.source), ['items', 'sla']);
        assert.equal(mixed.submit().defaultPrevented, false);
    }],
    ['adding a technology keeps the item default and the check limit', () => {
        const p = page(configuration(sla));
        p.root.querySelector('[data-action="add-technology"]').fire('click');
        const tech = p.techs()[1]; assert.equal(field(tech, 'source').value, 'items');
        assert.equal(tech.querySelector('[data-source-panel="sla"]').hidden, true);
        const button = tech.querySelector('[data-action="add-check"]');
        for (let i = 0; i < 8; i++) button.fire('click');
        assert.equal(tech.querySelectorAll('.gav-check').length, 6);
        assert.match(p.nodes['gav-config-status'].textContent, /Máximo de 6/);
    }],
    ['automatic, exact-hour and manual evidence windows roundtrip explicitly', () => {
        const automaticItem = {...item, checks: [{...check, max_age: null}]};
        const p = page(configuration(automaticItem)), tech = p.techs()[0];
        assert.equal(field(tech, 'age_mode').value, 'auto');
        assert.equal(p.data().departments[0].technologies[0].checks[0].max_age, null);
        p.change(field(tech, 'age_mode'), 'hour');
        assert.equal(field(tech, 'max_age').disabled, true);
        assert.equal(p.data().departments[0].technologies[0].checks[0].max_age, 3600);
        assert.match(tech.querySelector('.gav-validity-hint').textContent, /novo 0 ou 1 substitui/);
        const reopened = page(p.data()), reopenedTech = reopened.techs()[0];
        assert.equal(field(reopenedTech, 'age_mode').value, 'hour');
        assert.equal(reopened.data().departments[0].technologies[0].checks[0].max_age, 3600);
        reopened.change(field(reopenedTech, 'age_mode'), 'manual');
        reopened.change(field(reopenedTech, 'max_age'), '4000');
        assert.equal(reopened.data().departments[0].technologies[0].checks[0].max_age, 4000);
        reopened.change(field(reopenedTech, 'age_mode'), 'auto');
        assert.equal(reopened.data().departments[0].technologies[0].checks[0].max_age, null);
    }],
    ['English SLA help and source labels are available', () => {
        const p = page(configuration(sla), 'en'), tech = p.techs()[0];
        assert.match(tech.textContent, /Calculation source/);
        assert.match(tech.textContent, /closed months only/);
        assert.match(tech.textContent, /does not provide a daily timeline/);
        assert.match(tech.textContent, /Align the report time zone with the SLA time zone/);
        assert.match(tech.querySelector('.gav-summary-meta').textContent, /Native monthly SLA/);
        assert.equal(p.submit().defaultPrevented, false);
    }]
];

for (const [name, test] of tests) {
    try { test(); }
    catch (error) { error.message = name + ': ' + error.message; throw error; }
}
console.log('PASS: ' + tests.length + ' availability configuration UI scenarios (local DOM only).');
