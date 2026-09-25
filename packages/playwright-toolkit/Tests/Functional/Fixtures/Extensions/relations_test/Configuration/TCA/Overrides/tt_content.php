<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

$GLOBALS['TCA']['tt_content']['columns']['tx_relationstest_items'] = [
    'label' => 'Items',
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'tx_relationstest_item',
        'foreign_field' => 'parentid',
    ],
];

ExtensionManagementUtility::addToAllTCAtypes('tt_content', 'tx_relationstest_items', 'text');
