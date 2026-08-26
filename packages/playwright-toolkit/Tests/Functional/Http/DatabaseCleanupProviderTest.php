<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http;

use Plan2net\PlaywrightToolkit\Database\Cleanup\LockFiles;
use Plan2net\PlaywrightToolkit\Database\DatabaseName;
use Plan2net\PlaywrightToolkit\Http\DatabaseCleanupProvider;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use Plan2net\PlaywrightToolkit\Tests\ContractFixture;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DatabaseCleanupProviderTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';
    /**
     * @var string
     */
    private const PATH = '/typo3/test-api/databases/drop';
    /**
     * @var string
     */
    private const SWEEP_PATH = '/typo3/test-api/databases/sweep';
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function tearDown(): void
    {
        foreach (glob(Environment::getVarPath() . '/test-locks/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        foreach (glob(Environment::getVarPath() . '/test-databases/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }

        parent::tearDown();
    }

    /**
     * The endpoint drops databases, so it must not exist anywhere but Testing —
     * no JSON, no 405, nothing that would even reveal the route.
     */
    #[Test]
    #[DataProvider('contextsThatMustNeverAnswer')]
    public function passesThroughOutsideATestingContext(string $context): void
    {
        $original = Environment::getContext();
        $this->reinitializeWith(new ApplicationContext($context));

        try {
            $response = $this->post([self::TEST_ID]);

            self::assertStringContainsString('passedThrough', (string) $response->getBody());
            self::assertStringNotContainsString('results', (string) $response->getBody());
        } finally {
            $this->reinitializeWith($original);
        }
    }

    /**
     * @return list<array{string}>
     */
    public static function contextsThatMustNeverAnswer(): array
    {
        return [['Production'], ['Production/Staging'], ['Development'], ['Development/Local']];
    }

    #[Test]
    public function forwardsRequestsForAnotherPath(): void
    {
        $request = (new ServerRequest('https://example.test/some/other/path', 'POST'));

        $response = $this->get(DatabaseCleanupProvider::class)->process($request, $this->passThroughHandler());

        self::assertStringContainsString('passedThrough', (string) $response->getBody());
    }

    #[Test]
    public function rejectsAnythingButPost(): void
    {
        $request = (new ServerRequest('https://example.test' . self::PATH, 'GET'))
            ->withHeader(TestApiSecret::HEADER, $this->authenticate());

        $response = $this->get(DatabaseCleanupProvider::class)->process($request, $this->passThroughHandler());

        self::assertSame(405, $response->getStatusCode());
    }

    #[Test]
    public function rejectsABodyThatIsNotJson(): void
    {
        self::assertSame(400, $this->postRaw('not json at all')->getStatusCode());
    }

    #[Test]
    public function rejectsABodyWithoutTestIds(): void
    {
        self::assertSame(400, $this->postRaw('{"somethingElse": []}')->getStatusCode());
    }

    #[Test]
    public function rejectsABatchLargerThanTheCap(): void
    {
        $tooMany = array_fill(0, 501, self::TEST_ID);

        self::assertSame(400, $this->post($tooMany)->getStatusCode());
    }

    // A big suite has more live ids than the drop cap, and refusing the keep list
    // would skip the sweep instead of protecting them.
    #[Test]
    public function acceptsAKeepListLargerThanTheDropCap(): void
    {
        $manyLiveIds = array_fill(0, 501, self::TEST_ID);

        $response = $this->postTo(self::SWEEP_PATH, ['keepTestIds' => $manyLiveIds, 'minimumAgeMs' => 0]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    #[Test]
    public function reportsTheEngineItActedOn(): void
    {
        self::assertSame('sqlite', $this->json($this->post([self::TEST_ID]))['engine']);
    }

    #[Test]
    public function dropsAClaimedDatabaseAndReportsIt(): void
    {
        $this->claimAndCreate(self::TEST_ID);

        $body = $this->json($this->post([self::TEST_ID]));

        self::assertSame([['testId' => self::TEST_ID, 'outcome' => 'dropped']], $body['results']);
        self::assertFileDoesNotExist($this->databaseFile(self::TEST_ID));
    }

    #[Test]
    public function reportsEveryOutcomeInOneBatch(): void
    {
        $this->claimAndCreate('AAAA1111AAAA1111');
        $this->createOnly('BBBB2222BBBB2222');

        $body = $this->json($this->post([
            'AAAA1111AAAA1111',
            'BBBB2222BBBB2222',
            'CCCC3333CCCC3333',
            'not-a-test-id',
        ]));

        self::assertSame(
            [
                ['testId' => 'AAAA1111AAAA1111', 'outcome' => 'dropped'],
                ['testId' => 'BBBB2222BBBB2222', 'outcome' => 'unclaimed'],
                ['testId' => 'CCCC3333CCCC3333', 'outcome' => 'absent'],
                ['testId' => 'not-a-test-id', 'outcome' => 'refused'],
            ],
            $body['results']
        );
        self::assertFileExists($this->databaseFile('BBBB2222BBBB2222'), 'dropped a database we never claimed');
    }

    // A whole replay run's content lives there.
    #[Test]
    public function refusesToDropTheReplayDatabase(): void
    {
        $body = $this->json($this->post([DatabaseName::REPLAY_TEST_ID]));

        self::assertSame(
            [['testId' => DatabaseName::REPLAY_TEST_ID, 'outcome' => 'refused']],
            $body['results']
        );
    }

    #[Test]
    public function answersOnThePathWithoutTheTypo3Prefix(): void
    {
        $request = $this->requestFor('/test-api/databases/drop', ['testIds' => [self::TEST_ID]]);

        $response = $this->get(DatabaseCleanupProvider::class)->process($request, $this->passThroughHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * A drop request must not be the thing that creates the database, which is
     * what carrying the test ID in a header would do.
     */
    #[Test]
    public function provisionsNothingWhileDropping(): void
    {
        $this->post([self::TEST_ID]);

        self::assertFileDoesNotExist($this->databaseFile(self::TEST_ID));
        self::assertFileDoesNotExist(LockFiles::inVarPath()->claim('db' . self::TEST_ID));
    }

    /**
     * The toolkit parses this body, and its own tests cannot see a change here.
     * The fixture is the shared contract: neither side may alter it alone.
     */
    #[Test]
    public function theDropResponseMatchesTheContractFixture(): void
    {
        $this->claimAndCreate('AAAA1111AAAA1111');
        $this->createOnly('BBBB2222BBBB2222');

        $body = $this->json($this->post([
            'AAAA1111AAAA1111',
            'BBBB2222BBBB2222',
            'CCCC3333CCCC3333',
            'not-a-test-id',
        ]));

        self::assertSame($this->contractFixture('cleanup-drop-response'), $body);
    }

    #[Test]
    public function theSweepResponseCarriesEveryKeyTheContractFixtureNames(): void
    {
        $body = $this->json($this->postTo(self::SWEEP_PATH, ['keepTestIds' => [], 'minimumAgeMs' => 1]));

        self::assertSame(
            array_keys($this->contractFixture('cleanup-sweep-response')),
            array_keys($body)
        );
    }

    #[Test]
    public function sweepReportsTheCutoffItActuallyApplied(): void
    {
        $body = $this->json($this->postTo(self::SWEEP_PATH, ['keepTestIds' => [], 'minimumAgeMs' => 1]));

        self::assertSame(3600000, $body['cutoffMs'], 'the request chose its own cutoff');
        self::assertSame(0, $body['kept']);
    }

    #[Test]
    public function sweepReclaimsAnOldClaimAndReportsWhatItKept(): void
    {
        $this->claimAndCreate(self::TEST_ID);
        touch(LockFiles::inVarPath()->claim('db' . self::TEST_ID), time() - 10800);
        $this->claimAndCreate('LIVE1111LIVE1111');

        $body = $this->json($this->postTo(self::SWEEP_PATH, [
            'keepTestIds' => ['LIVE1111LIVE1111'],
            'minimumAgeMs' => 3600000,
        ]));

        self::assertSame([['testId' => self::TEST_ID, 'outcome' => 'dropped']], $body['results']);
        self::assertSame(1, $body['kept']);
        self::assertFileExists($this->databaseFile('LIVE1111LIVE1111'), 'reclaimed a live database');
    }

    // The knob has to reach the endpoint, not just the service behind it.
    #[Test]
    public function sweepAppliesTheConfiguredFloor(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = ['cleanupMinimumAgeMs' => '7200000'];

        $body = $this->json($this->postTo(self::SWEEP_PATH, ['keepTestIds' => [], 'minimumAgeMs' => 1000]));

        self::assertSame(7200000, $body['cutoffMs']);
    }

    #[Test]
    public function sweepRejectsANegativeAge(): void
    {
        $response = $this->postTo(self::SWEEP_PATH, ['keepTestIds' => [], 'minimumAgeMs' => -1]);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function sweepRejectsAMissingKeepList(): void
    {
        self::assertSame(400, $this->postTo(self::SWEEP_PATH, ['minimumAgeMs' => 3600000])->getStatusCode());
    }

    #[Test]
    #[DataProvider('contextsThatMustNeverAnswer')]
    public function sweepPassesThroughOutsideATestingContext(string $context): void
    {
        $original = Environment::getContext();
        $this->reinitializeWith(new ApplicationContext($context));

        try {
            $response = $this->postTo(self::SWEEP_PATH, ['keepTestIds' => []]);

            self::assertStringContainsString('passedThrough', (string) $response->getBody());
        } finally {
            $this->reinitializeWith($original);
        }
    }

    /**
     * @return array<string, mixed> the fixture with its comment stripped
     */
    private function contractFixture(string $name): array
    {
        return ContractFixture::read($name);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postTo(string $path, array $payload): ResponseInterface
    {
        return $this->get(DatabaseCleanupProvider::class)
            ->process($this->requestFor($path, $payload), $this->passThroughHandler());
    }

    private function passThroughHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(['passedThrough' => true]);
            }
        };
    }

    /**
     * @param list<string> $testIds
     */
    private function post(array $testIds): ResponseInterface
    {
        return $this->get(DatabaseCleanupProvider::class)
            ->process($this->requestFor(self::PATH, ['testIds' => $testIds]), $this->passThroughHandler());
    }

    private function postRaw(string $body): ResponseInterface
    {
        $request = (new ServerRequest('https://example.test' . self::PATH, 'POST'))
            ->withHeader(TestApiSecret::HEADER, $this->authenticate())
            ->withBody($this->streamFor($body));

        return $this->get(DatabaseCleanupProvider::class)->process($request, $this->passThroughHandler());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requestFor(string $path, array $payload): ServerRequest
    {
        return (new ServerRequest('https://example.test' . $path, 'POST'))
            ->withHeader(TestApiSecret::HEADER, $this->authenticate())
            ->withBody($this->streamFor((string) json_encode($payload)));
    }

    /** The endpoint answers authenticated callers only; see EndpointAuthenticationTest. */
    private function authenticate(): string
    {
        return $this->get(TestApiSecret::class)->ensureExists();
    }

    private function streamFor(string $body): Stream
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        return $stream;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    private function claimAndCreate(string $testId): void
    {
        $this->createOnly($testId);
        $locks = LockFiles::inVarPath();
        $locks->ensureDirectory();
        touch($locks->claim('db' . $testId));
    }

    private function createOnly(string $testId): void
    {
        $directory = Environment::getVarPath() . '/test-databases';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        touch($this->databaseFile($testId));
    }

    private function databaseFile(string $testId): string
    {
        return Environment::getVarPath() . '/test-databases/db' . $testId . '.sqlite';
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
