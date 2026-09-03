<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup;

final class RunLocation
{
    /**
     * Both halves are needed. A shared secret on its own is allowed in any layout, and
     * an empty test directory on its own is just a project nobody has set up yet.
     */
    public static function inAnotherContainer(?string $sharedSecret, string $testDirectory): bool
    {
        if (null === $sharedSecret || '' === $sharedSecret) {
            return false;
        }

        return !is_dir($testDirectory . '/node_modules');
    }
}
