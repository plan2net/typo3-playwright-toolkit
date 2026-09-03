<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup;

final class HostCommands
{
    /**
     * @var string
     */
    private const BROWSERS_PATH = '/var/www/html/.cache/ms-playwright';

    /**
     * @var string
     */
    private const ADDON_RELEASE = 'https://github.com/plan2net/typo3-playwright-toolkit/'
        . 'releases/latest/download/ddev-typo3-playwright-toolkit.tar.gz';

    /**
     * @return list<string>
     */
    public static function block(
        ?string $hostnameFlag,
        bool $needsBrowsers = false,
        string $testDirectory = Answers::DEFAULT_TEST_DIRECTORY,
        bool $needsNpm = false,
        ?string $version = null,
        bool $hasPackageFile = false,
        bool $needsAddon = false,
        bool $testDirectoryConfigured = false,
    ): array {
        // It comes first because it ships the `ddev playwright` commands.
        $lines = $needsAddon ? ['ddev add-on get ' . self::ADDON_RELEASE] : [];
        $flags = array_values(array_filter([
            $hostnameFlag,
            $needsBrowsers ? '--web-environment-add=PLAYWRIGHT_BROWSERS_PATH=' . self::BROWSERS_PATH : null,
            // web_environment is the only place the ddev commands read it from, so it
            // needs configuring unless it is already there.
            $testDirectoryConfigured || Answers::DEFAULT_TEST_DIRECTORY === $testDirectory
                ? null
                : '--web-environment-add=PW_TEST_DIR=' . $testDirectory,
        ]));

        $flags = array_map(self::pasteable(...), $flags);
        if ([] !== $flags) {
            $lines = [...$lines, ...self::ddevConfig($flags), 'ddev restart'];
        } elseif ($needsAddon) {
            $lines[] = 'ddev restart';
        }

        if ($needsNpm) {
            $lines[] = 'cd ' . self::pasteable($testDirectory);
            if (!$hasPackageFile) {
                $lines[] = 'ddev npm init -y && ddev npm pkg set type=module';
            }

            $release = 1 === preg_match('/^\d+\.\d+\.\d+$/', ltrim((string) $version, 'v'));
            $lines[] = 'ddev npm i -D @plan2net/typo3-playwright-toolkit'
                . ($release ? '@' . ltrim((string) $version, 'v') : '') . ' @playwright/test';
        }

        // The install needs the path in the environment, so it comes after the restart.
        if ($needsBrowsers) {
            $lines[] = 'ddev npx playwright install --with-deps chromium';
        }

        return $lines;
    }

    // People paste this block, and hostnames come from the project's own config.yaml.
    private static function pasteable(string $argument): string
    {
        return 1 === preg_match('#^[A-Za-z0-9._/=,:@-]+\z#', $argument)
            ? $argument
            : escapeshellarg($argument);
    }

    /**
     * @param list<string> $flags
     *
     * @return list<string>
     */
    private static function ddevConfig(array $flags): array
    {
        $lines = ['ddev config ' . $flags[0]];
        foreach (\array_slice($flags, 1) as $flag) {
            $lines[\count($lines) - 1] .= ' \\';
            $lines[] = '    ' . $flag;
        }

        return $lines;
    }
}
