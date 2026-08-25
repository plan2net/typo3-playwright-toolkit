<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database;

use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LockFileNamingTest extends TestCase
{
    /**
     * Cleanup discovers disposable test databases by globbing "db-*.lock"; the
     * serialization locks and the reusable template must stay invisible to it.
     */
    #[Test]
    public function exposesOnlyTheClaimToTheCleanupGlob(): void
    {
        $lockFiles = new LockFiles('/locks');

        self::assertStringStartsWith('db-', basename($lockFiles->claim('dbABCDEF0123456789')));
        self::assertStringStartsNotWith('db-', basename($lockFiles->createLock('dbABCDEF0123456789')));
        self::assertStringStartsNotWith('db-', LockFiles::TEMPLATE_LOCK_FILE);
    }
}
