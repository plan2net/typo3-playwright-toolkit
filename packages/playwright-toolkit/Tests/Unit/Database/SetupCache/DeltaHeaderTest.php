<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database\SetupCache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Database\Driver\Engine;
use Plan2net\PlaywrightToolkit\Database\SetupCache\DeltaHeader;

final class DeltaHeaderTest extends TestCase
{
    #[Test]
    public function readsBackTheHeaderItWrote(): void
    {
        $header = new DeltaHeader(
            engine: Engine::Postgres,
            templateFingerprint: 'fingerprint',
            tables: ['pages' => 'hash-a', 'tt_content' => 'hash-b'],
            state: ['slug' => '/text-ABCD1234EFGH5678'],
        );

        self::assertEquals($header, DeltaHeader::fromLine($header->toLine()));
    }

    #[Test]
    public function refusesADeltaBuiltAgainstAnotherTemplate(): void
    {
        $header = self::header(templateFingerprint: 'built-against-this');

        self::assertFalse($header->appliesTo(Engine::Postgres, 'but-the-template-says-this'));
        self::assertTrue($header->appliesTo(Engine::Postgres, 'built-against-this'));
    }

    #[Test]
    public function refusesADeltaWrittenForAnotherEngine(): void
    {
        $header = self::header(engine: Engine::Mysql);

        self::assertFalse($header->appliesTo(Engine::Postgres, 'fingerprint'));
    }

    #[Test]
    #[DataProvider('unusableLines')]
    public function readsNoHeaderFrom(string $line): void
    {
        self::assertNull(DeltaHeader::fromLine($line));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableLines(): iterable
    {
        yield 'a statement rather than a header' => ['DELETE FROM "pages";'];
        yield 'the prefix with broken JSON' => [DeltaHeader::PREFIX . '{"engine": '];
        yield 'JSON that is not an object' => [DeltaHeader::PREFIX . '"pages"'];
        yield 'a header missing a field' => [DeltaHeader::PREFIX . '{"engine":"postgres"}'];
        yield 'an engine this build does not know' => [
            DeltaHeader::PREFIX
            . '{"engine":"oracle","templateFingerprint":"f","tables":{},"state":{}}',
        ];
    }

    private static function header(
        Engine $engine = Engine::Postgres,
        string $templateFingerprint = 'fingerprint',
    ): DeltaHeader {
        return new DeltaHeader(
            engine: $engine,
            templateFingerprint: $templateFingerprint,
            tables: ['pages' => 'hash-a'],
            state: ['slug' => '/text-ABCD1234EFGH5678'],
        );
    }
}
