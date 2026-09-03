<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\DdevHostname;

final class DdevHostnameTest extends TestCase
{
    private string $projectPath;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/playwright-ddev-hostname-' . uniqid('', true);
        mkdir($this->projectPath . '/.ddev', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectPath . '/.ddev/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->projectPath . '/.ddev');
        rmdir($this->projectPath);
    }

    #[Test]
    public function keepsEveryLabelBelowTheDdevTld(): void
    {
        self::assertSame(
            ['flag' => 'additional-hostnames', 'entry' => 'fr.example'],
            DdevHostname::entryFor('https://fr.example.ddev.site', 'ddev.site')
        );
    }

    #[Test]
    public function keepsAHostOutsideTheDdevTldWhole(): void
    {
        self::assertSame(
            ['flag' => 'additional-fqdns', 'entry' => 'testing.acme.dev'],
            DdevHostname::entryFor('https://testing.acme.dev', 'ddev.site')
        );
    }

    #[Test]
    public function dropsThePort(): void
    {
        self::assertSame(
            ['flag' => 'additional-fqdns', 'entry' => 'localhost'],
            DdevHostname::entryFor('http://localhost:8080', 'ddev.site')
        );
    }

    #[Test]
    public function readsTheConfiguredEntries(): void
    {
        file_put_contents(
            $this->projectPath . '/.ddev/config.yaml',
            "name: example\nadditional_hostnames:\n  - one\n  - two\n"
        );

        self::assertSame(
            ['one', 'two'],
            DdevHostname::configured($this->projectPath, 'additional-hostnames')
        );
    }

    #[Test]
    public function readsNothingOutOfConfigItCannotUse(): void
    {
        file_put_contents($this->projectPath . '/.ddev/config.yaml', "additional_hostnames: one\n");
        file_put_contents($this->projectPath . '/.ddev/config.broken.yaml', "\tnot: [yaml\n");

        self::assertSame([], DdevHostname::configured($this->projectPath, 'additional-hostnames'));
    }

    #[Test]
    public function readsNothingWhenThereIsNoConfigFile(): void
    {
        self::assertSame([], DdevHostname::configured($this->projectPath, 'additional-hostnames'));
    }

    #[Test]
    public function appendsWhatTheOverrideFilesAdd(): void
    {
        file_put_contents(
            $this->projectPath . '/.ddev/config.yaml',
            "additional_hostnames:\n  - one\n"
        );
        file_put_contents(
            $this->projectPath . '/.ddev/config.local.yaml',
            "additional_hostnames:\n  - two\n"
        );

        self::assertSame(
            ['one', 'two'],
            DdevHostname::configured($this->projectPath, 'additional-hostnames')
        );
    }

    #[Test]
    public function letsAnOverrideFileReplaceTheList(): void
    {
        file_put_contents(
            $this->projectPath . '/.ddev/config.yaml',
            "additional_hostnames:\n  - one\n"
        );
        file_put_contents(
            $this->projectPath . '/.ddev/config.local.yaml',
            "override_config: true\nadditional_hostnames:\n  - two\n"
        );

        self::assertSame(
            ['two'],
            DdevHostname::configured($this->projectPath, 'additional-hostnames')
        );
    }

    #[Test]
    public function putsTheConfiguredEntriesInFrontOfTheNewOne(): void
    {
        file_put_contents(
            $this->projectPath . '/.ddev/config.yaml',
            "additional_hostnames:\n  - one\n  - two\n"
        );

        self::assertSame(
            '--additional-hostnames=one,two,example-testing',
            DdevHostname::flagFor($this->projectPath, 'https://example-testing.ddev.site', 'ddev.site')
        );
    }

    #[Test]
    public function needsNoFlagWhenTheEntryIsAlreadyConfigured(): void
    {
        file_put_contents(
            $this->projectPath . '/.ddev/config.yaml',
            "additional_hostnames:\n  - example-testing\n"
        );

        self::assertNull(
            DdevHostname::flagFor($this->projectPath, 'https://example-testing.ddev.site', 'ddev.site')
        );
    }
}
