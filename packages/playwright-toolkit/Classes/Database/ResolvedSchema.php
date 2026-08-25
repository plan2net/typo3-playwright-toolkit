<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use Doctrine\DBAL\Schema\Table;
use Plan2net\PlaywrightToolkit\Compatibility\ResolvedSchemaTables;

// TCA adds columns to ext_tables.sql, so hashing the statements alone would miss
// a TCA-only change.
final class ResolvedSchema
{
    public function __construct(
        private readonly ResolvedSchemaTables $tables,
    ) {
    }

    /**
     * @param list<string> $statements
     */
    public function fingerprintSource(array $statements): string
    {
        $described = [];
        foreach ($this->tables->parse($statements) as $table) {
            $described[$table->getName()] = $this->describeTable($table);
        }
        ksort($described);

        return implode("\n", $described);
    }

    private function describeTable(Table $table): string
    {
        $columns = [];
        foreach ($table->getColumns() as $column) {
            $columns[] = 'COLUMN ' . $column->getName() . ' ' . $this->describeOptions($column->toArray());
        }

        $indexes = [];
        foreach ($table->getIndexes() as $index) {
            $indexes[] = 'INDEX ' . implode(',', $index->getColumns())
                . ($index->isPrimary() ? ' primary' : '')
                . ($index->isUnique() ? ' unique' : '');
        }

        $foreignKeys = [];
        foreach ($table->getForeignKeys() as $foreignKey) {
            $foreignKeys[] = 'FOREIGN ' . implode(',', $foreignKey->getLocalColumns())
                . ' -> ' . $foreignKey->getForeignTableName()
                . '(' . implode(',', $foreignKey->getForeignColumns()) . ')';
        }

        // Sorted so the cosmetic column reordering the migrator applies cannot
        // move the fingerprint on its own.
        sort($columns);
        sort($indexes);
        sort($foreignKeys);

        return implode("\n", ['TABLE ' . $table->getName(), ...$columns, ...$indexes, ...$foreignKeys]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function describeOptions(array $options): string
    {
        ksort($options);

        $described = [];
        foreach ($options as $key => $value) {
            $described[] = $key . '=' . match (true) {
                is_object($value) => $value::class,
                is_bool($value) => $value ? 'true' : 'false',
                is_array($value) => json_encode($value),
                null === $value => 'null',
                default => (string) $value,
            };
        }

        return implode(' ', $described);
    }
}
