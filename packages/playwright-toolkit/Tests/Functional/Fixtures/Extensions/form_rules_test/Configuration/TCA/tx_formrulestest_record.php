<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

$isVersion11 = (new Typo3Version())->getMajorVersion() < 12;
$required = $isVersion11 ? ['eval' => 'required'] : ['required' => true];
$flexRequired = $isVersion11 ? '<eval>required</eval>' : '<required>1</required>';
$structure = '<T3DataStructure><sheets><sDEF><ROOT><type>array</type><el>'
    . '<limit><label>Limit</label><config><type>input</type>' . $flexRequired . '</config></limit>'
    . '</el></ROOT></sDEF>'
    . '<sMore><ROOT><type>array</type><el>'
    . '<color><label>Color</label><config><type>input</type></config></color>'
    . '</el></ROOT></sMore>'
    . '<sBonus><ROOT><type>array</type><displayCond>FIELD:parentRec.amount:>:8</displayCond><el>'
    . '<reward><label>Reward</label><config><type>input</type>' . $flexRequired . '</config></reward>'
    . '</el></ROOT></sBonus>'
    . '<sItems><ROOT><type>array</type><el>'
    . '<items><type>array</type><section>1</section><el>'
    . '<item><type>array</type><el>'
    . '<name><label>Name</label><config><type>input</type>' . $flexRequired . '</config></name>'
    . '</el></item>'
    . '</el></items>'
    . '</el></ROOT></sItems></sheets></T3DataStructure>';
$items = static fn(array $values): array => array_map(
    static fn(string $value): array => $isVersion11 ? [$value, $value] : ['label' => $value, 'value' => $value],
    $values
);

return [
    'ctrl' => [
        'title' => 'Form rules test record',
        'label' => 'title',
        'type' => 'kind',
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'translationSource' => 'l10n_source',
        'sortby' => 'sorting',
        'origUid' => 't3_origuid',
        'versioningWS' => true,
    ],
    'columns' => [
        'sys_language_uid' => [
            'label' => 'Language',
            'config' => $isVersion11
                ? ['type' => 'select', 'renderType' => 'selectSingle', 'special' => 'languages', 'items' => [['all', -1]], 'default' => 0]
                : ['type' => 'language'],
        ],
        'l10n_parent' => [
            'label' => 'Original',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => $items(['0']),
                'foreign_table' => 'tx_formrulestest_record',
                'default' => 0,
            ],
        ],
        'l10n_diffsource' => [
            'config' => ['type' => 'passthrough'],
        ],
        'note' => [
            'label' => 'Note',
            'l10n_mode' => 'exclude',
            'config' => ['type' => 'input', ...$required],
        ],
        'shared' => [
            'label' => 'Shared',
            'l10n_display' => 'defaultAsReadonly',
            'config' => ['type' => 'input', ...$required],
        ],
        'title' => [
            'label' => 'Title',
            'config' => ['type' => 'input', ...$required],
        ],
        'kind' => [
            'label' => 'Kind',
            'config' => ['type' => 'select', 'renderType' => 'selectSingle', 'items' => $items(['plain', 'extra', 'locked', 'localized', 'flexible']), 'default' => 'plain'],
        ],
        'tags' => [
            'label' => 'Tags',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'items' => $items(['a', 'b', 'c']),
                'minitems' => 1,
                'maxitems' => 2,
                'default' => 'a',
            ],
        ],
        'amount' => [
            'label' => 'Amount',
            'config' => [
                ...($isVersion11 ? ['type' => 'input', 'eval' => 'int'] : ['type' => 'number']),
                'range' => ['lower' => 1, 'upper' => 10],
                'default' => 5,
            ],
        ],
        'children' => [
            'label' => 'Children',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_formrulestest_child',
                'foreign_field' => 'parentid',
                'foreign_table_field' => 'parenttable',
                'foreign_sortby' => 'sorting_foreign',
                'foreign_match_fields' => ['fieldname' => 'children'],
                'overrideChildTca' => [
                    'columns' => [
                        'label' => ['config' => $required],
                    ],
                ],
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'config' => [
                'type' => 'flex',
                'ds' => (new Typo3Version())->getMajorVersion() >= 14 ? $structure : ['default' => $structure],
            ],
        ],
        'bonus' => [
            'label' => 'Bonus',
            'displayCond' => 'FIELD:amount:>:8',
            'config' => ['type' => 'input', ...$required],
        ],
        'stock' => [
            'label' => 'Stock',
            'config' => [
                ...($isVersion11 ? ['type' => 'input', 'eval' => 'int'] : ['type' => 'number']),
                'range' => ['lower' => 0],
                'default' => 0,
            ],
        ],
        'code' => [
            'label' => 'Code',
            'config' => ['type' => 'input', 'min' => 3],
        ],
        'fixed' => [
            'label' => 'Fixed',
            'config' => ['type' => 'input', 'readOnly' => true, ...$required],
        ],
        'secret' => [
            'label' => 'Secret',
            'exclude' => true,
            'config' => ['type' => 'input', ...$required],
        ],
        'subtitle' => [
            'label' => 'Subtitle',
            'config' => ['type' => 'input', ...$required],
        ],
    ],
    'types' => [
        'plain' => ['showitem' => 'kind, title, tags, amount, stock, bonus, code, children'],
        'extra' => ['showitem' => 'kind, title, subtitle, fixed'],
        'locked' => ['showitem' => 'kind, title, secret'],
        'localized' => ['showitem' => 'kind, title, sys_language_uid, l10n_parent, note, shared'],
        'flexible' => ['showitem' => 'kind, title, settings'],
    ],
];
