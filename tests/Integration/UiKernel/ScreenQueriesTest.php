<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Queries;

/**
 * The read queries rewritten for speed (branch perf, 8 Oct 2026) give exactly what the one-query forms gave: the lists' count and
 * their page of 50 (ties in the units broken by the listing id, filters, the waiting second OK, the listings no queue lists), and a
 * product's listings now and in the past. The old SQL is kept here as the reference.
 */
final class ScreenQueriesTest extends KernelUiTestCase
{
    /** The queue page as one query (before the rewrite). @return array{rows: list<array<string, mixed>>, total: int} */
    private static function oldQueue(string $band, ?int $channelId, string $q, int $min30, int $page): array
    {
        $where = ["p.status = 'open'", 'p.band = ?', "cl.status IN ('unmapped', 'suggested')", 'NOT EXISTS (SELECT 1 FROM match_decision pd WHERE pd.pending_listing_id = cl.id)'];
        $params = [$band];
        if ($channelId !== null) {
            $where[] = 'cl.channel_id = ?';
            $params[] = $channelId;
        }
        if ($min30 > 0) {
            $where[] = 'COALESCE(lp.units_30d, 0) >= ?';
            $params[] = $min30;
        }
        if ($q !== '') {
            $like = '%' . Queries::escapeLike($q) . '%';
            $where[] = "(lp.product_title LIKE ? OR lp.variant_title LIKE ? OR lp.brand LIKE ? OR cl.external_variant_id = ? OR JSON_SEARCH(lp.barcodes, 'one', ?) IS NOT NULL)";
            array_push($params, $like, $like, $like, $q, Queries::escapeLike($q));
        }
        $from = 'FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id JOIN channel ch ON ch.id = cl.channel_id '
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id LEFT JOIN sku s ON s.id = p.proposed_sku_id WHERE ' . implode(' AND ', $where);
        $total = (int) self::$db->value('SELECT COUNT(*) ' . $from, $params);
        $rows = self::$db->all(
            'SELECT p.id AS proposal_id, p.listing_id, p.band, p.lane, p.ai_outcome, p.ai_confidence, p.ai_units_per_item, p.proposed_sku_id, '
            . 'p.proposed_new_item, p.flags, cl.channel_id, ch.code AS channel_code, cl.external_variant_id, cl.status, '
            . 'lp.product_title, lp.variant_title, lp.brand, lp.units_30d, lp.units_365d, s.code AS sku_code, s.name AS sku_name '
            . $from . ' ORDER BY COALESCE(lp.units_365d, 0) DESC, COALESCE(lp.units_30d, 0) DESC, cl.id ASC LIMIT ? OFFSET ?',
            [...$params, Queries::PER_PAGE, ($page - 1) * Queries::PER_PAGE],
        );
        return ['rows' => $rows, 'total' => $total];
    }

