<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Http\RecordDiagnostics;
use Plan2net\PlaywrightToolkit\Http\SavedRecord;
use Plan2net\PlaywrightToolkit\Http\TranslatedRecord;
use Plan2net\PlaywrightToolkit\Http\TranslatedRecordReport;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\Tests\ContractFixture;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TranslatedRecordReportTest extends FunctionalTestCase
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
    public function reportsTheTranslationALocalizeCommandCreated(): void
    {
        $response = $this->commit(['tt_content' => [1 => ['localize' => 1]]]);

        self::assertSame(
            ['sys_language_uid' => 1, 'l18n_parent' => 1],
            $this->row('tt_content', (int) ($this->translatedRecord($response)['uid'] ?? 0), ['sys_language_uid', 'l18n_parent'])
        );
    }

    #[Test]
    public function reportsThePageTheTranslationIsOn(): void
    {
        $response = $this->commit(['tt_content' => [1 => ['localize' => 1]]]);

        self::assertSame(2, $this->translatedRecord($response)['pid'] ?? null);
    }

    #[Test]
    public function listsTheCopiedReferencesInTheOrderOfTheOrigin(): void
    {
        $response = $this->commit(['tt_content' => [1 => ['localize' => 1]]]);

        $copies = $this->translatedRecord($response)['references']['assets'] ?? [];
        self::assertSame(
            [1, 2],
            array_map(fn(int $copy): int => $this->row('sys_file_reference', $copy, ['l10n_parent'])['l10n_parent'], $copies)
        );
    }

    #[Test]
    public function reportsAFreeCopyByItsSource(): void
    {
        $response = $this->commit(['tt_content' => [1 => ['copyToLanguage' => 1]]]);

        self::assertSame(
            ['sys_language_uid' => 1, 'l18n_parent' => 0, 'l10n_source' => 1],
            $this->row('tt_content', (int) ($this->translatedRecord($response)['uid'] ?? 0), ['sys_language_uid', 'l18n_parent', 'l10n_source'])
        );
    }

    #[Test]
    public function reportsTheSlugATranslatedPageWasGiven(): void
    {
        $response = $this->commit(['pages' => [2 => ['localize' => 1]]]);

        $uid = (int) ($this->translatedRecord($response)['uid'] ?? 0);
        $slug = $this->getConnectionPool()->getConnectionForTable('pages')
            ->executeQuery('SELECT slug FROM pages WHERE uid = ?', [$uid])
            ->fetchOne();
        self::assertSame(['slug' => $slug], json_decode($response->getHeaderLine(SavedRecord::HEADER), true));
    }

    #[Test]
    public function listsTheMediaATranslatedPageTookOver(): void
    {
        $response = $this->commit(['pages' => [2 => ['localize' => 1]]]);

        self::assertCount(2, $this->translatedRecord($response)['references']['media'] ?? []);
    }

    #[Test]
    public function tellsACallerWithoutTheSecretNothing(): void
    {
        $response = $this->commit(['tt_content' => [1 => ['localize' => 1]]], 'wrong-secret');

        self::assertFalse($response->hasHeader(TranslatedRecord::HEADER));
    }

    #[Test]
    public function reportsWhyTypo3RefusedTheTranslation(): void
    {
        $this->commit(['tt_content' => [1 => ['localize' => 1]]]);

        $response = $this->commit(['tt_content' => [1 => ['localize' => 1]]]);

        self::assertFalse($response->hasHeader(TranslatedRecord::HEADER));
        self::assertGreaterThan(0, json_decode($response->getHeaderLine(RecordDiagnostics::HEADER), true)['count'] ?? 0);
    }

    #[Test]
    public function reportsWhyTypo3RefusedToTranslateATableWithoutLanguages(): void
    {
        $response = $this->commit(['sys_file_storage' => [1 => ['localize' => 1]]]);

        self::assertStringContainsString(
            'languageField',
            json_decode($response->getHeaderLine(RecordDiagnostics::HEADER), true)['errors'][0]['message'] ?? ''
        );
    }

    #[Test]
    public function answersInTheShapeTheContractFixtureHolds(): void
    {
        $response = $this->commit(['pages' => [2 => ['localize' => 1]]]);

        self::assertSame(ContractFixture::read('translated-record-header'), $this->translatedRecord($response));
    }

    /**
     * @param array<string, array<int, array<string, int>>> $command
     */
    private function commit(array $command, ?string $secret = null): ResponseInterface
    {
        $request = (new ServerRequest('https://testing.test/typo3/record/commit', 'POST'))
            ->withHeader(TestApiSecret::HEADER, $secret ?? $this->secret)
            ->withParsedBody(['cmd' => $command]);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $dataHandler->start([], (array) (((array) $request->getParsedBody())['cmd'] ?? []));
                $dataHandler->process_cmdmap();

                return new HtmlResponse('');
            }
        };

        return $this->get(TranslatedRecordReport::class)->process($request, $handler);
    }

    /**
     * @param list<string> $columns
     *
     * @return array<int|string, int>
     */
    private function row(string $table, int $uid, array $columns): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable($table)
            ->executeQuery(sprintf('SELECT %s FROM %s WHERE uid = ?', implode(', ', $columns), $table), [$uid])
            ->fetchAssociative();

        return array_map(static fn(mixed $value): int => (int) $value, (array) $row);
    }

    /**
     * @return array<string, mixed>
     */
    private function translatedRecord(ResponseInterface $response): array
    {
        return (array) json_decode($response->getHeaderLine(TranslatedRecord::HEADER), true);
    }
}
