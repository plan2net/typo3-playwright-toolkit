<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

use Plan2net\PlaywrightToolkit\Database\DatabaseInitializer;
use Plan2net\PlaywrightToolkit\Database\DatabaseName;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use Plan2net\PlaywrightToolkit\Database\SetupCache\SetupCache;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;

final class SetupCacheProvider implements MiddlewareInterface
{
    /**
     * @var string
     */
    private const RESTORE_PATH = '/test-api/setup-cache/restore';

    /**
     * @var string
     */
    private const STORE_PATH = '/test-api/setup-cache/store';

    public function __construct(
        private readonly SetupCache $cache,
        private readonly DatabaseInitializer $initializer,
        private readonly TestApiSecret $secret,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Environment::getContext()->isTesting()) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if (!TestApi::matches($path, self::RESTORE_PATH, self::STORE_PATH)) {
            return $handler->handle($request);
        }

        $refusal = TestApi::refuse($request, $this->secret, 'POST');
        if (null !== $refusal) {
            return $refusal;
        }

        $payload = json_decode((string) $request->getBody(), true);
        if (!is_array($payload)) {
            return TestApi::error('Expected a JSON object body', 400);
        }

        if (DatabaseName::REPLAY_TEST_ID === ($payload['testId'] ?? null)) {
            return TestApi::error('The setup cache and replay mode exclude each other', 400);
        }

        $testId = is_string($payload['testId'] ?? null) ? $payload['testId'] : '';
        $key = is_string($payload['key'] ?? null) ? $payload['key'] : '';
        if (1 !== preg_match(TestContext::TEST_ID_PATTERN, $testId) || '' === $key) {
            return TestApi::error('Expected a contract-shaped "testId" and a "key"', 400);
        }

        $driver = TestDatabaseDriverFactory::fromConnection(
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? []
        );

        if (TestApi::matches($path, self::STORE_PATH)) {
            $state = is_array($payload['state'] ?? null) ? $payload['state'] : [];
            $onlyIfPresent = true === ($payload['onlyIfPresent'] ?? false);

            return new JsonResponse([
                'ok' => true,
                'outcome' => $this->cache->store($driver, $testId, $key, $state, $onlyIfPresent),
            ]);
        }

        // Nothing to apply means nothing to clone; the setup that runs instead
        // provisions on its first request.
        if (!$this->cache->has($key)) {
            return new JsonResponse(['ok' => true, 'outcome' => 'absent', 'state' => []]);
        }

        $this->initializer->provision($driver, $testId);
        $restored = $this->cache->restore($driver, $testId, $key);

        return new JsonResponse([
            'ok' => true,
            'outcome' => $restored['outcome'],
            'state' => $restored['state'],
            'detail' => $restored['detail'],
        ]);
    }
}
