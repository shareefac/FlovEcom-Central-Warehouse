<?php

declare(strict_types=1);

namespace CW;

use DateTimeImmutable;

/**
 * The reservation state machine on (channel, order_ref) (plan §3, §4, §8.1).
 *
 *   reserve   new -> held (201) | refused (409, per-line available); held + same lines -> TTL
 *             extended; held + other lines -> 422; released/expired -> new attempt (attempt+1)
 *             or 409; tombstone -> 409 (no hold); committed -> 200 already_paid.
 *   commit    held -> allocated; also without a hold (outage, late or resurrected payments):
 *             creates/revives the reservation and snapshots today's links. Never refused;
 *             a strict/stopped item pushed below zero -> oversell_event. Body lines win.
 *   release   held -> released; stale attempt ignored; committed -> 409 use_cancel;
 *             unknown ref -> released tombstone.
 *   cancel    allocated (or held) -> cancelled; restockable=false moves the units to VERIFY
 *             and opens a recount (never a write-off).
 *   ship      allocated -> shipped: allocated -u, on_hand -u; dispatched at/before the last
 *             count's counted_at: allocated -u only ("pre_count"); within ±10 min: count review.
 *   unship    shipped -> allocated: allocated +u, on_hand +u; a reset at/before the last
 *             count's counted_at: allocated +u only ("pre_count"); within ±10 min: count review.
 *   return    shipped -> returned: on_hand +u, once per unit.
 *             ship / unship / return need a committed order; before the commit they throw
 *             409 not_committed, which is not stored (the site retries the same key).
 *   expire    held past expires_at -> expired (cron; no idempotency key needed).
 *
 * Line kinds (§2.3): unlinked lines (listing not mapped/quarantined) are recorded with
 * sku NULL and move no bucket; linked lines move buckets whatever the policy; only when the
 * channel is `live` does CW refuse: strict when short, stopped items and quarantined listings
 * always (D31). One reservation_unit row per sold unit snapshots listing, sku, u and warehouse,
 * so every later reversal uses the link as it was at sale time.
 *
 * Every public operation is idempotent through Idempotency (same key -> same stored result).
 *
 * Caller-reported times (dispatched_at, a reset's `at`, T0) must be plausible (R5): at most
 * Clock::MAX_AHEAD_SEC ahead of CW's clock and at most EVENT_MAX_AGE_SEC old; a reset must be
 * later than the dispatch it reverses. An order has at most MAX_ORDER_UNITS units (R15).
 */
final class Reservations
{
    public const ORIGINS = ['reserved', 'unreserved', 'opening'];
    public const MAX_LINES = 500;
    /** Units per opening_orders call (D40). */
    public const MAX_UNITS = 20_000;
    /**
     * Units per order (reserve, commit, one opening order) and per unit operation (R15). Checked
     * before any lock: every unit is one row, one UPDATE and one ledger row under the item's
     * balance lock, so an unbounded order would hold that item for every other site.
     */
    public const MAX_ORDER_UNITS = 1_000;
    /** Oldest dispatch / reset time accepted (R5): the connector's nightly backstop looks back 30 days. */
    public const EVENT_MAX_AGE_SEC = 90 * 86_400;
    /** attempt is INT UNSIGNED. */
    public const MAX_ATTEMPT = 4_294_967_295;
    private const ER_DUP_ENTRY = 1062;

    private readonly Stock $stock;
    private readonly Idempotency $idem;
    /** @var \Closure(): DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?Stock $stock = null, ?\Closure $clock = null)
    {
        $this->stock = $stock ?? new Stock($db);
        $this->idem = new Idempotency($db);
        $this->clock = $clock ?? static fn (): DateTimeImmutable => Clock::now();
    }

    // ==========================================================================================
    // reserve
    // ==========================================================================================

    /** @param list<array{variant_id: string|int, qty?: int, unit_ids: list<string|int>}> $lines */
    public function reserve(Caller $caller, string $orderRef, array $lines, string $idemKey): OpResult
    {
        $ch = $this->channel($caller);
        $orderRef = self::ref($orderRef);
        $norm = self::normaliseLines($lines);
        $this->ensureListings($ch['id'], array_column($norm, 'variant_id'));
        return $this->idem->run(
            $caller, $idemKey, 'reservation.reserve', '/v1/reservations', ['order_ref' => $orderRef, 'lines' => $norm],
            'reservation', $orderRef,
            fn (Db $db): OpResult => $this->doReserve($caller, $ch, $orderRef, $norm, $idemKey),
        );
    }

    /**
     * @param array{id: int, code: string, mode: string, ttl: int, warehouse_id: int} $ch
     * @param list<array{variant_id: string, qty: int, unit_ids: list<string>}> $lines
     */
    private function doReserve(Caller $caller, array $ch, string $orderRef, array $lines, string $idemKey): OpResult
    {
        $hash = self::linesHash($lines);
        $res = $this->lockReservation($ch['id'], $orderRef);
        $base = ['order_ref' => $orderRef];
        if ($res !== null) {
            if ($res['status'] === 'committed') {
                return OpResult::of(200, $base + ['result' => 'already_paid', 'status' => 'committed', 'attempt' => $res['attempt']]);
            }
            if ($res['status'] === 'held') {
                if ($res['lines_hash'] !== null && hash_equals($res['lines_hash'], $hash)) {
                    $expires = $this->expiry($ch);
                    $this->db->exec('UPDATE reservation SET expires_at = ? WHERE id = ?', [Clock::db($expires), $res['id']]);
                    return OpResult::of(200, $base + ['result' => 'extended', 'status' => 'held', 'attempt' => $res['attempt'],
                        'expires_at' => Clock::iso(Clock::db($expires))]);
                }
                return OpResult::of(422, $base + ['error' => 'lines_changed', 'status' => 'held', 'attempt' => $res['attempt'],
                    'message' => 'the order is held with other lines; release it first']);
            }
            if ($res['is_tombstone']) {
                // A release arrived before this reserve (the site rolled the order back): no hold.
                return OpResult::of(409, $base + ['error' => 'released', 'result' => 'tombstone', 'status' => $res['status']]);
            }
        }

        // A new order, or a fresh attempt on a released/expired one.
        $existing = $res === null ? [] : $this->unitsOf($res['id']);
        $conflict = $this->unitConflicts($ch['id'], $res['id'] ?? null, $lines);
        if ($conflict !== null) {
            return $conflict;
        }
        $snap = $this->listings($ch['id'], array_column($lines, 'variant_id'));
        $wh = $ch['warehouse_id'];
        $pairs = [];
        foreach ($snap as $l) {
            if ($l['sku_id'] !== null) {
                $pairs[] = [$wh, $l['sku_id']];
            }
        }
        $this->stock->lock($pairs);
        $skus = $this->lockSkus(array_column($pairs, 1));

        $need = [];
        foreach ($lines as $line) {
            $l = $snap[$line['variant_id']];
            if ($l['sku_id'] !== null) {
                $need[$l['sku_id']] = ($need[$l['sku_id']] ?? 0) + $line['qty'] * $l['u'];
            }
        }
        $refusable = $ch['mode'] === 'live';
        $views = [];
        $reasons = [];
        foreach ($lines as $line) {
            $l = $snap[$line['variant_id']];
            $v = ['variant_id' => $line['variant_id'], 'qty' => $line['qty'], 'units_per_item' => $l['u']];
            if ($l['sku_id'] === null) {
                $views[] = $v + ['kind' => 'unlinked', 'result' => 'unlinked', 'sku_code' => null, 'available' => null];
                continue;
            }
            $sku = $l['sku_id'];
            $policy = $skus[$sku]['policy'];
            $avail = $this->stock->available($wh, $sku);
            $problem = null;
            if ($refusable) {
                // A legacy line is never refused (§2.3), even on a quarantined listing (R8).
                if ($l['status'] === 'quarantined' && $policy !== 'legacy') {
                    $problem = 'quarantined';
                } elseif ($policy === 'stopped') {
                    $problem = 'stopped';
                } elseif ($policy === 'strict' && $need[$sku] > $avail) {
                    $problem = 'short';
                }
            }
            if ($problem !== null) {
                $reasons[$problem] = true;
            }
            $views[] = $v + ['kind' => $policy, 'result' => $problem ?? 'ok', 'sku_code' => $skus[$sku]['code'],
                'quarantined' => $l['status'] === 'quarantined', 'available' => self::listingUnits($avail, $l['u'])];
        }
        if ($reasons !== []) {
            return OpResult::of(409, $base + ['error' => 'refused', 'reasons' => array_keys($reasons),
                'status' => $res['status'] ?? 'none', 'lines' => $views]);
        }

        $expires = $this->expiry($ch);
        if ($res === null) {
            $attempt = 1;
            $resId = $this->insertReservation($ch['id'], $orderRef, 'held', 1, $hash, 'reserved', Clock::db($expires), null);
        } else {
            $attempt = $res['attempt'] + 1;
            $resId = $res['id'];
            $this->db->exec(
                "UPDATE reservation SET status = 'held', attempt = ?, lines_hash = ?, expires_at = ? WHERE id = ?",
                [$attempt, $hash, Clock::db($expires), $resId],
            );
        }
        foreach ($lines as $line) {
            $l = $snap[$line['variant_id']];
            foreach ($line['unit_ids'] as $unitId) {
                $this->writeUnit($ch['id'], $unitId, $resId, $l, $wh, 'held', isset($existing[$unitId]));
                if ($l['sku_id'] !== null) {
                    $this->stock->apply($wh, $l['sku_id'], 'held', $l['u'],
                        $this->move('reserve', $caller, $orderRef, $unitId, $idemKey));
                }
            }
        }
        $this->stock->flush();
        foreach ($views as $i => $v) {
            if ($v['kind'] !== 'unlinked') {
                $l = $snap[$v['variant_id']];
                $views[$i]['available'] = self::listingUnits($this->stock->available($wh, (int) $l['sku_id']), $l['u']);
                $views[$i]['result'] = 'held';
            }
        }
        return OpResult::of(201, $base + ['result' => 'held', 'status' => 'held', 'attempt' => $attempt,
            'expires_at' => Clock::iso(Clock::db($expires)), 'lines' => $views]);
    }

