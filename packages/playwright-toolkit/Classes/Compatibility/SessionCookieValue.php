<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Compatibility;

use TYPO3\CMS\Core\Session\UserSession;

/**
 * What goes into the be_typo_user cookie: a JWT since TYPO3 12, the session
 * identifier itself on 11. Delete this class when 11 is dropped and call
 * getJwt() at its two callers.
 */
final class SessionCookieValue
{
    public static function of(UserSession $session): string
    {
        // Always true on 12+, which is why the ignore lives here and not in the
        // shared config: it is deleted together with the class.
        // @phpstan-ignore function.alreadyNarrowedType
        return method_exists($session, 'getJwt')
            ? (string) $session->getJwt()
            : $session->getIdentifier();
    }
}
