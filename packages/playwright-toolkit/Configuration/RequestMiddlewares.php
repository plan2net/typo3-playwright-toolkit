<?php

declare(strict_types=1);

use Plan2net\PlaywrightToolkit\Http\DatabaseCleanupProvider;
use Plan2net\PlaywrightToolkit\Http\HealthCheckProvider;
use Plan2net\PlaywrightToolkit\Http\InspectProvider;
use Plan2net\PlaywrightToolkit\Http\SavedRecordHeader;
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
        'plan2net/playwright-toolkit/saved-slug' => [
            'target' => SavedRecordHeader::class,
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
    ],
];
