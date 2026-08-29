<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

use Plan2net\PlaywrightToolkit\Database\BorrowedConnection;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use Plan2net\PlaywrightToolkit\Log\RecordedErrors;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;

final class RecordedErrorProvider implements MiddlewareInterface
{
    /**
     * @var string
     */
    private const ERRORS_PATH = '/test-api/errors';

    /**
     * @var int
     */
    private const MAXIMUM_ROWS = 20;

    public function __construct(
        private readonly TestApiSecret $secret,
        private readonly BorrowedConnection $borrowedConnection,
        private readonly ConnectionPool $connectionPool,
        private readonly RecordedErrors $recordedErrors,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Environment::getContext()->isTesting()) {
            return $handler->handle($request);
        }

        if (!TestApi::matches($request->getUri()->getPath(), self::ERRORS_PATH)) {
            return $handler->handle($request);
        }

        if (null !== $refusal = TestApi::refuse($request, $this->secret, 'GET')) {
            return $refusal;
        }

        $testId = trim((string) ($request->getQueryParams()['id'] ?? ''));

        // The value names a database, so it is checked before it reaches one.
        if (1 !== preg_match(TestContext::TEST_ID_PATTERN, $testId)) {
            return TestApi::error('A test id is required', 400);
        }

        $errors = $this->errorsOf($testId);

        return new JsonResponse([
            'success' => true,
            'testId' => $testId,
            'truncated' => \count($errors) >= self::MAXIMUM_ROWS,
            'errors' => \array_slice($errors, 0, self::MAXIMUM_ROWS),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function errorsOf(string $testId): array
    {
        /** @var array<string, mixed> $default */
        $default = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? [];

        try {
            $overrides = TestDatabaseDriverFactory::fromConnection($default)->connectionOverrides($testId);

            return $this->borrowedConnection->use(
                $overrides,
                fn(): array => $this->recordedErrors->readFrom(
                    $this->connectionPool->getConnectionForTable('sys_log'),
                    self::MAXIMUM_ROWS + 1
                )
            );
        } catch (\Throwable) {
            // A database that cannot be reached has nothing to report, and asking
            // about one must never fail the request that asked.
            return [];
        }
    }
}
