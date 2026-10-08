<?php

declare(strict_types=1);

namespace CW\Suppliers;

use CW\Admin\ApprovalRules;
use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Settings;
use CW\Staff\StaffRoles;

/**
 * Suppliers (IM4; docs/decisions.md I38-I47): the supplier record, its state machine and its approvals.
 *
 *   draft --request activation--> pending_approval --approve (a second person)--> active --deactivate--> inactive
 *   pending_approval --withdraw (the requester) / reject--> draft (never approved) or inactive (a reactivation)
 *   inactive --request activation--> pending_approval (reason reactivation)
 *
 * A supplier becomes active only through a BLOCKING approval by a second person (decision 11, provisional): a
 * review_task with subject_type 'supplier', kind 'approval', reason new_supplier / reactivation, decided by someone
 * holding suppliers.approve who is not admin, did not ask for it, did not create the supplier and did not last change
 * an approval-relevant field (refusal(); ck_review_task_not_own holds the opener in SQL too). An overseas supplier's
 * import route (how and where UK duty stamps are applied) is approved with the activation; changing the route of an
 * active overseas supplier clears that approval and opens a blocking `import_route` approval (POs are refused until
 * it is approved). Any other identity change of an active supplier opens a NON-blocking review (reason
 * supplier_changed, when suppliers.change_review is true): approving it acknowledges the change, rejecting it
 * deactivates the supplier.
 *
 * Every public write is ONE Db::transaction (joins an open one), takes a staff caller (403 staff_required), re-reads
 * the caller's roles inside it (an inactive person is refused) and locks the supplier row FOR UPDATE, then its
 * review_task rows FOR UPDATE (the lock order of I21 / spec §6.9); supplier code never locks a document row and books
 * no stock. Writes carry the version the form was drawn with (409 version_conflict) or rely on a state check (409).
 * No bank details exist anywhere (decision 25).
 */
final class Suppliers
{
    public const STATUSES = ['draft', 'pending_approval', 'active', 'inactive'];
    /** Days a supplier_changed review is due after it is opened. */
    public const CHANGE_REVIEW_DUE_DAYS = 7;
    public const NOTE_MIN = 3;
    public const NOTE_MAX = 500;
    public const APPROVAL_REASONS = ['new_supplier', 'reactivation'];

    /**
     * The fields a person (the form, the import) may set: column => [kind, ...limits]. Kinds: code, line (one line,
     * max), text (line breaks allowed, max), country, email, int (min, max), money, vat, bool, date, staff.
     */
    public const FIELDS = [
        'code' => ['code'],
        'name' => ['line', 128],
        'legal_name' => ['line', 160],
        'company_number' => ['line', 16],
        'vat_number' => ['line', 20],
        'address_line1' => ['line', 128],
        'address_line2' => ['line', 128],
        'city' => ['line', 64],
        'postcode' => ['line', 16],
        'country' => ['country'],
        'contact_name' => ['line', 128],
        'email' => ['email', 191],
        'phone' => ['line', 32],
        'contacts_note' => ['text', 500],
        'payment_terms' => ['line', 100],
        'payment_terms_days' => ['int', 0, 365],
        'default_lead_days' => ['int', 0, 120],
        'review_days' => ['int', 1, 120],
        'min_order_value' => ['money'],
        'default_vat_code' => ['vat'],
        'is_overseas' => ['bool'],
        'import_route' => ['text', 1000],
        'dd_checked_on' => ['date'],
        'dd_checked_by' => ['staff'],
        'dd_evidence' => ['text', 1000],
        'dd_next_review_on' => ['date'],
        'notes' => ['text', 1000],
    ];
    /** The ERPNext name: set only by the seed import (create with $allowErpName). The evidence file ids: only setEvidence(). */
    private const ERP_NAME = ['line', 140];

    /** Approval-relevant fields: a change sets details_changed_by/at; on an active supplier it is reviewed. */
    public const IDENTITY = ['name', 'legal_name', 'company_number', 'vat_number', 'address_line1', 'address_line2', 'city', 'postcode', 'country',
        'email', 'is_overseas', 'import_route', 'import_route_file_id', 'dd_checked_on', 'dd_checked_by', 'dd_evidence', 'dd_evidence_file_id',
        'dd_next_review_on'];
    /** The import-route fields: a change on an active overseas supplier needs the route approved again (blocking). */
    public const ROUTE = ['is_overseas', 'import_route', 'import_route_file_id'];

    /** People's names for the fields (messages, the completeness list). */
    public const LABELS = [
        'code' => 'code', 'name' => 'name', 'legal_name' => 'legal name', 'company_number' => 'company number', 'vat_number' => 'VAT number',
        'address_line1' => 'address line 1', 'address_line2' => 'address line 2', 'city' => 'town / city', 'postcode' => 'postcode',
        'country' => 'country', 'contact_name' => 'contact name', 'email' => 'e-mail', 'phone' => 'phone', 'contacts_note' => 'other contacts',
        'payment_terms' => 'payment terms', 'payment_terms_days' => 'payment days', 'default_lead_days' => 'lead days', 'review_days' => 'order cycle days',
        'min_order_value' => 'minimum order value', 'default_vat_code' => 'VAT code', 'is_overseas' => 'overseas', 'import_route' => 'import route',
        'import_route_file_id' => 'import route evidence file', 'dd_checked_on' => 'due diligence checked on', 'dd_checked_by' => 'due diligence checked by',
        'dd_evidence' => 'due diligence evidence', 'dd_evidence_file_id' => 'due diligence evidence file', 'dd_next_review_on' => 'next due diligence review',
        'notes' => 'notes', 'erp_name' => 'ERPNext name', 'email_or_phone' => 'e-mail or phone',
    ];

