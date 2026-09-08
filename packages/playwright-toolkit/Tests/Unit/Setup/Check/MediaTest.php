<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup\Check;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Media\MediaSources;
use Plan2net\PlaywrightToolkit\Setup\Check\Media;

final class MediaTest extends TestCase
{
    private string $mediaPath;

    protected function setUp(): void
    {
        $this->mediaPath = sys_get_temp_dir() . '/playwright-media-check-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->mediaPath . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->mediaPath)) {
            rmdir($this->mediaPath);
        }
    }

    // Media is optional, unlike the fixtures, so seeding none is nothing to report.
    #[Test]
    public function passesWhenNoMediaIsConfigured(): void
    {
        $result = (new Media('', $this->mediaPath))->run();

        self::assertTrue($result->passed);
        self::assertStringContainsString('not configured', $result->detail);
    }

    #[Test]
    public function failsWhenTheConfiguredDirectoryIsMissing(): void
    {
        $result = (new Media('tests/playwright/fixtures/media', $this->mediaPath))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString($this->mediaPath, $result->detail);
    }

    #[Test]
    public function countsWhatItFound(): void
    {
        mkdir($this->mediaPath, 0777, true);
        file_put_contents($this->mediaPath . '/hero.png', 'one');
        file_put_contents(
            $this->mediaPath . '/' . MediaSources::CONFIGURATION_FILE,
            '{"campus-tour.youtube":{"onlineMediaId":"dQw4w9WgXcQ"}}'
        );

        $result = (new Media('fixtures/media', $this->mediaPath))->run();

        self::assertTrue($result->passed);
        self::assertStringContainsString('1 file', $result->detail);
        self::assertStringContainsString('1 online', $result->detail);
    }

    // MediaSources already names what it refuses, so the check passes that on.
    #[Test]
    public function reportsWhyTheFixturesWereRefused(): void
    {
        mkdir($this->mediaPath, 0777, true);
        file_put_contents(
            $this->mediaPath . '/' . MediaSources::CONFIGURATION_FILE,
            '{"portrait.jpg":{"title":"Typo"}}'
        );

        $result = (new Media('fixtures/media', $this->mediaPath))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('portrait.jpg', $result->detail);
    }
}
