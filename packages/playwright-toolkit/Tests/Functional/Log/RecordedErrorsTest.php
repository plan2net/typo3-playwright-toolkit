<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Log;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Log\RecordedErrors;
use Plan2net\PlaywrightToolkit\Tests\ContractFixture;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordedErrorsTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    #[Test]
    public function readsOnlyTheRowsThatReportAProblem(): void
    {
        $this->insertRow(['type' => 1, 'error' => 0, 'details' => 'a page was saved', 'tablename' => 'pages']);
        $this->insertRow(['type' => 1, 'error' => 1, 'details' => 'a refusal', 'tablename' => 'tt_content']);
        $this->insertRow(['type' => 0, 'component' => 'TYPO3.CMS.Core.Resource.ResourceStorage', 'level' => '6', 'message' => 'just information']);
        $this->insertRow(['type' => 0, 'component' => 'TYPO3.CMS.Core.Resource.ResourceStorage', 'level' => '3', 'message' => 'a storage failure']);
        $this->insertRow(['type' => 5, 'channel' => 'php', 'error' => 2, 'details' => 'Core: Exception handler (WEB): Uncaught TYPO3 Exception']);

        $errors = (new RecordedErrors())->readFrom($this->getConnectionPool()->getConnectionForTable('sys_log'), 200)['errors'];

        self::assertSame(
            ['a refusal', 'a storage failure', 'Core: Exception handler (WEB): Uncaught TYPO3 Exception'],
            array_column($errors, 'message')
        );
        self::assertSame(['datahandler', 'log', 'php'], array_column($errors, 'source'));
    }

    #[Test]
    public function collapsesARepeatedMessageIntoOneEntryWithACount(): void
    {
        $this->insertRow(['type' => 5, 'channel' => 'php', 'error' => 2, 'details' => 'the same failure']);
        $this->insertRow(['type' => 5, 'channel' => 'php', 'error' => 2, 'details' => 'the same failure']);
        $this->insertRow(['type' => 5, 'channel' => 'php', 'error' => 2, 'details' => 'a different failure']);
        $this->insertRow(['type' => 5, 'channel' => 'php', 'error' => 2, 'details' => 'the same failure']);

        $errors = (new RecordedErrors())->readFrom($this->getConnectionPool()->getConnectionForTable('sys_log'), 200)['errors'];

        self::assertSame(['the same failure', 'a different failure'], array_column($errors, 'message'));
        self::assertSame([3, 1], array_column($errors, 'count'));
    }

    #[Test]
    public function countsOnlyRefusedRecordsAsRefusals(): void
    {
        $this->insertRow(['type' => 5, 'channel' => 'php', 'error' => 2, 'details' => 'PHP Warning: Undefined array key']);
        $this->insertRow(['type' => 1, 'channel' => 'content', 'error' => 1, 'details' => 'this table is not allowed', 'tablename' => 'tt_content']);

        $refusals = (new RecordedErrors())->refusalsAfter($this->getConnectionPool()->getConnectionForTable('sys_log'), 0);

        self::assertSame([['message' => 'this table is not allowed', 'table' => 'tt_content']], $refusals);
    }

    #[Test]
    public function answersExactlyWhatTheContractFixtureHolds(): void
    {
        $this->insertRow(['type' => 1, 'channel' => 'content', 'error' => 1, 'tstamp' => 1760954471, 'details' => "Attempt to insert a record on page '%s' (%s) where this table, %s, is not allowed", 'log_data' => '["\/gallery","127","sys_file_reference"]', 'tablename' => 'sys_file_reference']);
        $this->insertRow(['type' => 5, 'channel' => 'php', 'error' => 2, 'level' => 'error', 'tstamp' => 1760954471, 'details' => 'Core: Exception handler (WEB): Uncaught TYPO3 Exception: no page configured']);
        $this->insertRow(['type' => 5, 'channel' => 'php', 'error' => 2, 'level' => 'error', 'tstamp' => 1760954471, 'details' => 'Core: Exception handler (WEB): Uncaught TYPO3 Exception: no page configured']);
        $this->insertRow(['type' => 0, 'component' => 'TYPO3.CMS.Core.Resource.ResourceStorage', 'level' => '2', 'tstamp' => 1760954471, 'message' => 'Failed initializing storage']);

        $result = (new RecordedErrors())->readFrom($this->getConnectionPool()->getConnectionForTable('sys_log'), 20);

        self::assertSame(
            ContractFixture::read('errors-response')['errors'],
            $result['errors']
        );
        self::assertSame(ContractFixture::read('errors-response')['truncated'], $result['truncated']);
    }

    #[Test]
    public function dropsTheLegacyTwinCoreWritesForEveryUncaughtException(): void
    {
        $this->insertRow([
            'type' => 0,
            'component' => 'TYPO3.CMS.Core.Error.ProductionExceptionHandler',
            'level' => '2',
            'message' => 'Core: Exception handler (WEB: FE): {exception_class}, code #{exception_code}: {message}',
            'data' => '{"exception_class":"RuntimeException","exception_code":1607585445,"message":"No page configured"}',
        ]);
        $this->insertRow([
            'type' => 5,
            'channel' => 'php',
            'error' => 2,
            'details' => 'Core: Exception handler (WEB): Uncaught TYPO3 Exception: #1607585445: No page configured | RuntimeException thrown in file x in line 5.',
        ]);

        $errors = (new RecordedErrors())->readFrom($this->getConnectionPool()->getConnectionForTable('sys_log'), 200)['errors'];

        self::assertSame(['log'], array_column($errors, 'source'));
    }

    #[Test]
    public function dropsTheLegacyTwinOfAnExceptionThatCarriesNoCode(): void
    {
        $this->insertRow([
            'type' => 0,
            'component' => 'TYPO3.CMS.Core.Error.ProductionExceptionHandler',
            'level' => '2',
            'message' => 'Core: Exception handler (WEB: FE): {exception_class}, code #{exception_code}, file {file}, line {line}: {message}',
            'data' => '{"exception_class":"TypeError","exception_code":0,"file":"/app/Thing.php","line":42,"message":"Argument #1 must be of type string"}',
        ]);
        $this->insertRow([
            'type' => 5,
            'channel' => 'php',
            'error' => 2,
            'details' => 'Core: Exception handler (WEB): Uncaught TYPO3 Exception: Argument #1 must be of type string | TypeError thrown in file /app/Thing.php in line 42.',
        ]);

        $errors = (new RecordedErrors())->readFrom($this->getConnectionPool()->getConnectionForTable('sys_log'), 200)['errors'];

        self::assertSame(['log'], array_column($errors, 'source'));
    }

    #[Test]
    public function repeatsDoNotUseUpTheRoomForDistinctErrors(): void
    {
        for ($index = 0; $index < 25; ++$index) {
            $this->insertRow(['type' => 5, 'channel' => 'php', 'error' => 2, 'details' => 'the same failure']);
        }
        $this->insertRow(['type' => 5, 'channel' => 'php', 'error' => 2, 'details' => 'the last distinct failure']);

        $result = (new RecordedErrors())->readFrom($this->getConnectionPool()->getConnectionForTable('sys_log'), 20);

        self::assertSame(
            ['the same failure', 'the last distinct failure'],
            array_column($result['errors'], 'message')
        );
        self::assertFalse($result['truncated']);
    }

    #[Test]
    public function truncatedTurnsOnOnePastTheLimitAndNotAtIt(): void
    {
        for ($index = 1; $index <= 5; ++$index) {
            $this->insertRow(['type' => 5, 'channel' => 'php', 'error' => 2, 'details' => 'failure ' . $index]);
        }
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_log');

        self::assertFalse((new RecordedErrors())->readFrom($connection, 5)['truncated']);
        self::assertTrue((new RecordedErrors())->readFrom($connection, 4)['truncated']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertRow(array $row): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_log')->insert('sys_log', $row + [
            'type' => 0,
            'channel' => 'default',
            'level' => 'info',
            'error' => 0,
            'details' => '',
            'log_data' => '',
            'tablename' => '',
            'recuid' => 0,
            'tstamp' => 1760954471,
            'time_micro' => 0.0,
            'component' => '',
            'message' => '',
            'data' => '',
        ]);
    }
}
