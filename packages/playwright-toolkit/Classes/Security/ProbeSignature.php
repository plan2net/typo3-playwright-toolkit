<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Security;

final class ProbeSignature
{
    /**
     * @var string
     */
    public const PURPOSE = 'probe';

    public static function sign(string $secret, string $testId): string
    {
        return hash_hmac('sha256', self::PURPOSE . ':' . $testId, $secret);
    }

    public static function verify(string $secret, string $testId, string $signature): bool
    {
        if ('' === $secret) {
            return false;
        }

        return hash_equals(self::sign($secret, $testId), $signature);
    }
}
