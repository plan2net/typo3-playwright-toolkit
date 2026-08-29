<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database\Driver;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\Driver\Engine;
use Plan2net\PlaywrightToolkit\Database\Driver\MysqlTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TemplateSeed;

final class MysqlTestDatabaseDriverTest extends ServerTestDatabaseDriverTestCase
{
    // Its own template, so this suite cannot disturb a real run on the same server.
    /**
     * @var string
     */
    protected const TEMPLATE = 'playwright_tmpl_phpunit';

    /**
     * @var string
     */
    private const HOST_VARIABLE = 'PW_TEST_MYSQL_HOST';
    /**
     * @var string
     */
    private const USER_VARIABLE = 'PW_TEST_MYSQL_USER';
    /**
     * @var string
     */
    private const PASSWORD_VARIABLE = 'PW_TEST_MYSQL_PASSWORD';

    #[Test]
    public function reportsItsEngine(): void
    {
        self::assertSame(Engine::Mysql, $this->driver()->engine());
    }

    #[Test]
    public function overridesNameTheTestDatabaseOnTheTestServer(): void
    {
        $overrides = $this->driver()->connectionOverrides(self::TEST_ID);

        self::assertSame('mysqli', $overrides['DB/Connections/Default/driver']);
        self::assertSame('db' . self::TEST_ID, $overrides['DB/Connections/Default/dbname']);
        self::assertSame(self::host(), $overrides['DB/Connections/Default/host']);
        self::assertSame(3306, $overrides['DB/Connections/Default/port']);
    }

    #[Test]
    public function aMaterialisedDatabaseCarriesTheFixtures(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);

        $pdo = $this->connectTo('db' . self::TEST_ID);

