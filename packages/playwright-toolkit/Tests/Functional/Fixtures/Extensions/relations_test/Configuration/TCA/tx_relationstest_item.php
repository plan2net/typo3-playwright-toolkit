<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

// 11.5 has no type => 'file'; 12 and up migrate the inline spelling to it and
// deprecate saying it the old way, so each version is given its own.
$fileColumn = (new Typo3Version())->getMajorVersion() < 12
    ? [
        'type' => 'inline',
        'foreign_table' => 'sys_file_reference',
        'foreign_field' => 'uid_foreign',
        'foreign_sortby' => 'sorting_foreign',
        'foreign_table_field' => 'tablenames',
        'foreign_match_fields' => ['fieldname' => 'image'],
    ]
    : ['type' => 'file'];

return [
    'ctrl' => [
        'title' => 'Relations test item',
        'label' => 'title',
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => ['type' => 'input'],
        ],
        'image' => [
            'label' => 'Image',
            'config' => $fileColumn,
        ],
        'parentid' => [
            'label' => 'Parent',
            'config' => ['type' => 'passthrough'],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'title, image'],
    ],
];
