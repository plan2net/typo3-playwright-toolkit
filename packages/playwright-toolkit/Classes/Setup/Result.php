<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup;

final class Result
{
    /**
     * @param list<string> $missingFiles
     */
    private function __construct(
        public readonly bool $passed,
        public readonly string $detail,
        public readonly array $missingFiles = [],
    ) {
    }

    /**
     * For a check whose work already throws a message the user can act on.
     *
     * @param callable(): string $assert
     */
    public static function of(callable $assert): self
    {
        try {
            return self::pass($assert());
        } catch (\Throwable $failure) {
            return self::fail($failure->getMessage());
        }
    }

    public static function pass(string $detail): self
    {
        return new self(true, $detail);
    }

    public static function fail(string $reason, string ...$missingFiles): self
    {
        return new self(false, $reason, array_values($missingFiles));
    }
}
