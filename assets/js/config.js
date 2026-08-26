(() => {
    const initializeConfig = () => {
        const list = document.getElementById('gov-config-list');
        const addButton = document.getElementById('gov-add-card');

        if (!list || !addButton) {
            return;
        }

        let nextIndex = list.querySelectorAll('.gov-config-card').length;

        const cardHtml = (index) => `
            <div class="gov-config-card">
                <input type="hidden" name="cards[${index}][id]" value="custom_${index}">
                <div class="gov-config-card-head">
                    <input type="text" name="cards[${index}][title]" maxlength="255" placeholder="Nome do card">
                    <button type="button" class="gov-remove-card">Remover</button>
                </div>
                <div class="gov-config-grid">
                    <div class="gov-config-field gov-config-field-wide">
                        <label>Descrição</label>
                        <textarea name="cards[${index}][description]" rows="2"></textarea>
                    </div>
                    <div class="gov-config-field">
                        <label>Tipo de métrica</label>
                        <select name="cards[${index}][type]" class="gov-card-type">
                            <option value="tag">Tag personalizada</option>
                            <option value="inventory">Inventário preenchido</option>
                            <option value="templates">Template vinculado</option>
                            <option value="interface">Interface configurada</option>
                        </select>
                    </div>
                    <div class="gov-config-field gov-config-score-field">
                        <input type="checkbox" name="cards[${index}][include_score]" value="1" checked>
                        <label>Participa do score geral</label>
                    </div>
                    <div class="gov-config-field gov-tag-setting">
                        <label>Tags / aliases</label>
                        <input type="text" class="gov-tag-field" name="cards[${index}][tag_names]" placeholder="unidade,unit,site">
                    </div>
                    <div class="gov-config-field gov-tag-setting">
                        <label>Valores aceitos (opcional)</label>
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
