<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Http\DatabaseCleanupProvider;
use Plan2net\PlaywrightToolkit\Http\HealthCheckProvider;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\Session\BackendSessionProvider;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The Testing context decides whether these endpoints exist. It cannot decide who
 * may call them, and a reachable Testing instance would otherwise hand a backend
 * session to anybody — including one that provisions its own test database first.
 */
final class EndpointAuthenticationTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = $this->get(TestApiSecret::class)->ensureExists();
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
    }

    protected function tearDown(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        @unlink($this->get(TestApiSecret::class)->file());
        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string<MiddlewareInterface>, string, string}>
     */
    public static function protectedEndpoints(): array
    {
        return [
            'session' => [BackendSessionProvider::class, '/test-api/session', 'POST'],
            'health' => [HealthCheckProvider::class, '/test-api/health', 'GET'],
            'drop' => [DatabaseCleanupProvider::class, '/test-api/databases/drop', 'POST'],
            'sweep' => [DatabaseCleanupProvider::class, '/test-api/databases/sweep', 'POST'],
        ];
    }

    /**
     * @param class-string<MiddlewareInterface> $middleware
     */
    #[Test]
    #[DataProvider('protectedEndpoints')]
    public function refusesACallerWithNoSecret(string $middleware, string $path, string $method): void
    {
        $response = $this->call($middleware, $path, $method, null);

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * @param class-string<MiddlewareInterface> $middleware
     */
    #[Test]
    #[DataProvider('protectedEndpoints')]
    public function refusesACallerWithTheWrongSecret(string $middleware, string $path, string $method): void
    {
        $response = $this->call($middleware, $path, $method, 'not-the-secret');

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * @param class-string<MiddlewareInterface> $middleware
     */
    #[Test]
    #[DataProvider('protectedEndpoints')]
    public function answersACallerWithTheRightSecret(string $middleware, string $path, string $method): void
    {
        $response = $this->call($middleware, $path, $method, $this->secret);

        self::assertNotSame(401, $response->getStatusCode());
    }

    // An unauthenticated caller must not learn whether the path exists at all.
    #[Test]
    public function saysNothingAboutTheSecretItExpected(): void
    {
        $response = $this->call(BackendSessionProvider::class, '/test-api/session', 'POST', 'wrong');
        $body = (string) $response->getBody();

        self::assertStringNotContainsString($this->secret, $body);
        self::assertStringNotContainsString('api-secret', $body);
    }

    private function call(string $middleware, string $path, string $method, ?string $secret): ResponseInterface
    {
        $request = new ServerRequest('https://example.test' . $path, $method);
        $request = $request->withHeader(TestContext::TEST_ID_HEADER, self::TEST_ID);

        if (null !== $secret) {
            $request = $request->withHeader(TestApiSecret::HEADER, $secret);
        }

        if ('POST' === $method) {
            $request = $request->withParsedBody(['testIds' => []]);
        }

        return $this->get($middleware)->process($request, $this->passThroughHandler());
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
}
