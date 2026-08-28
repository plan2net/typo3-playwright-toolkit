<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

final class SavedRecord
{
    /**
     * @var string
     */
    public const HEADER = 'X-Playwright-Saved-Record';

    public static function uidFrom(string $location, string $table): ?int
    {
        $pattern = '/edit\[' . preg_quote($table, '/') . '\]\[(\d+)\]/';
        if (1 !== preg_match($pattern, urldecode($location), $matches)) {
            return null;
        }

        $uid = (int) $matches[1];

        return $uid > 0 ? $uid : null;
    }
}
