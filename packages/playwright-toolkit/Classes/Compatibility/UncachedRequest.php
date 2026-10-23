<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Compatibility;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Frontend\Cache\CacheInstruction;

final class UncachedRequest
{
    public static function of(ServerRequestInterface $request, string $reason): ServerRequestInterface
    {
        if (!class_exists(CacheInstruction::class)) {
            return $request->withAttribute('noCache', true);
        }

        $instruction = $request->getAttribute('frontend.cache.instruction', new CacheInstruction());
        $instruction->disableCache($reason);

        return $request->withAttribute('frontend.cache.instruction', $instruction);
    }
}
