<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Duty;

use CW\Duty\DutyRegister;
use CW\Settings;
use CW\Tests\Integration\Receiving\ReceivingTestCase;

/**
 * The vaping duty register (pack H, IM13; docs/decisions.md PH15-PH18) against real receipts: its lines ARE the receipts' posted lines
 * (every field read from them, nothing copied), with the stamp state and the supplier's declaration per delivery line; the filters; the
 * setting that lists every line; a reversed receipt stays listed, marked; the unstamped stock and where it is, with the alarm from its
 * date. The invariants hold after every test (receipts G1-G8 with the rest).
 */
final class DutyRegisterTest extends ReceivingTestCase
{
    private function register(): DutyRegister
    {
        return new DutyRegister(self::$db, new Settings(self::$db));
    }

    public function testTheRegisterLinesAreTheReceiptsOwnWithTheStampAndTheDeclaration(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Duty Supplies');
        $stamped = self::makeSku('Stamped liquid 10ml');
        $this->liquid($stamped);
        $declared = self::makeSku('Declared liquid 10ml');
        $this->liquid($declared);
        $coil = self::makeSku('A coil');
        $this->dry($coil);
        $bench = $this->staffUser('goods_in');

        // 1: stamped liquid, and a coil that needs no stamp.
        $r1 = $this->receipt($desk, (int) $s['id'], [['sku_id' => $stamped, 'packs' => 2, 'units_per_pack' => 10, 'pack_price' => '30.00'],
            ['sku_id' => $coil, 'packs' => 5, 'units_per_pack' => 1, 'pack_price' => '1.00']], ['delivery_note' => 'DN-100']);
        $this->ready($desk, $r1->id);
        $p1 = $this->post($desk, $r1->id);
        // 2: unstamped, accepted on the supplier's declaration (before the refusal date), with a file of it.
        $r2 = $this->receipt($desk, (int) $s['id'], [['sku_id' => $declared, 'packs' => 1, 'units_per_pack' => 12, 'pack_price' => '20.00']]);
        $this->invoice($desk, $r2->id);
        $this->grns->attach($desk, $r2->id, $this->pdf('Supplier letter'), 'letter.pdf', 'evidence');
        $this->bench($bench, $r2->id, [1 => ['stamp_on_pack' => '0', 'unstamped_units' => '12', 'unstamped_action' => 'accept_pre_october',
            'pre_october_evidence' => 'Supplier letter 20 Sep: made on 1 Sep 2026']]);
        $p2 = $this->post($desk, $r2->id);
        // 3: after the refusal date, unstamped and quarantined.
        $this->setting('receiving.unstamped_refusal_from', json_encode(self::day('-1 day'), JSON_THROW_ON_ERROR));
        $r3 = $this->receipt($desk, (int) $s['id'], [['sku_id' => $stamped, 'packs' => 1, 'units_per_pack' => 6, 'pack_price' => '18.00']]);
        $this->invoice($desk, $r3->id);
        $this->bench($bench, $r3->id, [1 => ['stamp_on_pack' => '0', 'unstamped_units' => '6', 'unstamped_action' => 'quarantine']]);
        $p3 = $this->post($desk, $r3->id);

        $reg = $this->register();
        $all = DutyRegister::filters([]);
        $rows = $reg->rows($all, 100);
        self::assertSame(3, $reg->count($all), 'the coil needed no stamp check: not listed');
        self::assertCount(3, $rows);
        // Every field is the receipt's own.
        foreach ($rows as $r) {
            $g = (array) self::$db->one('SELECT g.*, dl.sku_id, dl.qty, d.number, d.external_ref, gr.delivery_note, gr.received_at, gr.supplier_id FROM grn_line g '
                . 'JOIN document_line dl ON dl.document_id = g.document_id AND dl.line_no = g.line_no JOIN document d ON d.id = g.document_id '
                . 'JOIN goods_receipt gr ON gr.document_id = g.document_id WHERE g.document_id = ? AND g.line_no = ?', [$r['document_id'], $r['line_no']]);
            $want = [(string) $g['number'], (string) $g['received_at'], (string) $g['external_ref'], $g['delivery_note'], (int) $g['supplier_id'], (int) $g['sku_id'],
                (int) $g['qty'], (int) $g['accepted_units'], (int) $g['quarantine_units'], (int) $g['refused_units'], (int) $g['unstamped_units'], $g['unstamped_action'],
                $g['pre_october_evidence'], $g['stamp_type'], $g['stamp_code'], $g['duty_ml'] === null ? null : (string) $g['duty_ml'],
                $g['expected_duty'] === null ? null : (string) $g['expected_duty'], $g['stamp_on_pack'] === null ? null : (int) $g['stamp_on_pack'] === 1];
            $got = [(string) $r['number'], (string) $r['received_at'], (string) $r['supplier_invoice'], $r['delivery_note'], $r['supplier_id'], $r['sku_id'], $r['units'],
                $r['accepted_units'], $r['quarantine_units'], $r['refused_units'], $r['unstamped_units'], $r['unstamped_action'], $r['pre_october_evidence'],
                $r['stamp_type'], $r['stamp_code'], $r['duty_ml'] === null ? null : (string) $r['duty_ml'], $r['expected_duty'] === null ? null : (string) $r['expected_duty'],
                $r['stamp_on_pack']];
            self::assertSame($want, $got, "line {$r['number']}/{$r['line_no']}");
        }
        // Newest arrival first: the quarantined, the declared, the stamped.
        self::assertSame([[$p3->number, 'unstamped', 0, 6, 'quarantine', null], [$p2->number, 'declaration', 0, 12, 'accept_pre_october', 'Supplier letter 20 Sep: made on 1 Sep 2026'],
            [$p1->number, 'stamped', 20, 0, null, null]], array_map(static fn (array $r): array => [$r['number'], $r['stamp_state'], $r['stamped_units'], $r['unstamped_units'],
                $r['unstamped_action'], $r['pre_october_evidence']], $rows));
        self::assertSame([false, true, false], array_column($rows, 'evidence_file'), 'the declaration\'s file is kept with the delivery');
        self::assertSame(['digital', 'DN-100'], [$rows[2]['stamp_type'], $rows[2]['delivery_note']]);
        self::assertSame([1, 0, 0], array_column($rows, 'incidents'), 'the quarantine opened an incident; a declaration none');
        self::assertSame(['10.0', '44.00'], [(string) $rows[2]['duty_ml'], (string) $rows[2]['expected_duty']], '20 units of 10 ml at 22p per ml: 2.20 a unit');

        // Filters.
        self::assertSame([$p2->number], array_column($reg->rows(DutyRegister::filters(['stamp' => 'declaration']), 100), 'number'));
        self::assertSame([$p3->number, $p1->number], array_column($reg->rows(DutyRegister::filters(['q' => 'Stamped liquid']), 100), 'number'));
        self::assertSame(3, $reg->count(DutyRegister::filters(['from' => self::day('+0 day'), 'to' => self::day('+0 day'), 'supplier' => (string) $s['id']])));
        self::assertSame(0, $reg->count(DutyRegister::filters(['from' => self::day('+1 day')])));
        self::assertSame(['from' => null, 'to' => null, 'supplier' => null, 'stamp' => null, 'state' => null, 'q' => ''],
            DutyRegister::filters(['from' => '14/10/2026', 'supplier' => 'x', 'stamp' => 'maybe', 'state' => 'draft']), 'what the register cannot read is no filter');
        // Every line, when the setting says so.
        $this->setting('duty.register_lines', '"all"');
        $rowsAll = $this->register()->rows($all, 100);
        self::assertCount(4, $rowsAll);
        self::assertSame(['not_needed', $coil], [$rowsAll[3]['stamp_state'], $rowsAll[3]['sku_id']]);
        $this->setting('duty.register_lines', '"stamp"');
        // A reversed receipt stays in the register, marked; "booked in" leaves it out.
        $this->docs->reverse($desk, $p1->id, 'entered_in_error', null);
        $after = $this->register()->rows($all, 100);
        self::assertSame(['posted', 'posted', 'reversed'], array_column($after, 'status'));
        self::assertSame(2, $this->register()->count(DutyRegister::filters(['state' => 'posted'])));
        self::assertSame([['id' => (int) $s['id'], 'name' => 'Duty Supplies']], $this->register()->suppliers());
    }

