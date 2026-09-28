<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\Driver\TestDatabaseDriverFactory;
use Plan2net\PlaywrightToolkit\Http\SiteProbe;
use Plan2net\PlaywrightToolkit\Security\ProbeSignature;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use Plan2net\PlaywrightToolkit\Tests\ContractFixture;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SiteProbeTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $originalConnection = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'];
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = $this->originalConnection;
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);

        parent::tearDown();
    }

    #[Test]
    public function answersAValidProbeWithTheSelectedDatabaseAndTheSite(): void
    {
        $this->selectTestDatabase();

        $response = $this->probe($this->signature());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ContractFixture::read('site-probe')['response'], $this->json($response));
    }

    #[Test]
    public function reportsNoDatabaseWhenTheConnectionWasNotRedirected(): void
    {
        self::assertNull($this->json($this->probe($this->signature()))['database']);
    }

    #[Test]
    public function passesAWrongSignatureThrough(): void
    {
        self::assertSame(['passedThrough' => true], $this->json($this->probe('0000')));
    }

    #[Test]
    public function passesAProbeWithoutATestIdThrough(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        $signature = ProbeSignature::sign($this->get(TestApiSecret::class)->ensureExists(), '');

        self::assertSame(['passedThrough' => true], $this->json($this->probe($signature)));
    }

    #[Test]
    public function sitsBetweenSiteResolutionAndTheBaseRedirect(): void
    {
        $middlewares = require \dirname(__DIR__, 3) . '/Configuration/RequestMiddlewares.php';
        $probe = $middlewares['frontend']['plan2net/playwright-toolkit/site-probe'];

        self::assertSame(['typo3/cms-frontend/site'], $probe['after']);
        self::assertSame(['typo3/cms-frontend/base-redirect-resolver'], $probe['before']);
    }

    private function selectTestDatabase(): void
    {
        $driver = TestDatabaseDriverFactory::fromConnection($this->originalConnection);
        foreach ($driver->connectionOverrides(self::TEST_ID) as $path => $value) {
            $GLOBALS['TYPO3_CONF_VARS'] = ArrayUtility::setValueByPath($GLOBALS['TYPO3_CONF_VARS'], $path, $value);
        }
    }

    private function signature(): string
    {
        return ProbeSignature::sign($this->get(TestApiSecret::class)->ensureExists(), self::TEST_ID);
    }

    private function probe(string $signature, ?RequestHandlerInterface $handler = null): ResponseInterface
    {
        $request = (new ServerRequest('https://shop-testing.ddev.site/', 'GET'))
            ->withAttribute('site', new Site('shop', 2573, ['base' => 'https://shop-testing.ddev.site/', 'languages' => []]));
        if ('' !== $signature) {
            $request = $request->withHeader(SiteProbe::HEADER, $signature);
        }

        return $this->get(SiteProbe::class)->process($request, $handler ?? new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(['passedThrough' => true]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }
}
