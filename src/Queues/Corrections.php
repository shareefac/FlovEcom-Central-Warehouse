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
    /**
     * The press's one deadline, in seconds (the UI pool stops a page at 50; CA6, CA15, CA23): the ids and all their waits end
     * BULK_FINAL_SECONDS before it, the end (what needs a look now, the end row) in that reserve. An id is started only while the time
     * left for the ids gives each of the BULK_WAITS waits it may meet at least a second; each wait then gets its share of that time, at
     * most BULK_LOCK_WAIT seconds, set again before every try (a retry after a deadlock too, which is not made once the time is gone).
     */
    public const BULK_SECONDS = 40;
    /** The longest one lock or key wait of a press (CA15, CA23). */
    public const BULK_LOCK_WAIT = 5;
    /** The time kept for the end of a press (CA15, CA23). */
    public const BULK_FINAL_SECONDS = 5;
    /**
     * The most lock waits one confirmation may meet (CA23): its key, its queue row, its balance, the item's row, the limit's setting, the
     * value clock, the feed clock, and one to spare.
     */
    public const BULK_WAITS = 8;
    /** The setting of the big-change rule (0054; CA3), and what is used while it is not there yet. */
    public const BIG_SETTING = 'relay.correction_bulk_max_units';
    public const BIG_DEFAULT = 100;
    /** Why an open correction needs a look (CA2), in the order they are checked: the first that applies is its reason (why()). */
    public const NOT_READY = ['counted', 'newer', 'big', 'below_zero', 'variants', 'fixed'];
    /** The doc_ref of the one-time stock fix (cw_listening_fix.php: listening-fix:<channel>:<date>, its undo <that>:undo). */
    public const FIX_PREFIX = 'listening-fix:';
    /**
     * The gathered reads of the list (effects(), facts(); CA17): one branch of a UNION ALL per moment, per balance and bucket (effect()'s own
     * statements), and one IN list of (place, item) or (item, place) pairs (%s). Public for the plan test (QueueListPlansTest).
     */
    public const LAST_AT_SQL = '(SELECT ? AS k, id FROM stock_ledger WHERE created_at <= ? ORDER BY created_at DESC, id DESC LIMIT 1)';
    public const BUCKET_AT_SQL = '(SELECT ? AS k, ? AS b, balance_after FROM stock_ledger FORCE INDEX (ix_stock_ledger_balance) WHERE warehouse_id = ? AND sku_id = ? '
        . 'AND id <= ? AND bucket = ? ORDER BY id DESC LIMIT 1)';
    public const CONFIRMED_SQL = "SELECT id, sku_id, warehouse_id, CAST(detail AS CHAR) AS detail, created_at, resolved_at FROM count_review WHERE source = ? "
        . "AND status = 'resolved' AND (sku_id, warehouse_id) IN (%s)";
    public const BALANCE_SQL = 'SELECT warehouse_id, sku_id, on_hand, counted_at FROM stock_balance WHERE (warehouse_id, sku_id) IN (%s)';
    /**
     * The fixes of the items (CA20 (a), CW-913): one range read of the fixes' own rows by doc_ref (ix_stock_ledger_doc_ref, migration 0056),
     * not each item's whole ledger at its place.
     */
    public const FIX_SQL = 'SELECT warehouse_id, sku_id, MAX(created_at) AS at FROM stock_ledger FORCE INDEX (ix_stock_ledger_doc_ref) '
        . "WHERE (warehouse_id, sku_id) IN (%s) AND bucket = 'on_hand' AND movement_type = 'adjustment' AND doc_ref LIKE ? GROUP BY warehouse_id, sku_id";
    /** When the last one-time stock fix was booked (CA25): its bookings' audit rows (entity movement, id = its doc_ref), by their index. */
    public const LATEST_FIX_SQL = 'SELECT MAX(created_at) FROM audit_log FORCE INDEX (ix_audit_entity) WHERE entity_type = ? AND entity_id LIKE ?';

    /** How many open corrections the list reads at once (each chunk: one statement per read below). */
    public const CHUNK = 200;
    /**
     * The most statements readiness() runs (NightlyConfirmTest and ConfirmAllTest hold it to them): READ_FIXED (the settings, the list, its
     * names and stores, the last fix) and READ_PER_CHUNK per CHUNK rows (three for effects(), three for facts()), with spare. Few and fixed,
     * so a read under a deadline (Db::readsUntil) is a few statements, each checked against it.
     */
    public const READ_FIXED = 8;
    public const READ_PER_CHUNK = 6;

    /** The audit row of one press of Confirm all, written before its first id (CA7, CA15): its id is the done page's. */
    public const RUN_ACTION = 'count_review.confirm_all';
    /** The audit row of the end of a press, written after its last id (entity count_review, id "confirm_all:<run>"; CA15). */
    public const RUN_END_ACTION = 'count_review.confirm_all_end';
    /** The nightly Confirm all (docs/decisions.md NC1-NC12): its job's own system caller, its switch, and how long one night's press may take. */
    public const NIGHTLY_JOB = 'confirm_nightly';
    public const NIGHTLY_SWITCH = 'relay.nightly_confirm';
    public const NIGHTLY_SECONDS = 240;

    /** @var \Closure(): float seconds (a press's deadline; tests move it) */
    private \Closure $clock;
    /** How long a confirmation waits for its key (Idempotency): the default, or its share of the time while a press runs (CA15, CA23). */
    private int $keyWait = Idempotency::KEY_LOCK_WAIT_SEC;
    /** While a press runs: the moment (by $clock) its ids' work must end, the longest one wait, and the press (its run row) (CA22, CA23). */
    private ?float $deadline = null;
    private int $bulkWait = self::BULK_LOCK_WAIT;
    private ?int $press = null;
    /** True only while confirmNightly() runs: the one way the nightly job's caller is let through (authorise(); NC4). */
    private bool $nightly = false;

    /** @param (\Closure(): float)|null $clock seconds, for the press's deadline (default: the monotonic clock) */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function openCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM count_review FORCE INDEX (ix_count_review_open) WHERE status = 'open' AND source = ?", [self::SOURCE]);
    }

    /**
     * The list: open rows oldest first (each with its effect(), read for all rows at once: effects()), or every row of a status, newest
     * first. $only: that one row (Approvals opening a correction beyond the first rows it shows, CA18). $after: the open rows after that id
     * (the nightly job reading the queue page by page, NC13).
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $status = 'open', int $limit = QueueRules::PAGE + 1, ?int $only = null, ?int $after = null): array
    {
        $limit = max(1, min(500, $limit));
        $params = $status === null ? [self::SOURCE] : [self::SOURCE, $status];
        if ($only !== null) {
            $params[] = $only;
        }
        if ($after !== null) {
            $params[] = $after;
        }
        $rows = $this->db->all(
            'SELECT STRAIGHT_JOIN r.id, r.warehouse_id, w.code AS warehouse_code, w.name AS warehouse, r.sku_id, s.code AS sku_code, s.name AS sku_name, r.proposed_qty, r.ref, '
            . 'CAST(r.detail AS CHAR) AS detail, CAST(r.status AS CHAR) AS status, r.resolution, r.resolved_by, r.resolved_at, r.note, r.created_at '
            . 'FROM count_review r' . ($status === null || $only !== null ? '' : ' FORCE INDEX (ix_count_review_open)') . ' JOIN sku s ON s.id = r.sku_id JOIN warehouse w ON w.id = r.warehouse_id '
            . 'WHERE r.source = ?' . ($status === null ? '' : ' AND r.status = ?') . ($only === null ? '' : ' AND r.id = ?') . ($after === null ? '' : ' AND r.id > ?')
            . ' ORDER BY r.id' . ($status === 'open' ? '' : ' DESC') . " LIMIT {$limit}",
            $params,
        );
        $names = QueueRules::names($this->db, array_column($rows, 'resolved_by'));
        $stores = QueueRules::stores($this->db);
        foreach ($rows as &$r) {
            $d = json_decode((string) $r['detail'], true);
            $r['detail'] = is_array($d) ? $d : [];
            $r['at'] = self::at($r);
            $r['store'] = isset($r['detail']['channel_id']) ? ($stores[(int) $r['detail']['channel_id']] ?? null) : null;
            $r['resolved_by_name'] = $r['resolved_by'] === null ? null : ($names[(int) $r['resolved_by']] ?? null);
        }
        unset($r);
        $effects = $this->effects(array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === 'open')), new Settings($this->db));
        foreach ($rows as &$r) {
            $r['effect'] = $r['status'] === 'open' ? $effects[(int) $r['id']] : null;
        }
        unset($r);
        return $rows;
    }

    /**
     * effect() of many open rows at once (the lists; CA17, Codex MEDIUM 5): the same figures from the same rows, effect()'s own statements
     * gathered into three per 200 rows: the last ledger row booked by each moment, both buckets of each balance at that row, the confirmed
     * corrections of the items. A booking never uses this: confirm() reads effect() itself, under its locks.
     *
     * @param list<array<string, mixed>> $rows open rows with id, warehouse_id, sku_id, proposed_qty and `at`
     * @return array<int, array{shelf: int, allocated: int, target: int, delta: int, for_sale: bool, carried: int, newer: bool}> by row id
     */
    private function effects(array $rows, Settings $settings): array
    {
        $forSale = self::forSale($settings);
        $out = [];
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            // 1. The last ledger row booked by each moment: effect()'s statement, once per moment, in one UNION ALL.
            $ats = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['at'], $chunk)));
            $last = [];
            foreach ($this->db->all(implode(' UNION ALL ', array_fill(0, count($ats), self::LAST_AT_SQL)), array_merge(...array_map(static fn (int $i, string $at): array => [$i, $at],
                array_keys($ats), $ats))) as $l) {
                $last[$ats[(int) $l['k']]] = (int) $l['id'];
            }
            // 2. Both buckets of each balance at its moment's row: effect()'s statement per balance and bucket, in one UNION ALL.
            $keys = [];
            foreach ($chunk as $r) {
                $cut = $last[(string) $r['at']] ?? null;
                if ($cut !== null) {
                    $keys[(int) $r['warehouse_id'] . ':' . (int) $r['sku_id'] . ':' . $cut] = [(int) $r['warehouse_id'], (int) $r['sku_id'], $cut];
                }
            }
            $buckets = [];
            if ($keys !== []) {
                $list = array_values($keys);
                $params = [];
                foreach ($list as $i => [$wh, $sku, $cut]) {
                    foreach (['on_hand', 'allocated'] as $b) {
                        array_push($params, $i, $b, $wh, $sku, $cut, $b);
                    }
                }
                foreach ($this->db->all(implode(' UNION ALL ', array_fill(0, 2 * count($list), self::BUCKET_AT_SQL)), $params) as $x) {
                    [$wh, $sku, $cut] = $list[(int) $x['k']];
                    $buckets["{$wh}:{$sku}:{$cut}"][(string) $x['b']] = (int) $x['balance_after'];
                }
            }
            // 3. The confirmed corrections of the items (confirmedOf()), all at once.
            $pairs = [];
            foreach ($chunk as $r) {
                $pairs[(int) $r['sku_id'] . ':' . (int) $r['warehouse_id']] = [(int) $r['sku_id'], (int) $r['warehouse_id']];
            }
            $confirmed = [];
            foreach ($this->db->all(sprintf(self::CONFIRMED_SQL, implode(', ', array_fill(0, count($pairs), '(?, ?)'))),
                [self::SOURCE, ...array_merge(...array_values($pairs))]) as $c) {
                $confirmed[(int) $c['sku_id'] . ':' . (int) $c['warehouse_id']][] = ['id' => (int) $c['id']] + self::confirmedRow($c);
            }
            foreach ($chunk as $r) {
                $cut = $last[(string) $r['at']] ?? null;
                $b = $cut === null ? [] : ($buckets[(int) $r['warehouse_id'] . ':' . (int) $r['sku_id'] . ':' . $cut] ?? []);
                $mine = array_values(array_filter($confirmed[(int) $r['sku_id'] . ':' . (int) $r['warehouse_id']] ?? [],
                    static fn (array $c): bool => $c['id'] !== (int) $r['id']));
                $out[(int) $r['id']] = self::fold((int) $r['proposed_qty'], (int) ($b['on_hand'] ?? 0), (int) ($b['allocated'] ?? 0), $mine, (string) $r['at'], $forSale);
            }
        }
        return $out;
    }

    /** Whether a correction is the figure for sale (relay.correction_is_for_sale; on while the setting is not there). */
    private static function forSale(Settings $settings): bool
    {
        return !$settings->has('relay.correction_is_for_sale') || $settings->get('relay.correction_is_for_sale') === true;
    }

    /**
     * effect()'s arithmetic, shared by effect() and effects(): what the item held then (plus the corrections carried), the shelf figure
     * the correction stands for, the difference.
     *
     * @param list<array{at: string, booked_at: string, delta: int}> $confirmed the item's other confirmed corrections
     * @return array{shelf: int, allocated: int, target: int, delta: int, for_sale: bool, carried: int, newer: bool}
     */
    private static function fold(int $units, int $onHand, int $allocated, array $confirmed, string $at, bool $forSale): array
    {
        $carried = 0;
        $newer = false;
        foreach ($confirmed as $c) {
            if ($c['at'] > $at) {
                $newer = true;
            } elseif ($c['booked_at'] > $at) {
                $carried += $c['delta'];
            }
        }
        $shelf = $onHand + $carried;
        $target = $units + ($forSale ? max(0, $allocated) : 0);
        return ['shelf' => $shelf, 'allocated' => $allocated, 'target' => $target, 'delta' => $target - $shelf, 'for_sale' => $forSale, 'carried' => $carried,
            'newer' => $newer];
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
        // The last ledger row booked by then (the created_at index, one row), then each bucket's last row of this balance at or before it
        // (the balance's (warehouse, item, id) index, read backwards from there).
        $last = $this->db->value('SELECT id FROM stock_ledger WHERE created_at <= ? ORDER BY created_at DESC, id DESC LIMIT 1', [$at]);
        $bucket = fn (string $b): int => $last === null ? 0 : (int) ($this->db->value(
            'SELECT balance_after FROM stock_ledger FORCE INDEX (ix_stock_ledger_balance) WHERE warehouse_id = ? AND sku_id = ? AND id <= ? AND bucket = ? ORDER BY id DESC LIMIT 1',
            [$warehouseId, $skuId, (int) $last, $b],
        ) ?? 0);
        return self::fold($units, $bucket('on_hand'), $bucket('allocated'), $this->confirmedOf($warehouseId, $skuId, $self), $at, self::forSale($settings));
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
            $out[] = self::confirmedRow($r);
        }
        return $out;
    }

    /** A confirmed correction as confirmedOf() gives it. @param array<string, mixed> $r @return array{at: string, booked_at: string, delta: int} */
    private static function confirmedRow(array $r): array
    {
        $d = json_decode((string) $r['detail'], true);
        $d = is_array($d) ? $d : [];
        $booked = is_array($d['booked'] ?? null) ? $d['booked'] : [];
        return ['at' => self::at(['detail' => $d, 'created_at' => $r['created_at']]), 'booked_at' => (string) ($booked['at'] ?? $r['resolved_at']),
            'delta' => (int) ($booked['delta'] ?? 0)];
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
        $this->authorise($this->db, $caller);
        $key = self::KEY . $id;
        // The press this confirmation belongs to (CA22): marked on what it books, never part of the key's request (a replay still matches).
        $press = $this->press;
        $r = (new Idempotency($this->db, $this->keyWait))->run($caller, $key, 'queue.correction.confirm', '/ui/queues/corrections/' . $id, ['id' => $id, 'note' => $note],
            self::TABLE, (string) $id, function (Db $db) use ($caller, $id, $note, $key, $whenReady, $press): OpResult {
                if ($this->deadline !== null) {
                    // CA23: a press's try (the first, or one after a deadlock) starts only with time left for all its waits, each its share.
                    $wait = $this->share($this->deadline);
                    if ($wait === null) {
                        throw new CwException('out_of_time', 'the press has no time left for this one: run it again', 409);
                    }
                    $db->exec('SET SESSION innodb_lock_wait_timeout = ' . $wait);
                }
                $this->authorise($db, $caller);
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
                    // CA13 (Codex HIGH 2): the item's row FOR UPDATE, after its balance (the stock core's lock order, as Stock::setPolicy and
                    // Reservations take them). Every link change reads that row FOR SHARE first (DecisionService::lockSkus), so no link of
                    // the item changes until this commits, and a link being made is waited for and then counted (READ COMMITTED).
                    $db->one('SELECT id FROM sku WHERE id = ? FOR UPDATE', [(int) $row['sku_id']]);
                    // CA14 (Codex HIGH 3): the limit is read FOR SHARE and held to the commit: a change of it waits for this booking.
                    $why = self::why($at, $e, self::facts($db, [$row])[$id], self::lockedLimit($db));
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
                if ($press === null) {
                    $db->exec("UPDATE count_review SET detail = JSON_SET(COALESCE(detail, JSON_OBJECT()), '$.booked', JSON_OBJECT('delta', ?, 'at', ?)) WHERE id = ?",
                        [$e['delta'], $bookedAt, $id]);
                } else {
                    $db->exec("UPDATE count_review SET detail = JSON_SET(COALESCE(detail, JSON_OBJECT()), '$.booked', JSON_OBJECT('delta', ?, 'at', ?, 'run', ?)) WHERE id = ?",
                        [$e['delta'], $bookedAt, $press, $id]);
                }
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
     * confirm() reads the same facts again for the one row, under its locks. $after: the open ones after that id (NC13).
     *
     * @return list<array<string, mixed>>
     */
    public function readiness(int $limit = self::BULK_MAX, ?int $only = null, ?int $after = null): array
    {
        $units = self::bigLimit(new Settings($this->db));
        $rows = $this->list('open', max(1, min($limit, self::BULK_MAX)), $only, $after);
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

    /** bigLimit() read FOR SHARE inside a booking's transaction (CA14): the setting's row stays as read until the booking commits. */
    private static function lockedLimit(Db $db): int
    {
        $v = $db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ? FOR SHARE', [self::BIG_SETTING]);
        $d = $v === null ? null : json_decode((string) $v, true);
        return is_int($d) && $d >= 0 ? $d : self::BIG_DEFAULT;
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
        // CA25 (Codex re-review LOW 4): when the last one-time stock fix was booked, read once from the audit log's entity index (each fix
        // booking writes its audit row in its own transaction, after its ledger row: entity movement, id = its doc_ref). A correction made
        // at or after that moment cannot be "made before a fix": its ledger is not read for rule f; the others' still is.
        $lastFix = $rows === [] ? null : $db->value(self::LATEST_FIX_SQL, ['movement', self::FIX_PREFIX . '%']);
        $lastFix = $lastFix === null ? null : (string) $lastFix;
        $out = [];
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $pairs = [];
            $skus = [];
            foreach ($chunk as $r) {
                $pairs[(int) $r['warehouse_id'] . ':' . (int) $r['sku_id']] = [(int) $r['warehouse_id'], (int) $r['sku_id']];
                $skus[(int) $r['sku_id']] = (int) $r['sku_id'];
            }
            $in = implode(', ', array_fill(0, count($pairs), '(?, ?)'));
            $flat = array_merge(...array_values($pairs));
            $balance = [];
            foreach ($db->all(sprintf(self::BALANCE_SQL, $in), $flat) as $b) {
                $balance[(int) $b['warehouse_id'] . ':' . (int) $b['sku_id']] = $b;
            }
            $links = [];
            foreach ($db->all('SELECT channel_id, sku_id, COUNT(*) AS n FROM channel_listing WHERE sku_id IN (' . implode(', ', array_fill(0, count($skus), '?'))
                . ') GROUP BY channel_id, sku_id', array_values($skus)) as $l) {
                $links[(int) $l['sku_id']][(int) $l['channel_id']] = (int) $l['n'];
            }
            $fixed = [];
            $before = [];
            foreach ($chunk as $r) {
                if ($lastFix !== null && (string) ($r['at'] ?? self::at($r)) < $lastFix) {
                    $before[(int) $r['warehouse_id'] . ':' . (int) $r['sku_id']] = [(int) $r['warehouse_id'], (int) $r['sku_id']];
                }
            }
            if ($before !== []) {
                foreach ($db->all(sprintf(self::FIX_SQL, implode(', ', array_fill(0, count($before), '(?, ?)'))), [...array_merge(...array_values($before)),
                    self::FIX_PREFIX . '%']) as $f) {
                    $fixed[(int) $f['warehouse_id'] . ':' . (int) $f['sku_id']] = (string) $f['at'];
                }
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
     * One press of "Confirm all ready" (CA5-CA7, CA15, CA22-CA24): each id of the check page, in its order, confirmed exactly as one by one
     * (confirm(): the same caller, key, request, ledger line and audit rows) when it is still READY at that moment, under its locks
     * ($whenReady). Any refusal (it changed: counted, confirmed or closed by someone, a rule applies now) or a database failure of that one
     * id (rolled back whole by its own transaction) leaves it for a person, nothing of it booked, and the rest go on.
     *
     * The press is saved FIRST, as its run row (RUN_ACTION: who, when, the note, the ids it was given; the done page's id); each confirmation
     * it books carries the run's id (count_review.detail.booked.run, in its own transaction), so a press that stops half way shows exactly
     * what it booked (run()); its end row (RUN_END_ACTION) after the last id says what it did.
     *
     * Time (CA23): one deadline, $maxSeconds from the start. The ids and their waits end BULK_FINAL_SECONDS before it; an id, and every try
     * of it (a retry after a deadlock too), starts only while that time gives each of its BULK_WAITS waits a second, and its key's wait and
     * the session's lock wait are its share of what is left (at most $lockWait). The rest are "not reached: run it again". The end (what needs
     * a look now, the end row) runs in the reserve: the list's reads are stopped at the deadline (max_execution_time) and left out of the end
     * row if they are, never the end row. The session's lock wait and statement time come back exactly as they were, however the press ends.
     * A second press of the same page books nothing again: each id's key replays its first answer.
     *
     * @param list<int> $ids
     * @return array{run: int, confirmed: list<int>, left: list<array{id: int, why: array<string, mixed>}>}
     */
    public function confirmAll(Caller $caller, array $ids, string $note, int $maxIds = self::BULK_MAX, float $maxSeconds = self::BULK_SECONDS,
        int $lockWait = self::BULK_LOCK_WAIT): array
    {
        return $this->press($caller, $ids, $note, $maxIds, $maxSeconds, $lockWait, null);
    }

    /**
     * The nightly Confirm all's press (docs/decisions.md NC3, NC4; NightlyConfirm::tick() calls it, once a night, under the night's lock):
     * confirmAll() exactly, as the nightly job's own system caller, with the night's mark on its run row (entity `nightly:<date>`), at most
     * $maxIds (relay.nightly_confirm_max) within $maxSeconds. Refused (409 nightly_off) while the switch is off; each confirmation reads the
     * switch again, so switching it off stops the rest of the night ("not reached"). Only here is the job's caller let through (authorise()).
     *
     * @param list<int> $ids
     * @return array{run: int, confirmed: list<int>, left: list<array{id: int, why: array<string, mixed>}>}
     */
    public function confirmNightly(string $night, array $ids, string $note, int $maxIds, float $maxSeconds = self::NIGHTLY_SECONDS): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $night) !== 1) {
            throw new \InvalidArgumentException('the night is a date, YYYY-MM-DD');
        }
        if (!Settings::flag($this->db, self::NIGHTLY_SWITCH, false)) {
            throw new CwException('nightly_off', 'the nightly Confirm all is switched off', 409);
        }
        $this->nightly = true;
        try {
            return $this->press(Caller::system(self::NIGHTLY_JOB), $ids, $note, $maxIds, $maxSeconds, self::BULK_LOCK_WAIT, 'nightly:' . $night);
        } finally {
            $this->nightly = false;
        }
    }

    /** Whether $caller is the nightly job's own system caller (bin/confirm_corrections_nightly.php), and nobody else. */
    public static function isNightly(Caller $caller): bool
    {
        return $caller->channelId === null && $caller->staffUserId === null && $caller->actor === 'system:' . self::NIGHTLY_JOB
            && $caller->source === 'system:' . self::NIGHTLY_JOB;
    }

    /**
     * Who may confirm (NC4): a person with queues.corrections (QueueRules::authorise: their roles re-read, inside the transaction too); and,
     * only while confirmNightly() runs, the nightly job's own system caller while the switch is on (read on every call). Any other system
     * job, a store, and the nightly job's caller anywhere else (one by one, Confirm all, closing: dismiss() asks QueueRules alone) are refused
     * as before (403 staff_required).
     */
    private function authorise(Db $db, Caller $caller): void
    {
        if ($this->nightly && self::isNightly($caller)) {
            if (!Settings::flag($db, self::NIGHTLY_SWITCH, false)) {
                throw new CwException('nightly_off', 'the nightly Confirm all was switched off', 409);
            }
            return;
        }
        QueueRules::authorise($db, $caller, self::PERM);
    }

    /**
     * One press (confirmAll(), confirmNightly()): the class docblock of confirmAll(); $entity marks the run row (a night's: `nightly:<date>`).
     *
     * @param list<int> $ids
     * @return array{run: int, confirmed: list<int>, left: list<array{id: int, why: array<string, mixed>}>}
     */
    private function press(Caller $caller, array $ids, string $note, int $maxIds, float $maxSeconds, int $lockWait, ?string $entity): array
    {
        $note = QueueRules::note($note);
        $this->authorise($this->db, $caller);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $started = ($this->clock)();
        $deadline = $started + $maxSeconds;
        $workUntil = $deadline - self::BULK_FINAL_SECONDS;
        Audit::write($this->db, $caller, self::RUN_ACTION, self::TABLE, $entity, null, ['state' => 'running', 'note' => $note, 'asked' => count($ids), 'ids' => $ids]);
        $run = (int) $this->db->value('SELECT LAST_INSERT_ID()');
        $confirmed = [];
        $replayed = [];
        $left = [];
        $booked = 0;
        $added = 0;
        $taken = 0;
        // The session as it was (CA24): given back exactly, on every way out.
        $waitWas = (int) $this->db->value('SELECT @@SESSION.innodb_lock_wait_timeout');
        $timeWas = (int) $this->db->value('SELECT @@SESSION.max_execution_time');
        try {
            $this->bulkWait = max(1, $lockWait);
            $this->press = $run;
            foreach ($ids as $i => $id) {
                $wait = $i < $maxIds ? $this->share($workUntil) : null;
                if ($wait === null) {
                    $left[] = ['id' => $id, 'why' => ['code' => 'not_reached']];
                    continue;
                }
                $this->keyWait = $wait;
                $this->db->exec('SET SESSION innodb_lock_wait_timeout = ' . $wait);
                $this->deadline = $workUntil;
                try {
                    $r = $this->confirm($caller, $id, $note, true);
                } catch (\RuntimeException $e) {
                    // CwException (a refusal, or no time left for its try) or a database failure of this one (a lock waited for too long, a
                    // deadlock given up, a statement that failed): its own transaction rolled it back whole; it is left, the rest go on.
                    $code = $e instanceof CwException ? $e->errorCode : 'failed';
                    $now = $e instanceof CwException && is_array($e->detail['reason'] ?? null) ? ['now' => $e->detail['reason']] : [];
                    $left[] = ['id' => $id, 'why' => ['code' => in_array($code, ['out_of_time', 'nightly_off'], true) ? 'not_reached' : 'changed', 'error' => $code] + $now];
                    continue;
                } finally {
                    $this->deadline = null;
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
            // The end, in its reserve (CW-111): before each of the list's reads, the time left until the deadline is its time
            // (Db::readsUntil), so they all end by it, however long the ones before took; none is started once it has passed. The end row
            // is always written.
            $this->press = null;
            $this->db->exec('SET SESSION innodb_lock_wait_timeout = 1');
            $unlisted = false;
            try {
                $listed = array_column($left, 'id');
                foreach ($this->db->readsUntil($deadline, $this->clock, fn (): array => $this->readiness()) as $r) {
                    if ($r['why'] !== null && !in_array((int) $r['id'], $listed, true)) {
                        $left[] = ['id' => (int) $r['id'], 'why' => $r['why']];
                    }
                }
            } catch (\RuntimeException) {
                $unlisted = true; // the list of what needs a look now did not fit: the end row says so, Approvals lists them
            }
            Audit::write($this->db, $caller, self::RUN_END_ACTION, self::TABLE, 'confirm_all:' . $run, null, ['state' => 'finished', 'run' => $run,
                'confirmed' => $confirmed, 'replayed' => $replayed, 'booked' => $booked, 'units_added' => $added, 'units_taken' => $taken, 'left' => $left]
                + ($unlisted ? ['unlisted' => true] : []));
        } finally {
            $this->press = null;
            $this->deadline = null;
            $this->keyWait = Idempotency::KEY_LOCK_WAIT_SEC;
            $this->bulkWait = self::BULK_LOCK_WAIT;
            $this->db->exec('SET SESSION innodb_lock_wait_timeout = ' . $waitWas);
            $this->db->exec('SET SESSION max_execution_time = ' . $timeWas);
        }
        return ['run' => $run, 'confirmed' => $confirmed, 'left' => $left];
    }

    /** The most statements readiness() runs for $rows open corrections (READ_FIXED, READ_PER_CHUNK). */
    public static function readStatements(int $rows): int
    {
        return self::READ_FIXED + self::READ_PER_CHUNK * max(1, (int) ceil(min($rows, self::BULK_MAX) / self::CHUNK));
    }

    /**
     * One wait's share of the time left until $until (CA23): that time shared by the BULK_WAITS waits one confirmation may meet, at most the
     * press's longest wait; null when that is less than a second each (nothing more is started).
     */
    private function share(float $until): ?int
    {
        $left = $until - ($this->clock)();
        return $left < self::BULK_WAITS ? null : max(1, min($this->bulkWait, (int) floor($left / self::BULK_WAITS)));
    }

    /**
     * A press as saved (CA15, CA22): its run row (who, when, the note, the ids it was given) with its end row (`finished`: what it did); a
     * press that stopped before its end (`stopped`: no end row) as its own marks show it: the ids whose booking carries this run's id
     * (count_review.detail.booked.run, written in the booking's transaction), the rest "not reached, or answered elsewhere"; a night's run
     * with no end row yet while its job still runs (the night's lock held, the run younger than the job's longest run; NC16) is `running`,
     * its marks so far. null: no such press.
     *
     * `actor` is who pressed (a person's staff:<id>, or the nightly job's system:confirm_nightly), `night` the night of a nightly run (NC3).
     *
     * @return array{id: int, actor: string, staff_user_id: ?int, night: ?string, created_at: string, state: string, note: string, asked: int, confirmed: list<int>, replayed: list<int>,
     *               booked: int, units_added: int, units_taken: int, left: list<array{id: int, why: array<string, mixed>}>}|null
     */
    public function run(int $runId): ?array
    {
        $start = $this->db->one('SELECT id, actor, staff_user_id, entity_id, CAST(detail AS CHAR) AS detail, created_at FROM audit_log WHERE id = ? AND action = ?',
            [$runId, self::RUN_ACTION]);
        if ($start === null) {
            return null;
        }
        $ints = static fn (mixed $v): array => is_array($v) ? array_values(array_map('intval', array_filter($v, 'is_numeric'))) : [];
        $s = json_decode((string) $start['detail'], true);
        $s = is_array($s) ? $s : [];
        $out = ['id' => $runId, 'actor' => (string) $start['actor'], 'staff_user_id' => $start['staff_user_id'] === null ? null : (int) $start['staff_user_id'],
            'night' => is_string($start['entity_id']) && str_starts_with($start['entity_id'], 'nightly:') ? substr($start['entity_id'], 8) : null,
            'created_at' => (string) $start['created_at'], 'note' => (string) ($s['note'] ?? ''), 'asked' => (int) ($s['asked'] ?? 0)];
        $end = $this->db->value('SELECT CAST(detail AS CHAR) FROM audit_log WHERE entity_type = ? AND entity_id = ? AND action = ? ORDER BY id LIMIT 1',
            [self::TABLE, 'confirm_all:' . $runId, self::RUN_END_ACTION]);
        if ($end !== null) {
            $e = json_decode((string) $end, true);
            $e = is_array($e) ? $e : [];
            $left = array_values(array_filter(is_array($e['left'] ?? null) ? $e['left'] : [], static fn (mixed $l): bool => is_array($l) && isset($l['id'])));
            return $out + ['state' => 'finished', 'confirmed' => $ints($e['confirmed'] ?? []), 'replayed' => $ints($e['replayed'] ?? []), 'booked' => (int) ($e['booked'] ?? 0),
                'units_added' => (int) ($e['units_added'] ?? 0), 'units_taken' => (int) ($e['units_taken'] ?? 0),
                'left' => array_map(static fn (array $l): array => ['id' => (int) $l['id'], 'why' => is_array($l['why'] ?? null) ? $l['why'] : []], $left)];
        }
        // Stopped before its end: what its own marks show (CA22). A confirmation of one of its ids made otherwise (one by one, another press)
        // carries no mark of this run, whoever made it and with whatever note.
        $ids = $ints($s['ids'] ?? []);
        $mine = [];
        if ($ids !== []) {
            foreach ($this->db->all('SELECT id, CAST(detail AS CHAR) AS detail FROM count_review WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ") "
                . "AND source = ? AND status = 'resolved'", [...$ids, self::SOURCE]) as $r) {
                $d = json_decode((string) $r['detail'], true);
                $b = is_array($d) && is_array($d['booked'] ?? null) ? $d['booked'] : [];
                if (isset($b['run']) && (int) $b['run'] === $runId) {
                    $mine[(int) $r['id']] = (int) ($b['delta'] ?? 0);
                }
            }
        }
        return $out + ['state' => self::running($this->db, $out) ? 'running' : 'stopped', 'confirmed' => array_values(array_filter($ids, static fn (int $id): bool => isset($mine[$id]))), 'replayed' => [],
            'booked' => count(array_filter($mine)), 'units_added' => array_sum(array_map(static fn (int $d): int => max(0, $d), $mine)),
            'units_taken' => array_sum(array_map(static fn (int $d): int => max(0, -$d), $mine)),
            'left' => array_values(array_map(static fn (int $id): array => ['id' => $id, 'why' => ['code' => 'not_reached_or_elsewhere']],
                array_filter($ids, static fn (int $id): bool => !isset($mine[$id]))))];
    }

    /**
     * Whether a run with no end row is a night's run still going (NC16): the night's lock is held (NightlyConfirm::tick() holds it for the
     * whole run) and the run started less than the job's longest run (NIGHTLY_SECONDS, and a minute to spare) ago. Any other: stopped.
     *
     * @param array{night: ?string, created_at: string} $run
     */
    private static function running(Db $db, array $run): bool
    {
        if ($run['night'] === null || Clock::fromDb($run['created_at'])->getTimestamp() < Clock::now()->getTimestamp() - self::NIGHTLY_SECONDS - 60) {
            return false;
        }
        return $db->value('SELECT IS_USED_LOCK(?)', [NightlyConfirm::lockName($db)]) !== null;
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
