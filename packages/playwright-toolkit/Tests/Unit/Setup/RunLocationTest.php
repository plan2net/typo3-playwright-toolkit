<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\RunLocation;

final class RunLocationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/playwright-run-location-' . uniqid('', true);
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory . '/node_modules')) {
            rmdir($this->directory . '/node_modules');
        }
        rmdir($this->directory);
    }

    #[Test]
    public function saysTheRunIsElsewhereWhenTheSecretIsSharedAndNoPackagesAreHere(): void
    {
        self::assertTrue(RunLocation::inAnotherContainer('a-shared-secret', $this->directory));
    }

    #[Test]
    public function saysTheRunIsHereWhenPackagesAreInstalledHere(): void
    {
        mkdir($this->directory . '/node_modules');

        self::assertFalse(RunLocation::inAnotherContainer('a-shared-secret', $this->directory));
    }

    #[Test]
    public function saysTheRunIsHereOnAFreshProject(): void
    {
        self::assertFalse(RunLocation::inAnotherContainer(null, $this->directory));
    }
}
