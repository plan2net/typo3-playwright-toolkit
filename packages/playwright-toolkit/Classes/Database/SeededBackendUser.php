<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

final class SeededBackendUser
{
    /**
     * @var string
     */
    public const TABLE = 'be_users';

    /**
     * @var string
     */
    public const USERNAME = '_playwright_toolkit';

    // No registered hash algorithm claims this, so PasswordHashFactory throws
    // and the login form can never accept the account.
    // SeededBackendUserTest pins that.
    /**
     * @var string
     */
    private const UNUSABLE_PASSWORD = '*';

    /**
     * @return array<string, string|int>
     */
    public static function row(int $sessionUserId): array
    {
        return [
            'uid' => $sessionUserId,
            'pid' => 0,
            'username' => self::USERNAME,
            'password' => self::UNUSABLE_PASSWORD,
            'admin' => 1,
            'disable' => 0,
            'deleted' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ];
    }
}
