<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Log;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Log\RecordedErrors;
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

        $errors = (new RecordedErrors())->readFrom($this->getConnectionPool()->getConnectionForTable('sys_log'), 200);

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

        $errors = (new RecordedErrors())->readFrom($this->getConnectionPool()->getConnectionForTable('sys_log'), 200);

        self::assertSame(['the same failure', 'a different failure'], array_column($errors, 'message'));
        self::assertSame([3, 1], array_column($errors, 'count'));
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

        $errors = (new RecordedErrors())->readFrom($this->getConnectionPool()->getConnectionForTable('sys_log'), 200);

        self::assertSame(['log'], array_column($errors, 'source'));
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
