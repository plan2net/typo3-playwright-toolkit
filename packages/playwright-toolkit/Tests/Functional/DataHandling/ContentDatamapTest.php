<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Tests\ContractFixture;
use TYPO3\CMS\Core\DataHandling\DataHandler;
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
        $this->setUpBackendUser(1);
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
