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
        const updateConditionalFields = (card) => {
            const type = getField(card, 'type').value;
            card.querySelectorAll('[data-for-type]').forEach((group) => {
                const applicable = group.dataset.forType === type;
                group.hidden = !applicable;
                group.querySelectorAll('input').forEach((control) => {
                    control.disabled = !applicable;
                    control.required = applicable && control.dataset.field !== 'tag_values';
                    if (!applicable) control.setCustomValidity('');
                });
            });
            const hints = {
                tag: t('Confere se o host tem uma das tags informadas com um valor aceito.', 'Checks whether the host has one of the specified tags with an accepted value.'),
                hostgroups: t('Confere se o host pertence a um dos grupos informados, incluindo subgrupos selecionados por nome.', 'Checks whether the host belongs to a specified group, including subgroups selected by name.'),
                inventory: t('Confere se ao menos um dos campos essenciais de inventário avaliados pelo módulo está preenchido.', 'Checks whether at least one of the essential inventory fields assessed by this module is populated.'),
                templates: t('Confere se há ao menos um template vinculado ao host.', 'Checks whether the host has at least one linked template.'),
                interface: t('Confere se há ao menos uma interface com IP ou DNS configurado.', 'Checks whether there is at least one interface with an IP address or DNS name configured.')
            };
            card.querySelector('.gqp-metric-hint').textContent = hints[type] || '';
            card.querySelector('.gqp-card-kind').textContent = types[type] || type;
            card.querySelector('.gqp-card-score').hidden = !getField(card, 'include_score').checked;
        };
        const makeCard = (data, open) => {
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
            grid.append(field(t('Nome do card', 'Card name'), input('title', data.title, 100, true)));
            const select = element('select');
            select.dataset.field = 'type';
            Object.entries(types).forEach(([value, title]) => {
                const option = element('option', '', title);
                option.value = value;
                select.append(option);
            });
            select.value = data.type;
            select.required = true;
            grid.append(field(t('Tipo de métrica', 'Metric type'), select));
            const description = element('textarea');
            description.dataset.field = 'description';
            description.value = data.description;
            description.maxLength = 255;
            description.rows = 2;
            grid.append(field(t('Descrição (opcional)', 'Description (optional)'), description, '', 'gqp-field-wide'));
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
            const groupGroup = field(t('Grupos de hosts (nomes ou IDs)', 'Host groups (names or IDs)'), groups,
                t('Nomes incluem subgrupos; IDs selecionam o grupo exato. Separe por vírgula.', 'Names include subgroups; IDs select the exact group. Separate entries with commas.'), 'gqp-field-wide');
            groupGroup.dataset.forType = 'hostgroups';
            grid.append(tagGroup, valuesGroup, groupGroup);
            body.append(grid, element('p', 'gqp-metric-hint gqp-muted'));

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
                    include_score: getField(card, 'include_score').checked ? 1 : 0
                }))
            }));
            payload.value = JSON.stringify(pages);
            selectedField.value = selected;
            root.querySelector('#gov-draft-copy').value = payload.value;
        };
        const selectPage = (id, focusTab) => {
            if (!pageNodes().some((panel) => panel.dataset.pageId === id)) return;
            selected = id;
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
            control.focus();
        };
        const updateValidity = (control) => {
            control.setCustomValidity('');
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
                        tag_names: '', tag_values: '', group_names: '', include_score: 1 }, true);
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
