<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Session;

use Plan2net\PlaywrightToolkit\Compatibility\SessionCookieValue;
use Plan2net\PlaywrightToolkit\Configuration\BackendSettings;
use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;
use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use Plan2net\PlaywrightToolkit\Database\DatabaseName;
use Plan2net\PlaywrightToolkit\Http\TestApi;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\FormProtection\BackendFormProtection;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Session\UserSessionManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class BackendSessionProvider implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var string
     */
    private const CREATE_SESSION_PATH = '/test-api/session';

    // The backend routes the builders post to; RouteDispatcher refuses each
    // without a token.
    /**
     * @var list<string>
     */
    private const TOKENED_ROUTES = ['record_edit'];

    public function __construct(
        private readonly ToolkitConfigurationFactory $configurationFactory,
        private readonly TestApiSecret $secret,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Environment::getContext()->isTesting()) {
            return $handler->handle($request);
        }

        if (!TestApi::matches($request->getUri()->getPath(), self::CREATE_SESSION_PATH)) {
            return $handler->handle($request);
        }

        $refusal = TestApi::refuse($request, $this->secret, 'POST');
        if (null !== $refusal) {
            return $refusal;
        }

        $testId = $request->getHeaderLine(TestContext::TEST_ID_HEADER);
        if ('' === $testId) {
            return TestApi::error('Missing ' . TestContext::TEST_ID_HEADER . ' header', 401);
        }

        // The same rule the database layer applies, so a header it would reject
        // cannot mint a backend session here. Never echo the value back.
        if (1 !== preg_match(TestContext::TEST_ID_PATTERN, $testId)) {
            return TestApi::error('Malformed ' . TestContext::TEST_ID_HEADER . ' header', 401);
        }

        $this->rememberTestName($request, $testId);

        try {
            $preseededSessionId = $this->configurationFactory->create()->preseededSessionId;
            $userSessionManager = UserSessionManager::create('BE');
            $userSession = $userSessionManager->createSessionFromStorage($preseededSessionId);
            $cookieValue = SessionCookieValue::of($userSession);

            return new JsonResponse([
                'success' => true,
                'message' => 'Backend session retrieved from fixture',
                'sessionId' => $userSession->getIdentifier(),
                'cookieName' => BackendSettings::cookieName(),
                'cookieValue' => $cookieValue,
                'backendPath' => BackendSettings::entryPoint(),
                'userId' => $userSession->getUserId(),
                'testId' => $testId,
                'tokens' => $this->routeTokens($request, $cookieValue),
            ]);
        } catch (\Throwable $e) {
            $this->logger?->error('Could not create the pre-seeded backend session.', ['exception' => $e]);

            return TestApi::error('Could not create the backend session', 500);
        }
    }

    /** Stored, not sent per request: an inspect session has cookies and no headers. */
    private function rememberTestName(ServerRequestInterface $request, string $testId): void
    {
        // Every scenario of a replay run would overwrite the one before it.
        if (DatabaseName::REPLAY_TEST_ID === $testId) {
            return;
        }

        $payload = json_decode((string) $request->getBody(), true);
        if (!is_array($payload) || !is_string($payload['name'] ?? null)) {
            return;
        }

        LockFiles::inVarPath()->writeLabel(DatabaseName::forTestId($testId), $payload['name']);
    }

    /**
     * Core mints the token from the session data of `$GLOBALS['BE_USER']`, hence
     * the round trip through the cookie rather than an assembled user.
     *
     * @return array<string, string> route identifier => token
     */
    private function routeTokens(ServerRequestInterface $request, string $cookieValue): array
    {
        $authenticated = $request
            ->withCookieParams([...$request->getCookieParams(), BackendSettings::cookieName() => $cookieValue])
            ->withAttribute(
                'normalizedParams',
                $request->getAttribute('normalizedParams') ?? NormalizedParams::createFromRequest($request)
            );

        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->start($authenticated);

        $previousBackendUser = $GLOBALS['BE_USER'] ?? null;
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            // Not the factory: v11 has no createFromRequest() and does not register
            // it as a service. This constructor is the same on 11 through 14.
            $formProtection = GeneralUtility::makeInstance(
                BackendFormProtection::class,
                $backendUser,
                GeneralUtility::makeInstance(Registry::class),
            );

            $tokens = [];
            foreach (self::TOKENED_ROUTES as $route) {
                $tokens[$route] = $formProtection->generateToken('route', $route);
            }
            // Written to the session row here, so the POST that spends the token
            // validates against the same value.
            $formProtection->persistSessionToken();

            return $tokens;
        } finally {
            if (null === $previousBackendUser) {
                unset($GLOBALS['BE_USER']);
            } else {
                $GLOBALS['BE_USER'] = $previousBackendUser;
            }
        }
    }
}
