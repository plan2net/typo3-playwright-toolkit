<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup\Check;

use Plan2net\PlaywrightToolkit\Setup\Result;

final class PlaywrightConfig
{
    public function __construct(
        private readonly string $directory,
        private readonly string $testingUrl,
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
        if (!str_contains((string) file_get_contents($config), $this->testingUrl)) {
            return Result::fail('playwright.config.ts does not name ' . $this->testingUrl);
        }

        return Result::pass($this->testingUrl);
    }
}