    private readonly Settings $settings;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock (task due dates, "today") */
    public function __construct(private readonly Db $db, ?Settings $settings = null, ?\Closure $clock = null)
    {
        $this->settings = $settings ?? new Settings($db);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    // ------------------------------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null the supplier row */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM supplier WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed> the supplier row (404 unknown_supplier) */
    public function get(int $id): array
    {
        return $this->find($id) ?? throw new CwException('unknown_supplier', 'there is no such supplier', 404);
    }

    /** @return array<string, mixed>|null a supplier by its CW code or its ERPNext name */
    public function findByCodeOrErpName(string $ref): ?array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }
        return $this->db->one('SELECT * FROM supplier WHERE erp_name = ? LIMIT 1', [$ref]) ?? $this->db->one('SELECT * FROM supplier WHERE code = ?', [strtoupper($ref)]);
    }

    /**
     * What an activation request still needs (§5.2 completeness), as field names ([] = complete): name; address line 1,
     * postcode and country; e-mail or phone; payment terms; the due-diligence check (on, by, next review); for an
     * overseas supplier the import route.
     *
     * @param array<string, mixed> $s a supplier row
     * @return list<string>
     */
    public static function missing(array $s): array
    {
        $out = [];
        $blank = static fn (string $k): bool => !isset($s[$k]) || $s[$k] === null || (is_string($s[$k]) && trim($s[$k]) === '');
        foreach (['name', 'address_line1', 'postcode', 'country'] as $k) {
            if ($blank($k)) {
                $out[] = $k;
            }
        }
        if ($blank('email') && $blank('phone')) {
            $out[] = 'email_or_phone';
        }
        foreach (['payment_terms', 'dd_checked_on', 'dd_checked_by', 'dd_next_review_on'] as $k) {
            if ($blank($k)) {
                $out[] = $k;
            }
        }
        if ((int) ($s['is_overseas'] ?? 0) === 1 && $blank('import_route')) {
            $out[] = 'import_route';
        }
        return $out;
    }

    /** @param list<string> $fields @return string "e-mail or phone, payment terms" */
    public static function labels(array $fields): string
    {
        return implode(', ', array_map(static fn (string $f): string => self::LABELS[$f] ?? $f, $fields));
    }

    /**
     * Why this person may NOT decide $task on $supplier, or null when they may (the screens show the reason instead of
     * the forms; the service refuses with it, 403).
     *
     * @param list<string> $roles
     * @param array<string, mixed> $supplier
     * @param array<string, mixed> $task review_task row
     * @return array{code: string, message: string}|null
     */
    public static function refusal(int $staffId, array $roles, array $supplier, array $task): ?array
    {
        if (in_array('admin', $roles, true)) {
            return ['code' => 'admin_cannot_review', 'message' => 'admin manages people and roles and never approves or reviews suppliers (I12).'];
        }
        if (!Permissions::can($roles, 'suppliers.approve')) {
            return ['code' => 'role_not_allowed', 'message' => ucfirst(self::rolesPhrase($roles)) . ' cannot approve or review suppliers.'];
        }
        $id = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        return match ($staffId) {
            $id($task['opened_by'] ?? null) => ['code' => 'own_supplier', 'message' => ($task['kind'] ?? '') === 'review'
                ? 'You last changed this supplier: another reviewer must decide.' : 'You asked for this approval: another reviewer must decide.'],
            $id($supplier['created_by'] ?? null) => ['code' => 'own_supplier', 'message' => 'You created this supplier: another reviewer must decide.'],
            $id($supplier['details_changed_by'] ?? null) => ['code' => 'own_supplier', 'message' => 'You last changed this supplier: another reviewer must decide.'],
            default => null,
        };
    }

    /**
     * Open supplier tasks this person may decide (added to the menu badge reviews_open): only for holders of
     * suppliers.approve (never admin), not opened by them, not on a supplier they created or last changed.
     *
     * @param list<string> $roles
     */
    public function decidableCount(int $staffId, array $roles): int
    {
        return array_sum($this->decidableCounts($staffId, $roles));
    }

    /**
     * decidableCount() by kind ('review' | 'approval'), in one query (the Home page's two cards).
     *
     * @param list<string> $roles
     * @return array{review: int, approval: int}
     */
    public function decidableCounts(int $staffId, array $roles): array
    {
        $out = ['review' => 0, 'approval' => 0];
        if (in_array('admin', $roles, true) || !Permissions::can($roles, 'suppliers.approve')) {
            return $out;
        }
        foreach ($this->db->all(
            "SELECT t.kind, COUNT(*) AS n FROM review_task t JOIN supplier s ON s.id = t.subject_id WHERE t.subject_type = 'supplier' AND t.state = 'open' "
            . 'AND NOT (t.opened_by <=> ?) AND NOT (s.created_by <=> ?) AND NOT (s.details_changed_by <=> ?) GROUP BY t.kind',
            [$staffId, $staffId, $staffId],
        ) as $r) {
            $out[(string) $r['kind']] = ($out[(string) $r['kind']] ?? 0) + (int) $r['n'];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> the supplier's review tasks, oldest first, with the people's names */
    public function tasks(int $supplierId): array
    {
        return $this->db->all(
            'SELECT t.*, o.display_name AS opened_by_name, x.display_name AS decided_by_name FROM review_task t '
            . 'LEFT JOIN staff_user o ON o.id = t.opened_by LEFT JOIN staff_user x ON x.id = t.decided_by '
            . "WHERE t.subject_type = 'supplier' AND t.subject_id = ? ORDER BY t.id",
            [$supplierId],
        );
    }

    /** The UK date of CW's clock (due diligence dates are UK calendar dates). */
    public function today(): string
    {
        return ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
    }

    // ------------------------------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------------------------------

    /**
     * A new supplier, status draft (suppliers.manage). $fields: FIELDS (name required; code generated from the name when
     * blank: codeFor()), and erp_name for the seed import. 409 duplicate_code / duplicate_erp_name; 422 bad_field.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed> the new row
     */
    public function create(Caller $caller, array $fields, bool $allowErpName = false): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $fields, $allowErpName): array {
            $me = $this->staff($caller, 'suppliers.manage');
            $allowed = self::FIELDS + ($allowErpName ? ['erp_name' => self::ERP_NAME] : []);
            $v = $this->normalise($fields, $allowed);
            if (($v['name'] ?? null) === null) {
                throw new CwException('bad_field', 'name: a supplier needs a name', 422, ['field' => 'name']);
            }
            if (($v['code'] ?? null) === null) {
                $v['code'] = $this->codeFor((string) $v['name']);
            }
            $this->checkUnique($v, null);
            $this->checkDd($v);
            self::checkOverseas($v + ['country' => 'GB', 'is_overseas' => 0]);
            $now = $this->nowDb();
            $cols = array_keys($v);
            $params = array_values($v);
            array_push($cols, 'status', 'version', 'created_by', 'created_actor', 'created_at', 'updated_by', 'updated_actor', 'updated_at',
                'details_changed_by', 'details_changed_at');
            array_push($params, 'draft', 1, $me['id'], $caller->actor, $now, $me['id'], $caller->actor, $now, $me['id'], $now);
            try {
                $id = $db->insert('INSERT INTO supplier (' . implode(', ', array_map(static fn (string $c): string => "`{$c}`", $cols)) . ') VALUES ('
                    . implode(', ', array_fill(0, count($cols), '?')) . ')', $params);
            } catch (\PDOException $e) {
                throw self::duplicate($e) ?? $e;
            }
            Audit::write($db, $caller, 'supplier.create', 'supplier', (string) $id, null, ['code' => $v['code'], 'name' => $v['name'], 'fields' => $v]);
            return $this->get($id);
        });
    }

    /**
     * Changes fields of a draft, active or inactive supplier (suppliers.manage, at $expectedVersion). 409 supplier_pending
     * while an activation request waits ("withdraw it first"). An approval-relevant change sets details_changed_by/at; on
     * an ACTIVE supplier it opens the supplier_changed review (non-blocking, when suppliers.change_review is on and none is
     * open), and a change of the import route of an overseas supplier clears the route's approval and opens a blocking
     * import_route approval (an active supplier made overseas needs a route: 422 supplier_incomplete). Nothing changed:
     * nothing is written.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed> the row afterwards
     */
    public function update(Caller $caller, int $id, int $expectedVersion, array $fields): array
    {
        return $this->change($caller, $id, $expectedVersion, $this->normaliseInput($fields, self::FIELDS), 'supplier.update');
    }

    /**
     * Records an evidence file (stored by FileStore, kind supplier_check) as the due-diligence evidence ($kind 'dd') or
     * the import-route evidence ('import_route'): an approval-relevant change like update().
     *
     * @return array<string, mixed>
     */
    public function setEvidence(Caller $caller, int $id, int $expectedVersion, string $kind, int $fileId): array
    {
        $col = match ($kind) {
            'dd' => 'dd_evidence_file_id',
            'import_route' => 'import_route_file_id',
            default => throw new CwException('bad_field', 'kind must be dd or import_route', 400, ['field' => 'kind']),
        };
        if ($this->db->value('SELECT 1 FROM stored_file WHERE id = ?', [$fileId]) === null) {
            throw new CwException('unknown_file', 'there is no such file', 404);
        }
        return $this->change($caller, $id, $expectedVersion, [$col => $fileId], 'supplier.evidence');
    }

    /**
     * Asks a second person to activate a draft or inactive supplier (suppliers.manage, at $expectedVersion): 422
     * supplier_incomplete (detail.missing) unless missing() is empty; status pending_approval and an open approval task
     * (reason new_supplier, or reactivation for a supplier that was approved before), due suppliers.approval_due_days.
     *
     * @return array<string, mixed>
     */
    public function requestActivation(Caller $caller, int $id, int $expectedVersion): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $expectedVersion): array {
            $me = $this->staff($caller, 'suppliers.manage');
            $s = $this->lock($id);
            if ($s['status'] === 'pending_approval') {
                throw new CwException('supplier_pending', "{$s['code']} is already waiting for approval", 409);
            }
            if ($s['status'] === 'active') {
                throw new CwException('already_active', "{$s['code']} is active already", 409);
            }
            $this->checkVersion($s, $expectedVersion);
            $missing = self::missing($s);
            if ($missing !== []) {
                throw new CwException('supplier_incomplete', 'complete these before asking for activation: ' . self::labels($missing), 422,
                    ['missing' => $missing]);
            }
            $reason = $s['approved_at'] === null ? 'new_supplier' : 'reactivation';
            $now = $this->nowDb();
            if (!ApprovalRules::on($db, 'approvals.supplier_activation')) {
                // The owner switched the second person off (Approval rules page, Y11): the request activates the supplier at once,
                // recorded as approved alone with the version of the switch that allowed it (S2, S3 check both).
                $evidence = ApprovalRules::evidence($db, 'approvals.supplier_activation');
                $overseas = (int) $s['is_overseas'] === 1;
                $db->exec("UPDATE supplier SET status = 'active', approved_by = ?, approved_at = ?, approved_alone = 1, route_alone = ?, alone_change_id = ?, "
                    . 'deactivated_by = NULL, deactivated_at = NULL, deactivate_reason = NULL, import_route_approved_by = ?, import_route_approved_at = ?, '
                    . 'version = version + 1, updated_by = ?, updated_actor = ?, updated_at = ? WHERE id = ?',
                    [$me['id'], $now, $overseas ? 1 : 0, $evidence, $overseas ? $me['id'] : null, $overseas ? $now : null, $me['id'], $caller->actor, $now, $id]);
                Audit::write($db, $caller, 'supplier.activate_alone', 'supplier', (string) $id, null,
                    ['reason' => $reason, 'code' => $s['code'], 'rule' => 'approvals.supplier_activation', 'change_id' => $evidence, 'route' => $overseas]);
                return $this->get($id);
            }
            $db->exec("UPDATE supplier SET status = 'pending_approval', deactivated_at = NULL, version = version + 1, updated_by = ?, updated_actor = ?, updated_at = ? "
                . 'WHERE id = ?', [$me['id'], $caller->actor, $now, $id]);
            $taskId = $this->openTask($id, 'approval', $reason, $me['id'], $caller->actor, $now, (int) $this->settings->get('suppliers.approval_due_days'));
            Audit::write($db, $caller, 'supplier.request_activation', 'supplier', (string) $id, null, ['task_id' => $taskId, 'reason' => $reason, 'code' => $s['code']]);
            return $this->get($id);
        });
    }

    /**
     * The requester withdraws their open activation request: the task is withdrawn; the supplier is a draft again, or
     * inactive again when it was a reactivation (inactive from now: deactivated_at = now, by the requester).
     *
     * @return array<string, mixed>
     */
    public function withdraw(Caller $caller, int $taskId): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $taskId): array {
            $me = $this->staff($caller, 'suppliers.manage');
            [$s, $task] = $this->lockTask($taskId);
            if ($task['kind'] !== 'approval' || !in_array($task['reason'], self::APPROVAL_REASONS, true) || $s['status'] !== 'pending_approval') {
                throw new CwException('not_withdrawable', 'only an open activation request is withdrawn', 409, ['reason' => $task['reason']]);
            }
            if ((int) $task['opened_by'] !== $me['id']) {
                throw new CwException('not_requester', 'only the person who asked for this activation can withdraw it', 403);
            }
            $now = $this->nowDb();
            $this->decideTask($taskId, 'withdrawn', null, 'withdrawn by the requester', $now);
            if ($task['reason'] === 'reactivation') {
                $db->exec("UPDATE supplier SET status = 'inactive', deactivated_by = ?, deactivated_at = ?, deactivate_reason = ?, version = version + 1, "
                    . 'updated_by = ?, updated_actor = ?, updated_at = ? WHERE id = ?',
                    [$me['id'], $now, mb_substr('reactivation withdrawn by the requester' . ($s['deactivate_reason'] !== null ? '; earlier: ' . $s['deactivate_reason'] : ''), 0, 500),
                        $me['id'], $caller->actor, $now, $s['id']]);
            } else {
                $db->exec("UPDATE supplier SET status = 'draft', version = version + 1, updated_by = ?, updated_actor = ?, updated_at = ? WHERE id = ?",
                    [$me['id'], $caller->actor, $now, $s['id']]);
            }
            Audit::write($db, $caller, 'supplier.withdraw', 'supplier', (string) $s['id'], null, ['task_id' => $taskId, 'reason' => $task['reason']]);
            return $this->get((int) $s['id']);
        });
    }

    /**
     * A second person approves an open supplier task (suppliers.approve and refusal() == null; $note optional, at most 500
     * characters):
     *  - an activation (new_supplier / reactivation): the supplier is active (approved_by/at; for an overseas supplier
     *    also import_route_approved_by/at; deactivated_* cleared);
     *  - an import_route approval: import_route_approved_by/at are set;
     *  - a supplier_changed review: the change is acknowledged.
     *
     * @return array<string, mixed>
     */
    public function approve(Caller $caller, int $taskId, ?string $note): array
    {
        $note = self::optNote($note);
        return $this->db->transaction(function (Db $db) use ($caller, $taskId, $note): array {
            $me = $this->staff($caller, null);
            [$s, $task] = $this->lockTask($taskId);
            $this->checkDecider($me, $s, $task);
            $now = $this->nowDb();
            $sid = (int) $s['id'];
            $base = 'version = version + 1, updated_by = ?, updated_actor = ?, updated_at = ?, last_decision_note = ?';
            if ($task['kind'] === 'approval' && in_array($task['reason'], self::APPROVAL_REASONS, true)) {
                if ($s['status'] !== 'pending_approval') {
                    throw new CwException('not_pending', "{$s['code']} is not waiting for approval", 409, ['status' => $s['status']]);
                }
                $overseas = (int) $s['is_overseas'] === 1;
                $this->decideTask($taskId, 'approved', $me['id'], $note, $now);
                $db->exec("UPDATE supplier SET status = 'active', approved_by = ?, approved_at = ?, deactivated_by = NULL, deactivated_at = NULL, deactivate_reason = NULL, "
                    . 'approved_alone = 0, route_alone = 0, alone_change_id = NULL, import_route_approved_by = ?, import_route_approved_at = ?, ' . $base . ' WHERE id = ?',
                    [$me['id'], $now, $overseas ? $me['id'] : null, $overseas ? $now : null, $me['id'], $caller->actor, $now, $note, $sid]);
            } elseif ($task['kind'] === 'approval' && $task['reason'] === 'import_route') {
                if ($s['status'] !== 'active' || ((int) $s['is_overseas'] === 1 && $s['import_route'] === null)) {
                    throw new CwException('not_applicable', "{$s['code']} has no import route waiting for approval", 409);
                }
                $this->decideTask($taskId, 'approved', $me['id'], $note, $now);
                if ((int) $s['is_overseas'] === 1) {
                    $db->exec('UPDATE supplier SET import_route_approved_by = ?, import_route_approved_at = ?, route_alone = 0, '
                        . 'alone_change_id = IF(approved_alone = 1, alone_change_id, NULL), ' . $base . ' WHERE id = ?',
                        [$me['id'], $now, $me['id'], $caller->actor, $now, $note, $sid]);
                } else {
                    // The supplier was made UK: the second person confirms it no longer needs an import route (I72).
                    $db->exec('UPDATE supplier SET ' . $base . ' WHERE id = ?', [$me['id'], $caller->actor, $now, $note, $sid]);
                }
            } else {
                $this->decideTask($taskId, 'approved', $me['id'], $note, $now);
                $db->exec('UPDATE supplier SET ' . $base . ' WHERE id = ?', [$me['id'], $caller->actor, $now, $note, $sid]);
            }
            Audit::write($db, $caller, 'supplier.approve', 'supplier', (string) $sid, null,
                ['task_id' => $taskId, 'kind' => $task['kind'], 'reason' => $task['reason'], 'note' => $note]);
            return $this->get($sid);
        });
    }

    /**
     * A second person rejects an open supplier task ($note 3-500 characters: the reason, last_decision_note):
     *  - a new supplier's activation: back to draft;
     *  - a reactivation: inactive again (from now, by the reviewer: "reactivation rejected: <note>");
     *  - an import_route approval: the route stays unapproved (POs stay refused) and the note is recorded;
     *  - a supplier_changed review: the supplier is deactivated ("rejected at review: <note>"; its other open task
     *    withdrawn).
     *
     * @return array<string, mixed>
     */
    public function reject(Caller $caller, int $taskId, string $note): array
    {
        $note = trim($note);
        if (mb_strlen($note) < self::NOTE_MIN || mb_strlen($note) > self::NOTE_MAX || !mb_check_encoding($note, 'UTF-8')) {
            throw new CwException('note_required', 'a rejection needs a note of ' . self::NOTE_MIN . ' to ' . self::NOTE_MAX . ' characters: it is the reason', 400,
                ['field' => 'note']);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $taskId, $note): array {
            $me = $this->staff($caller, null);
            [$s, $task] = $this->lockTask($taskId);
            $this->checkDecider($me, $s, $task);
            $now = $this->nowDb();
            $sid = (int) $s['id'];
            $base = 'version = version + 1, updated_by = ?, updated_actor = ?, updated_at = ?, last_decision_note = ?';
            $this->decideTask($taskId, 'rejected', $me['id'], $note, $now);
            if ($task['kind'] === 'approval' && $task['reason'] === 'new_supplier') {
                $this->requirePending($s);
                $db->exec("UPDATE supplier SET status = 'draft', {$base} WHERE id = ?", [$me['id'], $caller->actor, $now, $note, $sid]);
            } elseif ($task['kind'] === 'approval' && $task['reason'] === 'reactivation') {
                $this->requirePending($s);
                $db->exec("UPDATE supplier SET status = 'inactive', deactivated_by = ?, deactivated_at = ?, deactivate_reason = ?, {$base} WHERE id = ?",
                    [$me['id'], $now, mb_substr('reactivation rejected: ' . $note, 0, 500), $me['id'], $caller->actor, $now, $note, $sid]);
            } elseif ($s['status'] === 'active' && ($task['kind'] === 'review'
                || ($task['kind'] === 'approval' && $task['reason'] === 'import_route' && (int) $s['is_overseas'] !== 1))) {
                // A rejected change review, or a rejected "no longer overseas" (I72): the supplier is deactivated; a
                // reactivation is approved afresh (with the route, when it is overseas again).
                $this->withdrawOpenTasks($sid, 'the supplier was deactivated (its change review was rejected)', $now);
                $db->exec("UPDATE supplier SET status = 'inactive', deactivated_by = ?, deactivated_at = ?, deactivate_reason = ?, {$base} WHERE id = ?",
                    [$me['id'], $now, mb_substr('rejected at review: ' . $note, 0, 500), $me['id'], $caller->actor, $now, $note, $sid]);
            } else {
                // import_route (the route stays unapproved), or the review of a supplier no longer active: recorded only.
                $db->exec("UPDATE supplier SET {$base} WHERE id = ?", [$me['id'], $caller->actor, $now, $note, $sid]);
            }
            Audit::write($db, $caller, 'supplier.reject', 'supplier', (string) $sid, null,
                ['task_id' => $taskId, 'kind' => $task['kind'], 'reason' => $task['reason'], 'note' => $note]);
            return $this->get($sid);
        });
    }

    /**
     * Deactivates an active supplier (suppliers.manage, at $expectedVersion; $reason 3-500 characters): no new PO can be
     * approved for it; posted POs are unaffected. Its open tasks (a change review, an import-route approval) are
     * withdrawn: a reactivation is approved afresh.
     *
     * @return array<string, mixed>
     */
    public function deactivate(Caller $caller, int $id, int $expectedVersion, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::NOTE_MIN || mb_strlen($reason) > self::NOTE_MAX || !mb_check_encoding($reason, 'UTF-8')) {
            throw new CwException('reason_required', 'say in ' . self::NOTE_MIN . ' to ' . self::NOTE_MAX . ' characters why the supplier is deactivated', 400,
                ['field' => 'reason']);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $id, $expectedVersion, $reason): array {
            $me = $this->staff($caller, 'suppliers.manage');
            $s = $this->lock($id);
            if ($s['status'] !== 'active') {
                throw new CwException('not_active', "{$s['code']} is " . str_replace('_', ' ', (string) $s['status']) . ': only an active supplier is deactivated', 409,
                    ['status' => $s['status']]);
            }
            $this->checkVersion($s, $expectedVersion);
            $now = $this->nowDb();
            $withdrawn = $this->withdrawOpenTasks($id, 'the supplier was deactivated', $now);
            $db->exec("UPDATE supplier SET status = 'inactive', deactivated_by = ?, deactivated_at = ?, deactivate_reason = ?, version = version + 1, "
                . 'updated_by = ?, updated_actor = ?, updated_at = ? WHERE id = ?', [$me['id'], $now, $reason, $me['id'], $caller->actor, $now, $id]);
            Audit::write($db, $caller, 'supplier.deactivate', 'supplier', (string) $id, null, ['reason' => $reason, 'withdrawn_tasks' => $withdrawn]);
            return $this->get($id);
        });
    }

    /**
     * The code a new supplier gets when none is given: the upper-case letters and digits of its name, cut to 12 (SUP when
     * fewer than 2 remain), then -2, -3 ... while the code is taken.
     */
    public function codeFor(string $name): string
    {
        $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;
        $base = substr((string) preg_replace('/[^A-Z0-9]/', '', strtoupper($ascii)), 0, 12);
        if (strlen($base) < 2) {
            $base = 'SUP';
        }
        $code = $base;
        for ($n = 2; $this->db->value('SELECT 1 FROM supplier WHERE code = ?', [$code]) !== null; $n++) {
            $code = $base . '-' . $n;
        }
        return $code;
    }

    // ------------------------------------------------------------------------------------------
    // The steps
    // ------------------------------------------------------------------------------------------

    /**
     * update() and setEvidence(): $v are normalised column values (only the keys to change).
     *
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    private function change(Caller $caller, int $id, int $expectedVersion, array $v, string $action): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $expectedVersion, $v, $action): array {
            $me = $this->staff($caller, 'suppliers.manage');
            $s = $this->lock($id);
            if ($s['status'] === 'pending_approval') {
                throw new CwException('supplier_pending', "{$s['code']} is waiting for approval: withdraw the activation request first to change it", 409);
            }
            $this->checkVersion($s, $expectedVersion);
            $this->resolveDeferred($v);
            if (array_key_exists('name', $v) && $v['name'] === null) {
                throw new CwException('bad_field', 'name: a supplier needs a name', 422, ['field' => 'name']);
            }
            if (array_key_exists('code', $v) && $v['code'] === null) {
                unset($v['code']);
            }
            $changed = [];
            foreach ($v as $col => $val) {
                if (self::str($s[$col]) !== self::str($val)) {
                    $changed[$col] = [$s[$col], $val];
                }
            }
            if ($changed === []) {
                return $s;
            }
            $after = array_merge($s, $v);
            $this->checkUnique($v, $id);
            $this->checkDd($after);
            self::checkOverseas($after);
            $now = $this->nowDb();
            $set = $v;
            $identity = array_values(array_intersect(array_keys($changed), self::IDENTITY));
            if ($identity !== []) {
                $set['details_changed_by'] = $me['id'];
                $set['details_changed_at'] = $now;
            }
            $tasks = [];
            $route = array_values(array_intersect($identity, self::ROUTE));
            $wasOverseas = (int) $s['is_overseas'] === 1;
            $isOverseas = (int) $after['is_overseas'] === 1;
            $routeChanged = $route !== [] && ($wasOverseas || $isOverseas);
            if ($routeChanged) {
                // Whatever the status (I72): the approval covered the route as it was. A reactivation approves it again;
                // an inactive supplier made UK or given a blank route no longer trips ck_supplier_route.
                $set['import_route_approved_by'] = null;
                $set['import_route_approved_at'] = null;
                $set['route_alone'] = 0;
            }
            if ($s['status'] === 'active') {
                if ($isOverseas && self::str($after['import_route']) === null) {
                    throw new CwException('supplier_incomplete', 'an overseas supplier needs its import route (how and where UK duty stamps are applied)', 422,
                        ['missing' => ['import_route']]);
                }
                $alone = $routeChanged && !ApprovalRules::on($db, 'approvals.supplier_activation');
                if ($alone && $isOverseas) {
                    // The second person is switched off (Y11): the new route is approved by this person, with the switch's version.
                    $set['import_route_approved_by'] = $me['id'];
                    $set['import_route_approved_at'] = $now;
                    $set['route_alone'] = 1;
                    $set['alone_change_id'] = ApprovalRules::evidence($db, 'approvals.supplier_activation');
                }
                // The supplier row is written first (the task rows are locked after it, I21).
                $this->writeRow($id, $set, $me['id'], $caller->actor, $now);
                if ($routeChanged && !$alone && $this->openTaskRow($id, 'approval') === null) {
                    // Both directions block (I72): a route change of an overseas supplier, an overseas supplier made UK (one
                    // buyer must not switch the duty-stamp check off alone) and a UK supplier made overseas. POs are refused
                    // while it is open (PurchaseOrderHandler::validate); an open one is kept, whatever the change.
                    $tasks['import_route'] = $this->openTask($id, 'approval', 'import_route', $me['id'], $caller->actor, $now,
                        (int) $this->settings->get('suppliers.approval_due_days'));
                }
                $other = array_values(array_diff($identity, self::ROUTE));
                if ($other !== [] && $this->settings->get('suppliers.change_review') === true && $this->openTaskRow($id, 'review') === null) {
                    $tasks['supplier_changed'] = $this->openTask($id, 'review', 'supplier_changed', $me['id'], $caller->actor, $now, self::CHANGE_REVIEW_DUE_DAYS);
                }
            } else {
                $this->writeRow($id, $set, $me['id'], $caller->actor, $now);
            }
            Audit::write($db, $caller, $action, 'supplier', (string) $id, null,
                ['version' => $expectedVersion + 1, 'changed' => $changed] + ($tasks === [] ? [] : ['tasks' => $tasks]));
            return $this->get($id);
        });
    }

    /** @param array<string, mixed> $set */
    private function writeRow(int $id, array $set, int $by, string $actor, string $now): void
    {
        $cols = [];
        $params = [];
        foreach ($set as $c => $val) {
            $cols[] = "`{$c}` = ?";
            $params[] = $val;
        }
        array_push($params, $by, $actor, $now, $id);
        try {
            $this->db->exec('UPDATE supplier SET ' . implode(', ', $cols) . ', version = version + 1, updated_by = ?, updated_actor = ?, updated_at = ? WHERE id = ?',
                $params);
        } catch (\PDOException $e) {
            throw self::duplicate($e) ?? $e;
        }
    }

    /** Opens a review_task on the supplier (review_task has no FKs). @return int its id */
    private function openTask(int $supplierId, string $kind, string $reason, int $openedBy, string $actor, string $now, int $dueDays): int
    {
        return $this->db->insert(
            'INSERT INTO review_task (subject_type, subject_id, kind, reason, units, opened_by, opened_actor, opened_at, due_at) '
            . "VALUES ('supplier', ?, ?, ?, NULL, ?, ?, ?, ?)",
            [$supplierId, $kind, $reason, $openedBy, $actor, $now, Clock::db(Clock::fromDb($now)->modify("+{$dueDays} days"))],
        );
    }

    /** @return array<string, mixed>|null the open task of $kind on the supplier, X-locked (the supplier row is locked first) */
    private function openTaskRow(int $supplierId, string $kind): ?array
    {
        return $this->db->one("SELECT * FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND kind = ? AND state = 'open' FOR UPDATE",
            [$supplierId, $kind]);
    }

    /** Withdraws every open task of the supplier (deactivation). @return list<int> their ids */
    private function withdrawOpenTasks(int $supplierId, string $why, string $now): array
    {
        $ids = array_map('intval', $this->db->column("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open' ORDER BY id FOR UPDATE",
            [$supplierId]));
        foreach ($ids as $t) {
            $this->decideTask($t, 'withdrawn', null, $why, $now);
        }
        return $ids;
    }

    private function decideTask(int $taskId, string $state, ?int $decidedBy, ?string $note, string $now): void
    {
        if ($this->db->exec("UPDATE review_task SET state = ?, decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ? AND state = 'open'",
            [$state, $decidedBy, $now, $note === null ? null : mb_substr($note, 0, 500), $taskId]) !== 1) {
            throw new CwException('task_closed', 'this review task is no longer open', 409);
        }
    }

    /** @return array<string, mixed> the supplier row, X-locked (404 unknown_supplier) */
    private function lock(int $id): array
    {
        return $this->db->one('SELECT * FROM supplier WHERE id = ? FOR UPDATE', [$id])
            ?? throw new CwException('unknown_supplier', 'there is no such supplier', 404);
    }

    /**
     * Locks a supplier task's supplier, then the task (the order every supplier write uses), and checks the task is
     * still open (409 task_closed). 404 unknown_task for no task or a document's task.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [supplier row, task row]
     */
    private function lockTask(int $taskId): array
    {
        $t = $this->db->one("SELECT subject_id FROM review_task WHERE id = ? AND subject_type = 'supplier'", [$taskId])
            ?? throw new CwException('unknown_task', 'there is no such supplier task', 404);
        $s = $this->lock((int) $t['subject_id']);
        $task = $this->db->one('SELECT * FROM review_task WHERE id = ? FOR UPDATE', [$taskId]);
        if ($task === null || $task['state'] !== 'open') {
            throw new CwException('task_closed', 'this review task is no longer open (' . ($task['state'] ?? 'gone') . ')', 409, ['state' => $task['state'] ?? null]);
        }
        return [$s, $task];
    }

    /** @param array<string, mixed> $s */
    private function requirePending(array $s): void
    {
        if ($s['status'] !== 'pending_approval') {
            throw new CwException('not_pending', "{$s['code']} is not waiting for approval", 409, ['status' => $s['status']]);
        }
    }

    /** @param array<string, mixed> $s */
    private function checkVersion(array $s, int $expected): void
    {
        if ((int) $s['version'] !== $expected) {
            throw new CwException('version_conflict', 'the supplier changed since this page was drawn: reload it and try again', 409,
                ['version' => (int) $s['version'], 'expected_version' => $expected]);
        }
    }

    /** @param array{id: int, roles: list<string>} $me @param array<string, mixed> $s @param array<string, mixed> $task */
    private function checkDecider(array $me, array $s, array $task): void
    {
        $no = self::refusal($me['id'], $me['roles'], $s, $task);
        if ($no !== null) {
            throw new CwException($no['code'], $no['message'], 403);
        }
    }

    /**
     * The staff caller with their live roles (re-read inside the transaction), holding $perm when given.
     *
     * @return array{id: int, roles: list<string>}
     */
    private function staff(Caller $caller, ?string $perm): array
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'suppliers are created, changed and approved by staff', 403);
        }
        $roles = StaffRoles::active($this->db, $caller->staffUserId);
        if ($perm !== null && !Permissions::can($roles, $perm)) {
            throw new CwException('role_not_allowed', ucfirst(self::rolesPhrase($roles)) . ' cannot change suppliers', 403);
        }
        return ['id' => $caller->staffUserId, 'roles' => $roles];
    }

    /**
     * Unique code and ERPNext name (409 duplicate_code / duplicate_erp_name), checked before the write (the UNIQUE keys
     * catch a race: duplicate()).
     *
     * @param array<string, mixed> $v
     */
    private function checkUnique(array $v, ?int $id): void
    {
        if (isset($v['code']) && $this->db->value('SELECT 1 FROM supplier WHERE code = ? AND id <> ?', [$v['code'], $id ?? 0]) !== null) {
            throw new CwException('duplicate_code', "another supplier has the code {$v['code']}", 409, ['field' => 'code']);
        }
        if (isset($v['erp_name']) && $this->db->value('SELECT 1 FROM supplier WHERE erp_name = ? AND id <> ?', [$v['erp_name'], $id ?? 0]) !== null) {
            throw new CwException('duplicate_erp_name', 'another supplier has this ERPNext name', 409, ['field' => 'erp_name']);
        }
    }

    /** The due-diligence dates: checked on no later than today, next review not before the check (422). @param array<string, mixed> $s */
    private function checkDd(array $s): void
    {
        $on = self::str($s['dd_checked_on'] ?? null);
        $next = self::str($s['dd_next_review_on'] ?? null);
        if ($on !== null && $on > $this->today()) {
            throw new CwException('bad_field', 'due diligence checked on: the check cannot be in the future', 422, ['field' => 'dd_checked_on']);
        }
        if ($on !== null && $next !== null && $next < $on) {
            throw new CwException('bad_field', 'next due diligence review: it cannot be before the check', 422, ['field' => 'dd_next_review_on']);
        }
    }

    /**
     * A supplier whose country is not GB is overseas (I72, provisional): its goods are imported, so it needs the import
     * route and its blocking approval; one buyer cannot leave "overseas" unticked for a CN supplier and skip the
     * duty-stamp check. 422 bad_field (is_overseas).
     *
     * @param array<string, mixed> $s
     */
    public static function checkOverseas(array $s): void
    {
        $country = self::str($s['country'] ?? null) ?? 'GB';
        if ($country !== 'GB' && (int) ($s['is_overseas'] ?? 0) !== 1) {
            throw new CwException('bad_field', "overseas: a supplier in {$country} is an overseas supplier: tick 'overseas' and give its import route "
                . '(how and where UK duty stamps are applied)', 422, ['field' => 'is_overseas']);
        }
    }

    private static function duplicate(\PDOException $e): ?CwException
    {
        if (Db::driverCode($e) !== 1062) {
            return null;
        }
        return str_contains($e->getMessage(), 'uq_supplier_erp_name')
            ? new CwException('duplicate_erp_name', 'another supplier has this ERPNext name', 409, ['field' => 'erp_name'])
            : new CwException('duplicate_code', 'another supplier has this code', 409, ['field' => 'code']);
    }

    // ------------------------------------------------------------------------------------------
    // Input
    // ------------------------------------------------------------------------------------------

    /**
     * Checks the given fields and returns their column values (only the keys given; '' and null clear a field). Unknown
     * keys: 400 bad_field. Bad values: 422 bad_field with detail.field.
     *
     * @param array<string, mixed> $in
     * @param array<string, list<mixed>> $allowed
     * @return array<string, mixed>
     */
    public function normalise(array $in, array $allowed = self::FIELDS): array
    {
        $v = $this->normaliseInput($in, $allowed);
        $this->resolveDeferred($v);
        return $v;
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, list<mixed>> $allowed
     * @return array<string, mixed>
     */
    private function normaliseInput(array $in, array $allowed): array
    {
        $out = [];
        foreach ($in as $k => $raw) {
            $k = (string) $k;
            // Only the given list: the evidence file ids are set by setEvidence() alone, the ERPNext name by the import alone.
            $spec = $allowed[$k] ?? null;
            if ($spec === null) {
                throw new CwException('bad_field', 'a supplier has no field ' . mb_substr($k, 0, 40), 400, ['field' => mb_substr($k, 0, 40)]);
            }
            $out[$k] = self::value($k, $spec, $raw);
        }
        return $out;
    }

    /** Checks needing the database (VAT code, staff): resolved in place. @param array<string, mixed> $v */
    private function resolveDeferred(array &$v): void
    {
        if (array_key_exists('default_vat_code', $v)) {
            if ($v['default_vat_code'] === null) {
                $v['default_vat_code'] = 'S';
            } elseif ($this->db->value('SELECT 1 FROM vat_code WHERE code = ? AND is_active = 1', [$v['default_vat_code']]) === null) {
                throw new CwException('bad_field', 'VAT code: there is no active VAT code ' . $v['default_vat_code'], 422, ['field' => 'default_vat_code']);
            }
        }
        if (array_key_exists('dd_checked_by', $v) && $v['dd_checked_by'] !== null
            && $this->db->value('SELECT 1 FROM staff_user WHERE id = ? AND is_active = 1', [$v['dd_checked_by']]) === null) {
            throw new CwException('bad_field', 'due diligence checked by: choose an active member of staff', 422, ['field' => 'dd_checked_by']);
        }
        if (array_key_exists('country', $v) && $v['country'] === null) {
            $v['country'] = 'GB';
        }
        if (array_key_exists('is_overseas', $v) && $v['is_overseas'] === null) {
            $v['is_overseas'] = 0;
        }
    }

    /** @param list<mixed> $spec */
    private static function value(string $field, array $spec, mixed $raw): mixed
    {
        $label = self::LABELS[$field] ?? $field;
        $bad = static fn (string $why): CwException => new CwException('bad_field', "{$label}: {$why}", 422, ['field' => $field]);
        if (is_bool($raw)) {
            $raw = $raw ? '1' : '0';
        } elseif (is_int($raw)) {
            $raw = (string) $raw;
        } elseif ($raw !== null && !is_string($raw)) {
            throw $bad('must be text');
        }
        if ($raw !== null && !mb_check_encoding($raw, 'UTF-8')) {
            throw $bad('must be text');
        }
        $s = $raw === null ? '' : trim($raw);
        switch ($spec[0]) {
            case 'line':
            case 'email':
                $s = trim((string) preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $s));
                if ($s === '') {
                    return null;
                }
                if (mb_strlen($s) > (int) $spec[1]) {
                    throw $bad("at most {$spec[1]} characters");
                }
                if ($spec[0] === 'email' && filter_var($s, FILTER_VALIDATE_EMAIL) === false) {
                    throw $bad('is not an e-mail address');
                }
                return $s;
            case 'text':
                $s = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', str_replace("\r\n", "\n", $s)));
                $s = str_replace("\r", "\n", $s);
                if ($s === '') {
                    return null;
                }
                if (mb_strlen($s) > (int) $spec[1]) {
                    throw $bad("at most {$spec[1]} characters");
                }
                return $s;
            case 'code':
                $s = strtoupper($s);
                if ($s === '') {
                    return null;
                }
                if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,15}$/D', $s) !== 1) {
                    throw $bad('2 to 16 capital letters, digits, - or _ (starting with a letter or digit)');
                }
                return $s;
            case 'country':
                $s = strtoupper($s);
                if ($s === 'UK') {
                    $s = 'GB';
                }
                if ($s === '') {
                    return null;
                }
                if (preg_match('/^[A-Z]{2}$/D', $s) !== 1) {
                    throw $bad('a two-letter ISO country code (GB, CN, ...)');
                }
                return $s;
            case 'vat':
                $s = strtoupper($s);
                return $s === '' ? null : $s;
            case 'int':
                if ($s === '') {
                    return null;
                }
                if (preg_match('/^\d{1,6}$/D', $s) !== 1 || (int) $s < (int) $spec[1] || (int) $s > (int) $spec[2]) {
                    throw $bad("a whole number from {$spec[1]} to {$spec[2]}");
                }
                return (int) $s;
            case 'money':
                $s = str_replace([',', '£', ' '], '', $s);
                if ($s === '') {
                    return null;
                }
                if (preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', $s, $m) !== 1) {
                    throw $bad('an amount in GBP with at most 2 decimals');
                }
                return ltrim($m[1], '0') === '' ? '0.' . str_pad($m[2] ?? '', 2, '0') : ltrim($m[1], '0') . '.' . str_pad($m[2] ?? '', 2, '0');
            case 'bool':
                $l = strtolower($s);
                if (in_array($l, ['1', 'true', 'yes', 'y', 'on'], true)) {
                    return 1;
                }
                if (in_array($l, ['', '0', 'false', 'no', 'n', 'off'], true)) {
                    return 0;
                }
                throw $bad('yes or no (1 or 0)');
            case 'date':
                if ($s === '') {
                    return null;
                }
                $d = self::date($s);
                if ($d === null) {
                    throw $bad('a date, YYYY-MM-DD (or DD/MM/YYYY)');
                }
                return $d;
            case 'staff':
                if ($s === '') {
                    return null;
                }
                if (preg_match('/^[1-9][0-9]{0,17}$/D', $s) !== 1) {
                    throw $bad('choose one from the list');
                }
                return (int) $s;
        }
        throw new \LogicException("unknown field kind {$spec[0]}");
    }

    /** A calendar date from YYYY-MM-DD or DD/MM/YYYY, as Y-m-d; null when it is not one. */
    public static function date(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $s, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#D', $s, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        return $y >= 1900 && $y <= 2999 && checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    private static function optNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }
        $note = trim($note);
        if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > self::NOTE_MAX) {
            throw new CwException('bad_field', 'note: at most ' . self::NOTE_MAX . ' characters', 400, ['field' => 'note']);
        }
        return $note === '' ? null : $note;
    }

    /** A column value as a comparable string (null stays null; ints and decimals as text). */
    public static function str(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    /** "your role (buyer)" / "your roles (a, b)". @param list<string> $roles */
    public static function rolesPhrase(array $roles): string
    {
        return (count($roles) === 1 ? 'your role (' : 'your roles (') . ($roles === [] ? 'none' : implode(', ', $roles)) . ')';
    }

    private function nowDb(): string
    {
        return Clock::db(($this->clock)());
    }
}
