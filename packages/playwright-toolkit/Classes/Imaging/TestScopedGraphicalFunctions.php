<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Imaging;

use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// Unique per conversion, not per test: two conversions of the same image otherwise
// share one scratch path, and whichever finishes second serves the original image.
final class TestScopedGraphicalFunctions
{
    public function create(): GraphicalFunctions
    {
        // Not makeInstance(): the container would hand the call straight back.
        $graphicalFunctions = GeneralUtility::makeInstanceForDi(GraphicalFunctions::class);
        $graphicalFunctions->filenamePrefix = self::scope();

        return $graphicalFunctions;
    }

    public static function scope(): string
    {
        if (!Environment::getContext()->isTesting()) {
            return '';
        }

        $testId = TestContext::testId();

        // The test ID stays in front so cleanup can still collect these.
        return '' === $testId ? '' : $testId . '-' . bin2hex(random_bytes(6)) . '-';
    }
}
