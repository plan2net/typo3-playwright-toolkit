<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Log;

use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\DatabaseWriter;

final class ErrorCapture
{
    /**
     * @param array<string, mixed> $logConfiguration
     *
     * @return array<string, array<string, mixed>>
     */
    public static function settings(array $logConfiguration): array
    {
        return self::writerPathFor($logConfiguration, 'LOG') + self::nestedWriterPaths($logConfiguration, 'LOG');
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, array<string, mixed>>
     */
    private static function nestedWriterPaths(array $node, string $path): array
    {
        $paths = [];

        foreach ($node as $key => $child) {
            if ('writerConfiguration' === $key || !\is_array($child)) {
                continue;
            }

            $childPath = $path . '/' . $key;

            // LogManager picks the most specific configuration instead of merging,
            // so a logger with one of its own never sees the root writer.
            if (isset($child['writerConfiguration'])) {
                $paths += self::writerPathFor($child, $childPath);
            }

            $paths += self::nestedWriterPaths($child, $childPath);
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, array<string, mixed>>
     */
    private static function writerPathFor(array $node, string $path): array
    {
        if (isset($node['writerConfiguration'][LogLevel::ERROR][DatabaseWriter::class])) {
            return [];
        }

        return [$path . '/writerConfiguration/' . LogLevel::ERROR . '/' . DatabaseWriter::class => []];
    }
}
