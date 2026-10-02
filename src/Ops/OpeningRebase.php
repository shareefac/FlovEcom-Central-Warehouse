<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Clock;
use CW\CwException;
use CW\Db;

/**
 * The rebase of a site's opening estimate at its T0 (plan §8.1; D40a "Rule at a site's T0"; D40b).
 *
 * The estimate (OpeningEstimate, D40a) booked max(site stock as of an earlier moment, 0) x u per item, and
 * /v1/opening_orders then added the site's open paid units to `allocated` only. At T0 each item is brought to
 *
 *     target = max(site stock read in the T0 snapshot, 0) x u, summed over the item's `mapped` listings of
 *              this channel
 *            + the units_per_item of every unit of this channel's origin='opening' reservations that the
 *              opening committed: in a state such a unit can reach (allocated, shipped, cancelled, returned)
 *              AND with a `commit` (or `adopt`) row on its allocated bucket. A `released` unit was held and
 *              never paid; a unit cancelled while held and left out of the opening body was never paid
 *              either (an uncancel of it after T0 is a sale after T0, not part of the opening)
 *
 * by one signed `adjustment` of (target - the sum of the item's rows under the estimate's doc_ref), under a
 * NEW doc_ref, actor system:opening_estimate (so a later estimate run sees it as an earlier opening), at the
 * channel's sellable warehouse. After it, available = the site's figure at T0 (plus any restockable cancel
 * since), and once every opening unit has shipped, on_hand = max(site stock at T0, 0) x u.
 *
 * Refused (nothing booked): the channel has no accepted final opening_orders batch; the doc_ref is the
 * estimate's; an item already rebased under this doc_ref with another figure (`rebase_conflict`: a different
 * file, or links changed since; a deliberate second rebase uses another doc_ref).
 *
 * Skipped and reported, with the delta the rebase would have booked, so a person can decide:
 *   counted              the item was counted (sku/balance counted_at, or a `count` row): the count replaced
 *                        the figure
 *   other_opening        an opening row under another doc_ref, or at another warehouse
 *   moved                any other on_hand row than the opening's own: the estimate's rows and the rows of
 *                        this channel's opening units (ship, unship, return, the VERIFY moves of a cancel or
 *                        uncancel). A goods-in, adjustment, write-off, a ship of a later order, ...
 *   quarantined_listing  a quarantined listing of the item has stock > 0 at T0 (or no figure): its link is
 *                        in doubt, so the item's target is not known
 *   no_t0_figure         a mapped listing of the item is not in the file
 *   no_mapped_listing    the item has earlier estimate rows or opening units but no mapped or quarantined
 *                        listing on this channel now (e.g. relinked or unmapped since the estimate): its
 *                        site figure is unknown, and the rebase would silently write it down to the units term
 *   units_elsewhere      an opening unit of the item sits at another warehouse
 * An item already rebased under this doc_ref is judged on its figure only (a later ship or count does not
 * turn it into a skip). Re-runs and resumes book only what is missing (Idempotency-Key "<doc_ref>:<sku_id>").
 *
 * Nothing here locks: the plan is read, then OpeningEstimate::apply() books item by item through Movements
 * (one transaction per item). The rebase therefore runs straight after the final opening batch, before
 * staff book anything on the site's items (D40a).
 */
