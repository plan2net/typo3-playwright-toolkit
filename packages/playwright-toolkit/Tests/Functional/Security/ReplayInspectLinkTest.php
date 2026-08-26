<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Security;

use Plan2net\PlaywrightToolkit\Database\SeededBackendUser;
use Plan2net\PlaywrightToolkit\Database\SeededSession;
use Plan2net\PlaywrightToolkit\Http\InspectProvider;
use Plan2net\PlaywrightToolkit\Security\InspectToken;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ReplayInspectLinkTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = $this->get(TestApiSecret::class)->ensureExists();

        $connectionPool = $this->get(ConnectionPool::class);
        $connectionPool->getConnectionForTable(SeededBackendUser::TABLE)
            ->insert(SeededBackendUser::TABLE, SeededBackendUser::row(1));
        $connectionPool->getConnectionForTable(SeededSession::TABLE)
            ->insert(SeededSession::TABLE, SeededSession::row('playwright_test_session', 1));
    }

    protected function tearDown(): void
    {
        @unlink($this->get(TestApiSecret::class)->file());
        parent::tearDown();
    }

    #[Test]
    public function logsIntoTheBackendOfTheReplayedDatabase(): void
    {
        $response = $this->call($this->validToken());

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('be_typo_user=', implode(' ', $response->getHeader('Set-Cookie')));
    }

    // Otherwise the redirected request lands on that test database.
    #[Test]
    public function expiresATestIdCookieTheBrowserStillCarries(): void
    {
        $request = $this->request($this->validToken())
            ->withCookieParams([TestContext::TEST_ID_COOKIE => 'ABCD1234EFGH5678']);

        $response = $this->get(InspectProvider::class)->process($request, $this->passThroughHandler());

        $cookies = implode(' ', $response->getHeader('Set-Cookie'));
        self::assertStringContainsString(TestContext::TEST_ID_COOKIE . '=;', $cookies);
        self::assertStringContainsString('Max-Age=0', $cookies);
    }

    #[Test]
    public function neverSetsATestIdCookieOfItsOwn(): void
    {
        $cookies = implode(' ', $this->call($this->validToken())->getHeader('Set-Cookie'));

        self::assertStringNotContainsString(TestContext::TEST_ID_COOKIE . '=playwright', $cookies);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedTokens(): array
    {
        return [
            'no token at all' => [''],
            'a token that is not a token' => ['let-me-in'],
            'a signature for a test id' => ['__TEST_ID__'],
            'a token signed with another secret' => ['__FOREIGN__'],
        ];
    }

    #[Test]
    #[DataProvider('refusedTokens')]
    public function refusesTheReplayLink(string $token): void
    {
        $response = $this->call($this->resolveToken($token));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    #[Test]
    public function tellsTheHolderOfAnExpiredReplayLinkToMintAnother(): void
    {
        $expired = InspectToken::mint($this->secret, InspectToken::REPLAY_SUBJECT, time() - 1);

        $response = $this->call($expired);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('expired', (string) $response->getBody());
    }

    private function validToken(): string
    {
        return InspectToken::mint($this->secret, InspectToken::REPLAY_SUBJECT, time() + 60);
    }

    private function resolveToken(string $token): string
    {
        return match ($token) {
            '__TEST_ID__' => InspectToken::mint($this->secret, 'ABCD1234EFGH5678', time() + 60),
            '__FOREIGN__' => InspectToken::mint('not-the-secret', InspectToken::REPLAY_SUBJECT, time() + 60),
            default => $token,
        };
    }

    private function request(string $token): ServerRequestInterface
    {
        return (new ServerRequest('https://example.test/typo3/test-api/inspect', 'GET'))
            ->withQueryParams(['replay' => '1', 't' => $token]);
    }

    private function call(string $token): ResponseInterface
    {
        return $this->get(InspectProvider::class)->process($this->request($token), $this->passThroughHandler());
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