    // ==========================================================================================
    // commit
    // ==========================================================================================

    /** @param list<array{variant_id: string|int, qty?: int, unit_ids: list<string|int>}> $lines */
    public function commit(Caller $caller, string $orderRef, array $lines, string $origin, string $idemKey): OpResult
    {
        $ch = $this->channel($caller);
        $orderRef = self::ref($orderRef);
        $norm = self::normaliseLines($lines);
        self::checkOrigin($origin);
        $this->ensureListings($ch['id'], array_column($norm, 'variant_id'));
        return $this->idem->run(
            $caller, $idemKey, 'reservation.commit', '/v1/reservations/' . $orderRef . '/commit',
            ['order_ref' => $orderRef, 'lines' => $norm, 'origin' => $origin], 'reservation', $orderRef,
            function (Db $db) use ($caller, $ch, $orderRef, $norm, $origin, $idemKey): OpResult {
                $res = $this->lockReservation($ch['id'], $orderRef);
                if ($res !== null && $res['status'] === 'committed') {
                    return OpResult::of(200, ['order_ref' => $orderRef, 'result' => 'already_committed', 'status' => 'committed',
                        'attempt' => $res['attempt'], 'origin' => $res['origin']]);
                }
                $plan = $this->planCommit($ch, $res, $norm);
                if ($plan instanceof OpResult) {
                    return $plan;
                }
                $this->stock->lock($plan['pairs']);
                $skus = $this->lockSkus(array_column($plan['pairs'], 1));
                return $this->applyCommit($caller, $ch, $orderRef, $res, $norm, $origin, $plan, $skus, $idemKey);
            },
        );
    }

    /**
     * Works out what a commit does to each unit, before any balance is locked.
     *
     * @param array{id: int, code: string, mode: string, ttl: int, warehouse_id: int} $ch
     * @param array{id: int, status: string, attempt: int, lines_hash: ?string, origin: string, is_tombstone: bool, expires_at: ?string}|null $res
     * @param list<array{variant_id: string, qty: int, unit_ids: list<string>}> $lines
     * @return OpResult|array{actions: list<array<string, mixed>>, pairs: list<array{0: int, 1: int}>, differs: bool,
     *         released: list<string>, fresh: list<string>}
     */
    private function planCommit(array $ch, ?array $res, array $lines): OpResult|array
    {
        $conflict = $this->unitConflicts($ch['id'], $res['id'] ?? null, $lines);
        if ($conflict !== null) {
            return $conflict;
        }
        $existing = $res === null ? [] : $this->unitsOf($res['id']);
        $snap = $this->listings($ch['id'], array_column($lines, 'variant_id'));
        $wh = $ch['warehouse_id'];
        $actions = [];
        $pairs = [];
        $inBody = [];
        $released = [];
        $fresh = [];
        foreach ($lines as $line) {
            $l = $snap[$line['variant_id']];
            foreach ($line['unit_ids'] as $unitId) {
                $inBody[$unitId] = true;
                $ex = $existing[$unitId] ?? null;
                if ($ex !== null && $ex['state'] === 'held' && $ex['listing_id'] === $l['listing_id']) {
                    // Paid for what was held: keep the sale-time snapshot.
                    $actions[] = ['do' => 'convert', 'unit' => $ex];
                    if ($ex['sku_id'] !== null) {
                        $pairs[] = [$ex['warehouse_id'], $ex['sku_id']];
                    }
                    continue;
                }
                if ($ex !== null && $ex['state'] === 'held') {
                    // Held under another listing: body wins; drop the old hold first.
                    $actions[] = ['do' => 'release', 'unit' => $ex];
                    $released[] = $unitId;
                    if ($ex['sku_id'] !== null) {
                        $pairs[] = [$ex['warehouse_id'], $ex['sku_id']];
                    }
                }
                $actions[] = ['do' => 'allocate', 'unit_id' => $unitId, 'listing' => $l, 'warehouse_id' => $wh, 'exists' => $ex !== null];
                $fresh[] = $unitId;
                if ($l['sku_id'] !== null) {
                    $pairs[] = [$wh, $l['sku_id']];
                }
            }
        }
        foreach ($existing as $ex) {
            if ($ex['state'] === 'held' && !isset($inBody[$ex['unit_id']])) {
                // Held but not paid for (body lines win over stored ones).
                $actions[] = ['do' => 'release', 'unit' => $ex, 'final' => true];
                $released[] = $ex['unit_id'];
                if ($ex['sku_id'] !== null) {
                    $pairs[] = [$ex['warehouse_id'], $ex['sku_id']];
                }
            }
        }
        $heldBefore = array_values(array_map(static fn (array $u): string => $u['unit_id'],
            array_filter($existing, static fn (array $u): bool => $u['state'] === 'held')));
        $differs = $res !== null && $res['status'] === 'held' && ($released !== [] || $fresh !== []);
        return ['actions' => $actions, 'pairs' => $pairs, 'differs' => $differs, 'released' => $released,
            'fresh' => $fresh, 'held_before' => $heldBefore];
    }

