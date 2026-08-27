<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use Plan2net\PlaywrightToolkit\Database\DatabaseName;
use Plan2net\PlaywrightToolkit\Database\ProcessedFileIsolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ProcessedFileIsolationTest extends FunctionalTestCase
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
        $base = $this->storageBasePath();

        foreach ([ProcessedFileIsolation::folderFor(self::TEST_ID), 'not-ours', 'not-ours-either', '_processed_'] as $name) {
            $path = $base . '/' . $name;
            if (is_link($path)) {
                unlink($path);
                continue;
            }
            foreach (glob($path . '/*') ?: [] as $file) {
                is_link($file) || !is_dir($file) ? unlink($file) : rmdir($file);
            }
            @rmdir($path);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unexpectedTestIds(): array
    {
        return [
            'empty' => [''],
            'parent directory' => ['..'],
            'traversal' => ['../../../var'],
            'trailing traversal' => ['ABCD1234EFGH56/..'],
            'separator' => ['ABCD1234/EFGH567'],
            'too short' => ['ABCD1234EFGH567'],
            'too long' => ['ABCD1234EFGH56789'],
            'lower case' => ['abcd1234efgh5678'],
            'null byte' => ["ABCD1234EFGH567\0"],
            'wildcard' => ['*'],
        ];
    }

    #[Test]
    #[DataProvider('unexpectedTestIds')]
    public function refusesATestIdThatCouldNameSomethingElse(string $testId): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->get(ProcessedFileIsolation::class)->remove($testId);
    }

    // db is the base database and never droppable, so its folder is never ours.
    #[Test]
    public function refusesTheReplayTestId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->get(ProcessedFileIsolation::class)->remove(DatabaseName::REPLAY_TEST_ID);
    }

    /**
     * The folder TYPO3 itself processes into sits beside ours under the same
     * storage, and an empty test ID would name exactly it.
     */
    #[Test]
    public function leavesTypo3sOwnProcessingFolderAlone(): void
    {
        $shared = $this->storageBasePath() . '/_processed_';
        mkdir($shared, 0777, true);
        touch($shared . '/csm_image_0123456789.jpg');

        try {
            $this->get(ProcessedFileIsolation::class)->remove('');
        } catch (\InvalidArgumentException) {
            // refusing is the point
        }

        self::assertDirectoryExists($shared);
        self::assertFileExists($shared . '/csm_image_0123456789.jpg');
    }

    /**
     * is_dir() follows a symlink, so recursing into one would empty whatever it
     * points at — outside the folder we own.
     */
    #[Test]
    public function doesNotFollowASymlinkOutOfTheFolder(): void
    {
        $folder = $this->storageBasePath() . '/' . ProcessedFileIsolation::folderFor(self::TEST_ID);
        $outside = $this->storageBasePath() . '/not-ours';
        mkdir($folder, 0777, true);
        mkdir($outside, 0777, true);
        touch($outside . '/keep.txt');
        symlink($outside, $folder . '/escape');

        $this->get(ProcessedFileIsolation::class)->remove(self::TEST_ID);

        self::assertFileExists($outside . '/keep.txt', 'the symlink target was emptied');
        self::assertDirectoryDoesNotExist($folder);
    }

    #[Test]
    public function refusesToDeleteWhenTheFolderItselfIsASymlink(): void
    {
        $folder = $this->storageBasePath() . '/' . ProcessedFileIsolation::folderFor(self::TEST_ID);
        $outside = $this->storageBasePath() . '/not-ours-either';
        mkdir($outside, 0777, true);
        touch($outside . '/keep.txt');
        symlink($outside, $folder);

        $this->get(ProcessedFileIsolation::class)->remove(self::TEST_ID);

        self::assertFileExists($outside . '/keep.txt', 'a symlinked folder emptied its target');
    }

    private function storageBasePath(): string
    {
        $configuration = $this->get(StorageRepository::class)->getDefaultStorage()?->getConfiguration() ?? [];

        return rtrim(Environment::getPublicPath(), '/') . '/' . trim((string) ($configuration['basePath'] ?? ''), '/');
    }
}
