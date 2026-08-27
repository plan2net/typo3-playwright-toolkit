<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database\Driver;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\Driver\Engine;
use Plan2net\PlaywrightToolkit\Database\Driver\SqliteTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TemplateSeed;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SqliteTestDatabaseDriverTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/pw-sqlite-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        // Some tests put a directory where a database file belongs, to make a
        // deletion fail on purpose.
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    #[Test]
    public function reportsItsEngine(): void
    {
        self::assertSame(Engine::Sqlite, $this->driver()->engine());
    }

    #[Test]
    public function anEmptyTestIdYieldsNoOverrides(): void
    {
        self::assertSame([], $this->driver()->connectionOverrides(''));
    }

    #[Test]
    public function overridesPointAtAFileInsideTheDirectory(): void
    {
        $overrides = $this->driver()->connectionOverrides(self::TEST_ID);

        self::assertSame('pdo_sqlite', $overrides['DB/Connections/Default/driver']);
        self::assertSame($this->directory . '/db' . self::TEST_ID . '.sqlite', $overrides['DB/Connections/Default/path']);
    }

    #[Test]
    public function theTemplateHasNoFingerprintBeforeItIsFinalised(): void
    {
        $driver = $this->driver();
        $driver->createEmptyTemplate();
        $driver->seedTemplate($this->seed());

        self::assertNull($driver->templateFingerprint());
    }

    #[Test]
    public function finalisingRecordsTheFingerprint(): void
    {
        self::assertSame('abc', $this->prepareTemplate(fingerprint: 'abc')->templateFingerprint());
    }

    #[Test]
    public function materialisingCopiesTheTemplate(): void
    {
        $driver = $this->prepareTemplate();

        $driver->materialise(self::TEST_ID);

        self::assertFileExists($this->directory . '/db' . self::TEST_ID . '.sqlite');
    }

    #[Test]
    public function aMaterialisedDatabaseCarriesTheFixtures(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);

        $pdo = $this->connectTo('db' . self::TEST_ID . '.sqlite');

        self::assertSame('1', (string) $pdo->query('SELECT count(*) FROM pages')->fetchColumn());
    }

    #[Test]
    public function aMaterialisedDatabaseHasTheSeededSession(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);

        self::assertTrue($driver->hasSeededSession(self::TEST_ID, 'playwright_test_session', 1));
    }

    #[Test]
    public function theSeededSessionIsRejectedAfterTheEncryptionKeyRotates(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'a-rotated-key';

        self::assertFalse($driver->hasSeededSession(self::TEST_ID, 'playwright_test_session', 1));
    }

    #[Test]
    public function theSeededSessionIsRejectedForAnotherUser(): void
    {
        $driver = $this->prepareTemplate(userId: 1);
        $driver->materialise(self::TEST_ID);

        self::assertFalse($driver->hasSeededSession(self::TEST_ID, 'playwright_test_session', 2));
    }

    #[Test]
    public function theSeededSessionCheckIsFalseWhenTheDatabaseHasNoSessionTable(): void
    {
        $driver = $this->driver();
        $driver->createEmptyTemplate();
        $driver->finaliseTemplate('abc');
        $driver->materialise(self::TEST_ID);

        self::assertFalse($driver->hasSeededSession(self::TEST_ID, 'playwright_test_session', 1));
    }

    #[Test]
    public function droppingRemovesTheFile(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);

        $driver->drop(self::TEST_ID);

        self::assertFileDoesNotExist($this->directory . '/db' . self::TEST_ID . '.sqlite');
    }

    #[Test]
    public function recreatingTheTemplateLeavesNoStaleSchemaBehind(): void
    {
        $driver = $this->prepareTemplate();

        $driver->createEmptyTemplate();

        $pdo = $this->connectTo('playwright_db_template.sqlite');
        self::assertNull($pdo->query("SELECT name FROM sqlite_master WHERE name = 'pages'")->fetchColumn() ?: null);
    }

    #[Test]
    public function refusesToRecreateATemplateItCannotRemove(): void
    {
        $this->driver()->createEmptyTemplate();
        unlink($this->directory . '/playwright_db_template.sqlite');
        mkdir($this->directory . '/playwright_db_template.sqlite');

        // PDOException extends RuntimeException, so pin the message too.
        $this->expectExceptionMessageMatches('/template/i');

        $this->driver()->createEmptyTemplate();
    }

    #[Test]
    public function reportsFailureWhenATestDatabaseCannotBeDropped(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);
        unlink($this->directory . '/db' . self::TEST_ID . '.sqlite');
        mkdir($this->directory . '/db' . self::TEST_ID . '.sqlite');

        $this->expectExceptionMessageMatches('/db' . self::TEST_ID . '/');

        $driver->drop(self::TEST_ID);
    }

    #[Test]
    public function droppingSomethingAbsentIsQuiet(): void
    {
        $driver = $this->driver();

        $driver->drop(self::TEST_ID);

        self::addToAssertionCount(1);
    }

    #[Test]
    public function refusesATestIdThatWouldEscapeTheDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->driver()->materialise('../../etc/passwd');
    }

    #[Test]
    public function theHealthCheckFailsBeforeMaterialising(): void
    {
        $driver = $this->prepareTemplate();

        self::assertFalse($driver->checkTestDatabase(self::TEST_ID)['ok']);
    }

    #[Test]
    public function theHealthCheckPassesForAMaterialisedDatabase(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);

        self::assertTrue($driver->checkTestDatabase(self::TEST_ID)['ok']);
    }

    private function driver(): SqliteTestDatabaseDriver
    {
        return new SqliteTestDatabaseDriver($this->directory);
    }

    private function connectTo(string $file): \PDO
    {
        $connection = new \PDO('sqlite:' . $this->directory . '/' . $file);
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $connection;
    }

    private function seed(int $userId = 1): TemplateSeed
    {
        return new TemplateSeed(
            fixtures: ['pages.sql' => 'CREATE TABLE pages (uid INTEGER PRIMARY KEY); INSERT INTO pages VALUES (1);'],
            plainSessionId: 'playwright_test_session',
            sessionUserId: $userId,
        );
    }

    private function prepareTemplate(int $userId = 1, string $fingerprint = 'abc'): SqliteTestDatabaseDriver
    {
        $driver = $this->driver();
        $driver->createEmptyTemplate();
        $driver->seedTemplate($this->seed($userId));
        $driver->finaliseTemplate($fingerprint);

        return $driver;
    }
}
