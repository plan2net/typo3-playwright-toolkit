<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Log\RecordedErrors;

final class RecordedErrorsTest extends TestCase
{
    #[Test]
    public function mapsADataHandlerRow(): void
    {
        $row = [
            'uid' => 412,
            'type' => 1,
            'channel' => 'content',
            'tstamp' => 1787040861,
            'time_micro' => 0,
            'component' => '',
            'level' => 'info',
            'error' => 1,
            'details' => "Attempt to insert a record on page '%s' (%s) where this table, %s, is not allowed",
            'log_data' => '["\/gallery","127","sys_file_reference"]',
            'tablename' => 'sys_file_reference',
            'recuid' => 0,
        ];

        self::assertSame(
            [
                'uid' => 412,
                'source' => 'datahandler',
                'at' => '2026-08-18T08:14:21+00:00',
                'message' => "Attempt to insert a record on page '/gallery' (127) where this table, sys_file_reference, is not allowed",
                'table' => 'sys_file_reference',
                'recordUid' => 0,
            ],
            (new RecordedErrors())->fromRow($row)
        );
    }

    #[Test]
    public function mapsAnUncaughtExceptionWrittenThroughTheLegacyPath(): void
    {
        $row = [
            'uid' => 154,
            'type' => 5,
            'channel' => 'php',
            'tstamp' => 1760954471,
            'time_micro' => 0,
            'component' => '',
            'level' => 'error',
            'error' => 2,
            'details' => 'Core: Exception handler (WEB): Uncaught TYPO3 Exception: TYPO3\CMS\Core\Page\JavaScriptRenderer::TYPO3\CMS\Core\Page\{closure}(): Argument #1 ($url) must be of type string, null given | TypeError thrown in file /var/www/html/vendor/typo3/cms-core/Classes/Page/JavaScriptRenderer.php in line 136.',
            'log_data' => '',
            'tablename' => '',
            'recuid' => 0,
            'message' => null,
            'data' => null,
        ];

        self::assertSame(
            [
                'uid' => 154,
                'source' => 'php',
                'at' => '2025-10-20T10:01:11+00:00',
                'level' => 'error',
                'message' => 'Core: Exception handler (WEB): Uncaught TYPO3 Exception: TYPO3\CMS\Core\Page\JavaScriptRenderer::TYPO3\CMS\Core\Page\{closure}(): Argument #1 ($url) must be of type string, null given | TypeError thrown in file /var/www/html/vendor/typo3/cms-core/Classes/Page/JavaScriptRenderer.php in line 136.',
            ],
            (new RecordedErrors())->fromRow($row)
        );
    }

    #[Test]
    public function mapsALogRowWrittenByTheDatabaseWriter(): void
    {
        $row = [
            'uid' => 277,
            'type' => 0,
            'channel' => 'default',
            'tstamp' => 0,
            'time_micro' => 1761251868.0143,
            'component' => 'TYPO3.CMS.Core.Resource.ResourceStorage',
            'level' => '3',
            'error' => 0,
            'details' => null,
            'log_data' => null,
            'tablename' => '',
            'recuid' => 0,
            'message' => 'Failed initializing storage [1] "fileadmin", error: Base path "/var/www/html/public/fileadmin/" does not exist or is no directory.',
            'data' => '',
        ];

        self::assertSame(
            [
                'uid' => 277,
                'source' => 'log',
                'at' => '2025-10-23T20:37:48+00:00',
                'level' => 'error',
                'component' => 'TYPO3.CMS.Core.Resource.ResourceStorage',
                'message' => 'Failed initializing storage [1] "fileadmin", error: Base path "/var/www/html/public/fileadmin/" does not exist or is no directory.',
            ],
            (new RecordedErrors())->fromRow($row)
        );
    }
}
