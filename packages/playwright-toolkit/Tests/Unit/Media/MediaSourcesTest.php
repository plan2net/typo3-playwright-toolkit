<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Media\MediaSources;

final class MediaSourcesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/playwright-media-' . uniqid('', true);
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        self::removeRecursively($this->directory);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableOnlineMediaIds(): array
    {
        return [
            'empty' => [''],
            'a share URL' => ['https://youtu.be/dQw4w9WgXcQ'],
            'an embed URL' => ['//www.youtube.com/embed/dQw4w9WgXcQ'],
        ];
    }

    #[Test]
    #[DataProvider('unusableOnlineMediaIds')]
    public function refusesAnUnusableOnlineMediaId(string $id): void
    {
        $this->givenConfiguration(['campus-tour.youtube' => ['onlineMediaId' => $id]]);

        $this->expectException(\RuntimeException::class);

        MediaSources::onlineMedia($this->directory);
    }

    #[Test]
    public function namesEveryFileByItsPathRelativeToTheMediaDirectory(): void
    {
        $this->givenFile('hero.png', 'one');
        $this->givenFile('gallery/lawn-01.jpg', 'two');
        $this->givenFile('gallery/lawn-02.jpg', 'three');

        self::assertSame(
            [
                'gallery/lawn-01.jpg' => $this->directory . '/gallery/lawn-01.jpg',
                'gallery/lawn-02.jpg' => $this->directory . '/gallery/lawn-02.jpg',
                'hero.png' => $this->directory . '/hero.png',
            ],
            MediaSources::scan($this->directory)
        );
    }

    #[Test]
    public function refusesASymlinkedFile(): void
    {
        $this->givenFile('hero.png', 'one');
        symlink($this->directory . '/hero.png', $this->directory . '/alias.png');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/alias\.png/');

        MediaSources::scan($this->directory);
    }

    #[Test]
    public function refusesASymlinkedDirectory(): void
    {
        $outside = sys_get_temp_dir() . '/playwright-media-outside-' . uniqid('', true);
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/secret.png', 'not ours');
        symlink($outside, $this->directory . '/gallery');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/gallery/');

            MediaSources::scan($this->directory);
        } finally {
            unlink($outside . '/secret.png');
            rmdir($outside);
        }
    }

    #[Test]
    public function digestIdentifiesTheContentOfTheMediaDirectory(): void
    {
        $this->givenFile('hero.png', 'one');
        $first = MediaSources::digest($this->directory);

        self::assertSame($first, MediaSources::digest($this->directory), 'the digest is not stable');

        $this->givenFile('hero.png', 'two');

        self::assertNotSame($first, MediaSources::digest($this->directory), 'changed bytes did not move it');
    }

    #[Test]
    public function doesNotTreatTheRootConfigurationFileAsAFixture(): void
    {
        $this->givenFile('hero.png', 'one');
        $this->givenFile('media.json', '{}');

        self::assertSame(['hero.png'], array_keys(MediaSources::scan($this->directory)));
    }

    #[Test]
    public function digestCoversTheConfigurationFileEvenThoughItIsNoFixture(): void
    {
        $this->givenFile('hero.png', 'one');
        $this->givenFile('media.json', '{"hero.png":{"alternative":"before"}}');
        $first = MediaSources::digest($this->directory);

        $this->givenFile('media.json', '{"hero.png":{"alternative":"after"}}');

        self::assertNotSame($first, MediaSources::digest($this->directory));
    }

    #[Test]
    public function treatsAConfigurationFileNameDeeperInTheTreeAsAFixture(): void
    {
        $this->givenFile('media.json', '{}');
        $this->givenFile('gallery/media.json', '{"not":"configuration"}');

        self::assertSame(['gallery/media.json'], array_keys(MediaSources::scan($this->directory)));
    }

    #[Test]
    public function readsTheMetadataDeclaredForAFixture(): void
    {
        $this->givenFile('hero.png', 'one');
        $this->givenFile('media.json', '{"hero.png":{"title":"Hero","alternative":"A lawn"}}');

        self::assertSame(
            ['hero.png' => ['title' => 'Hero', 'alternative' => 'A lawn']],
            MediaSources::metadata($this->directory)
        );
    }

    #[Test]
    public function readsFileDatesInUtcWithoutPassingThemToMetadata(): void
    {
        $this->givenFile('hero.png', 'one');
        $this->givenConfiguration([
            'hero.png' => [
                'title' => 'Hero',
                'creationDate' => '2024-01-01',
                'modificationDate' => '2024-02-01',
            ],
        ]);
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Honolulu');

        try {
            self::assertSame(
                ['hero.png' => ['creation_date' => 1704067200, 'modification_date' => 1706745600]],
                MediaSources::fileDates($this->directory)
            );
            self::assertSame(['hero.png' => ['title' => 'Hero']], MediaSources::metadata($this->directory));
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidFileDates(): iterable
    {
        foreach (['creationDate', 'modificationDate'] as $field) {
            foreach ([
                'relative' => 'today',
                'invalid day' => '2024-02-30',
                'invalid month' => '2024-13-01',
                'empty' => '',
                'integer' => 1704067200,
                'null' => null,
                'array' => [],
                'boolean' => false,
                'short date' => '2024-1-1',
                'invalid hour' => '2024-01-01T24:00',
                'invalid minute' => '2024-01-01T14:60',
                'invalid second' => '2024-01-01T14:30:60',
                'timezone suffix' => '2024-01-01T14:30Z',
                'timezone offset' => '2024-01-01T14:30+02:00',
            ] as $case => $value) {
                yield $field . ' ' . $case => [$field, $value];
            }
        }
    }

    #[Test]
    #[DataProvider('invalidFileDates')]
    public function rejectsInvalidFileDatesBeforeReturningMetadata(string $field, mixed $value): void
    {
        $this->givenFile('hero.png', 'one');
        $this->givenFile('media.json', (string) json_encode(['hero.png' => [$field => $value]]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($field . ' of "hero.png" must use YYYY-MM-DD with an optional UTC time (THH:MM or THH:MM:SS).');

        MediaSources::metadata($this->directory);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function fileDateTimes(): array
    {
        return [
            'hour and minute' => ['2024-01-01T14:30', 1704119400],
            'seconds' => ['2024-01-01T14:30:45', 1704119445],
        ];
    }

    #[Test]
    #[DataProvider('fileDateTimes')]
    public function readsFileDatesWithTimes(string $date, int $timestamp): void
    {
        $this->givenFile('hero.png', 'one');
        $this->givenConfiguration(['hero.png' => ['creationDate' => $date, 'modificationDate' => $date]]);
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Honolulu');

        try {
            self::assertSame(
                ['hero.png' => ['creation_date' => $timestamp, 'modification_date' => $timestamp]],
                MediaSources::fileDates($this->directory)
            );
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    #[Test]
    public function resolvesFileDatesFromPrefixesAndExactEntries(): void
    {
        $this->givenFile('gallery/hero.png', 'one');
        $this->givenFile('gallery/summer/portrait.png', 'two');
        $this->givenConfiguration([
            'gallery/' => ['creationDate' => '2024-01-01', 'modificationDate' => '2024-02-01'],
            'gallery/hero.png' => ['creationDate' => '2024-03-01'],
            'gallery/summer/' => ['modificationDate' => '2024-04-01'],
            'video.youtube' => ['onlineMediaId' => 'fixture-video', 'creationDate' => '2024-05-01'],
        ]);

        self::assertSame([
            'gallery/hero.png' => ['creation_date' => 1709251200, 'modification_date' => 1706745600],
            'gallery/summer/portrait.png' => ['modification_date' => 1711929600],
            'video.youtube' => ['creation_date' => 1714521600],
        ], MediaSources::fileDates($this->directory));
        self::assertSame([], MediaSources::metadata($this->directory));
    }

    #[Test]
    public function returnsNoFileDatesWithoutAManifest(): void
    {
        $this->givenFile('hero.png', 'one');

        self::assertSame([], MediaSources::fileDates($this->directory));
    }

    #[Test]
    public function aPrefixSuppliesDefaultsThatAnExactEntryOverridesFieldByField(): void
    {
        $this->givenFile('gallery/lawn-01.jpg', 'one');
        $this->givenFile('gallery/lawn-07.jpg', 'two');
        $this->givenConfiguration([
            'gallery/' => ['title' => 'Gallery', 'alternative' => 'Campus lawn'],
            'gallery/lawn-07.jpg' => ['alternative' => 'The one with the bench'],
        ]);

        self::assertSame(
            [
                'gallery/lawn-01.jpg' => ['title' => 'Gallery', 'alternative' => 'Campus lawn'],
                'gallery/lawn-07.jpg' => ['title' => 'Gallery', 'alternative' => 'The one with the bench'],
            ],
            MediaSources::metadata($this->directory)
        );
    }

    #[Test]
    public function anExplicitlyEmptyFieldOverridesAnInheritedDefault(): void
    {
        $this->givenFile('gallery/lawn-01.jpg', 'one');
        $this->givenConfiguration([
            'gallery/' => ['alternative' => 'Campus lawn'],
            'gallery/lawn-01.jpg' => ['alternative' => ''],
        ]);

        self::assertSame(
            ['gallery/lawn-01.jpg' => ['alternative' => '']],
            MediaSources::metadata($this->directory)
        );
    }

    #[Test]
    public function theLongestPrefixWinsWithoutInheritingFromShorterOnes(): void
    {
        $this->givenFile('gallery/summer/lawn-01.jpg', 'one');
        $this->givenConfiguration([
            'gallery/' => ['title' => 'Gallery', 'alternative' => 'Campus lawn'],
            'gallery/summer/' => ['alternative' => 'Summer lawn'],
        ]);

        self::assertSame(
            ['gallery/summer/lawn-01.jpg' => ['alternative' => 'Summer lawn']],
            MediaSources::metadata($this->directory)
        );
    }

    #[Test]
    public function readsOnlineMediaEntriesThatHaveNoFileOnDisk(): void
    {
        $this->givenFile('hero.png', 'one');
        $this->givenConfiguration([
            'campus-tour.youtube' => ['onlineMediaId' => 'dQw4w9WgXcQ', 'title' => 'Campus tour'],
            'hero.png' => ['title' => 'Hero'],
        ]);

        self::assertSame(
            ['campus-tour.youtube' => 'dQw4w9WgXcQ'],
            MediaSources::onlineMedia($this->directory)
        );
    }

    #[Test]
    public function resolvesMetadataForAnOnlineEntryWithoutLeakingItsId(): void
    {
        $this->givenConfiguration([
            'campus-tour.youtube' => ['onlineMediaId' => 'dQw4w9WgXcQ', 'title' => 'Campus tour'],
        ]);

        self::assertSame(
            ['campus-tour.youtube' => ['title' => 'Campus tour']],
            MediaSources::metadata($this->directory)
        );
    }

    #[Test]
    public function refusesAnEntryNamingNeitherAFixtureNorOnlineMedia(): void
    {
        $this->givenFile('hero.png', 'one');
        $this->givenConfiguration(['portrait.jpg' => ['title' => 'Typo']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/portrait\.jpg/');

        MediaSources::metadata($this->directory);
    }

    #[Test]
    public function refusesAnOnlineMediaIdOnAPrefix(): void
    {
        $this->givenConfiguration(['gallery/' => ['onlineMediaId' => 'dQw4w9WgXcQ']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#gallery/#');

        MediaSources::metadata($this->directory);
    }

    #[Test]
    public function namesTheConfigurationFileWhenItCannotBeParsed(): void
    {
        $this->givenFile(MediaSources::CONFIGURATION_FILE, '{ not json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/media\.json/');

        MediaSources::metadata($this->directory);
    }

    private function givenFile(string $relativePath, string $contents): void
    {
        $absolute = $this->directory . '/' . $relativePath;
        @mkdir(\dirname($absolute), 0777, true);
        file_put_contents($absolute, $contents);
    }

    /**
     * @param array<string, array<string, string>> $declared
     */
    private function givenConfiguration(array $declared): void
    {
        $this->givenFile(MediaSources::CONFIGURATION_FILE, (string) json_encode($declared));
    }

    private static function removeRecursively(string $directory): void
    {
        foreach ((array) glob($directory . '/*') as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            is_dir($entry) ? self::removeRecursively($entry) : unlink($entry);
        }

        @rmdir($directory);
    }
}
