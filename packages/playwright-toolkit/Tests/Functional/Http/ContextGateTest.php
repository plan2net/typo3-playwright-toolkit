<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Http\DatabaseCleanupProvider;
use Plan2net\PlaywrightToolkit\Http\HealthCheckProvider;
use Plan2net\PlaywrightToolkit\Http\InspectProvider;
use Plan2net\PlaywrightToolkit\Http\RecordedErrorProvider;
use Plan2net\PlaywrightToolkit\Http\RecordEditDiagnostics;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\Session\BackendSessionProvider;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Every gate is tested against the strongest case: a valid secret and a valid
 * test ID.
 */
final class ContextGateTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';

    /**
     * In the order RequestMiddlewares.php registers them.
     *
     * @var array<class-string, array{string, string}>
     */
    private const ENDPOINTS = [
        BackendSessionProvider::class => ['/typo3/test-api/session', 'POST'],
        HealthCheckProvider::class => ['/typo3/test-api/health', 'GET'],
        DatabaseCleanupProvider::class => ['/typo3/test-api/databases/drop', 'POST'],
        InspectProvider::class => ['/typo3/test-api/inspect', 'GET'],
        RecordedErrorProvider::class => ['/typo3/test-api/errors', 'GET'],
        RecordEditDiagnostics::class => ['/typo3/record/edit', 'POST'],
    ];

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function tearDown(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY], $_SERVER[TestApiSecret::SERVER_KEY]);

        foreach (glob(Environment::getVarPath() . '/test-locks/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }

        parent::tearDown();
    }

    #[Test]
    #[DataProvider('endpointsAndContexts')]
    public function passesEveryRequestThroughOutsideATestingContext(
        string $middleware,
        string $path,
        string $method,
        string $context,
    ): void {
        $original = Environment::getContext();
        $this->reinitializeWith(new ApplicationContext($context));

        try {
            $response = $this->dispatch($middleware, $path, $method);

            self::assertSame('{"passedThrough":true}', (string) $response->getBody());
            foreach (array_keys($response->getHeaders()) as $name) {
                self::assertStringStartsNotWith('x-playwright', strtolower((string) $name));
            }
        } finally {
            $this->reinitializeWith($original);
        }
    }

    /**
     * @return \Generator<string, array{string, string, string, string}>
     */
    public static function endpointsAndContexts(): \Generator
    {
        foreach (['Production', 'Production/Staging', 'Development', 'Development/Local'] as $context) {
            foreach (self::ENDPOINTS as $middleware => [$path, $method]) {
                $label = substr((string) strrchr($middleware, '\\'), 1);

                yield $label . ' in ' . $context => [$middleware, $path, $method, $context];
            }
        }
    }

    /** Without this, every assertion above would pass on a typo in the path. */
    #[Test]
    #[DataProvider('endpoints')]
    public function answersTheSameRequestInsideATestingContext(
        string $middleware,
        string $path,
        string $method,
    ): void {
        self::assertTrue(Environment::getContext()->isTesting());

        $response = $this->dispatch($middleware, $path, $method);

        self::assertNotSame('{"passedThrough":true}', (string) $response->getBody());
    }

    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function endpoints(): \Generator
    {
        foreach (self::ENDPOINTS as $middleware => [$path, $method]) {
            yield substr((string) strrchr($middleware, '\\'), 1) => [$middleware, $path, $method];
        }
    }

    /** A middleware added without a row above would have its gate tested by nothing. */
    #[Test]
    public function everyRegisteredMiddlewareIsCoveredHere(): void
    {
        /** @var array<string, array<string, array{target: class-string}>> $stacks */
        $stacks = require \dirname(__DIR__, 3) . '/Configuration/RequestMiddlewares.php';

        $registered = [];
        foreach ($stacks as $stack) {
            foreach ($stack as $middleware) {
                $registered[$middleware['target']] = true;
            }
        }

        self::assertSame(
            array_keys($registered),
            array_keys(self::ENDPOINTS),
            'RequestMiddlewares.php and this test disagree about which middlewares exist'
        );
    }

    private function dispatch(string $middleware, string $path, string $method): ResponseInterface
    {
        $secret = $this->get(TestApiSecret::class)->ensureExists();

        // What a real test run sends, so only the context gate can stop it.
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
        $_SERVER[TestApiSecret::SERVER_KEY] = $secret;

        $body = new Stream('php://temp', 'rw');
        $body->write((string) json_encode(['testIds' => [self::TEST_ID]]));
        $body->rewind();

        // An empty datamap comes straight back, so an unknown column is what makes an
        // ungated RecordEditDiagnostics answer 422 instead of looking like a pass-through.
        $request = (new ServerRequest('https://example.test' . $path . '?id=' . self::TEST_ID, $method))
            ->withHeader(TestApiSecret::HEADER, $secret)
            ->withHeader(TestContext::TEST_ID_HEADER, self::TEST_ID)
            ->withParsedBody(['data' => ['pages' => ['NEW1' => ['thisColumnDoesNotExist' => 'x']]]])
            ->withBody($body);

        /** @var MiddlewareInterface $instance */
        $instance = $this->get($middleware);

        return $instance->process($request, $this->passThroughHandler());
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
}
