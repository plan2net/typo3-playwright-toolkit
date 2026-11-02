<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Imaging;

use Plan2net\PlaywrightToolkit\Database\ProcessedFileIsolation;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\Event\AfterResourceStorageInitializationEvent;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class TestScopedProcessingFolder
{
    /**
     * @var string
     */
    private const RECORD_PROPERTY = 'storageRecord';

    /**
     * @var string
     */
    private const FALLBACK_PATH = 'typo3temp/assets/';

    public function __invoke(AfterResourceStorageInitializationEvent $event): void
    {
        if (!Environment::getContext()->isTesting()) {
            return;
        }

        $storage = $event->getStorage();
        $testId = TestContext::testId();
        if ('' === $testId) {
            return;
        }

        // The fallback storage's record is built after the Before event, so the
        // folder is reachable on the object only; getProcessingFolder() resolves it
        // lazily, so writing it here still counts.
        $record = new \ReflectionProperty(ResourceStorage::class, self::RECORD_PROPERTY);

        /** @var array<string, mixed> $row */
        $row = $record->getValue($storage);
        $row['processingfolder'] = self::folderFor($storage->getUid(), $testId);

        $record->setValue($storage, $row);

        self::createFolder($storage, $testId);
        self::registerFolderFilter($storage);
    }

    // Core hides only this storage's own processing folder, so every other test's
    // shows up in a listing.
    /**
     * @return true|-1 Core throws when a filter answers false
     */
    public static function filterProcessingFolders(string $itemName, string $itemIdentifier): bool|int
    {
        $prefix = ProcessedFileIsolation::folderFor('');
        if (!str_ends_with($itemIdentifier, '/') || !str_starts_with($itemName, $prefix)) {
            return true;
        }
        $testId = substr($itemName, \strlen($prefix));

        return '' === $testId || 1 === preg_match(TestContext::TEST_ID_PATTERN, $testId) ? -1 : true;
    }

    // Core creates it only when missing, so of two parallel requests one fails.
    private static function createFolder(ResourceStorage $storage, string $testId): void
    {
        $root = ProcessedFileIsolation::rootFor($storage);
        if (null === $root) {
            return;
        }

        GeneralUtility::mkdir_deep($root . '/' . ProcessedFileIsolation::folderFor($testId));
        ProcessedFileIsolation::record($root);
    }

    // The file list and the folder tree reset a storage's filters to the defaults
    // before listing, so the filter goes in the defaults as well as on this storage.
    private static function registerFolderFilter(ResourceStorage $storage): void
    {
        $filter = [self::class, 'filterProcessingFolders'];
        if (!\in_array($filter, $GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['defaultFilterCallbacks'], true)) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['defaultFilterCallbacks'][] = $filter;
        }

        if (!\in_array($filter, $storage->getFileAndFolderNameFilters(), true)) {
            $storage->addFileAndFolderNameFilter($filter);
        }
    }

    // The fallback storage is mounted on the public path, where TYPO3 processes
    // into typo3temp/assets rather than into the storage root.
    private static function folderFor(int $storageUid, string $testId): string
    {
        $folder = ProcessedFileIsolation::folderFor($testId) . '/';

        return 0 === $storageUid ? self::FALLBACK_PATH . $folder : $folder;
    }
}
