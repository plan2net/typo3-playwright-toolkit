<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database\Driver;

use Plan2net\PlaywrightToolkit\Database\Driver\PostgresTestDatabaseDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostgresCloneStatementTest extends TestCase
{
    #[Test]
    #[DataProvider('versionsWithStrategySupport')]
    public function clonesWithFileCopyFromPostgres15On(string $serverVersion): void
    {
        $statement = PostgresTestDatabaseDriver::cloneStatement('dbABCD', 'tpl', $serverVersion);

        self::assertSame('CREATE DATABASE "dbABCD" TEMPLATE "tpl" STRATEGY = FILE_COPY', $statement);
    }

    /**
     * @return list<array{string}>
     */
    public static function versionsWithStrategySupport(): array
    {
        return [['15.0'], ['16.11'], ['17.2'], ['18beta1']];
    }

    /**
     * STRATEGY is a syntax error before 15, and a consumer's project decides the
     * server version, not this package.
     */
    #[Test]
    #[DataProvider('versionsWithoutStrategySupport')]
    public function omitsTheStrategyOnOlderServers(string $serverVersion): void
    {
        $statement = PostgresTestDatabaseDriver::cloneStatement('dbABCD', 'tpl', $serverVersion);

        self::assertSame('CREATE DATABASE "dbABCD" TEMPLATE "tpl"', $statement);
    }

    /**
     * @return list<array{string}>
     */
    public static function versionsWithoutStrategySupport(): array
    {
        return [['14.10'], ['13.4'], ['9.6.24'], [''], ['not a version']];
    }
}
