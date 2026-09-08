<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Imaging;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Imaging\GifBuilderOutput;

final class GifBuilderOutputTest extends TestCase
{
    private const SCOPE = 'ABCD1234EFGH5678-0f1e2d3c4b5a-';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/gifbuilder-output-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function theFinalPathAppearsOnlyOnceTheBytesAreComplete(): void
    {
        $file = $this->directory . '/_image.jpg';
        $seenWhileEncoding = null;
        $scratch = null;

        GifBuilderOutput::write($file, self::SCOPE, static function (string $path) use ($file, &$seenWhileEncoding, &$scratch): void {
            file_put_contents($path, 'bytes');
            $seenWhileEncoding = file_exists($file);
            $scratch = $path;
        });

        self::assertFalse($seenWhileEncoding);
        self::assertSame($this->directory . '/' . self::SCOPE . '_image.jpg', $scratch);
        self::assertFileDoesNotExist($this->directory . '/' . self::SCOPE . '_image.jpg');
        self::assertStringEqualsFile($file, 'bytes');
    }

    #[Test]
    public function leavesNothingBehindWhenTheWriterProducedNoFile(): void
    {
        GifBuilderOutput::write($this->directory . '/_image.jpg', self::SCOPE, static function (): void {
        });

        self::assertSame([], glob($this->directory . '/*'));
    }

    #[Test]
    public function removesAHalfWrittenFileWhenTheWriterThrows(): void
    {
        try {
            GifBuilderOutput::write($this->directory . '/_image.jpg', self::SCOPE, static function (string $path): void {
                file_put_contents($path, 'half');
                throw new \RuntimeException('encoder died');
            });
            self::fail('The exception must reach the caller.');
        } catch (\RuntimeException $exception) {
            self::assertSame('encoder died', $exception->getMessage());
        }

        self::assertSame([], glob($this->directory . '/*'));
    }
}
