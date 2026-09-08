<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Compatibility;

use Plan2net\PlaywrightToolkit\Imaging\GifBuilderOutput;
use Plan2net\PlaywrightToolkit\Imaging\TestScopedGraphicalFunctions;
use TYPO3\CMS\Frontend\Imaging\GifBuilder;

// 11.5 and 12.4 only: there GifBuilder extends GraphicalFunctions and runs the
// crop, scale and mask conversions itself. 13.4 and 14.3 convert through a
// GraphicalFunctions the container already scopes; AtomicOutputGifBuilder covers them.
final class ScopedGifBuilder extends GifBuilder
{
    public function __construct(private readonly string $scope = '')
    {
        parent::__construct();

        $this->filenamePrefix = $this->scope;
    }

    public static function create(): GifBuilder
    {
        $scope = TestScopedGraphicalFunctions::scope();

        return '' === $scope ? new GifBuilder() : new self($scope);
    }

    /**
     * @param string $file
     *
     * @return string
     */
    public function output($file)
    {
        GifBuilderOutput::write((string) $file, $this->scope, fn(string $scratch) => parent::output($scratch));

        return $file;
    }

    /**
     * A scratch file is named {filenamePrefix}{md5(source, parameters, mtime)}, so
     * the prefix is the only part of it that can tell two tests apart — and the
     * crop step sets it to a bare 'crop_' for the length of one call. Every test
     * cropping the same fixture then converts into crop_<hash>, with $mustCreate
     * on, and deletes it afterwards while the others are still reading it.
     *
     * @param string               $imagefile
     * @param string               $newExt
     * @param string               $w
     * @param string               $h
     * @param string               $params
     * @param string               $frame
     * @param array<string, mixed> $options
     * @param bool                 $mustCreate
     *
     * @return array<int, mixed>|null
     */
    public function imageMagickConvert($imagefile, $newExt = '', $w = '', $h = '', $params = '', $frame = '', $options = [], $mustCreate = false)
    {
        if (!str_starts_with((string) $this->filenamePrefix, $this->scope)) {
            $this->filenamePrefix = $this->scope . $this->filenamePrefix;
        }

        return parent::imageMagickConvert($imagefile, $newExt, $w, $h, $params, $frame, $options, $mustCreate);
    }
}
