<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use TYPO3\CMS\Core\Session\Backend\DatabaseSessionBackend;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
    public static function row(string $plainSessionId, int $sessionUserId): array
    {
        return [
            ...self::criteria($plainSessionId, $sessionUserId),
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
    public static function criteria(string $plainSessionId, int $sessionUserId): array
    {
        return [
            'ses_id' => self::hashedSessionId($plainSessionId),
            'ses_iplock' => self::IPLOCK,
            'ses_userid' => $sessionUserId,
        ];
    }

    public static function hashedSessionId(string $plainSessionId): string
    {
        return GeneralUtility::makeInstance(DatabaseSessionBackend::class)->hash($plainSessionId);
    }
}
