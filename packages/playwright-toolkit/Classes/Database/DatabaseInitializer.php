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
 * no query during boot can hit a missing database. No container exists there, so
 * the dependencies are created by hand.
 */
final class DatabaseInitializer
{
    private static bool $provisionedThisRequest = false;

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

    public static function provisionedThisRequest(): bool
    {
        return self::$provisionedThisRequest;
    }

    /** @internal only functional tests share a process */
    public static function forgetProvisioning(): void
    {
        self::$provisionedThisRequest = false;
    }

    public function provisionCurrentRequest(): void
    {
        if (!Environment::getContext()->isTesting()) {
            return;
        }

        $testId = TestContext::testId();
        if ('' === $testId) {
            return;
        }

        // playwright:prepare boots TYPO3 too, and it has no database to provision.
        if (Environment::isCli()) {
            return;
        }

        // A test ID is sixteen public characters, so creating a database must cost
        // more than guessing one.
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

        // Set for a request that finds the database already there as well, so
        // TemplateDriftGuard checks the template for that one too.
        self::$provisionedThisRequest = true;

        $this->lockFiles->ensureDirectory();
        $seedMarker = $this->lockFiles->claim($databaseName);

        if ($this->isAlreadySeeded($driver, $testId, $configuration, $seedMarker)) {
            return;
        }

        // Shared, so several workers can clone at once but none of them observes a
        // template that playwright:prepare is midway through replacing.
        $this->lockFiles->shared(LockFiles::TEMPLATE_LOCK, function () use ($driver, $testId, $configuration, $seedMarker, $databaseName): void {
            // Parallel workers can share one test ID; serialize per database.
            $this->lockFiles->exclusively($this->lockFiles->databaseLock($databaseName), function () use ($driver, $testId, $configuration, $seedMarker, $databaseName): void {
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

                // The marker describes the database this one replaces, so it must
                // go before the new clone exists.
                @unlink($this->lockFiles->checkedMarker($databaseName));

                $driver->materialise($testId);
                $driver->isolateProcessedFiles($testId);
            });
        });
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
