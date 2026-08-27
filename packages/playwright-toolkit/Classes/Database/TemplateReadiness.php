<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfiguration;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriver;

final class TemplateReadiness
{
    public function __construct(
        private readonly SeedSources $seedSources,
        private readonly BorrowedConnection $borrowedConnection,
    ) {
    }

    public function assertPrepared(TestDatabaseDriver $driver, ToolkitConfiguration $configuration): void
    {
        // The fingerprint is written last, so an absent one also covers a
        // preparation that died partway through. Checked first because working out
        // the expected fingerprint needs a template to read the schema from.
        $stored = $driver->templateFingerprint();
        if (null === $stored) {
            // templateExists() asks the server, so an unreachable service or a wrong
            // password surfaces as itself instead of as "not prepared".
            throw new \RuntimeException(sprintf(
                'The Playwright test database template is %s. Run "ddev playwright-prepare" to build it.',
                $driver->templateExists() ? 'unfinished' : 'missing'
            ));
        }

        if ($stored !== $this->expectedFingerprint($driver, $configuration)) {
            throw new \RuntimeException(
                'The Playwright test database template is out of date. Run "ddev playwright-prepare" to build it.'
            );
        }
    }

    private function expectedFingerprint(
        TestDatabaseDriver $driver,
        ToolkitConfiguration $configuration,
    ): string {
        return $this->borrowedConnection->use(
            $driver->schemaConnectionOverrides(),
            fn(): string => $this->seedSources->snapshot($configuration)->fingerprint
        );
    }
}
