(() => {
    const init = () => {
        const root = document.getElementById('gav-config');
        if (!root || root.dataset.initialized) return;
        const page = root.closest('main');
        const layout = root.closest('.wrapper');
        if (page) page.classList.add('gav-page');
        if (layout) layout.classList.add('gav-layout');
        root.dataset.initialized = '1';
        const pt = root.dataset.lang === 'pt';
        const t = (a, b) => pt ? a : b;
        const number = value => Number(value).toLocaleString(pt ? 'pt-BR' : 'en-GB', {maximumFractionDigits: 6});
        const techCount = count => `${count} ${count === 1 ? t('tecnologia', 'technology') : t('tecnologias', 'technologies')}`;
        const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]));
        const list = document.getElementById('gav-departments');
        const form = document.getElementById('gav-config-form');
        const status = document.getElementById('gav-config-status');
        const payload = document.getElementById('gav-payload');
        const input = (name, value, extra = '', type = 'text') => `<input type="${type}" data-field="${name}" value="${esc(value)}" ${extra}>`;
        const field = (label, control, classes = '', hint = '') => `<label class="gav-field ${classes}"><span>${label}</span>${control}${hint ? `<small>${hint}</small>` : ''}</label>`;
        const options = (values, selected) => Object.entries(values).map(([value, label]) => `<option value="${value}"${value === selected ? ' selected' : ''}>${esc(label)}</option>`).join('');
        const operators = {eq: '=', ne: '≠', gt: '>', ge: '≥', lt: '<', le: '≤', range: t('Entre (inclusive)', 'Between (inclusive)')};
        const rule = (side, value = {op: 'eq', a: 1}) => `<div class="gav-rule" data-side="${side}">
            ${field(t('Operador', 'Operator'), `<select data-field="op">${options(operators, value.op)}</select>`)}
            ${field(t('Valor', 'Value'), input('a', value.a, 'step="any" required', 'number'))}
            ${field(t('Até', 'To'), input('b', value.b ?? '', 'step="any"', 'number'), 'gav-bound')}
        </div>`;
        const checkHtml = (check = {key: '', up: {op: 'eq', a: 1}, down: null, max_age: null}, legacyAge = null) => {
            const age = Object.prototype.hasOwnProperty.call(check, 'max_age') ? check.max_age : legacyAge;
            return `<section class="gav-check">
                <div class="gav-toolbar gav-check-heading"><h4>${t('Verificação por item', 'Item check')}</h4><button type="button" data-action="remove-check" class="btn-alt gav-remove">${t('Remover', 'Remove')}</button></div>
                <div class="gav-check-source">
                    ${field(t('Chave exata do item', 'Exact item key'), input('key', check.key, 'required maxlength="2048" placeholder="icmpping" spellcheck="false"'), '', t('Copie do host, mantendo as macros. Uma chave por verificação.', 'Copy from the host, keeping macros. One key per check.'))}
                    ${field(t('Validade da amostra', 'Sample validity'), `<select data-field="age_mode">${options({auto: t('Automática por item', 'Automatic per item'), manual: t('Manual (segundos)', 'Manual (seconds)')}, age === null ? 'auto' : 'manual')}</select>`)}
                    ${field(t('Segundos', 'Seconds'), input('max_age', age ?? 180, 'min="1" max="86400" step="1"', 'number'), 'gav-manual-age')}
                </div>
                <p class="gav-muted gav-validity-hint"></p>
                <div class="gav-check-grid"><div><strong>${t('Disponível quando', 'Available when')}</strong>${rule('up', check.up)}</div>
                    <div>${field(t('Indisponível quando', 'Unavailable when'), `<select data-field="down_mode">${options({complement: t('Qualquer outro valor válido', 'Any other valid value'), explicit: t('Condição específica', 'Explicit condition')}, check.down ? 'explicit' : 'complement')}</select>`)}
                        <div class="gav-down-rule">${rule('down', check.down ?? {op: 'eq', a: 0})}</div></div></div>
            </section>`;
        };
        const technologyHtml = (tech = {name: '', weight: 1, target: 99.9, mode: 'any_down', groups: '', checks: []}, open = false) => `<details class="gav-technology" ${open ? 'open' : ''}>
            <summary><strong>${esc(tech.name || t('Nova tecnologia', 'New technology'))}</strong><span class="gav-summary-meta"></span></summary>
            <div class="gav-tech-content"><div class="gav-config-grid">
                ${field(t('Tecnologia / serviço', 'Technology / service'), input('name', tech.name, 'required maxlength="100" placeholder="PostgreSQL"'), 'gav-span-6')}
                ${field(t('Peso', 'Weight'), input('weight', tech.weight, 'min="0.001" max="100000" step="any" required', 'number'), 'gav-span-3')}
                ${field(t('Meta (%)', 'Target (%)'), input('target', tech.target, 'min="0" max="100" step="any" required', 'number'), 'gav-span-3')}
                ${field(t('Grupos de hosts', 'Host groups'), input('groups', tech.groups, 'required maxlength="1000" placeholder="Equipes/Banco de Dados"'), 'gav-span-6', t('Nomes ou IDs separados por vírgula. Nomes incluem subgrupos.', 'Comma-separated names or IDs. Names include subgroups.'))}
                ${field(t('Consolidação dos hosts', 'Host aggregation'), `<select data-field="mode">${options({any_down: t('Indisponível se qualquer host cair', 'Unavailable if any host goes down'), mean: t('Média dos hosts (pesos iguais)', 'Mean of hosts (equal weights)')}, tech.mode)}</select>`, 'gav-span-6')}
            </div>
            <div class="gav-checks-title"><h4>${t('Itens que determinam o estado de cada host', 'Items that determine each host state')}</h4><p class="gav-muted">${t('Todos são obrigatórios. Uma falha confirmada prevalece sobre outro item sem dados; quedas sobrepostas contam uma vez.', 'All are required. A confirmed failure takes precedence over another item with no data; overlapping outages count once.')}</p></div>
            <div class="gav-checks">${(tech.checks.length ? tech.checks : [{key: '', up: {op: 'eq', a: 1}, down: null, max_age: null}]).map(check => checkHtml(check, tech.max_age ?? null)).join('')}</div>
            <div class="gav-toolbar gav-node-actions"><button type="button" data-action="add-check" class="btn-alt">${t('Adicionar verificação', 'Add check')}</button><button type="button" data-action="remove-technology" class="btn-alt gav-remove">${t('Remover tecnologia', 'Remove technology')}</button></div>
            </div></details>`;
        const departmentHtml = (dept = {name: '', target: 99.9, technologies: []}, open = true) => `<details class="gav-department-editor" ${open ? 'open' : ''}>
            <summary><strong>${esc(dept.name || t('Novo departamento', 'New department'))}</strong><span class="gav-summary-meta"></span></summary>
            <div class="gav-department-content"><div class="gav-config-grid">
                ${field(t('Nome do departamento', 'Department name'), input('name', dept.name, 'required maxlength="100" placeholder="Banco de Dados"'), 'gav-span-9')}
                ${field(t('Meta do departamento (%)', 'Department target (%)'), input('target', dept.target, 'min="0" max="100" step="any" required', 'number'), 'gav-span-3')}
            </div><div class="gav-technologies">${dept.technologies.map(tech => technologyHtml(tech)).join('')}</div>
            <div class="gav-toolbar gav-node-actions"><button type="button" data-action="add-technology" class="btn-alt">${t('Adicionar tecnologia', 'Add technology')}</button><button type="button" data-action="remove-department" class="btn-alt gav-remove">${t('Remover departamento', 'Remove department')}</button></div>
        </div></details>`;
        const get = (node, name) => node.querySelector(`[data-field="${name}"]`).value;
        const updateRules = () => {
            root.querySelectorAll('.gav-check').forEach(check => {
                const manual = get(check, 'age_mode') === 'manual';
                const age = check.querySelector('[data-field="max_age"]');
                check.querySelector('.gav-manual-age').hidden = !manual;
                age.disabled = !manual;
                age.required = manual;
                check.querySelector('.gav-validity-hint').textContent = manual
                    ? t('Validade fixa somente deste item. Considere o intervalo gravado e o heartbeat, quando existir.', 'Fixed validity for this item only. Consider the stored interval and heartbeat, when present.')
                    : t('Usa o intervalo e o heartbeat deste item, com margem. Itens cuja cadência não puder ser interpretada exigem validade manual.', 'Uses this item’s interval and heartbeat, with a margin. Items with an uninterpretable cadence require manual validity.');
                const explicit = get(check, 'down_mode') === 'explicit';
                check.querySelector('.gav-down-rule').hidden = !explicit;
                check.querySelectorAll('.gav-rule').forEach(ruleNode => {
                    const active = ruleNode.dataset.side === 'up' || explicit;
                    const range = get(ruleNode, 'op') === 'range';
                    ruleNode.querySelector('.gav-bound').hidden = !range;
                    ruleNode.querySelectorAll('input, select').forEach(control => {
                        control.disabled = !active || (control.dataset.field === 'b' && !range);
                        if (control.tagName === 'INPUT') control.required = !control.disabled;
                    });
                    const upper = ruleNode.querySelector('[data-field="b"]');
                    upper.setCustomValidity(active && range && Number(upper.value) < Number(get(ruleNode, 'a')) ? t('O limite final deve ser maior ou igual ao inicial.', 'The upper bound must be greater than or equal to the lower bound.') : '');
                });
            });
            root.querySelectorAll('.gav-technology').forEach(tech => {
                tech.querySelector('summary strong').textContent = get(tech, 'name') || t('Nova tecnologia', 'New technology');
                const checks = tech.querySelectorAll('.gav-check').length;
                tech.querySelector('.gav-summary-meta').textContent = `${t('Peso', 'Weight')} ${number(get(tech, 'weight'))} · ${checks} ${checks === 1 ? t('item', 'item') : t('itens', 'items')}`;
            });
            [...list.children].forEach(dept => {
                dept.querySelector('summary strong').textContent = get(dept, 'name') || t('Novo departamento', 'New department');
                dept.querySelector('.gav-summary-meta').textContent = `${techCount(dept.querySelectorAll('.gav-technology').length)} · ${t('Meta', 'Target')} ${number(get(dept, 'target'))}%`;
            });
            document.getElementById('gav-config-empty').hidden = !!list.children.length;
            document.getElementById('gav-config-count').textContent = `${list.children.length} ${list.children.length === 1 ? t('departamento', 'department') : t('departamentos', 'departments')} · ${techCount(root.querySelectorAll('.gav-technology').length)}`;
        };
        const serialize = () => {
            const readRule = node => ({op: get(node, 'op'), a: Number(get(node, 'a')), ...(get(node, 'op') === 'range' ? {b: Number(get(node, 'b'))} : {})});
            const data = {timezone: document.getElementById('gav-timezone').value.trim(), departments: [...list.children].map(dept => ({
                name: get(dept, 'name').trim(), target: Number(get(dept, 'target')),
                technologies: [...dept.querySelectorAll('.gav-technology')].map(tech => ({
                    name: get(tech, 'name').trim(), target: Number(get(tech, 'target')), weight: Number(get(tech, 'weight')),
                    groups: get(tech, 'groups').trim(), mode: get(tech, 'mode'),
                    checks: [...tech.querySelectorAll('.gav-check')].map(check => ({key: get(check, 'key').trim(),
                        max_age: get(check, 'age_mode') === 'manual' ? Number(get(check, 'max_age')) : null,
                        up: readRule(check.querySelector('[data-side="up"]')),
                        down: get(check, 'down_mode') === 'explicit' ? readRule(check.querySelector('[data-side="down"]')) : null}))
                }))
            }))};
            payload.value = JSON.stringify(data);
            return data;
        };
        const changed = () => { updateRules(); serialize(); status.textContent = t('Alterações não salvas.', 'Unsaved changes.'); };
        try {
            const data = JSON.parse(document.getElementById('gav-config-data').textContent);
            if (!Array.isArray(data.departments)) throw new Error('Invalid configuration');
            list.innerHTML = data.departments.map((dept, i) => departmentHtml(dept, i === 0)).join('');
            document.getElementById('gav-legacy-notice').hidden = !data.departments.some(dept => dept.technologies.some(tech => tech.max_age != null && tech.checks.some(check => !Object.prototype.hasOwnProperty.call(check, 'max_age'))));
            updateRules();
            serialize();
        } catch (error) {
            status.textContent = t('Não foi possível abrir a configuração. As regras existentes não foram alteradas.', 'Unable to open configuration. Existing rules were not changed.');
            return;
        }
        document.getElementById('gav-add-department').addEventListener('click', () => {
            if (list.children.length >= 12) { status.textContent = t('Máximo de 12 departamentos.', 'Maximum 12 departments.'); return; }
            list.insertAdjacentHTML('beforeend', departmentHtml());
            list.lastElementChild.querySelector('input').focus();
            changed();
        });
        root.addEventListener('change', changed);
        root.addEventListener('input', changed);
        root.addEventListener('click', event => {
            const button = event.target.closest('[data-action]');
            if (!button) return;
            const action = button.dataset.action;
            if (action === 'add-technology') {
                if (root.querySelectorAll('.gav-technology').length >= 30) { status.textContent = t('Máximo de 30 tecnologias.', 'Maximum 30 technologies.'); return; }
                const technologies = button.closest('.gav-department-editor').querySelector('.gav-technologies');
                technologies.insertAdjacentHTML('beforeend', technologyHtml(undefined, true));
                technologies.lastElementChild.querySelector('input').focus();
            }
            if (action === 'add-check') {
                const checks = button.closest('.gav-technology').querySelector('.gav-checks');
                if (checks.children.length >= 6) { status.textContent = t('Máximo de 6 verificações.', 'Maximum 6 checks.'); return; }
                checks.insertAdjacentHTML('beforeend', checkHtml());
                checks.lastElementChild.querySelector('input').focus();
            }
            const removals = {'remove-check': '.gav-check', 'remove-technology': '.gav-technology', 'remove-department': '.gav-department-editor'};
            if (removals[action]) {
                if (!window.confirm(t('Remover esta configuração? A alteração será aplicada ao salvar.', 'Remove this configuration? The change will apply when saved.'))) return;
                button.closest(removals[action]).remove();
            }
            changed();
        });
        form.addEventListener('invalid', event => {
            for (let node = event.target.parentElement; node && node !== form; node = node.parentElement) {
                if (node.tagName === 'DETAILS') node.open = true;
            }
            status.textContent = t('Revise os campos destacados antes de salvar.', 'Review the highlighted fields before saving.');
        }, true);
        form.addEventListener('submit', event => {
            const data = serialize();
            if (data.departments.some(d => !d.technologies.length || d.technologies.some(tech => !tech.checks.length))) {
                event.preventDefault();
                status.textContent = t('Cada departamento precisa de uma tecnologia e cada tecnologia de uma verificação.', 'Each department needs a technology and each technology needs a check.');
                return;
            }
            if (payload.value.length > 300000) {
                event.preventDefault();
                status.textContent = t('Configuração muito grande. Reduza a quantidade de regras.', 'Configuration too large. Reduce the number of rules.');
            }
        }, true);
        document.getElementById('gav-add-department').disabled = false;
        document.getElementById('gav-save').disabled = false;
        status.textContent = t('Nenhuma alteração pendente.', 'No pending changes.');
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();
})();