    public function testTheListsCountAndPagesAreTheOneQueryResult(): void
    {
        $vpg = $this->site('vpg');
        $alt = $this->site('alt');
        $sku = self::makeSku('Elf Bar Blue Razz 20mg');
        // 120 Key listings over two sites, many with the same units (ties), some without a profile's units, and filters to match.
        for ($i = 1; $i <= 120; $i++) {
            $site = $i % 3 === 0 ? $alt : $vpg;
            $this->queued($site, 'k' . $i, 'Key', $sku, [
                'product_title' => $i % 4 === 0 ? 'Elf Bar ' . $i : 'Other kit ' . $i, 'variant_title' => $i % 5 === 0 ? 'Blue Razz' : null,
                'brand' => $i % 2 === 0 ? 'ELFBAR' : 'Other', 'units_30d' => $i % 7, 'units_365d' => intdiv($i, 10) * 5,
                'barcodes' => $i === 33 ? ['5056168812345'] : [],
            ]);
        }
        $this->queued($vpg, 'c1', 'Check', $sku, ['units_365d' => 99]); // another band
        // A listing waiting for a second OK leaves the lists.
        $waiting = $this->queued($vpg, 'w1', 'Key', $sku, ['units_365d' => 1000]);
        $lead = $this->staffUser('mapping_lead');
        self::$db->exec("INSERT INTO match_decision (listing_id, action, sku_id, units_per_item, decided_by, actor, state, expected_map_version) "
            . "VALUES (?, 'link', ?, 1, ?, ?, 'pending_second', 0)", [$waiting, $sku, $lead->staffUserId, $lead->actor]);
        $q = new Queries(self::$db);
        $cases = [['Key', null, '', 0], ['Key', $vpg->channelId, '', 0], ['Key', null, 'elf', 0], ['Key', null, '', 3], ['Key', $alt->channelId, 'razz', 2],
            ['Key', null, '5056168812345', 0], ['Key', null, 'k7', 0], ['Check', null, '', 0], ['Conflict', null, '', 0]];
        foreach ($cases as [$band, $channel, $text, $min]) {
            for ($page = 1; $page <= 3; $page++) {
                $old = self::oldQueue($band, $channel, $text, $min, $page);
                $new = $q->queue($band, $channel, $text, null, $min, $page);
                $label = json_encode([$band, $channel, $text, $min, $page]);
                self::assertSame($old['total'], $new['total'], $label);
                if ($page <= $new['pages']) {
                    self::assertSame($old['rows'], $new['rows'], $label);
                }
            }
        }
        self::assertSame(120, $q->queue('Key', null, '', null, 0, 1)['total'], 'the waiting listing is not listed');
    }

    public function testAProductsListingsAreTheLinkedAndTheFormerlyLinkedOnes(): void
    {
        $vpg = $this->site('vpg');
        $alt = $this->site('alt');
        $sku = self::makeSku('Kit A');
        $other = self::makeSku('Kit B');
        $now = [];
        for ($i = 1; $i <= 4; $i++) {
            $now[] = $this->profiled($i % 2 === 0 ? $alt : $vpg, 'a' . $i, ['units_30d' => $i], $sku);
        }
        $former = $this->profiled($vpg, 'b1', [], $other);
        $never = $this->profiled($vpg, 'n1', [], null);
        $lead = $this->staffUser('mapping_lead');
        $d = self::$db->insert("INSERT INTO match_decision (listing_id, action, sku_id, units_per_item, decided_by, actor, state, expected_map_version, applied_at) "
            . "VALUES (?, 'link', ?, 1, ?, ?, 'applied', 0, NOW(6))", [$former, $sku, $lead->staffUserId, $lead->actor]);
        self::$db->exec('INSERT INTO listing_map_history (listing_id, sku_id, units_per_item, valid_from, valid_to, decision_id) VALUES (?, ?, 1, NOW(6) - INTERVAL 2 DAY, NOW(6) - INTERVAL 1 DAY, ?)',
            [$former, $sku, $d]);
        $old = self::$db->all(
            'SELECT cl.id, cl.channel_id, ch.code AS channel_code, cl.external_variant_id, cl.sku_id, cl.units_per_item, cl.status, cl.map_version, '
            . 'lp.product_title, lp.variant_title, lp.units_30d, lp.units_365d FROM channel_listing cl JOIN channel ch ON ch.id = cl.channel_id '
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id '
            . 'WHERE cl.sku_id = ? OR cl.id IN (SELECT h.listing_id FROM listing_map_history h WHERE h.sku_id = ?) '
            . 'ORDER BY (cl.sku_id = ?) DESC, ch.code, cl.id LIMIT ?',
            [$sku, $sku, $sku, 200],
        );
        $new = (new Queries(self::$db))->listingsOfSku($sku);
        self::assertSame($old, $new);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $new);
        self::assertEqualsCanonicalizing([...$now, $former], $ids, 'linked now and linked before, nothing else');
        self::assertSame($former, $ids[4], 'the former link last');
        self::assertNotContains($never, $ids);
        self::assertSame(2, count((new Queries(self::$db))->listingsOfSku($sku, 2)), 'the limit');
    }
}