    /**
     * @param array{id: int, code: string, mode: string, ttl: int, warehouse_id: int} $ch
     * @param array{id: int, status: string, attempt: int, lines_hash: ?string, origin: string, is_tombstone: bool, expires_at: ?string}|null $res
     * @param list<array{variant_id: string, qty: int, unit_ids: list<string>}> $lines
     * @param array{actions: list<array<string, mixed>>, pairs: list<array{0: int, 1: int}>, differs: bool, released: list<string>, fresh: list<string>} $plan
     * @param array<int, array{code: string, policy: string}> $skus
     */
    private function applyCommit(Caller $caller, array $ch, string $orderRef, ?array $res, array $lines, string $origin,
        array $plan, array $skus, string $idemKey, bool $flush = true): OpResult
    {
        $now = Clock::db($this->now());
        $hash = self::linesHash($lines);
        if ($res === null) {
            $attempt = 1;
            $resId = $this->insertReservation($ch['id'], $orderRef, 'committed', 1, $hash, $origin, null, $now);
        } else {
            $attempt = $res['attempt'];
            $resId = $res['id'];
            $this->db->exec(
                "UPDATE reservation SET status = 'committed', origin = ?, lines_hash = ?, committed_at = ?, expires_at = NULL WHERE id = ?",
                [$origin, $hash, $now, $resId],
            );
        }

        $unheld = []; // central units allocated without a hold, per balance
        $unheldQuarantined = []; // ... of them sold through a quarantined listing
        foreach ($plan['actions'] as $a) {
            if ($a['do'] === 'convert') {
                $u = $a['unit'];
                $this->setUnitState($ch['id'], $u['unit_id'], 'allocated');
                if ($u['sku_id'] !== null) {
                    $m = $this->move('commit', $caller, $orderRef, $u['unit_id'], $idemKey);
                    $this->stock->apply($u['warehouse_id'], $u['sku_id'], 'held', -$u['u'], $m);
                    $this->stock->apply($u['warehouse_id'], $u['sku_id'], 'allocated', $u['u'], $m);
                }
            } elseif ($a['do'] === 'release') {
                $u = $a['unit'];
                $this->setUnitState($ch['id'], $u['unit_id'], 'released');
                if ($u['sku_id'] !== null) {
                    $this->stock->apply($u['warehouse_id'], $u['sku_id'], 'held', -$u['u'],
                        $this->move('commit_release', $caller, $orderRef, $u['unit_id'], $idemKey));
                }
            } else {
                $l = $a['listing'];
                $this->writeUnit($ch['id'], $a['unit_id'], $resId, $l, $a['warehouse_id'], 'allocated', $a['exists']);
                if ($l['sku_id'] !== null) {
                    $this->stock->apply($a['warehouse_id'], $l['sku_id'], 'allocated', $l['u'],
                        $this->move('commit', $caller, $orderRef, $a['unit_id'], $idemKey, 'no_hold'));
                    $k = Stock::key($a['warehouse_id'], $l['sku_id']);
                    $unheld[$k] = ($unheld[$k] ?? 0) + $l['u'];
                    if ($l['status'] === 'quarantined') {
                        $unheldQuarantined[$k] = ($unheldQuarantined[$k] ?? 0) + $l['u'];
                    }
                }
            }
        }

        $kind = match (true) {
            $origin === 'unreserved' => 'outage_order',
            $origin === 'opening' => 'opening_short',
            $res !== null && in_array($res['status'], ['released', 'expired'], true) => 'commit_after_expiry',
            default => 'commit_short',
        };
        $oversell = [];
        $live = $ch['mode'] === 'live';
        foreach ($unheld as $k => $units) {
            [$wh, $sku] = array_map('intval', explode(':', $k));
            $policy = $skus[$sku]['policy'];
            $avail = $this->stock->available($wh, $sku);
            $quarantined = $unheldQuarantined[$k] ?? 0;
            if ($live && $policy === 'stopped') {
                // A sale CW always refuses on a live site got through without a hold (an outage
                // order, a payment after expiry): flagged whatever the stock (R7, §4).
                [$evKind, $shortfall] = ['stopped_sale', $units];
            } elseif ($live && $quarantined > 0 && $policy !== 'legacy') {
                [$evKind, $shortfall] = ['quarantined_sale', $quarantined];
            } elseif (in_array($policy, ['strict', 'stopped'], true) && $avail < 0) {
                [$evKind, $shortfall] = [$kind, min($units, -$avail)];
            } else {
                continue; // legacy: today's behaviour; backorder: selling below zero is intended
            }
            $this->db->exec(
                'INSERT INTO oversell_event (warehouse_id, sku_id, channel_id, reservation_id, order_ref, kind, shortfall, available_after, detail, dedupe_key) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
                [$wh, $sku, $ch['id'], $resId, $orderRef, $evKind, $shortfall, $avail,
                    Idempotency::json(['policy' => $policy, 'unheld_units' => $units, 'quarantined_units' => $quarantined,
                        'origin' => $origin, 'channel_mode' => $ch['mode']]),
                    mb_strcut("commit:{$ch['id']}:{$orderRef}:{$wh}:{$sku}", 0, 191, 'UTF-8')],
            );
            $oversell[] = ['sku_code' => $skus[$sku]['code'], 'kind' => $evKind, 'shortfall' => $shortfall, 'available_after' => $avail];
        }
        if ($plan['differs']) {
            Audit::write($this->db, $caller, 'reservation.commit_lines_differ', 'reservation', $orderRef, $idemKey, [
                'held_units' => $plan['held_before'], 'released_units' => $plan['released'], 'allocated_without_hold' => $plan['fresh'],
            ]);
        }
        if ($flush) {
            $this->stock->flush();
        }
        return OpResult::of(200, ['order_ref' => $orderRef, 'result' => 'committed', 'status' => 'committed', 'attempt' => $attempt,
            'origin' => $origin, 'oversell' => $oversell]);
    }

    // ==========================================================================================
    // release / expire
    // ==========================================================================================

    /**
     * $attempt null means attempt 1 (R9), the only attempt a site can fail to know: a release
     * without it can never drop the hold of a later attempt (that customer may be paying).
     */
    public function release(Caller $caller, string $orderRef, ?int $attempt, string $idemKey): OpResult
    {
        $ch = $this->channel($caller);
        $orderRef = self::ref($orderRef);
        if ($attempt !== null && ($attempt < 1 || $attempt > self::MAX_ATTEMPT)) {
            throw new CwException('bad_attempt', 'attempt must be between 1 and ' . self::MAX_ATTEMPT, 400);
        }
        $attempt ??= 1;
        return $this->idem->run(
            $caller, $idemKey, 'reservation.release', '/v1/reservations/' . $orderRef . '/release',
            ['order_ref' => $orderRef, 'attempt' => $attempt], 'reservation', $orderRef,
            function (Db $db) use ($caller, $ch, $orderRef, $attempt, $idemKey): OpResult {
                $base = ['order_ref' => $orderRef];
                $res = $this->lockReservation($ch['id'], $orderRef);
                if ($res === null) {
                    // Unknown ref: remember it, so a reserve that arrives late creates no hold.
                    $this->insertReservation($ch['id'], $orderRef, 'released', $attempt, null, 'reserved', null, null,
                        Clock::db($this->now()), true);
                    return OpResult::of(200, $base + ['result' => 'tombstoned', 'status' => 'released', 'attempt' => $attempt]);
                }
                if ($res['status'] === 'committed') {
                    return OpResult::of(409, $base + ['error' => 'use_cancel', 'status' => 'committed',
                        'message' => 'the order is paid; cancel its units instead']);
                }
                if ($res['status'] !== 'held') {
                    return OpResult::of(200, $base + ['result' => 'already_released', 'status' => $res['status'], 'attempt' => $res['attempt']]);
                }
                if ($attempt < $res['attempt']) {
                    return OpResult::of(200, $base + ['result' => 'stale_attempt', 'status' => 'held', 'attempt' => $res['attempt']]);
                }
                $this->releaseHeld($caller, $res['id'], $orderRef, 'release', 'released', $idemKey);
                return OpResult::of(200, $base + ['result' => 'released', 'status' => 'released', 'attempt' => $res['attempt']]);
            },
        );
    }

    /**
     * Expires holds past their TTL (cron). Selects ids without locks, then runs each through
     * the normal path (lock the reservation, re-check). Returns how many were expired.
     *
     * With $onError, a reservation whose expiry fails is reported as $onError(id, error) and
     * skipped, so one bad row (a lock-wait timeout, a corrupt balance) cannot stop every other
     * hold from expiring; the cron (bin/expire_reservations.php) uses this. Without it the
     * error propagates.
     *
     * @param null|callable(int, \Throwable): void $onError
     */
    public function expireDue(int $limit = 500, ?callable $onError = null): int
    {
        $now = Clock::db($this->now());
        $ids = $this->db->column(
            "SELECT id FROM reservation WHERE status = 'held' AND expires_at <= ? ORDER BY expires_at LIMIT ?",
            [$now, max(1, $limit)],
        );
        $caller = Caller::system('expiry');
        $n = 0;
        foreach ($ids as $id) {
            try {
                $n += $this->db->transaction(function (Db $db) use ($id, $now, $caller): int {
                    $r = $db->one('SELECT id, order_ref, status, expires_at FROM reservation WHERE id = ? FOR UPDATE', [(int) $id]);
                    if ($r === null || $r['status'] !== 'held' || (string) $r['expires_at'] > $now) {
                        return 0; // paid, released or extended meanwhile
                    }
                    $this->releaseHeld($caller, (int) $r['id'], (string) $r['order_ref'], 'expire', 'expired', null);
                    Audit::write($db, $caller, 'reservation.expire', 'reservation', (string) $r['order_ref'], null, ['reservation_id' => (int) $r['id']]);
                    return 1;
                });
            } catch (\Throwable $e) {
                if ($onError === null) {
                    throw $e;
                }
                $onError((int) $id, $e);
            }
        }
        return $n;
    }

    /** held units -> released, held -u each; reservation -> $status. Caller holds the reservation lock. */
    private function releaseHeld(Caller $caller, int $resId, string $orderRef, string $type, string $status, ?string $idemKey): void
    {
        $units = array_filter($this->unitsOf($resId), static fn (array $u): bool => $u['state'] === 'held');
        $pairs = [];
        foreach ($units as $u) {
            if ($u['sku_id'] !== null) {
                $pairs[] = [$u['warehouse_id'], $u['sku_id']];
            }
        }
        $this->stock->lock($pairs);
        foreach ($units as $u) {
            $this->setUnitState($u['channel_id'], $u['unit_id'], 'released');
            if ($u['sku_id'] !== null) {
                $this->stock->apply($u['warehouse_id'], $u['sku_id'], 'held', -$u['u'],
                    ['type' => $type, 'actor' => $caller->actor, 'channel_id' => $u['channel_id'], 'order_ref' => $orderRef,
                        'unit_id' => $u['unit_id'], 'idem_key' => $idemKey]);
            }
        }
        $this->db->exec('UPDATE reservation SET status = ?, released_at = ? WHERE id = ?', [$status, Clock::db($this->now()), $resId]);
        $this->stock->flush();
    }

