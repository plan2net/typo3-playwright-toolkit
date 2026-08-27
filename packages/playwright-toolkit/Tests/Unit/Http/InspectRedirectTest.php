<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Http\InspectProvider;
use Plan2net\PlaywrightToolkit\TestContext;

final class InspectRedirectTest extends TestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';

    /**
     * @var array<string, mixed>
     */
    private array $backendConfiguration = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->backendConfiguration = $GLOBALS['TYPO3_CONF_VARS']['BE'] ?? [];
        // A booted TYPO3 always has this; 11.5 reads it without a null coalesce,
        // so leaving it unset warns there and nowhere else.
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieName'] = 'be_typo_user';
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE'] = $this->backendConfiguration;
        parent::tearDown();
    }

    #[Test]
    public function opensTheStockBackendPath(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = '';

        $response = InspectProvider::backendRedirect(self::TEST_ID, 'the-jwt');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/typo3/', $response->getHeaderLine('location'));
    }

    #[Test]
    public function opensTheConfiguredBackendPath(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = '/admin';

        $response = InspectProvider::backendRedirect(self::TEST_ID, 'the-jwt');

        self::assertSame('/admin/', $response->getHeaderLine('location'));
    }

    /** A cookie under the wrong name lands on the login form instead of the backend. */
    #[Test]
    public function sendsTheSessionUnderTheConfiguredCookieName(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieName'] = 'be_project_user';

        $cookies = InspectProvider::backendRedirect(self::TEST_ID, 'the-jwt')->getHeader('Set-Cookie');

        self::assertCount(2, $cookies);
        self::assertStringStartsWith(TestContext::TEST_ID_COOKIE . '=' . self::TEST_ID . ';', $cookies[0]);
        self::assertStringStartsWith('be_project_user=the-jwt;', $cookies[1]);
    }
}
