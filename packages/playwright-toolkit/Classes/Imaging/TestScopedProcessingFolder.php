<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Imaging;

use Plan2net\PlaywrightToolkit\Database\ProcessedFileIsolation;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\Event\AfterResourceStorageInitializationEvent;
use TYPO3\CMS\Core\Resource\ResourceStorage;

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
    }

    // The fallback storage is mounted on the public path, where TYPO3 processes
    // into typo3temp/assets rather than into the storage root.
    private static function folderFor(int $storageUid, string $testId): string
    {
        $folder = ProcessedFileIsolation::folderFor($testId) . '/';

        return 0 === $storageUid ? self::FALLBACK_PATH . $folder : $folder;
    }
}
