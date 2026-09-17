<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\DatabaseName;
use Plan2net\PlaywrightToolkit\Http\SetupCacheProvider;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SetupCacheProviderTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const KEY = '0123456789abcdef0123456789abcdef';

    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    #[Test]
    public function answersAbsentForAKeyNothingHasStored(): void
    {
        $response = $this->post('/typo3/test-api/setup-cache/restore', [
            'testId' => self::TEST_ID,
            'key' => self::KEY,
        ]);

        self::assertSame(['ok' => true, 'outcome' => 'absent', 'state' => []], $this->json($response));
    }

    #[Test]
    public function refusesATestIdThatIsNotContractShaped(): void
    {
        $response = $this->post('/typo3/test-api/setup-cache/restore', [
            'testId' => 'DROP DATABASE db',
            'key' => self::KEY,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function refusesTheReplayTestId(): void
    {
        $response = $this->post('/typo3/test-api/setup-cache/restore', [
            'testId' => DatabaseName::REPLAY_TEST_ID,
            'key' => self::KEY,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    #[DataProvider('endpointsAndContextsThatMustNeverAnswer')]
    public function passesThroughOutsideATestingContext(string $operation, string $context): void
    {
        $original = Environment::getContext();
        $this->reinitializeWith(new ApplicationContext($context));

        try {
            $response = $this->post('/typo3/test-api/setup-cache/' . $operation, [
                'testId' => self::TEST_ID,
                'key' => self::KEY,
            ]);

            self::assertStringContainsString('passedThrough', (string) $response->getBody());
            self::assertStringNotContainsString('outcome', (string) $response->getBody());
        } finally {
            $this->reinitializeWith($original);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function endpointsAndContextsThatMustNeverAnswer(): iterable
    {
        foreach (['restore', 'store'] as $operation) {
            foreach (['Production', 'Production/Staging', 'Development', 'Development/Local'] as $context) {
                yield $operation . ' in ' . $context => [$operation, $context];
            }
        }
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

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $path, array $payload): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write((string) json_encode($payload));
        $stream->rewind();

        $request = (new ServerRequest('https://example.test' . $path, 'POST'))
            ->withHeader(TestApiSecret::HEADER, $this->get(TestApiSecret::class)->ensureExists())
            ->withBody($stream);

        return $this->get(SetupCacheProvider::class)->process($request, $this->passThroughHandler());
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
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
