<?php

declare(strict_types=1);

$GLOBALS['TCA']['tt_content']['columns']['tx_relationstest_items'] = [
    'label' => 'Items',
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'tx_relationstest_item',
        'foreign_field' => 'parentid',
    ],
];