    // ==========================================================================================
    // cancel / ship / unship / return (per unit)
    // ==========================================================================================

    /** @param list<string|int> $unitIds */
    public function cancel(Caller $caller, string $orderRef, array $unitIds, bool $restockable, string $idemKey): OpResult
    {
        return $this->unitOperation($caller, 'cancel', $orderRef, $unitIds, ['restockable' => $restockable], $idemKey,
            function (string $orderRef, array $units, array $wanted, string $idemKey) use ($caller, $restockable): array {
                $verify = $this->stock->warehouseId('VERIFY');
                $pairs = [];
                foreach ($units as $u) {
                    if ($u['sku_id'] !== null && in_array($u['state'], ['held', 'allocated'], true)) {
                        $pairs[] = [$u['warehouse_id'], $u['sku_id']];
                        if (!$restockable && $u['state'] === 'allocated') {
                            $pairs[] = [$verify, $u['sku_id']];
                        }
                    }
                }
                $this->stock->lock($pairs);
                $out = [];
                foreach ($wanted as $id) {
                    $u = $units[$id] ?? null;
                    if ($u === null) {
                        $out[] = ['unit_id' => $id, 'result' => 'unknown_unit'];
                        continue;
                    }
                    if (!in_array($u['state'], ['held', 'allocated'], true)) {
                        $out[] = ['unit_id' => $id, 'result' => $u['state'] === 'cancelled' ? 'already_cancelled' : $u['state']];
                        continue;
                    }
                    $this->setUnitState($u['channel_id'], $id, 'cancelled');
                    $result = 'cancelled';
                    if ($u['sku_id'] !== null) {
                        $m = $this->move('cancel', $caller, $orderRef, $id, $idemKey);
                        $this->stock->apply($u['warehouse_id'], $u['sku_id'], $u['state'], -$u['u'], $m);
                        if (!$restockable && $u['state'] === 'allocated') {
                            // Doubtful: the goods never left, but staff did not confirm they are back
                            // on the shelf. Park them in VERIFY for a recount; never a write-off here.
                            $booked = ['effective_at' => Clock::db($this->now())]; // booking time (D45)
                            $this->stock->apply($u['warehouse_id'], $u['sku_id'], 'on_hand', -$u['u'],
                                $this->move('transfer_out', $caller, $orderRef, $id, $idemKey, 'cancel_not_restockable') + $booked);
                            $this->stock->apply($verify, $u['sku_id'], 'on_hand', $u['u'],
                                $this->move('transfer_in', $caller, $orderRef, $id, $idemKey, 'cancel_not_restockable') + $booked);
                            $this->stock->openCountReview($verify, $u['sku_id'], 'verify_recount', null,
                                "{$u['channel_id']}:{$orderRef}:{$id}",
                                ['order_ref' => $orderRef, 'unit_id' => $id, 'units' => $u['u'], 'from_warehouse_id' => $u['warehouse_id']],
                                "verify:{$u['channel_id']}:{$id}");
                            $result = 'cancelled_to_verify';
                        }
                    }
                    $out[] = ['unit_id' => $id, 'result' => $result];
                }
                return $out;
            });
    }

    /** @param list<string|int> $unitIds */
    public function ship(Caller $caller, string $orderRef, array $unitIds, mixed $dispatchedAt, string $idemKey): OpResult
    {
        $at = Clock::parse($dispatchedAt, 'dispatched_at');
        $atDb = Clock::db($at);
        return $this->unitOperation($caller, 'ship', $orderRef, $unitIds, ['dispatched_at' => $atDb], $idemKey,
            function (string $orderRef, array $units, array $wanted, string $idemKey) use ($caller, $at, $atDb): array {
                // Checked here, after the idempotency lookup, so a stored answer is still replayed.
                Clock::checkWindow($at, 'dispatched_at', $this->now(), self::EVENT_MAX_AGE_SEC);
                $pairs = [];
                foreach ($units as $u) {
                    if ($u['sku_id'] !== null && $u['state'] === 'allocated') {
                        $pairs[] = [$u['warehouse_id'], $u['sku_id']];
                    }
                }
                $this->stock->lock($pairs);
                $out = [];
                foreach ($wanted as $id) {
                    $u = $units[$id] ?? null;
                    if ($u === null) {
                        $out[] = ['unit_id' => $id, 'result' => 'unknown_unit'];
                        continue;
                    }
                    if ($u['state'] !== 'allocated') {
                        $out[] = ['unit_id' => $id, 'result' => $u['state'] === 'shipped' ? 'already_shipped' : $u['state']];
                        continue;
                    }
                    $result = 'shipped';
                    if ($u['sku_id'] !== null) {
                        $countedAt = $this->stock->row($u['warehouse_id'], $u['sku_id'])['counted_at'];
                        // §8.2: dispatched at/before the last count -> the count already excluded it.
                        $preCount = $countedAt !== null && $countedAt >= $atDb;
                        $m = $this->move('ship', $caller, $orderRef, $id, $idemKey, $preCount ? 'pre_count' : null) + ['effective_at' => $atDb];
                        $this->stock->apply($u['warehouse_id'], $u['sku_id'], 'allocated', -$u['u'], $m);
                        if (!$preCount) {
                            $this->stock->apply($u['warehouse_id'], $u['sku_id'], 'on_hand', -$u['u'], $m);
                        } else {
                            $result = 'shipped_pre_count';
                        }
                        if ($countedAt !== null && abs(Clock::diff(Clock::fromDb($countedAt), $at)) <= Stock::NEAR_COUNT_SEC) {
                            $this->stock->openCountReview($u['warehouse_id'], $u['sku_id'], 'ship_near_count', null,
                                "{$u['channel_id']}:{$orderRef}:{$id}",
                                ['unit_id' => $id, 'dispatched_at' => $atDb, 'counted_at' => $countedAt, 'applied' => $preCount ? 'pre_count' : 'normal'],
                                "ship_near_count:{$u['channel_id']}:{$id}");
                        }
                    }
                    $this->db->exec("UPDATE reservation_unit SET state = 'shipped', dispatched_at = ? WHERE channel_id = ? AND unit_id = ?",
                        [$atDb, $u['channel_id'], $id]);
                    $out[] = ['unit_id' => $id, 'result' => $result];
                }
                return $out;
            });
    }

