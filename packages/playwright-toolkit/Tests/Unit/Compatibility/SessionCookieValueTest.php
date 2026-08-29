<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Compatibility;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Compatibility\SessionCookieValue;
use TYPO3\CMS\Core\Session\UserSession;

/**
 * One of these two runs on any given core and the other skips, because which
 * branch is reachable is the whole thing this class exists to hide.
 */
final class SessionCookieValueTest extends TestCase
{
    private const IDENTIFIER = 'some-session-identifier';

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);
        $GLOBALS['EXEC_TIME'] = 1_000_000;
    }

    #[Test]
    public function usesTheJwtWhereTheSessionOffersOne(): void
    {
        $session = UserSession::createNonFixated(self::IDENTIFIER);
        // @phpstan-ignore function.alreadyNarrowedType
        if (!method_exists($session, 'getJwt')) {
            self::markTestSkipped('This core has no JWT.');
        }

        $cookieValue = SessionCookieValue::of($session);

        // getJwt() stamps the current second into the payload, so a second call
        // returns a different token and cannot serve as the expected value.
        self::assertSame(self::IDENTIFIER, self::identifierClaimOf($cookieValue));
        // Core rejects the bare identifier, and the failure looks like a login
        // redirect rather than a bad cookie.
        self::assertNotSame(self::IDENTIFIER, $cookieValue);
    }

    #[Test]
    public function usesTheIdentifierWhereThereIsNoJwt(): void
    {
        $session = UserSession::createNonFixated(self::IDENTIFIER);
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists($session, 'getJwt')) {
            self::markTestSkipped('This core mints a JWT.');
        }

        self::assertSame(self::IDENTIFIER, SessionCookieValue::of($session));
    }

    private static function identifierClaimOf(string $jwt): ?string
    {
        $segments = explode('.', $jwt);
        if (3 !== \count($segments)) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($segments[1], '-_', '+/'), true), true);
        $identifier = \is_array($payload) ? ($payload['identifier'] ?? null) : null;

        return \is_string($identifier) ? $identifier : null;
    }
}
