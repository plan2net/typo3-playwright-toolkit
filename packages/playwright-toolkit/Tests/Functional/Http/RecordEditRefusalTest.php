<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Http\RecordDiagnostics;
use Plan2net\PlaywrightToolkit\Http\RecordEditRefusal;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Plan2net\PlaywrightToolkit\Tests\ContractFixture;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordEditRefusalTest extends FunctionalTestCase
{
    public bool $saved = false;
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
        '../Tests/Functional/Fixtures/Extensions/form_rules_test',
        '../Tests/Functional/Fixtures/Extensions/relations_test',
    ];

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/RecordEditRefusal.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)
            ->createFromUserPreferences($backendUser);
        $this->secret = $this->get(TestApiSecret::class)->ensureExists();
    }

    protected function tearDown(): void
    {
        @unlink($this->get(TestApiSecret::class)->file());
        parent::tearDown();
    }

    #[Test]
    public function refusesANewRecordThatLeavesARequiredFieldOut(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1]]]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function namesTheFieldTheFormWouldDemand(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1]]]);

        $envelope = json_decode($response->getHeaderLine(RecordDiagnostics::HEADER), true);
        self::assertSame('tx_formrulestest_record', $envelope['errors'][0]['table'] ?? null);
        self::assertStringContainsString('"title" is required', $envelope['errors'][0]['message'] ?? '');
    }

    #[Test]
    public function keepsTheHeaderSmallEnoughForAWebserverToPassOn(): void
    {
        $records = [];
        foreach (range(1, 40) as $index) {
            $records['NEW' . $index] = ['pid' => 1];
        }

        $header = $this->save(['tx_formrulestest_record' => $records])->getHeaderLine(RecordDiagnostics::HEADER);

        self::assertLessThanOrEqual(2000, \strlen($header));
        self::assertSame(40, json_decode($header, true)['count'] ?? null);
    }

    #[Test]
    public function savesANewRecordThatFillsItsRequiredFieldIn(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'A title']]]);

        self::assertSame(302, $response->getStatusCode());
    }

    #[Test]
    public function savesAnUpdateThatKeepsTheStoredRequiredValue(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['5' => ['pid' => 1]]], null, '/typo3/record/edit', 'edit');

        self::assertSame(302, $response->getStatusCode());
    }

    #[Test]
    public function refusesAnUpdateThatEmptiesARequiredField(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['5' => ['title' => '']]], null, '/typo3/record/edit', 'edit');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function checksAnUpdateAgainstTheFormOfTheTypeItPosts(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['5' => ['kind' => 'extra']]], null, '/typo3/record/edit', 'edit');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function refusesFewerItemsThanTheFormNeeds(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'tags' => '']]]);

        self::assertStringContainsString('"tags" needs at least 1 item, has 0.', $this->refusal($response));
    }

    #[Test]
    public function refusesMoreItemsThanTheFormAllows(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'tags' => 'a,b,c']]]);

        self::assertStringContainsString('"tags" allows at most 2 items, has 3.', $this->refusal($response));
    }

    #[Test]
    public function refusesAValueOutsideTheRange(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'amount' => 11]]]);

        self::assertStringContainsString('"amount" must be between 1 and 10, is 11.', $this->refusal($response));
    }

    #[Test]
    public function refusesAValueBelowALowerBoundAlone(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'stock' => -1]]]);

        self::assertStringContainsString('"stock" must be at least 0, is -1.', $this->refusal($response));
    }

    #[Test]
    public function refusesAValueShorterThanTheMinimum(): void
    {
        if ((new Typo3Version())->getMajorVersion() < 12) {
            self::markTestSkipped('This core has no min rule.');
        }

        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'code' => 'ab']]]);

        self::assertStringContainsString('"code" needs at least 3 characters, has 2.', $this->refusal($response));
    }

    #[Test]
    public function demandsNothingOfAFieldTsconfigDisables(): void
    {
        $this->givenPageTsConfig('TCEFORM.tx_formrulestest_record.title.disabled = 1');

        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1]]]);

        self::assertSame(302, $response->getStatusCode(), $this->refusal($response));
    }

    #[Test]
    public function appliesATsconfigLimitOnItems(): void
    {
        $this->givenPageTsConfig('TCEFORM.tx_formrulestest_record.tags.config.maxitems = 1');

        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'tags' => 'a,b']]]);

        self::assertStringContainsString('"tags" allows at most 1 item, has 2.', $this->refusal($response));
    }

    #[Test]
    public function demandsNothingOfAFieldTheUserMayNotEdit(): void
    {
        $this->actAs(2);

        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'kind' => 'locked']]]);

        self::assertSame(302, $response->getStatusCode(), $this->refusal($response));
    }

    #[Test]
    public function refusesAFieldTheUserMayNotEdit(): void
    {
        $this->actAs(2);

        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'kind' => 'locked', 'secret' => 'x']]]);

        self::assertStringContainsString('"secret" is not editable by user "editor".', $this->refusal($response));
    }

    #[Test]
    public function demandsNothingOfAReadOnlyField(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'kind' => 'extra', 'subtitle' => 'S']]]);

        self::assertSame(302, $response->getStatusCode(), $this->refusal($response));
    }

    #[Test]
    public function demandsNothingOfAFieldATranslationDoesNotShow(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => [
            'pid' => 1,
            'title' => 'T',
            'kind' => 'localized',
            'sys_language_uid' => 1,
            'l10n_parent' => 5,
        ]]]);

        self::assertSame(302, $response->getStatusCode(), $this->refusal($response));
    }

    #[Test]
    public function refusesAFieldTheFormOfTheTypeDoesNotShow(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'subtitle' => 'S']]]);

        self::assertStringContainsString('"subtitle" is not in the form of type "plain".', $this->refusal($response));
    }

    #[Test]
    public function acceptsThePositionLanguageAndWorkspaceFieldsEveryForm(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => [
            'pid' => '-NEW0',
            'title' => 'T',
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'l10n_source' => 0,
            'l10n_diffsource' => '',
            'l10n_state' => '',
            't3_origuid' => 0,
            't3ver_oid' => 0,
            't3ver_wsid' => 0,
            't3ver_state' => 0,
            't3ver_stage' => 0,
        ]]]);

        self::assertSame(302, $response->getStatusCode(), $this->refusal($response));
    }

    #[Test]
    public function demandsAFieldThePostedValuesShow(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'amount' => 9]]]);

        self::assertStringContainsString('"bonus" is required.', $this->refusal($response));
    }

    #[Test]
    public function refusesAFieldADisplayConditionHides(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'bonus' => 'B']]]);

        self::assertStringContainsString('"bonus" is not in the form of type "plain".', $this->refusal($response));
    }

    #[Test]
    public function checksAChildAgainstTheFormItsParentFieldGivesIt(): void
    {
        $response = $this->save([
            'tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'children' => 'NEW2']],
            'tx_formrulestest_child' => ['NEW2' => ['pid' => 1, 'title' => 'C']],
        ]);

        self::assertStringContainsString('tx_formrulestest_child NEW2: "label" is required.', $this->refusal($response));
    }

    #[Test]
    public function leavesARecordTheFormCannotOpenToTheBackend(): void
    {
        $this->save(['tx_formrulestest_record' => ['999' => ['title' => 'T']]], null, '/typo3/record/edit', 'edit');

        self::assertTrue($this->saved);
    }

    #[Test]
    public function checksAGrandchildWhateverOrderTheTablesArePostedIn(): void
    {
        $response = $this->save([
            'tx_formrulestest_child' => ['NEW2' => ['pid' => 1, 'label' => 'L', 'subitems' => 'NEW3'], 'NEW3' => ['pid' => 1]],
            'tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'children' => 'NEW2']],
        ]);

        self::assertStringContainsString('tx_formrulestest_child NEW3: "label" is required.', $this->refusal($response));
    }

    #[Test]
    public function countsTheChildrenAnInlineFieldLists(): void
    {
        $this->givenPageTsConfig('TCEFORM.tx_formrulestest_record.children.config.maxitems = 1');

        $response = $this->save([
            'tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'children' => 'NEW2,NEW3']],
            'tx_formrulestest_child' => ['NEW2' => ['pid' => 1, 'label' => 'L'], 'NEW3' => ['pid' => 1, 'label' => 'L']],
        ]);

        self::assertStringContainsString('"children" allows at most 1 item, has 2.', $this->refusal($response));
    }

    #[Test]
    public function acceptsTheFieldsThatLinkAChildToItsParent(): void
    {
        $response = $this->save([
            'tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'title' => 'T', 'children' => 'NEW2']],
            'tx_formrulestest_child' => ['NEW2' => [
                'pid' => 1,
                'title' => 'C',
                'label' => 'L',
                'parentid' => 0,
                'parenttable' => 'tx_formrulestest_record',
                'fieldname' => 'children',
                'sorting_foreign' => 1,
            ]],
        ]);

        self::assertSame('', $this->refusal($response));
    }

    #[Test]
    public function compilesAChildAddedDuringAnUpdateOnItsParentsPage(): void
    {
        $this->givenPageTsConfig('TCEFORM.tx_formrulestest_child.label.disabled = 1');

        $response = $this->save([
            'tx_formrulestest_record' => ['5' => ['children' => 'NEW2']],
            'tx_formrulestest_child' => ['NEW2' => ['pid' => '-NEW9', 'title' => 'C']],
        ], null, '/typo3/record/edit', 'edit');

        self::assertSame('', $this->refusal($response));
    }

    #[Test]
    public function refusesAnEmptyRequiredFlexFormField(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => [
            'pid' => 1,
            'title' => 'T',
            'kind' => 'flexible',
            'settings' => ['data' => ['sDEF' => ['lDEF' => ['limit' => ['vDEF' => '']]]]],
        ]]]);

        self::assertStringContainsString('tx_formrulestest_record NEW1 settings, sheet sDEF: "limit" is required.', $this->refusal($response));
    }

    #[Test]
    public function demandsNothingOfAFlexFormSheetADisplayConditionHides(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => [
            'pid' => 1,
            'title' => 'T',
            'kind' => 'flexible',
            'settings' => ['data' => ['sDEF' => ['lDEF' => ['limit' => ['vDEF' => '3']]]]],
        ]]]);

        self::assertSame('', $this->refusal($response));
    }

    #[Test]
    public function findsAnInvalidStoredSectionInstanceAnUpdateDoesNotTouch(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['7' => ['title' => 'Renamed']]], null, '/typo3/record/edit', 'edit');

        self::assertStringContainsString('tx_formrulestest_record 7 settings, sheet sItems: "name" is required.', $this->refusal($response));
    }

    #[Test]
    public function keepsTheStoredFlexFormValuesAnUpdateDoesNotPost(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['6' => [
            'settings' => ['data' => ['sMore' => ['lDEF' => ['color' => ['vDEF' => 'red']]]]],
        ]]], null, '/typo3/record/edit', 'edit');

        self::assertSame('', $this->refusal($response));
    }

    #[Test]
    public function answersExactlyWhatTheFormRuleFixtureHolds(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1]]]);

        self::assertSame(
            ContractFixture::read('record-diagnostics-form-rule'),
            json_decode($response->getHeaderLine(RecordDiagnostics::HEADER), true)
        );
    }

    #[Test]
    #[DataProvider('bodiesTheBuildersPost')]
    public function acceptsWhatTheToolkitsOwnBuildersPost(string $fixture): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Files.csv');
        parse_str(http_build_query(ContractFixture::read($fixture)), $body);

        /** @var array<string, array<string, array<string, mixed>>> $datamap */
        $datamap = $body['data'];
        $response = $this->save($datamap);

        self::assertSame('', $this->refusal($response));
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function bodiesTheBuildersPost(): \Generator
    {
        yield 'an image element' => ['content-image-datamap'];
        yield 'nested content' => ['content-nested-datamap'];
    }

    #[Test]
    public function skipsTheFormRulesForAPostThatOptsOut(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'subtitle' => 'S']]], skipFormRules: true);

        self::assertSame('', $this->refusal($response));
    }

    #[Test]
    public function stillRefusesAFieldTheUserMayNotEditWhenAPostOptsOut(): void
    {
        $this->actAs(2);

        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1, 'kind' => 'locked', 'secret' => 'x']]], skipFormRules: true);

        self::assertStringContainsString('"secret" is not editable by user "editor".', $this->refusal($response));
    }

    #[Test]
    public function checksNothingWithoutTheSecret(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1]]], 'not the secret');

        self::assertSame(302, $response->getStatusCode());
    }

    #[Test]
    public function checksNothingOnAnotherRoute(): void
    {
        $response = $this->save(['tx_formrulestest_record' => ['NEW1' => ['pid' => 1]]], null, '/typo3/record/commit');

        self::assertSame(302, $response->getStatusCode());
    }

    #[Test]
    public function refusesASaveWhoseColumnTcaDoesNotKnow(): void
    {
        $response = $this->save(['tt_content' => ['NEW1' => ['bodytxt' => 'a value']]]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function refusesBeforeTheHandlerRuns(): void
    {
        $this->save(['tt_content' => ['NEW1' => ['bodytxt' => 'a value']]]);

        self::assertFalse($this->saved);
    }

    #[Test]
    public function putsTheRefusalIntoTheSameHeaderADataHandlerRefusalUses(): void
    {
        $response = $this->save(['tt_content' => ['NEW1' => ['bodytxt' => 'a value']]]);

        self::assertSame(
            ['errors' => [[
                'table' => 'tt_content',
                'message' => 'Unknown column "bodytxt" on tt_content. TCA has no such column, '
                    . 'so DataHandler would drop it and save the record without it. '
                    . 'Did you mean "bodytext"?',
            ]], 'count' => 1],
            json_decode($response->getHeaderLine(RecordDiagnostics::HEADER), true)
        );
    }

    #[Test]
    public function answersExactlyWhatTheUnknownColumnFixtureHolds(): void
    {
        $response = $this->save(['tt_content' => ['NEW1' => ['bodytxt' => 'a value']]]);

        self::assertSame(
            ContractFixture::read('record-diagnostics-unknown-column'),
            json_decode($response->getHeaderLine(RecordDiagnostics::HEADER), true)
        );
    }

    private function actAs(int $backendUserUid): void
    {
        $backendUser = $this->setUpBackendUser($backendUserUid);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)
            ->createFromUserPreferences($backendUser);
    }

    private function givenPageTsConfig(string $tsConfig): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['TSconfig' => $tsConfig], ['uid' => 1]);
    }

    private function refusal(ResponseInterface $response): string
    {
        return implode("\n", array_column(
            (array) (json_decode($response->getHeaderLine(RecordDiagnostics::HEADER), true)['errors'] ?? []),
            'message'
        ));
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $data
     */
    private function save(
        array $data,
        ?string $secret = null,
        string $path = '/typo3/record/edit',
        string $action = 'new',
        bool $skipFormRules = false,
    ): ResponseInterface {
        $table = (string) array_key_first($data);
        $target = 'new' === $action ? 1 : (int) array_key_first($data[$table]);
        $edit = ['edit' => [$table => [$target => $action]]];
        $request = (new ServerRequest('https://testing.test' . $path . '?' . http_build_query($edit), 'POST'))
            ->withQueryParams($edit)
            ->withHeader(TestApiSecret::HEADER, $secret ?? $this->secret)
            ->withParsedBody(['data' => $data]);
        if ($skipFormRules) {
            $request = $request->withHeader(RecordEditRefusal::SKIP_FORM_RULES_HEADER, '1');
        }

        $saved = new class($this) implements RequestHandlerInterface {
            public function __construct(private readonly RecordEditRefusalTest $test)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->test->saved = true;

                return new RedirectResponse('/typo3/record/edit', 302);
            }
        };

        return $this->get(RecordEditRefusal::class)->process($request, $saved);
    }
}
