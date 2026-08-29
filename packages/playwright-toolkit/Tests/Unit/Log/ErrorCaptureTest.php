<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Log\ErrorCapture;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\DatabaseWriter;
use TYPO3\CMS\Core\Log\Writer\FileWriter;

final class ErrorCaptureTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $originalConfiguration = null;

    private bool $hadConfiguration = false;

    protected function setUp(): void
    {
        $this->hadConfiguration = isset($GLOBALS['TYPO3_CONF_VARS']['LOG']);
        $this->originalConfiguration = $GLOBALS['TYPO3_CONF_VARS']['LOG'] ?? null;
    }

    protected function tearDown(): void
    {
        if (!$this->hadConfiguration) {
            unset($GLOBALS['TYPO3_CONF_VARS']['LOG']);

            return;
        }

        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = $this->originalConfiguration;
    }

    #[Test]
    public function registersTheDatabaseWriterAtErrorLevel(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = [];

        ErrorCapture::register();

        self::assertArrayHasKey(
            DatabaseWriter::class,
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'][LogLevel::ERROR]
        );
    }

    #[Test]
    public function registersTheDatabaseWriterInANestedConfiguration(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = [
            'TYPO3' => ['CMS' => ['Core' => ['Resource' => ['ResourceStorage' => [
                'writerConfiguration' => [LogLevel::ERROR => [FileWriter::class => []]],
            ]]]]],
        ];

        ErrorCapture::register();

        self::assertArrayHasKey(
            DatabaseWriter::class,
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['TYPO3']['CMS']['Core']['Resource']['ResourceStorage']['writerConfiguration'][LogLevel::ERROR]
        );
    }

    #[Test]
    public function keepsAWriterTheProjectAlreadyConfigured(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = [
            'writerConfiguration' => [LogLevel::ERROR => [FileWriter::class => ['logFile' => 'var/log/own.log']]],
        ];

        ErrorCapture::register();

        self::assertSame(
            ['logFile' => 'var/log/own.log'],
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'][LogLevel::ERROR][FileWriter::class]
        );
    }

    #[Test]
    public function leavesANodeThatHasNoConfigurationAlone(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = [
            'TYPO3' => ['CMS' => ['deprecations' => ['someOtherSetting' => true]]],
        ];

        ErrorCapture::register();

        self::assertSame(
            ['someOtherSetting' => true],
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['TYPO3']['CMS']['deprecations']
        );
    }
}
