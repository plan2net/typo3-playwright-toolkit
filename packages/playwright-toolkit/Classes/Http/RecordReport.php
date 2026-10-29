<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

use Plan2net\PlaywrightToolkit\Log\RecordedErrors;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RecordReport
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly RecordedErrors $recordedErrors,
    ) {
    }

    public function lastLogUid(): int
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

    /**
     * @return array<string, int> table => records DataHandler wrote
     */
    public function writtenAfter(int $before): array
    {
        return $this->recordedErrors->writesAfter($this->connectionPool->getConnectionForTable('sys_log'), $before);
    }

    public function withRefusals(ResponseInterface $response, int $before): ResponseInterface
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

    public function slugOf(int $pageUid): ?string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $slug = $queryBuilder
            ->select('slug')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid)))
            ->executeQuery()
            ->fetchOne();

        return \is_string($slug) ? $slug : null;
    }
}
