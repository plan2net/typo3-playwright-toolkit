<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database\Driver;

use Plan2net\PlaywrightToolkit\Database\Driver\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Engine}>
     */
    public static function driverNames(): iterable
    {
        yield 'pdo_pgsql' => ['pdo_pgsql', Engine::Postgres];
        yield 'pgsql' => ['pgsql', Engine::Postgres];
        yield 'mysqli' => ['mysqli', Engine::Mysql];
        yield 'pdo_mysql' => ['pdo_mysql', Engine::Mysql];
        yield 'pdo_sqlite' => ['pdo_sqlite', Engine::Sqlite];
        yield 'sqlite3' => ['sqlite3', Engine::Sqlite];
    }

    #[Test]
    #[DataProvider('driverNames')]
    public function mapsEveryDriverTypo3Supports(string $driver, Engine $expected): void
    {
        self::assertSame($expected, Engine::fromDoctrineDriver($driver));
    }

    #[Test]
    public function refusesAnUnknownDriverAndNamesTheOnesItKnows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/oracle.*mysqli/s');

        Engine::fromDoctrineDriver('oracle');
    }

    #[Test]
    public function exposesAStableNameForReporting(): void
    {
        self::assertSame('postgres', Engine::Postgres->value);
        self::assertSame('mysql', Engine::Mysql->value);
        self::assertSame('sqlite', Engine::Sqlite->value);
    }
}
