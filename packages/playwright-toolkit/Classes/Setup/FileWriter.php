<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup;

final class FileWriter
{
    /**
     * @param array<string, string> $placeholders
     */
    public function __construct(
        private readonly string $targetDirectory,
        private readonly array $placeholders = [],
        ?string $projectPath = null,
    ) {
        // fixturesPath and testDir come from configuration, so the directory itself
        // can climb out of the project even when every file name is clean.
        if (null !== $projectPath && !self::contains($projectPath, $targetDirectory)) {
            throw new \InvalidArgumentException(
                sprintf('Refusing to write into "%s", which is outside %s.', $targetDirectory, $projectPath),
                1756900003
            );
        }
    }

    public function write(string $file): string
    {
        // A file name can come from a project's own config, which nothing here validates.
        if (\in_array('..', explode('/', $file), true) || str_starts_with($file, '/')) {
            throw new \InvalidArgumentException(
                sprintf('Refusing to write "%s" outside %s.', $file, $this->targetDirectory),
                1756900001
            );
        }

        $target = $this->targetDirectory . '/' . $file;
        if (is_file($target)) {
            return $target;
        }

        // A target may sit in a subdirectory and may start with a dot; the template never does.
        $templateFile = self::templateDirectory() . '/' . ltrim(basename($file), '.');
        if (!is_file($templateFile)) {
            throw new \InvalidArgumentException(
                sprintf('This package has no template for "%s", so it cannot write one.', $file),
                1756900002
            );
        }

        $template = (string) file_get_contents($templateFile);
        if (!is_dir(\dirname($target))) {
            mkdir(\dirname($target), 0o777, true);
        }

        $filled = str_replace(
            array_map(static fn(string $name): string => '{{' . $name . '}}', array_keys($this->placeholders)),
            array_values($this->placeholders),
            $template
        );
        file_put_contents($target, $filled);

        return $target;
    }

    private static function contains(string $projectPath, string $candidate): bool
    {
        $root = self::canonical($projectPath);

        return $root === self::canonical($candidate) || str_starts_with(self::canonical($candidate), $root . '/');
    }

    /**
     * The target need not exist yet, so realpath() cannot answer this on its own.
     */
    private static function canonical(string $path): string
    {
        $resolved = [];
        foreach (explode('/', $path) as $segment) {
            if ('..' === $segment) {
                array_pop($resolved);

                continue;
            }

            if ('.' !== $segment && '' !== $segment) {
                $resolved[] = $segment;
            }
        }

        return '/' . implode('/', $resolved);
    }

    private static function templateDirectory(): string
    {
        return \dirname(__DIR__, 2) . '/Resources/Private/Setup';
    }
}
