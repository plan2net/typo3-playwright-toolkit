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

    /**
     * @param array<string, mixed>|null $defaultConnection the project's own Default connection
     *
     * @return bool whether this request may be pointed at its test database
     */
    public function provisionCurrentRequest(?array $defaultConnection = null): bool
    {
        if (!Environment::getContext()->isTesting()) {
            return false;
        }

        $testId = TestContext::testId();
        if ('' === $testId) {
            return false;
        }

        // playwright:prepare boots TYPO3 too, and it has no database to provision.
        if (Environment::isCli()) {
            return false;
        }

        // A test ID is sixteen public characters, so creating a database must cost
        // more than guessing one. An inspect session sends a cookie and no secret:
        // it may use a database a run left behind, but not create one.
        if (!$this->secret->matchesCurrentRequest()) {
            return file_exists($this->lockFiles->claim(DatabaseName::forTestId($testId)));
        }

        $this->provision(
            TestDatabaseDriverFactory::fromConnection(
                $defaultConnection ?? $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? []
            ),
            $testId
        );

        return true;
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

                $this->refuseUnreadableSession($driver, $testId, $configuration, $seedMarker, $databaseName);

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

    // A session we cannot read means the database was seeded, only not by a key we
    // have. Rebuilding it would drop what the running test has already written.
    private function refuseUnreadableSession(
        TestDatabaseDriver $driver,
        string $testId,
        ToolkitConfiguration $configuration,
        string $seedMarker,
        string $databaseName,
    ): void {
        if (!file_exists($seedMarker) || !$driver->hasSessionForUser($testId, $configuration->sessionUserId)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Test database %s holds a backend session that is not the pre-seeded one, so it was left as it is. '
            . 'The session id is hashed with SYS/encryptionKey, so this usually means the key in place now is not '
            . 'the one "playwright:prepare" ran with — set it before the connection overrides are applied. '
            . 'To rebuild the database instead, delete "%s".',
            $databaseName,
            $seedMarker
        ));
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
