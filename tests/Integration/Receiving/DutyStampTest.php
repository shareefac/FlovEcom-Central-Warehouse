<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Receiving;

/**
 * The duty stamp at goods-in (IM6; owner decision 8; I134, I135): duty-liable liquid must arrive stamped. Before
 * receiving.unstamped_refusal_from (1 Jan 2027; the delivery's received date, UK) unstamped stock is accepted only with the
 * supplier's evidence that it was made or imported before 1 Oct 2026; from that date it is refused at the door or quarantined in
 * UNSTAMPED, with an incident either way. Dry products need no stamp; an item no card answers is treated as duty-liable. The
 * expected duty (ml x 22p, rounded down per unit) is shown for information.
 */
final class DutyStampTest extends ReceivingTestCase
{
    public function testBeforeTheRefusalDateUnstampedStockIsAcceptedOnlyWithThePreOctoberEvidence(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Pre-October liquid');
        $this->liquid($sku);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 2, 'units_per_pack' => 10, 'pack_price' => '30.00']]);
        $this->invoice($desk, $d->id);
        $bench = $this->staffUser('goods_in');
        $this->bench($bench, $d->id, [1 => ['stamp_on_pack' => '0', 'unstamped_units' => '20', 'unstamped_action' => 'accept_pre_october',
            'pre_october_evidence' => 'Supplier letter 12 Sep: batch L0912 made 2 Sep 2026']]);
        $plan = $this->grns->plan($d->id);
        self::assertSame([], $plan['problems']);
        self::assertSame(['accepted' => 20, 'quarantine' => 0, 'refused' => 0], array_intersect_key($plan['lines'][1]['split'], array_flip(['accepted', 'quarantine', 'refused'])));
        self::assertStringContainsString('20 unstamped units accepted on the supplier\'s evidence of manufacture before 1 Oct 2026: they must be sold, returned or '
            . 'destroyed by 31 Mar 2027', implode(' ', $plan['warnings']));
        $this->post($desk, $d->id);
        self::assertSame(20, $this->onHand($sku));
        self::assertSame([], $this->incidents($d->id), 'accepted with evidence: recorded on the line, no incident');
        self::assertSame(['accept_pre_october', 'Supplier letter 12 Sep: batch L0912 made 2 Sep 2026', 20], array_values((array) self::$db->one(
            'SELECT unstamped_action, pre_october_evidence, unstamped_units FROM grn_line WHERE document_id = ?', [$d->id])));
    }

    public function testFromTheRefusalDateUnstampedStockIsRefusedOrQuarantined(): void
    {
        $this->setting('receiving.unstamped_refusal_from', json_encode(self::day('-1 day'), JSON_THROW_ON_ERROR));
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('After cutoff liquid');
        $this->liquid($sku);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 3, 'units_per_pack' => 10]]);
        $this->invoice($desk, $d->id);
        $bench = $this->staffUser('goods_in');
        $this->bench($bench, $d->id, [1 => ['unstamped_units' => '10', 'unstamped_action' => 'accept_pre_october', 'pre_october_evidence' => 'supplier says made in August']]);
        $e = $this->refusedCode('unstamped_refused_now', fn () => $this->post($desk, $d->id));
        self::assertStringContainsString('an unstamped duty-liable delivery is refused at the door or quarantined: choose refuse or quarantine', $e->getMessage());
        // Ten refused at the door, five quarantined, fifteen stamped.
        $this->bench($bench, $d->id, [1 => ['unstamped_units' => '10', 'unstamped_action' => 'refuse']]);
        $p = $this->post($desk, $d->id);
        self::assertSame('posted', $p->status);
        self::assertSame([20, 0], [$this->onHand($sku), $this->onHand($sku, 'UNSTAMPED')]);
        self::assertSame([['kind' => 'unstamped', 'disposition' => 'refused', 'units' => 10, 'status' => 'open']], array_map(static fn (array $r): array =>
            ['kind' => $r['kind'], 'disposition' => $r['disposition'], 'units' => (int) $r['units'], 'status' => $r['status']], $this->incidents($d->id)));
        $d2 = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1, 'units_per_pack' => 10]]);
        $this->invoice($desk, $d2->id);
        $this->bench($bench, $d2->id, [1 => ['stamp_on_pack' => '0', 'unstamped_units' => '10', 'unstamped_action' => 'quarantine']]);
        $this->post($desk, $d2->id);
        self::assertSame([20, 10], [$this->onHand($sku), $this->onHand($sku, 'UNSTAMPED')]);
        self::assertSame('quarantine', self::$db->value('SELECT disposition FROM incident WHERE document_id = ?', [$d2->id]));
    }

    /** The refusal date is compared with the day the goods ARRIVED (UK), not the day they are keyed: a paper sheet keeps its date. */
    public function testTheRefusalDateIsTheReceivedDate(): void
    {
        $this->setting('receiving.unstamped_refusal_from', json_encode(self::day('-1 day'), JSON_THROW_ON_ERROR));
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Received before cutoff');
        $this->liquid($sku);
        $twoDays = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->modify('-2 days')->format('Y-m-d') . ' 11:00';
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 4]], ['received_at' => $twoDays, 'paper_sheet' => '1',
            'backdate_reason' => 'paper sheet from the outage']);
        $this->invoice($desk, $d->id);
        $this->bench($this->staffUser('goods_in'), $d->id, [1 => ['stamp_on_pack' => '0', 'unstamped_units' => '4', 'unstamped_action' => 'accept_pre_october',
            'pre_october_evidence' => 'manufacturer certificate 2026-09-20']]);
        self::assertFalse($this->grns->plan($d->id)['header']['refusal_regime']);
        self::assertSame('posted', $this->post($desk, $d->id)->status);
        self::assertSame(4, $this->onHand($sku));
    }

    public function testTheStampCheckIsCompulsoryForDutyLiableLiquid(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $liquid = self::makeSku('Stamp check liquid');
        $this->liquid($liquid);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $liquid, 'packs' => 1, 'units_per_pack' => 6]]);
        $this->invoice($desk, $d->id);
        $bench = $this->staffUser('goods_in');
        $v = $this->docs->get($d->id)->version;
        $this->grns->bench($bench, $d->id, $v, ['paperwork_ok' => '1'], []);
        $this->refusedCode('line_not_checked', fn () => $this->post($desk, $d->id), 'the line is not counted yet');
        $this->grns->bench($bench, $d->id, $v + 1, [], [1 => []]);
        $this->refusedCode('stamp_check_required', fn () => $this->post($desk, $d->id), 'counted without the stamp answer');
        $this->grns->bench($bench, $d->id, $v + 2, [], [1 => ['stamp_on_pack' => '1']]);
        $this->refusedCode('stamp_type_required', fn () => $this->post($desk, $d->id));
        // "No stamp on the pack" with only some units counted unstamped: refused at the bench already.
        $e = $this->refusedCode('bad_bench', fn () => $this->grns->bench($bench, $d->id, $v + 3, [], [1 => ['stamp_on_pack' => '0', 'unstamped_units' => '2',
            'unstamped_action' => 'quarantine']]));
        self::assertStringContainsString('"no stamp on the pack" means all 6 units that arrived are unstamped', $e->getMessage());
        // Everything short: nothing arrived to check.
        $this->grns->bench($bench, $d->id, $v + 3, [], [1 => ['short_units' => '6']]);
        self::assertSame([], $this->grns->plan($d->id)['problems']);
        $this->grns->bench($bench, $d->id, $v + 4, [], [1 => ['stamp_on_pack' => '1', 'stamp_type' => 'transitional', 'stamp_code' => 'UKVPD-0001-ABCD']]);
        $this->post($desk, $d->id);
        self::assertSame(['transitional', 'UKVPD-0001-ABCD', 1], array_values((array) self::$db->one('SELECT stamp_type, stamp_code, stamp_required FROM grn_line WHERE document_id = ?',
            [$d->id])));
    }

    public function testDryProductsNeedNoStampAndAnItemWithoutACardIsTreatedAsDutyLiable(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $coil = self::makeSku('Coil 0.4 ohm');
        $this->dry($coil);
        $unknown = self::makeSku('No card yet');
        $tankNoAnswer = self::makeSku('Tank with no duty answer');
        $this->card($tankNoAnswer, ['product_type' => 'tank', 'liquid_ml' => '2']);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $coil, 'packs' => 5], ['sku_id' => $unknown, 'packs' => 2], ['sku_id' => $tankNoAnswer, 'packs' => 1]]);
        $plan = $this->grns->plan($d->id);
        self::assertSame([false, true, false], array_map(static fn (array $l): bool => $l['stamp_required'], array_values($plan['lines'])), 'I119');
        self::assertStringContainsString('no item card says whether it is duty-liable, so it is treated as duty-liable', implode(' ', $plan['warnings']));
        self::assertSame(['invoice_file_required', 'bench_check_required'], array_column($plan['problems'], 'code'), 'one "waiting for the bench" problem');
        $this->invoice($desk, $d->id);
        $bench = $this->staffUser('goods_in');
        $v = $this->docs->get($d->id)->version;
        $this->grns->bench($bench, $d->id, $v, ['paperwork_ok' => '1'], [1 => ['unstamped_units' => '1', 'unstamped_action' => 'refuse']]);
        $e = $this->refusedCode('unstamped_not_duty', fn () => $this->post($desk, $d->id));
        self::assertStringContainsString('needs no duty stamp (its item card: coil)', $e->getMessage());
        $this->grns->bench($bench, $d->id, $v + 1, [], [1 => ['unstamped_units' => '0'], 2 => ['stamp_on_pack' => '1', 'stamp_type' => 'digital'], 3 => []]);
        $this->post($desk, $d->id);
        self::assertSame([5, 2, 1], [$this->onHand($coil), $this->onHand($unknown), $this->onHand($tankNoAnswer)]);
    }

    /**
     * An unstamped delivery's damaged and over units are unstamped too (I167; review 7 Oct, probe C): refused with its unstamped units
     * (not held in VERIFY as ordinary stock), or quarantined with them in UNSTAMPED, each with its incident; wrong items stay in VERIFY.
     */
    public function testAnUnstampedDeliverysDamagedAndOverUnitsAreUnstampedToo(): void
    {
        $this->setting('receiving.unstamped_refusal_from', json_encode(self::day('-1 day'), JSON_THROW_ON_ERROR));
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Unstamped delivery liquid');
        $this->liquid($sku);
        $bench = $this->staffUser('goods_in');
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1, 'units_per_pack' => 10]]);
        $this->invoice($desk, $d->id);
        $this->bench($bench, $d->id, [1 => ['stamp_on_pack' => '0', 'unstamped_units' => '8', 'unstamped_action' => 'refuse', 'damaged_units' => '2', 'over_units' => '5']]);
        self::assertSame([], $this->grns->plan($d->id)['problems']);
        $this->post($desk, $d->id);
        self::assertSame([0, 0, 0], [$this->onHand($sku), $this->onHand($sku, 'VERIFY'), $this->onHand($sku, 'UNSTAMPED')], 'nothing of a refused unstamped delivery is held');
        self::assertSame([['over', 'refused', 5], ['damaged', 'refused', 2], ['unstamped', 'refused', 8]], array_map(static fn (array $r): array => [$r['kind'], $r['disposition'],
            (int) $r['units']], $this->incidents($d->id)));
        self::assertSame([0, 0, 0, 15], array_map('intval', array_values((array) self::$db->one('SELECT accepted_units, verify_units, quarantine_units, refused_units FROM grn_line '
            . 'WHERE document_id = ?', [$d->id]))));
        // Quarantined instead: all of it in UNSTAMPED (the "unstamped must be empty" alert sees it); wrong items in VERIFY.
        $d2 = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1, 'units_per_pack' => 10]]);
        $this->invoice($desk, $d2->id);
        $this->bench($bench, $d2->id, [1 => ['stamp_on_pack' => '0', 'unstamped_units' => '7', 'unstamped_action' => 'quarantine', 'damaged_units' => '2', 'over_units' => '5',
            'wrong_item_units' => '1']]);
        $this->post($desk, $d2->id);
        self::assertSame([0, 1, 14], [$this->onHand($sku), $this->onHand($sku, 'VERIFY'), $this->onHand($sku, 'UNSTAMPED')]);
        self::assertSame([['over', 'quarantine', 5], ['damaged', 'quarantine', 2], ['wrong_item', 'verify', 1], ['unstamped', 'quarantine', 7]], array_map(static fn (array $r): array =>
            [$r['kind'], $r['disposition'], (int) $r['units']], $this->incidents($d2->id)));
        self::assertSame([], \CW\Receiving\ReceivingInvariants::check(self::$db));
    }

    /** The expected duty, for information: ml x 22p rounded down per unit, times the units; none for a kit's tank or a dry product. */
    public function testTheExpectedDuty(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $ten = self::makeSku('10ml liquid');
        $this->liquid($ten);
        $pod = self::makeSku('Prefilled pod 1.9ml');
        $this->card($pod, ['product_type' => 'prefilled pod', 'liquid_ml' => '1.9', 'nicotine_mg' => '20', 'duty_liable' => 'yes']);
        $kit = self::makeSku('Kit with a 2ml tank');
        $this->card($kit, ['product_type' => 'device / kit', 'liquid_ml' => '2', 'duty_liable' => 'no', 'single_use' => 'no']);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $ten, 'packs' => 12], ['sku_id' => $pod, 'packs' => 3, 'units_per_pack' => 2], ['sku_id' => $kit, 'packs' => 1]]);
        $plan = $this->grns->plan($d->id);
        // 10 ml: 220p x 12 = £26.40; 1.9 ml: floor(41.8) = 41p x 6 = £2.46; the kit: none.
        self::assertSame([26_40, 2_46, null], array_map(static fn (array $l): ?int => $l['expected_duty_pence'], array_values($plan['lines'])));
        self::assertSame(28_86, $plan['totals']['expected_duty_pence']);
        $this->ready($desk, $d->id);
        $this->post($desk, $d->id);
        self::assertSame([['10.0', '26.40'], ['1.9', '2.46'], [null, null]], array_map(static fn (array $r): array => [$r['duty_ml'], $r['expected_duty']],
            self::$db->all('SELECT duty_ml, expected_duty FROM grn_line WHERE document_id = ? ORDER BY line_no', [$d->id])));
    }
}
