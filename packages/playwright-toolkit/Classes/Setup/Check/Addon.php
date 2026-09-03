<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup\Check;

use Plan2net\PlaywrightToolkit\Configuration\AddonVersion;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseService;
use Plan2net\PlaywrightToolkit\Setup\Result;

final class Addon
{
    /**
     * @param \Closure(): ?string $probeTestDatabase
     */
    public function __construct(
        private readonly string $commandFile,
        private readonly string $projectPath,
        private readonly ?string $extensionVersion,
        private readonly \Closure $probeTestDatabase,
        private readonly bool $runsElsewhere = false,
    ) {
    }

    /**
     * The service is configurable, so callers must not pass a host and port of their own.
     *
     * @return \Closure(): ?string
     */
    public static function probeFor(TestDatabaseService $service, float $timeout = 2.0): \Closure
    {
        return self::tcpProbe($service->host, $service->port, $timeout);
    }

    /**
     * @return \Closure(): ?string
     */
    public static function tcpProbe(string $host, int $port, float $timeout = 2.0): \Closure
    {
        return static function () use ($host, $port, $timeout): ?string {
            $socket = @fsockopen($host, $port, $errorNumber, $errorMessage, $timeout);
            if (false === $socket) {
                return sprintf('%s:%d does not answer: %s', $host, $port, $errorMessage);
            }

            fclose($socket);

            return null;
        };
    }

    public function run(): Result
    {
        // The add-on ships web commands, which cannot reach a run in another container.
        if (!$this->runsElsewhere && !is_file($this->commandFile)) {
            return Result::fail('the DDEV add-on is not installed');
        }

        $unreachable = ($this->probeTestDatabase)();
        if (null !== $unreachable) {
            return Result::fail($unreachable);
        }

        $drift = AddonVersion::driftInProject($this->projectPath, $this->extensionVersion);
        if (null !== $drift) {
            return Result::fail($drift);
        }

        return Result::pass($this->commandFile);
    }
}
