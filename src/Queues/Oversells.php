<?php

declare(strict_types=1);

namespace CW\Queues;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;

/**
 * Oversold orders (oversell_event; plan §3, R6; docs/decisions.md PF9): a paid order of a strict or stopped item that CW could not
 * cover, or a stock change that left one below zero. Approvals › From the stores › Oversold. CW has already booked what happened;
 * the list is for the people who sort it out with the customer.
 *
 *   handle()   marks the event handled: how (sent later, refunded, nothing needed) and a note. Settled once (409 after).
 *   addNote()  adds a note to an event, open or handled (queue_note, append-only: a note is never changed or removed).
 *
 * Who: queues.oversell (the purchasing desk and manager, the stock controller, the manager), re-read inside the transaction.
 */
final class Oversells
{
    public const TABLE = 'oversell_event';
    public const PERM = 'queues.oversell';
    /** How an oversold order was sorted out (oversell_event.resolution; 0001 names backordered, refunded, no_action). */
    public const RESOLUTIONS = ['backordered', 'refunded', 'no_action'];

    public function __construct(private readonly Db $db)
    {
    }

    public function openCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM oversell_event WHERE status = 'open'");
    }

    /** @return list<array<string, mixed>> open rows oldest first, or every row of a status newest first; each with its notes */
    public function list(?string $status = 'open', int $limit = QueueRules::PAGE + 1): array
    {
        $order = $status === 'open' ? 'o.created_at, o.id' : 'o.id DESC';
        $rows = $this->db->all(
            'SELECT STRAIGHT_JOIN o.id, o.warehouse_id, w.name AS warehouse, o.sku_id, s.code AS sku_code, s.name AS sku_name, o.channel_id, o.reservation_id, o.order_ref, '
            . 'o.kind, o.shortfall, o.available_after, CAST(o.status AS CHAR) AS status, o.resolution, o.resolved_by, o.resolved_at, o.note, o.created_at '
            . 'FROM oversell_event o JOIN sku s ON s.id = o.sku_id JOIN warehouse w ON w.id = o.warehouse_id'
            . ($status === null ? '' : ' WHERE o.status = ?') . " ORDER BY {$order} LIMIT " . max(1, min(500, $limit)),
            $status === null ? [] : [$status],
        );
        $notes = self::notes($this->db, self::TABLE, array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $names = QueueRules::names($this->db, [...array_column($rows, 'resolved_by'), ...array_merge([], ...array_map(
            static fn (array $l): array => array_column($l, 'staff_user_id'), array_values($notes)))]);
        $stores = QueueRules::stores($this->db);
        foreach ($rows as &$r) {
            $r['store'] = $r['channel_id'] === null ? null : ($stores[(int) $r['channel_id']] ?? null);
            $r['resolved_by_name'] = $r['resolved_by'] === null ? null : ($names[(int) $r['resolved_by']] ?? null);
            $r['notes'] = array_map(static fn (array $n): array => $n + ['by_name' => $n['staff_user_id'] === null ? null : ($names[(int) $n['staff_user_id']] ?? null)],
                $notes[(int) $r['id']] ?? []);
        }
        return $rows;
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
            Audit::write($db, $caller, 'oversell_event.handle', self::TABLE, (string) $id, null, ['resolution' => $resolution, 'note' => $note,
                'order_ref' => $row['order_ref'], 'kind' => $row['kind'], 'shortfall' => (int) $row['shortfall']]);
        });
    }

    /** Adds a note to an event (open or handled). @return int the note's id */
    public function addNote(Caller $caller, int $id, string $note): int
    {
        $note = QueueRules::note($note);
        return $this->db->transaction(function (Db $db) use ($caller, $id, $note): int {
            QueueRules::authorise($db, $caller, self::PERM);
            if ($db->value('SELECT id FROM oversell_event WHERE id = ?', [$id]) === null) {
                throw new CwException('unknown_queue_row', 'there is no such row in this queue', 404);
            }
            $nid = $db->insert('INSERT INTO queue_note (queue_name, row_id, staff_user_id, actor, note) VALUES (?, ?, ?, ?, ?)',
                [self::TABLE, $id, $caller->staffUserId, $caller->actor, $note]);
            Audit::write($db, $caller, 'oversell_event.note', self::TABLE, (string) $id, null, ['note' => $note, 'note_id' => $nid]);
            return $nid;
        });
    }

    /**
     * The notes of some rows of a queue, oldest first.
     *
     * @param list<int> $ids
     * @return array<int, list<array{id: int, staff_user_id: ?int, note: string, created_at: string}>>
     */
    public static function notes(Db $db, string $queue, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($db->all('SELECT id, row_id, staff_user_id, note, created_at FROM queue_note WHERE queue_name = ? AND row_id IN ('
            . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY row_id, id', [$queue, ...$ids]) as $n) {
            $out[(int) $n['row_id']][] = ['id' => (int) $n['id'], 'staff_user_id' => $n['staff_user_id'] === null ? null : (int) $n['staff_user_id'],
                'note' => (string) $n['note'], 'created_at' => (string) $n['created_at']];
        }
        return $out;
    }
}
