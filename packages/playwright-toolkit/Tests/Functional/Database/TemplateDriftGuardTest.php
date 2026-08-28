<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use Plan2net\PlaywrightToolkit\Database\DatabaseInitializer;
use Plan2net\PlaywrightToolkit\Database\Driver\SqliteTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\TemplateDriftGuard;
use Plan2net\PlaywrightToolkit\Database\TemplatePreparer;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TemplateDriftGuardTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'DRIFTGUARD123456';

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private string $scratchDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratchDirectory = sys_get_temp_dir() . '/pw-drift-' . uniqid('', true);
        mkdir($this->scratchDirectory, 0777, true);

        DatabaseInitializer::forgetProvisioning();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'the-encryption-key';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => '',
            'fixtureManifest' => '',
            'preseededSessionId' => 'playwright_test_session',
            'sessionUserId' => '1',
        ];
    }

    protected function tearDown(): void
    {
        DatabaseInitializer::forgetProvisioning();
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        foreach (glob($this->scratchDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->scratchDirectory);

        foreach (glob(Environment::getVarPath() . '/test-databases/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob(Environment::getVarPath() . '/test-locks/*') ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    #[Test]
    public function failsTheRequestThatClonedFromADriftedTemplate(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $this->driver()->finaliseTemplate('a-fingerprint-from-another-life');
        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        $this->expectExceptionMessageMatches('/playwright-prepare/');

        $this->get(TemplateDriftGuard::class)->__invoke(new BootCompletedEvent(true));
    }

    #[Test]
    public function theNextRequestMustNotRunOnTheRejectedClone(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $this->driver()->finaliseTemplate('a-fingerprint-from-another-life');
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);
        try {
            $this->get(TemplateDriftGuard::class)->__invoke(new BootCompletedEvent(true));
            self::fail('Expected the first request to be rejected.');
        } catch (\RuntimeException) {
            // the rejection is the precondition
        }

        DatabaseInitializer::forgetProvisioning();
        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);

        $this->expectExceptionMessageMatches('/playwright-prepare/');

        $this->get(TemplateDriftGuard::class)->__invoke(new BootCompletedEvent(true));
    }

    // A second request of the same test ID finds the database already seeded and
    // clones nothing. It must still refuse to run on it until one request has
    // checked the template.
    #[Test]
    public function aRequestThatClonedNothingStillRefusesAnUncheckedDatabase(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $this->driver()->finaliseTemplate('a-fingerprint-from-another-life');
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);

        DatabaseInitializer::forgetProvisioning();
        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);

        $this->expectExceptionMessageMatches('/playwright-prepare/');

        $this->get(TemplateDriftGuard::class)->__invoke(new BootCompletedEvent(true));
    }

    #[Test]
    public function checksTheTemplateOnlyOncePerDatabase(): void
    {
        $this->expectNotToPerformAssertions();

        $this->get(TemplatePreparer::class)->prepare();
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);
        $this->get(TemplateDriftGuard::class)->__invoke(new BootCompletedEvent(true));

        // Drifting the template afterwards changes nothing: this database was
        // already checked, and a later request must not pay for that check again.
        $this->driver()->finaliseTemplate('a-fingerprint-from-another-life');
        DatabaseInitializer::forgetProvisioning();
        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);

        $this->get(TemplateDriftGuard::class)->__invoke(new BootCompletedEvent(true));
    }

    // A database that lost its seeded session is cloned again. That new clone was
    // never checked, whatever was true of the one it replaced.
    #[Test]
    public function aDatabaseThatWasClonedAgainIsCheckedAgain(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);
        $this->get(TemplateDriftGuard::class)->__invoke(new BootCompletedEvent(true));

        $this->testDatabase()->exec('DELETE FROM be_sessions');
        $this->driver()->finaliseTemplate('a-fingerprint-from-another-life');
        DatabaseInitializer::forgetProvisioning();
        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);

        $this->expectExceptionMessageMatches('/playwright-prepare/');

        $this->get(TemplateDriftGuard::class)->__invoke(new BootCompletedEvent(true));
    }

    // A request provisioning between the drop and the claim removal would end up
    // with a database that has no claim, which cleanup never finds.
    #[Test]
    public function discardingTheCloneWaitsForTheDatabaseCreateLock(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $this->driver()->finaliseTemplate('a-fingerprint-from-another-life');
        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        $holder = $this->startCreateLockHolder();
        $startedAt = microtime(true);

        try {
            $this->get(TemplateDriftGuard::class)->__invoke(new BootCompletedEvent(true));
            self::fail('Expected the drifted template to be rejected.');
        } catch (\RuntimeException) {
            // the rejection is expected; the wait is what this pins
        } finally {
            $waited = microtime(true) - $startedAt;
            proc_close($holder);
        }

        self::assertGreaterThan(0.25, $waited, 'the discard did not wait for the create lock');
    }

    #[Test]
    public function staysSilentWhenThisRequestProvisionedNothing(): void
    {
        $this->expectNotToPerformAssertions();

        $this->get(TemplatePreparer::class)->prepare();
        $this->driver()->finaliseTemplate('a-fingerprint-from-another-life');
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        $this->get(TemplateDriftGuard::class)->__invoke(new BootCompletedEvent(true));
    }

    /**
     * @return resource
     */
    private function startCreateLockHolder()
    {
        $arguments = [
            'autoload' => __DIR__ . '/../../../vendor/autoload.php',
            'directory' => LockFiles::inVarPath()->directory(),
            'key' => LockFiles::inVarPath()->databaseLock('db' . self::TEST_ID),
            'signal' => $this->scratchDirectory . '/create-lock-held',
        ];
        $signal = $arguments['signal'];
        // The same lock, or this would block nothing.
        $source = '<?php ' . sprintf('$a = %s;', var_export($arguments, true)) . <<<'PHP'
            require $a['autoload'];
            $factory = new Symfony\Component\Lock\LockFactory(
                new Symfony\Component\Lock\Store\FlockStore($a['directory'])
            );
            $lock = $factory->createLock($a['key'], null);
            $lock->acquire(true);
            touch($a['signal']);
            usleep(600000);
            $lock->release();
            PHP;

        $script = $this->scratchDirectory . '/hold-create-lock.php';
        file_put_contents($script, $source);

        $process = proc_open(['php', $script], [], $pipes);
        if (!is_resource($process)) {
            self::fail('Could not start the lock holder process.');
        }

        $deadline = microtime(true) + 10.0;
        while (!file_exists($signal)) {
            if (microtime(true) > $deadline) {
                self::fail('The lock holder process never acquired the lock.');
            }
            usleep(5000);
        }

        return $process;
    }

    private function driver(): SqliteTestDatabaseDriver
    {
        return new SqliteTestDatabaseDriver(Environment::getVarPath() . '/test-databases');
    }

    private function testDatabase(): \PDO
    {
        $connection = new \PDO(
            'sqlite:' . Environment::getVarPath() . '/test-databases/db' . self::TEST_ID . '.sqlite'
        );
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $connection;
    }
}
