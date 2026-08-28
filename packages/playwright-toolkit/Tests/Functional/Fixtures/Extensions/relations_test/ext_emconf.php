<?php

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title' => 'Relations test',
    'description' => 'A child table with a FAL column, which core has none of.',
    'category' => 'example',
    'version' => '0.0.0',
    'state' => 'stable',
    'constraints' => [
        'depends' => ['typo3' => '11.5.0-14.99.99'],
    ],
];
