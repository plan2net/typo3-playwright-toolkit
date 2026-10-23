<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Site;

use Plan2net\PlaywrightToolkit\Compatibility\SiteWithSettings;
use Plan2net\PlaywrightToolkit\Compatibility\UncachedRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ArrayUtility;

final class SiteSettingsOverlay implements MiddlewareInterface
{
    /**
     * @var string
     */
    public const REGISTRY_NAMESPACE = 'playwright_toolkit_site_settings';

    public function __construct(
        private readonly Registry $registry,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Environment::getContext()->isTesting()) {
            return $handler->handle($request);
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $handler->handle($request);
        }

        $overrides = $this->registry->get(self::REGISTRY_NAMESPACE, $site->getIdentifier());
        if (!is_array($overrides) || [] === $overrides) {
            return $handler->handle($request);
        }

        $settings = SiteWithSettings::settingsOf($site);
        foreach ($overrides as $path => $value) {
            $settings = ArrayUtility::setValueByPath($settings, (string) $path, $value, '.');
        }

        $request = UncachedRequest::of($request, 'EXT:playwright_toolkit: the test overrides site settings, which no TypoScript cache key covers');

        return $handler->handle($request->withAttribute('site', SiteWithSettings::create($site, $settings)));
    }
}
