<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup\Check;

use Plan2net\PlaywrightToolkit\Setup\Result;

final class BrowsersPath
{
    /**
     * @var string
     */
    private const PROJECT_ROOT = '/var/www/html';

    public function __construct(
        private readonly ?string $path,
        private readonly ?string $browserServer = null,
        private readonly bool $runsElsewhere = false,
        private readonly string $projectRoot = self::PROJECT_ROOT,
    ) {
    }

    public function run(): Result
    {
        if ($this->runsElsewhere) {
            return Result::pass('the test run and its browsers live in another container');
        }

        // Playwright drives that server, so no local browser path is needed.
        if (null !== $this->browserServer && '' !== $this->browserServer) {
            return Result::pass('browsers run on ' . $this->browserServer);
        }

        if (null === $this->path || '' === $this->path) {
            return Result::fail('PLAYWRIGHT_BROWSERS_PATH is not set');
        }

        // DDEV drops anything outside the project on the next rebuild.
        if (!str_starts_with($this->path, $this->projectRoot . '/')) {
            return Result::fail($this->path . ' is outside ' . $this->projectRoot);
        }

        $installed = glob($this->path . '/chromium-*', GLOB_ONLYDIR) ?: [];
        if ([] === $installed) {
            return Result::fail('no chromium in ' . $this->path);
        }

        return Result::pass(basename($installed[0]) . ' in ' . $this->path);
    }
}
