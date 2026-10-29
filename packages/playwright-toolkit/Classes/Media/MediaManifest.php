<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Media;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class MediaManifest
{
    public function __construct(
        private readonly string $manifestFile,
    ) {
    }

    public static function inVarPath(): self
    {
        return new self(Environment::getVarPath() . '/playwright/media.json');
    }

    public function file(): string
    {
        return $this->manifestFile;
    }

    public function metadataFile(): string
    {
        return \dirname($this->manifestFile) . '/media-metadata.json';
    }

    /**
     * @param array<string, int> $uidsByName
     * @param array<string, int> $metadataUidsByName
     */
    public function write(array $uidsByName, array $metadataUidsByName = []): void
    {
        self::writeFile($this->manifestFile, $uidsByName);
        self::writeFile($this->metadataFile(), $metadataUidsByName);
    }

    public function remove(): void
    {
        foreach ([$this->manifestFile, $this->metadataFile()] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param array<string, int> $uidsByName
     */
    private static function writeFile(string $file, array $uidsByName): void
    {
        $json = json_encode((object) $uidsByName, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $directory = \dirname($file);
        if (!is_dir($directory)) {
            try {
                GeneralUtility::mkdir_deep($directory);
            } catch (\RuntimeException $failure) {
                throw new \RuntimeException(
                    sprintf('Could not write the media manifest %s. %s', $file, $failure->getMessage()),
                    0,
                    $failure
                );
            }
        }

        // file_put_contents reports a short write only in its return value.
        if (@file_put_contents($file, $json) !== \strlen($json)) {
            throw new \RuntimeException(sprintf('Could not write the media manifest %s.', $file));
        }
    }
}
