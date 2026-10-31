<?php

declare(strict_types=1);

namespace CW\Queues;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Movements;
use CW\OpResult;
use CW\Relay\RelayIntake;
use CW\Settings;
use CW\Stock;

/**
 * ERPNext's corrections ("set to this number", stock_reconcillation.php), relayed by a store into the count-review queue (count_review,
 * source erp_reconciliation; plan §9; docs/decisions.md PF7). Approvals › From the stores › ERPNext corrections.
 *
 *   effect()   what confirming would do, worked out from the stock ledger AT THE MOMENT ERPNext set the number (the relayed posted_at,
 *              else the moment CW received it): what CW held on the shelf then (the on_hand balance after the last row booked by then),
 *              the units it kept for paid orders not sent yet (allocated, likewise), and the shelf figure ERPNext's number stands for:
 *              the number in CW's units, plus those paid units when relay.correction_is_for_sale is on (the go-live plan's rule: on the
 *              website the corrected number is the figure customers can buy; a shelf count would leave the paid units out). The
 *              difference is what confirming books.
 *   confirm()  books that difference NOW as an adjustment through the movement route (Movements::bookInTransaction; the ERPNext
 *              document's name on the ledger), so the stock that moved since the correction (sales sent, deliveries) is kept on top,
 *              and settles the row (resolved, `booked`, or `no_difference` when there is nothing to book), in ONE transaction under the
 *              key queue:correction:<id>. It is NOT a count: the item's count time (stock_balance.counted_at, the count plan's) and its
 *              stock rule are left alone ("ERPNext's pushes never become counts automatically"; the item is counted on a count sheet).
 *   dismiss()  nothing is booked; the row is dismissed with the note.
 *
 * "Confirm all ready" (the owner, 31 Oct 2026; docs/decisions.md CA1-CA12): readiness() splits the open rows into READY (why() null) and
 * Needs a look (the first rule of NOT_READY that applies, read from effect() and facts()); confirmAll() confirms the ids of its check page
 * one by one through confirm() with $whenReady, which checks the same rules again under the row's and the balance's locks, so a press books
 * exactly what one-by-one confirms would book, and nothing that changed meanwhile.
 *
 * Who: queues.corrections (the stock controller), re-read inside the transaction. The queue row is locked first, then the booking's
 * balance and the feed clock.
 */
final class Corrections
{
    public const TABLE = 'count_review';
    public const PERM = 'queues.corrections';
    public const SOURCE = RelayIntake::SOURCE_RECONCILIATION;
    /** The Idempotency-Key of a confirmation (and of the adjustment it books): KEY . <count_review id> (the rollback file knows them by it). */
    public const KEY = 'queue:correction:';
    /** The most open corrections Confirm all reads and confirms in one press, the oldest first; the rest wait for the next press (CA6). */
    public const BULK_MAX = 500;
    /** The longest one press confirms for, in seconds (the UI pool stops a page at 50): the rest are left as "not reached" (CA6). */
    public const BULK_SECONDS = 25;
    /** The setting of the big-change rule (0054; CA3), and what is used while it is not there yet. */
    public const BIG_SETTING = 'relay.correction_bulk_max_units';
    public const BIG_DEFAULT = 100;
    /** Why an open correction needs a look (CA2), in the order they are checked: the first that applies is its reason (why()). */
    public const NOT_READY = ['counted', 'newer', 'big', 'below_zero', 'variants', 'fixed'];
    /** The doc_ref of the one-time stock fix (cw_listening_fix.php: listening-fix:<channel>:<date>, its undo <that>:undo). */
    public const FIX_PREFIX = 'listening-fix:';
    /** The audit row of one press of Confirm all (CA7): the done page reads it. */
    public const RUN_ACTION = 'count_review.confirm_all';

    public function __construct(private readonly Db $db)
    {
    }

