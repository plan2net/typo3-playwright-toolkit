<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Imaging;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\ProcessedFileIsolation;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TestScopedProcessingFolderTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';

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

        parent::tearDown();
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

    #[Test]
    public function cleanupRemovesWhatTheFallbackStorageProcessed(): void
    {
        $folder = $this->assetsPath() . '/' . ProcessedFileIsolation::folderFor(self::TEST_ID);
        mkdir($folder, 0777, true);
        touch($folder . '/csm_image_0123456789.jpg');

        $this->get(ProcessedFileIsolation::class)->remove(self::TEST_ID);

        self::assertDirectoryDoesNotExist($folder);
    }

    private function processingFolderOf(int $storageUid): string
    {
        $storage = $this->get(StorageRepository::class)->findByUid($storageUid);
        self::assertNotNull($storage);

        return $storage->getProcessingFolder()->getIdentifier();
    }

    private function assetsPath(): string
    {
        return rtrim(Environment::getPublicPath(), '/') . '/typo3temp/assets';
    }
}
