(() => {
    const initializeConfig = () => {
        const table = document.getElementById('gov-config-table');
        const addButton = document.getElementById('gov-add-card');

        if (!table || !addButton) {
            return;
        }

        let nextIndex = table.querySelectorAll('.gov-config-row').length;

        const rowHtml = (index) => `
            <tr class="gov-config-row">
                <td>
                    <input type="hidden" name="cards[${index}][id]" value="custom_${index}">
                    <input type="text" name="cards[${index}][title]" maxlength="255" placeholder="Ex.: Tag de Unidade">
                </td>
                <td><textarea name="cards[${index}][description]" rows="2"></textarea></td>
                <td>
                    <select name="cards[${index}][type]" class="gov-card-type">
                        <option value="tag">Tag personalizada</option>
                        <option value="inventory">Inventário preenchido</option>
                        <option value="templates">Template vinculado</option>
                        <option value="interface">Interface configurada</option>
                    </select>
                </td>
                <td><input type="text" class="gov-tag-field" name="cards[${index}][tag_names]" placeholder="unidade,unit,site"></td>
                <td><input type="text" class="gov-tag-field" name="cards[${index}][tag_values]" placeholder="Opcional: prod,homolog"></td>
                <td><input type="checkbox" name="cards[${index}][include_score]" value="1" checked></td>
                <td><button type="button" class="gov-remove-card">Remover</button></td>
            </tr>`;

        const updateTagFields = (row) => {
            const isTag = row.querySelector('.gov-card-type').value === 'tag';
            row.querySelectorAll('.gov-tag-field').forEach((field) => {
                field.disabled = !isTag;
                field.closest('td').classList.toggle('gov-field-disabled', !isTag);
            });
        };

        table.addEventListener('click', (event) => {
            if (event.target.classList.contains('gov-remove-card')) {
                event.target.closest('.gov-config-row').remove();
            }
        });

        table.addEventListener('change', (event) => {
            if (event.target.classList.contains('gov-card-type')) {
                updateTagFields(event.target.closest('.gov-config-row'));
            }
        });

        addButton.addEventListener('click', () => {
            table.querySelector('tbody').insertAdjacentHTML('beforeend', rowHtml(nextIndex++));
        });

        table.querySelectorAll('.gov-config-row').forEach(updateTagFields);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeConfig, { once: true });
    } else {
        initializeConfig();
    }
})();
