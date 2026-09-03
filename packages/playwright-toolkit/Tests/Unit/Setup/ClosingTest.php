<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\Closing;

final class ClosingTest extends TestCase
{
    #[Test]
    public function namesTheFirstTestToRunOnceEverythingPasses(): void
    {
        self::assertSame(
            'Ready. Your first test: ddev playwright test',
            Closing::line(true, false)
        );
    }

    #[Test]
    public function handsTheTerminalBackWhenItPrintedCommands(): void
    {
        self::assertSame(
            'Stopped here: those need your terminal. Then: ddev playwright setup',
            Closing::line(false, true)
        );
    }

    #[Test]
    public function saysNothingWhenTheTableAlreadySaidIt(): void
    {
        self::assertSame('', Closing::line(false, false));
    }
}
