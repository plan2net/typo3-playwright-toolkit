<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use Plan2net\PlaywrightToolkit\Database\DatabaseInitializer;
use Plan2net\PlaywrightToolkit\Database\Driver\ServerTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseService;
use Plan2net\PlaywrightToolkit\Database\TemplatePreparer;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Provisioning as a real request reaches it: additional.php has already pointed the
 * Default connection at the per-test database, and nothing has created it yet.
 * Sqlite cannot show this — it creates a missing file on connect.
 */
abstract class DatabaseInitializerServerProvisionTestCase extends FunctionalTestCase
{
    /**
     * @var string
     */
    protected const ENCRYPTION_KEY = 'the-encryption-key';
    /**
     * @var string
     */
    protected const SESSION_ID = 'playwright_test_session';
    /**
     * @var string
     */
    protected const TEST_ID = 'SERVERPROV123456';

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    /** @var array<string, mixed> */
    private array $originalConnections = [];

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    private bool $serverIsReachable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnections = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'];

        foreach ([
            TestDatabaseService::HOST_VARIABLE => static::host(),
            TestDatabaseService::USER_VARIABLE => static::user(),
            TestDatabaseService::PASSWORD_VARIABLE => static::password(),
        ] as $variable => $value) {
            $this->originalEnvironment[$variable] = getenv($variable);
            putenv($variable . '=' . $value);
        }

        try {
            $this->driver()->dropTemplate();
            $this->serverIsReachable = true;
        } catch (\PDOException $exception) {
            self::markTestSkipped(sprintf('No server at %s: %s', static::host(), $exception->getMessage()));
        }

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::ENCRYPTION_KEY;

        $fixturesPath = Environment::getProjectPath() . '/' . $this->fixturesDirectory();
        if (!is_dir($fixturesPath)) {
            mkdir($fixturesPath, 0777, true);
        }
        file_put_contents(
            $fixturesPath . '/pages.sql',
            "INSERT INTO pages (uid, pid, title) VALUES (99, 0, 'Fixture root');"
        );

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => $this->fixturesDirectory(),
            'fixtureManifest' => 'pages.sql',
            'preseededSessionId' => self::SESSION_ID,
            'sessionUserId' => '1',
        ];

        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = $this->defaultConnection();
    }

    protected function tearDown(): void
    {
        if ($this->serverIsReachable) {
            $this->driver()->drop(self::TEST_ID);
            $this->driver()->dropTemplate();
        }

        // The claim is an ownership record the extension never deletes, so the next
        // run would take this database for one it had already seeded.
        @unlink(LockFiles::inVarPath()->claim('db' . self::TEST_ID));

        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'] = $this->originalConnections;
        $this->get(ConnectionPool::class)->resetConnections();

        foreach ($this->originalEnvironment as $variable => $value) {
            is_string($value) ? putenv($variable . '=' . $value) : putenv($variable);
        }

        parent::tearDown();
    }

    #[Test]
    public function provisionsWhileTheDefaultConnectionNamesTheDatabaseItIsAboutToCreate(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $this->applyTestConnectionOverrides();

        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);

        self::assertTrue($this->driver()->exists(self::TEST_ID));
    }

    #[Test]
    public function theProvisionedDatabaseCarriesTheSeededSession(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $this->applyTestConnectionOverrides();

        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);

        self::assertTrue(
            $this->driver()->hasSeededSession(self::TEST_ID, self::SESSION_ID, 1)
        );
    }

    // The actionable message is the whole value of the guard, so it has to survive
    // a Default connection that cannot be opened.
    #[Test]
    public function demandsPreparationInsteadOfFailingOnTheUnopenableConnection(): void
    {
        $this->driver()->dropTemplate();
        $this->applyTestConnectionOverrides();

        $this->expectExceptionMessageMatches('/playwright-prepare/');

        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);
    }

    // "Run ddev playwright-prepare" sends a developer to rebuild a template that
    // was never the problem, and the real cause never reaches them.
    #[Test]
    public function saysTheServerIsUnreachableRatherThanBlamingTheTemplate(): void
    {
        $this->applyTestConnectionOverrides();
        putenv(TestDatabaseService::PASSWORD_VARIABLE . '=not-the-password');

        try {
            $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);
            self::fail('Expected provisioning to fail with an unusable connection.');
        } catch (\Throwable $failure) {
            self::assertStringNotContainsString('playwright-prepare', $failure->getMessage());
        } finally {
            // Restored here: tearDown drops this run's databases and needs to connect.
            putenv(TestDatabaseService::PASSWORD_VARIABLE . '=' . static::password());
        }
    }

    abstract protected function driver(): ServerTestDatabaseDriver;

    /**
     * What this engine's consumer has in its Testing settings; the factory reads
     * the driver from here to decide what to provision.
     *
     * @return array<string, mixed>
     */
    abstract protected function defaultConnection(): array;

    /** Per engine, so two suites in one test run cannot share a fixture file. */
    abstract protected function fixturesDirectory(): string;

    abstract protected static function host(): string;

    abstract protected static function user(): string;

    abstract protected static function password(): string;

    protected static function environment(string $name, string $fallback): string
    {
        $value = getenv($name);

        return is_string($value) && '' !== $value ? $value : $fallback;
    }

    /** Exactly what the consumer's additional.php does, and just as early. */
    protected function applyTestConnectionOverrides(): void
    {
        foreach ($this->driver()->connectionOverrides(self::TEST_ID) as $path => $value) {
            $GLOBALS['TYPO3_CONF_VARS'] = ArrayUtility::setValueByPath($GLOBALS['TYPO3_CONF_VARS'], $path, $value);
        }
        $this->get(ConnectionPool::class)->resetConnections();
    }
}
