<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use Plan2net\PlaywrightToolkit\TestContext;

// The name is interpolated into CREATE/DROP DATABASE, so no driver derives it.
final class DatabaseName
{
    /**
     * Must stay in step with TestContext::TEST_ID_PATTERN and DATABASE_PREFIX.
     *
     * @var string
     */
    private const DROPPABLE = '/^db[A-Z0-9]{16}$/';

    /**
     * An empty test ID legitimately selects the base database.
     *
     * @var string
     */
    private const PROVISIONABLE = '/^db([A-Z0-9]{16})?$/';

    public static function forTestId(string $testId): string
    {
        return TestContext::DATABASE_PREFIX . $testId;
    }

    public static function forTestIdChecked(string $testId): string
    {
        $databaseName = self::forTestId($testId);

        if (!self::isDroppable($databaseName)) {
            throw new \InvalidArgumentException(
                sprintf('Refusing to use unexpected database name "%s".', $databaseName),
                1724160001
            );
        }

        return $databaseName;
    }

    public static function testIdOf(string $databaseName): string
    {
        return substr($databaseName, \strlen(TestContext::DATABASE_PREFIX));
    }

    public static function assertProvisionable(string $databaseName): void
    {
        if (!self::isProvisionable($databaseName)) {
            throw new \InvalidArgumentException(
                sprintf('Refusing to use "%s" as a test database name', $databaseName),
                1724160000
            );
        }
    }

    public static function isProvisionable(string $databaseName): bool
    {
        return 1 === preg_match(self::PROVISIONABLE, $databaseName);
    }

    public static function isDroppable(string $databaseName): bool
    {
        return 1 === preg_match(self::DROPPABLE, $databaseName);
    }
}
