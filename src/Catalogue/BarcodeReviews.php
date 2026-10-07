<?php

declare(strict_types=1);

namespace CW\Catalogue;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;

/**
 * The barcode review queue (IM3; docs/decisions.md I107): what the barcode sync (BarcodeSync) could not decide alone. A person
 * with catalogue.edit decides each row; nothing is moved or added without them.
 *
 *  on_another_item  a linked listing of item S (the claimant) carries a barcode that item H (the holder) has. The sync made H's
 *                   row unusable (the rule "a barcode on two items is unusable until fixed"). Decisions:
 *                     keep_holder  it is H's: usable again (see below); the listing of S carries a wrong barcode, to be
 *                                  corrected on the site;
 *                     move         it is S's: the row moves to S (units per scan as given), usable again (see below), and a
 *                                  decided `moved_away` row is written for (barcode, H), so H's own listing, which usually still
 *                                  carries it, does not claim it back at the next sync (I116);
 *                     unusable     it stays on H, unusable: a code shared by several items (a box barcode, a supplier's
 *                                  catch-all), never used to recognise an item.
 *  multipack_listing a listing linked with units per item u <> 1 ("10 x 10ml") carries a barcode no item has: is it the
 *                   pack's barcode (u units a scan) or the single unit's (1)? Decisions: add (with the units given; default
 *                   u), dismiss (do not add; a row someone added meanwhile, unusable only because of this review, is
 *                   usable again).
 *  removed          not a question: a person removed the barcode from the item (ItemBarcodes::remove); recorded decided.
 *
 * "Usable again" (I117) only when no other review of the barcode is open AND no person has ruled the barcode shared ("unusable")
 * since its row was made: a later keep or move for another item does not undo that ruling (the note says why); removing the
 * barcode and adding it by hand starts it afresh. The sync never re-opens a pair (barcode, claimant) whose latest decision keeps
 * the barcode away from the claimant (KEEPS_AWAY). A decision re-reads the barcode's row: when it changed since the review opened
 * so that the decision no longer applies (the holder no longer has it, someone else added it), 409 review_stale.
 * Audit barcode_review.decide.
 */
final class BarcodeReviews
{
    /** reason => decision => what people read. */
    public const DECISIONS = [
        'on_another_item' => [
            'keep_holder' => 'It belongs to the item that has it (the listing carries a wrong barcode)',
            'move' => 'It belongs to the listing\'s item: move it there',
            'unusable' => 'Shared by both: keep it unusable',
        ],
        'multipack_listing' => [
            'add' => 'Add it to the listing\'s item',
            'dismiss' => 'Do not add it',
        ],
    ];
    public const REASONS = [
        'on_another_item' => 'on another item',
        'multipack_listing' => 'multipack listing',
        'removed' => 'removed by a person',
    ];
    /** The decisions written for a person, never chosen in the queue => what people read. */
    public const RECORDED = [
        'removed' => 'removed from the item',
        'moved_away' => 'moved to another item by a barcode review',
    ];
    /** Decisions after which the sync never offers the barcode to the claimant again. */
    public const KEEPS_AWAY = ['keep_holder', 'unusable', 'dismiss', 'removed', 'moved_away'];
    /** What every writer puts in sku_barcode.note when it adds or leaves a row unusable only because a review of it is open. */
    public const IN_REVIEW_NOTE = 'in barcode review';
    public const LIST_LIMIT = 200;

    public function __construct(private readonly Db $db)
    {
    }

