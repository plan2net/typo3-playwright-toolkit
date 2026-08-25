<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;
use Plan2net\PlaywrightToolkit\Database\Cleanup\DatabaseCleanup;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;

final class DatabaseCleanupProvider implements MiddlewareInterface
{
    /**
     * @var string
     */
    private const DROP_PATH = '/test-api/databases/drop';
    /**
     * @var string
     */
    private const SWEEP_PATH = '/test-api/databases/sweep';
    /**
     * @var int
     */
    private const MAXIMUM_BATCH = 500;

    public function __construct(
        private readonly DatabaseCleanup $cleanup,
        private readonly ToolkitConfigurationFactory $configurationFactory,
        private readonly TestApiSecret $secret,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // First, and before the path is examined: outside a Testing context this
        // endpoint must not exist at all — not a 403, not a 405, nothing that
        // reveals the route. Development is refused as firmly as Production.
        if (!Environment::getContext()->isTesting()) {
            return $handler->handle($request);
        }

        $operation = $this->operationFor($request->getUri()->getPath());
        if (null === $operation) {
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

        $driver = TestDatabaseDriverFactory::fromConnectionOrNull(
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? null
        );
        if (null === $driver) {
            return TestApi::error(
                'No usable Default DB connection — check the driver the Testing context configures',
                503
            );
        }

        return match ($operation) {
            self::DROP_PATH => $this->drop($driver, $payload),
            default => $this->sweep($driver, $payload),
        };
    }

    private function operationFor(string $path): ?string
    {
        foreach ([self::DROP_PATH, self::SWEEP_PATH] as $operation) {
            if (TestApi::matches($path, $operation)) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $payload
     */
    private function drop(TestDatabaseDriver $driver, array $payload): ResponseInterface
    {
        $testIds = $this->readTestIds($payload, 'testIds');
        if (null === $testIds) {
            return TestApi::error(
                'Expected a "testIds" array of at most ' . self::MAXIMUM_BATCH . ' strings',
                400
            );
        }

        return new JsonResponse([
            'ok' => true,
            'engine' => $driver->engine()->value,
            'results' => $this->describe($this->cleanup->dropAll($driver, $testIds)),
        ]);
    }

    /**
     * @param array<mixed> $payload
     */
    private function sweep(TestDatabaseDriver $driver, array $payload): ResponseInterface
    {
        // Uncapped: the cap limits how many databases one request drops, and this
        // list only protects them. A big suite sends more ids than that, and
        // refusing it would skip the whole sweep.
        $keepTestIds = $this->readTestIds($payload, 'keepTestIds', null);
        if (null === $keepTestIds) {
            return TestApi::error('Expected a "keepTestIds" array of strings', 400);
        }

        $requestedAgeMs = $payload['minimumAgeMs'] ?? 0;
        if (!is_int($requestedAgeMs) || $requestedAgeMs < 0) {
            return TestApi::error('"minimumAgeMs" must be a non-negative integer', 400);
        }

        // The floor is configuration, never the request: that is what stops a
        // caller choosing an age young enough to hit a run that just started.
        $sweep = $this->cleanup->sweep(
            $driver,
            $keepTestIds,
            $requestedAgeMs,
            $this->configurationFactory->create()->cleanupMinimumAgeMs
        );

        return new JsonResponse([
            'ok' => true,
            'engine' => $driver->engine()->value,
            'results' => $this->describe($sweep['outcomes']),
            'kept' => $sweep['kept'],
            'cutoffMs' => $sweep['cutoffMs'],
        ]);
    }

    /**
     * @param array<mixed> $payload
     * @param int|null     $maximum null accepts a list of any length
     *
     * @return list<string>|null null when the key is missing or unusable
     */
    private function readTestIds(array $payload, string $key, ?int $maximum = self::MAXIMUM_BATCH): ?array
    {
        if (!isset($payload[$key]) || !is_array($payload[$key])) {
            return null;
        }
        if (null !== $maximum && count($payload[$key]) > $maximum) {
            return null;
        }

        $testIds = [];
        foreach ($payload[$key] as $testId) {
            if (!is_string($testId)) {
                return null;
            }
            $testIds[] = $testId;
        }

        return $testIds;
    }

    /**
     * @param array<string, \Plan2net\PlaywrightToolkit\Database\Cleanup\CleanupOutcome> $outcomes
     *
     * @return list<array{testId: string, outcome: string}>
     */
    private function describe(array $outcomes): array
    {
        $results = [];
        foreach ($outcomes as $testId => $outcome) {
            $results[] = ['testId' => (string) $testId, 'outcome' => $outcome->value];
        }

        return $results;
    }
}
