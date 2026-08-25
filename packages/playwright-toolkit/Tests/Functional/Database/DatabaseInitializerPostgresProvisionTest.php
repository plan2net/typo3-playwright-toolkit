<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use Plan2net\PlaywrightToolkit\Database\DatabaseInitializer;
use Plan2net\PlaywrightToolkit\Database\Driver\PostgresTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\TemplatePreparer;
use PHPUnit\Framework\Attributes\Test;

/**
 * Postgres clones with CREATE DATABASE ... TEMPLATE, which it refuses while any
 * other session holds the template open — so this suite, not the mysql one, is
 * what pins that provisioning leaves no connection behind.
 */
final class DatabaseInitializerPostgresProvisionTest extends DatabaseInitializerServerProvisionTest
{
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

    /**
     * Postgres refuses CREATE DATABASE ... TEMPLATE while any other session holds
     * the template open. Provisioning must therefore leave no session behind, or
     * one worker's schema read breaks another worker's clone.
     */
    #[Test]
    public function leavesNoSessionOnTheTemplate(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        $this->applyTestConnectionOverrides();

        $this->get(DatabaseInitializer::class)->provision($this->driver(), self::TEST_ID);

        self::assertSame(0, $this->sessionsOnTheTemplate());
    }

    #[\Override]
    protected function driver(): PostgresTestDatabaseDriver
    {
        return PostgresTestDatabaseDriver::onTestService();
    }

    #[\Override]
    protected function defaultConnection(): array
    {
        return [
            'driver' => 'pdo_pgsql',
            'host' => static::host(),
            'port' => 5432,
            'user' => static::user(),
            'password' => static::password(),
            'dbname' => 'db',
            'charset' => 'utf8',
        ];
    }

    #[\Override]
    protected function fixturesDirectory(): string
    {
        return 'playwright-fixtures-postgres-provision';
    }

    #[\Override]
    protected static function host(): string
    {
        return self::environment(self::HOST_VARIABLE, 'db-test');
    }

    #[\Override]
    protected static function user(): string
    {
        return self::environment(self::USER_VARIABLE, 'db');
    }

    #[\Override]
    protected static function password(): string
    {
        return self::environment(self::PASSWORD_VARIABLE, 'db');
    }

    private function sessionsOnTheTemplate(): int
    {
        $connection = new \PDO(
            sprintf('pgsql:host=%s;port=5432;dbname=postgres', static::host()),
            static::user(),
            static::password()
        );
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $statement = $connection->prepare('SELECT count(*) FROM pg_stat_activity WHERE datname = ?');
        $statement->execute([PostgresTestDatabaseDriver::TEMPLATE_DATABASE]);

        return (int) $statement->fetchColumn();
    }
}
