<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use Plan2net\PlaywrightToolkit\Database\DatabaseInitializer;
use Plan2net\PlaywrightToolkit\Database\Driver\SqliteTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\TemplatePreparer;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DatabaseInitializerContentionTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';
    /**
     * @var string
     */
    private const ENCRYPTION_KEY = 'the-encryption-key';
    /**
     * @var int
     */
    private const HOLD_MICROSECONDS = 600000;
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private string $scratchDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::ENCRYPTION_KEY;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => '',
            'fixtureManifest' => '',
            'preseededSessionId' => 'playwright_test_session',
            'sessionUserId' => '1',
        ];

        $this->scratchDirectory = sys_get_temp_dir() . '/pw-contention-' . uniqid('', true);
        mkdir($this->scratchDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratchDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->scratchDirectory);

        foreach (glob($this->databaseDirectory() . '/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob(Environment::getVarPath() . '/test-locks/*') ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    /**
     * A clone that starts while a preparation holds the exclusive lock has to wait
     * for it and then read the finished template. Without the shared lock it would
     * read the fingerprint mid-write and demand a preparation that is in progress.
     */
    #[Test]
    public function aCloneStartingDuringPreparationWaitsAndSeesTheFinishedTemplate(): void
    {
        $fingerprint = $this->get(TemplatePreparer::class)->prepare()['fingerprint'];
        $holder = $this->startLockHolder($fingerprint);

        $this->waitForTheHolderToOwnTheLock();
        $startedAt = microtime(true);

        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);

        $waited = microtime(true) - $startedAt;
        proc_close($holder);

        self::assertFileExists($this->databaseDirectory() . '/db' . self::TEST_ID . '.sqlite');
        self::assertGreaterThan(
            0.25,
            $waited,
            'provisioning did not wait for the exclusive template lock'
        );
    }

    /**
     * @return resource
     */
    private function startLockHolder(string $finalFingerprint)
    {
        $script = $this->scratchDirectory . '/hold-the-lock.php';
        file_put_contents($script, $this->lockHolderSource($finalFingerprint));

        $process = proc_open(['php', $script], [], $pipes);
        if (!is_resource($process)) {
            self::fail('Could not start the lock holder process.');
        }

        return $process;
    }

    private function lockHolderSource(string $finalFingerprint): string
    {
        $arguments = [
            'autoload' => __DIR__ . '/../../../vendor/autoload.php',
            'lockDirectory' => LockFiles::inVarPath()->directory(),
            'templateLock' => LockFiles::TEMPLATE_LOCK,
            'template' => $this->databaseDirectory() . '/playwright_db_template.sqlite',
            'signal' => $this->signalFile(),
            'fingerprint' => $finalFingerprint,
            'hold' => self::HOLD_MICROSECONDS,
        ];

        return '<?php ' . sprintf(
            '$a = %s;',
            var_export($arguments, true)
        ) . <<<'PHP'
            require $a['autoload'];
            // The same lock, or this would block nothing.
            $factory = new Symfony\Component\Lock\LockFactory(
                new Symfony\Component\Lock\Store\FlockStore($a['lockDirectory'])
            );
            $lock = $factory->createLock($a['templateLock'], null);
            $lock->acquire(true);
            touch($a['signal']);

            $write = static function (string $fingerprint) use ($a): void {
                $pdo = new PDO('sqlite:' . $a['template']);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec('DELETE FROM playwright_seed');
                $statement = $pdo->prepare('INSERT INTO playwright_seed (fingerprint) VALUES (?)');
                $statement->execute([$fingerprint]);
            };

            // Stand in for a preparation in flight: the fingerprint is wrong until
            // the very end, so a reader that ignores the lock sees a broken template.
            $write('mid-preparation');
            usleep($a['hold']);
            $write($a['fingerprint']);

            $lock->release();
            PHP;
    }

    private function waitForTheHolderToOwnTheLock(): void
    {
        $deadline = microtime(true) + 10.0;
        while (!file_exists($this->signalFile())) {
            if (microtime(true) > $deadline) {
                self::fail('The lock holder process never acquired the lock.');
            }
            usleep(5000);
        }
    }

    private function signalFile(): string
    {
        return $this->scratchDirectory . '/lock-acquired';
    }

    private function driver(): SqliteTestDatabaseDriver
    {
        return new SqliteTestDatabaseDriver($this->databaseDirectory());
    }

    private function databaseDirectory(): string
    {
        return Environment::getVarPath() . '/test-databases';
    }
}
