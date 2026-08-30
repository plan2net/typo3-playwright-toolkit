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
    private const ROOT_PATH = 'LOG/writerConfiguration/' . LogLevel::ERROR . '/' . DatabaseWriter::class;

    #[Test]
    public function settingsNameTheDatabaseWriterAtErrorLevel(): void
    {
        self::assertSame([self::ROOT_PATH => []], ErrorCapture::settings([]));
    }

    #[Test]
    public function settingsReachALoggerThatCarriesItsOwnWriterConfiguration(): void
    {
        $configuration = [
            'TYPO3' => ['CMS' => ['Core' => ['Resource' => ['ResourceStorage' => [
                'writerConfiguration' => [LogLevel::ERROR => [FileWriter::class => []]],
            ]]]]],
        ];

        self::assertArrayHasKey(
            'LOG/TYPO3/CMS/Core/Resource/ResourceStorage/writerConfiguration/' . LogLevel::ERROR . '/' . DatabaseWriter::class,
            ErrorCapture::settings($configuration)
        );
    }

    #[Test]
    public function settingsSkipADatabaseWriterTheProjectAlreadyConfigured(): void
    {
        $configuration = [
            'writerConfiguration' => [LogLevel::ERROR => [DatabaseWriter::class => ['logTable' => 'own_log']]],
        ];

        self::assertSame([], ErrorCapture::settings($configuration));
    }

    #[Test]
    public function settingsTouchNothingBesideAWriterTheProjectConfigured(): void
    {
        $configuration = [
            'writerConfiguration' => [LogLevel::ERROR => [FileWriter::class => ['logFile' => 'var/log/own.log']]],
        ];

        self::assertSame([self::ROOT_PATH => []], ErrorCapture::settings($configuration));
    }

    #[Test]
    public function settingsLeaveALoggerWithoutAWriterConfigurationAlone(): void
    {
        $configuration = [
            'TYPO3' => ['CMS' => ['deprecations' => ['someOtherSetting' => true]]],
        ];

        self::assertSame([self::ROOT_PATH => []], ErrorCapture::settings($configuration));
    }
}
