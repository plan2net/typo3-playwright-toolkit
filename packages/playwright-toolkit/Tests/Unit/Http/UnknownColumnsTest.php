<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\PlaywrightToolkit\Http\UnknownColumns;

final class UnknownColumnsTest extends TestCase
{
    #[Test]
    public function flagsAColumnTcaDoesNotKnow(): void
    {
        $tca = ['tt_content' => ['columns' => ['header' => [], 'bodytext' => []]]];
        $data = ['tt_content' => ['NEW1' => ['zzzzzzzzzz' => 'a value']]];

        self::assertSame(
            [[
                'table' => 'tt_content',
                'message' => 'Unknown column "zzzzzzzzzz" on tt_content. TCA has no such column, '
                    . 'so DataHandler would drop it and save the record without it.',
            ]],
            UnknownColumns::check($data, $tca)
        );
    }

    #[Test]
    public function suggestsTheColumnTheTypoIsClosestTo(): void
    {
        $tca = ['tt_content' => ['columns' => ['header' => [], 'bodytext' => []]]];
        $data = ['tt_content' => ['NEW1' => ['bodytxt' => 'a value']]];

        self::assertSame(
            'Unknown column "bodytxt" on tt_content. TCA has no such column, '
                . 'so DataHandler would drop it and save the record without it. '
                . 'Did you mean "bodytext"?',
            UnknownColumns::check($data, $tca)[0]['message']
        );
    }

    // Four case differences: past the distance limit unless both sides are lowercased.
    #[Test]
    public function suggestsAColumnThatDiffersOnlyInCase(): void
    {
        $tca = ['tx_test_table' => ['columns' => ['myCamelCaseColumnName' => []]]];
        $data = ['tx_test_table' => ['NEW1' => ['mycamelcasecolumnname' => 'a value']]];

        self::assertStringContainsString(
            'Did you mean "myCamelCaseColumnName"?',
            UnknownColumns::check($data, $tca)[0]['message']
        );
    }

    #[Test]
    public function letsThroughPidWhichFillInFieldArrayHandlesItself(): void
    {
        $tca = ['tt_content' => ['columns' => ['header' => []]]];
        $data = ['tt_content' => ['NEW1' => ['pid' => 2]]];

        self::assertSame([], UnknownColumns::check($data, $tca));
    }

    #[Test]
    public function letsThroughEveryOtherKeyThatSwitchAnswers(): void
    {
        $tca = ['tt_content' => ['columns' => ['header' => []]]];
        $data = ['tt_content' => ['NEW1' => [
            'uid' => 1,
            'l10n_state' => '{}',
            't3ver_oid' => 0,
            't3ver_wsid' => 0,
            't3ver_state' => 0,
            't3ver_stage' => 0,
        ]]];

        self::assertSame([], UnknownColumns::check($data, $tca));
    }

    #[Test]
    public function letsPermissionColumnsThroughOnPages(): void
    {
        $tca = ['pages' => ['columns' => ['title' => []]]];
        $data = ['pages' => ['NEW1' => [
            'perms_userid' => 1,
            'perms_groupid' => 1,
            'perms_user' => 31,
            'perms_group' => 27,
            'perms_everybody' => 0,
        ]]];

        self::assertSame([], UnknownColumns::check($data, $tca));
    }

    #[Test]
    public function flagsAPermissionColumnOnAnyOtherTable(): void
    {
        $tca = ['tt_content' => ['columns' => ['header' => []]]];
        $data = ['tt_content' => ['NEW1' => ['perms_user' => 31]]];

        self::assertCount(1, UnknownColumns::check($data, $tca));
    }

    // A made-up name, so this pins the ctrl lookup and not a core version.
    #[Test]
    public function letsThroughTheFieldCtrlOrigUidNames(): void
    {
        $tca = ['tx_test_table' => [
            'ctrl' => ['origUid' => 'tx_test_ancestor'],
            'columns' => ['title' => []],
        ]];
        $data = ['tx_test_table' => ['NEW1' => ['tx_test_ancestor' => 12]]];

        self::assertSame([], UnknownColumns::check($data, $tca));
    }

    #[Test]
    public function reportsAnUnknownTableOnceInsteadOfEveryColumnOnIt(): void
    {
        $tca = ['tt_content' => ['columns' => ['header' => []]]];
        $data = ['tx_foo' => ['NEW1' => ['title' => 'a', 'subtitle' => 'b']]];

        self::assertSame(
            [['table' => 'tx_foo', 'message' => 'Unknown table "tx_foo". TCA has no such table.']],
            UnknownColumns::check($data, $tca)
        );
    }

    #[Test]
    public function collapsesTheSameColumnRepeatedAcrossABatch(): void
    {
        $tca = ['tt_content' => ['columns' => ['header' => []]]];
        $records = [];
        foreach (range(1, 40) as $index) {
            $records['NEW' . $index] = ['bodytxt' => 'a value'];
        }

        self::assertCount(1, UnknownColumns::check(['tt_content' => $records], $tca));
    }

    #[Test]
    public function truncatesAColumnNameLongerThanAnyTcaColumn(): void
    {
        $tca = ['tt_content' => ['columns' => ['header' => []]]];
        $data = ['tt_content' => ['NEW1' => [str_repeat('x', 300) => 'a value']]];

        self::assertStringContainsString(
            sprintf('Unknown column "%s" on tt_content.', str_repeat('x', 64)),
            UnknownColumns::check($data, $tca)[0]['message']
        );
    }
}