        self::assertSame('1', (string) $pdo->query('SELECT count(*) FROM pages')->fetchColumn());
        self::assertSame('1', (string) $pdo->query('SELECT count(*) FROM tt_content')->fetchColumn());
    }

    /**
     * MySQL's CREATE TABLE ... LIKE drops foreign keys, so a clone has to be
     * compared against the template it came from rather than assumed faithful.
     */
    #[Test]
    public function theCloneCarriesTheSameSchemaIncludingForeignKeys(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);

        foreach (['pages', 'tt_content', 'be_sessions', 'tx_odd_columns'] as $table) {
            self::assertSame(
                $this->createTableStatement(self::TEMPLATE, $table),
                $this->createTableStatement('db' . self::TEST_ID, $table),
                sprintf('table %s differs between template and clone', $table)
            );
        }
    }

    /**
     * Copying table by table takes only BASE TABLEs, so a view in the consumer's
     * schema was simply absent from every test database — and only on MySQL, since
     * postgres and sqlite copy the whole database in one step.
     */
    #[Test]
    public function theCloneCarriesViewsAsWellAsTables(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);

        $title = $this->connectTo('db' . self::TEST_ID)
            ->query('SELECT title FROM v_pages WHERE uid = 1')
            ->fetchColumn();

        self::assertSame('Root', $title);
    }

    #[Test]
    public function theCloneKeepsDataInAnInvisibleColumn(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);

        $secret = $this->connectTo('db' . self::TEST_ID)
            ->query('SELECT secret FROM tx_odd_columns WHERE uid = 1')
            ->fetchColumn();

        self::assertSame('classified', $secret);
    }

    #[Test]
    public function theCloneRecomputesGeneratedColumnsInsteadOfRejectingThem(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);

        $row = $this->connectTo('db' . self::TEST_ID)
            ->query('SELECT tax, total FROM tx_odd_columns WHERE uid = 1')
            ->fetch(\PDO::FETCH_ASSOC);

        self::assertSame('20.00', $row['tax']);
        self::assertSame('120.00', $row['total']);
    }

    #[Test]
    public function theCloneReallyHasAForeignKeyToCompare(): void
    {
        $this->prepareTemplate();

        self::assertStringContainsString(
            'FOREIGN KEY',
            $this->createTableStatement(self::TEMPLATE, 'tt_content')
        );
    }

    #[Test]
    public function recreatingTheTemplateLeavesNoStaleSchemaBehind(): void
    {
        $driver = $this->prepareTemplate();

        $driver->createEmptyTemplate();

        self::assertSame([], $this->tablesIn(self::TEMPLATE));
    }

    #[Test]
    public function materialisingOverAnExistingDatabaseReplacesIt(): void
    {
        $driver = $this->prepareTemplate();
        $driver->materialise(self::TEST_ID);
        $this->connectTo('db' . self::TEST_ID)->exec('DROP TABLE tt_content');

        $driver->materialise(self::TEST_ID);

        self::assertContains('tt_content', $this->tablesIn('db' . self::TEST_ID));
    }

    #[\Override]
    protected function driver(): MysqlTestDatabaseDriver
    {
        return new MysqlTestDatabaseDriver(
            host: self::host(),
            port: 3306,
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
                'schema.sql' => 'CREATE TABLE pages (
                        uid int(11) NOT NULL AUTO_INCREMENT,
                        title varchar(255) NOT NULL DEFAULT \'\',
                        PRIMARY KEY (uid)
                    ) ENGINE=InnoDB;',
                'schema-content.sql' => 'CREATE TABLE tt_content (
                        uid int(11) NOT NULL AUTO_INCREMENT,
                        pid int(11) NOT NULL,
                        header varchar(255) NOT NULL DEFAULT \'\',
                        PRIMARY KEY (uid),
                        KEY header_index (header),
                        CONSTRAINT fk_content_page FOREIGN KEY (pid) REFERENCES pages (uid)
                    ) ENGINE=InnoDB;',
                'schema-sessions.sql' => 'CREATE TABLE be_sessions (
                        ses_id varchar(190) NOT NULL,
                        ses_iplock varchar(45) NOT NULL DEFAULT \'\',
                        ses_userid int(11) NOT NULL DEFAULT 0,
                        ses_tstamp int(11) NOT NULL DEFAULT 0,
                        ses_data longblob,
                        PRIMARY KEY (ses_id)
                    ) ENGINE=InnoDB;',
                'schema-users.sql' => 'CREATE TABLE be_users (
                        uid int(11) NOT NULL AUTO_INCREMENT,
                        pid int(11) NOT NULL DEFAULT 0,
                        username varchar(255) NOT NULL DEFAULT \'\',
                        password varchar(255) NOT NULL DEFAULT \'\',
                        admin tinyint(4) NOT NULL DEFAULT 0,
                        disable tinyint(4) NOT NULL DEFAULT 0,
                        deleted tinyint(4) NOT NULL DEFAULT 0,
                        tstamp int(11) NOT NULL DEFAULT 0,
                        crdate int(11) NOT NULL DEFAULT 0,
                        PRIMARY KEY (uid)
                    ) ENGINE=InnoDB;',
                'schema-odd-columns.sql' => 'CREATE TABLE tx_odd_columns (
                        uid int(11) NOT NULL AUTO_INCREMENT,
                        price decimal(10,2) NOT NULL DEFAULT 0,
                        tax decimal(10,2) AS (price * 0.2) VIRTUAL,
                        total decimal(10,2) GENERATED ALWAYS AS (price * 1.2) STORED,
                        secret varchar(50) INVISIBLE DEFAULT \'\',
                        PRIMARY KEY (uid)
                    ) ENGINE=InnoDB;',
                'schema-view.sql' => 'CREATE VIEW v_pages AS SELECT uid, title FROM pages;',
                'pages.sql' => 'INSERT INTO pages (uid, title) VALUES (1, \'Root\');',
                'content.sql' => 'INSERT INTO tt_content (uid, pid, header) VALUES (1, 1, \'Hello\');',
                'odd-columns.sql' => 'INSERT INTO tx_odd_columns (uid, price, secret) VALUES (1, 100, \'classified\');',
            ],
            plainSessionId: 'playwright_test_session',
            sessionUserId: $userId,
        );
    }

    #[\Override]
    protected static function host(): string
    {
        return self::environment(self::HOST_VARIABLE, 'db-test-mysql');
    }

    #[\Override]
    protected function adminConnection(): \PDO
    {
        return $this->openConnection(null);
    }

    #[\Override]
    protected function connectTo(string $database): \PDO
    {
        return $this->openConnection($database);
    }

    #[\Override]
    protected function databaseExists(string $database): bool
    {
        $statement = $this->adminConnection()->prepare(
            'SELECT count(*) FROM information_schema.schemata WHERE schema_name = ?'
        );
        $statement->execute([$database]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @return list<string>
     */
    private function tablesIn(string $database): array
    {
        $statement = $this->adminConnection()->prepare(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name'
        );
        $statement->execute([$database]);

        return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * The database name is stripped so two databases holding the same schema
     * produce identical text.
     */
    private function createTableStatement(string $database, string $table): string
    {
        $row = $this->connectTo($database)->query('SHOW CREATE TABLE `' . $table . '`')->fetch(\PDO::FETCH_NUM);

        return str_replace($database, '<database>', (string) $row[1]);
    }

    private static function user(): string
    {
        return self::environment(self::USER_VARIABLE, 'root');
    }

    private static function password(): string
    {
        return self::environment(self::PASSWORD_VARIABLE, 'root');
    }

    private function openConnection(?string $database): \PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d', self::host(), 3306);
        if (null !== $database) {
            $dsn .= ';dbname=' . $database;
        }

        $pdo = new \PDO($dsn, self::user(), self::password());
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
