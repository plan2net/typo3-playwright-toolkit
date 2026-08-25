<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database\Driver;

use Plan2net\PlaywrightToolkit\Database\Driver\Engine;
use Plan2net\PlaywrightToolkit\Database\Driver\MysqlTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\PostgresTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\SqliteTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

final class TestDatabaseDriverFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            '/app',
            '/app/public',
            '/app/var',
            '/app/config',
            '/app/public/index.php',
            'UNIX',
        );
    }
    #[Test]
    public function buildsAPostgresDriverFromThePostgresConnection(): void
    {
        $driver = TestDatabaseDriverFactory::fromConnection(['driver' => 'pdo_pgsql']);

        self::assertInstanceOf(PostgresTestDatabaseDriver::class, $driver);
        self::assertSame(Engine::Postgres, $driver->engine());
    }

    #[Test]
    public function ignoresTheHostTheProjectConfiguredAndUsesTheTestService(): void
    {
        $overrides = TestDatabaseDriverFactory::fromConnection([
            'driver' => 'pdo_pgsql',
            'host' => 'db',
            'port' => 6543,
            'user' => 'someone-else',
            'password' => 'production',
        ])->connectionOverrides('ABCD1234EFGH5678');

        self::assertSame('db-test', $overrides['DB/Connections/Default/host']);
        self::assertSame(5432, $overrides['DB/Connections/Default/port']);
        self::assertSame('db', $overrides['DB/Connections/Default/user']);
    }

    #[Test]
    public function buildsASqliteDriverFromTheSqliteConnection(): void
    {
        $driver = TestDatabaseDriverFactory::fromConnection(['driver' => 'pdo_sqlite']);

        self::assertInstanceOf(SqliteTestDatabaseDriver::class, $driver);
    }

    #[Test]
    public function theSqliteDriverUsesTheDerivedDirectory(): void
    {
        $driver = TestDatabaseDriverFactory::fromConnection(['driver' => 'pdo_sqlite']);

        self::assertSame(
            '/app/var/test-databases/dbABCD1234EFGH5678.sqlite',
            $driver->connectionOverrides('ABCD1234EFGH5678')['DB/Connections/Default/path']
        );
    }

    #[Test]
    #[DataProvider('postgresAliases')]
    public function thePostgresOverridesKeepTheConfiguredAlias(string $alias): void
    {
        $driver = TestDatabaseDriverFactory::fromConnection(['driver' => $alias]);

        self::assertSame(
            $alias,
            $driver->connectionOverrides('ABCD1234EFGH5678')['DB/Connections/Default/driver']
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function postgresAliases(): array
    {
        return [['pdo_pgsql'], ['pgsql']];
    }

    #[Test]
    #[DataProvider('sqliteAliases')]
    public function theSqliteOverridesKeepTheConfiguredAlias(string $alias): void
    {
        $driver = TestDatabaseDriverFactory::fromConnection(['driver' => $alias]);

        self::assertSame(
            $alias,
            $driver->connectionOverrides('ABCD1234EFGH5678')['DB/Connections/Default/driver']
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function sqliteAliases(): array
    {
        return [['pdo_sqlite'], ['sqlite3']];
    }

    #[Test]
    #[DataProvider('mysqlAliases')]
    public function buildsAMysqlDriverThatKeepsTheConfiguredAlias(string $alias): void
    {
        $driver = TestDatabaseDriverFactory::fromConnection(['driver' => $alias]);

        self::assertInstanceOf(MysqlTestDatabaseDriver::class, $driver);
        self::assertSame(
            $alias,
            $driver->connectionOverrides('ABCD1234EFGH5678')['DB/Connections/Default/driver']
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function mysqlAliases(): array
    {
        return [['mysqli'], ['pdo_mysql']];
    }

    #[Test]
    public function theMysqlDriverTargetsTheTestServiceOnItsOwnPort(): void
    {
        $overrides = TestDatabaseDriverFactory::fromConnection(['driver' => 'mysqli'])
            ->connectionOverrides('ABCD1234EFGH5678');

        self::assertSame('db-test', $overrides['DB/Connections/Default/host']);
        self::assertSame(3306, $overrides['DB/Connections/Default/port']);
    }

    #[Test]
    public function refusesAConnectionWithNoDriverAtAll(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TestDatabaseDriverFactory::fromConnection(['host' => 'db-test']);
    }

    #[Test]
    public function refusesADriverItDoesNotKnow(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TestDatabaseDriverFactory::fromConnection(['driver' => 'oci8']);
    }

    #[Test]
    public function answersNullWhenNoDefaultConnectionIsConfigured(): void
    {
        self::assertNull(TestDatabaseDriverFactory::fromConnectionOrNull(null));
    }

    #[Test]
    public function answersNullWhenTheConnectionNamesNoUsableDriver(): void
    {
        self::assertNull(TestDatabaseDriverFactory::fromConnectionOrNull(['driver' => 'oci8']));
        self::assertNull(TestDatabaseDriverFactory::fromConnectionOrNull(['host' => 'db-test']));
    }
}
