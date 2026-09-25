<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

$required = (new Typo3Version())->getMajorVersion() < 12 ? ['eval' => 'required'] : ['required' => true];

return [
    'ctrl' => [
        'title' => 'Form rules test child',
        'label' => 'title',
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => ['type' => 'input'],
        ],
        'label' => [
            'label' => 'Label',
            'config' => ['type' => 'input'],
        ],
        'subitems' => [
            'label' => 'Sub items',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_formrulestest_child',
                'foreign_field' => 'parentchild',
                'overrideChildTca' => [
                    'columns' => [
                        'label' => ['config' => $required],
                    ],
                ],
            ],
        ],
        'parentid' => [
            'config' => ['type' => 'passthrough'],
        ],
        'parentchild' => [
            'config' => ['type' => 'passthrough'],
        ],
        'parenttable' => [
            'config' => ['type' => 'passthrough'],
        ],
        'fieldname' => [
            'config' => ['type' => 'passthrough'],
        ],
        'sorting_foreign' => [
            'config' => ['type' => 'passthrough'],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'title, label, subitems'],
    ],
];
