<?php

/**
 * @var CView $this
 * @var array $data
 */

$moduleWebPath = 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/assets/';
$assetVersion = '?v=1.2.0';
$this->addCssFile($moduleWebPath . 'css/governance.css' . $assetVersion);
$this->addJsFile($moduleWebPath . 'js/config.js' . $assetVersion);

$widget = (new CWidget())->setTitle($data['page_title']);

$form = (new CForm())
    ->setId('gov-config-form')
    ->setAction((new CUrl('zabbix.php'))
        ->setArgument('action', 'governance.quality.config.update')
        ->getUrl()
    );

$table = (new CTable())
    ->setId('gov-config-table')
    ->addClass('gov-config-table')
    ->setHeader([
        'Nome do card',
        'Descrição',
        'Métrica',
        'Tags / aliases',
        'Valores aceitos',
        'Score geral',
        ''
    ]);

foreach ($data['cards'] as $index => $card) {
    $typeSelect = (new CSelect('cards[' . $index . '][type]'))
        ->addClass('gov-card-type')
        ->setValue($card['type'])
        ->addOptions(CSelect::createOptionsFromArray([
            'tag' => 'Tag personalizada',
            'inventory' => 'Inventário preenchido',
            'templates' => 'Template vinculado',
            'interface' => 'Interface configurada'
        ]));

    $table->addRow((new CRow([
        new CCol([
            (new CVar('cards[' . $index . '][id]', $card['id']))->removeId(),
            (new CTextBox('cards[' . $index . '][title]', $card['title']))
                ->setAttribute('placeholder', 'Ex.: Tag de Unidade')
        ]),
        new CCol(
            (new CTextArea('cards[' . $index . '][description]', $card['description']))
                ->setAttribute('rows', '2')
        ),
        new CCol($typeSelect),
        new CCol(
            (new CTextBox('cards[' . $index . '][tag_names]', $card['tag_names']))
                ->addClass('gov-tag-field')
                ->setAttribute('placeholder', 'unidade,unit,site')
        ),
        new CCol(
            (new CTextBox('cards[' . $index . '][tag_values]', $card['tag_values']))
                ->addClass('gov-tag-field')
                ->setAttribute('placeholder', 'Opcional: prod,homolog')
        ),
        new CCol(
            (new CCheckBox('cards[' . $index . '][include_score]', 1))
                ->setChecked((bool) $card['include_score'])
        ),
        new CCol(
            (new CButton('remove_card_' . $index, 'Remover'))
                ->setAttribute('type', 'button')
                ->addClass('gov-remove-card')
        )
    ]))->addClass('gov-config-row'));
}

$help = (new CDiv([
    new CTag('p', true,
        'Para métricas de tag, informe um ou mais nomes separados por vírgula. O card considera a tag válida quando ela possui valor.'
    ),
    new CTag('p', true,
        'Valores aceitos é opcional. Quando preenchido, somente esses valores contam como conformes.'
    )
]))->addClass('gov-config-help');

$buttons = (new CDiv([
    (new CButton('add_card', 'Adicionar card'))
        ->setId('gov-add-card')
        ->setAttribute('type', 'button'),
    new CSubmit('save', 'Salvar configuração')
]))->addClass('gov-config-actions');

$form->addItem([$help, $table, $buttons]);

$content = (new CDiv($form))
    ->addClass('gov-container')
    ->addClass('gov-config-container');
if (!empty($data['is_dark'])) {
    $content->addClass('gov-theme-dark');
}

$widget->addItem($content)->show();
