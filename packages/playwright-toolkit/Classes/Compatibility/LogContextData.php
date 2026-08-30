<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Compatibility;

final class LogContextData
{
    // 11.5 and 12.4 put '- ' in front of the JSON, 13.4 and 14.3 do not.
    // TYPO3's own decoder cannot read the prefixed form.
    public static function unprefixed(mixed $data): mixed
    {
        if (!\is_string($data) || !str_starts_with($data, '- ')) {
            return $data;
        }

        return substr($data, 2);
    }
}
