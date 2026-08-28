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

final class DatabaseInitializerProvisionTest extends FunctionalTestCase
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
     * @var string
     */
    private const SESSION_ID = 'playwright_test_session';
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::ENCRYPTION_KEY;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => '',
            'fixtureManifest' => '',
            'preseededSessionId' => self::SESSION_ID,
            'sessionUserId' => '1',
        ];
    }

    protected function tearDown(): void
    {
        // A test puts a directory where a database file belongs, to fail a copy.
        foreach (glob($this->databaseDirectory() . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        // A test puts a directory where the claim file belongs, to fail a touch.
        foreach (glob(Environment::getVarPath() . '/test-locks/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }

        parent::tearDown();
    }

    #[Test]
    public function clonesThePreparedTemplateIntoTheTestDatabase(): void
    {
        $this->prepareTemplate();

        $this->initializer()->provision($this->driver(), self::TEST_ID);

        self::assertFileExists($this->databaseDirectory() . '/db' . self::TEST_ID . '.sqlite');
    }

    #[Test]
    public function givesTheTestDatabaseItsOwnProcessedFileFolder(): void
    {
        $this->prepareTemplate();
        $this->seedFileStorageInTemplate();

        $this->initializer()->provision($this->driver(), self::TEST_ID);

        self::assertSame(
            '_processed_' . self::TEST_ID,
            $this->testDatabase()->query('SELECT processingfolder FROM sys_file_storage WHERE uid = 1')->fetchColumn()
        );
    }

    #[Test]
    public function writesTheClaimCleanupLooksFor(): void
    {
        $this->prepareTemplate();

        $this->initializer()->provision($this->driver(), self::TEST_ID);

        self::assertFileExists($this->claimFile());
    }

    /**
     * The claim is an ownership record, not proof of contents: it has to exist
     * before the database does, or a crash mid-materialise leaves a database that
     * no claim names and cleanup can never discover.
     */
    #[Test]
    public function claimsTheNameBeforeCreatingTheDatabase(): void
    {
        $this->prepareTemplate();
        // A directory where the database file belongs makes the copy fail.
        mkdir($this->databaseDirectory() . '/db' . self::TEST_ID . '.sqlite');

        try {
            $this->initializer()->provision($this->driver(), self::TEST_ID);
            self::fail('Expected materialise to fail.');
        } catch (\RuntimeException) {
            // the failure is the point
        }

        self::assertFileExists($this->claimFile(), 'a failed provision left no claim behind');
    }

    /**
     * If the claim cannot be written, materialising anyway creates exactly the
     * undiscoverable database the claim-first ordering exists to prevent.
     */
    #[Test]
    public function createsNoDatabaseWhenTheClaimCannotBeWritten(): void
    {
        $this->prepareTemplate();

        // touch() succeeds on a directory, so the only real failure is an
        // unwritable parent. Both locks are taken once first, so their files exist
        // — creating those comes first and would otherwise be what fails.
        $locks = LockFiles::inVarPath();
        $locks->shared(LockFiles::TEMPLATE_LOCK, static fn(): mixed => null);
        $locks->exclusively($locks->databaseLock('db' . self::TEST_ID), static fn(): mixed => null);
        chmod($locks->directory(), 0500);

        try {
            $this->initializer()->provision($this->driver(), self::TEST_ID);
            self::fail('Expected the unwritable claim to abort provisioning.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('claim', $exception->getMessage());
        } finally {
            chmod($locks->directory(), 0700);
        }

        self::assertFileDoesNotExist(
            $this->databaseDirectory() . '/db' . self::TEST_ID . '.sqlite',
            'created a database that no claim names'
        );
    }

    #[Test]
    public function leavesAnAlreadySeededDatabaseAlone(): void
    {
        $this->prepareTemplate();
        $initializer = $this->initializer();
        $initializer->provision($this->driver(), self::TEST_ID);
        $this->markTheTestDatabase();

        $initializer->provision($this->driver(), self::TEST_ID);

        self::assertTrue($this->testDatabaseIsMarked(), 'the database was cloned again');
    }

    #[Test]
    public function reclonesWhenTheMarkerSurvivedButTheSeededSessionDidNot(): void
    {
        $this->prepareTemplate();
        $initializer = $this->initializer();
        $initializer->provision($this->driver(), self::TEST_ID);
        $this->markTheTestDatabase();
        $this->testDatabase()->exec('DELETE FROM be_sessions');

        $initializer->provision($this->driver(), self::TEST_ID);

        self::assertFalse($this->testDatabaseIsMarked(), 'the database was not cloned again');
    }

    #[Test]
    public function demandsPreparationWhenNoTemplateWasEverBuilt(): void
    {
        $this->expectExceptionMessageMatches('/playwright-prepare/');

        $this->initializer()->provision($this->driver(), self::TEST_ID);
    }

    private function claimFile(): string
    {
        return LockFiles::inVarPath()->claim('db' . self::TEST_ID);
    }

    private function initializer(): DatabaseInitializer
    {
        return $this->get(DatabaseInitializer::class);
    }

    private function prepareTemplate(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
    }

    private function driver(): SqliteTestDatabaseDriver
    {
        return new SqliteTestDatabaseDriver($this->databaseDirectory());
    }

    private function databaseDirectory(): string
    {
        return Environment::getVarPath() . '/test-databases';
    }

    private function seedFileStorageInTemplate(): void
    {
        $template = new \PDO('sqlite:' . $this->databaseDirectory() . '/playwright_db_template.sqlite');
        $template->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $template->exec(
            "INSERT INTO sys_file_storage (uid, pid, name, driver, processingfolder) VALUES (1, 0, 'fileadmin', 'Local', '')"
        );
    }

    private function testDatabase(): \PDO
    {
        $connection = new \PDO('sqlite:' . $this->databaseDirectory() . '/db' . self::TEST_ID . '.sqlite');
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $connection;
    }

    private function markTheTestDatabase(): void
    {
        $this->testDatabase()->exec('CREATE TABLE playwright_sentinel (id int)');
    }

    private function testDatabaseIsMarked(): bool
    {
        $found = $this->testDatabase()
            ->query("SELECT count(*) FROM sqlite_master WHERE name = 'playwright_sentinel'")
            ->fetchColumn();

        return (int) $found > 0;
    }
}