final class OpeningRebase
{
    public const DOC_TYPE = 'opening_rebase';
    /** Unit states that count towards the target (every state a unit the opening committed can reach; see COMMITTED_BY). */
    public const UNIT_STATES = ['allocated', 'shipped', 'cancelled', 'returned'];
    public const SKIP_REASONS = ['counted', 'other_opening', 'moved', 'quarantined_listing', 'no_t0_figure', 'no_mapped_listing', 'units_elsewhere'];
    /** A unit counts towards the target only if the opening's commit allocated it (or adopted it allocated): one of these rows. */
    public const COMMITTED_BY = ['commit', 'adopt'];
    /** Skipped items listed one by one in the report (all are counted). */
    public const DETAIL_ITEMS = 500;
    private const CHUNK = 2000;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The channel's opening as recorded: refuses unless its final opening_orders batch was accepted.
     *
     * @return array{id: int, code: string, t0_at: string, opening_orders_at: string, warehouse_id: ?int, warehouse: ?string}
     *         (times as DB values)
     */
    public function opening(string $channelCode): array
    {
        $ch = $this->db->one(
            'SELECT c.id, c.code, co.t0_at, co.opening_orders_at, w.id AS warehouse_id, w.code AS warehouse FROM channel c '
            . 'LEFT JOIN channel_opening co ON co.channel_id = c.id '
            . 'LEFT JOIN channel_warehouse cw ON cw.channel_id = c.id AND cw.is_sellable = 1 '
            . 'LEFT JOIN warehouse w ON w.id = cw.warehouse_id WHERE c.code = ?',
            [$channelCode],
        );
        if ($ch === null) {
            throw new CwException('unknown_channel', "no channel {$channelCode}", 404);
        }
        if ($ch['opening_orders_at'] === null || $ch['t0_at'] === null) {
            throw new CwException('opening_not_final', "channel {$channelCode} has no accepted final opening_orders batch yet: "
                . 'the rebase runs after it (D40a, D40b)', 409);
        }
        return ['id' => (int) $ch['id'], 'code' => (string) $ch['code'], 't0_at' => (string) $ch['t0_at'],
            'opening_orders_at' => (string) $ch['opening_orders_at'],
            'warehouse_id' => $ch['warehouse_id'] === null ? null : (int) $ch['warehouse_id'],
            'warehouse' => $ch['warehouse'] === null ? null : (string) $ch['warehouse']];
    }

    /** The doc_ref used unless the operator names one: opening-rebase:<channel>:<T0 as YYYYMMDDTHHMMSSZ>. */
    public static function defaultDocRef(string $channelCode, string $t0Db): string
    {
        return 'opening-rebase:' . $channelCode . ':' . Clock::fromDb($t0Db)->format('Ymd\THis\Z');
    }

