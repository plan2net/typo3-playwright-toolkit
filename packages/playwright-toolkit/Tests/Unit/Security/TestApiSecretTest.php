<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Security;

use Plan2net\PlaywrightToolkit\Security\InspectToken;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TestApiSecretTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/toolkit-secret-' . bin2hex(random_bytes(6));
        putenv(TestApiSecret::ENV_NAME);
    }

    protected function tearDown(): void
    {
        putenv(TestApiSecret::ENV_NAME);
        if (is_dir($this->directory)) {
            @unlink($this->secretFile());
            @rmdir($this->directory);
        }
    }

    #[Test]
    public function refusesEverythingWhileUnconfigured(): void
    {
        $subject = $this->subject();

        self::assertFalse($subject->matches('anything'));
        self::assertFalse($subject->matches(''));
    }

    #[Test]
    public function generatesASecretOfUsefulLength(): void
    {
        $secret = $this->subject()->ensureExists();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $secret);
    }

    #[Test]
    public function keepsTheSecretItAlreadyGenerated(): void
    {
        $subject = $this->subject();

        self::assertSame($subject->ensureExists(), $subject->ensureExists());
    }

    #[Test]
    public function acceptsOnlyTheGeneratedSecret(): void
    {
        $subject = $this->subject();
        $secret = $subject->ensureExists();

        self::assertTrue($subject->matches($secret));
        self::assertFalse($subject->matches(strrev($secret)));
        self::assertFalse($subject->matches(''));
    }

    // A truncated secret must not pass: a prefix comparison would accept it.
    #[Test]
    public function refusesAPrefixOfTheRealSecret(): void
    {
        $subject = $this->subject();
        $secret = $subject->ensureExists();

        self::assertFalse($subject->matches(substr($secret, 0, 32)));
    }

    #[Test]
    public function writesTheSecretSoOnlyTheOwnerCanReadIt(): void
    {
        $this->subject()->ensureExists();

        self::assertSame('0600', substr(sprintf('%o', fileperms($this->secretFile())), -4));
    }

    #[Test]
    public function theEnvironmentOverridesTheFile(): void
    {
        $subject = $this->subject();
        $fileSecret = $subject->ensureExists();
        putenv(TestApiSecret::ENV_NAME . '=from-the-environment');

        self::assertTrue($subject->matches('from-the-environment'));
        self::assertFalse($subject->matches($fileSecret));
    }

    // Reachable through the environment alone, so a container that never shares
    // the project's filesystem still works.
    #[Test]
    public function theEnvironmentAloneIsEnough(): void
    {
        putenv(TestApiSecret::ENV_NAME . '=only-the-environment');
        $subject = $this->subject();

        self::assertTrue($subject->matches('only-the-environment'));
        self::assertFileDoesNotExist($this->secretFile());
    }

    #[Test]
    public function ignoresAnEmptyEnvironmentValueInFavourOfTheFile(): void
    {
        $subject = $this->subject();
        $fileSecret = $subject->ensureExists();
        putenv(TestApiSecret::ENV_NAME . '=');

        self::assertTrue($subject->matches($fileSecret));
    }

    #[Test]
    public function acceptsAnInspectTokenMintedFromItsOwnSecret(): void
    {
        $subject = $this->subject();
        $secret = $subject->ensureExists();
        $token = InspectToken::mint($secret, 'ABCD1234EFGH5678', time() + 60);

        self::assertTrue($subject->matchesInspectToken('ABCD1234EFGH5678', $token));
    }

    #[Test]
    public function refusesAnInspectTokenMintedFromAnotherSecret(): void
    {
        $subject = $this->subject();
        $subject->ensureExists();
        $token = InspectToken::mint('somebody-elses-secret', 'ABCD1234EFGH5678', time() + 60);

        self::assertFalse($subject->matchesInspectToken('ABCD1234EFGH5678', $token));
    }

    /** No secret on disk must never mean "everything is allowed". */
    #[Test]
    public function refusesAnInspectTokenWhenNoSecretExistsYet(): void
    {
        $token = InspectToken::mint('any-secret', 'ABCD1234EFGH5678', time() + 60);

        self::assertFalse($this->subject()->matchesInspectToken('ABCD1234EFGH5678', $token));
    }

    private function subject(): TestApiSecret
    {
        return new TestApiSecret($this->secretFile());
    }

    private function secretFile(): string
    {
        return $this->directory . '/api-secret';
    }
}
