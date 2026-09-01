<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Database\TemplatePreparer;

final class TemplatePreparerTest extends TestCase
{
    #[Test]
    public function namesTheOtherConnectionsTheSchemaBuildAlsoOpens(): void
    {
        $message = TemplatePreparer::schemaFailureMessage(
            'SQLSTATE[HY000] [2002] Connection refused',
            ['Default', 'Legacy']
        );

        self::assertStringContainsString('Legacy', $message);
        self::assertStringContainsString('Connection refused', $message);
    }
}
