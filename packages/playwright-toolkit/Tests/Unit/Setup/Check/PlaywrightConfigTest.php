<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup\Check;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\Check\PlaywrightConfig;

final class PlaywrightConfigTest extends TestCase
{
    private const TESTING_URL = 'https://example-testing.ddev.site';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/playwright-config-' . uniqid('', true);
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
    }

    #[Test]
    public function failsWhenTheConfigIsMissing(): void
    {
        $result = (new PlaywrightConfig($this->directory, self::TESTING_URL))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('playwright.config.ts', $result->detail);
    }

    #[Test]
    public function failsWhenTheConfigNamesAnotherTestingUrl(): void
    {
        file_put_contents(
            $this->directory . '/playwright.config.ts',
            "defineToolkitConfig({ testingURL: 'https://other-testing.ddev.site' })\n"
        );
        file_put_contents($this->directory . '/tsconfig.json', "{}\n");
        file_put_contents($this->directory . '/.gitignore', "test-results/\n");

        $result = (new PlaywrightConfig($this->directory, self::TESTING_URL))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString(self::TESTING_URL, $result->detail);
        self::assertSame([], $result->missingFiles);
    }

    #[Test]
    public function failsWhenTheTypescriptConfigIsMissing(): void
    {
        file_put_contents(
            $this->directory . '/playwright.config.ts',
            "defineToolkitConfig({ testingURL: '" . self::TESTING_URL . "' })\n"
        );
        file_put_contents($this->directory . '/.gitignore', "test-results/\n");

        $result = (new PlaywrightConfig($this->directory, self::TESTING_URL))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('tsconfig.json', $result->detail);
    }

    #[Test]
    public function namesEveryMissingFile(): void
    {
        file_put_contents(
            $this->directory . '/playwright.config.ts',
            "defineToolkitConfig({ testingURL: '" . self::TESTING_URL . "' })\n"
        );

        $detail = (new PlaywrightConfig($this->directory, self::TESTING_URL))->run()->detail;

        self::assertStringContainsString('tsconfig.json', $detail);
        self::assertStringContainsString('.gitignore', $detail);
    }

    #[Test]
    public function asksForEveryMissingFileByName(): void
    {
        $result = (new PlaywrightConfig($this->directory, self::TESTING_URL))->run();

        self::assertSame(
            ['playwright.config.ts', 'tsconfig.json', '.gitignore'],
            $result->missingFiles
        );
    }

    #[Test]
    public function passesWhenEveryFileIsThere(): void
    {
        file_put_contents(
            $this->directory . '/playwright.config.ts',
            "defineToolkitConfig({ testingURL: '" . self::TESTING_URL . "' })\n"
        );
        file_put_contents($this->directory . '/tsconfig.json', "{}\n");
        file_put_contents($this->directory . '/.gitignore', "test-results/\n");

        self::assertTrue((new PlaywrightConfig($this->directory, self::TESTING_URL))->run()->passed);
    }
}