    /**
     * @param iterable<array{0: string, 1: int}> $rows   (site variant id, site stock in the T0 snapshot)
     * @param ?string $estimateDocRef  the doc_ref of this site's estimate, compared byte for byte (null: none was booked)
     * @param ?string $warehouse       warehouse code; default the channel's sellable warehouse (where its opening units are)
     * @return array{lines: array<int, int>, report: array<string, mixed>, opening: array<string, mixed>}
     *         lines: sku_id => signed central units still to book
     */
    public function plan(string $channelCode, iterable $rows, string $docRef, ?string $estimateDocRef, ?string $warehouse = null): array
    {
        $op = $this->opening($channelCode);
        if ($estimateDocRef !== null && strcasecmp($docRef, $estimateDocRef) === 0) {
            throw new CwException('bad_doc_ref', 'the rebase needs a new doc_ref, not the estimate\'s (in any letter case)', 422);
        }
        if ($warehouse === null) {
            if ($op['warehouse_id'] === null) {
                throw new CwException('no_sellable_warehouse', "channel {$channelCode} has no sellable warehouse", 409);
            }
            [$whId, $whCode] = [$op['warehouse_id'], (string) $op['warehouse']];
        } else {
            $id = $this->db->value('SELECT id FROM warehouse WHERE code = ?', [$warehouse]);
            if ($id === null) {
                throw new CwException('unknown_warehouse', "no warehouse {$warehouse}", 404);
            }
            [$whId, $whCode] = [(int) $id, $warehouse];
        }
        $channelId = $op['id'];
        $actor = 'system:' . OpeningEstimate::ACTOR_JOB;

        // ---- the file ----------------------------------------------------------------------------
        $site = [];
        $report = ['channel' => $op['code'], 'warehouse' => $whCode, 't0' => Clock::iso($op['t0_at']),
            'opening_orders_at' => Clock::iso($op['opening_orders_at']), 'doc_ref' => $docRef, 'estimate_doc_ref' => $estimateDocRef,
            'rows' => 0, 'positive_rows' => 0, 'positive_units' => 0, 'zero_or_negative_rows' => 0,
            'no_listing' => ['rows' => 0, 'units' => 0], 'not_linked_by_status' => []];
        foreach ($rows as [$variant, $qty]) {
            $report['rows']++;
            if (isset($site[$variant])) {
                throw new CwException('duplicate_variant', "variant {$variant} appears twice in the input", 422);
            }
            $site[$variant] = $qty;
            if ($qty > 0) {
                $report['positive_rows']++;
                $report['positive_units'] += $qty;
            } else {
                $report['zero_or_negative_rows']++;
            }
        }
        if ($report['rows'] === 0) {
            throw new CwException('empty_input', 'the input has no data rows', 422);
        }

        // ---- site term: the channel's linked listings ----------------------------------------------
        $siteTerm = [];
        $skip = [];
        $listed = [];
        $report['listings'] = ['mapped' => 0, 'mapped_missing_from_file' => 0, 'quarantined' => 0];
        foreach ($this->db->all('SELECT external_variant_id v, sku_id, units_per_item u, status FROM channel_listing WHERE channel_id = ?', [$channelId]) as $l) {
            $v = (string) $l['v'];
            $listed[$v] = true;
            $qty = $site[$v] ?? null;
            $linked = $l['sku_id'] !== null && in_array($l['status'], ['mapped', 'quarantined'], true);
            if (!$linked) {
                if ($qty !== null && $qty > 0) {
                    $s = (string) $l['status'];
                    $report['not_linked_by_status'][$s] ??= ['rows' => 0, 'units' => 0];
                    $report['not_linked_by_status'][$s]['rows']++;
                    $report['not_linked_by_status'][$s]['units'] += $qty;
                }
                continue;
            }
            $sku = (int) $l['sku_id'];
            $siteTerm[$sku] ??= 0;
            if ($l['status'] === 'quarantined') {
                $report['listings']['quarantined']++;
                if ($qty === null || $qty > 0) {
                    $skip[$sku]['quarantined_listing'] = true;
                }
                continue;
            }
            $report['listings']['mapped']++;
            if ($qty === null) {
                $report['listings']['mapped_missing_from_file']++;
                $skip[$sku]['no_t0_figure'] = true;
                continue;
            }
            $siteTerm[$sku] += max($qty, 0) * (int) $l['u'];
        }
        foreach ($site as $v => $qty) {
            if ($qty > 0 && !isset($listed[(string) $v])) {
                $report['no_listing']['rows']++;
                $report['no_listing']['units'] += $qty;
            }
        }

        // ---- units term: this channel's opening units ------------------------------------------------
        $unitsTerm = [];
        $report['opening_units'] = ['units' => 0, 'central_units' => 0, 'unlinked' => 0, 'released' => 0, 'never_committed' => 0];
        $states = implode(',', array_fill(0, count(self::UNIT_STATES), '?'));
        $by = implode(',', array_fill(0, count(self::COMMITTED_BY), '?'));
        foreach ($this->db->all(
            "SELECT ru.sku_id, ru.warehouse_id, ru.state IN ({$states}) AS in_state, "
            . 'EXISTS (SELECT 1 FROM stock_ledger l WHERE l.channel_id = r.channel_id AND l.order_ref = r.order_ref AND l.unit_id = ru.unit_id '
            . "AND l.bucket = 'allocated' AND l.qty_delta > 0 AND l.movement_type IN ({$by})) AS committed, COUNT(*) n, SUM(ru.units_per_item) cu "
            . 'FROM reservation r JOIN reservation_unit ru ON ru.reservation_id = r.id '
            . "WHERE r.channel_id = ? AND r.origin = 'opening' GROUP BY ru.sku_id, ru.warehouse_id, in_state, committed",
            [...self::UNIT_STATES, ...self::COMMITTED_BY, $channelId],
        ) as $r) {
            if ((int) $r['in_state'] !== 1) {
                $report['opening_units']['released'] += (int) $r['n'];
                continue;
            }
            if ($r['sku_id'] === null) {
                $report['opening_units']['unlinked'] += (int) $r['n'];
                continue;
            }
            if ((int) $r['committed'] !== 1) {
                // Cancelled while held and left out of the opening body (or taken back after T0): never paid at T0.
                $report['opening_units']['never_committed'] += (int) $r['n'];
                continue;
            }
            $sku = (int) $r['sku_id'];
            $report['opening_units']['units'] += (int) $r['n'];
            $report['opening_units']['central_units'] += (int) $r['cu'];
            if ((int) $r['warehouse_id'] !== $whId) {
                $skip[$sku]['units_elsewhere'] = true;
                continue;
            }
            $unitsTerm[$sku] = ($unitsTerm[$sku] ?? 0) + (int) $r['cu'];
        }

        // ---- earlier opening rows (estimate, this rebase, anything else) -----------------------------
        $earlier = [];
        $booked = [];
        $otherOpening = [];
        foreach ($this->db->all(
            "SELECT sku_id, warehouse_id, doc_ref COLLATE utf8mb4_0900_bin AS d, SUM(qty_delta) q FROM stock_ledger "
            . "WHERE actor = ? AND bucket = 'on_hand' GROUP BY sku_id, warehouse_id, d",
            [$actor],
        ) as $r) {
            $sku = (int) $r['sku_id'];
            $d = $r['d'] === null ? null : (string) $r['d'];
            $here = (int) $r['warehouse_id'] === $whId;
            if ($here && $estimateDocRef !== null && $d === $estimateDocRef) {
                $earlier[$sku] = ($earlier[$sku] ?? 0) + (int) $r['q'];
            } elseif ($here && $d === $docRef) {
                $booked[$sku] = ($booked[$sku] ?? 0) + (int) $r['q'];
            } else {
                $otherOpening[$sku] = true;
            }
        }

        // The items this channel's opening concerns: linked here, sold in its opening, or opened by its estimate or
        // this rebase. Another site's opening rows matter only on these (they make the item `other_opening`).
        $items = array_keys($siteTerm + $unitsTerm + $earlier + $booked + $skip);
        sort($items);
        foreach ($items as $sku) {
            if (isset($otherOpening[$sku])) {
                $skip[$sku]['other_opening'] = true;
            }
            // Opened by the estimate, or sold in the opening, but no longer linked on this channel: the site figure
            // is unknown (siteTerm is set for every item with a mapped or quarantined listing here).
            if (!isset($siteTerm[$sku]) && (isset($earlier[$sku]) || isset($unitsTerm[$sku]))) {
                $skip[$sku]['no_mapped_listing'] = true;
            }
        }

        // ---- history: counted, moved -----------------------------------------------------------------
        $moved = [];
        foreach (array_chunk($items, self::CHUNK) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($this->db->column(
                "SELECT id FROM sku WHERE id IN ({$in}) AND counted_at IS NOT NULL
                 UNION SELECT sku_id FROM stock_balance WHERE sku_id IN ({$in}) AND counted_at IS NOT NULL
                 UNION SELECT sku_id FROM stock_ledger WHERE sku_id IN ({$in}) AND movement_type = 'count'",
                [...$chunk, ...$chunk, ...$chunk],
            ) as $sku) {
                $skip[(int) $sku]['counted'] = true;
            }
            // Every on_hand row that is neither an opening row nor a row of one of this channel's opening units.
            foreach ($this->db->all(
                "SELECT l.sku_id, l.movement_type, COUNT(*) n FROM stock_ledger l
                 LEFT JOIN reservation_unit ru ON ru.channel_id = l.channel_id AND ru.unit_id = l.unit_id
                 LEFT JOIN reservation r ON r.id = ru.reservation_id
                 WHERE l.sku_id IN ({$in}) AND l.bucket = 'on_hand' AND l.actor <> ?
                   AND (r.id IS NULL OR r.origin <> 'opening' OR r.channel_id <> ?)
                 GROUP BY l.sku_id, l.movement_type",
                [...$chunk, $actor, $channelId],
            ) as $r) {
                $sku = (int) $r['sku_id'];
                $skip[$sku]['moved'] = true;
                $moved[$sku][(string) $r['movement_type']] = (int) $r['n'];
            }
        }

        // ---- per item --------------------------------------------------------------------------------
        $codes = $this->codes($items);
        $lines = [];
        $conflicts = [];
        $report['items'] = count($items);
        $report['site_term'] = 0;
        $report['units_term'] = 0;
        $report['earlier'] = 0;
        $report['to_book'] = ['items' => 0, 'up' => 0, 'down' => 0, 'net' => 0];
        $report['no_change'] = 0;
        $report['already_rebased'] = ['items' => 0, 'units' => 0];
        $report['skipped'] = ['items' => 0, 'why' => [], 'details' => []];
        foreach ($items as $sku) {
            $target = ($siteTerm[$sku] ?? 0) + ($unitsTerm[$sku] ?? 0);
            $before = $earlier[$sku] ?? 0;
            $delta = $target - $before;
            if (isset($booked[$sku])) {
                // Rebased by this doc_ref already: judged on its figure only.
                if ($booked[$sku] !== $delta) {
                    $conflicts[] = ['sku_id' => $sku, 'sku_code' => $codes[$sku] ?? null, 'booked' => $booked[$sku], 'planned' => $delta];
                } else {
                    $report['already_rebased']['items']++;
                    $report['already_rebased']['units'] += $delta;
                }
                continue;
            }
            if (isset($skip[$sku])) {
                $why = array_values(array_intersect(self::SKIP_REASONS, array_keys($skip[$sku])));
                $report['skipped']['items']++;
                foreach ($why as $w) {
                    $report['skipped']['why'][$w] = ($report['skipped']['why'][$w] ?? 0) + 1;
                }
                if (count($report['skipped']['details']) < self::DETAIL_ITEMS) {
                    $report['skipped']['details'][] = ['sku_id' => $sku, 'sku_code' => $codes[$sku] ?? null, 'why' => $why,
                        'target' => $target, 'earlier' => $before, 'delta' => $delta] + (isset($moved[$sku]) ? ['moved' => $moved[$sku]] : []);
                }
                continue;
            }
            if (abs($delta) > OpeningEstimate::MAX_ITEM_UNITS) {
                throw new CwException('too_many_units', "item {$sku} would move {$delta} units (max " . OpeningEstimate::MAX_ITEM_UNITS . ')', 422);
            }
            $report['site_term'] += $siteTerm[$sku] ?? 0;
            $report['units_term'] += $unitsTerm[$sku] ?? 0;
            $report['earlier'] += $before;
            if ($delta === 0) {
                $report['no_change']++;
                continue;
            }
            $lines[$sku] = $delta;
            $report['to_book']['items']++;
            $report['to_book'][$delta > 0 ? 'up' : 'down'] += abs($delta);
            $report['to_book']['net'] += $delta;
        }
        if ($conflicts !== []) {
            throw new CwException('rebase_conflict', sprintf('%d item(s) were rebased under %s with other figures than planned now '
                . '(another file, or links changed since): not the same rebase (a deliberate second rebase uses another doc_ref)',
                count($conflicts), $docRef), 409, ['items' => array_slice($conflicts, 0, 50)]);
        }
        ksort($report['skipped']['why']);
        return ['lines' => $lines, 'report' => $report, 'opening' => $op + ['warehouse_code' => $whCode]];
    }

    /**
     * @param list<int> $skus
     * @return array<int, string>
     */
    private function codes(array $skus): array
    {
        $out = [];
        foreach (array_chunk($skus, self::CHUNK) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($this->db->all("SELECT id, code FROM sku WHERE id IN ({$in})", $chunk) as $r) {
                $out[(int) $r['id']] = (string) $r['code'];
            }
        }
        return $out;
    }
}
