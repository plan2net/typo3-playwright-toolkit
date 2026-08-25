<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Compatibility;

use Doctrine\DBAL\Schema\Table;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;

// parseCreateTableStatements() is public in v12 and protected since v13, and v14
// made SchemaMigrator readonly, so it is reached by reflection rather than by
// subclassing.
final class ResolvedSchemaTables
{
    /**
     * @var string
     */
    private const METHOD = 'parseCreateTableStatements';

    public function __construct(
        private readonly SchemaMigrator $schemaMigrator,
    ) {
    }

    /**
     * @param list<string> $statements
     *
     * @return array<Table>
     */
    public function parse(array $statements): array
    {
        $method = new \ReflectionMethod($this->schemaMigrator, self::METHOD);

        /** @var array<Table> $tables */
        $tables = $method->invoke($this->schemaMigrator, $statements);

        return $tables;
    }
}
