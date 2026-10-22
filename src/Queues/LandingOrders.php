<?php

declare(strict_types=1);

namespace CW\Queues;

use CW\Audit;
use CW\Caller;
use CW\Db;
use CW\CwException;

/**
 * Website bugs (landing_order, 0050; docs/decisions.md LP7-LP9): an order a website took for a LANDING PAGE, which is not a product
 * (the owner, 22 Oct 2026: "Even if you see any sale on it, that only means something is broken"). Approvals › From the stores ›
 * Website bugs. The reservations service writes the row when the order reaches the warehouse (CW\Reservations::flagLandingLines());
 * no stock moved for it. The list is for the people who fix the order by hand with the customer (the option the customer wanted, a
 * refund), as for an oversold order; bin/health_alert.php alerts while one is open (CW\Ops\Alerts).
 *
 *   handle()  marks it handled: how (fixed by hand, refunded, nothing needed) and a note. Settled once (409 after).
 *
 * Who: queues.oversell (the people who sort orders out with the customer), re-read inside the transaction.
 */
final class LandingOrders
{
    public const TABLE = 'landing_order';
    public const PERM = 'queues.oversell';
    /** How an order for a landing page was sorted out (landing_order.resolution). */
    public const RESOLUTIONS = ['fixed_by_hand', 'refunded', 'no_action'];

    public function __construct(private readonly Db $db)
    {
    }

    public function openCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM landing_order WHERE status = 'open'");
    }

    /**
     * Open rows oldest first, or every row of a status newest first; each with its store, the landing page's titles and its variant.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $status = 'open', int $limit = QueueRules::PAGE + 1): array
    {
        $order = $status === 'open' ? 'o.created_at, o.id' : 'o.id DESC';
        $rows = $this->db->all(
            'SELECT o.id, o.channel_id, o.order_ref, o.listing_id, o.reservation_id, o.seen_in, o.units, o.paid, CAST(o.status AS CHAR) AS status, o.resolution, '
            . 'o.resolved_by, o.resolved_at, o.note, o.created_at, cl.external_variant_id, CAST(cl.status AS CHAR) AS listing_status, lp.product_title, lp.variant_title '
            . 'FROM landing_order o JOIN channel_listing cl ON cl.id = o.listing_id LEFT JOIN listing_profile lp ON lp.listing_id = o.listing_id'
            . ($status === null ? '' : ' WHERE o.status = ?') . " ORDER BY {$order} LIMIT " . max(1, min(500, $limit)),
            $status === null ? [] : [$status],
        );
        $names = QueueRules::names($this->db, array_column($rows, 'resolved_by'));
        $stores = QueueRules::stores($this->db);
        foreach ($rows as &$r) {
            $r['store'] = $stores[(int) $r['channel_id']] ?? null;
            $r['resolved_by_name'] = $r['resolved_by'] === null ? null : ($names[(int) $r['resolved_by']] ?? null);
        }
        return $rows;
    }

    /**
     * Per store, the open rows: how many, and the newest (its order, the landing page's titles and variant): what the alert says.
     *
     * @return list<array{channel_id: int, code: string, name: string, open: int, order_ref: string, variant: string, title: ?string, option: ?string}>
     */
    public function openByStore(): array
    {
        $out = [];
        foreach ($this->db->all("SELECT o.channel_id, ch.code, ch.name, COUNT(*) AS n, MAX(o.id) AS newest FROM landing_order o JOIN channel ch ON ch.id = o.channel_id "
            . "WHERE o.status = 'open' GROUP BY o.channel_id, ch.code, ch.name ORDER BY o.channel_id") as $r) {
            $n = $this->db->one('SELECT o.order_ref, cl.external_variant_id, lp.product_title, lp.variant_title FROM landing_order o JOIN channel_listing cl ON cl.id = o.listing_id '
                . 'LEFT JOIN listing_profile lp ON lp.listing_id = o.listing_id WHERE o.id = ?', [(int) $r['newest']]);
            $out[] = ['channel_id' => (int) $r['channel_id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'open' => (int) $r['n'],
                'order_ref' => (string) ($n['order_ref'] ?? ''), 'variant' => (string) ($n['external_variant_id'] ?? ''),
                'title' => isset($n['product_title']) ? (string) $n['product_title'] : null, 'option' => isset($n['variant_title']) ? (string) $n['variant_title'] : null];
        }
        return $out;
    }

    public function handle(Caller $caller, int $id, string $resolution, string $note): void
    {
        if (!in_array($resolution, self::RESOLUTIONS, true)) {
            throw new CwException('bad_field', 'say how it was sorted out: ' . implode(', ', self::RESOLUTIONS), 400, ['field' => 'resolution']);
        }
        $note = QueueRules::note($note);
        $this->db->transaction(function (Db $db) use ($caller, $id, $resolution, $note): void {
            QueueRules::authorise($db, $caller, self::PERM);
            $row = QueueRules::lockOpen($db, self::TABLE, $id);
            QueueRules::settle($db, $caller, self::TABLE, $id, 'resolved', $resolution, $note);
            Audit::write($db, $caller, 'landing_order.handle', self::TABLE, (string) $id, null, ['resolution' => $resolution, 'note' => $note,
                'order_ref' => $row['order_ref'], 'listing_id' => (int) $row['listing_id'], 'units' => (int) $row['units']]);
        });
    }
}
