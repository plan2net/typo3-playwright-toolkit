<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Http\SiteSettingsProvider;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\Site\SiteSettingsOverlay;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Registry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SiteSettingsProviderTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
    }

    protected function tearDown(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        parent::tearDown();
    }

    #[Test]
    public function storesTheSettingsOfASite(): void
    {
        $response = $this->post(['site' => 'shop', 'settings' => ['theme.look' => 'wild']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['theme.look' => 'wild'],
            $this->get(Registry::class)->get(SiteSettingsOverlay::REGISTRY_NAMESPACE, 'shop')
        );
    }

    #[Test]
    public function refusesARequestWithoutATestId(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        $response = $this->post(['site' => 'shop', 'settings' => ['theme.look' => 'wild']]);

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($this->get(Registry::class)->get(SiteSettingsOverlay::REGISTRY_NAMESPACE, 'shop'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(array $payload): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write((string) json_encode($payload));
        $stream->rewind();

        $request = (new ServerRequest('https://example.test/typo3/test-api/site-settings', 'POST'))
            ->withHeader(TestApiSecret::HEADER, $this->get(TestApiSecret::class)->ensureExists())
            ->withBody($stream);

        return $this->get(SiteSettingsProvider::class)->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(['passedThrough' => true]);
            }
        });
    }
}
