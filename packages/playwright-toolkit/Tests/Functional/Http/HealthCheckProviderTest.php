<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Http\HealthCheckProvider;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class HealthCheckProviderTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private ?string $originalTestId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTestId = $_SERVER[TestContext::TEST_ID_SERVER_KEY] ?? null;
    }

    protected function tearDown(): void
    {
        if (null === $this->originalTestId) {
            unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        } else {
            $_SERVER[TestContext::TEST_ID_SERVER_KEY] = $this->originalTestId;
        }
        foreach (glob(Environment::getVarPath() . '/test-locks/*') ?: [] as $file) {
            unlink($file);
        }
        // Written into the shared test instance, so it would leak into the next test.
        $extensionConfiguration = $this->get(ExtensionConfiguration::class);
        $extensionConfiguration->set('playwright_toolkit');
        $extensionConfiguration->set('vite_asset_collector');

        parent::tearDown();
    }

    // The toolkit refuses an extension whose api is below its minimum, so this
    // must be present even on an unhealthy response — otherwise "too old" and
    // "unhealthy" are indistinguishable.
    #[Test]
    public function reportsTheApiVersionEvenWhenACheckFails(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        $response = $this->getHealth();
        $body = (array) json_decode((string) $response->getBody(), true);

        self::assertSame(503, $response->getStatusCode(), 'expected an unhealthy response');
        self::assertSame(TestContext::API_VERSION, $body['api']);
    }

    // For whoever reads the health output: the toolkit knows no engine, so this is
    // the only place a run says which one it provisioned against.
    #[Test]
    public function reportsTheEngineItRanAgainst(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        self::assertSame('sqlite', $this->healthJson()['engine']);
    }

    #[Test]
    public function reportsTheDatabaseUnhealthyWhenTheSeedMarkerIsMissing(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        $database = $this->healthJson()['checks']['database'];

        self::assertFalse($database['ok']);
        self::assertStringContainsString('db' . self::TEST_ID, $database['detail']);
    }

    // Under the empty-test-ID rule the request runs against the project's own
    // database, which nothing seeds — so the preflight has to send a test ID.
    #[Test]
    public function saysSoWhenTheRequestCarriesNoTestId(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        $database = $this->healthJson()['checks']['database'];

        self::assertFalse($database['ok']);
        self::assertStringContainsString(TestContext::TEST_ID_HEADER, $database['detail']);
    }

    #[Test]
    public function rejectsNonGetRequestsOnTheHealthPath(): void
    {
        $request = (new ServerRequest('https://example.test/test-api/health', 'POST'))
            ->withHeader(TestApiSecret::HEADER, $this->authenticate());

        $response = $this->get(HealthCheckProvider::class)->process($request, $this->passThroughHandler());

        self::assertSame(405, $response->getStatusCode());
    }

    #[Test]
    public function forwardsRequestsThatAreNotForTheHealthPath(): void
    {
        $request = new ServerRequest('https://example.test/some/other/path', 'GET');

        $response = $this->get(HealthCheckProvider::class)->process($request, $this->passThroughHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('passedThrough', (string) $response->getBody());
    }

    #[Test]
    public function passesThroughWithoutEmittingHealthJsonOutsideTheTestingContext(): void
    {
        $originalContext = Environment::getContext();
        Environment::initialize(
            new \TYPO3\CMS\Core\Core\ApplicationContext('Production'),
            Environment::isCli(),
            Environment::isComposerMode(),
            Environment::getProjectPath(),
            Environment::getPublicPath(),
            Environment::getVarPath(),
            Environment::getConfigPath(),
            Environment::getCurrentScript(),
            Environment::isWindows() ? 'WINDOWS' : 'UNIX',
        );

        try {
            $request = new ServerRequest('https://example.test/test-api/health', 'GET');

            $response = $this->get(HealthCheckProvider::class)->process($request, $this->passThroughHandler());

            self::assertSame(200, $response->getStatusCode());
            $body = (string) $response->getBody();
            self::assertStringContainsString('passedThrough', $body);
            self::assertStringNotContainsString('checks', $body);
        } finally {
            Environment::initialize(
                $originalContext,
                Environment::isCli(),
                Environment::isComposerMode(),
                Environment::getProjectPath(),
                Environment::getPublicPath(),
                Environment::getVarPath(),
                Environment::getConfigPath(),
                Environment::getCurrentScript(),
                Environment::isWindows() ? 'WINDOWS' : 'UNIX',
            );
        }
    }

    private function passThroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(['passedThrough' => true]);
            }
        };
    }

    private function getHealth(): ResponseInterface
    {
        $request = new ServerRequest('https://example.test/test-api/health', 'GET');
        $request = $request->withHeader(TestApiSecret::HEADER, $this->authenticate());

        return $this->get(HealthCheckProvider::class)->process($request, $this->passThroughHandler());
    }

    /** The endpoint answers authenticated callers only; see EndpointAuthenticationTest. */
    private function authenticate(): string
    {
        return $this->get(TestApiSecret::class)->ensureExists();
    }

    /**
     * @return array<string, mixed>
     */
    private function healthJson(): array
    {
        return (array) json_decode((string) $this->getHealth()->getBody(), true);
    }
}