    public function openCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM barcode_review WHERE status = 'open'");
    }

    /**
     * The queue: open rows oldest first, or decided rows newest first, with both items (code, name, merged into, stock held),
     * the listing (site, variant, title, units per item, status) and where the barcode is now.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $status = 'open', int $limit = self::LIST_LIMIT, ?string $barcode = null): array
    {
        $status = $status === 'decided' ? 'decided' : 'open';
        $params = [$status];
        $where = 'r.status = ?';
        if ($barcode !== null && $barcode !== '') {
            $where .= ' AND r.barcode = ?';
            $params[] = $barcode;
        }
        $rows = $this->db->all(
            'SELECT r.*, c.code AS claimant_code, c.name AS claimant_name, c.merged_into_sku_id AS claimant_merged, h.code AS holder_code, h.name AS holder_name, '
            . 'h.merged_into_sku_id AS holder_merged, hm.code AS holder_merged_code, ch.code AS channel, cl.external_variant_id, cl.status AS listing_status, '
            . 'cl.sku_id AS listing_sku_id, cl.units_per_item AS listing_u, lp.product_title, lp.variant_title, b.sku_id AS now_sku_id, nb.code AS now_code, '
            . 'b.is_usable AS now_usable, b.units_per_scan AS now_units, u.display_name AS decided_by_name, o.display_name AS opened_by_name '
            . 'FROM barcode_review r JOIN sku c ON c.id = r.claimant_sku_id LEFT JOIN sku h ON h.id = r.holder_sku_id LEFT JOIN sku hm ON hm.id = h.merged_into_sku_id '
            . 'LEFT JOIN channel_listing cl ON cl.id = r.listing_id LEFT JOIN channel ch ON ch.id = cl.channel_id LEFT JOIN listing_profile lp ON lp.listing_id = r.listing_id '
            . 'LEFT JOIN sku_barcode b ON b.barcode = r.barcode LEFT JOIN sku nb ON nb.id = b.sku_id LEFT JOIN staff_user u ON u.id = r.decided_by '
            . 'LEFT JOIN staff_user o ON o.id = r.opened_by '
            . "WHERE {$where} ORDER BY r.id " . ($status === 'open' ? 'ASC' : 'DESC') . ' LIMIT ' . max(1, min(1000, $limit)),
            $params,
        );
        $ids = [];
        foreach ($rows as $r) {
            $ids[(int) $r['claimant_sku_id']] = true;
            if ($r['holder_sku_id'] !== null) {
                $ids[(int) $r['holder_sku_id']] = true;
            }
        }
        $stock = [];
        if ($ids !== []) {
            foreach ($this->db->all('SELECT sku_id, SUM(GREATEST(on_hand, 0)) AS held FROM stock_balance WHERE sku_id IN ('
                . implode(', ', array_fill(0, count($ids), '?')) . ') GROUP BY sku_id', array_keys($ids)) as $s) {
                $stock[(int) $s['sku_id']] = (int) $s['held'];
            }
        }
        foreach ($rows as &$r) {
            // The holder was merged into the claimant (I118): the barcode came with the merge; moving it is the usual answer.
            $r['holder_merged_into_claimant'] = $r['holder_merged'] !== null && (int) $r['holder_merged'] === (int) $r['claimant_sku_id'];
            $r['claimant_stock'] = $stock[(int) $r['claimant_sku_id']] ?? 0;
            $r['holder_stock'] = $r['holder_sku_id'] === null ? null : ($stock[(int) $r['holder_sku_id']] ?? 0);
            $r['decisions'] = self::DECISIONS[(string) $r['reason']] ?? [];
        }
        unset($r);
        return $rows;
    }

    /**
     * Decides an open row. $units: the units per scan for move / add (default: the barcode row's for a move, the listing's u
     * for an add). $note: optional, at most 500 characters. 404 unknown_review · 409 review_closed / review_stale · 400
     * bad_decision · 422 bad_units · 403 as ItemCards::editor.
     *
     * @return array{id: int, barcode: string, decision: string, units: ?int, usable: ?bool}
     */
    public function decide(Caller $caller, int $id, string $decision, ?int $units = null, ?string $note = null): array
    {
        $note = ItemBarcodes::note($note);
        if ($units !== null) {
            ItemBarcodes::checkUnits($units);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $id, $decision, $units, $note): array {
            $me = ItemCards::editor($db, $caller);
            $r = $db->one('SELECT * FROM barcode_review WHERE id = ? FOR UPDATE', [$id]) ?? throw new CwException('unknown_review', 'There is no such barcode review.', 404);
            if ($r['status'] !== 'open') {
                throw new CwException('review_closed', 'This barcode review was decided already: reload the page.', 409);
            }
            $allowed = self::DECISIONS[(string) $r['reason']] ?? [];
            if (!isset($allowed[$decision])) {
                throw new CwException('bad_decision', 'Choose one of: ' . implode('; ', $allowed) . '.', 400);
            }
            $key = (string) $r['barcode'];
            $claimant = (int) $r['claimant_sku_id'];
            $holder = $r['holder_sku_id'] === null ? null : (int) $r['holder_sku_id'];
            $codes = [];
            foreach ($db->all('SELECT id, code, merged_into_sku_id FROM sku WHERE id IN (?, ?)', [$claimant, $holder ?? $claimant]) as $s) {
                $codes[(int) $s['id']] = $s;
            }
            $row = $db->one('SELECT sku_id, is_usable, units_per_scan, note, created_at FROM sku_barcode WHERE barcode = ? FOR UPDATE', [$key]);
            $now = $row === null ? null : (int) $row['sku_id'];
            // A move answers the old holder's own claim too (I116): its open review, if any, is closed with this one.
            $holderOpen = $decision === 'move' && $holder !== null && $holder !== $claimant
                ? $db->value("SELECT id FROM barcode_review WHERE open_key = ? FOR UPDATE", ["{$key}:{$holder}"]) : null;
            $others = (int) $db->value("SELECT COUNT(*) FROM barcode_review WHERE barcode = ? AND status = 'open' AND id <> ? AND id <> ?",
                [$key, $id, $holderOpen === null ? 0 : (int) $holderOpen]);
            $shared = $row === null ? null : self::ruledShared($db, $key, (string) $row['created_at']);
            $usable = $others === 0 && $shared === null ? 1 : 0;
            $why = match (true) {
                $others > 0 => ' (another review is open)',
                $shared !== null => " (kept unusable: barcode review {$shared} ruled it shared; to use it again, remove it and add it by hand)",
                default => '',
            };
            $stale = static fn (string $why): CwException => new CwException('review_stale', "The barcode {$key} changed since this review opened: {$why} "
                . 'Choose again (or another option).', 409);
            $effectUnits = null;
            $effectUsable = null;
            switch ($decision) {
                case 'keep_holder':
                    if ($holder === null || $now !== $holder) {
                        throw $stale($now === null ? 'no item has it now.' : 'it is on ' . self::codeOf($db, $now) . ' now.');
                    }
                    $db->exec('UPDATE sku_barcode SET is_usable = ?, note = ? WHERE barcode = ?', [$usable, mb_substr("kept on {$codes[$holder]['code']} by barcode review {$id}"
                        . $why, 0, 255), $key]);
                    $effectUsable = $usable === 1;
                    break;
                case 'move':
                case 'add':
                    if ($codes[$claimant]['merged_into_sku_id'] !== null) {
                        throw $stale("{$codes[$claimant]['code']} was merged into another item.");
                    }
                    if ($decision === 'move' && $now !== null && $now !== $holder && $now !== $claimant) {
                        throw $stale('it is on ' . self::codeOf($db, $now) . ' now.');
                    }
                    if ($decision === 'add' && $now !== null && $now !== $claimant) {
                        throw $stale('it is on ' . self::codeOf($db, $now) . ' now: that needs the "on another item" review.');
                    }
                    $effectUnits = $units ?? ($decision === 'move' && $row !== null ? (int) $row['units_per_scan'] : max(1, (int) ($r['units_per_item'] ?? 1)));
                    $rowNote = mb_substr(($decision === 'move' && $holder !== null ? "moved from {$codes[$holder]['code']}" : 'added') . " by barcode review {$id}"
                        . $why, 0, 255);
                    if ($row === null) {
                        try {
                            $db->exec("INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan, source, note) VALUES (?, ?, ?, ?, 'review', ?)",
                                [$key, $claimant, $usable, $effectUnits, $rowNote]);
                        } catch (\PDOException $e) {
                            if (Db::driverCode($e) !== 1062) {
                                throw $e;
                            }
                            throw $stale('an item was given it a moment ago.');
                        }
                    } else {
                        $db->exec("UPDATE sku_barcode SET sku_id = ?, is_usable = ?, units_per_scan = ?, source = 'review', note = ? WHERE barcode = ?",
                            [$claimant, $usable, $effectUnits, $rowNote, $key]);
                    }
                    $effectUsable = $usable === 1;
                    if ($decision === 'move' && $holder !== null && $holder !== $claimant) {
                        $movedNote = mb_substr("moved to {$codes[$claimant]['code']} by barcode review {$id}", 0, 500);
                        if ($holderOpen !== null) {
                            $db->exec("UPDATE barcode_review SET status = 'decided', decision = 'moved_away', decided_by = ?, decided_actor = ?, decided_at = UTC_TIMESTAMP(6), "
                                . 'note = ? WHERE id = ?', [$me['id'], $caller->actor, $movedNote, (int) $holderOpen]);
                        } else {
                            $db->exec("INSERT INTO barcode_review (barcode, reason, claimant_sku_id, holder_sku_id, status, decision, decided_by, decided_actor, decided_at, "
                                . "note, opened_by, opened_actor) VALUES (?, 'on_another_item', ?, ?, 'decided', 'moved_away', ?, ?, UTC_TIMESTAMP(6), ?, ?, ?)",
                                [$key, $holder, $claimant, $me['id'], $caller->actor, $movedNote, $me['id'], $caller->actor]);
                        }
                    }
                    break;
                case 'unusable':
                    if ($now === null) {
                        throw $stale('no item has it now.');
                    }
                    $db->exec('UPDATE sku_barcode SET is_usable = 0, note = ? WHERE barcode = ?', [mb_substr("shared by {$codes[$claimant]['code']} and "
                        . self::codeOf($db, $now) . ": unusable (barcode review {$id})", 0, 255), $key]);
                    $effectUsable = false;
                    break;
                case 'dismiss':
                    // A row someone added meanwhile is unusable only because this review was open: usable again (I117).
                    if ($row !== null && $usable === 1 && (int) $row['is_usable'] === 0 && str_contains((string) $row['note'], self::IN_REVIEW_NOTE)) {
                        $db->exec('UPDATE sku_barcode SET is_usable = 1, note = ? WHERE barcode = ?', [mb_substr("usable: barcode review {$id} dismissed", 0, 255), $key]);
                        $effectUsable = true;
                    }
                    break;
            }
            $db->exec("UPDATE barcode_review SET status = 'decided', decision = ?, decided_units = ?, decided_by = ?, decided_actor = ?, decided_at = UTC_TIMESTAMP(6), "
                . 'note = ? WHERE id = ?', [$decision, $effectUnits, $me['id'], $caller->actor, $note, $id]);
            Audit::write($db, $caller, 'barcode_review.decide', 'barcode_review', (string) $id, null, ['barcode' => $key, 'reason' => $r['reason'],
                'decision' => $decision, 'claimant_sku_id' => $claimant, 'holder_sku_id' => $holder, 'was_on' => $now, 'units' => $effectUnits,
                'usable' => $effectUsable, 'note' => $note, 'ruled_shared_by' => $shared, 'closed_holder_review' => $holderOpen === null ? null : (int) $holderOpen]);
            return ['id' => $id, 'barcode' => $key, 'decision' => $decision, 'units' => $effectUnits, 'usable' => $effectUsable];
        });
    }

    /**
     * The latest review that ruled the barcode shared ("unusable") since its sku_barcode row was made ($rowCreatedAt), or null.
     */
    public static function ruledShared(Db $db, string $key, string $rowCreatedAt): ?int
    {
        $id = $db->value("SELECT id FROM barcode_review WHERE barcode = ? AND status = 'decided' AND decision = 'unusable' AND decided_at >= ? ORDER BY id DESC LIMIT 1",
            [$key, $rowCreatedAt]);
        return $id === null ? null : (int) $id;
    }

    private static function codeOf(Db $db, int $skuId): string
    {
        return (string) ($db->value('SELECT code FROM sku WHERE id = ?', [$skuId]) ?? "item {$skuId}");
    }
}
