(() => {
    const initializeConfig = () => {
        const list = document.getElementById('gov-config-list');
        const addButton = document.getElementById('gov-add-card');

        if (!list || !addButton) {
            return;
        }

        const isPt = list.getAttribute('data-lang') === 'pt';
        const text = isPt ? {
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
        } : {
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
        };

        let nextIndex = list.querySelectorAll('.gov-config-card').length;

        const cardHtml = (index) => `
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

        const updateTagFields = (card) => {
            const isTag = card.querySelector('.gov-card-type').value === 'tag';

            card.querySelectorAll('.gov-tag-setting').forEach((fieldGroup) => {
                fieldGroup.classList.toggle('gov-field-disabled', !isTag);
                fieldGroup.querySelectorAll('input').forEach((input) => {
                    input.disabled = !isTag;
                });
            });
        };

        list.addEventListener('click', (event) => {
            if (event.target.classList.contains('gov-remove-card')) {
                event.target.closest('.gov-config-card').remove();
            }
        });

        list.addEventListener('change', (event) => {
            if (event.target.classList.contains('gov-card-type')) {
                updateTagFields(event.target.closest('.gov-config-card'));
            }
        });

        addButton.addEventListener('click', () => {
            list.insertAdjacentHTML('beforeend', cardHtml(nextIndex++));
        });

        list.querySelectorAll('.gov-config-card').forEach(updateTagFields);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeConfig, { once: true });
    } else {
        initializeConfig();
    }
})();
