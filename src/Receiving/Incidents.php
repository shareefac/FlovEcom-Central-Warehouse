<?php

declare(strict_types=1);

namespace CW\Receiving;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Staff\StaffRoles;

/**
 * The incident register (IM6 line exceptions; IM13 builds the duty register on it; docs/decisions.md I131, I132): one row per
 * exception of a posted receipt line (unstamped, damaged, wrong item, short, over), opened by the posting, with where its units
 * went (VERIFY, UNSTAMPED quarantine, refused at the door, not received).
 *
 * An incident is closed by a person holding incidents.resolve (purchasing desk, purchasing manager, stock controller; never
 * admin) with a note of 3-500 characters: `resolved` (dealt with: credit requested, goods returned, the supplier re-delivered) or
 * `dismissed` (no action needed). Closing one records what was done; it moves no stock (units leave VERIFY and UNSTAMPED through
 * IM2's documents). A reversed receipt dismisses its open incidents itself. Rows are append-only for the app login except the
 * resolution columns (Grants::UPDATE_COLUMNS).
 */
final class Incidents
{
    public const KINDS = ['unstamped' => 'unstamped', 'damaged' => 'damaged', 'wrong_item' => 'wrong item', 'short' => 'short', 'over' => 'over-delivered'];
    public const DISPOSITIONS = ['verify' => 'in VERIFY', 'quarantine' => 'quarantined in UNSTAMPED', 'refused' => 'refused at the door', 'not_received' => 'not received'];
    public const STATUSES = ['open', 'resolved', 'dismissed'];
    public const NOTE_MIN = 3;
    public const NOTE_MAX = 500;

    public function __construct(private readonly Db $db)
    {
    }

    public function openCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM incident WHERE status = 'open'");
    }

    /**
     * The register, oldest open first (filters: status, kind, document).
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $status = 'open', ?string $kind = null, ?int $documentId = null, int $limit = 500): array
    {
        $where = [];
        $params = [];
        if ($status !== null) {
            $where[] = 'i.status = ?';
            $params[] = $status;
        }
        if ($kind !== null) {
            $where[] = 'i.kind = ?';
            $params[] = $kind;
        }
        if ($documentId !== null) {
            $where[] = 'i.document_id = ?';
            $params[] = $documentId;
        }
        return $this->db->all(
            'SELECT i.*, d.number, d.external_ref, s.code AS sku_code, s.name AS sku_name, sp.code AS supplier_code, sp.name AS supplier_name, w.code AS warehouse, '
            . 'ou.display_name AS opened_by_name, ru.display_name AS resolved_by_name FROM incident i JOIN document d ON d.id = i.document_id '
            . 'LEFT JOIN sku s ON s.id = i.sku_id LEFT JOIN supplier sp ON sp.id = i.supplier_id LEFT JOIN warehouse w ON w.id = i.warehouse_id '
            . 'LEFT JOIN staff_user ou ON ou.id = i.opened_by LEFT JOIN staff_user ru ON ru.id = i.resolved_by'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY i.status = \'open\' DESC, i.opened_at, i.id LIMIT ' . $limit,
            $params,
        );
    }

    /**
     * Closes an open incident: $status resolved | dismissed, $note what was done (3-500 characters). 409 incident_closed when it is
     * no longer open (someone closed it meanwhile). Audit incident.resolve.
     *
     * @return array<string, mixed> the incident row
     */
    public function resolve(Caller $caller, int $id, string $status, string $note): array
    {
        if (!in_array($status, ['resolved', 'dismissed'], true)) {
            throw new CwException('bad_field', 'an incident is resolved or dismissed', 400, ['field' => 'status']);
        }
        $note = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $note));
        if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note) < self::NOTE_MIN || mb_strlen($note) > self::NOTE_MAX) {
            throw new CwException('note_required', 'say in ' . self::NOTE_MIN . ' to ' . self::NOTE_MAX . ' characters what was done (or why nothing is needed)', 400,
                ['field' => 'note']);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $id, $status, $note): array {
            if ($caller->staffUserId === null) {
                throw new CwException('staff_required', 'incidents are closed by staff', 403);
            }
            $roles = StaffRoles::active($db, $caller->staffUserId);
            if (in_array('admin', $roles, true)) {
                throw new CwException('admin_cannot_post', 'admin manages people and roles and never closes incidents', 403);
            }
            if (!Permissions::can($roles, 'incidents.resolve')) {
                throw new CwException('role_not_allowed', (count($roles) === 1 ? 'your role (' : 'your roles (') . implode(', ', $roles) . ') cannot close incidents', 403);
            }
            $row = $db->one('SELECT * FROM incident WHERE id = ? FOR UPDATE', [$id]) ?? throw new CwException('unknown_incident', 'there is no such incident', 404);
            if ($row['status'] !== 'open') {
                throw new CwException('incident_closed', 'this incident is already ' . $row['status'], 409, ['status' => $row['status']]);
            }
            $db->exec('UPDATE incident SET status = ?, resolution = ?, resolved_by = ?, resolved_actor = ?, resolved_at = ? WHERE id = ?',
                [$status, $note, $caller->staffUserId, $caller->actor, Clock::db(Clock::now()), $id]);
            Audit::write($db, $caller, 'incident.resolve', 'incident', (string) $id, null, ['status' => $status, 'note' => $note, 'kind' => $row['kind'],
                'document_id' => (int) $row['document_id'], 'line_no' => (int) $row['line_no'], 'units' => (int) $row['units']]);
            return (array) $db->one('SELECT * FROM incident WHERE id = ?', [$id]);
        });
    }
}
