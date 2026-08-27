<?php

$EM_CONF['playwright_toolkit'] = [
    'title' => 'Playwright Toolkit',
    'description' => 'Playwright testing toolkit for TYPO3 CMS: a throwaway database per test file, pre-seeded backend sessions, health and cleanup endpoints.',
    'category' => 'misc',
    'author' => 'Wolfgang Klinger',
    'author_email' => 'wk@plan2.net',
    'author_company' => 'plan2net GmbH',
    'state' => 'beta',
    'version' => '0.4.1',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-14.99.99',
            'php' => '8.1.0-8.99.99',
        ],
    ],
];
