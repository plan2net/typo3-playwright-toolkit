<?php

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title' => 'Form rules test',
    'description' => 'A table whose fields carry the form rules core\'s own tables do not.',
    'category' => 'example',
    'version' => '0.0.0',
    'state' => 'stable',
    'constraints' => [
        'depends' => ['typo3' => '11.5.0-14.99.99'],
    ],
];
