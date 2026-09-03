<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup\Check;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseService;
use Plan2net\PlaywrightToolkit\Setup\Check\Addon;

final class AddonTest extends TestCase
{
    private string $projectPath;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/playwright-addon-' . uniqid('', true);
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
    public function failsWhenTheAddonCommandIsMissing(): void
    {
        $check = new Addon(
            $this->projectPath . '/nothing-here',
            $this->projectPath,
            '1.0.0',
            static fn(): ?string => null
        );

        $result = $check->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('add-on', $result->detail);
    }

    #[Test]
    public function failsWhenTheTestDatabaseServiceDoesNotAnswer(): void
    {
        $check = new Addon(
            $this->addonCommand(),
            $this->projectPath,
            '1.0.0',
            static fn(): string => 'db-test:3306 does not answer'
        );

        $result = $check->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('db-test:3306', $result->detail);
    }

    #[Test]
    public function failsWhenTheAddonAndTheExtensionAreDifferentReleases(): void
    {
        file_put_contents(
            $this->projectPath . '/.ddev/playwright-toolkit.version',
            "#ddev-generated\n1.0.0\n"
        );

        $check = new Addon(
            $this->addonCommand(),
            $this->projectPath,
            '2.0.0',
            static fn(): ?string => null
        );

        $result = $check->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('1.0.0', $result->detail);
        self::assertStringContainsString('2.0.0', $result->detail);
    }

    #[Test]
    public function passesWithTheAddonInstalledAndTheServiceAnswering(): void
    {
        $check = new Addon(
            $this->addonCommand(),
            $this->projectPath,
            '1.0.0',
            static fn(): ?string => null
        );

        self::assertTrue($check->run()->passed);
    }

    #[Test]
    public function doesNotAskForTheAddonWhenTheRunLivesInAnotherContainer(): void
    {
        $check = new Addon(
            $this->projectPath . '/nothing-here',
            $this->projectPath,
            '1.0.0',
            static fn(): ?string => null,
            runsElsewhere: true
        );

        self::assertTrue($check->run()->passed);
    }

    #[Test]
    public function probeAnswersNothingWhenTheServiceAccepts(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        self::assertIsResource($listener);
        $port = (int) explode(':', (string) stream_socket_get_name($listener, false))[1];

        try {
            self::assertNull((Addon::tcpProbe('127.0.0.1', $port))());
        } finally {
            fclose($listener);
        }
    }

    #[Test]
    public function probeTakesHostAndPortFromTheConfiguredService(): void
    {
        $service = new TestDatabaseService('configured-host', 65123, 'db', 'db');

        $reason = (string) (Addon::probeFor($service, 0.5))();

        self::assertStringContainsString('configured-host:65123', $reason);
    }

    #[Test]
    public function probeNamesTheServiceItCannotReach(): void
    {
        self::assertStringContainsString('127.0.0.1:1', (string) (Addon::tcpProbe('127.0.0.1', 1, 0.5))());
    }

    private function addonCommand(): string
    {
        $file = $this->projectPath . '/.ddev/playwright';
        file_put_contents($file, "#!/usr/bin/env bash\n");

        return $file;
    }
}
