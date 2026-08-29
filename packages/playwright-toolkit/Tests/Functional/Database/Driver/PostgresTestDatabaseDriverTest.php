<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database\Driver;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\Driver\Engine;
use Plan2net\PlaywrightToolkit\Database\Driver\PostgresTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TemplateSeed;

final class PostgresTestDatabaseDriverTest extends ServerTestDatabaseDriverTestCase
{
    // Its own template, so this suite cannot disturb a real run on the same server.
    /**
     * @var string
     */
    protected const TEMPLATE = 'playwright_db_template_phpunit';

    /**
     * @var string
     */
    private const HOST_VARIABLE = 'PW_TEST_POSTGRES_HOST';
    /**
     * @var string
     */
    private const USER_VARIABLE = 'PW_TEST_POSTGRES_USER';
    /**
     * @var string
     */
    private const PASSWORD_VARIABLE = 'PW_TEST_POSTGRES_PASSWORD';

    #[Test]
    public function reportsItsEngine(): void
    {
        self::assertSame(Engine::Postgres, $this->driver()->engine());
    }

    #[Test]
    public function overridesNameTheTestDatabaseOnTheTestServer(): void
    {
        $overrides = $this->driver()->connectionOverrides(self::TEST_ID);

        self::assertSame('pdo_pgsql', $overrides['DB/Connections/Default/driver']);
        self::assertSame('db' . self::TEST_ID, $overrides['DB/Connections/Default/dbname']);
        self::assertSame(self::host(), $overrides['DB/Connections/Default/host']);
        self::assertSame(5432, $overrides['DB/Connections/Default/port']);
    }

    #[Test]
    public function aMaterialisedDatabaseCarriesTheFixtures(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);

        $pdo = $this->connectTo('db' . self::TEST_ID);

        self::assertSame('1', (string) $pdo->query('SELECT count(*) FROM pages')->fetchColumn());
    }

    // A fixture that names its own uids leaves the sequence at the start, so the
    // first row TYPO3 writes would collide with the fixture's uid 1. mysql and
    // sqlite carry on from the highest value by themselves; postgres does not.
    #[Test]
    public function theSequenceCarriesOnAfterTheFixtureUids(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);

        $pdo = $this->connectTo('db' . self::TEST_ID);

        self::assertSame(2, (int) $pdo->query('INSERT INTO pages DEFAULT VALUES RETURNING uid')->fetchColumn());
    }

    // A previous worker's connection is enough to make postgres refuse a drop, and
    // cleanup runs while other workers are still about.
    #[Test]
    public function dropsATestDatabaseThatStillHasAnOpenSession(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);
        $session = $this->connectTo('db' . self::TEST_ID);
        $session->query('SELECT 1');

        $driver->drop(self::TEST_ID);

        self::assertFalse($this->databaseExists('db' . self::TEST_ID));
    }

    // Resolving the schema holds a connection for as long as TCA enrichment takes,
    // and postgres cannot clone a template another session has open — so that
    // connection must go anywhere but the template.
    #[Test]
    public function resolvesTheSchemaSomewhereOtherThanTheTemplate(): void
    {
        $overrides = $this->driver()->schemaConnectionOverrides();

        self::assertSame('postgres', $overrides['DB/Connections/Default/dbname']);
    }

    // Reading the fingerprint opens a connection to the template, and Postgres
    // refuses to clone a database anything is still connected to.
    #[Test]
    public function readingTheFingerprintDoesNotBlockTheNextClone(): void
    {
        $driver = $this->prepareTemplate();
        $driver->templateFingerprint();

        $driver->materialise(self::TEST_ID);

        self::assertTrue($this->databaseExists('db' . self::TEST_ID));
    }

    #[Test]
    public function recreatingTheTemplateLeavesNoStaleSchemaBehind(): void
    {
        $driver = $this->prepareTemplate();

        $driver->createEmptyTemplate();

        $pdo = $this->connectTo(self::TEMPLATE);
        self::assertFalse($pdo->query("SELECT to_regclass('pages') IS NOT NULL")->fetchColumn());
    }

    #[Test]
    public function materialisingOverAnExistingDatabaseReplacesIt(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);
        $this->connectTo('db' . self::TEST_ID)->exec('DROP TABLE pages');

        $driver->materialise(self::TEST_ID);

        $pdo = $this->connectTo('db' . self::TEST_ID);
        self::assertSame('1', (string) $pdo->query('SELECT count(*) FROM pages')->fetchColumn());
    }

    #[\Override]
    protected function driver(): PostgresTestDatabaseDriver
    {
        return new PostgresTestDatabaseDriver(
            host: self::host(),
            port: 5432,
            user: self::user(),
            password: self::password(),
            templateDatabase: self::TEMPLATE,
        );
    }

    #[\Override]
    protected function seed(int $userId = 1): TemplateSeed
    {
        return new TemplateSeed(
            fixtures: [
                'schema.sql' => 'CREATE TABLE pages (uid serial PRIMARY KEY);
                    CREATE TABLE be_sessions (
                        ses_id varchar(190) PRIMARY KEY,
                        ses_iplock varchar(45),
                        ses_userid integer,
                        ses_tstamp integer,
                        ses_data text
                    );
                    CREATE TABLE be_users (
                        uid serial PRIMARY KEY,
                        pid integer NOT NULL DEFAULT 0,
                        username varchar(255) NOT NULL DEFAULT \'\',
                        password varchar(255) NOT NULL DEFAULT \'\',
                        admin smallint NOT NULL DEFAULT 0,
                        disable smallint NOT NULL DEFAULT 0,
                        deleted smallint NOT NULL DEFAULT 0,
                        tstamp integer NOT NULL DEFAULT 0,
                        crdate integer NOT NULL DEFAULT 0
                    );',
                'pages.sql' => 'INSERT INTO pages (uid) VALUES (1);',
            ],
            plainSessionId: 'playwright_test_session',
            sessionUserId: $userId,
        );
    }

    #[\Override]
    protected static function host(): string
    {
        return self::environment(self::HOST_VARIABLE, 'db-test');
    }

    #[\Override]
    protected function adminConnection(): \PDO
    {
        return $this->openConnection('postgres');
    }

    #[\Override]
    protected function connectTo(string $database): \PDO
    {
        return $this->openConnection($database);
    }

    #[\Override]
    protected function databaseExists(string $database): bool
    {
        $statement = $this->adminConnection()->prepare('SELECT count(*) FROM pg_database WHERE datname = ?');
        $statement->execute([$database]);

        return (int) $statement->fetchColumn() > 0;
    }

    private static function user(): string
    {
        return self::environment(self::USER_VARIABLE, 'db');
    }

    private static function password(): string
    {
        return self::environment(self::PASSWORD_VARIABLE, 'db');
    }

    private function openConnection(string $database): \PDO
    {
        $pdo = new \PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', self::host(), 5432, $database),
            self::user(),
            self::password()
        );
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
