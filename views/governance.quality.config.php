<?php

/**
 * @var CView $this
 * @var array $data
 */

$moduleWebPath = 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/assets/';
$assetVersion = '?v=1.3.4';
$this->addCssFile($moduleWebPath . 'css/governance.css' . $assetVersion);
$this->includeJsFile('governance.quality.config.js.php');

$isPt = $data['is_pt'];
$widget = (new CWidget())->setTitle($data['page_title']);
$form = (new CForm())
    ->setId('gov-config-form')
    ->setAction((new CUrl('zabbix.php'))
        ->setArgument('action', 'governance.quality.config.update')
        ->getUrl()
    );

$help = (new CDiv([
    new CTag('p', true,
        $isPt
            ? 'Crie cards de tags ou reutilize métricas nativas. Nomes e valores alternativos devem ser separados por vírgula.'
            : 'Create tag cards or reuse native metrics. Alternative names and values must be comma-separated.'
    ),
    new CTag('p', true,
        $isPt
            ? 'Se valores aceitos ficar vazio, qualquer valor não vazio será considerado conforme.'
            : 'If accepted values is empty, any non-empty value will be considered compliant.'
    )
]))->addClass('gov-config-help');

$cardList = (new CDiv())
    ->setId('gov-config-list')
    ->addClass('gov-config-list')
    ->setAttribute('data-lang', $isPt ? 'pt' : 'en');

foreach ($data['cards'] as $index => $card) {
    $typeSelect = (new CSelect('cards[' . $index . '][type]'))
        ->addClass('gov-card-type')
        ->setValue($card['type'])
        ->addOptions(CSelect::createOptionsFromArray([
            'tag' => $isPt ? 'Tag personalizada' : 'Custom tag',
            'inventory' => $isPt ? 'Inventário preenchido' : 'Populated inventory',
            'templates' => $isPt ? 'Template vinculado' : 'Linked template',
            'interface' => $isPt ? 'Interface configurada' : 'Configured interface'
        ]));

    $cardList->addItem(
        (new CDiv([
            (new CVar('cards[' . $index . '][id]', $card['id']))->removeId(),
            (new CDiv([
                (new CTextBox('cards[' . $index . '][title]', $card['title']))
                    ->setAttribute('placeholder', $isPt ? 'Nome do card' : 'Card name'),
                (new CButton('remove_card_' . $index, $isPt ? 'Remover' : 'Remove'))
                    ->setAttribute('type', 'button')
                    ->addClass('gov-remove-card')
            ]))->addClass('gov-config-card-head'),
            (new CDiv([
                (new CDiv([
                    new CTag('label', true, $isPt ? 'Descrição' : 'Description'),
                    (new CTextArea('cards[' . $index . '][description]', $card['description']))
                        ->setAttribute('rows', '2')
                ]))->addClass('gov-config-field')->addClass('gov-config-field-wide'),
                (new CDiv([
                    new CTag('label', true, $isPt ? 'Tipo de métrica' : 'Metric type'),
                    $typeSelect
                ]))->addClass('gov-config-field'),
                (new CDiv([
                    (new CCheckBox('cards[' . $index . '][include_score]', 1))
                        ->setChecked((bool) $card['include_score']),
                    new CTag('label', true, $isPt ? 'Participa do score geral' : 'Included in overall score')
                ]))->addClass('gov-config-field')->addClass('gov-config-score-field'),
                (new CDiv([
                    new CTag('label', true, $isPt ? 'Tags / aliases' : 'Tags / aliases'),
                    (new CTextBox('cards[' . $index . '][tag_names]', $card['tag_names']))
                        ->addClass('gov-tag-field')
                        ->setAttribute('placeholder', 'unidade,unit,site')
                ]))->addClass('gov-config-field')->addClass('gov-tag-setting'),
                (new CDiv([
                    new CTag('label', true, $isPt ? 'Valores aceitos (opcional)' : 'Accepted values (optional)'),
                    (new CTextBox('cards[' . $index . '][tag_values]', $card['tag_values']))
                        ->addClass('gov-tag-field')
                        ->setAttribute('placeholder', 'prod,homolog')
                ]))->addClass('gov-config-field')->addClass('gov-tag-setting')
            ]))->addClass('gov-config-grid')
        ]))->addClass('gov-config-card')
    );
}

$buttons = (new CDiv([
    (new CButton('add_card', $isPt ? 'Adicionar card' : 'Add card'))
        ->setId('gov-add-card')
        ->setAttribute('type', 'button'),
    new CSubmit('save', $isPt ? 'Salvar configuração' : 'Save configuration')
]))->addClass('gov-config-actions');

$form->addItem([$help, $cardList, $buttons]);

$content = (new CDiv($form))
    ->addClass('gov-container')
    ->addClass('gov-config-container');

if (!empty($data['is_dark'])) {
    $content->addClass('gov-theme-dark');
}

$widget->addItem($content)->show();
