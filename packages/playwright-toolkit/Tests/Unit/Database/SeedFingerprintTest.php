<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database;

use Plan2net\PlaywrightToolkit\Database\SeedFingerprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SeedFingerprintTest extends TestCase
{
    #[Test]
    public function identicalInputsProduceTheSameFingerprint(): void
    {
        self::assertSame(
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'a-session-hash', 1),
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'a-session-hash', 1),
        );
    }

    #[Test]
    public function aChangedSchemaProducesADifferentFingerprint(): void
    {
        self::assertNotSame(
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'a-session-hash', 1),
            SeedFingerprint::compute('schema-v2', ['a.sql' => 'A'], 'a-session-hash', 1),
        );
    }

    #[Test]
    public function changedFixtureContentProducesADifferentFingerprint(): void
    {
        self::assertNotSame(
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'a-session-hash', 1),
            SeedFingerprint::compute('schema', ['a.sql' => 'A-changed'], 'a-session-hash', 1),
        );
    }

    #[Test]
    public function addingAFixtureProducesADifferentFingerprint(): void
    {
        self::assertNotSame(
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'a-session-hash', 1),
            SeedFingerprint::compute('schema', ['a.sql' => 'A', 'b.sql' => 'B'], 'a-session-hash', 1),
        );
    }

    #[Test]
    public function reorderingFixturesProducesADifferentFingerprint(): void
    {
        self::assertNotSame(
            SeedFingerprint::compute('schema', ['a.sql' => 'A', 'b.sql' => 'B'], 'a-session-hash', 1),
            SeedFingerprint::compute('schema', ['b.sql' => 'B', 'a.sql' => 'A'], 'a-session-hash', 1),
        );
    }

    #[Test]
    public function theFingerprintIsAHexSha256String(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'a-session-hash', 1),
        );
    }

    #[Test]
    // The hash, not the plain id: it also moves when the encryption key rotates or
    // when TYPO3 changes how it derives ses_id, and a template seeded under the old
    // one is unusable.
    public function aChangedSessionHashProducesADifferentFingerprint(): void
    {
        self::assertNotSame(
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'a-session-hash', 1),
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'a-different-session-hash', 1),
        );
    }

    #[Test]
    public function aChangedSessionUserProducesADifferentFingerprint(): void
    {
        self::assertNotSame(
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'a-session-hash', 1),
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'a-session-hash', 2),
        );
    }
}
