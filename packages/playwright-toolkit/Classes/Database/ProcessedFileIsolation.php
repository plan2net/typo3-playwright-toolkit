<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ProcessedFileIsolation
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
    ) {
    }

    public static function folderFor(string $testId): string
    {
        return '_processed_' . $testId;
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

        $this->removeScratchFiles($testId);
    }

    // A failed conversion leaves its scratch file behind; a successful one is renamed away.
    private function removeScratchFiles(string $testId): void
    {
        $scratch = rtrim(Environment::getPublicPath(), '/') . '/typo3temp/assets/images/';

        foreach ((array) glob($scratch . $testId . '-*') as $file) {
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
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
