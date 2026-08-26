<?php

/**
 * @var CView $this
 * @var array $data
 */

$moduleWebPath = 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/assets/';
$assetVersion = '?v=1.3.0';
$this->addCssFile($moduleWebPath . 'css/governance.css' . $assetVersion);
$this->addJsFile($moduleWebPath . 'js/config.js' . $assetVersion);

$widget = (new CWidget())->setTitle($data['page_title']);
$form = (new CForm())
    ->setId('gov-config-form')
    ->setAction((new CUrl('zabbix.php'))
        ->setArgument('action', 'governance.quality.config.update')
        ->getUrl()
    );

$help = (new CDiv([
    new CTag('p', true,
        'Crie cards de tags ou reutilize métricas nativas. Nomes e valores alternativos devem ser separados por vírgula.'
    ),
    new CTag('p', true,
        'Se valores aceitos ficar vazio, qualquer valor não vazio será considerado conforme.'
    )
]))->addClass('gov-config-help');

$cardList = (new CDiv())
    ->setId('gov-config-list')
    ->addClass('gov-config-list');

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

    $cardList->addItem(
        (new CDiv([
            (new CVar('cards[' . $index . '][id]', $card['id']))->removeId(),
            (new CDiv([
                (new CTextBox('cards[' . $index . '][title]', $card['title']))
                    ->setAttribute('placeholder', 'Nome do card'),
                (new CButton('remove_card_' . $index, 'Remover'))
                    ->setAttribute('type', 'button')
                    ->addClass('gov-remove-card')
            ]))->addClass('gov-config-card-head'),
            (new CDiv([
                (new CDiv([
                    new CTag('label', true, 'Descrição'),
                    (new CTextArea('cards[' . $index . '][description]', $card['description']))
                        ->setAttribute('rows', '2')
                ]))->addClass('gov-config-field')->addClass('gov-config-field-wide'),
                (new CDiv([
                    new CTag('label', true, 'Tipo de métrica'),
                    $typeSelect
                ]))->addClass('gov-config-field'),
                (new CDiv([
                    (new CCheckBox('cards[' . $index . '][include_score]', 1))
                        ->setChecked((bool) $card['include_score']),
                    new CTag('label', true, 'Participa do score geral')
                ]))->addClass('gov-config-field')->addClass('gov-config-score-field'),
                (new CDiv([
                    new CTag('label', true, 'Tags / aliases'),
                    (new CTextBox('cards[' . $index . '][tag_names]', $card['tag_names']))
                        ->addClass('gov-tag-field')
                        ->setAttribute('placeholder', 'unidade,unit,site')
                ]))->addClass('gov-config-field')->addClass('gov-tag-setting'),
                (new CDiv([
                    new CTag('label', true, 'Valores aceitos (opcional)'),
                    (new CTextBox('cards[' . $index . '][tag_values]', $card['tag_values']))
                        ->addClass('gov-tag-field')
                        ->setAttribute('placeholder', 'prod,homolog')
                ]))->addClass('gov-config-field')->addClass('gov-tag-setting')
            ]))->addClass('gov-config-grid')
        ]))->addClass('gov-config-card')
    );
}

$buttons = (new CDiv([
    (new CButton('add_card', 'Adicionar card'))
        ->setId('gov-add-card')
        ->setAttribute('type', 'button'),
    new CSubmit('save', 'Salvar configuração')
]))->addClass('gov-config-actions');

$form->addItem([$help, $cardList, $buttons]);

$content = (new CDiv($form))
    ->addClass('gov-container')
    ->addClass('gov-config-container');

if (!empty($data['is_dark'])) {
    $content->addClass('gov-theme-dark');
}

$widget->addItem($content)->show();
