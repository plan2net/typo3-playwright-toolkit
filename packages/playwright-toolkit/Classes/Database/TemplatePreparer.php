<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfiguration;
use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfigurationFactory;
use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriver;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use Plan2net\PlaywrightToolkit\Media\MediaManifest;
use Plan2net\PlaywrightToolkit\Media\MediaSeeder;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;

final class TemplatePreparer
{
    public function __construct(
        private readonly ToolkitConfigurationFactory $configurationFactory,
        private readonly SchemaMigrator $schemaMigrator,
        private readonly SeedSources $seedSources,
        private readonly BorrowedConnection $borrowedConnection,
        private readonly LockFiles $lockFiles,
        private readonly MediaSeeder $mediaSeeder,
        private readonly MediaManifest $mediaManifest,
    ) {
    }

    /**
     * @return array{fingerprint: string, built: bool, timings: array<string, float>}
     */
    public function prepare(bool $force = false): array
    {
        $configuration = $this->configurationFactory->create();
        $driver = TestDatabaseDriverFactory::fromConnection(
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? []
        );

        // Timed outside the build: resolving the schema against TCA happens here, so
        // a prepare that rebuilds nothing still pays for it.
        $startedAt = hrtime(true);
        $snapshot = $this->seedSources->snapshot($configuration);
        $timings = ['sources' => self::sinceMs($startedAt)];
        $mediaPath = self::mediaDirectory($configuration);
        $mediaStorage = $configuration->mediaStorage;

        return $this->lockFiles->exclusively(LockFiles::TEMPLATE_LOCK, function () use ($driver, $snapshot, $force, $mediaPath, $mediaStorage, $timings): array {
            // The fingerprint is written last, so a build that died in the middle
            // reads as null here and is rebuilt. It hashes the media sources, not
            // what was published from them, so the destination is checked too.
            if (!$force
                && $driver->templateFingerprint() === $snapshot->fingerprint
                && (null === $mediaPath || $this->mediaSeeder->destinationMatches($mediaPath, $mediaStorage))
            ) {
                $startedAt = hrtime(true);
                $this->publishManifest($driver, $mediaPath, $mediaStorage);
                $timings['manifest'] = self::sinceMs($startedAt);

                return ['fingerprint' => $snapshot->fingerprint, 'built' => false, 'timings' => $timings];
            }

            $startedAt = hrtime(true);
            $driver->createEmptyTemplate();
            $this->buildSchema($driver, $snapshot->schemaStatements);
            $timings['schema'] = self::sinceMs($startedAt);

            $startedAt = hrtime(true);
            $driver->seedTemplate($snapshot->templateSeed());
            $timings['fixtures'] = self::sinceMs($startedAt);

            $startedAt = hrtime(true);
            $this->seedMedia($driver, $mediaPath, $mediaStorage);
            $timings['media'] = self::sinceMs($startedAt);

            // Before the fingerprint, so a manifest that could not be written
            // leaves the template unfinalised rather than describing rows no
            // caller can trust.
            $startedAt = hrtime(true);
            $this->publishManifest($driver, $mediaPath, $mediaStorage);
            $timings['manifest'] = self::sinceMs($startedAt);

            $driver->finaliseTemplate($snapshot->fingerprint);

            return ['fingerprint' => $snapshot->fingerprint, 'built' => true, 'timings' => $timings];
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

    private static function sinceMs(float|int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }

    private static function mediaDirectory(ToolkitConfiguration $configuration): ?string
    {
        return '' === $configuration->mediaPath
            ? null
            : Environment::getProjectPath() . '/' . ltrim($configuration->mediaPath, '/');
    }

    private function seedMedia(TestDatabaseDriver $driver, ?string $mediaPath, string $mediaStorage): void
    {
        if (null === $mediaPath) {
            return;
        }

        $this->borrowedConnection->use(
            $driver->templateConnectionOverrides(),
            function () use ($mediaPath, $mediaStorage): void {
                $this->mediaSeeder->seed($mediaPath, $mediaStorage);
            }
        );
    }

    private function publishManifest(TestDatabaseDriver $driver, ?string $mediaPath, string $mediaStorage): void
    {
        if (null === $mediaPath) {
            $this->mediaManifest->remove();

            return;
        }

        /** @var array<string, int> $map */
        $map = $this->borrowedConnection->use(
            $driver->templateConnectionOverrides(),
            fn(): array => $this->mediaSeeder->readMap($mediaStorage)
        );

        $this->mediaManifest->write($map);
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
