<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ProcessedFileIsolation
{
    public static function folderFor(string $testId): string
    {
        return '_processed_' . $testId;
    }

    public static function rootFor(ResourceStorage $storage): ?string
    {
        if (0 === $storage->getUid()) {
            return self::assetsPath();
        }

        if ('Local' !== $storage->getDriverType()) {
            return null;
        }

        $configuration = $storage->getConfiguration();
        $basePath = trim((string) ($configuration['basePath'] ?? ''), '/');
        if ('' === $basePath) {
            return null;
        }

        $root = 'absolute' === ($configuration['pathType'] ?? 'relative')
            ? '/' . $basePath
            : rtrim(Environment::getPublicPath(), '/') . '/' . $basePath;

        // basePath comes from the database, so ".." would resolve above the site.
        return GeneralUtility::isAllowedAbsPath($root) ? $root : null;
    }

    /**
     * Recorded while the test's own database is open, because cleanup runs after it
     * was dropped: that request carries no test ID, so it reads the base database,
     * which has no storage row to ask. One file per root, written once, so parallel
     * requests never race over a list.
     */
    public static function record(string $root): void
    {
        $file = self::recordDirectory() . '/' . sha1($root);
        if (is_file($file)) {
            return;
        }

        GeneralUtility::mkdir_deep(self::recordDirectory());
        GeneralUtility::writeFile($file, $root);
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
        $scratch = self::assetsPath() . '/images/';

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
        // The fallback storage processes below the public path, and is recorded
        // like the others, so it is in the list whether anything ran or not.
        $roots = [self::assetsPath()];

        foreach ((array) glob(self::recordDirectory() . '/*') as $file) {
            $root = is_string($file) ? rtrim((string) file_get_contents($file), "\n") : '';
            // The file is ours, but a root is what rmdir() is pointed at.
            if ('' !== $root && GeneralUtility::isAllowedAbsPath($root)) {
                $roots[] = $root;
            }
        }

        return $roots;
    }

    private static function recordDirectory(): string
    {
        return Environment::getVarPath() . '/playwright/processing-roots';
    }

    private static function assetsPath(): string
    {
        return rtrim(Environment::getPublicPath(), '/') . '/typo3temp/assets';
    }
}
