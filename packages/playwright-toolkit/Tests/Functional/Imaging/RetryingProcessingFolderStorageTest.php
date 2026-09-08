<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Imaging;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Compatibility\RetryingProcessingFolderStorage;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RetryingProcessingFolderStorageTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const PROCESSING_FOLDER = '_processed_ABCD1234EFGH5678';

    /**
     * @var array<string, mixed>
     */
    private const STORAGE_RECORD = [
        'uid' => 1,
        'is_writable' => true,
        'is_public' => true,
        'is_browsable' => true,
        'is_online' => true,
        'processingfolder' => self::PROCESSING_FOLDER . '/',
    ];

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    #[Test]
    public function takesAFolderAParallelRequestCreatedFirst(): void
    {
        $storage = $this->storage();
        mkdir(rtrim(Environment::getPublicPath(), '/') . '/fileadmin/beat-us-to-it');

        $folder = $storage->createFolder('beat-us-to-it', $storage->getRootLevelFolder(false));

        self::assertSame('/beat-us-to-it/', $folder->getIdentifier());
    }

    #[Test]
    public function takesTheNestedFolderAParallelRequestCreatedFirst(): void
    {
        $storage = $this->storageLosingTheFirstFolderRace();
        $file = new File(['identifier' => '/image.jpg', 'name' => 'image.jpg'], $storage);

        $folder = $storage->getProcessingFolder($file);

        self::assertStringStartsWith('/' . self::PROCESSING_FOLDER . '/', $folder->getIdentifier());
        self::assertNotSame('/' . self::PROCESSING_FOLDER . '/', $folder->getIdentifier());
    }

    private function storage(): RetryingProcessingFolderStorage
    {
        return new RetryingProcessingFolderStorage(
            new LocalDriver(['basePath' => 'fileadmin/', 'pathType' => 'relative']),
            self::STORAGE_RECORD
        );
    }

    // The driver creates the first missing folder while still reporting it missing,
    // which is the window a parallel request gets in through.
    private function storageLosingTheFirstFolderRace(): RetryingProcessingFolderStorage
    {
        $basePath = rtrim(Environment::getPublicPath(), '/') . '/fileadmin/';
        mkdir($basePath . self::PROCESSING_FOLDER, 0777, true);

        $raced = false;
        $driver = $this->getMockBuilder(LocalDriver::class)
            ->setConstructorArgs([['basePath' => 'fileadmin/', 'pathType' => 'relative']])
            ->onlyMethods(['folderExistsInFolder'])
            ->getMock();
        $driver->expects(self::atLeastOnce())->method('folderExistsInFolder')->willReturnCallback(
            function ($folderName, $folderIdentifier) use ($basePath, &$raced): bool {
                $path = $basePath . ltrim((string) $folderIdentifier, '/') . $folderName;

                if (!$raced && !is_dir($path)) {
                    $raced = true;
                    mkdir($path);

                    return false;
                }

                return is_dir($path);
            }
        );

        return new RetryingProcessingFolderStorage($driver, self::STORAGE_RECORD);
    }
}
