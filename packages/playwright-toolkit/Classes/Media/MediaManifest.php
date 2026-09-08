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

    /**
     * @param array<string, int> $uidsByName
     */
    public function write(array $uidsByName): void
    {
        $json = json_encode($uidsByName, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $directory = \dirname($this->manifestFile);
        if (!is_dir($directory)) {
            try {
                GeneralUtility::mkdir_deep($directory);
            } catch (\RuntimeException $failure) {
                throw new \RuntimeException(
                    sprintf('Could not write the media manifest %s. %s', $this->manifestFile, $failure->getMessage()),
                    0,
                    $failure
                );
            }
        }

        // file_put_contents reports a short write only in its return value.
        if (@file_put_contents($this->manifestFile, $json) !== \strlen($json)) {
            throw new \RuntimeException(sprintf('Could not write the media manifest %s.', $this->manifestFile));
        }
    }

    public function remove(): void
    {
        if (is_file($this->manifestFile)) {
            unlink($this->manifestFile);
        }
    }
}
