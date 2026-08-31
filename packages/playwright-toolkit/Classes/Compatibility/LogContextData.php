<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Compatibility;

final class LogContextData
{
    // 11.5 and 12.4 put '- ' in front of the JSON; TYPO3's own decoder cannot read that.
    public static function unprefixed(mixed $data): mixed
    {
        if (!\is_string($data) || !str_starts_with($data, '- ')) {
            return $data;
        }

        return substr($data, 2);
    }
}
