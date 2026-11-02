<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Imaging;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\ProcessedFileIsolation;
use Plan2net\PlaywrightToolkit\Imaging\TestScopedProcessingFolder;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TestScopedProcessingFolderTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';

    /**
     * @var string
     */
    private const OTHER_TEST_ID = 'WXYZ9876STUV5432';

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function tearDown(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        $folder = $this->assetsPath() . '/' . ProcessedFileIsolation::folderFor(self::TEST_ID);
        foreach (glob($folder . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($folder);
        foreach ($this->fileadminFolders() as $name) {
            @rmdir($this->fileadminPath() . '/' . $name);
        }

        parent::tearDown();
    }

    #[Test]
    public function aListingLeavesOutTheFoldersOfOtherTests(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        $this->givenFileadminFolders('_processed_', ProcessedFileIsolation::folderFor(self::OTHER_TEST_ID), 'images');

        self::assertSame(['images'], $this->folderNamesIn($this->storage(1)));
    }

    // As the file list and the folder tree do before they list anything
    #[Test]
    public function aListingLeavesOutTheFoldersOfOtherTestsAfterTheFiltersAreReset(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        $this->givenFileadminFolders(ProcessedFileIsolation::folderFor(self::OTHER_TEST_ID), 'images');
        $storage = $this->storage(1);

        $storage->resetFileAndFolderNameFiltersToDefault();

        self::assertSame(['images'], $this->folderNamesIn($storage));
    }

    // Adding it again would run the filter twice for every item listed.
    #[Test]
    public function aStorageTakesTheFilterOnlyOnce(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        $this->storage(0);

        $filters = array_filter(
            $this->storage(1)->getFileAndFolderNameFilters(),
            static fn(mixed $filter): bool => [TestScopedProcessingFolder::class, 'filterProcessingFolders'] === $filter
        );

        self::assertCount(1, $filters);
    }

    #[Test]
    public function aListingWithoutATestIdKeepsCoreBehaviour(): void
    {
        $this->givenFileadminFolders('images', ProcessedFileIsolation::folderFor(self::OTHER_TEST_ID));

        self::assertContains(
            ProcessedFileIsolation::folderFor(self::OTHER_TEST_ID),
            $this->folderNamesIn($this->storage(1))
        );
    }

    // The fallback storage processes every file outside a configured storage.
    #[Test]
    public function theFallbackStorageProcessesIntoTheTestsOwnFolder(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        self::assertSame(
            '/typo3temp/assets/' . ProcessedFileIsolation::folderFor(self::TEST_ID) . '/',
            $this->processingFolderOf(0)
        );
    }

    #[Test]
    public function aRequestWithoutATestIdKeepsCoreBehaviour(): void
    {
        self::assertSame('/typo3temp/assets/_processed_/', $this->processingFolderOf(0));
    }

    #[Test]
    public function aMalformedTestIdNamesNothing(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = '../../etc';

        self::assertSame('/typo3temp/assets/_processed_/', $this->processingFolderOf(0));
    }

    // A storage row can also be written mid-test: TYPO3 writes a fileadmin one on
    // the first request that asks for a file when the table is empty.
    #[Test]
    public function aConfiguredStorageProcessesIntoTheTestsOwnFolder(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        self::assertSame(
            '/' . ProcessedFileIsolation::folderFor(self::TEST_ID) . '/',
            $this->processingFolderOf(1)
        );
    }

    #[Test]
    public function aConfiguredStorageWithoutATestIdKeepsCoreBehaviour(): void
    {
        self::assertSame('/_processed_/', $this->processingFolderOf(1));
    }

    // Core fails the second of two parallel requests that both create it.
    #[Test]
    public function theFallbackStoragesFolderExistsBeforeAnythingProcesses(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        $this->get(StorageRepository::class)->findByUid(0);

        self::assertDirectoryExists(
            $this->assetsPath() . '/' . ProcessedFileIsolation::folderFor(self::TEST_ID)
        );
    }

    #[Test]
    public function aConfiguredStoragesFolderExistsBeforeAnythingProcesses(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;

        $this->get(StorageRepository::class)->findByUid(1);

        self::assertDirectoryExists(
            rtrim(Environment::getPublicPath(), '/') . '/fileadmin/'
            . ProcessedFileIsolation::folderFor(self::TEST_ID)
        );
    }

    #[Test]
    public function cleanupRemovesWhatTheFallbackStorageProcessed(): void
    {
        $folder = $this->assetsPath() . '/' . ProcessedFileIsolation::folderFor(self::TEST_ID);
        mkdir($folder, 0777, true);
        touch($folder . '/csm_image_0123456789.jpg');

        $this->get(ProcessedFileIsolation::class)->remove(self::TEST_ID);

        self::assertDirectoryDoesNotExist($folder);
    }

    // Cleanup runs in a request that can no longer read the storage, so removing a
    // configured storage's folder needs the root this listener recorded.
    #[Test]
    public function cleanupRemovesWhatAConfiguredStorageProcessed(): void
    {
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        $this->get(StorageRepository::class)->findByUid(1);
        $folder = rtrim(Environment::getPublicPath(), '/') . '/fileadmin/'
            . ProcessedFileIsolation::folderFor(self::TEST_ID);

        $this->get(ProcessedFileIsolation::class)->remove(self::TEST_ID);

        self::assertDirectoryDoesNotExist($folder);
    }

    private function processingFolderOf(int $storageUid): string
    {
        return $this->storage($storageUid)->getProcessingFolder()->getIdentifier();
    }

    private function storage(int $storageUid): ResourceStorage
    {
        $storage = $this->get(StorageRepository::class)->findByUid($storageUid);
        self::assertNotNull($storage);

        return $storage;
    }

    private function givenFileadminFolders(string ...$names): void
    {
        foreach ($names as $name) {
            mkdir($this->fileadminPath() . '/' . $name, 0777, true);
        }
    }

    /**
     * @return list<string>
     */
    private function fileadminFolders(): array
    {
        return [
            '_processed_',
            ProcessedFileIsolation::folderFor(self::TEST_ID),
            ProcessedFileIsolation::folderFor(self::OTHER_TEST_ID),
            'images',
        ];
    }

    /**
     * @return list<string>
     */
    private function folderNamesIn(ResourceStorage $storage): array
    {
        return array_values(array_map(
            static fn(Folder $folder): string => $folder->getName(),
            $storage->getFoldersInFolder($storage->getRootLevelFolder())
        ));
    }

    private function assetsPath(): string
    {
        return rtrim(Environment::getPublicPath(), '/') . '/typo3temp/assets';
    }

    private function fileadminPath(): string
    {
        return rtrim(Environment::getPublicPath(), '/') . '/fileadmin';
    }
}
