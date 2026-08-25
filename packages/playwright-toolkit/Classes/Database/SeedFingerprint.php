<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

final class SeedFingerprint
{
    /**
     * @param array<string, string> $fixtures filename => contents, in seed order
     */
    public static function compute(
        string $schemaSql,
        array $fixtures,
        string $plainSessionId,
        int $sessionUserId,
        string $encryptionKey,
    ): string {
        $parts = [$schemaSql];
        foreach ($fixtures as $name => $contents) {
            $parts[] = $name;
            $parts[] = $contents;
        }
        $parts[] = $plainSessionId;
        $parts[] = (string) $sessionUserId;
        $parts[] = $encryptionKey;

        return hash('sha256', implode("\0", $parts));
    }
}
