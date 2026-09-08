<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Media\MediaManifest;
use Plan2net\PlaywrightToolkit\Tests\ContractFixture;

final class MediaManifestTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/playwright-manifest-' . uniqid('', true);
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . '/*') as $file) {
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
    }

    #[Test]
    public function writesTheShapeTheContractFixtureDescribes(): void
    {
        $file = $this->directory . '/media.json';

        (new MediaManifest($file))->write(['hero.png' => 900001, 'gallery/lawn-01.jpg' => 900002]);

        self::assertJsonStringEqualsJsonString(
            ContractFixture::contents('media-manifest'),
            (string) file_get_contents($file)
        );
    }

    #[Test]
    public function removesAPreviousManifest(): void
    {
        $file = $this->directory . '/media.json';
        $manifest = new MediaManifest($file);
        $manifest->write(['hero.png' => 900001]);

        $manifest->remove();

        self::assertFileDoesNotExist($file);
    }

    #[Test]
    public function removingIsSilentWhenThereIsNoManifest(): void
    {
        $file = $this->directory . '/media.json';

        (new MediaManifest($file))->remove();

        self::assertFileDoesNotExist($file);
    }

    #[Test]
    public function failsLoudlyWhenTheManifestCannotBeWritten(): void
    {
        // A file where a directory would go, so creating the directory fails too.
        file_put_contents($this->directory . '/blocked', 'not a directory');
        $manifest = new MediaManifest($this->directory . '/blocked/media.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/media\.json/');

        $manifest->write(['hero.png' => 900001]);
    }

    #[Test]
    public function writesTheNameToUidMapAsJson(): void
    {
        $file = $this->directory . '/media.json';

        (new MediaManifest($file))->write(['hero.png' => 900001, 'gallery/lawn-01.jpg' => 900002]);

        self::assertJsonStringEqualsJsonString(
            '{"hero.png":900001,"gallery/lawn-01.jpg":900002}',
            (string) file_get_contents($file)
        );
    }
}
