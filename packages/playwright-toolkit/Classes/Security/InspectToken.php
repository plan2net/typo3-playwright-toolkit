<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Security;

// The npm package mints the same token, so the shape is pinned in CONTRACT.md.
final class InspectToken
{
    /**
     * @var string
     */
    public const PURPOSE = 'inspect';
    /**
     * Where a test ID goes for a replay link; no test ID can be this.
     *
     * @var string
     */
    public const REPLAY_SUBJECT = 'replay';

    public static function mint(string $secret, string $testId, int $expiresAt): string
    {
        return $expiresAt . '.' . self::signature($secret, $testId, $expiresAt);
    }

    public static function verify(string $secret, string $testId, string $token, int $now): bool
    {
        if ('' === $secret) {
            return false;
        }

        $parts = explode('.', $token, 2);
        if (2 !== \count($parts) || '' === $parts[1] || 1 !== preg_match('/^\d+$/', $parts[0])) {
            return false;
        }

        $expiresAt = (int) $parts[0];
        if ($expiresAt < $now) {
            return false;
        }

        return hash_equals(self::signature($secret, $testId, $expiresAt), $parts[1]);
    }

    /** True only for a token this secret really signed, whose expiry has passed. */
    public static function lapsed(string $secret, string $testId, string $token, int $now): bool
    {
        if ('' === $secret) {
            return false;
        }

        $parts = explode('.', $token, 2);
        if (2 !== \count($parts) || '' === $parts[1] || 1 !== preg_match('/^\d+$/', $parts[0])) {
            return false;
        }

        $expiresAt = (int) $parts[0];

        return $expiresAt < $now
            && hash_equals(self::signature($secret, $testId, $expiresAt), $parts[1]);
    }

    private static function signature(string $secret, string $testId, int $expiresAt): string
    {
        return hash_hmac('sha256', self::PURPOSE . ':' . $testId . ':' . $expiresAt, $secret);
    }
}
