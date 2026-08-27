(() => {
    const init = () => {
        const root = document.getElementById('gav-config');
        if (!root || root.dataset.initialized) return;
        root.dataset.initialized = '1';
        const pt = root.dataset.lang === 'pt';
        const t = (a, b) => pt ? a : b;
        const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]));
        const list = document.getElementById('gav-departments');
        const form = document.getElementById('gav-config-form');
        const status = document.getElementById('gav-config-status');
        const input = (field, value, extra = '') => `<input data-field="${field}" value="${esc(value)}" ${extra}>`;
        const field = (label, control) => `<label class="gav-field"><span>${label}</span>${control}</label>`;
        const operators = {eq: '=', ne: '≠', gt: '>', ge: '≥', lt: '<', le: '≤', range: t('Entre (inclusive)', 'Between (inclusive)')};
        const options = (values, selected) => Object.entries(values).map(([value, label]) => `<option value="${value}"${value === selected ? ' selected' : ''}>${label}</option>`).join('');
        const rule = (side, value = {op: 'eq', a: 1}) => `<div class="gav-rule" data-side="${side}">
            ${field(t('Operador', 'Operator'), `<select data-field="op">${options(operators, value.op)}</select>`)}
            ${field(t('Valor', 'Value'), input('a', value.a, 'type="number" step="any" required'))}
            <label class="gav-field gav-bound"><span>${t('Até', 'To')}</span>${input('b', value.b ?? '', 'type="number" step="any"')}</label>
        </div>`;
        const checkHtml = (check = {key: '', up: {op: 'eq', a: 1}, down: null}) => `<div class="gav-check">
            <div class="gav-toolbar">${field(t('Chave exata do item em cada host', 'Exact item key on each host'), input('key', check.key, 'required maxlength="2048" placeholder="icmpping"'))}
                <button type="button" data-action="remove-check" class="btn-alt">${t('Remover verificação', 'Remove check')}</button></div>
            <div class="gav-check-grid"><div><strong>${t('Disponível quando', 'Available when')}</strong>${rule('up', check.up)}</div>
                <div>${field(t('Indisponibilidade', 'Unavailability'), `<select data-field="down_mode">${options({complement: t('Qualquer outro valor válido', 'Any other valid value'), explicit: t('Condição específica', 'Explicit condition')}, check.down ? 'explicit' : 'complement')}</select>`)}
                    <div class="gav-down-rule">${rule('down', check.down ?? {op: 'eq', a: 0})}</div></div></div>
        </div>`;
        const technologyHtml = (tech = {name: '', weight: 1, target: 99.9, mode: 'any_down', max_age: 180, groups: '', checks: []}, open = false) => `<details class="gav-technology" ${open ? 'open' : ''}>
            <summary><strong>${esc(tech.name || t('Nova tecnologia', 'New technology'))}</strong><span class="gav-muted">${t('Configurar', 'Configure')}</span></summary>
            <div class="gav-tech-content"><div class="gav-config-grid">
                ${field(t('Tecnologia / serviço', 'Technology / service'), input('name', tech.name, 'required maxlength="100" placeholder="PostgreSQL"'))}
                ${field(t('Peso no departamento', 'Weight within department'), input('weight', tech.weight, 'type="number" min="0.001" max="100000" step="any" required'))}
                ${field(t('Meta de disponibilidade (%)', 'Availability target (%)'), input('target', tech.target, 'type="number" min="0" max="100" step="any" required'))}
                ${field(t('Validade da última amostra (segundos)', 'Last sample validity (seconds)'), input('max_age', tech.max_age, 'type="number" min="1" max="86400" step="1" required'))}
                ${field(t('Grupos (nomes ou IDs, separados por vírgula)', 'Groups (names or IDs, comma-separated)'), input('groups', tech.groups, 'required maxlength="1000" placeholder="Equipes/Banco de Dados"'))}
                ${field(t('Consolidação dos servidores', 'Host aggregation'), `<select data-field="mode">${options({any_down: t('Indisponível se qualquer servidor cair', 'Unavailable if any host goes down'), mean: t('Média dos servidores (pesos iguais)', 'Mean of hosts (equal weights)')}, tech.mode)}</select>`)}
            </div><p class="gav-muted">${t('Todas as verificações abaixo são obrigatórias em cada host. Uma falha confirmada prevalece sobre uma verificação sem dados. Use validade maior que o intervalo de coleta; amostras antigas expiram.', 'All checks below are mandatory on every host. A confirmed failure takes precedence over a check with no data. Use validity longer than the polling interval; old samples expire.')}</p>
            <div class="gav-checks">${(tech.checks.length ? tech.checks : [{key: '', up: {op: 'eq', a: 1}, down: null}]).map(checkHtml).join('')}</div>
            <div class="gav-toolbar"><button type="button" data-action="add-check" class="btn-alt">${t('Adicionar verificação', 'Add check')}</button>
                <button type="button" data-action="remove-technology" class="btn-alt">${t('Remover tecnologia', 'Remove technology')}</button></div>
            </div></details>`;
        const departmentHtml = (dept = {name: '', target: 99.9, technologies: []}) => `<section class="gav-department-editor">
            <div class="gav-toolbar"><h3>${t('Departamento', 'Department')}</h3><button type="button" data-action="remove-department" class="btn-alt">${t('Remover departamento', 'Remove department')}</button></div>
            <div class="gav-config-grid">${field(t('Nome do departamento', 'Department name'), input('name', dept.name, 'required maxlength="100"'))}
                ${field(t('Meta do departamento (%)', 'Department target (%)'), input('target', dept.target, 'type="number" min="0" max="100" step="any" required'))}</div>
            <div class="gav-technologies">${dept.technologies.map(tech => technologyHtml(tech)).join('')}</div>
            <button type="button" data-action="add-technology" class="btn-alt">${t('Adicionar tecnologia', 'Add technology')}</button>
        </section>`;
        const updateRules = () => {
            root.querySelectorAll('.gav-check').forEach(check => {
                const explicit = check.querySelector('[data-field="down_mode"]').value === 'explicit';
                check.querySelector('.gav-down-rule').hidden = !explicit;
                check.querySelectorAll('.gav-rule').forEach(ruleNode => {
                    const active = ruleNode.dataset.side === 'up' || explicit;
                    const range = ruleNode.querySelector('[data-field="op"]').value === 'range';
                    ruleNode.querySelector('.gav-bound').hidden = !range;
                    ruleNode.querySelectorAll('input, select').forEach(control => {
                        control.disabled = !active || (control.dataset.field === 'b' && !range);
                        if (control.tagName === 'INPUT') control.required = !control.disabled;
                    });
                });
            });
            document.getElementById('gav-config-empty').hidden = !!list.children.length;
        };
        try {
            const data = JSON.parse(document.getElementById('gav-config-data').textContent);
            list.innerHTML = data.departments.map(departmentHtml).join('');
            updateRules();
        } catch (error) {
            status.textContent = t('Não foi possível abrir a configuração. As regras existentes não foram alteradas.', 'Unable to open configuration. Existing rules were not changed.');
            return;
        }
        document.getElementById('gav-add-department').disabled = false;
        document.getElementById('gav-save').disabled = false;
        status.textContent = '';
        document.getElementById('gav-add-department').addEventListener('click', () => {
            if (list.children.length >= 12) { status.textContent = t('Máximo de 12 departamentos.', 'Maximum 12 departments.'); return; }
            list.insertAdjacentHTML('beforeend', departmentHtml());
            list.lastElementChild.querySelector('input').focus();
            updateRules();
        });
        root.addEventListener('change', updateRules);
        root.addEventListener('click', event => {
            const button = event.target.closest('[data-action]');
            if (!button) return;
            const action = button.dataset.action;
            if (action === 'add-technology') {
                if (root.querySelectorAll('.gav-technology').length >= 30) { status.textContent = t('Máximo de 30 tecnologias.', 'Maximum 30 technologies.'); return; }
                button.closest('.gav-department-editor').querySelector('.gav-technologies').insertAdjacentHTML('beforeend', technologyHtml(undefined, true));
            }
            if (action === 'add-check') {
                const checks = button.closest('.gav-technology').querySelector('.gav-checks');
                if (checks.children.length >= 6) { status.textContent = t('Máximo de 6 verificações.', 'Maximum 6 checks.'); return; }
                checks.insertAdjacentHTML('beforeend', checkHtml());
            }
            const removals = {'remove-check': '.gav-check', 'remove-technology': '.gav-technology', 'remove-department': '.gav-department-editor'};
            if (removals[action] && window.confirm(t('Remover esta configuração? A alteração será aplicada ao salvar.', 'Remove this configuration? The change will apply when saved.'))) button.closest(removals[action]).remove();
            updateRules();
        });
        root.addEventListener('input', event => {
            if (event.target.dataset.field === 'name') {
                const tech = event.target.closest('.gav-technology');
                if (tech) tech.querySelector('summary strong').textContent = event.target.value || t('Nova tecnologia', 'New technology');
            }
        });
        // Open collapsed ancestors when native validation finds an invalid input.
        form.addEventListener('invalid', event => {
            const details = event.target.closest('details');
            if (details) details.open = true;
        }, true);
        form.addEventListener('submit', event => {
            const get = (node, name) => node.querySelector(`[data-field="${name}"]`).value;
            const readRule = node => ({op: get(node, 'op'), a: Number(get(node, 'a')), ...(get(node, 'op') === 'range' ? {b: Number(get(node, 'b'))} : {})});
            const data = {timezone: document.getElementById('gav-timezone').value.trim(), departments: [...list.children].map(dept => ({
                name: get(dept, 'name'), target: Number(get(dept, 'target')),
                technologies: [...dept.querySelectorAll('.gav-technology')].map(tech => ({
                    name: get(tech, 'name'), target: Number(get(tech, 'target')), weight: Number(get(tech, 'weight')),
                    max_age: Number(get(tech, 'max_age')), groups: get(tech, 'groups'), mode: get(tech, 'mode'),
                    checks: [...tech.querySelectorAll('.gav-check')].map(check => ({key: get(check, 'key'),
                        up: readRule(check.querySelector('[data-side="up"]')),
                        down: get(check, 'down_mode') === 'explicit' ? readRule(check.querySelector('[data-side="down"]')) : null}))
                }))
            }))};
            if (data.departments.some(d => !d.technologies.length || d.technologies.some(tech => !tech.checks.length))) {
                event.preventDefault();
                status.textContent = t('Cada departamento precisa de uma tecnologia e cada tecnologia de uma verificação.', 'Each department needs a technology and each technology needs a check.');
                return;
            }
            document.getElementById('gav-payload').value = JSON.stringify(data);
        });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();
})();
