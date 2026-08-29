<?php

declare(strict_types=1);

use Plan2net\PlaywrightToolkit\Http\DatabaseCleanupProvider;
use Plan2net\PlaywrightToolkit\Http\HealthCheckProvider;
use Plan2net\PlaywrightToolkit\Http\InspectProvider;
use Plan2net\PlaywrightToolkit\Http\RecordedErrorProvider;
use Plan2net\PlaywrightToolkit\Http\RecordEditDiagnostics;
use Plan2net\PlaywrightToolkit\Session\BackendSessionProvider;

return [
    'backend' => [
        'plan2net/playwright-toolkit/test-session' => [
            'target' => BackendSessionProvider::class,
            'before' => [
                'typo3/cms-backend/backend-routing',
            ],
        ],
        'plan2net/playwright-toolkit/test-health' => [
            'target' => HealthCheckProvider::class,
            'before' => [
                'typo3/cms-backend/backend-routing',
            ],
        ],
        'plan2net/playwright-toolkit/test-database-cleanup' => [
            'target' => DatabaseCleanupProvider::class,
            'before' => [
                'typo3/cms-backend/backend-routing',
            ],
        ],
        'plan2net/playwright-toolkit/test-inspect' => [
            'target' => InspectProvider::class,
            'before' => [
                'typo3/cms-backend/backend-routing',
            ],
        ],
        'plan2net/playwright-toolkit/test-errors' => [
            'target' => RecordedErrorProvider::class,
            'before' => [
                'typo3/cms-backend/backend-routing',
            ],
        ],
        'plan2net/playwright-toolkit/saved-slug' => [
            'target' => RecordEditDiagnostics::class,
            'before' => [
                'typo3/cms-backend/backend-routing',
            ],
        ],
    ],
    'frontend' => [
        'plan2net/playwright-toolkit/test-health' => [
            'target' => HealthCheckProvider::class,
        ],
        'plan2net/playwright-toolkit/test-database-cleanup' => [
            'target' => DatabaseCleanupProvider::class,
        ],
        'plan2net/playwright-toolkit/test-errors' => [
            'target' => RecordedErrorProvider::class,
        ],
    ],
];
