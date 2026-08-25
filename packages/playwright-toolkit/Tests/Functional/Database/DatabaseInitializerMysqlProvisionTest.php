<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use Plan2net\PlaywrightToolkit\Database\Driver\MysqlTestDatabaseDriver;

final class DatabaseInitializerMysqlProvisionTest extends DatabaseInitializerServerProvisionTest
{
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

    #[\Override]
    protected function driver(): MysqlTestDatabaseDriver
    {
        return MysqlTestDatabaseDriver::onTestService();
    }

    #[\Override]
    protected function defaultConnection(): array
    {
        return [
            'driver' => 'pdo_mysql',
            'host' => static::host(),
            'port' => 3306,
            'user' => static::user(),
            'password' => static::password(),
            'dbname' => 'db',
            'charset' => 'utf8mb4',
            'defaultTableOptions' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
        ];
    }

    #[\Override]
    protected function fixturesDirectory(): string
    {
        return 'playwright-fixtures-mysql-provision';
    }

    #[\Override]
    protected static function host(): string
    {
        return self::environment(self::HOST_VARIABLE, 'db-test-mysql');
    }

    #[\Override]
    protected static function user(): string
    {
        return self::environment(self::USER_VARIABLE, 'root');
    }

    #[\Override]
    protected static function password(): string
    {
        return self::environment(self::PASSWORD_VARIABLE, 'root');
    }
}
