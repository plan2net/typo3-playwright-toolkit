<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database\Driver;

use Plan2net\PlaywrightToolkit\Database\Driver\ServerTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TemplateSeed;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What any server-backed driver must do identically. Engine-specific behaviour —
 * how the template is cloned, what survives the copy — belongs in the concrete
 * suites, not behind a hook here.
 */
abstract class ServerTestDatabaseDriverTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    protected const TEST_ID = 'ABCD1234EFGH5678';
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private bool $serverIsReachable = false;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->adminConnection();
            $this->serverIsReachable = true;
        } catch (\PDOException $exception) {
            self::markTestSkipped(sprintf(
                'No %s at %s: %s',
                $this->driver()->engine()->value,
                static::host(),
                $exception->getMessage()
            ));
        }
    }

    protected function tearDown(): void
    {
        // tearDown still runs after a skip, and cleaning up needs the server that
        // was just found to be missing.
        if ($this->serverIsReachable) {
            $driver = $this->driver();
            $driver->drop(self::TEST_ID);
            $driver->dropTemplate();
        }

        parent::tearDown();
    }

    #[Test]
    public function anEmptyTestIdYieldsNoOverrides(): void
    {
        self::assertSame([], $this->driver()->connectionOverrides(''));
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
    public function materialisingClonesTheTemplate(): void
    {
        $driver = $this->prepareTemplate();

        $driver->materialise(self::TEST_ID);

        self::assertTrue($this->databaseExists('db' . self::TEST_ID));
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
    public function droppingRemovesTheDatabase(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);

        $driver->drop(self::TEST_ID);

        self::assertFalse($this->databaseExists('db' . self::TEST_ID));
    }

    #[Test]
    public function droppingSomethingAbsentIsQuiet(): void
    {
        $this->driver()->drop(self::TEST_ID);

        self::addToAssertionCount(1);
    }

    #[Test]
    public function aMaterialisedDatabaseCarriesTheTemplateFingerprint(): void
    {
        $driver = $this->prepareTemplate(fingerprint: 'abc');
        $driver->materialise(self::TEST_ID);

        $fingerprint = $this->connectTo('db' . self::TEST_ID)
            ->query('SELECT fingerprint FROM playwright_seed')
            ->fetchColumn();

        self::assertSame('abc', $fingerprint);
    }

    // The name guard rejects the shape before any statement is built.
    #[Test]
    public function refusesATestIdThatIsNotContractShaped(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->driver()->materialise('DROP DATABASE db');
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

    abstract protected function driver(): ServerTestDatabaseDriver;

    abstract protected function seed(int $userId = 1): TemplateSeed;

    abstract protected static function host(): string;

    abstract protected function adminConnection(): \PDO;

    abstract protected function connectTo(string $database): \PDO;

    abstract protected function databaseExists(string $database): bool;

    protected function prepareTemplate(int $userId = 1, string $fingerprint = 'abc'): ServerTestDatabaseDriver
    {
        $driver = $this->driver();
        $driver->createEmptyTemplate();
        $driver->seedTemplate($this->seed($userId));
        $driver->finaliseTemplate($fingerprint);

        return $driver;
    }

    protected static function environment(string $name, string $fallback): string
    {
        $value = getenv($name);

        return is_string($value) && '' !== $value ? $value : $fallback;
    }
}
