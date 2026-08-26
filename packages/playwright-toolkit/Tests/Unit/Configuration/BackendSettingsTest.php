<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Configuration;

use Plan2net\PlaywrightToolkit\Configuration\BackendSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BackendSettingsTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $backendConfiguration = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->backendConfiguration = $GLOBALS['TYPO3_CONF_VARS']['BE'] ?? [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE'] = $this->backendConfiguration;
        parent::tearDown();
    }

    #[Test]
    public function readsAConfiguredCookieName(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieName'] = 'be_project_user';

        self::assertSame('be_project_user', BackendSettings::cookieName());
    }

    /** An empty setting is core's own default, not a cookie with no name. */
    #[Test]
    public function fallsBackToTheStockCookieName(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieName'] = '  ';

        self::assertSame('be_typo_user', BackendSettings::cookieName());
    }

    /** TYPO3 11.5 and 12.4 have no entryPoint setting at all. */
    #[Test]
    public function fallsBackToTheStockEntryPoint(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint']);

        self::assertSame('/typo3', BackendSettings::entryPoint());
    }

    #[Test]
    #[DataProvider('entryPoints')]
    public function normalisesTheConfiguredEntryPoint(string $configured, string $expected): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = $configured;

        self::assertSame($expected, BackendSettings::entryPoint());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function entryPoints(): array
    {
        return [
            'stock value' => ['/typo3', '/typo3'],
            'renamed' => ['/admin', '/admin'],
            'no leading slash' => ['admin', '/admin'],
            'trailing slash' => ['/admin/', '/admin'],
            'in a subdirectory' => ['/mysite/typo3', '/mysite/typo3'],
            'doubled slashes' => ['/admin//backend/', '/admin/backend'],
            'absolute url' => ['https://backend.example.test/admin', '/admin'],
            'empty' => ['', '/typo3'],
        ];
    }
}
