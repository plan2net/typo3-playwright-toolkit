<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

use Plan2net\PlaywrightToolkit\Compatibility\SessionCookieValue;
use Plan2net\PlaywrightToolkit\Configuration\BackendSettings;
use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;
use Plan2net\PlaywrightToolkit\Database\BorrowedConnection;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Session\UserSessionManager;

final class InspectProvider implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var string
     */
    private const INSPECT_PATH = '/test-api/inspect';

    public function __construct(
        private readonly ToolkitConfigurationFactory $configurationFactory,
        private readonly TestApiSecret $secret,
        private readonly BorrowedConnection $borrowedConnection,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Environment::getContext()->isTesting()) {
            return $handler->handle($request);
        }

        if (!TestApi::matches($request->getUri()->getPath(), self::INSPECT_PATH)) {
            return $handler->handle($request);
        }

        if ('GET' !== $request->getMethod()) {
            return TestApi::error('Method not allowed', 405);
        }

        $parameters = $request->getQueryParams();
        $testId = trim((string) ($parameters['id'] ?? ''));
        $token = trim((string) ($parameters['t'] ?? ''));

        if (1 !== preg_match(TestContext::TEST_ID_PATTERN, $testId)) {
            return TestApi::error('Unauthorized', 401);
        }

        if (!$this->secret->matchesInspectToken($testId, $token)) {
            if ($this->secret->inspectTokenLapsed($testId, $token)) {
                return TestApi::error('This inspect link expired. Run playwright-inspect again.', 401);
            }

            $this->logger?->warning('Refused an inspect link with a bad token.');

            return TestApi::error('Unauthorized', 401);
        }

        return $this->openBackend($testId);
    }

    // Session-scoped, so closing the browser ends the visit. Always Secure: this
    // carries a backend session.
    public static function cookieHeader(string $name, string $value): string
    {
        return implode('; ', [$name . '=' . rawurlencode($value), 'Path=/', 'HttpOnly', 'Secure', 'SameSite=Lax']);
    }

    public static function backendRedirect(string $testId, string $cookieValue): ResponseInterface
    {
        return new RedirectResponse('/typo3/', 302, [
            'Set-Cookie' => [
                self::cookieHeader(TestContext::TEST_ID_COOKIE, $testId),
                self::cookieHeader(BackendSettings::cookieName(), $cookieValue),
            ],
        ]);
    }

    private function openBackend(string $testId): ResponseInterface
    {
        $cookieValue = $this->sessionCookieFor($testId);
        if (null === $cookieValue) {
            return TestApi::error('No seeded session in that test database', 404);
        }

        return self::backendRedirect($testId, $cookieValue);
    }

    // The session row lives in the test database, and this request has no cookie yet.
    private function sessionCookieFor(string $testId): ?string
    {
        $driver = TestDatabaseDriverFactory::fromConnection(
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? []
        );
        $preseededSessionId = $this->configurationFactory->create()->preseededSessionId;

        return $this->borrowedConnection->use(
            $driver->connectionOverrides($testId),
            function () use ($preseededSessionId): ?string {
                try {
                    return SessionCookieValue::of(
                        UserSessionManager::create('BE')->createSessionFromStorage($preseededSessionId)
                    );
                } catch (\Throwable $e) {
                    $this->logger?->warning('Could not open the seeded session.', ['exception' => $e]);

                    return null;
                }
            }
        );
    }

}
