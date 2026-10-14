<?php

declare(strict_types=1);

namespace CW\Items;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;

/**
 * Repack records (RPK, pack B; owner answer Q3; docs/decisions.md PB9-PB13): Stock › Repack. book() makes a record and makes it final in
 * ONE transaction (the screen's one button; the form's key makes it happen once however often it is sent): a draft of the document base
 * (Documents::createDraft: who may, never admin), its header (`repack`: the pack item's definition, the way, how many packs, the units
 * per pack and both items as they are now), its two lines (RepackHandler::lines), then Documents::post, which checks it again
 * (RepackHandler::validate) and books it, or leaves it waiting for a reviewer's OK when a rule asks (off by default). A refusal at any
 * step leaves nothing behind. A record left a draft (an OK taken back by its maker) is made final or stopped with post() and cancel().
 * It is cancelled once final on the record's own page (Approvals › Records: Documents::reverse, the exact negation).
 *
 * Who: doc.RPK.post (Permissions: the stock controller and the warehouse), never admin; documents.view reads the list.
 */
final class Repacks
{
    public const LIST_LIMIT = 200;
    public const NOTE_MAX = 1000;

    public function __construct(private readonly Db $db, private readonly Documents $docs)
    {
    }

    /**
     * Makes a repack and makes it final. $in: warehouse (an id), pack (an item_pack id switched on), direction (break | make), packs
     * (1 or more; the singles' side at most Documents::MAX_QTY), note (optional), doc_date (Y-m-d, optional: today in the UK).
     * 422 warehouse_required / unknown_pack / pack_off / bad_packs / bad_direction; the document base's and the handler's refusals.
     *
     * @param array<string, mixed> $in
     */
    public function book(Caller $caller, array $in): Document
    {
        $direction = (string) ($in['direction'] ?? '');
        if (!in_array($direction, RepackHandler::DIRECTIONS, true)) {
            throw new CwException('bad_direction', 'say which way: break packs into singles, or make packs from singles', 422, ['field' => 'direction']);
        }
        $packs = $in['packs'] ?? null;
        if (!is_int($packs) || $packs < 1) {
            throw new CwException('bad_packs', 'say how many packs: a whole number, 1 or more', 422, ['field' => 'packs']);
        }
        $warehouse = $in['warehouse'] ?? null;
        if (!is_int($warehouse)) {
            throw new CwException('warehouse_required', 'say which warehouse', 422, ['field' => 'warehouse']);
        }
        $packId = $in['pack'] ?? null;
        if (!is_int($packId)) {
            throw new CwException('unknown_pack', 'say which pack item', 422, ['field' => 'pack']);
        }
        $note = isset($in['note']) && is_string($in['note']) && trim($in['note']) !== '' ? trim($in['note']) : null;
        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw new CwException('bad_note', 'a note is at most ' . self::NOTE_MAX . ' characters', 422, ['field' => 'note']);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $direction, $packs, $warehouse, $packId, $note, $in): Document {
            $p = $db->one('SELECT id, pack_sku_id, single_sku_id, units, is_active FROM item_pack WHERE id = ? FOR SHARE', [$packId])
                ?? throw new CwException('unknown_pack', 'there is no such pack item', 422, ['field' => 'pack']);
            if ((int) $p['is_active'] !== 1) {
                throw new CwException('pack_off', 'this pack item is switched off', 422, ['field' => 'pack']);
            }
            $units = (int) $p['units'];
            if ($packs * $units > Documents::MAX_QTY) {
                throw new CwException('bad_packs', 'that is more singles than one record holds: split it', 422, ['field' => 'packs']);
            }
            $code = $db->value('SELECT code FROM warehouse WHERE id = ?', [$warehouse])
                ?? throw new CwException('warehouse_required', 'there is no such warehouse', 422, ['field' => 'warehouse']);
            $doc = $this->docs->createDraft($caller, RepackHandler::TYPE, ['warehouse' => (string) $code, 'note' => $note]
                + (isset($in['doc_date']) && is_string($in['doc_date']) && $in['doc_date'] !== '' ? ['doc_date' => $in['doc_date']] : []));
            $db->exec('INSERT INTO repack (document_id, item_pack_id, direction, packs, units_per_pack, pack_sku_id, single_sku_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$doc->id, (int) $p['id'], $direction, $packs, $units, (int) $p['pack_sku_id'], (int) $p['single_sku_id']]);
            $doc = $this->docs->setLines($caller, $doc->id, $doc->version,
                RepackHandler::lines($direction, (int) $p['pack_sku_id'], (int) $p['single_sku_id'], $packs, $units));
            Audit::write($db, $caller, 'repack.book', 'document', (string) $doc->id, null, ['pack_id' => (int) $p['id'], 'direction' => $direction, 'packs' => $packs,
                'units_per_pack' => $units, 'warehouse' => (string) $code]);
            return $this->docs->post($caller, $doc->id, $doc->version);
        });
    }

    /** Makes a draft repack final (one left a draft when its maker took an OK request back). */
    public function post(Caller $caller, int $id, int $version): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version): Document {
            $this->mine($id);
            return $this->docs->post($caller, $id, $version);
        });
    }

    /** Stops a draft repack (never numbered, nothing booked). */
    public function cancel(Caller $caller, int $id, int $version, string $reason): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $reason): Document {
            $this->mine($id);
            return $this->docs->cancelDraft($caller, $id, $version, $reason);
        });
    }

    /** Takes back a repack waiting for a reviewer's OK (it goes back to a draft): its open approval task. */
    public function withdraw(Caller $caller, int $id): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id): Document {
            $this->mine($id);
            $task = $db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND kind = 'approval' AND state = 'open'", [$id])
                ?? throw new CwException('not_awaiting_approval', 'this repack is not waiting for an OK', 409);
            return $this->docs->withdraw($caller, (int) $task);
        });
    }

    /**
     * The repack records, newest first (at most LIST_LIMIT): number, status, date, warehouse, the way, packs, units per pack, both
     * items, who made it, the record it cancels. Filters: state (a document status), q (a record number or a CW number of either item).
     *
     * @param array{state?: ?string, q?: string} $f
     * @return list<array<string, mixed>>
     */
    public function list(array $f): array
    {
        $where = ["d.doc_type = 'RPK'"];
        $params = [];
        if (($f['state'] ?? null) !== null) {
            $where[] = 'd.status = ?';
            $params[] = $f['state'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $item = Families::resolveOne($this->db, $q);
            if ($item !== null) {
                // A record of the item, or a cancellation of one (ix_repack_pack_sku, ix_repack_single_sku).
                $where[] = '(d.id IN (SELECT x.document_id FROM repack x WHERE x.pack_sku_id = ? OR x.single_sku_id = ?) '
                    . 'OR d.reverses_id IN (SELECT y.document_id FROM repack y WHERE y.pack_sku_id = ? OR y.single_sku_id = ?))';
                array_push($params, $item, $item, $item, $item);
            } else {
                $where[] = 'd.number LIKE ?';
                $params[] = '%' . addcslashes($q, '%_\\') . '%';
            }
        }
        // The page's records first, from the records themselves (ix_document_type_status, newest first): a cancellation that is not
        // posted yet (waiting for an OK, refused or taken back) has no repack header of its own and is shown with the header of the
        // record it cancels (the review of b62c69f, finding 4). Then only those records are joined to what the list shows.
        return $this->db->all('SELECT d.id, d.number, d.status, d.version, d.doc_date, d.created_at, d.posted_at, d.created_by, d.reverses_id, d.note, '
            . 'w.name AS warehouse, COALESCE(r.direction, ro.direction) AS direction, COALESCE(r.packs, ro.packs) AS packs, '
            . 'COALESCE(r.units_per_pack, ro.units_per_pack) AS units_per_pack, COALESCE(r.pack_sku_id, ro.pack_sku_id) AS pack_sku_id, '
            . 'COALESCE(r.single_sku_id, ro.single_sku_id) AS single_sku_id, ps.code AS pack_code, ps.name AS pack_name, '
            . 'ss.code AS single_code, ss.name AS single_name, cu.display_name AS created_by_name, od.number AS reverses_number '
            . 'FROM (SELECT d.id FROM document d WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT ' . self::LIST_LIMIT . ') pick '
            . 'JOIN document d ON d.id = pick.id LEFT JOIN repack r ON r.document_id = d.id LEFT JOIN repack ro ON ro.document_id = d.reverses_id '
            . 'JOIN sku ps ON ps.id = COALESCE(r.pack_sku_id, ro.pack_sku_id) JOIN sku ss ON ss.id = COALESCE(r.single_sku_id, ro.single_sku_id) '
            . 'LEFT JOIN warehouse w ON w.id = d.warehouse_id LEFT JOIN staff_user cu ON cu.id = d.created_by LEFT JOIN document od ON od.id = d.reverses_id '
            . 'ORDER BY d.id DESC', $params);
    }

    /** The choices of the form: the warehouses switched on, and the pack definitions switched on (newest first, at most 500). @return array{warehouses: list<array<string, mixed>>, packs: list<array<string, mixed>>} */
    public function choices(): array
    {
        return [
            'warehouses' => $this->db->all('SELECT id, code, name FROM warehouse WHERE is_active = 1 ORDER BY sort_order, name'),
            'packs' => $this->db->all('SELECT p.id, p.units, ps.code AS pack_code, ps.name AS pack_name, ss.code AS single_code, ss.name AS single_name FROM item_pack p '
                . 'JOIN sku ps ON ps.id = p.pack_sku_id JOIN sku ss ON ss.id = p.single_sku_id WHERE p.is_active = 1 ORDER BY p.id DESC LIMIT 500'),
        ];
    }

    /** Today in the UK (a record's default date). */
    public static function today(): string
    {
        return Clock::now()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
    }

    /** 404 unknown_repack unless $id is a repack record. */
    private function mine(int $id): void
    {
        if ($this->db->value("SELECT 1 FROM document WHERE id = ? AND doc_type = 'RPK'", [$id]) === null) {
            throw new CwException('unknown_repack', 'there is no such repack', 404);
        }
    }
}
