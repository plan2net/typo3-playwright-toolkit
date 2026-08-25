<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ArrayUtility;

// Resolving a schema needs a live Default connection, which during provisioning
// names a database that does not exist yet.
final class BorrowedConnection
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @template T
     *
     * @param array<string, mixed> $overrides paths into TYPO3_CONF_VARS
     * @param callable(): T        $work
     *
     * @return T
     */
    public function use(array $overrides, callable $work): mixed
    {
        $originalConnections = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'];

        foreach ($overrides as $path => $value) {
            $GLOBALS['TYPO3_CONF_VARS'] = ArrayUtility::setValueByPath($GLOBALS['TYPO3_CONF_VARS'], $path, $value);
        }
        $this->connectionPool->resetConnections();

        try {
            return $work();
        } finally {
            // Closed, not just dropped from the pool: postgres refuses to clone a
            // template another session still holds open.
            $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)->close();
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'] = $originalConnections;
            $this->connectionPool->resetConnections();
        }
    }
}
