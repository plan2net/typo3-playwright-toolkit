<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfiguration;
use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;
use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Runs before TYPO3 boots, from the same call that redirects the connection, so
 * no query during boot can hit a missing database. There is no container yet, so
 * the dependencies are created by hand. The check that needs TCA lives in
 * TemplateDriftGuard.
 */
final class DatabaseInitializer
{
    private static bool $clonedThisRequest = false;

    public function __construct(
        private readonly ToolkitConfigurationFactory $configurationFactory,
        private readonly LockFiles $lockFiles,
        private readonly TestApiSecret $secret,
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self(
            new ToolkitConfigurationFactory(new ExtensionConfiguration()),
            LockFiles::inVarPath(),
            TestApiSecret::inVarPath(),
        );
    }

    public static function clonedThisRequest(): bool
    {
        return self::$clonedThisRequest;
    }

    /** @internal only functional tests share a process */
    public static function forgetClone(): void
    {
        self::$clonedThisRequest = false;
    }

    public function provisionCurrentRequest(): void
    {
        if (!Environment::getContext()->isTesting()) {
            return;
        }

        // No test ID means this request is not part of a test run; provisioning here
        // would drop and rebuild whatever database the Default connection points at.
        $testId = TestContext::testId();
        if ('' === $testId) {
            return;
        }

        // A test ID only ever arrives as an HTTP header, so a CLI process has nothing
        // to provision — and playwright:prepare boots TYPO3, which would otherwise
        // trip the "not prepared" guard before it can build anything.
        if (Environment::isCli()) {
            return;
        }

        // A well-formed test ID is public knowledge — it is just sixteen characters
        // in a header. Creating a database must cost more than guessing one.
        if (!$this->secret->matchesCurrentRequest()) {
            return;
        }

        $this->provision(
            TestDatabaseDriverFactory::fromConnection(
                $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? []
            ),
            $testId
        );
    }

    public function provision(TestDatabaseDriver $driver, string $testId): void
    {
        $configuration = $this->configurationFactory->create();
        $databaseName = DatabaseName::forTestId($testId);
        DatabaseName::assertProvisionable($databaseName);

        $this->lockFiles->ensureDirectory();
        $seedMarker = $this->lockFiles->claim($databaseName);

        if ($this->isAlreadySeeded($driver, $testId, $configuration, $seedMarker)) {
            return;
        }

        // Shared, so several workers can clone at once but none of them observes a
        // template that playwright:prepare is midway through replacing.
        $templateHandle = $this->lockFiles->open($this->lockFiles->templateLock());

        try {
            if (!flock($templateHandle, LOCK_SH)) {
                throw new \RuntimeException('Could not acquire the template lock');
            }

            $createHandle = $this->lockFiles->open($this->lockFiles->createLock($databaseName));

            try {
                // Parallel workers can share one test ID; serialize per database.
                if (!flock($createHandle, LOCK_EX)) {
                    throw new \RuntimeException('Could not acquire the database create lock');
                }

                if ($this->isAlreadySeeded($driver, $testId, $configuration, $seedMarker)) {
                    return;
                }

                TemplateReadiness::assertFinalised($driver);

                // Claim the name before the database exists. A crash inside
                // materialise() would otherwise leave a database no claim names,
                // which cleanup can never discover. The claim records ownership;
                // isAlreadySeeded() decides readiness.
                //
                // An unwritable claim must stop us here: materialising anyway
                // creates exactly the undiscoverable database this ordering exists
                // to prevent.
                if (!@touch($seedMarker)) {
                    throw new \RuntimeException(sprintf(
                        'Could not claim the test database name at "%s". Cleanup could not find the database, so it was not created.',
                        $seedMarker
                    ));
                }

                $driver->materialise($testId);
                $driver->isolateProcessedFiles($testId);
                self::$clonedThisRequest = true;
            } finally {
                flock($createHandle, LOCK_UN);
                fclose($createHandle);
            }
        } finally {
            flock($templateHandle, LOCK_UN);
            fclose($templateHandle);
        }
    }

    /**
     * The marker lives on the host and the database in a container volume, so the
     * marker counts only while the seeded session is still there.
     *
     * @phpstan-impure another worker can finish between the two calls, which is
     *                the whole point of re-checking under the lock
     */
    private function isAlreadySeeded(
        TestDatabaseDriver $driver,
        string $testId,
        ToolkitConfiguration $configuration,
        string $seedMarker,
    ): bool {
        return file_exists($seedMarker)
            && $driver->hasSeededSession(
                $testId,
                $configuration->preseededSessionId,
                $configuration->sessionUserId
            );
    }
}
