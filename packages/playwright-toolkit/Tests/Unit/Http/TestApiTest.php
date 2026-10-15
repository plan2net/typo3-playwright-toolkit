<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Http\TestApi;

final class TestApiTest extends TestCase
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
    public function acceptsTheBareEndpoint(): void
    {
        self::assertTrue(TestApi::matches('/test-api/session', '/test-api/session'));
    }

    #[Test]
    public function acceptsTheEndpointBehindTheBackendPrefix(): void
    {
        self::assertTrue(TestApi::matches('/typo3/test-api/session', '/test-api/session'));
    }

    #[Test]
    public function acceptsTheEndpointBehindAConfiguredEntryPoint(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = '/admin';

        self::assertTrue(TestApi::matches('/admin/test-api/session', '/test-api/session'));
    }

    #[Test]
    #[DataProvider('unrelatedPaths')]
    public function rejectsEverythingElse(string $path): void
    {
        self::assertFalse(TestApi::matches($path, '/test-api/session', '/test-api/health'));
    }

    /**
     * @return list<array{string}>
     */
    public static function unrelatedPaths(): array
    {
        return [
            ['/test-api/sessions'],
            ['/test-api/session/extra'],
            ['typo3/test-api/session'],
            ['/other/test-api/session'],
            ['/'],
        ];
    }
}
