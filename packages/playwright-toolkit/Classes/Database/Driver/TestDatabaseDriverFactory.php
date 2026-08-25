<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\Driver;

// Static because TestContext resolves a driver while settings load, before the container exists.
final class TestDatabaseDriverFactory
{
    /**
     * @param array<string, mixed> $connection the project's Default connection
     */
    public static function fromConnection(array $connection): TestDatabaseDriver
    {
        $driverName = (string) ($connection['driver'] ?? '');
        if ('' === $driverName) {
            throw new \InvalidArgumentException(
                'The Default database connection names no driver, so no test database engine can be derived.'
            );
        }

        // Only the driver comes from the project; the rest addresses the add-on's
        // test service, so we never provision onto the developer's own database.
        return match (Engine::fromDoctrineDriver($driverName)) {
            Engine::Postgres => PostgresTestDatabaseDriver::onTestService($driverName),
            Engine::Sqlite => SqliteTestDatabaseDriver::inVarPath($driverName),
            Engine::Mysql => MysqlTestDatabaseDriver::onTestService($driverName),
        };
    }

    /**
     * @param array<string, mixed>|null $connection
     */
    public static function fromConnectionOrNull(?array $connection): ?TestDatabaseDriver
    {
        if (null === $connection) {
            return null;
        }

        try {
            return self::fromConnection($connection);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
