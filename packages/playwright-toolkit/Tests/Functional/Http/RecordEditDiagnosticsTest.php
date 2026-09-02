<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Http\RecordEditDiagnostics;
use Plan2net\PlaywrightToolkit\Http\SavedRecord;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use Plan2net\PlaywrightToolkit\Tests\ContractFixture;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordEditDiagnosticsTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = $this->get(TestApiSecret::class)->ensureExists();
        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
    }

    protected function tearDown(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        @unlink($this->get(TestApiSecret::class)->file());
        parent::tearDown();
    }

    #[Test]
    public function reportsTheSlugTheSavedPageHolds(): void
    {
        $this->givenPage('/a-page-1');

        $response = $this->save('/typo3/record/edit?edit%5Bpages%5D%5B17%5D=edit');

        self::assertSame('{"slug":"\/a-page-1"}', $response->getHeaderLine(SavedRecord::HEADER));
    }

    // A raw header field is ASCII, so an umlaut has to travel escaped.
    #[Test]
    public function escapesASlugThatIsNotAscii(): void
    {
        $this->givenPage('/über-uns');

        $response = $this->save('/typo3/record/edit?edit%5Bpages%5D%5B17%5D=edit');

        $header = $response->getHeaderLine(SavedRecord::HEADER);
        self::assertSame(1, preg_match('/^[\x20-\x7E]+$/', $header));
        self::assertSame('/über-uns', json_decode($header, true)['slug']);
    }

    #[Test]
    public function tellsACallerWithoutTheSecretNothing(): void
    {
        $this->givenPage('/a-page-1');

        $response = $this->save('/typo3/record/edit?edit%5Bpages%5D%5B17%5D=edit', 'wrong-secret');

        self::assertFalse($response->hasHeader(SavedRecord::HEADER));
    }

    #[Test]
    public function reportsWhatTypo3RefusedWhileSaving(): void
    {
        $this->givenPage('/a-page-1');

        $response = $this->saveWhileTypo3Logs(
            'index.php?route=%2Frecord%2Fedit&edit[pages][17]=edit',
            'this table is not allowed here',
        );

        self::assertSame(
            ['errors' => [['message' => 'this table is not allowed here', 'table' => 'tt_content']], 'count' => 1],
            $this->diagnostics($response)
        );
    }

    #[Test]
    public function reportsHowManyRecordsWereWrittenPerTable(): void
    {
        $this->givenPage('/a-page-1');

        $response = $this->saveWhileTypo3Writes(
            '/typo3/record/edit?edit%5Bpages%5D%5B17%5D=edit',
            ['pages', 'pages', 'sys_redirect'],
        );

        self::assertSame(['pages' => 2, 'sys_redirect' => 1], $this->savedRecord($response)['written']);
    }

    #[Test]
    public function answersExactlyWhatTheContractFixtureHolds(): void
    {
        $this->givenPage('/a-page-1');

        $response = $this->saveWhileTypo3Writes(
            '/typo3/record/edit?edit%5Bpages%5D%5B17%5D=edit',
            ['pages', 'pages', 'sys_redirect'],
        );

        self::assertSame(ContractFixture::read('saved-record-header'), $this->savedRecord($response));
    }

    private function givenPage(string $slug): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 17,
            'pid' => 1,
            'title' => 'A page',
            'slug' => $slug,
        ]);
    }

    private function save(string $location, ?string $secret = null): ResponseInterface
    {
        $request = (new ServerRequest('https://testing.test/typo3/record/edit', 'POST'))
            ->withHeader(TestApiSecret::HEADER, $secret ?? $this->secret);

        $handler = new class($location) implements RequestHandlerInterface {
            public function __construct(private readonly string $location)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new RedirectResponse($this->location, 302);
            }
        };

        return $this->get(RecordEditDiagnostics::class)->process($request, $handler);
    }

    private function saveWhileTypo3Logs(string $location, string $details): ResponseInterface
    {
        $request = (new ServerRequest('https://testing.test/typo3/record/edit', 'POST'))
            ->withHeader(TestApiSecret::HEADER, $this->secret);

        $connection = $this->getConnectionPool()->getConnectionForTable('sys_log');
        $handler = new class($location, $connection, $details) implements RequestHandlerInterface {
            public function __construct(
                private readonly string $location,
                private readonly Connection $connection,
                private readonly string $details,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->connection->insert('sys_log', [
                    'type' => 1,
                    'channel' => 'content',
                    'level' => 'error',
                    'error' => 1,
                    'details' => $this->details,
                    'log_data' => '',
                    'tablename' => 'tt_content',
                    'recuid' => 0,
                    'tstamp' => 1760954471,
                    'component' => '',
                    'message' => '',
                    'data' => '',
                ]);

                return new RedirectResponse($this->location, 302);
            }
        };

        return $this->get(RecordEditDiagnostics::class)->process($request, $handler);
    }

    /**
     * @param list<string> $tables one write log row per entry
     */
    private function saveWhileTypo3Writes(string $location, array $tables): ResponseInterface
    {
        $request = (new ServerRequest('https://testing.test/typo3/record/edit', 'POST'))
            ->withHeader(TestApiSecret::HEADER, $this->secret);

        $connection = $this->getConnectionPool()->getConnectionForTable('sys_log');
        $handler = new class($location, $connection, $tables) implements RequestHandlerInterface {
            /**
             * @param list<string> $tables
             */
            public function __construct(
                private readonly string $location,
                private readonly Connection $connection,
                private readonly array $tables,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                foreach ($this->tables as $table) {
                    $this->connection->insert('sys_log', [
                        'type' => 1,
                        'channel' => 'content',
                        'level' => 'info',
                        'error' => 0,
                        'details' => 'Record was saved',
                        'log_data' => '',
                        'tablename' => $table,
                        'recuid' => 0,
                        'tstamp' => 1760954471,
                        'component' => '',
                        'message' => '',
                        'data' => '',
                    ]);
                }

                return new RedirectResponse($this->location, 302);
            }
        };

        return $this->get(RecordEditDiagnostics::class)->process($request, $handler);
    }

    /**
     * @return array<string, mixed>
     */
    private function savedRecord(ResponseInterface $response): array
    {
        return (array) json_decode($response->getHeaderLine(SavedRecord::HEADER), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnostics(ResponseInterface $response): array
    {
        return (array) json_decode($response->getHeaderLine('X-Playwright-Record-Diagnostics'), true);
    }
}
