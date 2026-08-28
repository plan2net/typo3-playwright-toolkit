<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit;

use Plan2net\PlaywrightToolkit\Database\DatabaseInitializer;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use TYPO3\CMS\Core\Utility\ArrayUtility;

final class TestContext
{
    public const TEST_ID_HEADER = 'X-Playwright-Test-Id';
    public const TEST_ID_SERVER_KEY = 'HTTP_X_PLAYWRIGHT_TEST_ID';
    /** Only an inspect link sets this; a test run sends the header. */
    public const TEST_ID_COOKIE = 'playwright_test_id';
    public const DATABASE_PREFIX = 'db';
    public const TEST_ID_PATTERN = '/^[A-Z0-9]{16}\z/';

    /**
     * Raise this whenever an endpoint the toolkit depends on changes shape.
     */
    public const API_VERSION = 1;

    /**
     * @param array<string, mixed>|null $defaultConnection pass it when $GLOBALS does not carry it yet
     */
    public static function applyDatabaseConnectionOverrides(?array $defaultConnection = null): void
    {
        /** @var array<string, mixed> $connection */
        $connection = $defaultConnection ?? $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? [];

        foreach (self::databaseConnectionOverrides($connection) as $path => $value) {
            $GLOBALS['TYPO3_CONF_VARS'] = ArrayUtility::setValueByPath($GLOBALS['TYPO3_CONF_VARS'], $path, $value);
        }
    }

    /**
     * @param array<string, mixed> $defaultConnection the project's own Default connection
     *
     * @return array<string, mixed> empty when no test ID was sent
     */
    public static function databaseConnectionOverrides(array $defaultConnection): array
    {
        $testId = self::testId();
        if ('' === $testId) {
            return [];
        }

        // Naming a database nothing created fails every later request with
        // "Unknown database", so the connection moves only once it exists.
        if (!DatabaseInitializer::fromGlobals()->provisionCurrentRequest($defaultConnection)) {
            return [];
        }

        return TestDatabaseDriverFactory::fromConnection($defaultConnection)->connectionOverrides($testId);
    }

    // Malformed reads as absent, so a request the toolkit did not send changes
    // nothing. DatabaseName::assertProvisionable() is still the gate that throws.
    public static function testId(): string
    {
        $testId = self::rawTestId();

        return 1 === preg_match(self::TEST_ID_PATTERN, $testId) ? $testId : '';
    }

    public static function malformedTestId(): ?string
    {
        $testId = self::rawTestId();

        if ('' === $testId || 1 === preg_match(self::TEST_ID_PATTERN, $testId)) {
            return null;
        }

        return $testId;
    }

    private static function rawTestId(): string
    {
        $fromHeader = trim((string) ($_SERVER[self::TEST_ID_SERVER_KEY] ?? ''));

        return '' !== $fromHeader ? $fromHeader : trim((string) ($_COOKIE[self::TEST_ID_COOKIE] ?? ''));
    }
}
