<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Schema\SqlReader;

final class SeedSources
{
    public function __construct(
        private readonly ResolvedSchema $resolvedSchema,
        private readonly SqlReader $sqlReader,
    ) {
    }

    public function snapshot(ToolkitConfiguration $configuration): SeedSnapshot
    {
        $schemaStatements = array_values($this->sqlReader->getCreateTableStatementArray(
            $this->sqlReader->getTablesDefinitionString()
        ));
        $fixtures = self::loadFixtures(
            Environment::getProjectPath() . '/' . ltrim($configuration->fixturesPath, '/'),
            $configuration->fixtureManifest
        );

        return new SeedSnapshot(
            schemaStatements: $schemaStatements,
            fixtures: $fixtures,
            plainSessionId: $configuration->preseededSessionId,
            sessionUserId: $configuration->sessionUserId,
            fingerprint: SeedFingerprint::compute(
                $this->resolvedSchema->fingerprintSource($schemaStatements),
                $fixtures,
                SeededSession::hashedSessionId($configuration->preseededSessionId),
                $configuration->sessionUserId
            ),
        );
    }

    /**
     * @param list<string> $manifest
     *
     * @return array<string, string> filename => contents, in manifest order
     */
    public static function loadFixtures(string $fixturesPath, array $manifest): array
    {
        $basePath = rtrim($fixturesPath, '/') . '/';

        $loaded = [];
        foreach ($manifest as $fixture) {
            $sql = @file_get_contents($basePath . $fixture);
            if (false === $sql) {
                throw new \RuntimeException("Failed to load fixture: $fixture");
            }
            $loaded[$fixture] = $sql;
        }

        return $loaded;
    }
}
