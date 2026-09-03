<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class DdevHostname
{
    /**
     * @return array{flag: string, entry: string}
     */
    public static function entryFor(string $testingUrl, string $tld): array
    {
        $host = (string) parse_url($testingUrl, PHP_URL_HOST);
        $suffix = '.' . $tld;

        if (!str_ends_with($host, $suffix)) {
            return ['flag' => 'additional-fqdns', 'entry' => $host];
        }

        return [
            'flag' => 'additional-hostnames',
            'entry' => substr($host, 0, -\strlen($suffix)),
        ];
    }

    // The flag replaces the list, so it must carry every entry.
    public static function flagFor(string $projectPath, string $testingUrl, string $tld): ?string
    {
        ['flag' => $flag, 'entry' => $entry] = self::entryFor($testingUrl, $tld);
        $entries = self::configured($projectPath, $flag);

        if (\in_array($entry, $entries, true)) {
            return null;
        }

        return '--' . $flag . '=' . implode(',', [...$entries, $entry]);
    }

    /**
     * @return list<string>
     */
    public static function configured(string $projectPath, string $flag): array
    {
        $key = str_replace('-', '_', $flag);
        $entries = [];

        foreach (self::configFiles($projectPath) as $file) {
            try {
                $configuration = Yaml::parseFile($file);
            } catch (ParseException) {
                // Someone else's file. `ddev config` will complain about it, not us.
                continue;
            }

            if (!\is_array($configuration)) {
                continue;
            }

            $configured = $configuration[$key] ?? [];
            if (!\is_array($configured)) {
                continue;
            }

            $added = array_values(array_filter($configured, 'is_string'));
            $entries = true === ($configuration['override_config'] ?? false)
                ? $added
                : [...$entries, ...$added];
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private static function configFiles(string $projectPath): array
    {
        $files = array_filter([$projectPath . '/.ddev/config.yaml'], 'is_file');
        // DDEV reads these in alphabetical order, and so does glob.
        $overrides = glob($projectPath . '/.ddev/config.*.yaml') ?: [];

        return [...$files, ...$overrides];
    }
}
