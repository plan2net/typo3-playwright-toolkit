<?php

declare(strict_types=1);

namespace Plan2net\BootProbe;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Stands in for any extension that reads the database from its own
 * BootCompletedEvent listener. Listener order between extensions is not
 * guaranteed, so the per-test database must exist before boot starts —
 * otherwise this query kills the request with "Unknown database".
 */
final class BootQuery
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function __invoke(BootCompletedEvent $event): void
    {
        if (!Environment::getContext()->isTesting()
            || '' === trim((string) ($_SERVER['HTTP_X_PLAYWRIGHT_TEST_ID'] ?? ''))
        ) {
            return;
        }

        $this->connectionPool->getConnectionForTable('pages')
            ->executeQuery('SELECT uid FROM pages LIMIT 1')
            ->fetchOne();
    }
}
