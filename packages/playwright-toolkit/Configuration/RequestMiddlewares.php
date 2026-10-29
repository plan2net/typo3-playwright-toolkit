<?php

declare(strict_types=1);

use Plan2net\PlaywrightToolkit\Http\DatabaseCleanupProvider;
use Plan2net\PlaywrightToolkit\Http\HealthCheckProvider;
use Plan2net\PlaywrightToolkit\Http\InspectProvider;
use Plan2net\PlaywrightToolkit\Http\RecordedErrorProvider;
use Plan2net\PlaywrightToolkit\Http\RecordEditDiagnostics;
use Plan2net\PlaywrightToolkit\Http\RecordEditRefusal;
use Plan2net\PlaywrightToolkit\Http\SetupCacheProvider;
use Plan2net\PlaywrightToolkit\Http\SiteProbe;
use Plan2net\PlaywrightToolkit\Http\SiteSettingsProvider;
use Plan2net\PlaywrightToolkit\Http\TranslatedRecordReport;
use Plan2net\PlaywrightToolkit\Session\BackendSessionProvider;
use Plan2net\PlaywrightToolkit\Site\SiteSettingsOverlay;

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
        'plan2net/playwright-toolkit/test-setup-cache' => [
            'target' => SetupCacheProvider::class,
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
        'plan2net/playwright-toolkit/test-site-settings' => [
            'target' => SiteSettingsProvider::class,
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
        'plan2net/playwright-toolkit/translated-record' => [
            'target' => TranslatedRecordReport::class,
            'before' => [
                'typo3/cms-backend/backend-routing',
            ],
        ],
        'plan2net/playwright-toolkit/record-edit-refusal' => [
            'target' => RecordEditRefusal::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
            'before' => [
                'typo3/cms-backend/site-resolver',
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
        'plan2net/playwright-toolkit/site-probe' => [
            'target' => SiteProbe::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/base-redirect-resolver',
            ],
        ],
        'plan2net/playwright-toolkit/site-settings' => [
            'target' => SiteSettingsOverlay::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/base-redirect-resolver',
            ],
        ],
    ],
];
