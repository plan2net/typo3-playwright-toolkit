<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Configuration\AddonVersion;

final class AddonVersionTest extends TestCase
{
    #[Test]
    public function readsTheVersionPastTheDdevMarker(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'addon');
        file_put_contents($file, "#ddev-generated\n0.6.0\n");

        try {
            self::assertSame('0.6.0', AddonVersion::inFile($file));
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function namesBothReleasesWhenTheyDiffer(): void
    {
        $drift = (string) AddonVersion::drift('0.5.0', 'v0.6.0');

        self::assertStringContainsString('0.5.0', $drift);
        self::assertStringContainsString('0.6.0', $drift);
    }

    // Composer keeps the tag as written, so one side carries the v.
    #[Test]
    public function saysNothingWhenBothAreTheSameRelease(): void
    {
        self::assertNull(AddonVersion::drift('0.6.0', 'v0.6.0'));
    }

    // A dev checkout of the toolkit itself, where the two can never match.
    #[Test]
    public function saysNothingWhenTheExtensionIsADevelopmentCheckout(): void
    {
        self::assertNull(AddonVersion::drift('0.6.0', 'dev-main'));
    }

    // No version file means no add-on, which a project that does not run DDEV is
    // entitled to. Telling it to run `ddev add-on get` is advice it cannot follow.
    #[Test]
    public function saysNothingWhenNoAddonIsInstalled(): void
    {
        self::assertNull(AddonVersion::drift(null, '0.6.0'));
    }

    #[Test]
    public function readsTheAddonVersionOutOfTheProjectsDdevDirectory(): void
    {
        $project = sys_get_temp_dir() . '/' . uniqid('project', true);
        mkdir($project . '/.ddev', 0o777, true);
        file_put_contents($project . '/.ddev/playwright-toolkit.version', "#ddev-generated\n0.5.0\n");

        try {
            self::assertStringContainsString('0.5.0', (string) AddonVersion::driftInProject($project, '0.6.0'));
        } finally {
            unlink($project . '/.ddev/playwright-toolkit.version');
            rmdir($project . '/.ddev');
            rmdir($project);
        }
    }
}
