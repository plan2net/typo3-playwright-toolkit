<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Configuration;

final class AddonVersion
{
    public static function inFile(string $path): ?string
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            return null;
        }

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ('' !== $line && !str_starts_with($line, '#')) {
                return $line;
            }
        }

        return null;
    }

    public static function driftInProject(string $projectPath, ?string $extension): ?string
    {
        return self::drift(self::inFile($projectPath . '/.ddev/playwright-toolkit.version'), $extension);
    }

    public static function drift(?string $addon, ?string $extension): ?string
    {
        // No file, no add-on: a project may run the commands without DDEV.
        if (null === $addon) {
            return null;
        }

        $installed = ltrim((string) $extension, 'v');
        if (1 !== preg_match('/^\d+\.\d+\.\d+$/', $installed)) {
            return null;
        }

        if ($addon === $installed) {
            return null;
        }

        return sprintf(
            'The DDEV add-on is %s and the extension is %s. Run `ddev add-on get` '
                . 'for the release you are on, then `ddev restart`.',
            (string) $addon,
            ltrim((string) $extension, 'v')
        );
    }
}
