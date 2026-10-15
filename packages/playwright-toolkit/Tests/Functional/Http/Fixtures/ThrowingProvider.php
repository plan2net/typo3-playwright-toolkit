<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http\Fixtures;

use TYPO3\CMS\Backend\Form\FormDataProviderInterface;

final class ThrowingProvider implements FormDataProviderInterface
{
    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function addData(array $result): array
    {
        throw new \RuntimeException('invalid input syntax for type integer: ""');
    }
}
