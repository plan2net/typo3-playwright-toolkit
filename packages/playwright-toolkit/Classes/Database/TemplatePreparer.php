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

        $handle = $this->lockFiles->open($this->lockFiles->templateLock());

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not acquire the template build lock');
            }

            // The fingerprint is written last, so a build that died in the middle
            // reads as null here and is rebuilt.
            if (!$force && $driver->templateFingerprint() === $snapshot->fingerprint) {
                return ['fingerprint' => $snapshot->fingerprint, 'built' => false];
            }

            $driver->createEmptyTemplate();
            $this->buildSchema($driver, $snapshot->schemaStatements);
            $driver->seedTemplate($snapshot->templateSeed());
            $driver->finaliseTemplate($snapshot->fingerprint);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return ['fingerprint' => $snapshot->fingerprint, 'built' => true];
    }

    /**
     * @param list<string> $schemaStatements
     */
    private function buildSchema(TestDatabaseDriver $driver, array $schemaStatements): void
    {
        $this->borrowedConnection->use($driver->templateConnectionOverrides(), function () use ($schemaStatements): void {
            $errors = array_filter($this->schemaMigrator->install($schemaStatements));
            if ([] !== $errors) {
                throw new \RuntimeException(
                    'Could not build the test database schema: ' . implode('; ', $errors)
                );
            }
        });
    }
}
