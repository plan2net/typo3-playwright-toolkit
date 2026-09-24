<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup\Check;

use Plan2net\PlaywrightToolkit\Setup\Result;

final class PlaywrightConfig
{
    public function __construct(
        private readonly string $directory,
        private readonly string $testingUrl,
        private readonly string $recordedTestingUrlFile,
    ) {
    }

    public function run(): Result
    {
        $missing = array_values(array_filter(
            ['playwright.config.ts', 'tsconfig.json', '.gitignore'],
            fn(string $file): bool => !is_file($this->directory . '/' . $file)
        ));
        if ([] !== $missing) {
            return Result::fail(implode(' and ', $missing) . ' missing', ...$missing);
        }

        // A config for another host is the user's to change; we would overwrite their work.
        $config = $this->directory . '/playwright.config.ts';
        if (!str_contains((string) file_get_contents($config), $this->testingUrl)
            && $this->recordedTestingUrl() !== $this->testingUrl) {
            return Result::fail('playwright.config.ts does not name ' . $this->testingUrl);
        }

        return Result::pass($this->testingUrl);
    }

    // Every test run writes this file, so it shows the URL even when the config reads it from an env var.
    private function recordedTestingUrl(): ?string
    {
        $recorded = json_decode((string) @file_get_contents($this->recordedTestingUrlFile), true);

        return \is_array($recorded) && \is_string($recorded['testingURL'] ?? null) ? $recorded['testingURL'] : null;
    }
}
