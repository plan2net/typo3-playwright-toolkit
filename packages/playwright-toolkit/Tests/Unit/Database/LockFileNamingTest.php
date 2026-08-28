<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;

final class LockFileNamingTest extends TestCase
{
    /**
     * Cleanup finds test databases by globbing "db-*.lock". Lock files land in the
     * same directory and are named after the key, so no key may start with it.
     */
    #[Test]
    public function exposesOnlyTheClaimToTheCleanupGlob(): void
    {
        $lockFiles = new LockFiles('/locks');

        self::assertStringStartsWith('db-', basename($lockFiles->claim('dbABCDEF0123456789')));
        self::assertStringStartsNotWith('db-', $lockFiles->databaseLock('dbABCDEF0123456789'));
        self::assertStringStartsNotWith('db-', LockFiles::TEMPLATE_LOCK);
    }
}
