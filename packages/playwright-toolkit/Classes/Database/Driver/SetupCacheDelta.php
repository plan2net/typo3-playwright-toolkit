<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\Driver;

trait SetupCacheDelta
{
    /**
     * @return list<string>
     */
    public function changedTables(string $testId): array
    {
        $template = $this->tableHashes($this->templateConnection());

        $changed = [];
        foreach ($this->tableHashes($this->connectionFor($testId)) as $table => $hash) {
            if (self::carriedInADelta($table) && ($template[$table] ?? null) !== $hash) {
                $changed[] = $table;
            }
        }

        return $changed;
    }

    /**
     * @param list<string> $tables
     *
     * @return array{sql: string, hashes: array<string, string>}
     */
    public function dumpWithHashes(string $testId, array $tables): array
    {
        $connection = $this->connectionFor($testId);
        $this->beginSnapshot($connection);

        try {
            $hashes = [];
            foreach ($tables as $table) {
                $hashes[$table] = $this->tableHash($connection, $table);
            }

            return ['sql' => $this->dumpFrom($connection, $tables), 'hashes' => $hashes];
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * @param array<string, string> $expectedHashes
     */
    public function applyDelta(string $testId, string $sql, array $expectedHashes = []): bool
    {
        $connection = $this->connectionFor($testId);
        $connection->beginTransaction();

        try {
            // A delta names its own keys like a fixture, so it needs the same catch-up.
            $this->applyFixtures($connection, ['delta' => $sql]);

            foreach ($expectedHashes as $table => $expected) {
                if ($this->tableHash($connection, $table) !== $expected) {
                    $connection->rollBack();

                    return false;
                }
            }

            $connection->commit();
        } catch (\Throwable $failure) {
            $connection->rollBack();

            throw $failure;
        }

        return true;
    }

    /** Postgres needs an isolation level for a repeatable read; the others have one by default. */
    protected function beginSnapshot(\PDO $connection): void
    {
        $connection->beginTransaction();
    }

    abstract protected function connectionFor(string $testId): \PDO;

    abstract protected function templateConnection(): \PDO;

    /**
     * @return list<string>
     */
    abstract protected function baseTables(\PDO $connection): array;

    abstract protected function tableHash(\PDO $connection, string $table): string;

    abstract protected function quoteIdentifier(string $identifier): string;

    protected function quoteValue(\PDO $connection, mixed $value): string
    {
        if (null === $value) {
            return 'NULL';
        }

        // A blob arrives as a stream, and casting one to string yields "Resource id #7".
        if (is_resource($value)) {
            return $connection->quote((string) stream_get_contents($value), \PDO::PARAM_LOB);
        }

        return $connection->quote((string) $value);
    }

    /**
     * @param array<string, string> $fixtures
     */
    abstract protected function applyFixtures(\PDO $connection, array $fixtures): void;

    /**
     * @param list<string> $tables
     */
    private function dumpFrom(\PDO $connection, array $tables): string
    {
        $statements = [];

        foreach ($tables as $table) {
            $name = $this->quoteIdentifier($table);
            $statements[] = sprintf('DELETE FROM %s;', $name);

            $rows = $connection->query(sprintf('SELECT * FROM %s', $name))->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $statements[] = sprintf(
                    'INSERT INTO %s (%s) VALUES (%s);',
                    $name,
                    implode(', ', array_map(
                        fn($column): string => $this->quoteIdentifier((string) $column),
                        array_keys($row)
                    )),
                    implode(', ', array_map(
                        fn($value): string => $this->quoteValue($connection, $value),
                        array_values($row)
                    ))
                );
            }
        }

        return implode("\n", $statements);
    }

    /**
     * @return array<string, string>
     */
    private function tableHashes(\PDO $connection): array
    {
        $hashes = [];
        foreach ($this->baseTables($connection) as $table) {
            $hashes[$table] = $this->tableHash($connection, $table);
        }

        return $hashes;
    }

    /** Restoring any of these breaks something: a warm cache hides template edits. */
    private static function carriedInADelta(string $table): bool
    {
        foreach (['cache_', 'cf_', 'sys_log', 'sys_file_processedfile', 'be_sessions'] as $excluded) {
            if (str_starts_with($table, $excluded)) {
                return false;
            }
        }

        return true;
    }
}
