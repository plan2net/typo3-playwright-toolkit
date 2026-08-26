<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Session;

use Plan2net\PlaywrightToolkit\Database\SeededBackendUser;
use Plan2net\PlaywrightToolkit\Database\SeededSession;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\Session\BackendSessionProvider;
use Plan2net\PlaywrightToolkit\TestContext;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\FormProtection\BackendFormProtection;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class BackendSessionProviderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    #[Test]
    public function rejectsASessionRequestWithoutTheTestIdHeader(): void
    {
        $request = (new ServerRequest('https://example.test/test-api/session', 'POST'))
            ->withHeader(TestApiSecret::HEADER, $this->authenticate());

        $response = $this->get(BackendSessionProvider::class)->process($request, $this->passThroughHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function rejectsASessionRequestWhoseTestIdIsMalformed(): void
    {
        $request = (new ServerRequest('https://example.test/test-api/session', 'POST'))
            ->withHeader(TestApiSecret::HEADER, $this->authenticate())
            ->withHeader(TestContext::TEST_ID_HEADER, 'not-a-valid-test-id');

        $response = $this->get(BackendSessionProvider::class)->process($request, $this->passThroughHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function doesNotEchoAMalformedTestIdBackToTheCaller(): void
    {
        $request = (new ServerRequest('https://example.test/test-api/session', 'POST'))
            ->withHeader(TestApiSecret::HEADER, $this->authenticate())
            ->withHeader(TestContext::TEST_ID_HEADER, '<script>alert(1)</script>');

        $response = $this->get(BackendSessionProvider::class)->process($request, $this->passThroughHandler());

        self::assertStringNotContainsString('script', (string) $response->getBody());
    }

    #[Test]
    public function rejectsADuplicatedHeaderThatWouldReachTheDatabaseLayerAsOneValue(): void
    {
        $request = (new ServerRequest('https://example.test/test-api/session', 'POST'))
            ->withHeader(TestApiSecret::HEADER, $this->authenticate())
            ->withHeader(TestContext::TEST_ID_HEADER, ['ABCD1234EFGH5678', 'ZZZZ9999ZZZZ9999']);

        $response = $this->get(BackendSessionProvider::class)->process($request, $this->passThroughHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * The builders post to the real /typo3/record/edit, which RouteDispatcher
     * refuses without a route token. Asserting core validates it, rather than
     * asserting a shape, is what keeps us from reimplementing its hashing.
     */
    #[Test]
    public function theSessionResponseCarriesARouteTokenCoreAccepts(): void
    {
        $this->seedSessionAndBackendUser();

        $request = (new ServerRequest('https://example.test/test-api/session', 'POST'))
            ->withHeader(TestApiSecret::HEADER, $this->authenticate())
            ->withHeader(TestContext::TEST_ID_HEADER, 'ABCD1234EFGH5678');

        $response = $this->get(BackendSessionProvider::class)->process($request, $this->passThroughHandler());

        $payload = json_decode((string) $response->getBody(), true);
        self::assertTrue($payload['success'] ?? false, (string) $response->getBody());

        $token = $payload['tokens']['record_edit'] ?? null;
        self::assertIsString($token);

        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        // TYPO3 14 reads the client address off normalizedParams while starting.
        $backendUser->start(
            $request
                ->withCookieParams([...$request->getCookieParams(), 'be_typo_user' => $payload['cookieValue']])
                ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request))
        );
        $GLOBALS['BE_USER'] = $backendUser;

        $formProtection = GeneralUtility::makeInstance(
            BackendFormProtection::class,
            $backendUser,
            GeneralUtility::makeInstance(Registry::class),
        );
        self::assertTrue($formProtection->validateToken($token, 'route', 'record_edit'));
    }

    /**
     * The toolkit sets the cookie the response names, so a project that renamed
     * it would otherwise be handed one the backend never reads.
     */
    #[Test]
    public function namesTheConfiguredBackendCookie(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieName'] = 'be_project_user';
        $this->seedSessionAndBackendUser();

        $payload = $this->createSession();

        self::assertSame('be_project_user', $payload['cookieName'] ?? null);
    }

    /** The builders post to record/edit under this path, and a project may move it. */
    #[Test]
    public function namesTheBackendEntryPoint(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = '/admin';
        $this->seedSessionAndBackendUser();

        $payload = $this->createSession();

        self::assertSame('/admin', $payload['backendPath'] ?? null);
    }

    // What the other three endpoints answer, so a wrong verb on a toolkit path is
    // not reported as a missing route.
    #[Test]
    public function rejectsNonPostRequestsOnTheSessionPath(): void
    {
        $request = (new ServerRequest('https://example.test/test-api/session', 'GET'))
            ->withHeader(TestApiSecret::HEADER, $this->authenticate());

        $response = $this->get(BackendSessionProvider::class)->process($request, $this->passThroughHandler());

        self::assertSame(405, $response->getStatusCode());
    }

    #[Test]
    public function forwardsRequestsThatAreNotSessionPosts(): void
    {
        $request = (new ServerRequest('https://example.test/some/other/path', 'GET'));

        $response = $this->get(BackendSessionProvider::class)->process($request, $this->passThroughHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('passedThrough', (string) $response->getBody());
    }

    #[Test]
    public function passesSessionRequestThroughUntouchedWhenNotInTestingContext(): void
    {
        Environment::initialize(
            new ApplicationContext('Production'),
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
            $request = (new ServerRequest('https://example.test/test-api/session', 'POST'))
                ->withHeader('X-Playwright-Test-Id', 'ABCDEFGH12345678');

            $response = $this->get(BackendSessionProvider::class)->process($request, $this->passThroughHandler());

            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('passedThrough', (string) $response->getBody());
        } finally {
            Environment::initialize(
                new ApplicationContext('Testing'),
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

    /**
     * @return array<string, mixed>
     */
    private function createSession(): array
    {
        $request = (new ServerRequest('https://example.test/test-api/session', 'POST'))
            ->withHeader(TestApiSecret::HEADER, $this->authenticate())
            ->withHeader(TestContext::TEST_ID_HEADER, 'ABCD1234EFGH5678');

        $response = $this->get(BackendSessionProvider::class)->process($request, $this->passThroughHandler());

        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['success'] ?? false, (string) $response->getBody());

        return $payload;
    }

    private function seedSessionAndBackendUser(): void
    {
        $connectionPool = $this->get(ConnectionPool::class);

        $connectionPool->getConnectionForTable(SeededBackendUser::TABLE)
            ->insert(SeededBackendUser::TABLE, SeededBackendUser::row(1));
        $connectionPool->getConnectionForTable(SeededSession::TABLE)
            ->insert(SeededSession::TABLE, SeededSession::row('playwright_test_session', 1));
    }

    private function passThroughHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new JsonResponse(['passedThrough' => true]);
            }
        };
    }

    /** Authenticating first keeps the test-ID assertions above about test IDs. */
    private function authenticate(): string
    {
        return $this->get(TestApiSecret::class)->ensureExists();
    }
}
