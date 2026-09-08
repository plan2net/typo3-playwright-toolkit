<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Imaging;

// gifBuild() treats an existing file as finished, so the image is encoded next to
// its path and renamed into place.
final class GifBuilderOutput
{
    /**
     * @param callable(string): mixed $encode
     */
    public static function write(string $file, string $scope, callable $encode): void
    {
        // Starts with the test ID so cleanup can sweep a leftover.
        $scratch = dirname($file) . '/' . $scope . basename($file);

        try {
            $encode($scratch);

            if (is_file($scratch)) {
                rename($scratch, $file);
            }
        } finally {
            if (is_file($scratch)) {
                unlink($scratch);
            }
        }
    }
}
