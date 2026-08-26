<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database;

use Plan2net\PlaywrightToolkit\Database\Driver\TemplateSeed;

// The hash covers the bytes that were read, not a second read of the sources.
final class SeedSnapshot
{
    /**
     * @param list<string>          $schemaStatements
     * @param array<string, string> $fixtures         filename => contents, in manifest order
     */
    public function __construct(
        public readonly array $schemaStatements,
        public readonly array $fixtures,
        public readonly string $plainSessionId,
        public readonly int $sessionUserId,
        public readonly string $fingerprint,
    ) {
    }

    public function templateSeed(): TemplateSeed
    {
        return new TemplateSeed(
            fixtures: $this->fixtures,
            plainSessionId: $this->plainSessionId,
            sessionUserId: $this->sessionUserId,
        );
    }
}
