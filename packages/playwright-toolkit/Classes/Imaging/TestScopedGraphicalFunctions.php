<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Imaging;

use Plan2net\PlaywrightToolkit\Compatibility\ScopedGifBuilder;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Frontend\Imaging\GifBuilder;

// Unique per conversion, not per test: two conversions of the same image otherwise
// share one scratch path, and whichever finishes second serves the original image.
final class TestScopedGraphicalFunctions
{
    public function create(): GraphicalFunctions
    {
        // Not makeInstance(): the container would hand the call straight back.
        $graphicalFunctions = new GraphicalFunctions();
        $graphicalFunctions->filenamePrefix = self::scope();

        return $graphicalFunctions;
    }

    // 11.5 and 12.4 crop, scale and mask on GifBuilder rather than on
    // GraphicalFunctions, so the scope has to reach this class too.
    public function createGifBuilder(): GifBuilder
    {
        $scope = self::scope();
        if ('' === $scope || !property_exists(GifBuilder::class, 'filenamePrefix')) {
            return new GifBuilder();
        }

        return new ScopedGifBuilder($scope);
    }

    private static function scope(): string
    {
        if (!Environment::getContext()->isTesting()) {
            return '';
        }

        $testId = TestContext::testId();

        // The test ID stays in front so cleanup can still collect these.
        return '' === $testId ? '' : $testId . '-' . bin2hex(random_bytes(6)) . '-';
    }
}
