<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Configuration;

use Plan2net\PlaywrightToolkit\Configuration\BackendSettings;
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
}
