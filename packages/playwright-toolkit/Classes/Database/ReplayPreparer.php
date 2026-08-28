<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;
use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use Plan2net\PlaywrightToolkit\Database\Driver\ServerTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;

final class ReplayPreparer
{
    public function __construct(
        private readonly ToolkitConfigurationFactory $configurationFactory,
        private readonly TemplateReadiness $readiness,
        private readonly LockFiles $lockFiles,
    ) {
    }

    /** @return string the database that now holds a fresh clone of the template */
    public function prepare(): string
    {
        /** @var array<string, mixed> $connection */
        $connection = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? [];
        $driver = TestDatabaseDriverFactory::fromConnection($connection);

        if (!$driver instanceof ServerTestDatabaseDriver) {
            throw new \RuntimeException(sprintf(
                'Replay needs a database on the db-test service, but the Default connection uses %s.',
                $driver->engine()->value
            ));
        }

        $configuration = $this->configurationFactory->create();

        // Shared, like every clone: a concurrent playwright:prepare must not
        // replace the template midway through.
        $this->lockFiles->shared(LockFiles::TEMPLATE_LOCK, function () use ($driver, $configuration): void {
            $this->readiness->assertPrepared($driver, $configuration);
            // Cleanup refuses this name, so a clean start has to come from here.
            $driver->materialise(DatabaseName::REPLAY_TEST_ID);
        });

        return DatabaseName::forTestId(DatabaseName::REPLAY_TEST_ID);
    }
}
