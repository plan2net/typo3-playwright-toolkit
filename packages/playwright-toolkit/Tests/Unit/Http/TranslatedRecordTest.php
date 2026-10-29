<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Http\TranslatedRecord;

final class TranslatedRecordTest extends TestCase
{
    #[Test]
    public function carriesTheNewUidItsPageAndItsReferencesPerColumn(): void
    {
        self::assertSame(
            '{"uid":3,"pid":2,"references":{"media":[6,7]}}',
            TranslatedRecord::header(3, 2, ['media' => [6, 7]])
        );
    }

    #[Test]
    public function writesNoReferencesAsAnEmptyObject(): void
    {
        self::assertSame('{"uid":3,"pid":2,"references":{}}', TranslatedRecord::header(3, 2, []));
    }

    #[Test]
    public function dropsTheReferencesWhenTheyWouldOutgrowAHeaderAWebserverPassesOn(): void
    {
        $header = TranslatedRecord::header(3, 2, ['assets' => range(100000, 100500)]);

        self::assertSame('{"uid":3,"pid":2,"truncated":true}', $header);
    }
}
