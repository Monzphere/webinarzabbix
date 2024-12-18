<?php

// Cria a página HTML
$page = new CHtmlPage();
$page->setTitle(_('Host Location Map'));

// Inclui os assets do Leaflet via CDN
insert_js('
    var script = document.createElement("script");
    script.src = "https://unpkg.com/leaflet@1.9.4/dist/leaflet.js";
    document.head.appendChild(script);
', true);

// Inclui o CSS do Leaflet
$page->addItem(
    (new CTag('link', true))
        ->setAttribute('rel', 'stylesheet')
        ->setAttribute('href', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css')
);

// Container para mensagens
$messageBox = (new CDiv())
    ->setId('messageBox')
    ->addClass('message-container');

// Mostra mensagem de erro se houver
if (isset($data['error'])) {
    $messageBox->addClass('msg-bad')
        ->addItem($data['error']);
}

// Cria o filtro
$filter = (new CFilter())
    ->setResetUrl((new CUrl('zabbix.php'))
        ->setArgument('action', 'map.view'))
    ->addVar('action', 'map.view');

// Campo de seleção de grupos
$groupCombobox = (new CMultiSelect([
    'name' => 'groupids',
    'object_name' => 'hostGroup',
    'data' => [],
    'multiple' => false,
    'popup' => [
        'parameters' => [
            'srctbl' => 'host_groups',
            'srcfld1' => 'groupid',
            'dstfrm' => 'filter-form',
            'dstfld1' => 'groupids_',
            'with_hosts' => true,
            'enrich_parent_groups' => true
        ]
    ],
    'add_post_js' => true
]));

// Adiciona campos ao filtro
$filter_column = new CFormList();
$filter_column->addRow(
    new CLabel(_('Host group')),
    $groupCombobox
);

// Adiciona a coluna ao filtro
$filter->addFilterTab('filter', [$filter_column]);

// Container para o mapa
$mapDiv = (new CDiv())
    ->setId('map')
    ->addClass('map-container');

// Adiciona elementos à página
$page->addItem([
    $filter,
    $messageBox,
    $mapDiv
]);

// Renderiza a página
$page->show();