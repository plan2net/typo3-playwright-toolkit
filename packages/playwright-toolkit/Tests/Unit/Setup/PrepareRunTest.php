<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\PrepareRun;

final class PrepareRunTest extends TestCase
{
    #[Test]
    public function flushesTheTestingCachesBeforeItBuildsTheTemplate(): void
    {
        self::assertSame(
            [
                "TYPO3_CONTEXT=Testing '/var/www/html/vendor/bin/typo3' cache:flush",
                "TYPO3_CONTEXT=Testing '/var/www/html/vendor/bin/typo3' playwright:prepare",
            ],
            PrepareRun::commands('/var/www/html')
        );
    }
}
