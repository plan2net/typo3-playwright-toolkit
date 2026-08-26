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

        $database = (string) ($connection['dbname'] ?? '');
        $configuration = $this->configurationFactory->create();

        // Shared, the same way cloning a test database takes it: a concurrent
        // playwright:prepare must not replace the template midway through.
        $handle = $this->lockFiles->open($this->lockFiles->templateLock());

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException('Could not acquire the template lock');
            }

            $this->readiness->assertPrepared($driver, $configuration);
            $driver->replaceBaseDatabase($database);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $database;
    }
}