    /**
     * A dispatch reset: the unit is back in the building, still paid. allocated +u, on_hand +u.
     * $at = when the reset happened (default: now); it is the ledger rows' effective time (D35).
     * An explicit $at must be plausible (R5) and later than each unit's stored dispatch time: a
     * reset cannot precede the dispatch it reverses, and a connector that sends the ship's own
     * dispatched_at would otherwise turn a reset after a count into a pre-count one (R10).
     *
     * @param list<string|int> $unitIds
     */
    public function unship(Caller $caller, string $orderRef, array $unitIds, mixed $at, string $idemKey): OpResult
    {
        $parsed = $at === null ? null : Clock::parse($at, 'at');
        $when = $parsed === null ? null : Clock::db($parsed);
        return $this->unitOperation($caller, 'unship', $orderRef, $unitIds, ['at' => $when], $idemKey,
            function (string $orderRef, array $units, array $wanted, string $idemKey) use ($caller, $parsed, $when): array {
                if ($parsed !== null) {
                    Clock::checkWindow($parsed, 'at', $this->now(), self::EVENT_MAX_AGE_SEC);
                    foreach ($wanted as $id) {
                        $u = $units[$id] ?? null;
                        if ($u !== null && $u['state'] === 'shipped' && $u['dispatched_at'] !== null && $when <= $u['dispatched_at']) {
                            throw new CwException('bad_time', "at ({$when}) is not after unit {$id}'s dispatch ({$u['dispatched_at']}): "
                                . 'send the time of the reset, not of the dispatch', 400,
                                ['field' => 'at', 'reason' => 'not_after_dispatch', 'unit_id' => $id, 'dispatched_at' => Clock::iso($u['dispatched_at'])]);
                        }
                    }
                }
                $effective = $when ?? Clock::db($this->now());
                $pairs = [];
                foreach ($units as $u) {
                    if ($u['sku_id'] !== null && $u['state'] === 'shipped') {
                        $pairs[] = [$u['warehouse_id'], $u['sku_id']];
                    }
                }
                $this->stock->lock($pairs);
                $out = [];
                foreach ($wanted as $id) {
                    $u = $units[$id] ?? null;
                    if ($u === null) {
                        $out[] = ['unit_id' => $id, 'result' => 'unknown_unit'];
                        continue;
                    }
                    if ($u['state'] !== 'shipped') {
                        $out[] = ['unit_id' => $id, 'result' => $u['state'] === 'allocated' ? 'not_shipped' : $u['state']];
                        continue;
                    }
                    $result = 'unshipped';
                    if ($u['sku_id'] !== null) {
                        $countedAt = $this->stock->row($u['warehouse_id'], $u['sku_id'])['counted_at'];
                        // The mirror of the §8.2 ship rule (D35): a reset at/before the last count's
                        // counted_at put the unit back on the shelf before it was counted, so the
                        // count already includes it; only allocated moves.
                        $preCount = $countedAt !== null && $countedAt >= $effective;
                        $m = $this->move('unship', $caller, $orderRef, $id, $idemKey, $preCount ? 'pre_count' : null) + ['effective_at' => $effective];
                        if (!$preCount) {
                            $this->stock->apply($u['warehouse_id'], $u['sku_id'], 'on_hand', $u['u'], $m);
                        } else {
                            $result = 'unshipped_pre_count';
                        }
                        $this->stock->apply($u['warehouse_id'], $u['sku_id'], 'allocated', $u['u'], $m);
                        if ($countedAt !== null && abs(Clock::diff(Clock::fromDb($countedAt), Clock::fromDb($effective))) <= Stock::NEAR_COUNT_SEC) {
                            $this->stock->openCountReview($u['warehouse_id'], $u['sku_id'], 'unship_near_count', null,
                                "{$u['channel_id']}:{$orderRef}:{$id}",
                                ['unit_id' => $id, 'reset_at' => $effective, 'counted_at' => $countedAt, 'applied' => $preCount ? 'pre_count' : 'normal'],
                                "unship_near_count:{$u['channel_id']}:{$id}:{$effective}");
                        }
                    }
                    $this->db->exec("UPDATE reservation_unit SET state = 'allocated', dispatched_at = NULL WHERE channel_id = ? AND unit_id = ?",
                        [$u['channel_id'], $id]);
                    $out[] = ['unit_id' => $id, 'result' => $result];
                }
                return $out;
            });
    }

    /**
     * Goods back after dispatch: on_hand +u, once per unit whatever the key (a refund-with-restock
     * and a return receipt never double-count).
     *
     * @param list<string|int> $unitIds
     */
    public function returnUnits(Caller $caller, string $orderRef, array $unitIds, string $idemKey): OpResult
    {
        return $this->unitOperation($caller, 'return', $orderRef, $unitIds, [], $idemKey,
            function (string $orderRef, array $units, array $wanted, string $idemKey) use ($caller): array {
                $pairs = [];
                foreach ($units as $u) {
                    if ($u['sku_id'] !== null && $u['state'] === 'shipped') {
                        $pairs[] = [$u['warehouse_id'], $u['sku_id']];
                    }
                }
                $this->stock->lock($pairs);
                $now = Clock::db($this->now());
                $out = [];
                foreach ($wanted as $id) {
                    $u = $units[$id] ?? null;
                    if ($u === null) {
                        $out[] = ['unit_id' => $id, 'result' => 'unknown_unit'];
                        continue;
                    }
                    if ($u['state'] !== 'shipped') {
                        $out[] = ['unit_id' => $id, 'result' => $u['state'] === 'returned' ? 'already_returned' : 'not_shipped'];
                        continue;
                    }
                    if ($u['sku_id'] !== null) {
                        $this->stock->apply($u['warehouse_id'], $u['sku_id'], 'on_hand', $u['u'],
                            $this->move('return', $caller, $orderRef, $id, $idemKey) + ['effective_at' => $now]);
                    }
                    $this->setUnitState($u['channel_id'], $id, 'returned');
                    $out[] = ['unit_id' => $id, 'result' => 'returned'];
                }
                return $out;
            });
    }

    /**
     * Shared skeleton of the per-unit operations: lock the reservation, load its units, let $fn
     * lock balances and apply, flush the feed.
     *
     * @param list<string|int> $unitIds
     * @param array<string, mixed> $extra request fields besides order_ref/unit_ids
     * @param callable(string, array<string, array<string, mixed>>, list<string>, string): list<array{unit_id: string, result: string}> $fn
     */
    private function unitOperation(Caller $caller, string $op, string $orderRef, array $unitIds, array $extra, string $idemKey, callable $fn): OpResult
    {
        $ch = $this->channel($caller);
        $orderRef = self::ref($orderRef);
        $wanted = self::unitIds($unitIds);
        return $this->idem->run(
            $caller, $idemKey, 'reservation.' . $op, '/v1/reservations/' . $orderRef . '/' . $op,
            ['order_ref' => $orderRef, 'unit_ids' => $wanted] + $extra, 'reservation', $orderRef,
            function (Db $db) use ($op, $ch, $orderRef, $wanted, $idemKey, $fn): OpResult {
                $res = $this->lockReservation($ch['id'], $orderRef);
                if ($res === null) {
                    throw new CwException('unknown_order', 'CW has no reservation for this order yet', 404);
                }
                if ($op !== 'cancel' && $res['status'] !== 'committed') {
                    // ship / unship / return concern paid units; the commit is still on its way
                    // (the site's outbox sends it first). Not stored, so the same key works later (D36).
                    throw new CwException('not_committed', 'order ' . $orderRef . ' is not paid yet (commit first)', 409);
                }
                $units = $this->unitsOf($res['id'], $wanted);
                $out = $fn($orderRef, $units, $wanted, $idemKey);
                $this->stock->flush();
                return OpResult::of(200, ['order_ref' => $orderRef, 'status' => $res['status'], 'units' => $out]);
            },
        );
    }

    // ==========================================================================================
    // opening orders (§8.1)
    // ==========================================================================================

