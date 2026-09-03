<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup\Check;

use Plan2net\PlaywrightToolkit\Setup\Result;

final class Fixtures
{
    /**
     * @var string
     */
    public const ROOT_PAGE_FIXTURE = '010-root-page.sql';

    /**
     * @param list<string> $manifest
     */
    public function __construct(
        private readonly string $fixturesPath,
        private readonly array $manifest,
        private readonly ?int $rootPageId,
    ) {
    }

    public function run(): Result
    {
        // Without it, a fixture we asked for would be written to the project root.
        if ('' === $this->fixturesPath) {
            return Result::fail('fixturesPath is not configured');
        }

        // No fixture means a template with no content, so every test gets a 404.
        if ([] === $this->manifest) {
            return Result::fail('no fixture is configured', self::ROOT_PAGE_FIXTURE);
        }

        if (!is_dir($this->fixturesPath)) {
            return Result::fail($this->fixturesPath . ' does not exist', ...$this->manifest);
        }

        $missing = array_values(array_filter(
            $this->manifest,
            fn(string $file): bool => !is_file($this->fixturesPath . '/' . $file)
        ));
        if ([] !== $missing) {
            return Result::fail('missing ' . implode(', ', $missing), ...$missing);
        }

        if (null === $this->rootPageId) {
            return Result::fail('the project configures no site, so no root page can match the fixture');
        }

        $seeded = $this->seededRootPageId();
        if (null !== $seeded && $seeded !== $this->rootPageId) {
            return Result::fail(sprintf(
                'the fixtures create page %d, but the site serves page %d',
                $seeded,
                $this->rootPageId
            ));
        }

        return Result::pass(sprintf(
            '%d fixture(s) for page %d',
            \count($this->manifest),
            (int) $this->rootPageId
        ));
    }

    private function seededRootPageId(): ?int
    {
        foreach ($this->manifest as $file) {
            $statements = (string) file_get_contents($this->fixturesPath . '/' . $file);
            if (1 === preg_match('/INSERT\s+INTO\s+`?pages`?\s*\(([^)]*)\)\s*VALUES\s*\(([^)]*)\)/i', $statements, $matches)) {
                return self::uidIn($matches[1], $matches[2]);
            }
        }

        return null;
    }

    private static function uidIn(string $columns, string $values): ?int
    {
        $names = array_map(
            static fn(string $column): string => strtolower(trim($column, " \t\n`")),
            explode(',', $columns)
        );
        $position = array_search('uid', $names, true);
        if (false === $position) {
            return null;
        }

        $value = explode(',', $values)[$position] ?? null;

        return null === $value ? null : (int) trim($value, " \t\n'\"");
    }
}
