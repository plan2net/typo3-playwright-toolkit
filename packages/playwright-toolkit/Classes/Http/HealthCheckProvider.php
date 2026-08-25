<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfiguration;
use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;
use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use Plan2net\PlaywrightToolkit\Database\DatabaseName;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Session\UserSessionManager;

final class HealthCheckProvider implements MiddlewareInterface
{
    /**
     * @var string
     */
    private const HEALTH_PATH = '/test-api/health';

    public function __construct(
        private readonly ToolkitConfigurationFactory $configurationFactory,
        private readonly LockFiles $lockFiles,
        private readonly TestApiSecret $secret,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Environment::getContext()->isTesting()) {
            return $handler->handle($request);
        }

        if (!TestApi::matches($request->getUri()->getPath(), self::HEALTH_PATH)) {
            return $handler->handle($request);
        }

        // Also gated: the health response names the driver and the session id, and
        // it is the cheapest way to confirm a toolkit secret is right — which makes
        // it the cheapest way to probe for one too.
        $refusal = TestApi::refuse($request, $this->secret, 'GET');
        if (null !== $refusal) {
            return $refusal;
        }

        $configuration = $this->configurationFactory->create();
        $driver = TestDatabaseDriverFactory::fromConnectionOrNull(
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? null
        );

        $checks = [
            'context' => $this->checkTestingContext(),
            'database' => $this->checkDatabase($driver),
            'session' => $this->checkSessionCreation($configuration),
        ];

        $ok = array_reduce($checks, static fn(bool $carry, array $check): bool => $carry && $check['ok'], true);

        return new JsonResponse(
            [
                'ok' => $ok,
                // Reported even when a check fails, so the toolkit can tell "too
                // old" from "unhealthy" on the same response.
                'api' => TestContext::API_VERSION,
                'engine' => $driver?->engine()->value,
                'checks' => $checks,
            ],
            $ok ? 200 : 503
        );
    }

    /**
     * Always ok by the time it runs: process() passes a non-Testing context
     * through. It reports *which* Testing/* context answered.
     *
     * @return array{ok: bool, detail: string}
     */
    private function checkTestingContext(): array
    {
        return ['ok' => true, 'detail' => (string) Environment::getContext()];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function checkDatabase(?TestDatabaseDriver $driver): array
    {
        if (null === $driver) {
            return [
                'ok' => false,
                'detail' => 'No usable Default DB connection — check the driver the Testing context configures.',
            ];
        }

        $testId = TestContext::testId();
        if ('' === $testId) {
            return [
                'ok' => false,
                'detail' => sprintf(
                    'This request carried no %s, so it ran against the project\'s own database, which nothing seeds.',
                    TestContext::TEST_ID_HEADER
                ),
            ];
        }

        $databaseName = DatabaseName::forTestId($testId);
        $marker = $this->lockFiles->claim($databaseName);

        if (!file_exists($marker)) {
            return [
                'ok' => false,
                'detail' => sprintf(
                    'No claim for %s, so this extension never provisioned it. '
                    . 'A test database is created while the request boots, so this usually means '
                    . 'DatabaseInitializer did not run: check that TYPO3_CONTEXT is Testing and that '
                    . 'the request reached PHP over HTTP rather than the CLI. Expected claim: %s',
                    $databaseName,
                    $marker
                ),
            ];
        }

        return $driver->checkTestDatabase($testId);
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function checkSessionCreation(ToolkitConfiguration $configuration): array
    {
        try {
            $userSession = UserSessionManager::create('BE')
                ->createSessionFromStorage($configuration->preseededSessionId);

            return [
                'ok' => true,
                'detail' => sprintf(
                    'Pre-seeded BE session %s usable (user %d)',
                    $userSession->getIdentifier(),
                    $userSession->getUserId() ?? 0
                ),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'detail' => 'Cannot rehydrate pre-seeded BE session: ' . $exception->getMessage(),
            ];
        }
    }

}
