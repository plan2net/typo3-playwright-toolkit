<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Compatibility;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroupInterface;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class FormEngine
{
    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public static function compile(array $input, FormDataGroupInterface $group, ServerRequestInterface $request): array
    {
        $compile = new \ReflectionMethod(FormDataCompiler::class, 'compile');
        if ($compile->getNumberOfParameters() < 2) {
            return (array) $compile->invoke(GeneralUtility::makeInstance(FormDataCompiler::class, $group), $input);
        }

        return GeneralUtility::makeInstance(FormDataCompiler::class)->compile(['request' => $request, ...$input], $group);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function isRequired(array $config): bool
    {
        return !empty($config['required'])
            || \in_array('required', GeneralUtility::trimExplode(',', (string) ($config['eval'] ?? ''), true), true);
    }

    public static function checksMinimumLength(): bool
    {
        return (new Typo3Version())->getMajorVersion() >= 12;
    }
}
