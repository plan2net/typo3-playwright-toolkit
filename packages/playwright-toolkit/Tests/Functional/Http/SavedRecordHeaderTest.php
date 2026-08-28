<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Http\SavedRecord;
use Plan2net\PlaywrightToolkit\Http\SavedRecordHeader;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\TestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SavedRecordHeaderTest extends FunctionalTestCase
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

        return $this->get(SavedRecordHeader::class)->process($request, $handler);
    }
}
