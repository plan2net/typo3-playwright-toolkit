<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Imaging;

use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;

// Concurrent conversions of the same image would otherwise share one scratch path
// in typo3temp/assets/images, leaving the loser serving the unprocessed original.
final class TestScopedGraphicalFunctions
{
    public function create(): GraphicalFunctions
    {
        // Not makeInstance(): the container would hand the call straight back.
        $graphicalFunctions = new GraphicalFunctions();

        if (!Environment::getContext()->isTesting()) {
            return $graphicalFunctions;
        }

        $testId = TestContext::testId();
        if ('' !== $testId) {
            $graphicalFunctions->filenamePrefix = $testId . '-';
        }

        return $graphicalFunctions;
    }
}
