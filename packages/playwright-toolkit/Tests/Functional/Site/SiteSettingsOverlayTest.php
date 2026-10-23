<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Site;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Site\SiteSettingsOverlay;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Frontend\Cache\CacheInstruction;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SiteSettingsOverlayTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(SiteSettings::class)) {
            self::markTestSkipped('Site settings arrived in TYPO3 12.4');
        }
    }

    #[Test]
    public function overlaysTheStoredValuesOnTheSiteSettings(): void
    {
        $this->get(Registry::class)->set(SiteSettingsOverlay::REGISTRY_NAMESPACE, 'shop', ['theme.look' => 'wild']);

        $settings = $this->settingsSeenBy($this->site(['theme' => ['look' => 'calm', 'logo' => 'big']]));

        self::assertSame('wild', $settings->get('theme.look'));
        self::assertSame('big', $settings->get('theme.logo'));
    }

    #[Test]
    public function turnsCachingOffBecauseNoTypoScriptCacheKeyCoversTheSettings(): void
    {
        $this->get(Registry::class)->set(SiteSettingsOverlay::REGISTRY_NAMESPACE, 'shop', ['theme.look' => 'wild']);

        $request = $this->requestSeenBy($this->site(['theme' => ['look' => 'calm']]));

        if (!class_exists(CacheInstruction::class)) {
            // @phpstan-ignore phpstanTypo3.requestAttributeValidation
            self::assertTrue($request->getAttribute('noCache'));

            return;
        }

        $instruction = $request->getAttribute('frontend.cache.instruction');
        self::assertInstanceOf(CacheInstruction::class, $instruction);
        self::assertFalse($instruction->isCachingAllowed());
    }

    #[Test]
    public function leavesTheSiteAloneOutsideATestingContext(): void
    {
        $this->get(Registry::class)->set(SiteSettingsOverlay::REGISTRY_NAMESPACE, 'shop', ['theme.look' => 'wild']);
        $original = Environment::getContext();
        $this->reinitializeWith(new ApplicationContext('Production'));

        try {
            $settings = $this->settingsSeenBy($this->site(['theme' => ['look' => 'calm']]));
        } finally {
            $this->reinitializeWith($original);
        }

        self::assertSame('calm', $settings->get('theme.look'));
    }

    #[Test]
    public function runsRightAfterTheSiteIsResolved(): void
    {
        $middlewares = require \dirname(__DIR__, 3) . '/Configuration/RequestMiddlewares.php';
        $overlay = $middlewares['frontend']['plan2net/playwright-toolkit/site-settings'];

        self::assertSame(SiteSettingsOverlay::class, $overlay['target']);
        self::assertSame(['typo3/cms-frontend/site'], $overlay['after']);
        self::assertSame(['typo3/cms-frontend/base-redirect-resolver'], $overlay['before']);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function site(array $settings): Site
    {
        return new Site('shop', 1, ['base' => 'https://shop-testing.ddev.site/', 'languages' => [], 'settings' => $settings]);
    }

    private function reinitializeWith(ApplicationContext $context): void
    {
        Environment::initialize(
            $context,
            Environment::isCli(),
            Environment::isComposerMode(),
            Environment::getProjectPath(),
            Environment::getPublicPath(),
            Environment::getVarPath(),
            Environment::getConfigPath(),
            Environment::getCurrentScript(),
            Environment::isWindows() ? 'WINDOWS' : 'UNIX',
        );
    }

    private function settingsSeenBy(Site $site): SiteSettings
    {
        $seenSite = $this->requestSeenBy($site)->getAttribute('site');
        self::assertInstanceOf(Site::class, $seenSite);

        return $seenSite->getSettings();
    }

    private function requestSeenBy(Site $site): ServerRequestInterface
    {
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $seen = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request;

                return new JsonResponse([]);
            }
        };

        $request = (new ServerRequest('https://shop-testing.ddev.site/', 'GET'))->withAttribute('site', $site);
        $this->get(SiteSettingsOverlay::class)->process($request, $handler);
        self::assertNotNull($handler->seen);

        return $handler->seen;
    }
}
