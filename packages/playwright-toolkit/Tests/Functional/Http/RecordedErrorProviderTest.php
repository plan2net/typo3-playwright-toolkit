<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Http\RecordedErrorProvider;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordedErrorProviderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function tearDown(): void
    {
        @unlink($this->get(TestApiSecret::class)->file());
        parent::tearDown();
    }

    #[Test]
    public function refusesATestIdThatIsNotOneOfOurs(): void
    {
        $response = $this->call('../../../etc/passwd');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function answersAnEmptyListWhenTheTestDatabaseIsNotThere(): void
    {
        $response = $this->call('ZZZZ9999ZZZZ9999');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['success' => true, 'testId' => 'ZZZZ9999ZZZZ9999', 'truncated' => false, 'errors' => []],
            json_decode((string) $response->getBody(), true)
        );
    }

    private function call(string $testId): ResponseInterface
    {
        $request = new ServerRequest('https://example.test/test-api/errors?id=' . rawurlencode($testId), 'GET');
        $request = $request->withQueryParams(['id' => $testId]);
        $request = $request->withHeader(TestApiSecret::HEADER, $this->get(TestApiSecret::class)->ensureExists());

        return $this->get(RecordedErrorProvider::class)->process($request, $this->passThroughHandler());
    }

    private function passThroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(['passedThrough' => true]);
            }
        };
    }
}
