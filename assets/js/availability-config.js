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
        const dataPolicy = document.getElementById('gav-data-policy');
        const validDataPolicy = value => ['strict', 'observed'].includes(value);
        const input = (name, value, extra = '', type = 'text') => `<input type="${type}" data-field="${name}" value="${esc(value)}" ${extra}>`;
        const field = (label, control, classes = '', hint = '') => `<label class="gav-field ${classes}"><span>${label}</span>${control}${hint ? `<small>${hint}</small>` : ''}</label>`;
        const options = (values, selected) => Object.entries(values).map(([value, label]) => `<option value="${value}"${value === selected ? ' selected' : ''}>${esc(label)}</option>`).join('');
        const validNativeId = value => typeof value === 'string' && value.length > 0 && value.length <= 19
            && value[0] !== '0' && !/[^0-9]/.test(value)
            && (value.length < 19 || value <= '9223372036854775807');
        // Input maxlength exceeds the valid ID length so a pasted oversized ID is rejected, not shortened into another ID.
        const nativeIdControl = (name, value) => input(name, typeof value === 'string' ? value : '',
            'required inputmode="numeric" pattern="[1-9][0-9]{0,18}" maxlength="64" autocomplete="off" spellcheck="false"');
        const parseSlaUrl = raw => {
            if (typeof raw !== 'string' || !raw.trim() || raw.length > 4096 || /[\u0000-\u001f\u007f]/.test(raw)) return null;
            try {
                const current = new URL(window.location.href);
                const url = new URL(raw.trim(), current);
                if (!['http:', 'https:'].includes(url.protocol) || url.origin !== current.origin
                    || url.pathname !== current.pathname || url.username || url.password
                    || url.searchParams.getAll('action').length !== 1 || url.searchParams.get('action') !== 'slareport.list'
                    || url.searchParams.getAll('filter_slaid').length !== 1
                    || url.searchParams.getAll('filter_serviceid').length !== 1) return null;
                const slaid = url.searchParams.get('filter_slaid'), serviceid = url.searchParams.get('filter_serviceid');
                return validNativeId(slaid) && validNativeId(serviceid) ? {slaid, serviceid} : null;
            }
            catch (error) { return null; }
        };
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
                    ${field(t('Janela da evidência', 'Evidence window'), `<select data-field="age_mode">${options({auto: t('Automática (mínimo de 1 hora)', 'Automatic (one-hour minimum)'), hour: t('Uma hora exata', 'Exactly one hour'), manual: t('Manual (segundos)', 'Manual (seconds)')}, age === null ? 'auto' : (Number(age) === 3600 ? 'hour' : 'manual'))}</select>`, '', t('Por quanto tempo a última amostra representa o estado. Uma nova amostra substitui o estado imediatamente.', 'How long the latest sample represents the state. A new sample replaces the state immediately.'))}
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
                ${field(t('Fonte do cálculo', 'Calculation source'), `<select data-field="source">${options({items: t('Histórico de itens (24×7)', 'Item history (24×7)'), sla: t('SLA nativo mensal', 'Native monthly SLA')}, tech.source ?? 'items')}</select>`, 'gav-span-6')}
                <p class="gav-muted gav-span-6">${t('O peso continua sendo aplicado neste módulo. A fonte escolhida não é substituída pela outra se faltar histórico ou SLA.', 'The weight still applies in this module. The selected source is not replaced by the other when history or SLA data is missing.')}</p>
            </div>
            <div class="gav-items-source" data-source-panel="items"><div class="gav-config-grid">
                ${field(t('Grupos de hosts', 'Host groups'), input('groups', tech.groups, 'required maxlength="1000" placeholder="Equipes/Banco de Dados"'), 'gav-span-6', t('Nomes ou IDs separados por vírgula. Nomes incluem subgrupos.', 'Comma-separated names or IDs. Names include subgroups.'))}
                ${field(t('Consolidação dos hosts', 'Host aggregation'), `<select data-field="mode">${options({any_down: t('Indisponível se qualquer host cair', 'Unavailable if any host goes down'), mean: t('Média dos hosts (pesos iguais)', 'Mean of hosts (equal weights)')}, tech.mode ?? 'any_down')}</select>`, 'gav-span-6')}
            </div>
            <div class="gav-checks-title"><h4>${t('Itens que determinam o estado de cada host', 'Items that determine each host state')}</h4><p class="gav-muted">${t('Todos são obrigatórios. Uma falha confirmada prevalece sobre outro item sem dados; quedas sobrepostas contam uma vez.', 'All are required. A confirmed failure takes precedence over another item with no data; overlapping outages count once.')}</p></div>
            <div class="gav-checks">${(Array.isArray(tech.checks) && tech.checks.length ? tech.checks : [{key: '', up: {op: 'eq', a: 1}, down: null, max_age: null}]).map(check => checkHtml(check, tech.max_age ?? null)).join('')}</div>
            <div class="gav-toolbar gav-node-actions"><button type="button" data-action="add-check" class="btn-alt">${t('Adicionar verificação', 'Add check')}</button></div></div>
            <div class="gav-sla-source" data-source-panel="sla" hidden>
                <p class="gav-notice">${t('Nesta versão, use um SLA de período mensal e apenas meses encerrados. O resultado segue o calendário, o fuso horário e as exclusões do SLA nativo; o resumo mensal não fornece linha do tempo diária.', 'In this version, use a monthly SLA and closed months only. Results follow the native SLA schedule, time zone and exclusions; the monthly summary does not provide a daily timeline.')}</p>
                <div class="gav-config-grid">
                    ${field('SLA ID', nativeIdControl('slaid', tech.slaid), 'gav-span-6', t('Copie filter_slaid do endereço do relatório nativo.', 'Copy filter_slaid from the native report address.'))}
                    ${field(t('Serviço ID', 'Service ID'), nativeIdControl('serviceid', tech.serviceid), 'gav-span-6', t('Copie filter_serviceid do mesmo relatório, com um serviço selecionado.', 'Copy filter_serviceid from the same report, with one service selected.'))}
                </div>
                ${field(t('Colar endereço do relatório nativo (opcional)', 'Paste native report address (optional)'), input('sla_url', '', 'maxlength="8192" autocomplete="off" spellcheck="false" placeholder="zabbix.php?action=slareport.list&amp;filter_slaid=…&amp;filter_serviceid=…"'), '', t('Use um relatório deste Zabbix. O endereço não é acessado nem salvo; somente os dois IDs são copiados.', 'Use a report from this Zabbix. The address is not visited or saved; only the two IDs are copied.'))}
                <div class="gav-toolbar gav-node-actions"><button type="button" data-action="import-sla-url" class="btn-alt">${t('Preencher IDs do endereço', 'Fill IDs from address')}</button><p class="gav-muted gav-sla-import-status" role="status"></p></div>
                <p class="gav-muted">${t('Alinhe o fuso do relatório com o fuso do SLA para uma média departamental comparável. Esta fonte não consulta itens nem grupos de hosts.', 'Align the report time zone with the SLA time zone for a comparable departmental mean. This source does not query items or host groups.')}</p>
            </div>
            <div class="gav-toolbar gav-node-actions"><button type="button" data-action="remove-technology" class="btn-alt gav-remove">${t('Remover tecnologia', 'Remove technology')}</button></div>
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
            dataPolicy.setCustomValidity(validDataPolicy(dataPolicy.value) ? ''
                : t('Selecione uma política de dados válida.', 'Select a valid data policy.'));
            root.querySelectorAll('.gav-technology').forEach(tech => {
                const source = get(tech, 'source');
                tech.querySelectorAll('[data-source-panel]').forEach(panel => {
                    const active = panel.dataset.sourcePanel === source;
                    panel.hidden = !active;
                    panel.querySelectorAll('input, select, button').forEach(control => {
                        control.disabled = !active;
                        if (control.tagName === 'INPUT') {
                            control.required = active && ['groups', 'key', 'slaid', 'serviceid'].includes(control.dataset.field);
                            control.setCustomValidity('');
                        }
                    });
                });
                ['slaid', 'serviceid'].forEach(name => {
                    const control = tech.querySelector(`[data-field="${name}"]`);
                    control.setCustomValidity(source === 'sla' && control.value !== '' && !validNativeId(control.value)
                        ? t('Informe um ID inteiro positivo, sem zeros iniciais, até 9223372036854775807.', 'Enter a positive integer ID without leading zeros, up to 9223372036854775807.') : '');
                });
            });
            root.querySelectorAll('.gav-check').forEach(check => {
                const itemsActive = get(check.closest('.gav-technology'), 'source') === 'items';
                const ageMode = get(check, 'age_mode');
                const manual = ageMode === 'manual';
                const age = check.querySelector('[data-field="max_age"]');
                check.querySelector('.gav-manual-age').hidden = !manual;
                age.disabled = !itemsActive || !manual;
                age.required = itemsActive && manual;
                check.querySelector('.gav-validity-hint').textContent = manual
                    ? t('Janela fixa somente deste item. Após expirar sem nova amostra, o estado passa a desconhecido.', 'Fixed window for this item. After it expires without a new sample, the state becomes unknown.')
                    : ageMode === 'hour'
                        ? t('Cada valor real representa o estado por até uma hora. Um novo 0 ou 1 substitui o anterior imediatamente.', 'Each real value represents the state for up to one hour. A new 0 or 1 replaces the previous state immediately.')
                        : t('Usa no mínimo uma hora e amplia a janela quando o intervalo ou heartbeat do item exigir. Cadência não interpretável exige uma janela manual.', 'Uses at least one hour and extends the window when the item interval or heartbeat requires it. An uninterpretable cadence requires a manual window.');
                const explicit = get(check, 'down_mode') === 'explicit';
                check.querySelector('.gav-down-rule').hidden = !explicit;
                check.querySelectorAll('.gav-rule').forEach(ruleNode => {
                    const active = itemsActive && (ruleNode.dataset.side === 'up' || explicit);
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
                const detail = get(tech, 'source') === 'sla' ? t('SLA nativo mensal', 'Native monthly SLA')
                    : `${checks} ${checks === 1 ? t('item', 'item') : t('itens', 'items')}`;
                tech.querySelector('.gav-summary-meta').textContent = `${t('Peso', 'Weight')} ${number(get(tech, 'weight'))} · ${detail}`;
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
            const data = {timezone: document.getElementById('gav-timezone').value.trim(), data_policy: dataPolicy.value,
                departments: [...list.children].map(dept => ({
                name: get(dept, 'name').trim(), target: Number(get(dept, 'target')),
                technologies: [...dept.querySelectorAll('.gav-technology')].map(tech => {
                    const value = {name: get(tech, 'name').trim(), target: Number(get(tech, 'target')),
                        weight: Number(get(tech, 'weight')), source: get(tech, 'source')};
                    if (value.source === 'sla') return {...value, slaid: get(tech, 'slaid'), serviceid: get(tech, 'serviceid')};
                    return {...value, groups: get(tech, 'groups').trim(), mode: get(tech, 'mode'),
                        checks: [...tech.querySelectorAll('.gav-check')].map(check => ({key: get(check, 'key').trim(),
                            max_age: get(check, 'age_mode') === 'auto' ? null
                                : (get(check, 'age_mode') === 'hour' ? 3600 : Number(get(check, 'max_age'))),
                            up: readRule(check.querySelector('[data-side="up"]')),
                            down: get(check, 'down_mode') === 'explicit' ? readRule(check.querySelector('[data-side="down"]')) : null}))};
                })
            }))};
            payload.value = JSON.stringify(data);
            return data;
        };
        const changed = () => { updateRules(); serialize(); status.textContent = t('Alterações não salvas.', 'Unsaved changes.'); };
        try {
            const data = JSON.parse(document.getElementById('gav-config-data').textContent);
            if (!Array.isArray(data.departments)) throw new Error('Invalid configuration');
            const policy = Object.prototype.hasOwnProperty.call(data, 'data_policy') ? data.data_policy : 'strict';
            dataPolicy.value = validDataPolicy(policy) ? policy : '';
            list.innerHTML = data.departments.map((dept, i) => departmentHtml(dept, i === 0)).join('');
            document.getElementById('gav-legacy-notice').hidden = !data.departments.some(dept => dept.technologies.some(tech => tech.source !== 'sla' && tech.max_age != null
                && Array.isArray(tech.checks) && tech.checks.some(check => !Object.prototype.hasOwnProperty.call(check, 'max_age'))));
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
            if (!button || button.disabled) return;
            const action = button.dataset.action;
            if (action === 'import-sla-url') {
                const tech = button.closest('.gav-technology');
                const ids = parseSlaUrl(get(tech, 'sla_url'));
                const message = tech.querySelector('.gav-sla-import-status');
                if (!ids) {
                    message.textContent = t('Endereço inválido. Use o relatório nativo deste Zabbix com filter_slaid e filter_serviceid únicos e positivos.', 'Invalid address. Use this Zabbix native report with one positive filter_slaid and one positive filter_serviceid.');
                    return;
                }
                tech.querySelector('[data-field="slaid"]').value = ids.slaid;
                tech.querySelector('[data-field="serviceid"]').value = ids.serviceid;
                changed();
                message.textContent = t('IDs copiados. O endereço não foi acessado nem será salvo.', 'IDs copied. The address was not visited and will not be saved.');
                return;
            }
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
            if (!validDataPolicy(data.data_policy)) {
                event.preventDefault();
                status.textContent = t('Selecione uma política de dados válida antes de salvar.', 'Select a valid data policy before saving.');
                return;
            }
            if (data.departments.some(d => !d.technologies.length || d.technologies.some(tech => tech.source === 'items' && !tech.checks.length))) {
                event.preventDefault();
                status.textContent = t('Cada departamento precisa de uma tecnologia. A fonte por itens exige ao menos uma verificação.', 'Each department needs a technology. The item source requires at least one check.');
                return;
            }
            if (data.departments.some(d => d.technologies.some(tech => tech.source === 'sla' && (!validNativeId(tech.slaid) || !validNativeId(tech.serviceid))))) {
                event.preventDefault();
                status.textContent = t('Cada fonte SLA exige IDs válidos de SLA e serviço, sem zeros iniciais.', 'Each SLA source requires valid SLA and service IDs without leading zeros.');
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
