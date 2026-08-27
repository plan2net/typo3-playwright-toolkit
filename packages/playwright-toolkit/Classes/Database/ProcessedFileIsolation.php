<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use TYPO3\CMS\Core\Database\ConnectionPool;

final class ProcessedFileIsolation
{
    public function __construct(
        private readonly BorrowedConnection $borrowedConnection,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public static function folderFor(string $testId): string
    {
        return '_processed_' . $testId;
    }

    /**
     * @param array<string, mixed> $connectionOverrides addressing the test database
     */
    public function apply(array $connectionOverrides, string $testId): void
    {
        $this->borrowedConnection->use($connectionOverrides, function () use ($testId): void {
            $this->connectionPool->getConnectionForTable('sys_file_storage')->update(
                'sys_file_storage',
                ['processingfolder' => self::folderFor($testId)],
                ['driver' => 'Local']
            );
        });
    }
}
