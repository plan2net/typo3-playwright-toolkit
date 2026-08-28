<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Http\SavedRecord;

final class SavedRecordTest extends TestCase
{
    #[Test]
    public function readsTheUidOutOfTheRedirectTheSaveAnswersWith(): void
    {
        self::assertSame(
            42,
            SavedRecord::uidFrom('/typo3/record/edit?edit%5Bpages%5D%5B42%5D=edit&token=abc', 'pages')
        );
    }
}
