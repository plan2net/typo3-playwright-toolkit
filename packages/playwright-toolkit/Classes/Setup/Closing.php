<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup;

final class Closing
{
    public static function line(bool $everythingPasses, bool $printedHostCommands): string
    {
        if ($everythingPasses) {
            return 'Ready. Your first test: ddev playwright test';
        }

        if (!$printedHostCommands) {
            return '';
        }

        return 'Stopped here: those need your terminal. Then: ddev playwright setup';
    }
}
