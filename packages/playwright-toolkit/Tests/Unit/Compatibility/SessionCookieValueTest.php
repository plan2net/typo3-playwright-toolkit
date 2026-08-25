<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Compatibility;

use Plan2net\PlaywrightToolkit\Compatibility\SessionCookieValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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

        self::assertSame($session->getJwt(), $cookieValue);
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
}
