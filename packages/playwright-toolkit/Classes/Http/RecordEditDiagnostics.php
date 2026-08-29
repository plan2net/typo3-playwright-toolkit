<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

use Plan2net\PlaywrightToolkit\Log\RecordedErrors;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RecordEditDiagnostics implements MiddlewareInterface
{
    /**
     * @var string
     */
    private const EDIT_PATH = '/record/edit';

    public function __construct(
        private readonly TestApiSecret $secret,
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

        if (!TestApi::matches($request->getUri()->getPath(), self::EDIT_PATH)) {
            return $handler->handle($request);
        }

        // Not our endpoint: without the secret the backend's own answer goes back untouched.
        if (!$this->secret->matches(trim($request->getHeaderLine(TestApiSecret::HEADER)))) {
            return $handler->handle($request);
        }

        $before = $this->lastLogUid();
        $response = $handler->handle($request);

        $slug = $this->slugOf($response->getHeaderLine('location'));
        if (null !== $slug) {
            $response = $response->withHeader(SavedRecord::HEADER, (string) json_encode(['slug' => $slug]));
        }

        return $this->withDiagnostics($response, $before);
    }

    private function lastLogUid(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_log');

        return (int) $queryBuilder
            ->select('uid')
            ->from('sys_log')
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    private function withDiagnostics(ResponseInterface $response, int $before): ResponseInterface
    {
        $refused = $this->recordedErrors->refusalsAfter(
            $this->connectionPool->getConnectionForTable('sys_log'),
            $before
        );

        if ([] === $refused) {
            return $response;
        }

        return $response->withHeader(
            RecordDiagnostics::HEADER,
            (string) json_encode(['errors' => \array_slice($refused, 0, 1), 'count' => \count($refused)])
        );
    }

    private function slugOf(string $location): ?string
    {
        $uid = SavedRecord::uidFrom($location, 'pages');
        if (null === $uid) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $slug = $queryBuilder
            ->select('slug')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchOne();

        return is_string($slug) ? $slug : null;
    }
}
