<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

final class SeedFingerprint
{
    /**
     * Bump when this package changes what it seeds into a template. The hashed
     * sources below do not move with it, so an upgrade would reuse the old one.
     *
     * @var int
     */
    public const SEED_FORMAT = 1;

    /**
     * @param array<string, string> $fixtures filename => contents, in seed order
     */
    public static function compute(
        string $schemaSql,
        array $fixtures,
        string $hashedSessionId,
        int $sessionUserId,
    ): string {
        $parts = ['seed-format=' . self::SEED_FORMAT, $schemaSql];
        foreach ($fixtures as $name => $contents) {
            $parts[] = $name;
            $parts[] = $contents;
        }
        $parts[] = $hashedSessionId;
        $parts[] = (string) $sessionUserId;

        return hash('sha256', implode("\0", $parts));
    }
}