    /**
     * Sends a site's paid-not-shipped units at T0 as committed reservations (origin=opening).
     * Accepted until a call with $final = true is accepted (large sites send several batches; the
     * connector sends the final one only after every earlier batch answered 200, R1). Orders
     * already committed are skipped. All balances are locked once, in order, before any is changed.
     *
     * $t0 = {at, last_order_id, last_stock_log_id|null} (§8.1 watermarks, R12): stored once, by
     * any batch; a later batch with other values gets 409 t0_mismatch; the final batch is refused
     * (409 t0_required, not stored) while no T0 is recorded.
     *
     * Locking (R1): after the idempotency claim, the channel's channel_opening row FOR UPDATE, so
     * opening calls of one site run one at a time and the marker is read and written under that
     * lock. The channel row itself is never locked here: every site transaction holds an S lock on
     * it (FK checks), so an X lock on it after other locks deadlocked with the site's own writes.
     *
     * @param list<array{order_ref: string|int, lines: list<array<string, mixed>>}> $orders
     * @param array<string, mixed>|null $t0
     */
    public function openingOrders(Caller $caller, array $orders, bool $final, string $idemKey, ?array $t0 = null): OpResult
    {
        $ch = $this->channel($caller);
        if ($orders === [] || !array_is_list($orders)) {
            throw new CwException('bad_orders', 'orders must be a non-empty list', 400);
        }
        $t0n = $t0 === null ? null : self::normaliseT0($t0);
        $norm = [];
        $units = 0;
        $seenUnits = [];
        foreach ($orders as $i => $o) {
            if (!is_array($o)) {
                throw new CwException('bad_orders', "orders[{$i}] must be an object", 400);
            }
            $ref = self::ref(is_int($o['order_ref'] ?? null) ? (string) $o['order_ref'] : (string) ($o['order_ref'] ?? ''));
            if (isset($norm[$ref])) {
                throw new CwException('bad_orders', "order {$ref} appears twice", 400);
            }
            $lines = self::normaliseLines($o['lines'] ?? null);
            foreach ($lines as $l) {
                foreach ($l['unit_ids'] as $u) {
                    if (isset($seenUnits[$u])) {
                        throw new CwException('duplicate_unit', "unit {$u} appears in two orders", 400);
                    }
                    $seenUnits[$u] = true;
                    $units++;
                }
            }
            $norm[$ref] = $lines;
        }
        if ($units > self::MAX_UNITS) {
            throw new CwException('too_many_units', 'send at most ' . self::MAX_UNITS . ' units per call (final=false for all but the last)', 413);
        }
        ksort($norm, SORT_STRING);
        $variants = [];
        foreach ($norm as $lines) {
            foreach ($lines as $l) {
                $variants[$l['variant_id']] = true;
            }
        }
        $this->ensureListings($ch['id'], array_map('strval', array_keys($variants)));

        $request = ['orders' => $norm, 'final' => $final] + ($t0n === null ? [] : ['t0' => $t0n]);
        return $this->idem->run(
            $caller, $idemKey, 'reservation.opening_orders', '/v1/opening_orders', $request,
            'channel', $ch['code'],
            function (Db $db) use ($caller, $ch, $norm, $final, $idemKey, $t0n): OpResult {
                // Lock order (R1): claim -> channel_opening -> reservations -> balances -> skus -> feed clock.
                $db->exec('INSERT INTO channel_opening (channel_id) VALUES (?) ON DUPLICATE KEY UPDATE channel_id = channel_id', [$ch['id']]);
                $opening = $db->one(
                    'SELECT t0_at, t0_last_order_id, t0_last_stock_log_id, opening_orders_at FROM channel_opening WHERE channel_id = ? FOR UPDATE',
                    [$ch['id']],
                );
                if ($opening === null) {
                    throw new \RuntimeException('channel_opening row could not be created');
                }
                if ($opening['opening_orders_at'] !== null) {
                    return OpResult::of(409, ['error' => 'opening_orders_done', 'message' => 'opening orders were already accepted',
                        'opening_orders_at' => Clock::iso((string) $opening['opening_orders_at'])]);
                }
                $stored = $opening['t0_at'] === null ? null : ['at' => (string) $opening['t0_at'],
                    'last_order_id' => (int) $opening['t0_last_order_id'],
                    'last_stock_log_id' => $opening['t0_last_stock_log_id'] === null ? null : (int) $opening['t0_last_stock_log_id']];
                if ($t0n !== null) {
                    Clock::checkWindow(Clock::fromDb($t0n['at']), 't0.at', $this->now(), self::EVENT_MAX_AGE_SEC);
                    if ($stored !== null && $stored !== $t0n) {
                        return OpResult::of(409, ['error' => 't0_mismatch', 'message' => 'T0 watermarks were already recorded with other values',
                            't0' => self::t0Body($stored)]);
                    }
                }
                if ($final && $stored === null && $t0n === null) {
                    throw new CwException('t0_required', 'the final opening batch needs t0 {at, last_order_id, last_stock_log_id} (none recorded yet)', 409);
                }

                // Lock order: every reservation row, then every balance (sorted), then skus.
                $reservations = [];
                foreach (array_keys($norm) as $ref) {
                    $reservations[$ref] = $this->lockReservation($ch['id'], (string) $ref);
                }
                $plans = [];
                $pairs = [];
                $skipped = 0;
                foreach ($norm as $ref => $lines) {
                    $res = $reservations[$ref];
                    if ($res !== null && $res['status'] === 'committed') {
                        $skipped++;
                        continue;
                    }
                    $plan = $this->planCommit($ch, $res, $lines);
                    if ($plan instanceof OpResult) {
                        return OpResult::of($plan->status, $plan->body + ['order_ref' => (string) $ref]);
                    }
                    $plans[$ref] = $plan;
                    array_push($pairs, ...$plan['pairs']);
                }
                $this->stock->lock($pairs);
                $skus = $this->lockSkus(array_column($pairs, 1));
                $oversell = 0;
                foreach ($plans as $ref => $plan) {
                    // One feed flush at the end: the feed clock is the last lock (D39), and each
                    // further order still inserts a reservation row (FK -> channel).
                    $r = $this->applyCommit($caller, $ch, (string) $ref, $reservations[$ref], $norm[$ref], 'opening', $plan, $skus, $idemKey, false);
                    $oversell += count($r->body['oversell']);
                }
                // The marker and T0 are written under the channel_opening lock and before the
                // flush, so the feed clock stays the last lock taken (D39).
                if ($t0n !== null && $stored === null) {
                    $db->exec('UPDATE channel_opening SET t0_at = ?, t0_last_order_id = ?, t0_last_stock_log_id = ? WHERE channel_id = ?',
                        [$t0n['at'], $t0n['last_order_id'], $t0n['last_stock_log_id'], $ch['id']]);
                    $stored = $t0n;
                }
                if ($final) {
                    $db->exec('UPDATE channel_opening SET opening_orders_at = ? WHERE channel_id = ?', [Clock::db($this->now()), $ch['id']]);
                }
                $this->stock->flush();
                return OpResult::of(200, ['result' => 'accepted', 'orders' => count($norm), 'committed' => count($plans),
                    'already_committed' => $skipped, 'oversell_events' => $oversell, 'final' => $final,
                    't0' => $stored === null ? null : self::t0Body($stored)]);
            },
        );
    }

    /**
     * @param array<string, mixed> $t0
     * @return array{at: string, last_order_id: int, last_stock_log_id: ?int} (at as a DB time)
     */
    private static function normaliseT0(array $t0): array
    {
        $id = static function (mixed $v, string $field, bool $nullable): ?int {
            if ($v === null && $nullable) {
                return null;
            }
            if (!is_int($v) || $v < 0) {
                throw new CwException('bad_t0', "t0.{$field} must be an integer >= 0" . ($nullable ? ' or null' : ''), 400, ['field' => "t0.{$field}"]);
            }
            return $v;
        };
        if (!array_key_exists('at', $t0) || !array_key_exists('last_order_id', $t0) || !array_key_exists('last_stock_log_id', $t0)) {
            throw new CwException('bad_t0', 't0 needs at, last_order_id and last_stock_log_id (null when the site has no ERP stock feed)', 400);
        }
        return ['at' => Clock::db(Clock::parse($t0['at'], 't0.at')), 'last_order_id' => $id($t0['last_order_id'], 'last_order_id', false),
            'last_stock_log_id' => $id($t0['last_stock_log_id'], 'last_stock_log_id', true)];
    }

    /** @param array{at: string, last_order_id: int, last_stock_log_id: ?int} $t0 @return array<string, mixed> */
    private static function t0Body(array $t0): array
    {
        return ['at' => Clock::iso($t0['at']), 'last_order_id' => $t0['last_order_id'], 'last_stock_log_id' => $t0['last_stock_log_id']];
    }

    // ==========================================================================================
    // adopting units sold while their listing was unlinked (R4)
    // ==========================================================================================

