<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use Plan2net\PlaywrightToolkit\Database\Driver\MysqlTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseService;
use Plan2net\PlaywrightToolkit\Database\SeededSession;
use Plan2net\PlaywrightToolkit\Database\TemplatePreparer;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The sqlite preparer test cannot cover this: it is the whole chain from the
 * connection's driver through the factory to TYPO3's own schema migrator writing
 * into MySQL, and only a real MySQL server exercises it.
 */
final class TemplatePreparerMysqlTest extends FunctionalTestCase
{
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
            TestDatabaseService::HOST_VARIABLE => self::host(),
            TestDatabaseService::USER_VARIABLE => self::user(),
            TestDatabaseService::PASSWORD_VARIABLE => self::password(),
        ] as $variable => $value) {
            $this->originalEnvironment[$variable] = getenv($variable);
            putenv($variable . '=' . $value);
        }

        try {
            $this->connectTo(null);
            $this->serverIsReachable = true;
        } catch (\PDOException $exception) {
            self::markTestSkipped('No MySQL at ' . self::host() . ': ' . $exception->getMessage());
        }

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::ENCRYPTION_KEY;

        $fixturesPath = Environment::getProjectPath() . '/playwright-fixtures-mysql';
        if (!is_dir($fixturesPath)) {
            mkdir($fixturesPath, 0777, true);
        }
        file_put_contents(
            $fixturesPath . '/pages.sql',
            "INSERT INTO pages (uid, pid, title) VALUES (99, 0, 'Fixture root');"
        );

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => 'playwright-fixtures-mysql',
            'fixtureManifest' => 'pages.sql',
            'preseededSessionId' => self::SESSION_ID,
            'sessionUserId' => '1',
        ];

        // What a mysql consumer's Testing context looks like; the factory reads the
        // driver from here to decide which engine to provision.
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = [
            'driver' => 'pdo_mysql',
            'host' => self::host(),
            'port' => 3306,
            'user' => self::user(),
            'password' => self::password(),
            'dbname' => 'db',
            'charset' => 'utf8mb4',
            'defaultTableOptions' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->serverIsReachable) {
            MysqlTestDatabaseDriver::onTestService()->dropTemplate();
        }

        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'] = $this->originalConnections;

        foreach ($this->originalEnvironment as $variable => $value) {
            is_string($value) ? putenv($variable . '=' . $value) : putenv($variable);
        }

        parent::tearDown();
    }

    #[Test]
    public function buildsTheMysqlTemplateThroughTheTypo3Migrator(): void
    {
        $this->get(TemplatePreparer::class)->prepare();

        $tables = $this->templateTables();

        self::assertContains('pages', $tables);
        self::assertContains('be_sessions', $tables);
        self::assertContains('sys_refindex', $tables, 'the migrator should have built the whole schema');
    }

    #[Test]
    public function theMysqlTemplateCarriesTheFixturesAndTheSeededSession(): void
    {
        $this->get(TemplatePreparer::class)->prepare();

        $template = $this->connectTo(MysqlTestDatabaseDriver::TEMPLATE_DATABASE);

        self::assertSame(
            '1',
            (string) $template->query('SELECT count(*) FROM pages WHERE uid = 99')->fetchColumn()
        );

        $statement = $template->prepare('SELECT count(*) FROM be_sessions WHERE ses_id = ?');
        $statement->execute([SeededSession::hashedSessionId(self::SESSION_ID)]);

        self::assertSame('1', (string) $statement->fetchColumn());

        // The session authenticates against nothing without it.
        self::assertSame(
            '1',
            (string) $template->query('SELECT count(*) FROM be_users WHERE uid = 1 AND admin = 1')->fetchColumn()
        );
    }

    #[Test]
    public function theMysqlTemplateEndsUpFingerprinted(): void
    {
        $fingerprint = $this->get(TemplatePreparer::class)->prepare();

        self::assertSame($fingerprint, MysqlTestDatabaseDriver::onTestService()->templateFingerprint());
    }

    #[Test]
    public function aTestDatabaseClonedFromItCarriesTheMigratedSchema(): void
    {
        $this->get(TemplatePreparer::class)->prepare();

        $driver = MysqlTestDatabaseDriver::onTestService();
        $driver->materialise('ABCD1234EFGH5678');

        try {
            $clone = $this->connectTo('dbABCD1234EFGH5678');

            self::assertSame(
                '1',
                (string) $clone->query('SELECT count(*) FROM pages WHERE uid = 99')->fetchColumn()
            );
        } finally {
            $driver->drop('ABCD1234EFGH5678');
        }
    }

    /**
     * @return list<string>
     */
    private function templateTables(): array
    {
        $statement = $this->connectTo(null)->prepare(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ?'
        );
        $statement->execute([MysqlTestDatabaseDriver::TEMPLATE_DATABASE]);

        return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private function connectTo(?string $database): \PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d', self::host(), 3306);
        if (null !== $database) {
            $dsn .= ';dbname=' . $database;
        }

        $connection = new \PDO($dsn, self::user(), self::password());
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $connection;
    }

    private static function host(): string
    {
        return self::environment('PW_TEST_MYSQL_HOST', 'db-test-mysql');
    }

    private static function user(): string
    {
        return self::environment('PW_TEST_MYSQL_USER', 'root');
    }

    private static function password(): string
    {
        return self::environment('PW_TEST_MYSQL_PASSWORD', 'root');
    }

    private static function environment(string $name, string $fallback): string
    {
        $value = getenv($name);

        return is_string($value) && '' !== $value ? $value : $fallback;
    }
}
