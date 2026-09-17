<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\SetupCache;

use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriver;
use TYPO3\CMS\Core\Core\Environment;

final class SetupCache
{
    /**
     * @var string
     */
    private const KEY = '/^[a-f0-9]{32}\z/';

    public function __construct(
        private readonly string $directory,
    ) {
    }

    public static function inVarPath(): self
    {
        return new self(Environment::getVarPath() . '/playwright/setup-cache');
    }

    /**
     * @param array<string, mixed> $state
     */
    public function store(
        TestDatabaseDriver $driver,
        string $testId,
        string $key,
        array $state,
        bool $onlyIfPresent = false,
    ): string {
        if ($onlyIfPresent && !is_file($this->fileFor($key))) {
            return 'absent';
        }

        $delta = $driver->dumpWithHashes($testId, $driver->changedTables($testId));

        $this->write(
            $key,
            new DeltaHeader(
                engine: $driver->engine(),
                templateFingerprint: (string) $driver->templateFingerprint(),
                tables: $delta['hashes'],
                state: $state,
            ),
            $delta['sql']
        );

        return 'stored';
    }

    /**
     * @return array{outcome: string, state: array<string, mixed>, detail: string}
     */
    public function restore(TestDatabaseDriver $driver, string $testId, string $key): array
    {
        $delta = $this->read($key);
        if (null === $delta) {
            return ['outcome' => 'absent', 'state' => [], 'detail' => ''];
        }

        $header = $delta['header'];
        $detail = match (true) {
            !$header->appliesTo($driver->engine(), (string) $driver->templateFingerprint()) => 'it was built against another template or engine',
            !$driver->applyDelta($testId, $delta['sql'], $header->tables) => 'applying it did not reproduce the tables it recorded',
            default => '',
        };

        if ('' !== $detail) {
            $this->remove($key);

            return ['outcome' => 'refused', 'state' => [], 'detail' => $detail];
        }

        return ['outcome' => 'applied', 'state' => $header->state, 'detail' => ''];
    }

    /**
     * @return array{header: DeltaHeader, sql: string}|null
     */
    public function read(string $key): ?array
    {
        $contents = @file_get_contents($this->fileFor($key));
        if (false === $contents) {
            return null;
        }

        [$line, $sql] = array_pad(explode("\n", $contents, 2), 2, '');
        $header = DeltaHeader::fromLine($line);

        return null === $header ? null : ['header' => $header, 'sql' => $sql];
    }

    public function write(string $key, DeltaHeader $header, string $sql): void
    {
        $target = $this->fileFor($key);
        $this->ensureDirectory();

        $temporary = $target . '.' . getmypid() . '.tmp';

        if (false === file_put_contents($temporary, $header->toLine() . "\n" . $sql)
            || !rename($temporary, $target)
        ) {
            @unlink($temporary);

            throw new \RuntimeException(sprintf('Could not write the setup cache delta "%s".', $target));
        }
    }

    public function has(string $key): bool
    {
        return is_file($this->fileFor($key));
    }

    public function remove(string $key): void
    {
        @unlink($this->fileFor($key));
    }

    private function fileFor(string $key): string
    {
        if (1 !== preg_match(self::KEY, $key)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a setup cache key.', $key));
        }

        return $this->directory . '/' . $key . '.sql';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->directory));
        }
    }
}
