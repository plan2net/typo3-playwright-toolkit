<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Compatibility;

use Plan2net\PlaywrightToolkit\Imaging\GifBuilderOutput;
use Plan2net\PlaywrightToolkit\Imaging\TestScopedGraphicalFunctions;
use TYPO3\CMS\Frontend\Imaging\GifBuilder;

// 13.4 and 14.3 only; ScopedGifBuilder does the same for 11.5 and 12.4.
final class AtomicOutputGifBuilder extends GifBuilder
{
    public function __construct(private readonly string $scope)
    {
        parent::__construct();
    }

    public static function create(): GifBuilder
    {
        $scope = TestScopedGraphicalFunctions::scope();

        return '' === $scope ? new GifBuilder() : new self($scope);
    }

    #[\Override]
    protected function output(\GdImage $gdImage, string $file): void
    {
        GifBuilderOutput::write($file, $this->scope, fn(string $scratch) => parent::output($gdImage, $scratch));
    }
}
