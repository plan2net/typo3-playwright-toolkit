<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup\Check;

use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\Setup\Result;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use TYPO3\CMS\Core\Http\Request;

final class TestingHost
{
    /**
     * The command reads this back to decide whether a webserver hint helps.
     *
     * @var string
     */
    public const WRONG_CONTEXT = 'answers, but not in a Testing context';

    /**
     * @var string
     */
    private const HEALTH_PATH = '/typo3/test-api/health';

    public function __construct(
        private readonly string $testingUrl,
        private readonly string $secret,
        private readonly ClientInterface $client,
    ) {
    }

    public function run(): Result
    {
        $url = $this->testingUrl . self::HEALTH_PATH;

        try {
            $response = $this->client->sendRequest(new Request($url, 'GET', 'php://temp', [
                TestApiSecret::HEADER => $this->secret,
            ]));
        } catch (ClientExceptionInterface $exception) {
            return Result::fail($url . ' is not reachable: ' . self::short($exception->getMessage()));
        }

        if (404 === $response->getStatusCode()) {
            return Result::fail($this->testingUrl . ' ' . self::WRONG_CONTEXT);
        }

        if (401 === $response->getStatusCode()) {
            return Result::fail('the test API refuses this secret');
        }

        $body = json_decode((string) $response->getBody(), true);
        $body = \is_array($body) ? $body : [];
        $api = $body['api'] ?? null;

        if (TestContext::API_VERSION !== $api) {
            return Result::fail(sprintf(
                'the extension on that host answers with API version %s, this one is %d',
                var_export($api, true),
                TestContext::API_VERSION
            ));
        }

        $context = $body['checks']['context'] ?? [];
        if (true !== ($context['ok'] ?? false)) {
            return Result::fail(sprintf(
                'the test API answers, but it runs in the %s context',
                (string) ($context['context'] ?? 'wrong')
            ));
        }

        // The origin, not the health path: the table has one line per check.
        return Result::pass($this->testingUrl);
    }

    // Curl's message adds a doc link and repeats the URL, which the table cannot fit.
    private static function short(string $message): string
    {
        return rtrim(explode(' (see ', $message)[0], ' .');
    }
}
