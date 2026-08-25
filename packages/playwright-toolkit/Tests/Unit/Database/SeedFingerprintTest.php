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
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'playwright_test_session', 1, 'key'),
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'playwright_test_session', 1, 'key'),
        );
    }

    #[Test]
    public function aChangedSchemaProducesADifferentFingerprint(): void
    {
        self::assertNotSame(
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'playwright_test_session', 1, 'key'),
            SeedFingerprint::compute('schema-v2', ['a.sql' => 'A'], 'playwright_test_session', 1, 'key'),
        );
    }

    #[Test]
    public function changedFixtureContentProducesADifferentFingerprint(): void
    {
        self::assertNotSame(
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'playwright_test_session', 1, 'key'),
            SeedFingerprint::compute('schema', ['a.sql' => 'A-changed'], 'playwright_test_session', 1, 'key'),
        );
    }

    #[Test]
    public function addingAFixtureProducesADifferentFingerprint(): void
    {
        self::assertNotSame(
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'playwright_test_session', 1, 'key'),
            SeedFingerprint::compute('schema', ['a.sql' => 'A', 'b.sql' => 'B'], 'playwright_test_session', 1, 'key'),
        );
    }

    #[Test]
    public function reorderingFixturesProducesADifferentFingerprint(): void
    {
        self::assertNotSame(
            SeedFingerprint::compute('schema', ['a.sql' => 'A', 'b.sql' => 'B'], 'playwright_test_session', 1, 'key'),
            SeedFingerprint::compute('schema', ['b.sql' => 'B', 'a.sql' => 'A'], 'playwright_test_session', 1, 'key'),
        );
    }

    #[Test]
    public function aChangedEncryptionKeyProducesADifferentFingerprint(): void
    {
        self::assertNotSame(
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'playwright_test_session', 1, 'key'),
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'playwright_test_session', 1, 'other-key'),
        );
    }

    #[Test]
    public function theFingerprintIsAHexSha256String(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'playwright_test_session', 1, 'key'),
        );
    }

    #[Test]
    public function aChangedSessionIdProducesADifferentFingerprint(): void
    {
        self::assertNotSame(
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'playwright_test_session', 1, 'key'),
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'another_session', 1, 'key'),
        );
    }

    #[Test]
    public function aChangedSessionUserProducesADifferentFingerprint(): void
    {
        self::assertNotSame(
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'playwright_test_session', 1, 'key'),
            SeedFingerprint::compute('schema', ['a.sql' => 'A'], 'playwright_test_session', 2, 'key'),
        );
    }
}
