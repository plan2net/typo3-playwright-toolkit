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
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;

final class DatabaseInitializer implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ToolkitConfigurationFactory $configurationFactory,
        private readonly SeedSources $seedSources,
        private readonly LockFiles $lockFiles,
        private readonly TestApiSecret $secret,
        private readonly BorrowedConnection $borrowedConnection,
    ) {
    }

    /** @psalm-suppress UnusedParam */
    public function __invoke(BootCompletedEvent $event): void
    {
        if (!Environment::getContext()->isTesting()) {
            return;
        }

        // No test ID means this request is not part of a test run; provisioning here
        // would drop and rebuild whatever database the Default connection points at.
        $testId = TestContext::testId();
        if ('' === $testId) {
            $this->logMalformedTestId();

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

                $this->assertTemplatePrepared($driver, $configuration);

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
            } finally {
                flock($createHandle, LOCK_UN);
                fclose($createHandle);
            }
        } finally {
            flock($templateHandle, LOCK_UN);
            fclose($templateHandle);
        }
    }

    private function logMalformedTestId(): void
    {
        $malformed = TestContext::malformedTestId();
        if (null === $malformed) {
            return;
        }

        $this->logger?->warning(
            'Ignoring a malformed {header} header; this request uses the project database.',
            ['header' => TestContext::TEST_ID_HEADER, 'value' => $malformed]
        );
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
        string $seedMarker
    ): bool {
        return file_exists($seedMarker)
            && $driver->hasSeededSession(
                $testId,
                $configuration->preseededSessionId,
                $configuration->sessionUserId
            );
    }

    private function assertTemplatePrepared(
        TestDatabaseDriver $driver,
        ToolkitConfiguration $configuration
    ): void {
        // The fingerprint is written last, so an absent one also covers a
        // preparation that died partway through. Checked first because working out
        // the expected fingerprint needs a template to read the schema from.
        $stored = $driver->templateFingerprint();
        if (null === $stored) {
            // templateExists() asks the server, so an unreachable service or a wrong
            // password surfaces as itself instead of as "not prepared".
            throw new \RuntimeException(sprintf(
                'The Playwright test database template is %s. Run "ddev playwright-prepare" to build it.',
                $driver->templateExists() ? 'unfinished' : 'missing'
            ));
        }

        if ($stored !== $this->expectedFingerprint($driver, $configuration)) {
            throw new \RuntimeException(
                'The Playwright test database template is out of date. Run "ddev playwright-prepare" to build it.'
            );
        }
    }

    private function expectedFingerprint(
        TestDatabaseDriver $driver,
        ToolkitConfiguration $configuration
    ): string {
        return $this->borrowedConnection->use(
            $driver->schemaConnectionOverrides(),
            fn(): string => $this->seedSources->snapshot($configuration)->fingerprint
        );
    }
}
