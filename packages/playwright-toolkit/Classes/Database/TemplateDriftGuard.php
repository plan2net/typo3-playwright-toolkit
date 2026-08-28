<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;
use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;

/**
 * The one check that cannot run before boot: computing the expected fingerprint
 * needs TCA. A request that cloned from an outdated template fails here.
 */
final class TemplateDriftGuard implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ToolkitConfigurationFactory $configurationFactory,
        private readonly TemplateReadiness $readiness,
        private readonly LockFiles $lockFiles,
    ) {
    }

    /** @psalm-suppress UnusedParam */
    public function __invoke(BootCompletedEvent $event): void
    {
        if (!Environment::getContext()->isTesting()) {
            return;
        }

        if ('' === TestContext::testId()) {
            $this->logMalformedTestId();

            return;
        }

        if (!DatabaseInitializer::clonedThisRequest()) {
            return;
        }

        $driver = TestDatabaseDriverFactory::fromConnection(
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? []
        );

        try {
            $this->readiness->assertPrepared($driver, $this->configurationFactory->create());
        } catch (\RuntimeException $rejection) {
            // Dropped, or the next request would find a seeded database and run
            // on the stale clone.
            $this->discardClone($driver, TestContext::testId());

            throw $rejection;
        }
    }

    private function discardClone(TestDatabaseDriver $driver, string $testId): void
    {
        $databaseName = DatabaseName::forTestId($testId);
        if (!DatabaseName::isDroppable($databaseName)) {
            return;
        }

        try {
            // The lock provisioning takes: a request creating this database in
            // between would lose its claim, and cleanup never finds it.
            $this->lockFiles->exclusively(
                $this->lockFiles->databaseLock($databaseName),
                function () use ($driver, $testId, $databaseName): void {
                    $driver->drop($testId);
                    @unlink($this->lockFiles->claim($databaseName));
                }
            );
        } catch (\Throwable) {
            // Left claimed, so cleanup still finds it, and the caller can rethrow
            // the message that helps.
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
}
