<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Setup\Check;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Setup\Check\TestingHost;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final class TestingHostTest extends TestCase
{
    private const TESTING_URL = 'https://example-testing.ddev.site';

    #[Test]
    public function failsWhenTheHostCannotBeReached(): void
    {
        $client = self::clientThrowing('Could not resolve host: example-testing.ddev.site');

        $result = (new TestingHost(self::TESTING_URL, 'secret', $client))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('resolve host', $result->detail);
    }

    #[Test]
    public function keepsTheReachabilityReasonShortEnoughForTheTable(): void
    {
        $client = self::clientThrowing(
            'cURL error 6: Could not resolve host: example-testing.ddev.site '
            . '(see https://curl.se/libcurl/c/libcurl-errors.html) for '
            . 'https://example-testing.ddev.site/typo3/test-api/health'
        );

        $detail = (new TestingHost(self::TESTING_URL, 'secret', $client))->run()->detail;

        self::assertStringContainsString('Could not resolve host', $detail);
        self::assertStringNotContainsString('curl.se', $detail);
    }

    #[Test]
    public function failsWhenTheHostIsNotInATestingContext(): void
    {
        $result = (new TestingHost(self::TESTING_URL, 'secret', self::clientReturning(404)))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('Testing context', $result->detail);
    }

    #[Test]
    public function failsWhenTheSecretDoesNotMatch(): void
    {
        $result = (new TestingHost(self::TESTING_URL, 'secret', self::clientReturning(401)))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('secret', $result->detail);
    }

    #[Test]
    public function passesWhenTheContextAnswersWithAMatchingApiVersion(): void
    {
        $client = self::clientReturning(503, [
            'ok' => false,
            'api' => TestContext::API_VERSION,
            'checks' => ['context' => ['ok' => true], 'database' => ['ok' => false]],
        ]);

        self::assertTrue((new TestingHost(self::TESTING_URL, 'secret', $client))->run()->passed);
    }

    #[Test]
    public function failsWhenAnotherApiVersionAnswers(): void
    {
        $client = self::clientReturning(503, [
            'api' => TestContext::API_VERSION + 1,
            'checks' => ['context' => ['ok' => true]],
        ]);

        $result = (new TestingHost(self::TESTING_URL, 'secret', $client))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString((string) (TestContext::API_VERSION + 1), $result->detail);
    }

    #[Test]
    public function failsWhenTheContextCheckItselfFails(): void
    {
        $client = self::clientReturning(503, [
            'api' => TestContext::API_VERSION,
            'checks' => ['context' => ['ok' => false, 'context' => 'Development']],
        ]);

        $result = (new TestingHost(self::TESTING_URL, 'secret', $client))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('Development', $result->detail);
    }

    private static function clientThrowing(string $message): ClientInterface
    {
        return new class($message) implements ClientInterface {
            public function __construct(private readonly string $message)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class($this->message) extends \RuntimeException implements ClientExceptionInterface {
                };
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function clientReturning(int $status, array $body = []): ClientInterface
    {
        return new class(new JsonResponse($body, $status)) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
