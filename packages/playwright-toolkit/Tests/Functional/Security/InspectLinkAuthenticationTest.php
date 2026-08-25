<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Security;

use Plan2net\PlaywrightToolkit\Http\InspectProvider;
use Plan2net\PlaywrightToolkit\Security\InspectToken;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The link hands out a backend session to whoever opens it, so every way of not
 * being entitled to one has to end in the same bare 401.
 */
final class InspectLinkAuthenticationTest extends FunctionalTestCase
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
    }

    protected function tearDown(): void
    {
        @unlink($this->get(TestApiSecret::class)->file());
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedLinks(): array
    {
        return [
            'no token at all' => [self::TEST_ID, ''],
            'a token that is not a token' => [self::TEST_ID, 'let-me-in'],
            'a signature for another test id' => [self::TEST_ID, '__OTHER__'],
            'an expired token' => [self::TEST_ID, '__EXPIRED__'],
            'a token signed with another secret' => [self::TEST_ID, '__FOREIGN__'],
            'a test id that is not contract shaped' => ['../../etc/passwd', '__VALID__'],
        ];
    }

    #[Test]
    #[DataProvider('refusedLinks')]
    public function refusesTheLink(string $testId, string $token): void
    {
        $response = $this->call($testId, $this->resolveToken($token, $testId));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    #[Test]
    public function refusesAnythingButAGet(): void
    {
        $token = InspectToken::mint($this->secret, self::TEST_ID, time() + 60);
        $request = (new ServerRequest('https://example.test/typo3/test-api/inspect', 'POST'))
            ->withQueryParams(['id' => self::TEST_ID, 't' => $token]);

        $response = $this->get(InspectProvider::class)->process($request, $this->passThroughHandler());

        self::assertSame(405, $response->getStatusCode());
    }

    private function resolveToken(string $token, string $testId): string
    {
        return match ($token) {
            '__VALID__' => InspectToken::mint($this->secret, $testId, time() + 60),
            '__OTHER__' => InspectToken::mint($this->secret, 'ZZZZ9999ZZZZ9999', time() + 60),
            '__EXPIRED__' => InspectToken::mint($this->secret, $testId, time() - 1),
            '__FOREIGN__' => InspectToken::mint('not-the-secret', $testId, time() + 60),
            default => $token,
        };
    }

    private function call(string $testId, string $token): ResponseInterface
    {
        $request = (new ServerRequest('https://example.test/typo3/test-api/inspect', 'GET'))
            ->withQueryParams(['id' => $testId, 't' => $token]);

        return $this->get(InspectProvider::class)->process($request, $this->passThroughHandler());
    }

    private function passThroughHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(['passed' => 'through'], 200);
            }
        };
    }
}
