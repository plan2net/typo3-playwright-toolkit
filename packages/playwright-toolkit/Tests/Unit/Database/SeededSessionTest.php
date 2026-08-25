<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database;

use Plan2net\PlaywrightToolkit\Database\SeededSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SeededSessionTest extends TestCase
{
    #[Test]
    public function derivesTheSessionIdExactlyLikeTheCoreSessionBackend(): void
    {
        $encryptionKey = 'a-test-encryption-key';
        $expected = hash_hmac(
            'sha256',
            'playwright_test_session',
            sha1($encryptionKey . 'core-session-backend')
        );

        self::assertSame(
            $expected,
            SeededSession::hashedSessionId('playwright_test_session', $encryptionKey)
        );
    }

    #[Test]
    public function aChangedEncryptionKeyProducesADifferentSessionId(): void
    {
        self::assertNotSame(
            SeededSession::hashedSessionId('playwright_test_session', 'key'),
            SeededSession::hashedSessionId('playwright_test_session', 'other-key'),
        );
    }

    #[Test]
    public function aChangedPlainSessionIdProducesADifferentSessionId(): void
    {
        self::assertNotSame(
            SeededSession::hashedSessionId('playwright_test_session', 'key'),
            SeededSession::hashedSessionId('another_test_session', 'key'),
        );
    }

    #[Test]
    public function theSessionIdIsAHexSha256String(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            SeededSession::hashedSessionId('playwright_test_session', 'key'),
        );
    }

    #[Test]
    public function theRowCarriesEveryColumnAnInsertNeeds(): void
    {
        self::assertSame(
            [
                'ses_id' => SeededSession::hashedSessionId('playwright_test_session', 'key'),
                'ses_iplock' => SeededSession::IPLOCK,
                'ses_userid' => 1,
                'ses_tstamp' => 2147483647,
                'ses_data' => serialize([]),
            ],
            SeededSession::row('playwright_test_session', 1, 'key')
        );
    }

    #[Test]
    public function theCriteriaAreTheRowWithoutWhatMayDrift(): void
    {
        $criteria = SeededSession::criteria('playwright_test_session', 1, 'key');

        self::assertSame(['ses_id', 'ses_iplock', 'ses_userid'], array_keys($criteria));
        self::assertSame(
            array_intersect_key(SeededSession::row('playwright_test_session', 1, 'key'), $criteria),
            $criteria
        );
    }
}
