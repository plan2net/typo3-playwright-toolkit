<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

use Plan2net\PlaywrightToolkit\Configuration\BackendSettings;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final class TestApi
{
    public static function matches(string $path, string ...$endpoints): bool
    {
        $entryPoint = BackendSettings::entryPoint();
        foreach ($endpoints as $endpoint) {
            if ($path === $endpoint || $path === $entryPoint . $endpoint) {
                return true;
            }
        }

        return false;
    }

    /**
     * The second gate every endpoint shares: the Testing context decides whether
     * an endpoint exists, this decides who may call it.
     *
     * @return ResponseInterface|null null when the caller may proceed
     */
    public static function refuse(
        ServerRequestInterface $request,
        TestApiSecret $secret,
        string $method,
    ): ?ResponseInterface {
        if (!$secret->matches($request->getHeaderLine(TestApiSecret::HEADER))) {
            return TestApiSecret::unauthorizedResponse();
        }

        if ($method !== $request->getMethod()) {
            return self::error('Method not allowed', 405);
        }

        return null;
    }

    public static function error(string $message, int $status): ResponseInterface
    {
        return new JsonResponse(['success' => false, 'error' => $message], $status);
    }
}
