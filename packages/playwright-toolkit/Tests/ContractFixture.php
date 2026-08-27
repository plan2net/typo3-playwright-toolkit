<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests;

/**
 * The fixtures sit at the monorepo root, beside the npm package that asserts the
 * same bytes. The split repository carries a copy at its own root, so both
 * layouts have to resolve.
 */
final class ContractFixture
{
    public static function contents(string $name): string
    {
        $package = dirname(__DIR__);

        foreach ([$package, dirname($package, 2)] as $root) {
            $path = $root . '/contract/' . $name . '.json';
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        throw new \RuntimeException(sprintf('No contract fixture "%s" beside or above %s', $name, $package));
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(string $name): array
    {
        $decoded = (array) json_decode(self::contents($name), true, flags: JSON_THROW_ON_ERROR);
        unset($decoded['_comment']);

        return $decoded;
    }
}
