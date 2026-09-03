<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup\Check;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\Check\SpecFile;

final class SpecFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/playwright-specs-' . uniqid('', true);
        mkdir($this->directory . '/tests', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/{,*/}*.*', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory . '/tests');
        if (is_dir($this->directory . '/scenarios')) {
            rmdir($this->directory . '/scenarios');
        }
        rmdir($this->directory);
    }

    #[Test]
    public function failsWhenNoScenarioIsWrittenYet(): void
    {
        $result = (new SpecFile($this->directory))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('tests', $result->detail);
        self::assertSame(['tests/first.spec.ts'], $result->missingFiles);
    }

    #[Test]
    public function passesOnAScenarioInTheDefaultDirectory(): void
    {
        file_put_contents($this->directory . '/tests/first.spec.ts', "defineScenario({})\n");

        self::assertTrue((new SpecFile($this->directory))->run()->passed);
    }

    #[Test]
    public function looksWhereTheConfigPointsTestDir(): void
    {
        mkdir($this->directory . '/scenarios');
        file_put_contents($this->directory . '/playwright.config.ts', "testDir: './scenarios',\n");
        file_put_contents($this->directory . '/scenarios/first.spec.ts', "defineScenario({})\n");

        self::assertTrue((new SpecFile($this->directory))->run()->passed);
    }
}