    public function openCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM count_review FORCE INDEX (ix_count_review_open) WHERE status = 'open' AND source = ?", [self::SOURCE]);
    }

    /**
     * The list: open rows oldest first (each with its effect()), or every row of a status, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $status = 'open', int $limit = QueueRules::PAGE + 1): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->db->all(
            'SELECT STRAIGHT_JOIN r.id, r.warehouse_id, w.code AS warehouse_code, w.name AS warehouse, r.sku_id, s.code AS sku_code, s.name AS sku_name, r.proposed_qty, r.ref, '
            . 'CAST(r.detail AS CHAR) AS detail, CAST(r.status AS CHAR) AS status, r.resolution, r.resolved_by, r.resolved_at, r.note, r.created_at '
            . 'FROM count_review r' . ($status === null ? '' : ' FORCE INDEX (ix_count_review_open)') . ' JOIN sku s ON s.id = r.sku_id JOIN warehouse w ON w.id = r.warehouse_id '
            . 'WHERE r.source = ?' . ($status === null ? '' : ' AND r.status = ?') . ' ORDER BY r.id' . ($status === 'open' ? '' : ' DESC') . " LIMIT {$limit}",
            $status === null ? [self::SOURCE] : [self::SOURCE, $status],
        );
        $names = QueueRules::names($this->db, array_column($rows, 'resolved_by'));
        $stores = QueueRules::stores($this->db);
        $settings = new Settings($this->db);
        foreach ($rows as &$r) {
            $d = json_decode((string) $r['detail'], true);
            $r['detail'] = is_array($d) ? $d : [];
            $r['at'] = self::at($r);
            $r['store'] = isset($r['detail']['channel_id']) ? ($stores[(int) $r['detail']['channel_id']] ?? null) : null;
            $r['resolved_by_name'] = $r['resolved_by'] === null ? null : ($names[(int) $r['resolved_by']] ?? null);
            $r['effect'] = $r['status'] === 'open' ? $this->effect((int) $r['warehouse_id'], (int) $r['sku_id'], (int) $r['proposed_qty'], $r['at'], $settings,
                (int) $r['id']) : null;
        }
        return $rows;
    }

    /**
     * What confirming books (class docblock): shelf and allocated as the ledger had them at $at, the shelf figure the correction stands
     * for, and the difference.
     *
     * Review finding 1 (Codex, 14 Oct 2026): corrections of one item confirmed late must not compound. What CW held at the moment
     * also counts the corrections of the same item and place made at or before it but booked after it (confirmed later): without them
     * 20 -> 10 -> 8, both waiting, booked -10 and -12. `carried` is their sum; `newer` says a correction made after this one was confirmed
     * already (it set the figure: this one is out of date and is refused, 409 newer_correction).
     *
     * @return array{shelf: int, allocated: int, target: int, delta: int, for_sale: bool, carried: int, newer: bool}
     */
    public function effect(int $warehouseId, int $skuId, int $units, string $at, ?Settings $settings = null, int $self = 0): array
    {
        $settings ??= new Settings($this->db);
        $forSale = !$settings->has('relay.correction_is_for_sale') || $settings->get('relay.correction_is_for_sale') === true;
        // The last ledger row booked by then (the created_at index, one row), then each bucket's last row of this balance at or before it
        // (the balance's (warehouse, item, id) index, read backwards from there).
        $last = $this->db->value('SELECT id FROM stock_ledger WHERE created_at <= ? ORDER BY created_at DESC, id DESC LIMIT 1', [$at]);
        $bucket = fn (string $b): int => $last === null ? 0 : (int) ($this->db->value(
            'SELECT balance_after FROM stock_ledger FORCE INDEX (ix_stock_ledger_balance) WHERE warehouse_id = ? AND sku_id = ? AND id <= ? AND bucket = ? ORDER BY id DESC LIMIT 1',
            [$warehouseId, $skuId, (int) $last, $b],
        ) ?? 0);
        $carried = 0;
        $newer = false;
        foreach ($this->confirmedOf($warehouseId, $skuId, $self) as $c) {
            if ($c['at'] > $at) {
                $newer = true;
            } elseif ($c['booked_at'] > $at) {
                $carried += $c['delta'];
            }
        }
        $shelf = $bucket('on_hand') + $carried;
        $allocated = $bucket('allocated');
        $target = $units + ($forSale ? max(0, $allocated) : 0);
        return ['shelf' => $shelf, 'allocated' => $allocated, 'target' => $target, 'delta' => $target - $shelf, 'for_sale' => $forSale, 'carried' => $carried,
            'newer' => $newer];
    }

    /**
     * The confirmed corrections of one item at one place (not $except): when each was made, when it was booked and what it booked
     * (detail.booked, written by confirm()).
     *
     * @return list<array{at: string, booked_at: string, delta: int}>
     */
    private function confirmedOf(int $warehouseId, int $skuId, int $except): array
    {
        $out = [];
        foreach ($this->db->all("SELECT id, CAST(detail AS CHAR) AS detail, created_at, resolved_at FROM count_review WHERE sku_id = ? AND warehouse_id = ? AND source = ? "
            . "AND status = 'resolved' AND id <> ?", [$skuId, $warehouseId, self::SOURCE, $except]) as $r) {
            $d = json_decode((string) $r['detail'], true);
            $d = is_array($d) ? $d : [];
            $booked = is_array($d['booked'] ?? null) ? $d['booked'] : [];
            $out[] = ['at' => self::at(['detail' => $d, 'created_at' => $r['created_at']]), 'booked_at' => (string) ($booked['at'] ?? $r['resolved_at']),
                'delta' => (int) ($booked['delta'] ?? 0)];
        }
        return $out;
    }

    /**
     * $whenReady (Confirm all, CA5): the row must still be READY (why() null) on what its item holds now, read under the row's and the
     * balance's locks after the checks above; otherwise 409 not_ready (its reason in the detail) and nothing is booked or stored, so the key
     * stays free for a person's answer. Everything else (the key, the request it stores, the booking, the audit rows) is one-by-one's.
     *
     * @return array{result: string, delta: int, replayed: bool}
     */
    public function confirm(Caller $caller, int $id, string $note, bool $whenReady = false): array
    {
        $note = QueueRules::note($note);
        // Who first (and again inside): a person without the job hears that, not what the key stored for someone else's answer.
        QueueRules::authorise($this->db, $caller, self::PERM);
        $key = self::KEY . $id;
        $r = (new Idempotency($this->db))->run($caller, $key, 'queue.correction.confirm', '/ui/queues/corrections/' . $id, ['id' => $id, 'note' => $note],
            self::TABLE, (string) $id, function (Db $db) use ($caller, $id, $note, $key, $whenReady): OpResult {
                QueueRules::authorise($db, $caller, self::PERM);
                $row = QueueRules::lockOpen($db, self::TABLE, $id, 'source = ?', [self::SOURCE]);
                $d = json_decode((string) ($row['detail'] ?? '{}'), true);
                $row['detail'] = is_array($d) ? $d : [];
                $at = self::at($row);
                // The item's balance first: two corrections of one item confirmed at once decide one after the other, each seeing what
                // the other booked (finding 1). bookInTransaction locks the same balance again (held).
                (new Stock($db))->lock([[(int) $row['warehouse_id'], (int) $row['sku_id']]]);
                $countedAt = $db->value('SELECT counted_at FROM stock_balance WHERE warehouse_id = ? AND sku_id = ?',
                    [(int) $row['warehouse_id'], (int) $row['sku_id']]);
                if ($countedAt !== null && (string) $countedAt > $at) {
                    throw new CwException('newer_count', 'a later physical count already set this balance: close the older correction', 409);
                }
                $e = $this->effect((int) $row['warehouse_id'], (int) $row['sku_id'], (int) $row['proposed_qty'], $at, null, $id);
                if ($e['newer']) {
                    throw new CwException('newer_correction', 'a newer ERPNext correction of this item was confirmed already: this one is out of date, close it', 409);
                }
                if ($whenReady) {
                    $why = self::why($at, $e, self::facts($db, [$row])[$id], self::bigLimit(new Settings($db)));
                    if ($why !== null) {
                        throw new CwException('not_ready', "this correction needs a look now ({$why['code']}): answer it on its own", 409, ['reason' => $why]);
                    }
                }
                QueueRules::settle($db, $caller, self::TABLE, $id, 'resolved', $e['delta'] === 0 ? 'no_difference' : 'booked', $note);
                $onHand = null;
                $bookedAt = (string) $db->value('SELECT NOW(6)');
                if ($e['delta'] !== 0) {
                    $wh = (string) $db->value('SELECT code FROM warehouse WHERE id = ?', [(int) $row['warehouse_id']]);
                    $lines = (new Movements($db))->bookInTransaction($caller, ['type' => 'adjustment', 'doc_ref' => (string) $row['ref'], 'warehouse' => $wh,
                        'note' => mb_substr('ERPNext correction, review #' . $id, 0, 255),
                        'lines' => [['sku_id' => (int) $row['sku_id'], 'qty' => $e['delta'], 'line_index' => (int) ($row['detail']['line_index'] ?? 0)]]], $key);
                    $onHand = (int) $lines[0]['on_hand'];
                    // When the adjustment entered the ledger (its own row, under the balance's lock): what a later correction compares with.
                    $bookedAt = (string) $db->value("SELECT created_at FROM stock_ledger FORCE INDEX (ix_stock_ledger_balance) WHERE warehouse_id = ? AND sku_id = ? "
                        . "AND bucket = 'on_hand' ORDER BY id DESC LIMIT 1", [(int) $row['warehouse_id'], (int) $row['sku_id']]);
                }
                $db->exec("UPDATE count_review SET detail = JSON_SET(COALESCE(detail, JSON_OBJECT()), '$.booked', JSON_OBJECT('delta', ?, 'at', ?)) WHERE id = ?",
                    [$e['delta'], $bookedAt, $id]);
                Audit::write($db, $caller, 'count_review.confirm', self::TABLE, (string) $id, $key, ['ref' => $row['ref'], 'at' => $at,
                    'units' => (int) $row['proposed_qty'], 'note' => $note] + $e);
                return OpResult::of(200, ['result' => $e['delta'] === 0 ? 'no_difference' : 'booked', 'id' => $id, 'delta' => $e['delta'], 'on_hand' => $onHand]);
            });
        QueueRules::refused($r);
        return ['result' => (string) $r->body['result'], 'delta' => (int) $r->body['delta'], 'replayed' => $r->replayed];
    }

    public function dismiss(Caller $caller, int $id, string $note): void
    {
        $note = QueueRules::note($note);
        $this->db->transaction(function (Db $db) use ($caller, $id, $note): void {
            QueueRules::authorise($db, $caller, self::PERM);
            $row = QueueRules::lockOpen($db, self::TABLE, $id, 'source = ?', [self::SOURCE]);
            QueueRules::settle($db, $caller, self::TABLE, $id, 'dismissed', 'dismissed', $note);
            Audit::write($db, $caller, 'count_review.dismiss', self::TABLE, (string) $id, null, ['ref' => $row['ref'], 'units' => (int) $row['proposed_qty'], 'note' => $note]);
        });
    }

    // ------------------------------------------------------------------------------------------
    // "Confirm all ready" (docs/decisions.md CA1-CA12)
    // ------------------------------------------------------------------------------------------

    /**
     * The open corrections, oldest first (at most BULK_MAX), each a list() row with `why`: null when it is READY for Confirm all, else the
     * first rule of NOT_READY that applies (why()). The list, the check page and the press itself (after its run) read it; at booking time
     * confirm() reads the same facts again for the one row, under its locks.
     *
     * @return list<array<string, mixed>>
     */
    public function readiness(int $limit = self::BULK_MAX): array
    {
        $units = self::bigLimit(new Settings($this->db));
        $rows = $this->list('open', max(1, min($limit, self::BULK_MAX)));
        $facts = self::facts($this->db, $rows);
        foreach ($rows as &$r) {
            $r['why'] = self::why((string) $r['at'], $r['effect'], $facts[(int) $r['id']], $units);
        }
        unset($r);
        return $rows;
    }

    /** The big-change limit of Confirm all (relay.correction_bulk_max_units; BIG_DEFAULT while the setting is not there or not set). */
    public static function bigLimit(Settings $settings): int
    {
        $v = $settings->has(self::BIG_SETTING) ? $settings->get(self::BIG_SETTING) : null;
        return is_int($v) && $v >= 0 ? $v : self::BIG_DEFAULT;
    }

    /**
     * Whether an open correction made at $at is READY for Confirm all (null), else why it needs a look (CA2): the FIRST of these that
     * applies, in this order (NOT_READY):
     *   counted     counted on the shelf after ERPNext set it (stock_balance.counted_at later than $at): what confirm() refuses (newer_count)
     *   newer       a newer ERPNext correction of the item was confirmed already (effect()['newer']): what confirm() refuses
     *   big         it adds or takes off more than $limit units (relay.correction_bulk_max_units)
     *   below_zero  the item's on hand now plus what confirming books is below zero
     *   variants    the store links 2 or more of its website products to the item: ERPNext set only one of them
     *   fixed       a one-time stock fix (FIX_PREFIX) was booked for the item at that place after $at: confirming would book its units again
     * Nothing else: a correction with no difference is ready (it closes as "no difference", as one by one).
     *
     * @param array{delta: int, newer: bool} $effect effect() of the correction
     * @param array{on_hand: int, counted_at: ?string, links: int, fix_at: ?string} $facts facts() of the correction
     * @return array{code: string, delta?: int, limit?: int, after?: int, links?: int, fix_at?: string}|null
     */
    public static function why(string $at, array $effect, array $facts, int $limit): ?array
    {
        $delta = (int) $effect['delta'];
        return match (true) {
            $facts['counted_at'] !== null && $facts['counted_at'] > $at => ['code' => 'counted'],
            (bool) $effect['newer'] => ['code' => 'newer'],
            abs($delta) > $limit => ['code' => 'big', 'delta' => $delta, 'limit' => $limit],
            $facts['on_hand'] + $delta < 0 => ['code' => 'below_zero', 'after' => $facts['on_hand'] + $delta],
            $facts['links'] >= 2 => ['code' => 'variants', 'links' => $facts['links']],
            $facts['fix_at'] !== null && $facts['fix_at'] > $at => ['code' => 'fixed', 'fix_at' => $facts['fix_at']],
            default => null,
        };
    }

    /**
     * What the rules of Needs a look read besides effect(), for some corrections (rows with id, warehouse_id, sku_id and detail, as list()
     * and lockOpen give them), by id: the item's balance at the correction's place now (on hand; when it was last counted there), how many
     * website products of the correction's store are linked to the item (any store's when the correction names none: the safe side), and
     * when a one-time stock fix was last booked for the item at that place (an on-hand adjustment whose doc_ref starts with FIX_PREFIX).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, array{on_hand: int, counted_at: ?string, links: int, fix_at: ?string}>
     */
    public static function facts(Db $db, array $rows): array
    {
        $out = [];
        foreach (array_chunk($rows, 200) as $chunk) {
            $pairs = [];
            $skus = [];
            foreach ($chunk as $r) {
                $pairs[(int) $r['warehouse_id'] . ':' . (int) $r['sku_id']] = [(int) $r['warehouse_id'], (int) $r['sku_id']];
                $skus[(int) $r['sku_id']] = (int) $r['sku_id'];
            }
            $in = implode(', ', array_fill(0, count($pairs), '(?, ?)'));
            $flat = array_merge(...array_values($pairs));
            $balance = [];
            foreach ($db->all("SELECT warehouse_id, sku_id, on_hand, counted_at FROM stock_balance WHERE (warehouse_id, sku_id) IN ({$in})", $flat) as $b) {
                $balance[(int) $b['warehouse_id'] . ':' . (int) $b['sku_id']] = $b;
            }
            $links = [];
            foreach ($db->all('SELECT channel_id, sku_id, COUNT(*) AS n FROM channel_listing WHERE sku_id IN (' . implode(', ', array_fill(0, count($skus), '?'))
                . ') GROUP BY channel_id, sku_id', array_values($skus)) as $l) {
                $links[(int) $l['sku_id']][(int) $l['channel_id']] = (int) $l['n'];
            }
            $fixed = [];
            foreach ($db->all('SELECT warehouse_id, sku_id, MAX(created_at) AS at FROM stock_ledger FORCE INDEX (ix_stock_ledger_balance) '
                . "WHERE (warehouse_id, sku_id) IN ({$in}) AND bucket = 'on_hand' AND movement_type = 'adjustment' AND doc_ref LIKE ? GROUP BY warehouse_id, sku_id",
                [...$flat, self::FIX_PREFIX . '%']) as $f) {
                $fixed[(int) $f['warehouse_id'] . ':' . (int) $f['sku_id']] = (string) $f['at'];
            }
            foreach ($chunk as $r) {
                $k = (int) $r['warehouse_id'] . ':' . (int) $r['sku_id'];
                $detail = is_array($r['detail'] ?? null) ? $r['detail'] : [];
                $channel = $detail['channel_id'] ?? null;
                $bySku = $links[(int) $r['sku_id']] ?? [];
                $out[(int) $r['id']] = [
                    'on_hand' => (int) ($balance[$k]['on_hand'] ?? 0),
                    'counted_at' => isset($balance[$k]['counted_at']) ? (string) $balance[$k]['counted_at'] : null,
                    'links' => is_int($channel) || (is_string($channel) && ctype_digit($channel)) ? (int) ($bySku[(int) $channel] ?? 0) : array_sum($bySku),
                    'fix_at' => $fixed[$k] ?? null,
                ];
            }
        }
        return $out;
    }

    /**
     * One press of "Confirm all ready" (CA5-CA7): each id of the check page, in its order, confirmed exactly as one by one (confirm(): the
     * same caller, key, request, ledger line and audit rows) when it is still READY at that moment, under its locks ($whenReady). Any refusal
     * (it changed: counted, confirmed or closed by someone, a rule applies now) leaves it for a person, nothing of it booked, and one failure
     * never stops the rest. After $maxIds ids, or once $maxSeconds have gone, the rest are left as "not reached: run it again". A second
     * press of the same page books nothing again: each id's key replays its first answer. The open corrections that need a look now are
     * listed after them, and the press is ONE audit row (RUN_ACTION, by this person: the note, the counts, the ids confirmed and the ones
     * left with why), whose id is the done page's.
     *
     * @param list<int> $ids
     * @return array{run: int, confirmed: list<int>, left: list<array{id: int, why: array<string, mixed>}>}
     */
    public function confirmAll(Caller $caller, array $ids, string $note, int $maxIds = self::BULK_MAX, float $maxSeconds = self::BULK_SECONDS): array
    {
        $note = QueueRules::note($note);
        QueueRules::authorise($this->db, $caller, self::PERM);
        $started = hrtime(true);
        $confirmed = [];
        $replayed = [];
        $left = [];
        $booked = 0;
        $added = 0;
        $taken = 0;
        foreach (array_values(array_unique(array_map('intval', $ids))) as $i => $id) {
            if ($i >= $maxIds || (hrtime(true) - $started) / 1e9 >= $maxSeconds) {
                $left[] = ['id' => $id, 'why' => ['code' => 'not_reached']];
                continue;
            }
            try {
                $r = $this->confirm($caller, $id, $note, true);
            } catch (\RuntimeException $e) {
                // CwException (a refusal) or a database failure of this one (a deadlock given up): left and listed, the rest go on.
                $now = $e instanceof CwException && is_array($e->detail['reason'] ?? null) ? ['now' => $e->detail['reason']] : [];
                $left[] = ['id' => $id, 'why' => ['code' => 'changed', 'error' => $e instanceof CwException ? $e->errorCode : 'failed'] + $now];
                continue;
            }
            $confirmed[] = $id;
            if ($r['replayed']) {
                $replayed[] = $id;
                continue;
            }
            $booked += $r['delta'] === 0 ? 0 : 1;
            $added += max(0, $r['delta']);
            $taken += max(0, -$r['delta']);
        }
        $listed = array_column($left, 'id');
        foreach ($this->readiness() as $r) {
            if ($r['why'] !== null && !in_array((int) $r['id'], $listed, true)) {
                $left[] = ['id' => (int) $r['id'], 'why' => $r['why']];
            }
        }
        Audit::write($this->db, $caller, self::RUN_ACTION, self::TABLE, null, null, ['note' => $note, 'asked' => count($ids), 'confirmed' => $confirmed,
            'replayed' => $replayed, 'booked' => $booked, 'units_added' => $added, 'units_taken' => $taken, 'left' => $left]);
        return ['run' => (int) $this->db->value('SELECT LAST_INSERT_ID()'), 'confirmed' => $confirmed, 'left' => $left];
    }

    /** The moment ERPNext set the number: the relayed posted_at, else when CW received it (the database's form). @param array<string, mixed> $row */
    public static function at(array $row): string
    {
        $p = is_array($row['detail'] ?? null) ? ($row['detail']['posted_at'] ?? null) : null;
        if (is_string($p) && $p !== '') {
            return $p;
        }
        return Clock::db(Clock::fromDb((string) $row['created_at']));
    }
}
