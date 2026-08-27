<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ProcessedFileIsolation
{
    public function __construct(
        private readonly BorrowedConnection $borrowedConnection,
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
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

    public function remove(string $testId): void
    {
        // Deleting directories, so the caller is not trusted: an empty test ID
        // names TYPO3's own _processed_ folder, and the replay one names the base
        // database, whose folder outlives every run.
        if (!DatabaseName::isDroppable(DatabaseName::forTestId($testId))) {
            throw new \InvalidArgumentException(
                sprintf('Refusing to remove processed files for unexpected test ID "%s".', $testId),
                1724160002
            );
        }

        foreach ($this->processingRoots() as $root) {
            // rmdir() never descends into a symlink, so a link planted in the
            // folder cannot empty what it points at.
            GeneralUtility::rmdir($root . '/' . self::folderFor($testId), true);
        }
    }

    /**
     * @return list<string>
     */
    private function processingRoots(): array
    {
        $roots = [];

        foreach ($this->storageRepository->findAll() as $storage) {
            if ('Local' !== $storage->getDriverType()) {
                continue;
            }

            $configuration = $storage->getConfiguration();
            $basePath = trim((string) ($configuration['basePath'] ?? ''), '/');
            if ('' === $basePath) {
                continue;
            }

            $root = 'absolute' === ($configuration['pathType'] ?? 'relative')
                ? '/' . $basePath
                : rtrim(Environment::getPublicPath(), '/') . '/' . $basePath;

            // basePath comes from the database, so ".." would resolve above the site.
            if (!GeneralUtility::isAllowedAbsPath($root)) {
                continue;
            }

            $roots[] = $root;
        }

        return $roots;
    }

}
