<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Live\LiveChanges;
use CW\PurchaseOrders\PoMath;
use CW\Ui\LiveSaveWords;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * Each box saved by itself (the live drafts plan, step 4; docs/decisions.md LS3, LS6, LS7, LS12), the parts that need no database: the totals of
 * an order after one line changed are the totals over every line again (PurchaseOrders::retotal: never a delta that could drift); the audit's
 * hour (one row per person per draft per clock hour); the History card's groups (a person's run of changes in one minute) and their words; a
 * line's values in the page's words.
 */
final class LiveSaveUnitTest extends TestCase
{
    public function testTheTotalsAfterOneLineChangedAreTheTotalsOverEveryLine(): void
    {
        $lines = [['amount_e2' => 29100, 'vat_code' => 'S', 'rate_e2' => 2000], ['amount_e2' => 4600, 'vat_code' => 'S', 'rate_e2' => 2000],
            ['amount_e2' => 800, 'vat_code' => 'Z', 'rate_e2' => 0]];
        $before = PoMath::totals($lines);
        self::assertSame(34500, $before['net_e2']);
        // Line 2's packs from 50 to 51 (£0.92 a pack): one line's amount again, the totals over every line.
        $lines[1]['amount_e2'] = PoMath::lineAmountE2(51, PoMath::e4('0.92'));
        $after = PoMath::totals($lines);
        self::assertSame(34592, $after['net_e2']);
        $reversed = PoMath::totals(array_reverse($lines));
        ksort($reversed['by_code']);
        $sorted = $after;
        ksort($sorted['by_code']);
        self::assertSame($sorted, $reversed, 'the order of the lines changes no figure');
        self::assertSame($after['net_e2'] + $after['vat_e2'], $after['gross_e2']);
        self::assertSame(PoMath::lineVatE2(29100, 2000) + PoMath::lineVatE2(4692, 2000) + PoMath::lineVatE2(800, 0), $after['vat_e2'], 'VAT line by line, as saveDraft');
    }

    public function testTheAuditsHourIsTheClockHour(): void
    {
        self::assertSame('2026-11-19 10:00:00.000000', LiveChanges::hourStart('2026-11-19 10:00:00.000000'));
        self::assertSame('2026-11-19 10:00:00.000000', LiveChanges::hourStart('2026-11-19 10:59:59.999999'));
        self::assertSame('2026-11-19 11:00:00.000000', LiveChanges::hourStart('2026-11-19 11:05:00.000000'));
        self::assertSame(1, preg_match(LiveChanges::KEY, 'c0ffee0000000001'));
        self::assertSame(0, preg_match(LiveChanges::KEY, 'C0FFEE0000000001'));
        self::assertNull(LiveChanges::tab('not a tab'));
        self::assertSame('0123456789abcdef', LiveChanges::tab('0123456789abcdef'));
    }

