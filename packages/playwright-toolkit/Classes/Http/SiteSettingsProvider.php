<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\Site\SiteSettingsOverlay;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Registry;

final class SiteSettingsProvider implements MiddlewareInterface
{
    /**
     * @var string
     */
    private const PATH = '/test-api/site-settings';

    public function __construct(
        private readonly Registry $registry,
        private readonly TestApiSecret $secret,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Environment::getContext()->isTesting()) {
            return $handler->handle($request);
        }

        if (!TestApi::matches($request->getUri()->getPath(), self::PATH)) {
            return $handler->handle($request);
        }

        $refusal = TestApi::refuse($request, $this->secret, 'POST');
        if (null !== $refusal) {
            return $refusal;
        }

        if ('' === TestContext::testId()) {
            return TestApi::error('Expected an X-Playwright-Test-Id header: without one the settings would apply to every request', 400);
        }

        $payload = json_decode((string) $request->getBody(), true);
        $site = is_array($payload) && is_string($payload['site'] ?? null) ? $payload['site'] : '';
        $settings = is_array($payload) && is_array($payload['settings'] ?? null) ? $payload['settings'] : null;
        if ('' === $site || null === $settings) {
            return TestApi::error('Expected a "site" identifier and a "settings" object', 400);
        }

        $this->registry->set(SiteSettingsOverlay::REGISTRY_NAMESPACE, $site, $settings);

        return new JsonResponse(['ok' => true]);
    }
}
