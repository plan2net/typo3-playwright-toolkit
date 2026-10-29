<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

final class TranslatedRecord
{
    /**
     * @var string
     */
    public const HEADER = 'X-Playwright-Translated-Record';

    private const MAXIMUM_BYTES = 2000;

    /**
     * @param array<string, list<int>> $references
     */
    public static function header(int $uid, int $pid, array $references): string
    {
        $header = (string) json_encode(['uid' => $uid, 'pid' => $pid, 'references' => (object) $references]);
        if (\strlen($header) <= self::MAXIMUM_BYTES) {
            return $header;
        }

        return (string) json_encode(['uid' => $uid, 'pid' => $pid, 'truncated' => true]);
    }
}
