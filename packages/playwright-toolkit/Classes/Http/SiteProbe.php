<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

use Plan2net\PlaywrightToolkit\Database\DatabaseName;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\Entity\Site;

final class SiteProbe implements MiddlewareInterface
{
    public const HEADER = 'X-Playwright-Probe';

    public function __construct(
        private readonly TestApiSecret $secret,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Environment::getContext()->isTesting()) {
            return $handler->handle($request);
        }

        $testId = TestContext::testId();
        if ('' === $testId || !$this->secret->matchesProbe($testId, $request->getHeaderLine(self::HEADER))) {
            return $handler->handle($request);
        }

        $site = $request->getAttribute('site');

        return new JsonResponse([
            'testId' => $testId,
            'database' => self::selectedDatabase($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'], $testId),
            'site' => $site instanceof Site ? $site->getIdentifier() : null,
        ]);
    }

    /**
     * @param array<string, mixed> $connection
     */
    private static function selectedDatabase(array $connection, string $testId): ?string
    {
        foreach (TestDatabaseDriverFactory::fromConnection($connection)->connectionOverrides($testId) as $path => $value) {
            if (($connection[basename($path)] ?? null) !== $value) {
                return null;
            }
        }

        return DatabaseName::forTestId($testId);
    }
}
