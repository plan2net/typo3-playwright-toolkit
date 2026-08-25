<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

final class SeededSession
{
    /**
     * @var string
     */
    public const TABLE = 'be_sessions';

    /**
     * @var string
     */
    public const IPLOCK = '[DISABLED]';

    // Far enough out that no run outlives the session it was handed.
    /**
     * @var int
     */
    private const NEVER_EXPIRES = 2147483647;

    /**
     * @return array<string, string|int>
     */
    public static function row(string $plainSessionId, int $sessionUserId, string $encryptionKey): array
    {
        return [
            ...self::criteria($plainSessionId, $sessionUserId, $encryptionKey),
            'ses_tstamp' => self::NEVER_EXPIRES,
            'ses_data' => serialize([]),
        ];
    }

    /**
     * Identifying columns only: a lookup must not ask about the two a live session
     * updates.
     *
     * @return array<string, string|int>
     */
    public static function criteria(string $plainSessionId, int $sessionUserId, string $encryptionKey): array
    {
        return [
            'ses_id' => self::hashedSessionId($plainSessionId, $encryptionKey),
            'ses_iplock' => self::IPLOCK,
            'ses_userid' => $sessionUserId,
        ];
    }

    public static function hashedSessionId(string $plainSessionId, string $encryptionKey): string
    {
        return hash_hmac(
            'sha256',
            $plainSessionId,
            sha1($encryptionKey . 'core-session-backend')
        );
    }
}
