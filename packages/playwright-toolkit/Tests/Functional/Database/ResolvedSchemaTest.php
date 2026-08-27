<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\ResolvedSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ResolvedSchemaTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TABLE = 'tx_playwrighttest_thing';

    /**
     * @var string
     */
    private const CREATE = 'CREATE TABLE ' . self::TABLE . ' (
        uid int(11) NOT NULL auto_increment,
        pid int(11) DEFAULT 0 NOT NULL,
        title varchar(255) DEFAULT \'\' NOT NULL,
        PRIMARY KEY (uid)
    );';
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TCA'][self::TABLE] = [
            'ctrl' => ['title' => 'Thing'],
            'columns' => [],
        ];
        $this->applyTca();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA'][self::TABLE]);

        parent::tearDown();
    }

    #[Test]
    public function theSameSourcesDescribeTheSameSchema(): void
    {
        $resolved = $this->get(ResolvedSchema::class);

        self::assertSame(
            $resolved->fingerprintSource([self::CREATE]),
            $resolved->fingerprintSource([self::CREATE])
        );
    }

    #[Test]
    public function anExtTablesChangeMovesTheFingerprint(): void
    {
        $resolved = $this->get(ResolvedSchema::class);
        $before = $resolved->fingerprintSource([self::CREATE]);

        $withExtraColumn = str_replace(
            'PRIMARY KEY (uid)',
            "subtitle varchar(255) DEFAULT '' NOT NULL,\n PRIMARY KEY (uid)",
            self::CREATE
        );

        self::assertNotSame($before, $resolved->fingerprintSource([$withExtraColumn]));
    }

    #[Test]
    public function aTcaOnlyChangeMovesTheFingerprint(): void
    {
        $resolved = $this->get(ResolvedSchema::class);
        $before = $resolved->fingerprintSource([self::CREATE]);

        // TCA ctrl fields become real columns inside the migrator, not here.
        $GLOBALS['TCA'][self::TABLE]['ctrl']['delete'] = 'deleted';
        $this->applyTca();

        self::assertNotSame($before, $resolved->fingerprintSource([self::CREATE]));
    }

    #[Test]
    public function theResolvedSchemaCarriesColumnsOnlyTcaDeclares(): void
    {
        $GLOBALS['TCA'][self::TABLE]['ctrl']['delete'] = 'deleted';
        $this->applyTca();

        $described = $this->get(ResolvedSchema::class)->fingerprintSource([self::CREATE]);

        self::assertStringContainsString('COLUMN deleted', $described);
    }

    // Up to 13.4 the schema is enriched straight from $GLOBALS['TCA']; 14.3 reads
    // an already-built TcaSchemaFactory, which a mutation has to be pushed into.
    private function applyTca(): void
    {
        if (class_exists(TcaSchemaFactory::class)) {
            $this->get(TcaSchemaFactory::class)->rebuild($GLOBALS['TCA']);
        }
    }
}
