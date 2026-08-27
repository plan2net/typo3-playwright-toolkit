<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database\Driver;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Database\Driver\MysqlTestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\SeededSession;

/**
 * MySQL clones table by table, and readiness is judged by the seeded session row.
 * Copied in name order, be_sessions lands near the front, so a clone that stops
 * halfway leaves a database that claims to be ready with most tables missing.
 * Postgres and sqlite copy in one step and cannot do this.
 */
final class MysqlCopyOrderTest extends TestCase
{
    #[Test]
    public function copiesTheSessionTableLast(): void
    {
        $order = MysqlTestDatabaseDriver::copyOrder(['be_sessions', 'pages', 'tt_content']);

        self::assertSame(SeededSession::TABLE, end($order));
    }

    #[Test]
    public function keepsEveryOtherTableInTheOrderItWasGiven(): void
    {
        $order = MysqlTestDatabaseDriver::copyOrder(['backend_layout', 'be_sessions', 'pages']);

        self::assertSame(['backend_layout', 'pages', 'be_sessions'], $order);
    }

    #[Test]
    public function leavesATemplateWithoutTheSessionTableAlone(): void
    {
        $order = MysqlTestDatabaseDriver::copyOrder(['pages', 'tt_content']);

        self::assertSame(['pages', 'tt_content'], $order);
    }
}
