<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Http\RecordDiagnostics;
use Plan2net\PlaywrightToolkit\Http\RecordEditRefusal;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TranslationFormRulesTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/TranslatedRecord.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)
            ->createFromUserPreferences($backendUser);

        GeneralUtility::mkdir_deep(Environment::getConfigPath() . '/sites/main');
        GeneralUtility::writeFile(
            Environment::getConfigPath() . '/sites/main/config.yaml',
            "rootPageId: 1\nbase: 'https://main.test/'\nlanguages:\n"
            . "  - languageId: 0\n    title: English\n    locale: en_US.UTF-8\n    base: /\n"
            . "  - languageId: 1\n    title: German\n    locale: de_DE.UTF-8\n    base: /de/\n"
        );

        $this->secret = $this->get(TestApiSecret::class)->ensureExists();
    }

    protected function tearDown(): void
    {
        @unlink($this->get(TestApiSecret::class)->file());
        parent::tearDown();
    }

    #[Test]
    public function acceptsAChangeToAReferenceTheTranslationCopied(): void
    {
        $translation = $this->localize('tt_content', 1);
        $copies = $this->copiedReferences($translation);

        $response = $this->save('tt_content', $translation, [
            'tt_content' => [$translation => ['hidden' => 0, 'assets' => implode(',', $copies)]],
            'sys_file_reference' => [$copies[1] => ['alternative' => 'Zweites Bild']],
        ]);

        self::assertSame(302, $response->getStatusCode(), $response->getHeaderLine(RecordDiagnostics::HEADER));
    }

    #[Test]
    public function acceptsAChangeToAReferenceOfTheDefaultLanguage(): void
    {
        $response = $this->save('tt_content', 1, [
            'tt_content' => [1 => ['assets' => '1,2']],
            'sys_file_reference' => [2 => ['alternative' => 'Second image']],
        ]);

        self::assertSame(302, $response->getStatusCode(), $response->getHeaderLine(RecordDiagnostics::HEADER));
    }

    #[Test]
    public function refusesAFieldTheFormOfATranslationDoesNotShow(): void
    {
        $translation = $this->localize('pages', 2);

        $response = $this->save('pages', $translation, ['pages' => [$translation => ['layout' => 1]]]);

        $refusal = json_decode($response->getHeaderLine(RecordDiagnostics::HEADER), true);
        self::assertStringContainsString('"layout" cannot be translated', $refusal['errors'][0]['message'] ?? '');
    }

    private function localize(string $table, int $uid): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['localize' => 1]]]);
        $dataHandler->process_cmdmap();

        return (int) ($dataHandler->copyMappingArray_merged[$table][$uid] ?? 0);
    }

    /**
     * @return list<int>
     */
    private function copiedReferences(int $contentUid): array
    {
        return array_map(
            static fn(mixed $uid): int => (int) $uid,
            $this->getConnectionPool()->getConnectionForTable('sys_file_reference')
                ->executeQuery(
                    'SELECT uid FROM sys_file_reference WHERE tablenames = ? AND uid_foreign = ? ORDER BY sorting_foreign',
                    ['tt_content', $contentUid]
                )
                ->fetchFirstColumn()
        );
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $data
     */
    private function save(string $table, int $uid, array $data): ResponseInterface
    {
        $edit = ['edit' => [$table => [$uid => 'edit']]];
        $request = (new ServerRequest('https://testing.test/typo3/record/edit?' . http_build_query($edit), 'POST'))
            ->withQueryParams($edit)
            ->withHeader(TestApiSecret::HEADER, $this->secret)
            ->withParsedBody(['data' => $data]);

        $saved = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new RedirectResponse('/typo3/record/edit', 302);
            }
        };

        return $this->get(RecordEditRefusal::class)->process($request, $saved);
    }
}
