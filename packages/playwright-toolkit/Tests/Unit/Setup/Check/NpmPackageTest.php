<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup\Check;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\Check\NpmPackage;

final class NpmPackageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/playwright-npm-' . uniqid('', true);
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        $package = $this->directory . '/node_modules/@plan2net/typo3-playwright-toolkit';
        foreach ([$package . '/package.json', $this->directory . '/package.json'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach ([$package, \dirname($package), \dirname($package, 2), $this->directory] as $path) {
            if (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    #[Test]
    public function failsWhenThePackageIsNotInstalled(): void
    {
        $result = (new NpmPackage($this->directory, '2.0.0'))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('@plan2net/typo3-playwright-toolkit', $result->detail);
    }

    #[Test]
    public function failsWhenTheInstalledPackageIsAnotherRelease(): void
    {
        $this->installPackage('1.0.0');

        $result = (new NpmPackage($this->directory, '2.0.0'))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('1.0.0', $result->detail);
        self::assertStringContainsString('2.0.0', $result->detail);
    }

    #[Test]
    public function passesOnPresenceWhenTheExtensionIsADevelopmentCheckout(): void
    {
        $this->installPackage('1.0.0');

        $result = (new NpmPackage($this->directory, 'dev-main'))->run();

        self::assertTrue($result->passed);
        self::assertStringContainsString('dev-main', $result->detail);
    }

    #[Test]
    public function failsWhenThePackageFileIsNotEsm(): void
    {
        $this->installPackage('2.0.0');
        file_put_contents($this->directory . '/package.json', json_encode(['name' => 'tests']));

        $result = (new NpmPackage($this->directory, '2.0.0'))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('type=module', $result->detail);
    }

    #[Test]
    public function passesWhenBothSidesAreTheSameRelease(): void
    {
        $this->installPackage('2.0.0');

        self::assertTrue((new NpmPackage($this->directory, 'v2.0.0'))->run()->passed);
    }

    #[Test]
    public function passesWhenTheRunLivesInAnotherContainer(): void
    {
        $result = (new NpmPackage($this->directory, '2.0.0', runsElsewhere: true))->run();

        self::assertTrue($result->passed);
        self::assertStringContainsString('another container', $result->detail);
    }

    private function installPackage(string $version): void
    {
        $package = $this->directory . '/node_modules/@plan2net/typo3-playwright-toolkit';
        mkdir($package, 0777, true);
        file_put_contents($package . '/package.json', json_encode(['version' => $version]));
    }
}
