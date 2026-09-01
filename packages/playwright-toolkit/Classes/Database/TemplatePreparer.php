<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;
use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;

final class TemplatePreparer
{
    public function __construct(
        private readonly ToolkitConfigurationFactory $configurationFactory,
        private readonly SchemaMigrator $schemaMigrator,
        private readonly SeedSources $seedSources,
        private readonly BorrowedConnection $borrowedConnection,
        private readonly LockFiles $lockFiles,
    ) {
    }

    /**
     * @return array{fingerprint: string, built: bool}
     */
    public function prepare(bool $force = false): array
    {
        $configuration = $this->configurationFactory->create();
        $driver = TestDatabaseDriverFactory::fromConnection(
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? []
        );

        $snapshot = $this->seedSources->snapshot($configuration);

        return $this->lockFiles->exclusively(LockFiles::TEMPLATE_LOCK, function () use ($driver, $snapshot, $force): array {
            // The fingerprint is written last, so a build that died in the middle
            // reads as null here and is rebuilt.
            if (!$force && $driver->templateFingerprint() === $snapshot->fingerprint) {
                return ['fingerprint' => $snapshot->fingerprint, 'built' => false];
            }

            $driver->createEmptyTemplate();
            $this->buildSchema($driver, $snapshot->schemaStatements);
            $driver->seedTemplate($snapshot->templateSeed());
            $driver->finaliseTemplate($snapshot->fingerprint);

            return ['fingerprint' => $snapshot->fingerprint, 'built' => true];
        });
    }

    /**
     * @param list<string> $connectionNames
     */
    public static function schemaFailureMessage(string $failure, array $connectionNames): string
    {
        $others = array_values(array_diff($connectionNames, ['Default']));
        if ([] === $others) {
            return 'Could not build the test database schema: ' . $failure;
        }

        return sprintf(
            'Could not build the test database schema. TYPO3 builds it for every connection this'
            . ' project configures, not only Default, and this one also configures %s: each has to'
            . ' be reachable in the "Testing" context, or unset in it. %s',
            implode(', ', $others),
            $failure
        );
    }

    /**
     * @param list<string> $schemaStatements
     */
    private function buildSchema(TestDatabaseDriver $driver, array $schemaStatements): void
    {
        $this->borrowedConnection->use($driver->templateConnectionOverrides(), function () use ($schemaStatements): void {
            $names = array_map(strval(...), array_keys($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'] ?? []));

            try {
                $errors = array_filter($this->schemaMigrator->install($schemaStatements));
            } catch (\Throwable $failure) {
                throw new \RuntimeException(self::schemaFailureMessage($failure->getMessage(), $names), 0, $failure);
            }

            if ([] !== $errors) {
                throw new \RuntimeException(self::schemaFailureMessage(implode('; ', $errors), $names));
            }
        });
    }
}
