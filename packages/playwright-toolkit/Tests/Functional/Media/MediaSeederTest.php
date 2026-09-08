<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Media;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Media\MediaSeeder;
use Plan2net\PlaywrightToolkit\Media\MediaSources;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MediaSeederTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const PNG_4X3 = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAADCAIAAAA7ljmRAAAACXBIWXMAAA7EAAAOxAGVKw4b'
        . 'AAAAFElEQVQImWMU6YligAEmBiSAwgEAJHgBAMXOa18AAAAASUVORK5CYII=';

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private string $mediaPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaPath = rtrim(Environment::getVarPath(), '/') . '/media-fixtures';
        GeneralUtility::rmdir($this->mediaPath, true);
        GeneralUtility::mkdir_deep($this->mediaPath);
    }

    #[Test]
    public function provisionsItsOwnStorageWhenNoneIsConfigured(): void
    {
        $this->givenImage('hero.png');

        $this->get(MediaSeeder::class)->seed($this->mediaPath);

        $storage = $this->get(StorageRepository::class)->findByUid(MediaSeeder::STORAGE_UID);
        self::assertInstanceOf(ResourceStorage::class, $storage);
        self::assertSame('fileadmin/playwright-media/', $storage->getConfiguration()['basePath'] ?? null);
    }

    #[Test]
    public function indexesIntoAStorageTheProjectAlreadyDeclares(): void
    {
        $this->givenImage('hero.png');
        $this->givenImage('gallery/lawn-01.png');

        $this->get(MediaSeeder::class)->seed($this->mediaPath, '1:/playwright-media/');

        self::assertSame(
            ['/playwright-media/gallery/lawn-01.png', '/playwright-media/hero.png'],
            $this->indexedIdentifiers(1)
        );
    }

    #[Test]
    public function keysTheMapWithoutTheFolderAndLeavesTheRestOfTheStorageAlone(): void
    {
        $this->givenImage('hero.png');
        $unrelated = rtrim(Environment::getPublicPath(), '/') . '/fileadmin/unrelated.png';
        file_put_contents($unrelated, (string) base64_decode(self::PNG_4X3, true));
        $storage = $this->get(StorageRepository::class)->findByUid(1);
        self::assertInstanceOf(ResourceStorage::class, $storage);
        $storage->getFile('/unrelated.png');

        $seeder = $this->get(MediaSeeder::class);
        $seeder->seed($this->mediaPath, '1:/playwright-media/');

        self::assertSame(['hero.png'], array_keys($seeder->readMap('1:/playwright-media/')));
        self::assertFileExists($unrelated, 'clearing reached outside the configured folder');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableMediaStorages(): array
    {
        return [
            'a storage root' => ['1:/'],
            'no folder at all' => ['1:'],
            'a plain path' => ['fileadmin/playwright-media'],
            'a storage that does not exist' => ['9001:/playwright-media/'],
        ];
    }

    #[Test]
    #[DataProvider('unusableMediaStorages')]
    public function refusesAnUnusableMediaStorage(string $mediaStorage): void
    {
        $this->givenImage('hero.png');

        $this->expectException(\RuntimeException::class);

        $this->get(MediaSeeder::class)->seed($this->mediaPath, $mediaStorage);
    }

    #[Test]
    public function refusesAStorageThatIsNotLocal(): void
    {
        $this->givenStorageRow(901, 'Remote', 'fileadmin/', 1);
        $this->givenImage('hero.png');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Remote/');

        $this->get(MediaSeeder::class)->seed($this->mediaPath, '901:/playwright-media/');
    }

    // Case folding in sanitizeFileName() arrived in 13.4. On an older core the
    // driver keeps Hero.png, so there is nothing for the guard to refuse.
    #[Test]
    public function refusesANameACaseInsensitiveStorageWouldLowercase(): void
    {
        $this->givenStorageRow(902, 'Local', 'fileadmin/', 0);
        $storage = $this->get(StorageRepository::class)->findByUid(902);
        self::assertInstanceOf(ResourceStorage::class, $storage);
        if ('Hero.png' === $storage->sanitizeFileName('Hero.png', $storage->getRootLevelFolder())) {
            self::markTestSkipped('This core does not fold case in sanitizeFileName().');
        }

        $this->givenImage('Hero.png');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Hero\.png/');

        $this->get(MediaSeeder::class)->seed($this->mediaPath, '902:/playwright-media/');
    }

    /**
     * A borrowed connection swaps the database without clearing the repository's
     * row cache, so a warm cache names the wrong directory.
     */
    #[Test]
    public function doesNotTrustAStorageRowCachedBeforeTheDatabaseChanged(): void
    {
        $public = rtrim(Environment::getPublicPath(), '/');
        GeneralUtility::mkdir_deep($public . '/fileadmin/stale');
        GeneralUtility::mkdir_deep($public . '/fileadmin/fresh');
        $this->givenStorageRow(903, 'Local', 'fileadmin/stale/', 1);
        $this->get(StorageRepository::class)->findByUid(903);
        $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file_storage')
            ->executeStatement(
                "UPDATE sys_file_storage SET configuration = REPLACE(configuration, 'fileadmin/stale/', 'fileadmin/fresh/') WHERE uid = 903"
            );
        $this->givenImage('hero.png');

        $this->get(MediaSeeder::class)->seed($this->mediaPath, '903:/media/');

        self::assertFileExists($public . '/fileadmin/fresh/media/hero.png');
        self::assertFileDoesNotExist($public . '/fileadmin/stale/media/hero.png');
    }

    #[Test]
    public function indexesEveryFixtureAtTheMirroredIdentifier(): void
    {
        $this->givenImage('hero.png');
        $this->givenImage('gallery/lawn-01.png');

        $this->get(MediaSeeder::class)->seed($this->mediaPath);

        self::assertSame(['/gallery/lawn-01.png', '/hero.png'], $this->indexedIdentifiers());
    }

    #[Test]
    public function mapsEveryNameToTheUidTheDatabaseGaveIt(): void
    {
        $this->givenImage('hero.png');
        $this->givenImage('gallery/lawn-01.png');
        $seeder = $this->get(MediaSeeder::class);
        $seeder->seed($this->mediaPath);

        self::assertSame(
            [
                'gallery/lawn-01.png' => $this->uidOf('/gallery/lawn-01.png'),
                'hero.png' => $this->uidOf('/hero.png'),
            ],
            $seeder->readMap()
        );
    }

    #[Test]
    public function createsAnOnlineMediaPseudoFileFromItsIdAlone(): void
    {
        $this->givenConfiguration([
            'campus-tour.youtube' => ['onlineMediaId' => 'dQw4w9WgXcQ', 'title' => 'Campus tour'],
        ]);

        $this->get(MediaSeeder::class)->seed($this->mediaPath);

        self::assertSame(['/campus-tour.youtube'], $this->indexedIdentifiers());
        self::assertSame(
            ['video/youtube', 4, 'dQw4w9WgXcQ'],
            [
                $this->columnOf('/campus-tour.youtube', 'mime_type'),
                (int) $this->columnOf('/campus-tour.youtube', 'type'),
                trim((string) file_get_contents($this->targetPath() . 'campus-tour.youtube')),
            ]
        );
        self::assertSame(['Campus tour', ''], $this->metadataOf('/campus-tour.youtube'));
    }

    #[Test]
    public function reportsAnIntactDestinationAsMatching(): void
    {
        $this->givenImage('hero.png');
        $this->givenConfiguration(['campus-tour.youtube' => ['onlineMediaId' => 'dQw4w9WgXcQ']]);
        $seeder = $this->get(MediaSeeder::class);
        $seeder->seed($this->mediaPath);

        self::assertTrue($seeder->destinationMatches($this->mediaPath));
    }

    #[Test]
    public function reportsAModifiedPublishedFileAsNotMatching(): void
    {
        $this->givenImage('hero.png');
        $seeder = $this->get(MediaSeeder::class);
        $seeder->seed($this->mediaPath);

        file_put_contents($this->targetPath() . 'hero.png', 'truncated');

        self::assertFalse($seeder->destinationMatches($this->mediaPath));
    }

    #[Test]
    public function reportsADeletedPublishedFileAsNotMatching(): void
    {
        $this->givenImage('hero.png');
        $seeder = $this->get(MediaSeeder::class);
        $seeder->seed($this->mediaPath);

        unlink($this->targetPath() . 'hero.png');

        self::assertFalse($seeder->destinationMatches($this->mediaPath));
    }

    #[Test]
    public function reportsAnAlteredPseudoFileAsNotMatching(): void
    {
        $this->givenConfiguration(['campus-tour.youtube' => ['onlineMediaId' => 'dQw4w9WgXcQ']]);
        $seeder = $this->get(MediaSeeder::class);
        $seeder->seed($this->mediaPath);

        file_put_contents($this->targetPath() . 'campus-tour.youtube', 'someOtherId');

        self::assertFalse($seeder->destinationMatches($this->mediaPath));
    }

    #[Test]
    public function refusesAFixtureNameTheStorageWouldRewrite(): void
    {
        $this->givenImage('hero image.png');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/hero image\.png.*hero_image\.png/');

        $this->get(MediaSeeder::class)->seed($this->mediaPath);
    }

    #[Test]
    public function refusesAFolderNameTheStorageWouldRewrite(): void
    {
        $this->givenImage('summer gallery/lawn-01.png');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/summer gallery/');

        $this->get(MediaSeeder::class)->seed($this->mediaPath);
    }

    #[Test]
    public function refusesAMediaDirectoryThatIsTheTarget(): void
    {
        GeneralUtility::mkdir_deep($this->targetPath());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/playwright-media/');

        $this->get(MediaSeeder::class)->seed($this->targetPath());
    }

    #[Test]
    public function refusesAMediaDirectoryInsideTheTarget(): void
    {
        $inside = $this->targetPath() . 'sources';
        GeneralUtility::mkdir_deep($inside);
        file_put_contents($inside . '/hero.png', (string) base64_decode(self::PNG_4X3, true));

        try {
            $this->get(MediaSeeder::class)->seed($inside);
            self::fail('Seeding a media directory inside the target was allowed.');
        } catch (\RuntimeException) {
            // refusing is the point
        }

        self::assertFileExists($inside . '/hero.png', 'clearing the target destroyed the sources');
    }

    #[Test]
    public function refusesAMediaDirectoryThatContainsTheTarget(): void
    {
        GeneralUtility::mkdir_deep($this->targetPath());

        $this->expectException(\RuntimeException::class);

        $this->get(MediaSeeder::class)->seed(rtrim(Environment::getPublicPath(), '/') . '/fileadmin');
    }

    #[Test]
    public function keepsTheCommittedSourceWhereItIs(): void
    {
        $this->givenImage('hero.png');

        $this->get(MediaSeeder::class)->seed($this->mediaPath);

        self::assertFileExists($this->mediaPath . '/hero.png');
    }

    #[Test]
    public function writesTheDeclaredMetadata(): void
    {
        $this->givenImage('hero.png');
        $this->givenConfiguration(['hero.png' => ['title' => 'Hero', 'alternative' => 'A lawn']]);

        $this->get(MediaSeeder::class)->seed($this->mediaPath);

        self::assertSame(['Hero', 'A lawn'], $this->metadataOf('/hero.png'));
    }

    #[Test]
    public function clearsWhatAnEarlierRunLeftInTheTarget(): void
    {
        $this->givenImage('hero.png');
        $this->givenImage('gallery/lawn-01.png');
        $this->get(MediaSeeder::class)->seed($this->mediaPath);

        GeneralUtility::rmdir($this->mediaPath, true);
        GeneralUtility::mkdir_deep($this->mediaPath);
        $this->givenImage('portrait.png');
        $this->get(MediaSeeder::class)->seed($this->mediaPath);

        self::assertSame(['/portrait.png'], $this->indexedIdentifiers());
        self::assertFileDoesNotExist($this->targetPath() . 'hero.png');
        self::assertDirectoryDoesNotExist($this->targetPath() . 'gallery');
    }

    private function givenStorageRow(int $uid, string $driver, string $basePath, int $caseSensitive): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_storage')->insert(
            'sys_file_storage',
            [
                'uid' => $uid,
                'pid' => 0,
                'name' => 'Test storage ' . $uid,
                'driver' => $driver,
                'configuration' => sprintf(
                    '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">'
                    . '<field index="basePath"><value index="vDEF">%s</value></field>'
                    . '<field index="pathType"><value index="vDEF">relative</value></field>'
                    . '<field index="caseSensitive"><value index="vDEF">%d</value></field>'
                    . '</language></sheet></data></T3FlexForms>',
                    $basePath,
                    $caseSensitive
                ),
                'is_default' => 0,
                'is_browsable' => 1,
                'is_public' => 1,
                'is_writable' => 1,
                'is_online' => 1,
            ]
        );
        $this->get(StorageRepository::class)->flush();
    }

    private function columnOf(string $identifier, string $column): string
    {
        return (string) $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->executeQuery(
                sprintf('SELECT %s FROM sys_file WHERE identifier = ? AND storage = ?', $column),
                [$identifier, MediaSeeder::STORAGE_UID]
            )
            ->fetchOne();
    }

    private function uidOf(string $identifier): int
    {
        return (int) $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->executeQuery(
                'SELECT uid FROM sys_file WHERE identifier = ? AND storage = ?',
                [$identifier, MediaSeeder::STORAGE_UID]
            )
            ->fetchOne();
    }

    /**
     * @return list<string>
     */
    private function metadataOf(string $identifier): array
    {
        $row = $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata')
            ->executeQuery(
                'SELECT m.title, m.alternative FROM sys_file_metadata m'
                . ' INNER JOIN sys_file f ON f.uid = m.file WHERE f.identifier = ? AND f.storage = ?',
                [$identifier, MediaSeeder::STORAGE_UID]
            )
            ->fetchAssociative();

        return [(string) ($row['title'] ?? ''), (string) ($row['alternative'] ?? '')];
    }

    /**
     * @param array<string, array<string, string>> $declared
     */
    private function givenConfiguration(array $declared): void
    {
        $this->givenFile(MediaSources::CONFIGURATION_FILE, (string) json_encode($declared));
    }

    private function targetPath(): string
    {
        return rtrim(Environment::getPublicPath(), '/') . '/' . MediaSeeder::BASE_PATH;
    }

    /**
     * @return list<string>
     */
    private function indexedIdentifiers(int $storage = MediaSeeder::STORAGE_UID): array
    {
        $rows = $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->executeQuery(
                'SELECT identifier FROM sys_file WHERE storage = ? ORDER BY identifier',
                [$storage]
            )
            ->fetchFirstColumn();

        return array_map(strval(...), $rows);
    }

    private function givenImage(string $relativePath): void
    {
        $this->givenFile($relativePath, (string) base64_decode(self::PNG_4X3, true));
    }

    private function givenFile(string $relativePath, string $contents): void
    {
        $absolute = $this->mediaPath . '/' . $relativePath;
        @mkdir(\dirname($absolute), 0777, true);
        file_put_contents($absolute, $contents);
    }
}
