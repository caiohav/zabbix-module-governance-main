(() => {
    const translations = {
        pt: {
            cardName: 'Nome do card',
            remove: 'Remover',
            description: 'Descrição',
            metricType: 'Tipo de métrica',
            customTag: 'Tag personalizada',
            inventory: 'Inventário preenchido',
            template: 'Template vinculado',
            interface: 'Interface configurada',
            overallScore: 'Participa do score geral',
            tags: 'Tags / aliases',
            acceptedValues: 'Valores aceitos (opcional)'
        },
        en: {
            cardName: 'Card name',
            remove: 'Remove',
            description: 'Description',
            metricType: 'Metric type',
            customTag: 'Custom tag',
            inventory: 'Populated inventory',
            template: 'Linked template',
            interface: 'Configured interface',
            overallScore: 'Included in overall score',
            tags: 'Tags / aliases',
            acceptedValues: 'Accepted values (optional)'
        }
    };

    const updateTagFields = (card) => {
        const typeSelect = card && card.querySelector('.gov-card-type');

        if (!typeSelect) {
            return;
        }

        const isTag = typeSelect.value === 'tag';

        card.querySelectorAll('.gov-tag-setting').forEach((fieldGroup) => {
            fieldGroup.classList.toggle('gov-field-disabled', !isTag);
            fieldGroup.querySelectorAll('input').forEach((input) => {
                input.disabled = !isTag;
            });
        });
    };

    const getNextIndex = (list) => {
        let nextIndex = 0;

        list.querySelectorAll('[name^="cards["]').forEach((field) => {
            const match = field.name.match(/^cards\[(\d+)]/);

            if (match) {
                nextIndex = Math.max(nextIndex, Number(match[1]) + 1);
            }
        });

        return nextIndex;
    };

    const cardHtml = (index, text) => `
        <div class="gov-config-card">
            <input type="hidden" name="cards[${index}][id]" value="custom_${index}">
            <div class="gov-config-card-head">
                <input type="text" name="cards[${index}][title]" maxlength="255" placeholder="${text.cardName}">
                <button type="button" class="gov-remove-card">${text.remove}</button>
            </div>
            <div class="gov-config-grid">
                <div class="gov-config-field gov-config-field-wide">
                    <label>${text.description}</label>
                    <textarea name="cards[${index}][description]" rows="2"></textarea>
                </div>
                <div class="gov-config-field">
                    <label>${text.metricType}</label>
                    <select name="cards[${index}][type]" class="gov-card-type">
                        <option value="tag">${text.customTag}</option>
                        <option value="inventory">${text.inventory}</option>
                        <option value="templates">${text.template}</option>
                        <option value="interface">${text.interface}</option>
                    </select>
                </div>
                <div class="gov-config-field gov-config-score-field">
                    <input type="checkbox" name="cards[${index}][include_score]" value="1" checked>
                    <label>${text.overallScore}</label>
                </div>
                <div class="gov-config-field gov-tag-setting">
                    <label>${text.tags}</label>
                    <input type="text" class="gov-tag-field" name="cards[${index}][tag_names]" placeholder="unidade,unit,site">
                </div>
                <div class="gov-config-field gov-tag-setting">
                    <label>${text.acceptedValues}</label>
                    <input type="text" class="gov-tag-field" name="cards[${index}][tag_values]" placeholder="prod,homolog">
                </div>
            </div>
        </div>`;

    const initializeExistingCards = () => {
        document.querySelectorAll('#gov-config-list .gov-config-card').forEach(updateTagFields);
    };

    if (!window.governanceConfigEventsInitialized) {
        window.governanceConfigEventsInitialized = true;

        document.addEventListener('click', (event) => {
            const addButton = event.target.closest('#gov-add-card');

            if (addButton) {
                event.preventDefault();

                const list = document.getElementById('gov-config-list');

                if (!list) {
                    return;
                }

                const language = list.getAttribute('data-lang') === 'pt' ? 'pt' : 'en';
                list.insertAdjacentHTML('beforeend', cardHtml(getNextIndex(list), translations[language]));
                updateTagFields(list.lastElementChild);
                return;
            }

            const removeButton = event.target.closest('.gov-remove-card');

            if (removeButton && removeButton.closest('#gov-config-list')) {
                event.preventDefault();
                removeButton.closest('.gov-config-card').remove();
            }
        });

        document.addEventListener('change', (event) => {
            if (event.target.matches('#gov-config-list .gov-card-type')) {
                updateTagFields(event.target.closest('.gov-config-card'));
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeExistingCards, { once: true });
    }
    else {
        initializeExistingCards();
    }
})();
