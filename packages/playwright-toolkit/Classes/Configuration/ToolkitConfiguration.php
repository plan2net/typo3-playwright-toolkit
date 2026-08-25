<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Configuration;

final class ToolkitConfiguration
{
    public function __construct(
        public readonly string $fixturesPath,
        /** @var list<string> */
        public readonly array $fixtureManifest,
        public readonly string $preseededSessionId,
        public readonly int $sessionUserId,
        public readonly int $cleanupMinimumAgeMs,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function parseList(string $value): array
    {
        return array_values(
            array_filter(
                array_map('trim', explode(',', $value)),
                static fn(string $item): bool => '' !== $item,
            )
        );
    }
}