    /**
     * Review finding 4 (Codex, 14 Oct): the damaged and over units of an unstamped delivery go with its unstamped units
     * (ReceiptMath::extras, I167): the register counts them unstamped, never stamped, as the receipt booked them.
     */
    public function testTheDamagedAndOverUnitsOfAnUnstampedDeliveryAreUnstamped(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Extras Supplies');
        $sku = self::makeSku('Extras liquid 10ml');
        $this->liquid($sku);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 10, 'units_per_pack' => 10, 'pack_price' => '20.00']]);
        $this->invoice($desk, $d->id);
        $this->bench($this->staffUser('goods_in'), $d->id, [1 => ['stamp_on_pack' => '1', 'stamp_type' => 'digital', 'damaged_units' => '10', 'over_units' => '5',
            'unstamped_units' => '20', 'unstamped_action' => 'quarantine']]);
        $this->post($desk, $d->id);
        $r = $this->register()->rows(DutyRegister::filters([]), 10)[0];
        self::assertSame([70, 35, 0], [$r['accepted_units'], $r['quarantine_units'], $r['verify_units']], 'what the receipt booked');
        self::assertSame([70, 35], [$r['stamped_units'], $r['unstamped_total']], 'stamped: what went in as stamped stock; unstamped: all that was held back');
        self::assertSame('unstamped', $r['stamp_state']);
    }

    public function testUnstampedFiltersIncludeReceiptsWhoseOnlyGoodsAreDamagedOrOver(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Only Extras Ltd');
        $sku = self::makeSku('Only damaged and over liquid');
        $this->liquid($sku);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 10, 'units_per_pack' => 1, 'pack_price' => '2.00']]);
        $this->invoice($desk, $d->id);
        $this->bench($this->staffUser('goods_in'), $d->id,
            [1 => ['stamp_on_pack' => '0', 'damaged_units' => '10', 'over_units' => '5']]);
        $posted = $this->post($desk, $d->id);
        $reg = $this->register();
        $filter = DutyRegister::filters(['stamp' => 'unstamped']);
        self::assertSame(1, $reg->count($filter));
        $rows = $reg->rows($filter, 100);
        self::assertCount(1, $rows, 'the page and filtered exports use these same rows');
        $r = $rows[0];
        self::assertSame([$posted->id, 'unstamped', 0, 15, 0, 15],
            [$r['document_id'], $r['stamp_state'], $r['unstamped_units'], $r['unstamped_total'], $r['stamped_units'], $r['quarantine_units']]);
        self::assertSame(0, $reg->count(DutyRegister::filters(['stamp' => 'not_checked'])));
    }

    public function testDeclarationStampTotalsIncludeUnstampedExtrasInVerify(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Declared Extras Ltd');
        $sku = self::makeSku('Declared extras liquid');
        $this->liquid($sku);
        $line = ['sku_id' => $sku, 'packs' => 100, 'units_per_pack' => 1, 'pack_price' => '2.00'];
        $d = $this->receipt($desk, (int) $s['id'], [$line, $line]);
        $this->invoice($desk, $d->id);
        $declared = ['damaged_units' => '10', 'over_units' => '5', 'unstamped_units' => '90', 'unstamped_action' => 'accept_pre_october',
            'pre_october_evidence' => 'Supplier letter confirms manufacture before October 2026'];
        $this->bench($this->staffUser('goods_in'), $d->id,
            [1 => ['stamp_on_pack' => '0'] + $declared, 2 => ['stamp_on_pack' => '1'] + $declared]);
        $this->post($desk, $d->id);
        $rows = $this->register()->rows(DutyRegister::filters(['stamp' => 'declaration']), 100);
        self::assertSame([['declaration', 90, 15, 105, 0], ['declaration', 90, 15, 90, 15]],
            array_map(static fn (array $r): array => [$r['stamp_state'], $r['accepted_units'], $r['verify_units'], $r['unstamped_total'], $r['stamped_units']], $rows),
            'stock destination does not change the physical stamp state of the damaged and over units');
    }

    /** Review finding 6 (Codex, 14 Oct): the total taken in on a declaration counts every line, not only the items listed. */
    public function testTheDeclarationTotalCountsEveryLineNotOnlyTheListedItems(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Many Declared Supplies');
        $lines = [];
        $bench = [];
        for ($i = 1; $i <= DutyRegister::UNSTAMPED_LIMIT + 1; $i++) {
            $lines[] = ['sku_id' => self::makeSku('Declared ' . $i), 'packs' => 1, 'units_per_pack' => 1, 'pack_price' => '1.00'];
            $bench[$i] = ['stamp_on_pack' => '0', 'unstamped_units' => '1', 'unstamped_action' => 'accept_pre_october',
                'pre_october_evidence' => 'Supplier letter: made before 1 Oct 2026'];
        }
        $d = $this->receipt($desk, (int) $s['id'], $lines);
        $this->invoice($desk, $d->id);
        $this->bench($this->staffUser('goods_in'), $d->id, $bench);
        $this->post($desk, $d->id);
        $u = $this->register()->unstamped();
        self::assertCount(DutyRegister::UNSTAMPED_LIMIT, $u['accepted'], 'the list stops at its limit');
        self::assertSame(DutyRegister::UNSTAMPED_LIMIT + 1, $u['accepted_units'], 'the total does not');
    }

    public function testTheUnstampedStockAndWhereItIsWithTheAlarm(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Unstamped Supplies');
        $liquid = self::makeSku('Unstamped liquid');
        $this->liquid($liquid);
        $declared = self::makeSku('Declared liquid');
        $this->liquid($declared);
        $bench = $this->staffUser('goods_in');
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $declared, 'packs' => 1, 'units_per_pack' => 5]]);
        $this->invoice($desk, $d->id);
        $this->bench($bench, $d->id, [1 => ['stamp_on_pack' => '0', 'unstamped_units' => '5', 'unstamped_action' => 'accept_pre_october',
            'pre_october_evidence' => 'Manufacturer certificate 2026-09-20']]);
        $this->post($desk, $d->id);
        $this->setting('receiving.unstamped_refusal_from', json_encode(self::day('-1 day'), JSON_THROW_ON_ERROR));
        $q = $this->receipt($desk, (int) $s['id'], [['sku_id' => $liquid, 'packs' => 2, 'units_per_pack' => 4]]);
        $this->invoice($desk, $q->id);
        $this->bench($bench, $q->id, [1 => ['stamp_on_pack' => '0', 'unstamped_units' => '8', 'unstamped_action' => 'quarantine']]);
        $this->post($desk, $q->id);

        $u = $this->register()->unstamped();
        self::assertSame([[['code' => 'UNSTAMPED', 'name' => (string) self::$db->value("SELECT name FROM warehouse WHERE code = 'UNSTAMPED'")]], 8, false, '2027-04-01'],
            [$u['warehouses'], $u['held_units'], $u['alarm'], $u['from']]);
        self::assertSame([[$liquid, 8, 'UNSTAMPED']], array_map(static fn (array $h): array => [$h['sku_id'], $h['on_hand'], $h['warehouse_code']], $u['held']));
        self::assertSame([[$declared, 5, 1, 5]], array_map(static fn (array $a): array => [$a['sku_id'], $a['units'], $a['receipts'], $a['on_hand']], $u['accepted']));
        self::assertSame(5, $u['accepted_units']);
        // From the "must be zero" day, anything held unstamped is an alarm.
        $this->setting('duty.unstamped_zero_from', json_encode(self::day('-1 day'), JSON_THROW_ON_ERROR));
        self::assertTrue($this->register()->unstamped()['alarm']);
        // No place named: nothing listed, no alarm.
        $this->setting('duty.unstamped_warehouses', '""');
        $none = $this->register()->unstamped();
        self::assertSame([[], [], 0, false], [$none['warehouses'], $none['held'], $none['held_units'], $none['alarm']]);
    }
}
