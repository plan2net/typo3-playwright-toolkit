<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup\Check;

use Plan2net\PlaywrightToolkit\Setup\Result;

final class SpecFile
{
    public function __construct(private readonly string $directory)
    {
    }

    public function run(): Result
    {
        $testDirectory = $this->directory . '/' . $this->configuredTestDir();

        foreach (self::filesIn($testDirectory) as $file) {
            if (str_ends_with($file, '.spec.ts')) {
                return Result::pass($file);
            }
        }

        $testDir = $this->configuredTestDir();

        return Result::fail('no *.spec.ts under ' . $testDir, $testDir . '/first.spec.ts');
    }

    private function configuredTestDir(): string
    {
        $config = (string) @file_get_contents($this->directory . '/playwright.config.ts');
        if (1 !== preg_match('/testDir:\s*[\'"]\.?\/?([^\'"]+)[\'"]/', $config, $matches)) {
            return 'tests';
        }

        return rtrim($matches[1], '/');
    }

    /**
     * @return list<string>
     */
    private static function filesIn(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $tree = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $directory,
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($tree as $file) {
            $files[] = (string) $file;
        }

        return $files;
    }
}