    /**
     * Brings into the buckets the units a listing sold while it was unlinked, once it is linked.
     * Such units were recorded with sku NULL (§2.3 holding ledger) and would otherwise stay outside
     * the buckets for ever: their dispatch would never lower on_hand, their return never raise it.
     *
     * Runs INSIDE the transaction that links the listing (the DecisionService's, or any repair
     * sweep), after the channel_listing row was changed, and as its last stock step: it locks the
     * units' reservations (by order_ref, the order openingOrders uses), then the balances, sets
     * each held/allocated unit's sku and u to the link, books held/allocated +u (movement `adopt`,
     * per unit, so invariant 4 holds), flags a protected item pushed below zero (adopt_short, R6)
     * and writes the feed. The unit keeps the warehouse recorded at sale time (D13). Shipped,
     * returned, cancelled and released units are left alone (a count settles shipped ones).
     * Idempotent: a second call finds nothing to adopt.
     *
     * @return array{adopted: int, held: int, allocated: int, sku_code: ?string}
     */
    public function adoptUnlinkedUnits(Caller $caller, int $listingId): array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('adoptUnlinkedUnits runs inside the transaction that links the listing');
        }
        $none = ['adopted' => 0, 'held' => 0, 'allocated' => 0, 'sku_code' => null];
        $l = $this->db->one(
            'SELECT cl.channel_id, cl.sku_id, cl.units_per_item, cl.status, s.code FROM channel_listing cl '
            . 'LEFT JOIN sku s ON s.id = cl.sku_id WHERE cl.id = ?',
            [$listingId],
        );
        if ($l === null || $l['sku_id'] === null || !in_array($l['status'], ['mapped', 'quarantined'], true)) {
            return $none;
        }
        $channelId = (int) $l['channel_id'];
        $sku = (int) $l['sku_id'];
        $u = (int) $l['units_per_item'];
        $refs = array_map('strval', $this->db->column(
            "SELECT DISTINCT r.order_ref FROM reservation_unit ru JOIN reservation r ON r.id = ru.reservation_id "
            . "WHERE ru.listing_id = ? AND ru.sku_id IS NULL AND ru.state IN ('held', 'allocated')",
            [$listingId],
        ));
        if ($refs === []) {
            return $none + ['sku_code' => (string) $l['code']];
        }
        sort($refs, SORT_STRING);
        foreach ($refs as $ref) {
            $this->lockReservation($channelId, $ref);
        }
        // Re-read under the reservation locks: every unit change locks its reservation first.
        $units = $this->db->all(
            "SELECT ru.unit_id, ru.warehouse_id, ru.state, r.order_ref FROM reservation_unit ru JOIN reservation r ON r.id = ru.reservation_id "
            . "WHERE ru.listing_id = ? AND ru.sku_id IS NULL AND ru.state IN ('held', 'allocated') ORDER BY ru.channel_id, ru.unit_id",
            [$listingId],
        );
        $this->stock->lock(array_map(static fn (array $r): array => [(int) $r['warehouse_id'], $sku], $units));
        $count = ['held' => 0, 'allocated' => 0];
        foreach ($units as $r) {
            $state = (string) $r['state'];
            $this->db->exec('UPDATE reservation_unit SET sku_id = ?, units_per_item = ? WHERE channel_id = ? AND unit_id = ?',
                [$sku, $u, $channelId, (string) $r['unit_id']]);
            $this->stock->apply((int) $r['warehouse_id'], $sku, $state, $u, ['type' => 'adopt', 'actor' => $caller->actor,
                'channel_id' => $channelId, 'order_ref' => (string) $r['order_ref'], 'unit_id' => (string) $r['unit_id'],
                'idem_key' => null, 'note' => 'listing ' . $listingId]);
            $count[$state]++;
        }
        $this->stock->flush();
        Audit::write($this->db, $caller, 'listing.adopt_units', 'listing', (string) $listingId, null,
            $count + ['sku_id' => $sku, 'units_per_item' => $u, 'units' => array_map(static fn (array $r): string => (string) $r['unit_id'], $units)]);
        return ['adopted' => count($units)] + $count + ['sku_code' => (string) $l['code']];
    }

    // ==========================================================================================
    // helpers
    // ==========================================================================================

    /** @return array{id: int, code: string, mode: string, ttl: int, warehouse_id: int} */
    private function channel(Caller $caller): array
    {
        if ($caller->channelId === null) {
            throw new CwException('channel_required', 'reservations are made by a site', 403);
        }
        $row = $this->db->one(
            'SELECT c.id, c.code, c.mode, c.reserve_ttl_sec, cw.warehouse_id FROM channel c '
            . 'LEFT JOIN channel_warehouse cw ON cw.channel_id = c.id AND cw.is_sellable = 1 WHERE c.id = ?',
            [$caller->channelId],
        );
        if ($row === null) {
            throw new CwException('unknown_channel', 'no such channel', 403);
        }
        if ($row['warehouse_id'] === null) {
            throw new CwException('no_sellable_warehouse', 'the channel has no sellable warehouse assigned', 409);
        }
        return ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'mode' => (string) $row['mode'],
            'ttl' => (int) $row['reserve_ttl_sec'], 'warehouse_id' => (int) $row['warehouse_id']];
    }

    /** @param array{ttl: int} $ch */
    private function expiry(array $ch): DateTimeImmutable
    {
        return $this->now()->modify('+' . $ch['ttl'] . ' seconds');
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)()->setTimezone(Clock::utc());
    }

    /** @return array{id: int, status: string, attempt: int, lines_hash: ?string, origin: string, is_tombstone: bool, expires_at: ?string}|null */
    private function lockReservation(int $channelId, string $orderRef): ?array
    {
        $r = $this->db->one(
            'SELECT id, status, attempt, lines_hash, origin, is_tombstone, expires_at FROM reservation '
            . 'WHERE channel_id = ? AND order_ref = ? FOR UPDATE',
            [$channelId, $orderRef],
        );
        if ($r === null) {
            return null;
        }
        return ['id' => (int) $r['id'], 'status' => (string) $r['status'], 'attempt' => (int) $r['attempt'],
            'lines_hash' => $r['lines_hash'] === null ? null : (string) $r['lines_hash'], 'origin' => (string) $r['origin'],
            'is_tombstone' => (int) $r['is_tombstone'] === 1, 'expires_at' => $r['expires_at'] === null ? null : (string) $r['expires_at']];
    }

    private function insertReservation(int $channelId, string $orderRef, string $status, int $attempt, ?string $hash, string $origin,
        ?string $expiresAt, ?string $committedAt, ?string $releasedAt = null, bool $tombstone = false): int
    {
        try {
            return $this->db->insert(
                'INSERT INTO reservation (channel_id, order_ref, status, attempt, lines_hash, origin, is_tombstone, expires_at, committed_at, released_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$channelId, $orderRef, $status, $attempt, $hash, $origin, $tombstone ? 1 : 0, $expiresAt, $committedAt, $releasedAt],
            );
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === self::ER_DUP_ENTRY) {
                // Another call for this order_ref created it meanwhile: start over and follow the state machine.
                throw new RetryOperation('reservation created concurrently', 0, $e);
            }
            throw $e;
        }
    }

    /**
     * @param list<string>|null $only restrict to these unit ids
     * @return array<string, array{channel_id: int, unit_id: string, listing_id: int, sku_id: ?int, u: int, warehouse_id: int, state: string, dispatched_at: ?string}>
     */
    private function unitsOf(int $reservationId, ?array $only = null): array
    {
        $sql = 'SELECT channel_id, unit_id, listing_id, sku_id, units_per_item, warehouse_id, state, dispatched_at FROM reservation_unit WHERE reservation_id = ?';
        $params = [$reservationId];
        if ($only !== null) {
            if ($only === []) {
                return [];
            }
            $sql .= ' AND unit_id IN (' . implode(',', array_fill(0, count($only), '?')) . ')';
            array_push($params, ...$only);
        }
        $out = [];
        foreach ($this->db->all($sql . ' ORDER BY unit_id', $params) as $r) {
            $out[(string) $r['unit_id']] = ['channel_id' => (int) $r['channel_id'], 'unit_id' => (string) $r['unit_id'],
                'listing_id' => (int) $r['listing_id'], 'sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'],
                'u' => (int) $r['units_per_item'], 'warehouse_id' => (int) $r['warehouse_id'], 'state' => (string) $r['state'],
                'dispatched_at' => $r['dispatched_at'] === null ? null : (string) $r['dispatched_at']];
        }
        return $out;
    }

    /**
     * 422 when a unit id of the body already belongs to another order of this channel.
     *
     * @param list<array{unit_ids: list<string>}> $lines
     */
    private function unitConflicts(int $channelId, ?int $reservationId, array $lines): ?OpResult
    {
        $ids = array_merge(...array_column($lines, 'unit_ids'));
        $taken = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = $this->db->all(
                'SELECT unit_id, reservation_id FROM reservation_unit WHERE channel_id = ? AND unit_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')',
                [$channelId, ...$chunk],
            );
            foreach ($rows as $r) {
                if ($reservationId === null || (int) $r['reservation_id'] !== $reservationId) {
                    $taken[] = (string) $r['unit_id'];
                }
            }
        }
        if ($taken === []) {
            return null;
        }
        sort($taken, SORT_STRING);
        return OpResult::of(422, ['error' => 'unit_conflict', 'message' => 'unit ids already belong to another order', 'unit_ids' => $taken]);
    }

    /**
     * The link of each variant as it is now (the sale-time snapshot), read FOR SHARE (R4): a link
     * change (X on the listing row, then adoptUnlinkedUnits in the same transaction) and a sale of
     * that listing serialise. The sale either sees the old link, commits first and has its
     * unlinked units adopted by the link transaction, or waits and sees the new link.
     *
     * @param list<string> $variantIds
     * @return array<string, array{listing_id: int, sku_id: ?int, u: int, status: string}>
     */
    private function listings(int $channelId, array $variantIds): array
    {
        $variantIds = array_values(array_unique($variantIds));
        $out = [];
        for ($pass = 0; $pass < 2; $pass++) {
            foreach (array_chunk($variantIds, 1000) as $chunk) {
                $rows = $this->db->all(
                    'SELECT id, external_variant_id, sku_id, units_per_item, status FROM channel_listing WHERE channel_id = ? '
                    . 'AND external_variant_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY id FOR SHARE',
                    [$channelId, ...$chunk],
                );
                foreach ($rows as $r) {
                    $linked = in_array($r['status'], ['mapped', 'quarantined'], true);
                    $out[(string) $r['external_variant_id']] = ['listing_id' => (int) $r['id'],
                        'sku_id' => $linked ? (int) $r['sku_id'] : null, 'u' => (int) $r['units_per_item'], 'status' => (string) $r['status']];
                }
            }
            // A variant sent in another spelling than the stored one (the column is _ai_ci, so
            // 'ABC' is the row 'abc'): ask the database which row it is, as the unique key does.
            foreach ($variantIds as $v) {
                if (isset($out[$v])) {
                    continue;
                }
                $r = $this->db->one(
                    'SELECT id, sku_id, units_per_item, status FROM channel_listing WHERE channel_id = ? AND external_variant_id = ? FOR SHARE',
                    [$channelId, $v],
                );
                if ($r !== null) {
                    $linked = in_array($r['status'], ['mapped', 'quarantined'], true);
                    $out[$v] = ['listing_id' => (int) $r['id'],
                        'sku_id' => $linked ? (int) $r['sku_id'] : null, 'u' => (int) $r['units_per_item'], 'status' => (string) $r['status']];
                }
            }
            $missing = array_values(array_diff($variantIds, array_map('strval', array_keys($out))));
            if ($missing === []) {
                return $out;
            }
            $this->ensureListings($channelId, $missing);
        }
        throw new \RuntimeException('listing rows could not be created');
    }

    /**
     * Unknown variants get an `unmapped` listing row (D13) — the DecisionService's job once it
     * exists; until then this is the only place that creates listing rows from sales.
     *
     * @param list<string> $variantIds
     */
    private function ensureListings(int $channelId, array $variantIds): void
    {
        foreach (array_chunk(array_values(array_unique($variantIds)), 1000) as $chunk) {
            $have = $this->db->column(
                'SELECT external_variant_id FROM channel_listing WHERE channel_id = ? AND external_variant_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')',
                [$channelId, ...$chunk],
            );
            foreach (array_diff($chunk, array_map('strval', $have)) as $v) {
                $this->db->exec('INSERT IGNORE INTO channel_listing (channel_id, external_variant_id) VALUES (?, ?)', [$channelId, $v]);
            }
        }
    }

    /**
     * Reads (FOR SHARE, in id order: the last step of the lock order) the policy and code of skus.
     *
     * @param list<int> $skuIds
     * @return array<int, array{code: string, policy: string}>
     */
    private function lockSkus(array $skuIds): array
    {
        $skuIds = array_values(array_unique(array_map('intval', $skuIds)));
        if ($skuIds === []) {
            return [];
        }
        sort($skuIds);
        $out = [];
        foreach (array_chunk($skuIds, 1000) as $chunk) {
            $rows = $this->db->all(
                'SELECT id, code, sell_policy FROM sku WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY id FOR SHARE',
                $chunk,
            );
            foreach ($rows as $r) {
                $out[(int) $r['id']] = ['code' => (string) $r['code'], 'policy' => (string) $r['sell_policy']];
            }
        }
        return $out;
    }

    /** @param array{listing_id: int, sku_id: ?int, u: int, status: string} $l */
    private function writeUnit(int $channelId, string $unitId, int $resId, array $l, int $warehouseId, string $state, bool $exists): void
    {
        if ($exists) {
            $this->db->exec(
                'UPDATE reservation_unit SET reservation_id = ?, listing_id = ?, sku_id = ?, units_per_item = ?, warehouse_id = ?, '
                . 'state = ?, dispatched_at = NULL WHERE channel_id = ? AND unit_id = ?',
                [$resId, $l['listing_id'], $l['sku_id'], $l['u'], $warehouseId, $state, $channelId, $unitId],
            );
            return;
        }
        try {
            $this->db->exec(
                'INSERT INTO reservation_unit (channel_id, unit_id, reservation_id, listing_id, sku_id, units_per_item, warehouse_id, state) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$channelId, $unitId, $resId, $l['listing_id'], $l['sku_id'], $l['u'], $warehouseId, $state],
            );
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === self::ER_DUP_ENTRY) {
                // Another order claimed this unit id concurrently: start over (-> 422 unit_conflict).
                throw new RetryOperation('unit ' . $unitId . ' claimed concurrently', 0, $e);
            }
            throw $e;
        }
    }

    private function setUnitState(int $channelId, string $unitId, string $state): void
    {
        $this->db->exec('UPDATE reservation_unit SET state = ? WHERE channel_id = ? AND unit_id = ?', [$state, $channelId, $unitId]);
    }

    /** @return array{type: string, actor: string, channel_id: ?int, order_ref: string, unit_id: ?string, idem_key: ?string, note: ?string} */
    private function move(string $type, Caller $caller, string $orderRef, ?string $unitId, ?string $idemKey, ?string $note = null): array
    {
        return ['type' => $type, 'actor' => $caller->actor, 'channel_id' => $caller->channelId, 'order_ref' => $orderRef,
            'unit_id' => $unitId, 'idem_key' => $idemKey, 'note' => $note];
    }

    /** floor(available / u): what the site shows (may be negative for backorder items). */
    public static function listingUnits(int $centralAvailable, int $u): int
    {
        return (int) floor($centralAvailable / max(1, $u));
    }

    public static function ref(string $orderRef): string
    {
        if ($orderRef === '' || strlen($orderRef) > 64 || preg_match('/^[\x21-\x7e]+$/', $orderRef) !== 1) {
            throw new CwException('bad_order_ref', 'order_ref must be 1-64 printable characters', 400);
        }
        return $orderRef;
    }

    private static function checkOrigin(string $origin): void
    {
        if (!in_array($origin, self::ORIGINS, true)) {
            throw new CwException('bad_origin', 'origin must be one of ' . implode(', ', self::ORIGINS), 400);
        }
    }

    /**
     * Validates and canonicalises order lines: each {variant_id, qty, unit_ids} with
     * qty == count(unit_ids) (one unit id per listing unit sold, §2.2), unit ids unique across
     * the order, lines sorted by variant then first unit, unit ids sorted.
     *
     * @return list<array{variant_id: string, qty: int, unit_ids: list<string>}>
     */
    public static function normaliseLines(mixed $lines): array
    {
        if (!is_array($lines) || $lines === [] || !array_is_list($lines)) {
            throw new CwException('bad_lines', 'lines must be a non-empty list', 400);
        }
        if (count($lines) > self::MAX_LINES) {
            throw new CwException('bad_lines', 'too many lines', 413);
        }
        $seen = [];
        $out = [];
        foreach ($lines as $i => $line) {
            if (!is_array($line)) {
                throw new CwException('bad_lines', "lines[{$i}] must be an object", 400);
            }
            $variant = self::id($line['variant_id'] ?? null, "lines[{$i}].variant_id");
            $units = $line['unit_ids'] ?? null;
            if (!is_array($units) || $units === [] || !array_is_list($units)) {
                throw new CwException('bad_lines', "lines[{$i}].unit_ids must be a non-empty list", 400);
            }
            if (count($seen) + count($units) > self::MAX_ORDER_UNITS) {
                throw new CwException('too_many_units', 'an order may have at most ' . self::MAX_ORDER_UNITS . ' units', 413);
            }
            $ids = [];
            foreach ($units as $u) {
                $id = self::id($u, "lines[{$i}].unit_ids");
                if (isset($seen[$id])) {
                    throw new CwException('duplicate_unit', "unit {$id} appears twice", 400);
                }
                $seen[$id] = true;
                $ids[] = $id;
            }
            $qty = $line['qty'] ?? count($ids);
            if (!is_int($qty) || $qty !== count($ids)) {
                throw new CwException('bad_lines', "lines[{$i}].qty must equal the number of unit_ids", 400);
            }
            sort($ids, SORT_STRING);
            $out[] = ['variant_id' => $variant, 'qty' => $qty, 'unit_ids' => $ids];
        }
        usort($out, static fn (array $a, array $b): int => [$a['variant_id'], $a['unit_ids'][0]] <=> [$b['variant_id'], $b['unit_ids'][0]]);
        return $out;
    }

    /** @param list<array{variant_id: string, qty: int, unit_ids: list<string>}> $lines */
    public static function linesHash(array $lines): string
    {
        return hash('sha256', Idempotency::canonicalJson($lines));
    }

    /** @param list<string|int> $unitIds @return list<string> */
    private static function unitIds(mixed $unitIds): array
    {
        if (!is_array($unitIds) || $unitIds === [] || !array_is_list($unitIds)) {
            throw new CwException('bad_unit_ids', 'unit_ids must be a non-empty list', 400);
        }
        if (count($unitIds) > self::MAX_ORDER_UNITS) {
            throw new CwException('too_many_units', 'send at most ' . self::MAX_ORDER_UNITS . ' unit ids per call', 413);
        }
        $out = [];
        foreach ($unitIds as $u) {
            $out[self::id($u, 'unit_ids')] = true;
        }
        $ids = array_map('strval', array_keys($out));
        sort($ids, SORT_STRING);
        return $ids;
    }

    private static function id(mixed $v, string $field): string
    {
        if (is_int($v)) {
            $v = (string) $v;
        }
        if (!is_string($v) || $v === '' || strlen($v) > 64 || preg_match('/^[\x21-\x7e]+$/', $v) !== 1) {
            throw new CwException('bad_id', "{$field} must be 1-64 printable characters", 400, ['field' => $field]);
        }
        return $v;
    }
}
