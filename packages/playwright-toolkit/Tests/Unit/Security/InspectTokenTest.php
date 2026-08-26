<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Security;

use Plan2net\PlaywrightToolkit\Security\InspectToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Tests\ContractFixture;

final class InspectTokenTest extends TestCase
{
    /**
     * @var string
     */
    private const SECRET = 'a-test-api-secret';

    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';

    #[Test]
    public function acceptsATokenItMintedItself(): void
    {
        $token = InspectToken::mint(self::SECRET, self::TEST_ID, 1_000_000);

        self::assertTrue(InspectToken::verify(self::SECRET, self::TEST_ID, $token, 999_999));
    }

    #[Test]
    public function refusesATokenAfterItExpired(): void
    {
        $token = InspectToken::mint(self::SECRET, self::TEST_ID, 1_000_000);

        self::assertFalse(InspectToken::verify(self::SECRET, self::TEST_ID, $token, 1_000_001));
    }

    /** A link for one test must not open another test's database. */
    #[Test]
    public function refusesATokenMintedForAnotherTestId(): void
    {
        $token = InspectToken::mint(self::SECRET, 'ZZZZ9999ZZZZ9999', 1_000_000);

        self::assertFalse(InspectToken::verify(self::SECRET, self::TEST_ID, $token, 999_999));
    }

    #[Test]
    public function refusesATokenMintedWithAnotherSecret(): void
    {
        $token = InspectToken::mint('a-different-secret', self::TEST_ID, 1_000_000);

        self::assertFalse(InspectToken::verify(self::SECRET, self::TEST_ID, $token, 999_999));
    }

    /**
     * The expiry is readable in the token, so it must be covered by the signature
     * or anyone could extend their own link.
     */
    #[Test]
    public function refusesATokenWhoseExpiryWasEdited(): void
    {
        $token = InspectToken::mint(self::SECRET, self::TEST_ID, 1_000_000);
        [, $signature] = explode('.', $token, 2);

        self::assertFalse(
            InspectToken::verify(self::SECRET, self::TEST_ID, '9999999999.' . $signature, 999_999)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTokens(): array
    {
        return [
            'empty' => [''],
            'no separator' => ['deadbeef'],
            'no signature' => ['1000000.'],
            'no expiry' => ['.deadbeef'],
            'expiry not a number' => ['soon.deadbeef'],
            'signature not hex' => ['1000000.zzzz'],
        ];
    }

    #[Test]
    #[DataProvider('malformedTokens')]
    public function refusesAMalformedToken(string $token): void
    {
        self::assertFalse(InspectToken::verify(self::SECRET, self::TEST_ID, $token, 999_999));
    }

    /**
     * The npm package mints the same token for the teardown links, so both sides
     * are pinned against one fixture rather than against each other.
     */
    #[Test]
    public function mintsExactlyTheTokenTheContractFixtureRecords(): void
    {
        /** @var array{secret: string, testId: string, expiresAt: int, token: string} $fixture */
        $fixture = ContractFixture::read('inspect-token');

        self::assertSame(
            $fixture['token'],
            InspectToken::mint($fixture['secret'], $fixture['testId'], $fixture['expiresAt'])
        );
    }

    #[Test]
    public function mintsExactlyTheReplayTokenTheContractFixtureRecords(): void
    {
        /** @var array{secret: string, subject: string, expiresAt: int, token: string} $fixture */
        $fixture = ContractFixture::read('inspect-replay-token');

        self::assertSame(InspectToken::REPLAY_SUBJECT, $fixture['subject']);
        self::assertSame(
            $fixture['token'],
            InspectToken::mint($fixture['secret'], InspectToken::REPLAY_SUBJECT, $fixture['expiresAt'])
        );
    }

    // A replay link must not open a test database, and vice versa.
    #[Test]
    public function aReplayTokenIsNotValidForATestId(): void
    {
        $token = InspectToken::mint(self::SECRET, InspectToken::REPLAY_SUBJECT, 1_000_000);

        self::assertFalse(InspectToken::verify(self::SECRET, self::TEST_ID, $token, 999_999));
    }

    #[Test]
    public function aTestIdTokenIsNotValidForAReplayLink(): void
    {
        $token = InspectToken::mint(self::SECRET, self::TEST_ID, 1_000_000);

        self::assertFalse(InspectToken::verify(self::SECRET, InspectToken::REPLAY_SUBJECT, $token, 999_999));
    }

    #[Test]
    public function refusesEverythingWhenNoSecretIsConfigured(): void
    {
        $token = InspectToken::mint(self::SECRET, self::TEST_ID, 1_000_000);

        self::assertFalse(InspectToken::verify('', self::TEST_ID, $token, 999_999));
    }
}
