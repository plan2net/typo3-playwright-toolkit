<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup\Check;

use Plan2net\PlaywrightToolkit\Setup\Result;

final class NpmPackage
{
    /**
     * @var string
     */
    private const PACKAGE = '@plan2net/typo3-playwright-toolkit';

    public function __construct(
        private readonly string $directory,
        private readonly ?string $extensionVersion,
        private readonly bool $runsElsewhere = false,
    ) {
    }

    public function run(): Result
    {
        if ($this->runsElsewhere) {
            return Result::pass('the test run lives in another container');
        }

        $manifest = $this->directory . '/node_modules/' . self::PACKAGE . '/package.json';
        if (!is_file($manifest)) {
            return Result::fail(self::PACKAGE . ' is not installed in ' . $this->directory);
        }

        // The generated playwright.config.ts uses import.meta.url, so CommonJS cannot load it.
        $own = json_decode((string) @file_get_contents($this->directory . '/package.json'), true);
        if (\is_array($own) && 'module' !== ($own['type'] ?? null)) {
            return Result::fail(sprintf(
                '%s/package.json is not ESM. Run: ddev npm pkg set type=module',
                $this->directory
            ));
        }

        $installed = json_decode((string) file_get_contents($manifest), true);
        $installed = \is_array($installed) ? (string) ($installed['version'] ?? '') : '';
        $extension = ltrim((string) $this->extensionVersion, 'v');

        // Only two releases can be compared. A dev or path install never matches.
        if (1 !== preg_match('/^\d+\.\d+\.\d+$/', $extension)) {
            return Result::pass(sprintf(
                'version %s installed, and version %s of the extension cannot be compared to it',
                $installed,
                $extension
            ));
        }

        if ($installed !== $extension) {
            return Result::fail(sprintf(
                'the npm package is %s and the extension is %s',
                $installed,
                $extension
            ));
        }

        return Result::pass($installed);
    }
}
