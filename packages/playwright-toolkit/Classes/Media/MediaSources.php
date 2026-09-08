<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Media;

final class MediaSources
{
    /**
     * @var string
     */
    public const CONFIGURATION_FILE = 'media.json';

    /**
     * @var string
     */
    private const ONLINE_MEDIA_FIELD = 'onlineMediaId';

    /**
     * @var string
     */
    private const ONLINE_MEDIA_ID_PATTERN = '#^[A-Za-z0-9_\-/]+$#';

    /**
     * @return array<string, string> name => absolute path, ordered by name
     */
    public static function scan(string $mediaPath): array
    {
        $files = self::walk($mediaPath);
        unset($files[self::CONFIGURATION_FILE]);

        return $files;
    }

    public static function digest(string $mediaPath): string
    {
        $parts = [];
        foreach (self::walk($mediaPath) as $name => $path) {
            $parts[] = $name;
            $parts[] = (string) sha1_file($path);
        }

        return hash('sha256', implode("\0", $parts));
    }

    /**
     * @return array<string, array<string, string>> name => resolved fields
     */
    public static function metadata(string $mediaPath): array
    {
        $declared = self::declared($mediaPath);
        $names = array_merge(array_keys(self::scan($mediaPath)), array_keys(self::onlineMedia($mediaPath)));
        self::assertEveryEntryIsClaimed($declared, $names);

        $resolved = [];
        foreach ($names as $name) {
            $fields = array_merge(self::defaultsFor($declared, $name), $declared[$name] ?? []);
            unset($fields[self::ONLINE_MEDIA_FIELD]);

            if ([] !== $fields) {
                $resolved[$name] = $fields;
            }
        }

        ksort($resolved);

        return $resolved;
    }

    /**
     * @return array<string, string> name => online media id
     */
    public static function onlineMedia(string $mediaPath): array
    {
        $found = [];
        foreach (self::declared($mediaPath) as $name => $fields) {
            if (isset($fields[self::ONLINE_MEDIA_FIELD])) {
                $found[$name] = $fields[self::ONLINE_MEDIA_FIELD];
            }
        }

        return $found;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function declared(string $mediaPath): array
    {
        $file = rtrim($mediaPath, '/') . '/' . self::CONFIGURATION_FILE;
        if (!is_file($file)) {
            return [];
        }

        try {
            $declared = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $malformed) {
            throw new \RuntimeException(
                sprintf('%s cannot be read: %s', $file, $malformed->getMessage()),
                0,
                $malformed
            );
        }

        if (!\is_array($declared)) {
            return [];
        }

        foreach ($declared as $key => $fields) {
            if (!isset($fields[self::ONLINE_MEDIA_FIELD])) {
                continue;
            }

            if (str_ends_with((string) $key, '/')) {
                throw new \RuntimeException(sprintf(
                    'The media configuration declares %s on "%s", which names no file. Only'
                    . ' metadata fields belong on a prefix.',
                    self::ONLINE_MEDIA_FIELD,
                    $key
                ));
            }

            if (1 !== preg_match(self::ONLINE_MEDIA_ID_PATTERN, $fields[self::ONLINE_MEDIA_FIELD])) {
                throw new \RuntimeException(sprintf(
                    'The %s of "%s" is "%s". Use the video ID, not a URL.',
                    self::ONLINE_MEDIA_FIELD,
                    $key,
                    $fields[self::ONLINE_MEDIA_FIELD]
                ));
            }
        }

        return $declared;
    }

    /**
     * @param array<string, array<string, string>> $declared
     * @param list<string>                         $names
     */
    private static function assertEveryEntryIsClaimed(array $declared, array $names): void
    {
        foreach (array_keys($declared) as $key) {
            if (str_ends_with($key, '/') || \in_array($key, $names, true)) {
                continue;
            }

            throw new \RuntimeException(sprintf(
                'The media configuration describes "%s", but no such fixture exists and it declares'
                . ' no %s.',
                $key,
                self::ONLINE_MEDIA_FIELD
            ));
        }
    }

    /**
     * @param array<string, array<string, string>> $declared
     *
     * @return array<string, string>
     */
    private static function defaultsFor(array $declared, string $name): array
    {
        $longest = '';
        foreach (array_keys($declared) as $key) {
            if (!str_ends_with($key, '/') || !str_starts_with($name, $key)) {
                continue;
            }

            if (\strlen($key) > \strlen($longest)) {
                $longest = $key;
            }
        }

        return '' === $longest ? [] : $declared[$longest];
    }

    /**
     * @return array<string, string> name => absolute path, ordered by name
     */
    private static function walk(string $mediaPath): array
    {
        $root = rtrim($mediaPath, '/') . '/';

        $found = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            $name = substr($file->getPathname(), \strlen($root));
            // isFile() and is_dir() both follow a link.
            if ($file->isLink()) {
                throw new \RuntimeException(sprintf(
                    'The media fixture "%s" is a symbolic link. Fixtures must be regular files.',
                    $name
                ));
            }

            if (!$file->isFile()) {
                continue;
            }

            $found[$name] = $file->getPathname();
        }

        ksort($found);

        return $found;
    }
}
