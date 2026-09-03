<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup;

final class Answers
{
    /**
     * @var string
     */
    public const DEFAULT_TEST_DIRECTORY = 'tests/playwright';

    /**
     * We write files into this directory and paste it into shell commands.
     *
     * @var string
     */
    private const SAFE_DIRECTORY = '#^[A-Za-z0-9._/-]+\z#';

    public static function testDirectoryProblem(string $directory): ?string
    {
        if (\in_array('..', explode('/', $directory), true)) {
            return 'The test directory must not contain a ".." segment.';
        }

        if (str_starts_with($directory, '/')) {
            return 'The test directory must be relative to the project root.';
        }

        if (1 !== preg_match(self::SAFE_DIRECTORY, $directory)) {
            return 'The test directory may only contain letters, digits, ".", "_", "-" and "/".';
        }

        return null;
    }

    /**
     * The `..` chain from the test directory back to the project root, for consumerRoot in
     * playwright.config.ts. "." and empty segments are spelling, not depth.
     */
    public static function relativeProjectRoot(string $testDirectory): string
    {
        $depth = \count(array_filter(
            explode('/', $testDirectory),
            static fn(string $segment): bool => '' !== $segment && '.' !== $segment
        ));

        return implode('/', array_fill(0, max($depth, 1), '..'));
    }

    // Same rules as resolveTestingURL() on the npm side.
    public static function testingUrlProblem(string $url): ?string
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return 'The testing URL must be absolute, such as https://example-testing.ddev.site.';
        }

        // The URL is written into playwright.config.ts, which the run then executes.
        if (1 !== preg_match('#^[A-Za-z0-9.\[\]:_-]+\z#', $parts['host'])) {
            return 'The testing URL host may only contain letters, digits, ".", "_", "-" and ":".';
        }

        if (!\in_array($parts['scheme'], ['http', 'https'], true)) {
            return 'The testing URL must be http or https.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The testing URL must not contain a user name or password.';
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            return 'The testing URL must not contain a query string or a fragment.';
        }

        // The test API answers at /typo3/test-api/…, so a site in a subdirectory cannot work.
        if (1 !== preg_match('#^/*\z#', $parts['path'] ?? '')) {
            return 'The testing URL must be a bare origin, so it can have no path.';
        }

        return null;
    }
}
