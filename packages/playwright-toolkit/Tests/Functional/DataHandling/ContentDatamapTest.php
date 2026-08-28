<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Tests\ContractFixture;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What the toolkit posts has to survive DataHandler, and only a real one can say
 * so. The npm side asserts it produces these same bytes.
 */
final class ContentDatamapTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/ContentDatamap.csv');
        $backendUser = $this->setUpBackendUser(1);
        // DataHandler reaches for it through BackendUtility, and only the newer
        // testing-framework sets it up alongside the backend user.
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)
            ->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function theParentFieldIsWhatLinksAFileReference(): void
    {
        $contentUid = $this->processDatamap([
            'tt_content' => [
                'NEWcontent' => [
                    'pid' => 1,
                    'CType' => 'image',
                    'colPos' => 0,
                    'header' => 'With image',
                    'image' => 'NEWimage0,NEWimage1',
                ],
            ],
            'sys_file_reference' => [
                'NEWimage0' => $this->reference(1),
                'NEWimage1' => $this->reference(2),
            ],
        ]);

        self::assertSame([$contentUid, $contentUid], $this->referenceParents());
    }

    #[Test]
    public function pointingTheChildAtTheParentInsteadLinksNothing(): void
    {
        $contentUid = $this->processDatamap([
            'tt_content' => [
                'NEWcontent' => [
                    'pid' => 1,
                    'CType' => 'image',
                    'colPos' => 0,
                    'header' => 'Without the parent field',
                ],
            ],
            'sys_file_reference' => [
                'NEWimage0' => $this->reference(1) + ['uid_foreign' => 'NEWcontent'],
            ],
        ]);

        self::assertNotSame([$contentUid], $this->referenceParents());
    }

    #[Test]
    public function theSameHoldsForAnAssetsColumn(): void
    {
        $contentUid = $this->processDatamap([
            'tt_content' => [
                'NEWcontent' => [
                    'pid' => 1,
                    'CType' => 'textmedia',
                    'colPos' => 0,
                    'header' => 'With assets',
                    'assets' => 'NEWassets0',
                ],
            ],
            'sys_file_reference' => [
                'NEWassets0' => $this->reference(1, 'assets'),
            ],
        ]);

        self::assertSame([$contentUid], $this->referenceParents());
    }

    #[Test]
    public function theBodyTheToolkitPostsLinksItsReferences(): void
    {
        $posted = ContractFixture::read('content-image-datamap');

        // The same parsing a real POST goes through before DataHandler sees it.
        parse_str(http_build_query($posted), $body);

        /** @var array<string, array<string, array<string, mixed>>> $datamap */
        $datamap = $body['data'];
        $contentUid = $this->processDatamap($datamap);

        self::assertSame([$contentUid, $contentUid], $this->referenceParents());
    }

    #[Test]
    public function aPageLinksItsMediaTheSameWay(): void
    {
        $pageUid = $this->processDatamap([
            'pages' => [
                'NEWpage' => [
                    'pid' => 1,
                    'title' => 'With media',
                    'doktype' => 1,
                    'slug' => '/with-media',
                    'media' => 'NEWpagemedia',
                ],
            ],
            'sys_file_reference' => [
                'NEWpagemedia' => [
                    'pid' => 'NEWpage',
                    'uid_local' => 1,
                    'tablenames' => 'pages',
                    'fieldname' => 'media',
                    'sys_language_uid' => 0,
                ],
            ],
        ], 'NEWpage');

        self::assertSame([$pageUid], $this->referenceParents());
    }

    // A name the structure does not know is stored unchanged instead of refused,
    // so only the trimmed value proves it was found.
    #[Test]
    public function theBodyAFlexformElementPostsIsReadAgainstTheDataStructure(): void
    {
        $this->declareFlexFormStructure();
        $posted = ContractFixture::read('content-flexform-datamap');

        parse_str(http_build_query($posted), $body);

        /** @var array<string, array<string, array<string, mixed>>> $datamap */
        $datamap = $body['data'];
        $contentUid = $this->processDatamap($datamap);

        self::assertSame('10', $this->storedFlexFormValue($contentUid, 'sDEF', 'settings.limit'));
        self::assertSame(
            '1,2',
            $this->storedFlexFormValue($contentUid, 'sFilter', 'settings.categories')
        );
    }

    private function declareFlexFormStructure(): void
    {
        $structure = '<T3DataStructure><sheets><sDEF><ROOT><type>array</type><el>'
            . '<settings.limit><label>Limit</label><config><type>input</type><eval>trim</eval></config></settings.limit>'
            . '</el></ROOT></sDEF>'
            . '<sFilter><ROOT><type>array</type><el>'
            . '<settings.categories><label>Categories</label><config><type>input</type><eval>trim</eval></config></settings.categories>'
            . '</el></ROOT></sFilter>'
            . '</sheets></T3DataStructure>';

        $isVersion14 = (new Typo3Version())->getMajorVersion() >= 14;

        $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config'] = [
            'type' => 'flex',
            // 14 reads the structure from a string, older versions from a 'default' key.
            'ds' => $isVersion14 ? $structure : ['default' => $structure],
        ];

        if ($isVersion14) {
            // 14 reads it from the compiled schema, which was built before this.
            $schemaFactory = 'TYPO3\\CMS\\Core\\Schema\\TcaSchemaFactory';
            $factory = GeneralUtility::makeInstance($schemaFactory);
            if (method_exists($factory, 'rebuild')) {
                $factory->rebuild($GLOBALS['TCA']);
            }
        }
    }

    private function storedFlexFormValue(int $contentUid, string $sheet, string $field): ?string
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $stored = $queryBuilder
            ->select('pi_flexform')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $contentUid))
            ->executeQuery()
            ->fetchOne();

        $parsed = GeneralUtility::xml2array((string) $stored);

        return is_array($parsed)
            ? ($parsed['data'][$sheet]['lDEF'][$field]['vDEF'] ?? null)
            : null;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $datamap
     */
    private function processDatamap(array $datamap, string $identifier = 'NEWcontent'): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, []);
        $dataHandler->process_datamap();

        self::assertSame([], $dataHandler->errorLog);

        return (int) $dataHandler->substNEWwithIDs[$identifier];
    }

    /**
     * @return array<string, mixed>
     */
    private function reference(int $fileUid, string $column = 'image'): array
    {
        return [
            'pid' => 1,
            'uid_local' => $fileUid,
            'tablenames' => 'tt_content',
            'fieldname' => $column,
            'sys_language_uid' => 0,
        ];
    }

    /**
     * @return list<int>
     */
    private function referenceParents(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array{uid_foreign: int}> $rows */
        $rows = $queryBuilder
            ->select('uid_foreign')
            ->from('sys_file_reference')
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int) $row['uid_foreign'], $rows);
    }
}
