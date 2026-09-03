(() => {
    'use strict';

    const initialize = () => {
        const root = document.getElementById('gov-config');
        const form = document.getElementById('gov-config-form');
        if (!root || !form || root.dataset.configInitialized) return;
        root.dataset.configInitialized = 'true';

        const pt = root.dataset.lang === 'pt';
        const t = (portuguese, english) => pt ? portuguese : english;
        const types = {
            tag: t('Tag personalizada', 'Custom tag'),
            hostgroups: t('Grupo de hosts', 'Host group'),
            inventory: t('Inventário preenchido', 'Populated inventory'),
            templates: t('Template vinculado', 'Linked template'),
            interface: t('Interface configurada', 'Configured interface')
        };
        const tabs = root.querySelector('#gov-config-pages');
        const panels = root.querySelector('#gov-config-panels');
        const payload = root.querySelector('#gov-quality-payload');
        const selectedField = root.querySelector('#gov-quality-page');
        const addPageButton = root.querySelector('#gov-add-page');
        const addCardButton = root.querySelector('#gov-add-card');
        const saveButton = root.querySelector('#gov-save');
        const status = root.querySelector('#gov-config-status');
        const error = root.querySelector('#gov-config-error');
        const conflict = root.dataset.conflict === '1';
        const maxPages = 12;
        const maxCards = 30;
        const defaultPageName = t('Qualidade', 'Quality');
        let selected = selectedField.value;
        let dirty = false;
        let serial = 0;
        let previewEpoch = 0;
        let previewActive = false;
        const invalidatePreview = () => {
            previewEpoch++;
            root.querySelectorAll('.gqp-preview-output').forEach(node => { node.textContent = ''; });
        };

        const element = (tag, className, text) => {
            const node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        };
        const button = (text, className) => {
            const node = element('button', className || 'btn-alt', text);
            node.type = 'button';
            return node;
        };
        const field = (caption, control, hint, className) => {
            const label = element('label', 'gqp-field' + (className ? ' ' + className : ''));
            label.append(element('span', 'gqp-field-label', caption), control);
            if (hint) label.append(element('small', 'gqp-muted', hint));
            return label;
        };
        const input = (key, value, maximum, required) => {
            const node = element('input');
            node.type = 'text';
            node.dataset.field = key;
            node.value = value;
            if (maximum) node.maxLength = maximum;
            node.required = !!required;
            return node;
        };
        const getField = (container, key) => container.querySelector('[data-field="' + key + '"]');
        let closeCatalog = () => {};
        const catalogControl = (control, typeOf) => {
            const wrapper = element('div', 'gqp-catalog-control');
            const pick = button(t('Selecionar…', 'Select…'), 'btn-link gqp-catalog-open');
            wrapper.append(control, pick);
            pick.addEventListener('click', event => {
                event.preventDefault();
                const kind = typeOf();
                if (control.disabled || !['group', 'template'].includes(kind)) return;
                closeCatalog();
                const initialValue = control.value;
                const dialog = element('dialog', 'gqp-catalog-dialog');
                const title = element('h3', '', kind === 'group' ? t('Selecionar grupo de hosts', 'Select host group') : t('Selecionar template', 'Select template'));
                title.id = 'gqp-catalog-title'; dialog.setAttribute('aria-labelledby', title.id);
                const query = element('input'); query.type = 'text'; query.maxLength = 255;
                query.setAttribute('aria-label', t('Parte do nome (mínimo 2 caracteres)', 'Part of name (at least 2 characters)'));
                query.placeholder = t('Parte do nome (mínimo 2 caracteres)', 'Part of name (at least 2 characters)');
                const search = button(t('Buscar', 'Search'), 'btn-alt gqp-catalog-search');
                const cancel = button(t('Fechar', 'Close'), 'btn-alt');
                const results = element('div', 'gqp-catalog-results'); results.setAttribute('aria-live', 'polite');
                const bar = element('div', 'gqp-catalog-bar'); bar.append(query, search);
                dialog.append(title, bar, element('p', 'gqp-muted', t('Selecionar adiciona o nome à lista atual. Grupos respeitam a opção de subgrupos. Nomes com vírgula exigem digitação manual do ID (grupo exato).', 'Selecting adds the name to the current list. Groups respect the subgroup option. Names containing commas require manually entering the ID (exact group).')), results, cancel);
                root.append(dialog);
                let epoch = 0, request = null;
                const close = () => { epoch++; if (request) request.abort(); dialog.close(); dialog.remove(); closeCatalog = () => {}; };
                closeCatalog = close;
                cancel.addEventListener('click', close);
                dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
                dialog.addEventListener('input', event => { event.stopPropagation(); epoch++; if (request) request.abort(); search.disabled = false; results.replaceChildren(); });
                dialog.addEventListener('change', event => event.stopPropagation());
                const run = async () => {
                    const term = query.value.trim();
                    if (term.length < 2) { results.textContent = t('Digite pelo menos 2 caracteres.', 'Enter at least 2 characters.'); return; }
                    const current = ++epoch;
                    if (request) request.abort();
                    const controller = new AbortController(); request = controller;
                    const timer = setTimeout(() => controller.abort(), 25000);
                    search.disabled = true; results.textContent = t('Buscando…', 'Searching…');
                    try {
                        const sid = form.querySelector('[name="sid"]');
                        if (!sid || !sid.value) throw new Error('sid');
                        const body = new URLSearchParams({operation: 'lookup', lookup_type: kind, query: term, sid: sid.value});
                        const response = await fetch('zabbix.php?action=governance.quality.run', {method: 'POST', credentials: 'same-origin', body, signal: controller.signal});
                        if (!response.ok) throw new Error('http');
                        const data = await response.json();
                        if (epoch !== current) return;
                        if (data.status !== 'complete' || !Array.isArray(data.items) || data.items.length > 20 || typeof data.has_more !== 'boolean'
                            || data.items.some(item => !item || typeof item.name !== 'string' || !/^[0-9]+$/.test(item.id))) throw new Error('response');
                        results.replaceChildren();
                        data.items.forEach(item => {
                            const use = button(item.name + ' (ID: ' + item.id + ')', 'btn-link gqp-catalog-result');
                            use.disabled = item.name.includes(',') || !item.name.trim();
                            use.addEventListener('click', () => {
                                if (!control.isConnected || control.disabled || typeOf() !== kind || control.value !== initialValue) { close(); return; }
                                const values = control.value.split(',').map(value => value.trim()).filter(Boolean);
                                if (!values.some(value => value.toLowerCase() === item.name.trim().toLowerCase())) values.push(item.name.trim());
                                const next = values.join(', ');
                                if (next.length > 255) { results.textContent = t('A lista excede 255 caracteres. Reduza a lista ou use IDs manualmente.', 'The list exceeds 255 characters. Shorten it or enter IDs manually.'); return; }
                                close(); control.value = next; control.dispatchEvent(new Event('input', {bubbles: true})); control.focus();
                            });
                            results.append(use);
                        });
                        if (!data.items.length) results.textContent = t('Nenhum resultado.', 'No results.');
                        if (data.has_more) results.append(element('p', 'gqp-muted', t('Exibindo 20 resultados. Refine a busca.', 'Showing 20 results. Refine your search.')));
                    } catch (e) {
                        if (epoch === current) results.textContent = t('Não foi possível buscar. Confira a sessão e tente novamente.', 'Search failed. Check your session and retry.');
                    } finally { clearTimeout(timer); if (epoch === current) search.disabled = false; }
                };
                search.addEventListener('click', run);
                query.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); if (!search.disabled) run(); } });
                dialog.showModal(); query.focus();
            });
            return wrapper;
        };
        window.addEventListener('pagehide', () => closeCatalog());
        const pageNodes = () => [...panels.children];
        const activePanel = () => pageNodes().find((panel) => panel.dataset.pageId === selected);
        const pageName = (panel) => getField(panel, 'page_name').value.trim()
            || (panel.dataset.pageId === 'main' ? defaultPageName : t('Página sem nome', 'Unnamed page'));
        const cards = (panel) => [...panel.querySelector('.gqp-card-list').children];
        const safeId = (prefix) => {
            const used = new Set();
            pageNodes().forEach((panel) => {
                used.add(panel.dataset.pageId);
                cards(panel).forEach((card) => used.add(card.dataset.cardId));
            });
            let id;
            do {
                serial += 1;
                id = prefix + '_' + Date.now().toString(36) + '_' + serial.toString(36);
            } while (used.has(id));
            return id;
        };
        const showError = (message) => {
            error.textContent = message;
            error.hidden = false;
        };
        const selectInput = (key, value, options) => {
            const control = element('select'); control.dataset.field = key;
            Object.entries(options).forEach(([id, text]) => { const option = element('option', '', text); option.value = id; control.append(option); });
            control.value = String(value); return control;
        };
        const readSelection = card => ({version: 1, mode: getField(card, 'selection_mode').value,
            ...(getField(card, 'selection_mode').value === 'custom' ? {formula: getField(card, 'selection_formula').value} : {}),
            conditions: [...card.querySelector('.gqp-conditions').children].map(row => ({
                type: getField(row, 'condition_type').value, operator: getField(row, 'condition_operator').value,
                name: getField(row, 'condition_type').value === 'inventory' ? getField(row, 'condition_inventory').value : getField(row, 'condition_name').value, value: getField(row, 'condition_value').value,
                subgroups: Number(getField(row, 'condition_subgroups').value)
            }))});
        const makeSelection = data => {
            const section = element('section', 'gqp-selection');
            section.append(element('h3', '', t('1. Selecionar hosts', '1. Select hosts')));
            const legacy = [];
            if (data.scope_tag_name) legacy.push({type: 'tag', operator: data.scope_tag_value ? 'equals' : 'exists', name: data.scope_tag_name, value: data.scope_tag_value});
            if (data.scope_group_names) legacy.push({type: 'group', operator: 'equals', value: data.scope_group_names, subgroups: data.scope_include_subgroups});
            const selection = data.selection || {mode: 'all', conditions: legacy};
            section.append(field(t('Tipo de cálculo', 'Calculation type'), selectInput('selection_mode', selection.mode, {
                all: t('Todas as condições (E)', 'All conditions (AND)'), any: t('Qualquer condição (OU)', 'Any condition (OR)'),
                custom: t('Expressão personalizada', 'Custom expression')
            })));
            const formula = input('selection_formula', selection.formula || '', 512);
            formula.placeholder = '(A or B) and C';
            const formulaField = field(t('Expressão', 'Expression'), formula,
                t('Use todos os rótulos e os operadores and, or, not (em inglês). Ex.: (A or B) and C. Adicionar/remover uma linha limpa a expressão para evitar referências trocadas.',
                    'Use every label and the operators and, or, not. Example: (A or B) and C. Adding/removing a row clears the expression to avoid shifted references.'));
            section.append(formulaField);
            const table = element('table', 'list-table gqp-condition-table');
            const head = element('thead'), header = element('tr');
            [t('Rótulo', 'Label'), t('Tipo', 'Type'), t('Operador', 'Operator'), t('Condição', 'Condition'), t('Ações', 'Actions')].forEach(text => header.append(element('th', '', text)));
            head.append(header); const rows = element('tbody', 'gqp-conditions'); table.append(head, rows);
            const add = button(t('Adicionar condição', 'Add condition'), 'btn-link gqp-add-condition');
            const expression = element('p', 'gqp-expression');
            const updateLabels = () => {
                const labels = [...rows.children].map((row, index) => { const label = String.fromCharCode(65 + index); row.querySelector('td').textContent = label; return label; });
                const mode = getField(section, 'selection_mode').value;
                formulaField.hidden = formula.disabled = mode !== 'custom'; formula.required = mode === 'custom';
                expression.hidden = mode === 'custom';
                formula.setCustomValidity('');
                expression.textContent = mode === 'custom' ? formula.value || t('Informe a expressão acima.', 'Enter the expression above.')
                    : labels.length ? labels.join(mode === 'all' ? t(' E ', ' AND ') : t(' OU ', ' OR '))
                    : t('Todos os hosts monitorados', 'All monitored hosts');
                add.disabled = rows.children.length >= 20;
            };
            getField(section, 'selection_mode').addEventListener('change', updateLabels);
            formula.addEventListener('input', updateLabels);
            const makeRow = (condition = {}) => {
                const row = element('tr');
                const type = selectInput('condition_type', condition.type || 'tag', {tag: 'Tag', group: t('Grupo de hosts', 'Host group'), template: 'Template', inventory: t('Inventário', 'Inventory')});
                const operator = selectInput('condition_operator', '', {});
                const name = input('condition_name', condition.name || '', 255);
                const inventory = selectInput('condition_inventory', condition.type === 'inventory' ? condition.name : 'os', {
                    os: 'OS', os_full: 'OS (full)', os_short: 'OS (short)', serialno_a: 'Serial A', serialno_b: 'Serial B',
                    location: t('Localização', 'Location'), type: t('Tipo', 'Type'), software: 'Software', hardware: 'Hardware', name: t('Nome', 'Name'), contact: t('Contato', 'Contact')
                });
                const value = input('condition_value', condition.value || '', 255);
                const subgroups = selectInput('condition_subgroups', condition.subgroups ?? 1, {1: t('Incluir subgrupos (nomes)', 'Include subgroups (names)'), 0: t('Grupo exato', 'Exact group')});
                const remove = button(t('Remover', 'Remove'), 'btn-link gqp-remove-condition');
                const lookup = catalogControl(value, () => type.value);
                const values = element('div', 'gqp-condition-values'); values.append(name, inventory, lookup, subgroups);
                for (const control of [null, type, operator, values, remove]) { const td = element('td'); if (control) td.append(control); row.append(td); }
                const update = (wanted) => {
                    const ops = type.value === 'inventory' ? {exists: t('Preenchido', 'Populated'), not_exists: t('Vazio', 'Empty')}
                        : type.value === 'tag' ? {equals: t('É igual a', 'Equals'), not_equals: t('Não é igual a', 'Does not equal'), exists: t('Existe', 'Exists'), not_exists: t('Não existe', 'Does not exist')}
                            : {equals: t('Possui / pertence', 'Has / belongs to'), not_equals: t('Não possui / não pertence', 'Does not have / belong to')};
                    operator.replaceChildren(); Object.entries(ops).forEach(([id, text]) => { const option = element('option', '', text); option.value = id; operator.append(option); });
                    operator.value = wanted in ops ? wanted : Object.keys(ops)[0];
                    name.hidden = name.disabled = type.value !== 'tag'; name.required = !name.disabled;
                    inventory.hidden = inventory.disabled = type.value !== 'inventory'; inventory.required = !inventory.disabled;
                    inventory.setAttribute('aria-label', t('Campo de inventário', 'Inventory field'));
                    name.placeholder = t('Nome da tag', 'Tag name');
                    name.setAttribute('aria-label', t('Nome da tag ou campo de inventário', 'Tag name or inventory field'));
                    value.hidden = value.disabled = type.value === 'inventory' || ['exists', 'not_exists'].includes(operator.value);
                    value.required = ['group', 'template'].includes(type.value);
                    lookup.hidden = value.hidden;
                    lookup.querySelector('button').hidden = !['group', 'template'].includes(type.value);
                    value.placeholder = type.value === 'tag' ? t('Valor exato (pode ser vazio)', 'Exact value (may be empty)') : t('Nome exato ou ID; vírgula = OU', 'Exact name or ID; comma = OR');
                    value.setAttribute('aria-label', t('Valor da condição', 'Condition value'));
                    subgroups.hidden = subgroups.disabled = type.value !== 'group';
                    subgroups.setAttribute('aria-label', t('Subgrupos', 'Subgroups'));
                    [name, inventory, value, subgroups].forEach(c => c.setCustomValidity(''));
                };
                update(condition.operator); type.addEventListener('change', () => update());
                operator.addEventListener('change', () => update(operator.value));
                remove.addEventListener('click', () => { row.remove(); formula.value = ''; updateLabels(); changed(); });
                return row;
            };
            selection.conditions.forEach(c => rows.append(makeRow(c))); updateLabels();
            add.addEventListener('click', () => { if (rows.children.length < 20) { rows.append(makeRow()); formula.value = ''; updateLabels(); changed(); } });
            const help = element('details', 'gqp-rule-help');
            help.append(element('summary', '', t('Como as condições são aplicadas', 'How conditions are applied')),
                element('p', '', t('Sem condições: todos os hosts monitorados. Templates e tags são os vínculos diretos do host. “Não é igual a” também inclui hosts sem a tag; combine com “Existe” para exigir a presença dela.', 'No conditions: all monitored hosts. Templates and tags refer to direct host links. “Does not equal” includes hosts without the tag; combine with “Exists” to require its presence.')));
            section.append(expression, table, add, help);
            return section;
        };
        const updateConditionalFields = (card) => {
            const type = getField(card, 'type').value;
            card.querySelectorAll('[data-for-type]').forEach((group) => {
                const applicable = group.dataset.forType === type;
                group.hidden = !applicable;
                group.querySelectorAll('input,select').forEach((control) => {
                    control.disabled = !applicable;
                    control.required = applicable && ['tag_names', 'group_names'].includes(control.dataset.field);
                    if (!applicable) control.setCustomValidity('');
                });
            });
            const hints = {
                tag: t('Confere se o host tem uma das tags informadas com um valor aceito.', 'Checks whether the host has one of the specified tags with an accepted value.'),
                hostgroups: t('Confere se o host pertence a um dos grupos informados, incluindo subgrupos selecionados por nome.', 'Checks whether the host belongs to a specified group, including subgroups selected by name.'),
                inventory: t('Confere o campo escolhido; sem seleção, aceita qualquer campo essencial preenchido.', 'Checks the selected field; without a selection, accepts any populated essential field.'),
                templates: t('Avalia templates vinculados diretamente ao host, por nome exato ou ID. Não percorre templates herdados.', 'Checks templates directly linked to the host, by exact name or ID. Does not traverse inherited templates.'),
                interface: t('Confere se há ao menos uma interface com IP ou DNS configurado.', 'Checks whether there is at least one interface with an IP address or DNS name configured.')
            };
            card.querySelector('.gqp-metric-hint').textContent = hints[type] || '';
            card.querySelector('.gqp-card-kind').textContent = types[type] || type;
            card.querySelector('.gqp-card-score').hidden = !getField(card, 'include_score').checked;
        };
        const makeCard = (data, open) => {
            data = Object.assign({scope_tag_name: '', scope_tag_value: '', scope_group_names: '',
                scope_include_subgroups: 1, group_include_subgroups: 1, template_names: '',
                template_mode: 'any', inventory_field: '', display_mode: 'conformity'}, data);
            const card = element('details', 'gqp-card');
            card.dataset.cardId = data.id;
            card.open = !!open;
            const summary = element('summary', 'gqp-card-summary');
            const summaryText = element('span', 'gqp-card-summary-text');
            summaryText.append(element('strong', 'gqp-card-title', data.title || t('Novo card', 'New card')),
                element('span', 'gqp-card-kind', types[data.type]));
            summary.append(summaryText, element('span', 'gqp-card-score', t('No índice', 'In score')));
            card.append(summary);

            const body = element('div', 'gqp-card-body');
            const grid = element('div', 'gqp-field-grid');
            const identity = element('div', 'gqp-field-grid');
            identity.append(field(t('Nome do indicador', 'Indicator name'), input('title', data.title, 100, true)));
            const select = element('select');
            select.dataset.field = 'type';
            Object.entries(types).forEach(([value, title]) => {
                const option = element('option', '', title);
                option.value = value;
                select.append(option);
            });
            select.value = data.type;
            select.required = true;
            grid.append(field(t('O que medir nos hosts selecionados', 'What to measure in selected hosts'), select));
            const description = element('textarea');
            description.dataset.field = 'description';
            description.value = data.description;
            description.maxLength = 255;
            description.rows = 2;
            identity.append(field(t('Descrição (opcional)', 'Description (optional)'), description, '', 'gqp-field-wide'));
            const tagNames = input('tag_names', data.tag_names, 255);
            tagNames.placeholder = 'departamento,department,dept';
            const tagGroup = field(t('Tags / aliases', 'Tags / aliases'), tagNames,
                t('Nomes separados por vírgula.', 'Comma-separated names.'));
            tagGroup.dataset.forType = 'tag';
            const tagValues = input('tag_values', data.tag_values, 255);
            tagValues.placeholder = 'prod,homolog';
            const valuesGroup = field(t('Valores aceitos (opcional)', 'Accepted values (optional)'), tagValues,
                t('Vazio: qualquer valor não vazio.', 'Empty: any non-empty value.'));
            valuesGroup.dataset.forType = 'tag';
            const groups = input('group_names', data.group_names, 255);
            groups.placeholder = t('Equipes, Linux, 12', 'Teams, Linux, 12');
            const groupGroup = field(t('Grupos de hosts (nomes ou IDs)', 'Host groups (names or IDs)'), catalogControl(groups, () => 'group'),
                t('Separe por vírgula. A opção de subgrupos se aplica a nomes; IDs selecionam o grupo exato.', 'Separate with commas. The subgroup option applies to names; IDs select the exact group.'), 'gqp-field-wide');
            groupGroup.dataset.forType = 'hostgroups';
            grid.append(tagGroup, valuesGroup, groupGroup);
            const choice = (key, options) => {
                const control = element('select');
                control.dataset.field = key;
                Object.entries(options).forEach(([value, label]) => {
                    const option = element('option', '', label); option.value = value; control.append(option);
                });
                control.value = String(data[key]);
                return control;
            };
            const conditional = (type, label) => { label.dataset.forType = type; return label; };
            const yesNo = {1: t('Incluir subgrupos por nome', 'Include subgroups by name'), 0: t('Somente grupo exato', 'Exact group only')};
            grid.append(conditional('hostgroups', field(t('Subgrupos da regra', 'Rule subgroups'), choice('group_include_subgroups', yesNo))),
                conditional('templates', field(t('Templates esperados (nomes ou IDs)', 'Expected templates (names or IDs)'), catalogControl(input('template_names', data.template_names, 255), () => 'template'),
                    t('Separe por vírgula. Vazio: qualquer template diretamente vinculado.', 'Separate with commas. Empty: any directly linked template.'))),
                conditional('templates', field(t('Exigir templates', 'Require templates'), choice('template_mode', {any: t('Ao menos um dos informados', 'At least one listed'), all: t('Todos os informados', 'All listed')}))),
                conditional('inventory', field(t('Campo de inventário', 'Inventory field'), choice('inventory_field', {
                    '': t('Qualquer campo essencial', 'Any essential field'), os: t('SO', 'OS'), os_full: t('SO (completo)', 'OS (full)'),
                    os_short: t('SO (curto)', 'OS (short)'), serialno_a: 'Serial A', serialno_b: 'Serial B', location: t('Localização', 'Location'),
                    type: t('Tipo', 'Type'), software: 'Software', hardware: 'Hardware', name: t('Nome', 'Name'), contact: t('Contato', 'Contact')
                }))));
            const scope = makeSelection(data);
            grid.append(field(t('Percentual exibido', 'Displayed percentage'), choice('display_mode', {
                conformity: t('Em conformidade', 'Compliant'), non_conformity: t('Não conformes', 'Non-compliant')
            })));
            const preview = element('section', 'gqp-preview');
            const previewButton = button(t('Testar seleção e indicador', 'Test selection and indicator'), 'btn-alt');
            const cancelPreview = button(t('Cancelar prévia', 'Cancel preview'), 'btn-alt gqp-cancel-preview'); cancelPreview.hidden = true;
            const previewOutput = element('div', 'gqp-preview-output'); previewOutput.setAttribute('role', 'status');
            previewButton.addEventListener('click', () => runPreview(card, previewButton, previewOutput, cancelPreview));
            cancelPreview.addEventListener('click', () => { invalidatePreview(); cancelPreview.hidden = true; previewOutput.textContent = t('Prévia cancelada.', 'Preview cancelled.'); });
            preview.append(previewButton, cancelPreview, previewOutput);
            body.append(identity, scope, element('h3', '', t('2. Medir o indicador', '2. Measure the indicator')), grid, element('p', 'gqp-metric-hint gqp-muted'), preview);

            const footer = element('div', 'gqp-card-footer');
            const scoreLabel = element('label', 'gqp-checkbox');
            const score = element('input');
            score.type = 'checkbox';
            score.dataset.field = 'include_score';
            score.checked = !!Number(data.include_score);
            scoreLabel.append(score, element('span', '', t('Participa do índice desta página', 'Included in this page score')));
            footer.append(scoreLabel, button(t('Remover card', 'Remove card'), 'btn-alt gqp-remove-card gqp-danger'));
            body.append(footer);
            card.append(body);
            updateConditionalFields(card);
            return card;
        };
        const makePage = (data) => {
            const panel = element('section', 'gqp-page-panel');
            panel.dataset.pageId = data.id;
            panel.id = 'gqp-panel-' + data.id;
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', 'gqp-tab-' + data.id);
            const heading = element('div', 'gqp-page-settings');
            const nameInput = input('page_name', data.name, 100, data.id !== 'main');
            nameInput.placeholder = data.id === 'main' ? defaultPageName : t('Ex.: Inventário e cadastro', 'E.g. Inventory and registration');
            heading.append(field(t('Nome da página', 'Page name'), nameInput,
                data.id === 'main' ? t('Vazio: usa o nome padrão traduzido “Qualidade”.', 'Empty: uses the translated default name “Quality”.') : '', 'gqp-page-name'));
            const actions = element('div', 'gqp-page-actions');
            actions.append(element('span', 'gqp-page-count gqp-muted'),
                button(t('Remover página', 'Remove page'), 'btn-alt gqp-remove-page gqp-danger'));
            heading.append(actions);
            panel.append(heading);
            const list = element('div', 'gqp-card-list');
            data.cards.forEach((card, index) => list.append(makeCard(card, index === 0)));
            const empty = element('div', 'gqp-empty');
            empty.append(element('h3', '', t('Esta página ainda não tem cards', 'This page has no cards yet')),
                element('p', 'gqp-muted', t('Adicione uma métrica de tags, grupos ou cadastro. Você também pode salvar a página vazia.', 'Add a tag, group or registration metric. You can also save this page empty.')));
            panel.append(list, empty);
            return panel;
        };
        const makeTab = (panel) => {
            const tab = button(pageName(panel), 'gqp-page-link');
            tab.id = 'gqp-tab-' + panel.dataset.pageId;
            tab.dataset.pageId = panel.dataset.pageId;
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', panel.id);
            return tab;
        };
        const updatePage = (panel) => {
            const count = cards(panel).length;
            const tab = [...tabs.children].find((item) => item.dataset.pageId === panel.dataset.pageId);
            if (tab) {
                tab.textContent = pageName(panel);
                tab.title = pageName(panel);
            }
            panel.querySelector('.gqp-page-count').textContent = count + ' / ' + maxCards + ' cards';
            panel.querySelector('.gqp-empty').hidden = count !== 0;
            panel.querySelector('.gqp-remove-page').disabled = pageNodes().length === 1;
            panel.querySelector('.gqp-remove-page').title = pageNodes().length === 1
                ? t('Mantenha ao menos uma página.', 'Keep at least one page.') : '';
        };
        const updateControls = () => {
            addPageButton.disabled = pageNodes().length >= maxPages;
            addPageButton.title = addPageButton.disabled ? t('Limite de 12 páginas.', 'Limit of 12 pages.') : '';
            const active = activePanel();
            addCardButton.disabled = !active || cards(active).length >= maxCards;
            addCardButton.title = !active ? t('Adicione uma página primeiro.', 'Add a page first.')
                : addCardButton.disabled ? t('Limite de 30 cards por página.', 'Limit of 30 cards per page.') : '';
            root.querySelector('#gov-config-empty').hidden = pageNodes().length !== 0;
            saveButton.disabled = conflict;
            status.textContent = conflict
                ? t('Rascunho preservado. Recarregue as regras salvas antes de salvar novamente.', 'Draft preserved. Reload saved rules before saving again.')
                : (dirty ? t('Alterações não salvas · salva todas as páginas', 'Unsaved changes · saves all pages')
                    : t('Todas as páginas carregadas', 'All pages loaded'));
        };
        const serialize = () => {
            const pages = pageNodes().map((panel) => ({
                id: panel.dataset.pageId,
                name: getField(panel, 'page_name').value,
                cards: cards(panel).map((card) => ({
                    id: card.dataset.cardId,
                    type: getField(card, 'type').value,
                    title: getField(card, 'title').value,
                    description: getField(card, 'description').value,
                    tag_names: getField(card, 'tag_names').value,
                    tag_values: getField(card, 'tag_values').value,
                    group_names: getField(card, 'group_names').value,
                    selection: readSelection(card),
                    group_include_subgroups: Number(getField(card, 'group_include_subgroups').value),
                    template_names: getField(card, 'template_names').value,
                    template_mode: getField(card, 'template_mode').value,
                    inventory_field: getField(card, 'inventory_field').value,
                    display_mode: getField(card, 'display_mode').value,
                    include_score: getField(card, 'include_score').checked ? 1 : 0
                }))
            }));
            payload.value = JSON.stringify(pages);
            selectedField.value = selected;
            root.querySelector('#gov-draft-copy').value = payload.value;
        };
        const runPreview = async (card, trigger, output, cancel) => {
            if (previewActive) { output.textContent = t('Aguarde a prévia em andamento.', 'Wait for the current preview.'); return; }
            const controls = [...card.querySelectorAll('input,select,textarea')].filter(c => !c.disabled);
            controls.forEach(c => { if (c.type !== 'checkbox') updateValidity(c); });
            const invalid = controls.find(c => !c.checkValidity());
            if (invalid) { revealInvalid(invalid); invalid.reportValidity(); return; }
            invalidatePreview();
            const epoch = previewEpoch;
            let job = null;
            let post;
            previewActive = true; trigger.disabled = true;
            cancel.hidden = false;
            const localError = t('Não foi possível testar. Confira a sessão e tente novamente.', 'Could not test. Check your session and retry.');
            try {
                serialize();
                // IDs are unique only within each page.
                const pageDraft = JSON.parse(payload.value).find(p => p.id === card.closest('.gqp-page-panel').dataset.pageId);
                const rule = pageDraft.cards.find(c => c.id === card.dataset.cardId);
                const sid = form.querySelector('[name="sid"]')?.value;
                if (!sid || !window.fetch || !window.crypto?.getRandomValues) throw new Error(localError);
                const request = [...window.crypto.getRandomValues(new Uint8Array(32))].map(n => n.toString(16).padStart(2, '0')).join('');
                post = async data => {
                    const controller = new AbortController();
                    const timer = setTimeout(() => controller.abort(), 25000);
                    try {
                        const response = await window.fetch('zabbix.php?action=governance.quality.run', {method: 'POST', credentials: 'same-origin', redirect: 'error', cache: 'no-store',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', Accept: 'application/json'},
                            body: new URLSearchParams({sid, ...data}).toString(), signal: controller.signal});
                        if (!response.ok) throw new Error(localError);
                        return await response.json();
                    } finally { clearTimeout(timer); }
                };
                let data = {operation: 'preview_start', request_id: request, card_json: JSON.stringify(rule)};
                for (let step = 0; step < 510; step++) {
                    if (epoch !== previewEpoch) break;
                    output.textContent = t('Consultando hosts… ', 'Reading hosts… ') + (job ? (job.progress.hosts_done || 0) + ' / ' + (job.progress.hosts_total ?? '…') : '');
                    const response = await post(data);
                    if (response.status === 'failed') throw new Error(typeof response.error === 'string' ? response.error : localError);
                    if (!/^[a-f0-9]{64}$/.test(response.job) || !Number.isSafeInteger(response.sequence) || response.sequence < 0
                            || (job && (response.job !== job.job || response.sequence < job.sequence)) || response.page !== 'preview'
                            || !['running', 'complete'].includes(response.status) || !response.progress) throw new Error(localError);
                    job = response;
                    if (epoch !== previewEpoch) break;
                    if (job.status === 'complete') {
                        const result = job.result, kpi = result?.kpis?.[0];
                        const total = kpi?.total_count ?? 0, valid = kpi?.valid_count ?? 0;
                        if (!result || !Number.isSafeInteger(result.total_hosts) || result.total_hosts < 0
                                || !Array.isArray(result.kpis) || result.kpis.length !== (result.total_hosts > 0 ? 1 : 0)
                                || (kpi && kpi.id !== rule.id) || !Array.isArray(result.preview_hosts)
                                || result.preview_hosts.length > Math.min(50, total)
                                || !Number.isSafeInteger(total) || total < 0 || total > result.total_hosts
                                || !Number.isSafeInteger(valid) || valid < 0 || valid > total) throw new Error(localError);
                        const seen = new Set();
                        for (const host of result.preview_hosts) {
                            if (!/^[1-9][0-9]*$/.test(host.hostid) || typeof host.name !== 'string' || typeof host.compliant !== 'boolean' || seen.has(String(host.hostid))) throw new Error(localError);
                            seen.add(String(host.hostid));
                        }
                        output.replaceChildren(element('p', '', t('Selecionados: ', 'Selected: ') + total + ' · '
                            + t('Atendem ao indicador: ', 'Meet the indicator: ') + valid + ' · ' + t('Não atendem: ', 'Do not meet: ') + (total - valid)));
                        output.append(element('p', 'gqp-muted', t('Amostra: ', 'Sample: ') + result.preview_hosts.length + ' / ' + total
                            + t('. Até 50 hosts. Prévia do rascunho, sem salvar; considera todos os hosts monitorados acessíveis, sem o filtro de grupos do painel.', '. Up to 50 hosts. Unsaved draft preview; uses all accessible monitored hosts, without the dashboard group filter.')));
                        const table = element('table', 'list-table');
                        const header = element('tr'); header.append(element('th', '', 'Host'), element('th', '', t('Indicador', 'Indicator'))); table.append(header);
                        for (const host of result.preview_hosts) {
                            if (!/^[1-9][0-9]*$/.test(host.hostid) || typeof host.name !== 'string' || typeof host.compliant !== 'boolean') throw new Error(localError);
                            const row = element('tr'), name = element('td'), link = element('a', '', host.name);
                            link.href = 'zabbix.php?action=host.edit&hostid=' + encodeURIComponent(host.hostid); link.target = '_blank'; link.rel = 'noopener';
                            name.append(link); row.append(name, element('td', '', host.compliant ? t('Atende', 'Meets') : t('Não atende', 'Does not meet'))); table.append(row);
                        }
                        output.append(table); return;
                    }
                    data = {operation: 'step', job: job.job, sequence: String(job.sequence)};
                }
                if (epoch === previewEpoch) throw new Error(localError);
            } catch (e) {
                if (epoch === previewEpoch) {
                    const parts = String(e.message || localError).split(' / ');
                    output.textContent = (pt ? parts[1] || parts[0] : parts[0]).slice(0, 800);
                }
            } finally {
                if (job?.status === 'running' && post) {
                    // Release the checkpoint after edits/errors. A late step may have advanced its sequence.
                    try {
                        const cancelled = await post({operation: 'cancel', job: job.job, sequence: String(job.sequence)});
                        if (cancelled.status === 'running' && Number.isSafeInteger(cancelled.sequence)) {
                            await post({operation: 'cancel', job: job.job, sequence: String(cancelled.sequence)});
                        }
                    } catch (_) { /* Expiring server checkpoints remain bounded; never restart implicitly. */ }
                }
                previewActive = false; trigger.disabled = false; cancel.hidden = true;
            }
        };
        const selectPage = (id, focusTab) => {
            if (!pageNodes().some((panel) => panel.dataset.pageId === id)) return;
            selected = id;
            invalidatePreview();
            pageNodes().forEach((panel) => { panel.hidden = panel.dataset.pageId !== selected; });
            [...tabs.children].forEach((tab) => {
                const current = tab.dataset.pageId === selected;
                tab.setAttribute('aria-selected', current ? 'true' : 'false');
                tab.tabIndex = current ? 0 : -1;
                if (current && focusTab) {
                    tab.focus();
                    tab.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                }
            });
            serialize();
            updateControls();
        };
        const changed = () => {
            invalidatePreview();
            dirty = true;
            error.hidden = true;
            pageNodes().forEach(updatePage);
            serialize();
            updateControls();
        };
        const revealInvalid = (control) => {
            const panel = control.closest('.gqp-page-panel');
            if (panel) selectPage(panel.dataset.pageId, false);
            const card = control.closest('.gqp-card');
            if (card) card.open = true;
            const section = control.closest('.gqp-help');
            if (section) section.open = true;
            control.focus();
        };
        const updateValidity = (control) => {
            control.setCustomValidity('');
            if (control.dataset.field === 'selection_formula' && !control.disabled) {
                const text = control.value.trim(), count = control.closest('.gqp-card').querySelector('.gqp-conditions').children.length;
                const raw = text.match(/\s+|and\b|or\b|not\b|[A-T]|\(|\)/gi) || [];
                const tokens = raw.filter(s => s.trim()).map(s => s.toLowerCase());
                let depth = 0, operand = true, valid = text.length <= 512 && tokens.length <= 256 && raw.join('') === text;
                const seen = new Set();
                for (const token of tokens) {
                    if (/^[a-t]$/.test(token)) {
                        const index = token.charCodeAt(0) - 97;
                        if (!operand || index >= count) valid = false;
                        seen.add(index); operand = false;
                    }
                    else if (token === '(') { if (!operand) valid = false; depth++; }
                    else if (token === ')') { if (operand || --depth < 0) valid = false; }
                    else if (token === 'not') { if (!operand) valid = false; }
                    else { if (operand) valid = false; operand = true; }
                }
                if (!valid || operand || depth !== 0 || seen.size !== count) {
                    control.setCustomValidity(t('Revise a expressão: use todos os rótulos, and/or/not e parênteses válidos.', 'Review the expression: use every label, and/or/not and valid parentheses.'));
                }
                return;
            }
            if (control.required && !control.value.trim()) {
                control.setCustomValidity(t('Preencha este campo; espaços não são um valor válido.', 'Fill in this field; spaces are not a valid value.'));
            }
            else if (control.required && ['tag_names', 'group_names'].includes(control.dataset.field)
                    && !control.value.split(',').some((value) => value.trim())) {
                control.setCustomValidity(t('Informe ao menos um nome válido, além das vírgulas.', 'Enter at least one valid name, not just commas.'));
            }
            else if (control.maxLength > 0 && [...control.value].length > control.maxLength) {
                control.setCustomValidity(t('O texto excede o limite de caracteres.', 'The text exceeds the character limit.'));
            }
            else if ((control.dataset.field === 'description' ? /[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/
                    : /[\u0000-\u001f\u007f]/).test(control.value)) {
                control.setCustomValidity(t('Remova os caracteres de controle deste texto.', 'Remove control characters from this text.'));
            }
        };

        try {
            const data = JSON.parse(root.querySelector('#gov-quality-data').textContent);
            if (!Array.isArray(data) || data.length > maxPages) throw new Error('Invalid pages');
            const ids = new Set();
            data.forEach((page) => {
                if (!page || typeof page.id !== 'string' || !/^[a-zA-Z0-9_-]{1,64}$/.test(page.id)
                        || ids.has(page.id) || typeof page.name !== 'string'
                        || !Array.isArray(page.cards) || page.cards.length > maxCards) throw new Error('Invalid page');
                if (/[\u0000-\u001f\u007f]/.test(page.name)) throw new Error('Invalid page text');
                ids.add(page.id);
                const cardIds = new Set();
                page.cards.forEach((card) => {
                    if (!card || typeof card.id !== 'string' || !/^[a-zA-Z0-9_-]{1,64}$/.test(card.id)
                            || cardIds.has(card.id) || !Object.prototype.hasOwnProperty.call(types, card.type)) throw new Error('Invalid card');
                    if (![0, 1, '0', '1', false, true].includes(card.include_score)) throw new Error('Invalid score selection');
                    cardIds.add(card.id);
                    ['title', 'description', 'tag_names', 'tag_values', 'group_names'].forEach((key) => {
                        if (typeof card[key] !== 'string') throw new Error('Invalid card field');
                        if ((key === 'description' ? /[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/
                                : /[\u0000-\u001f\u007f]/).test(card[key])) throw new Error('Invalid card text');
                    });
                });
                const panel = makePage(page);
                panels.append(panel);
                tabs.append(makeTab(panel));
            });
            pageNodes().forEach(updatePage);
            if (!ids.has(selected)) selected = data.length ? data[0].id : '';

            root.addEventListener('input', (event) => {
                const control = event.target;
                if (!control.matches('[data-field]')) return;
                if (control.type !== 'checkbox') updateValidity(control);
                const card = control.closest('.gqp-card');
                if (card) {
                    card.querySelector('.gqp-card-title').textContent = getField(card, 'title').value.trim() || t('Novo card', 'New card');
                    updateConditionalFields(card);
                }
                changed();
            });
            root.addEventListener('change', (event) => {
                const control = event.target;
                if (!control.matches('[data-field]')) return;
                const card = control.closest('.gqp-card');
                if (card) updateConditionalFields(card);
                changed();
            });
            root.addEventListener('click', (event) => {
                const target = event.target.closest('button');
                if (!target || !root.contains(target) || target.disabled) return;
                if (target.matches('#gov-add-page')) {
                    if (pageNodes().length >= maxPages) return;
                    const names = new Set(pageNodes().map((panel) => pageName(panel).toLocaleLowerCase()));
                    let count = pageNodes().length + 1;
                    let name;
                    do { name = t('Página ', 'Page ') + count++; } while (names.has(name.toLocaleLowerCase()));
                    const panel = makePage({ id: safeId('page'), name, cards: [] });
                    panels.append(panel);
                    tabs.append(makeTab(panel));
                    changed();
                    selectPage(panel.dataset.pageId, false);
                    getField(panel, 'page_name').focus();
                    getField(panel, 'page_name').select();
                }
                else if (target.matches('.gqp-page-link')) {
                    selectPage(target.dataset.pageId, true);
                }
                else if (target.matches('.gqp-remove-page')) {
                    if (pageNodes().length <= 1) return;
                    const panel = target.closest('.gqp-page-panel');
                    if (!window.confirm(t('Remover a página “', 'Remove page “') + pageName(panel)
                            + t('” e seus cards? A exclusão será aplicada ao salvar todas as páginas.', '” and its cards? The deletion will be applied when all pages are saved.'))) return;
                    const index = pageNodes().indexOf(panel);
                    [...tabs.children].find((tab) => tab.dataset.pageId === panel.dataset.pageId).remove();
                    panel.remove();
                    selected = pageNodes()[Math.min(index, pageNodes().length - 1)].dataset.pageId;
                    changed();
                    selectPage(selected, true);
                }
                else if (target.matches('#gov-add-card')) {
                    const panel = activePanel();
                    if (!panel || cards(panel).length >= maxCards) return;
                    const card = makeCard({ id: safeId('card'), type: 'tag', title: '', description: '',
                        tag_names: '', tag_values: '', group_names: '', include_score: 1, display_mode: 'conformity' }, true);
                    panel.querySelector('.gqp-card-list').append(card);
                    changed();
                    getField(card, 'title').focus();
                }
                else if (target.matches('.gqp-remove-card')) {
                    const card = target.closest('.gqp-card');
                    const name = getField(card, 'title').value.trim();
                    if (name && !window.confirm(t('Remover o card “', 'Remove card “') + name + '”?')) return;
                    card.remove();
                    changed();
                    addCardButton.focus();
                }
            });
            tabs.addEventListener('keydown', (event) => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                const items = [...tabs.children];
                const index = items.indexOf(event.target);
                if (index < 0) return;
                event.preventDefault();
                const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1
                    : (index + (event.key === 'ArrowRight' ? 1 : -1) + items.length) % items.length;
                selectPage(items[next].dataset.pageId, true);
            });
            form.addEventListener('invalid', (event) => revealInvalid(event.target), true);
            form.addEventListener('submit', (event) => {
                if (conflict) {
                    event.preventDefault();
                    showError(t('Recarregue as regras salvas para resolver o conflito de versões.', 'Reload saved rules to resolve the version conflict.'));
                    return;
                }
                try {
                    serialize();
                    const controls = [...panels.querySelectorAll('input, select, textarea')].filter((control) => !control.disabled);
                    controls.forEach((control) => { if (control.type !== 'checkbox') updateValidity(control); });
                    const invalid = controls.find((control) => !control.checkValidity());
                    if (invalid) {
                        event.preventDefault();
                        revealInvalid(invalid);
                        invalid.reportValidity();
                        showError(t('Revise o campo destacado antes de salvar todas as páginas.', 'Review the highlighted field before saving all pages.'));
                    }
                }
                catch (serializationError) {
                    event.preventDefault();
                    saveButton.disabled = true;
                    root.querySelector('#gov-draft-backup').hidden = false;
                    showError(t('Não foi possível preparar as alterações. Copie o rascunho abaixo antes de recarregar; nada foi salvo.', 'Changes could not be prepared. Copy the draft below before reloading; nothing was saved.'));
                }
            });
            form.noValidate = true;
            window.addEventListener('pagehide', invalidatePreview);
            root.closest('main')?.classList.add('gqp-main');
            root.closest('.wrapper')?.classList.add('gqp-layout');
            if (data.length) selectPage(selected, false);
            else {
                serialize();
                updateControls();
            }
        }
        catch (initializationError) {
            addPageButton.disabled = true;
            addCardButton.disabled = true;
            saveButton.disabled = true;
            form.addEventListener('submit', (event) => event.preventDefault());
            root.querySelector('#gov-draft-backup').hidden = false;
            showError(t('Não foi possível iniciar o editor. Copie o rascunho abaixo antes de recarregar; nenhuma alteração foi salva.', 'The editor could not be started. Copy the draft below before reloading; no changes were saved.'));
            status.textContent = t('Editor indisponível', 'Editor unavailable');
        }
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
})();