    public function testTheHistoryGroupsAPersonsChangesOfAMinuteAndSaysThem(): void
    {
        $row = static fn (int $id, int $who, string $name, string $at, string $op, ?int $uid, ?array $b = null, ?array $a = null, ?string $field = null): array => ['id' => $id,
            'staff_user_id' => $who, 'name' => $name, 'tab' => null, 'op' => $op, 'line_uid' => $uid, 'field' => $field, 'before' => $b, 'after' => $a, 'created_at' => $at];
        // Newest first, as LiveChanges::history() reads them.
        $rows = [
            $row(6, 2, 'Fazil', '2026-11-19 14:02:50.000000', 'header', null, ['value' => null], ['value' => 'Call first'], 'note'),
            $row(5, 2, 'Fazil', '2026-11-19 14:02:30.000000', 'set', 3, ['packs' => 5, 'pack_price' => '1.0000', 'units_per_pack' => 1, 'name' => 'Elf Bar'],
                ['packs' => 8, 'pack_price' => '1.0000', 'units_per_pack' => 1, 'name' => 'Elf Bar']),
            $row(4, 2, 'Fazil', '2026-11-19 14:02:10.000000', 'set', 3, ['packs' => 4, 'pack_price' => '1.0000', 'name' => 'Elf Bar'], ['packs' => 5, 'pack_price' => '1.0000', 'name' => 'Elf Bar']),
            $row(3, 2, 'Fazil', '2026-11-19 14:02:00.000000', 'remove', 2, ['packs' => 2, 'name' => 'Mint']),
            $row(2, 5, 'Asha', '2026-11-19 14:01:00.000000', 'add', 4, null, ['packs' => 3, 'purchase_unit' => 'each', 'units_per_pack' => 1, 'name' => 'Coil']),
            $row(1, 2, 'Fazil', '2026-11-19 14:00:00.000000', 'set', 1, ['packs' => 1, 'name' => 'Pod'], ['packs' => 2, 'name' => 'Pod']),
        ];
        $g = LiveChanges::group($rows);
        self::assertSame([['Fazil', 2, true, 4], ['Asha', 1, false, 1], ['Fazil', 1, false, 1]], array_map(static fn (array $x): array => [$x['name'], $x['lines'], $x['head'],
            count($x['rows'])], $g), 'a run of one person in one minute: lines 2 and 3 and the note; then Asha; then Fazil again');
        self::assertSame([3, 4, 5, 6], array_column($g[0]['rows'], 'id'), 'each group\'s changes oldest first');
        $h = LiveSaveWords::history($g);
        self::assertSame(Words::say('LIVE_SAVE', 'ev_lines_details', 'Fazil', '2'), $h[0]['text']);
        self::assertSame(Words::say('LIVE_SAVE', 'ev_line', 'Asha'), $h[1]['text']);
        self::assertSame([Words::say('LIVE_SAVE', 'fold_remove', 'Mint'), Words::say('LIVE_SAVE', 'fold_packs', '4', '5', 'Elf Bar'),
            Words::say('LIVE_SAVE', 'fold_packs', '5', '8', 'Elf Bar'), Words::say('LIVE_SAVE', 'fold_head', Words::ORDER['note_title'], Words::LIVE_SAVE['empty'], 'Call first')],
            $h[0]['fold']);
        self::assertSame([Words::say('LIVE_SAVE', 'fold_add', 'Coil', '3')], $h[1]['fold']);
        self::assertSame('2026-11-19 14:02:50.000000', $h[0]['at'], 'the group\'s time: its newest change');
        // A change said to the other pages: the line's number now, else the one it had.
        self::assertSame(Words::say('LIVE_SAVE', 'ev_set', 'Fazil', '1', 'Elf Bar'), LiveSaveWords::event($rows[1], [3 => 1]));
        self::assertSame(Words::say('LIVE_SAVE', 'ev_remove', 'Fazil', '', 'Mint'), LiveSaveWords::event($rows[3], []));
    }

    public function testALinesValuesInThePagesWords(): void
    {
        self::assertSame('8', LiveSaveWords::packs(8, 'each', 1), 'one item a pack: the number');
        self::assertSame(Words::say('LIVE_SAVE', 'packs', '8', Words::say('SUPPLIER_ITEMS', 'pack_of', 'box', 10)), LiveSaveWords::packs(8, 'box', 10));
        self::assertSame(Words::say('LIVE_SAVE', 'at_price', Words::say('LIVE_SAVE', 'packs', '8', Words::say('SUPPLIER_ITEMS', 'pack_of', 'box', 10)), '£12.50'),
            LiveSaveWords::values(['packs' => 8, 'pack_price' => '12.5000', 'purchase_unit' => 'box', 'units_per_pack' => 10]));
        self::assertSame('£12.50', LiveSaveWords::values(['packs' => 8, 'pack_price' => '12.5000', 'units_per_pack' => 10, 'purchase_unit' => 'box'], ['pack_price']),
            'only the boxes that differ');
        self::assertSame(Words::say('LIVE_SAVE', 'per_pack', '12'), LiveSaveWords::values(['units_per_pack' => 12], ['units_per_pack']));
    }
}
