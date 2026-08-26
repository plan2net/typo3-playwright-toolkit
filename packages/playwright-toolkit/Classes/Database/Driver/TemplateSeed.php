<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\Driver;

final class TemplateSeed
{
    /**
     * @param array<string, string> $fixtures filename => contents, in manifest order
     */
    public function __construct(
        public readonly array $fixtures,
        public readonly string $plainSessionId,
        public readonly int $sessionUserId,
    ) {
    }
}
