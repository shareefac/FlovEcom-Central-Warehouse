<?php

declare(strict_types=1);

namespace CW\Company;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Staff\StaffRoles;

/**
 * The company that buys and owns the warehouse stock, as every purchase order prints it (decision 9; docs/decisions.md
 * I90-I99): legal and trading name, company number, VAT (a number, or "not VAT registered"), registered address, purchasing
 * phone and e-mail, delivery address, and whether a person confirmed them.
 *
 * company_profile (0013) holds one row per version and is append-only for the app login: saving changed details adds a
 * version (kind `change`, unconfirmed), confirming adds one (kind `confirm`), a rejected review whose change the details in
 * use still carry adds one (kind `unconfirm`). The highest version is in use (current(), company()); a posted PO keeps the
 * snapshot it was approved with.
 *
 *  - Who: everyone with reference.view reads; `company.edit` saves, `company.confirm` confirms and reviews (both reviewer today,
 *    provisional, I90); never admin (I12). Staff callers only, their roles re-read inside the transaction.
 *  - Optimistic concurrency: the form carries the version it was drawn with; a different current version, or another save of
 *    the same version at the same moment (the PRIMARY KEY), is 409 company_changed and nothing is written.
 *  - Saving a change of any field unconfirms the details (every field is printed). One person may change and confirm (I94),
 *    so the check is made when details are CONFIRMED: against the baseline, the last confirmed version that carries no value
 *    a reviewer rejected. When a WATCHED field differs from it and the confirmer saved a change of a watched field since it
 *    (they confirm their own change), a non-blocking review_task (subject `company`, subject_id = the confirm version,
 *    reason `company_changed`) asks another reviewer: one who neither saved a change since the baseline nor confirmed it
 *    (involved()). A change confirmed by someone who did not make it had its second person already.
 *  - A rejected review (I98): a confirmed version in use that carries a rejected value becomes unconfirmed; the people
 *    involved in the rejected change may not confirm details carrying a rejected value again (403 rejected_change; another
 *    reviewer may, which lifts the rejection); approved orders whose snapshot carries one are flagged (rejectedIn(),
 *    ordersWithRejectedDetails(): their PDF says DO NOT SEND, sending warns).
 *  - Audit: company.change {version, changed, before, after, reason, was_confirmed}, company.confirm {version,
 *    confirms_version, baseline_version, watched_changed, review_task, details}, company.review {task, version, decision,
 *    note, unconfirmed_version}. CompanyInvariants (C1-C4) checks every version against them each night.
 */
final class CompanyDetails
{
    /** The fields in form order => the words people read. */
    public const FIELDS = [
        'legal_name' => 'legal name',
        'trading_name' => 'trading name',
        'company_number' => 'company number',
        'address' => 'registered address',
        'vat_registered' => 'VAT registration',
        'vat_number' => 'VAT number',
        'phone' => 'phone',
        'email' => 'purchasing e-mail',
        'delivery_address' => 'delivery address',
    ];
    /**
     * A person who confirms their own change of one of these asks another reviewer to look (I94): who the supplier deals with
     * and where the goods and the replies go (a changed delivery address on a PO is the classic way to divert goods).
     */
    public const WATCHED = ['legal_name', 'company_number', 'vat_registered', 'vat_number', 'email', 'delivery_address'];
    /** Approved orders still going to or coming from the supplier: flagged when they carry a rejected change (I98). */
    public const OPEN_ORDER_STATES = ['approved', 'sent', 'part_received'];
    /** 8 digits, 2 letters + 6 digits (SC, NI, OC ...), R + 7 digits (old Northern Ireland), IP/SP/NP + 5 digits + R (societies). */
    public const COMPANY_NUMBER_PATTERN = '/^([0-9]{8}|[A-Z]{2}[0-9]{6}|R[0-9]{7}|(IP|SP|NP)[0-9]{5}R)$/D';
    public const NAME_MAX = 160;
    public const PHONE_MAX = 32;
    public const EMAIL_MAX = 191;
    public const ADDRESS_LINES = 8;
    public const ADDRESS_LINE_MAX = 100;
    public const REASON_MAX = 500;
    public const REVIEW_DUE_DAYS = 7;
    public const HISTORY_LIMIT = 200;

    public const COMPANY_NUMBER_HELP = 'A company number has 8 characters: 8 digits (keep the leading zeros, for example 01234567) or 2 letters and 6 digits '
        . '(for example SC123456).';
    public const VAT_HELP = 'A UK VAT number is GB and 9 digits (for example GB 123 4567 89), or GB and 12 digits for a branch; XI instead of GB in Northern Ireland.';
    public const PHONE_HELP = 'A phone number is digits with spaces and + ( ) - . only, 7 to 15 digits, for example 0113 496 0000 or +44 113 496 0000.';

    private const EMPTY = ['legal_name' => '', 'trading_name' => '', 'company_number' => '', 'address' => '', 'vat_registered' => null, 'vat_number' => '',
        'phone' => '', 'email' => '', 'delivery_address' => ''];

    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock (saved_at, confirmed_at, a review's due date) */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /**
     * The details in use: the highest version, typed (version int, vat_registered ?bool, confirmed bool), with saved_by_name and
     * confirmed_by_name; version 0 with empty details when there is none (a test schema after TestDb::clean: 0013 seeds 1).
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $r = $this->db->one('SELECT p.*, s.display_name AS saved_by_name, c.display_name AS confirmed_by_name FROM company_profile p '
            . 'LEFT JOIN staff_user s ON s.id = p.saved_by LEFT JOIN staff_user c ON c.id = p.confirmed_by ORDER BY p.version DESC LIMIT 1');
        if ($r === null) {
            return self::EMPTY + ['version' => 0, 'kind' => null, 'confirmed' => false, 'confirmed_by' => null, 'confirmed_by_name' => null, 'confirmed_actor' => null,
                'confirmed_at' => null, 'baseline_version' => null, 'reason' => null, 'saved_by' => null, 'saved_by_name' => null, 'saved_actor' => null, 'saved_at' => null];
        }
        return self::row($r);
    }

    /**
     * What a PO prints and its posting snapshots (Settings::company(), PurchaseOrderHandler::post): the fields of the version in
     * use, `confirmed` and `version`. Empty strings are printed as "[to be confirmed]".
     *
     * @return array{legal_name: string, trading_name: string, address: string, company_number: string, vat_number: string, phone: string, email: string, delivery_address: string, confirmed: bool, vat_registered: ?bool, version: int}
     */
    public function company(): array
    {
        $p = $this->current();
        return ['legal_name' => $p['legal_name'], 'trading_name' => $p['trading_name'], 'address' => $p['address'], 'company_number' => $p['company_number'],
            'vat_number' => $p['vat_number'], 'phone' => $p['phone'], 'email' => $p['email'], 'delivery_address' => $p['delivery_address'],
            'confirmed' => $p['confirmed'], 'vat_registered' => $p['vat_registered'], 'version' => $p['version']];
    }

    /**
     * Saves the details typed on the form as a new version (unconfirmed) when anything changed. $expectedVersion is the version
     * the form was drawn with. Returns result `saved` (with the fields changed) or `unchanged` (nothing written).
     *
     * 422 company_invalid (detail.errors: field => message) · 409 company_changed · 403 staff_required / admin_cannot_edit /
     * role_not_allowed / staff_not_allowed.
     *
     * @param array<string, mixed> $input the FIELDS (missing ones are empty; vat_registered 'yes' | 'no' | '' or a bool / null)
     * @return array{result: string, version: int, changed: list<string>, was_confirmed: bool}
     */
    public function save(Caller $caller, int $expectedVersion, array $input, ?string $reason = null): array
    {
        ['values' => $v, 'reason' => $reason] = self::check($input, $reason);
        return $this->db->transaction(function (Db $db) use ($caller, $expectedVersion, $v, $reason): array {
            $this->staff($caller, 'company.edit');
            $cur = $this->current();
            self::checkVersion($cur, $expectedVersion, 'saved');
            $changed = [];
            foreach (array_keys(self::FIELDS) as $k) {
                if ($v[$k] !== $cur[$k]) {
                    $changed[] = $k;
                }
            }
            if ($changed === []) {
                return ['result' => 'unchanged', 'version' => $cur['version'], 'changed' => [], 'was_confirmed' => $cur['confirmed']];
            }
            $next = $cur['version'] + 1;
            $this->insert($db, $next, 'change', $v, null, null, $reason, $caller);
            $only = array_flip($changed);
            Audit::write($db, $caller, 'company.change', 'company_profile', (string) $next, null, [
                'version' => $next, 'changed' => $changed, 'before' => array_intersect_key(self::fields($cur), $only), 'after' => array_intersect_key($v, $only),
                'reason' => $reason, 'was_confirmed' => $cur['confirmed'],
            ]);
            return ['result' => 'saved', 'version' => $next, 'changed' => $changed, 'was_confirmed' => $cur['confirmed']];
        });
    }

    /**
     * "These details are correct": confirms the version the page showed ($expectedVersion) by adding a `confirm` version with
     * the same details. Needs everything missing() lists, and details that pass check() as stored (a seeded value that is
     * not yet in its tidy form must be saved once first). Compared with the baseline (I94): a watched field that differs from
     * it, confirmed by a person who saved a change of a watched field since it, opens a review for another reviewer. Result
     * `confirmed` (with review_task, baseline_version, watched_changed), or `already` (nothing written).
     *
     * 422 company_incomplete (detail.missing) / company_invalid / company_unsaved · 409 company_changed · 403 rejected_change
     * (details carrying a value a reviewer rejected, confirmed by someone involved in that change, I98) or as save().
     *
     * @return array{result: string, version: int, review_task: ?int, baseline_version: ?int, watched_changed: list<string>}
     */
    public function confirm(Caller $caller, int $expectedVersion): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $expectedVersion): array {
            $me = $this->staff($caller, 'company.confirm');
            $cur = $this->current();
            self::checkVersion($cur, $expectedVersion, 'confirmed');
            if ($cur['confirmed']) {
                return ['result' => 'already', 'version' => $cur['version'], 'review_task' => null, 'baseline_version' => null, 'watched_changed' => []];
            }
            $missing = self::missing($cur);
            if ($missing !== []) {
                throw new CwException('company_incomplete', 'Before the details can be confirmed, fill in: ' . implode(', ', $missing) . '.', 422, ['missing' => $missing]);
            }
            $fields = self::fields($cur);
            try {
                $tidy = self::check($fields)['values'];
            } catch (CwException $e) {
                throw new CwException('company_invalid', 'Correct the details before confirming them. ' . $e->getMessage(), 422, $e->detail);
            }
            if ($tidy !== $fields) {
                throw new CwException('company_unsaved', 'Press "Change the details" and save them once (spaces and line breaks are tidied when they are saved), '
                    . 'then confirm them.', 422);
            }
            $rows = $this->versions();
            $rejections = $this->rejections($rows);
            $hit = self::taintedBy($fields, $rejections);
            if ($hit !== null && in_array($me['id'], $hit['involved'], true)) {
                throw new CwException('rejected_change', 'A reviewer rejected this change of the ' . implode(' and ', array_map(static fn (string $f): string => self::FIELDS[$f],
                    array_keys($hit['values']))) . ' (' . ($hit['decided_by_name'] ?? 'another reviewer') . ': "' . ($hit['note'] ?? '') . '"). You made or confirmed that '
                    . 'change, so another reviewer must confirm it; or change the details.', 403, ['task' => $hit['task'], 'version' => $hit['version']]);
            }
            $baseline = self::baseline($rows, $rejections, $cur['version']);
            $watchedChanged = [];
            $task = null;
            if ($baseline !== null) {
                $then = self::watched($baseline);
                $now = self::watched($fields);
                foreach (self::WATCHED as $k) {
                    if ($then[$k] !== $now[$k]) {
                        $watchedChanged[] = $k;
                    }
                }
            }
            $next = $cur['version'] + 1;
            $baselineVersion = $baseline === null || $baseline['version'] === 0 ? null : $baseline['version'];
            $this->insert($db, $next, 'confirm', $fields, $caller, $baselineVersion, null, $caller);
            if ($watchedChanged !== [] && in_array($me['id'], self::watchedChangers($rows, $baseline['version'] ?? 0, $cur['version']), true)) {
                $task = $this->openReview($db, $next, $caller);
            }
            Audit::write($db, $caller, 'company.confirm', 'company_profile', (string) $next, null, ['version' => $next, 'confirms_version' => $cur['version'],
                'baseline_version' => $baselineVersion, 'watched_changed' => $watchedChanged, 'review_task' => $task, 'details' => $fields]);
            return ['result' => 'confirmed', 'version' => $next, 'review_task' => $task, 'baseline_version' => $baselineVersion, 'watched_changed' => $watchedChanged];
        });
    }

    /**
     * Another reviewer's decision on a person's confirmation of their own change (review_task subject `company`). Approving
     * records that it was checked; rejecting (a note of 3-500 characters) records why and, while the details in use are
     * confirmed and still carry a value of the rejected change, adds an `unconfirm` version: every new PO PDF says DO NOT
     * SEND again until someone corrects and confirms them. `orders`: the approved orders that carry the rejected change.
     *
     * 404 unknown_task · 409 task_closed / company_changed · 403 as refusal() · 400 bad_note.
     *
     * @return array{decision: string, unconfirmed_version: ?int, orders: int}
     */
    public function decideReview(Caller $caller, int $taskId, bool $approve, ?string $note): array
    {
        $note = $note === null ? null : trim((string) preg_replace('/\s+/u', ' ', $note));
        $note = $note === '' ? null : $note;
        if ($note !== null && (!mb_check_encoding($note, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $note) === 1 || mb_strlen($note) > 500)) {
            throw new CwException('bad_note', 'The note is at most 500 characters of plain text.', 400);
        }
        if (!$approve && ($note === null || mb_strlen($note) < 3)) {
            throw new CwException('bad_note', 'Say in 3 to 500 characters what is wrong with the change.', 400);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $taskId, $approve, $note): array {
            $me = $this->staff($caller, null);
            // An unconfirm version racing a save or confirmation of the same version number: the PRIMARY KEY lets one through.
            $task = $db->one("SELECT * FROM review_task WHERE id = ? AND subject_type = 'company' FOR UPDATE", [$taskId])
                ?? throw new CwException('unknown_task', 'There is no such check of the company details.', 404);
            if ($task['state'] !== 'open') {
                throw new CwException('task_closed', 'This check is no longer open (' . $task['state'] . ').', 409, ['state' => $task['state']]);
            }
            $no = $this->refusal($me['id'], $me['roles'], $task);
            if ($no !== null) {
                throw new CwException($no['code'], $no['message'], 403);
            }
            $now = Clock::db(($this->clock)());
            $db->exec('UPDATE review_task SET state = ?, decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?',
                [$approve ? 'approved' : 'rejected', $me['id'], $now, $note, $taskId]);
            $unconfirmed = null;
            $orders = 0;
            if (!$approve) {
                $cur = $this->current();
                $rows = $this->versions();
                $mine = array_values(array_filter($this->rejections($rows), static fn (array $r): bool => $r['task'] === $taskId));
                if ($cur['confirmed'] && self::taintedBy($cur, $mine) !== null) {
                    $unconfirmed = $cur['version'] + 1;
                    $this->insert($db, $unconfirmed, 'unconfirm', self::fields($cur), null, null,
                        mb_substr('a reviewer rejected the change confirmed in version ' . (int) $task['subject_id'] . ': ' . $note, 0, self::REASON_MAX), $caller);
                }
                $orders = count($this->ordersWithRejectedDetails());
            }
            Audit::write($db, $caller, 'company.review', 'company_profile', (string) $task['subject_id'], null, ['task' => $taskId,
                'version' => (int) $task['subject_id'], 'decision' => $approve ? 'approved' : 'rejected', 'note' => $note, 'unconfirmed_version' => $unconfirmed]);
            return ['decision' => $approve ? 'approved' : 'rejected', 'unconfirmed_version' => $unconfirmed, 'orders' => $orders];
        });
    }

    /**
     * Why this person may not decide a check of the company details, or null: never admin (I12), only `company.confirm`, never
     * a person involved in the change (who saved a change since the baseline, or confirmed it: involved();
     * ck_review_task_not_own holds the opener in SQL too).
     *
     * @param list<string> $roles
     * @param array<string, mixed> $task
     * @return array{code: string, message: string}|null
     */
    public function refusal(int $staffId, array $roles, array $task): ?array
    {
        return $this->refusalOf($staffId, $roles, $task, null);
    }

    /**
     * @param list<string> $roles
     * @param array<string, mixed> $task
     * @param array<int, array<string, mixed>>|null $rows versions() when the caller has them
     * @return array{code: string, message: string}|null
     */
    private function refusalOf(int $staffId, array $roles, array $task, ?array $rows): ?array
    {
        if (in_array('admin', $roles, true)) {
            return ['code' => 'admin_cannot_review', 'message' => 'Admin manages people and roles and never checks the company details.'];
        }
        if (!Permissions::can($roles, 'company.confirm')) {
            return ['code' => 'role_not_allowed', 'message' => ucfirst(self::rolesPhrase($roles)) . ' cannot check changes of the company details.'];
        }
        if ((($task['opened_by'] ?? null) !== null && (int) $task['opened_by'] === $staffId) || in_array($staffId, $this->involved((int) $task['subject_id'], $rows), true)) {
            return ['code' => 'own_change', 'message' => 'You made or confirmed this change: another reviewer must check it.'];
        }
        return null;
    }

    /** The open checks of the company details this person may decide (the menu badge, I94). @param list<string> $roles */
    public function decidableCount(int $staffId, array $roles): int
    {
        if (in_array('admin', $roles, true) || !Permissions::can($roles, 'company.confirm')) {
            return 0;
        }
        $tasks = $this->db->all("SELECT id, subject_id, opened_by FROM review_task WHERE subject_type = 'company' AND state = 'open'");
        if ($tasks === []) {
            return 0;
        }
        $rows = $this->versions();
        $n = 0;
        foreach ($tasks as $t) {
            $n += $this->refusalOf($staffId, $roles, $t, $rows) === null ? 1 : 0;
        }
        return $n;
    }

    /**
     * The checks of a confirmation (open ones first, then the latest decided), with the people's names, what the confirmed
     * version changed against its baseline (`changes`: field, label, before, after, watched) and `others`: how many active
     * people could decide it (they hold company.confirm, are not admin and were not involved): 0 while the owner is the only
     * reviewer.
     *
     * @return list<array<string, mixed>>
     */
    public function reviews(int $limit = self::HISTORY_LIMIT): array
    {
        $tasks = $this->db->all(
            'SELECT t.*, o.display_name AS opened_by_name, x.display_name AS decided_by_name FROM review_task t LEFT JOIN staff_user o ON o.id = t.opened_by '
            . "LEFT JOIN staff_user x ON x.id = t.decided_by WHERE t.subject_type = 'company' ORDER BY t.state = 'open' DESC, t.id DESC LIMIT " . $limit,
        );
        if ($tasks === []) {
            return [];
        }
        $rows = $this->versions();
        $deciders = $this->deciders();
        $out = [];
        foreach ($tasks as $t) {
            $v = (int) $t['subject_id'];
            $row = $rows[$v] ?? null;
            $base = $row !== null && $row['baseline_version'] !== null ? ($rows[(int) $row['baseline_version']] ?? null) : null;
            $changes = [];
            if ($row !== null) {
                $then = self::tidy($base ?? self::EMPTY);
                foreach (self::FIELDS as $k => $label) {
                    if ($then[$k] !== $row[$k]) {
                        $changes[] = ['field' => $k, 'label' => $label, 'before' => self::shown($k, $then[$k]), 'after' => self::shown($k, $row[$k]),
                            'watched' => in_array($k, self::WATCHED, true)];
                    }
                }
            }
            $involved = $this->involved($v, $rows);
            $t['baseline_version'] = $base['version'] ?? null;
            $t['changes'] = $changes;
            $t['others'] = count(array_diff($deciders, $involved, [(int) ($t['opened_by'] ?? 0)]));
            $out[] = $t;
        }
        return $out;
    }

    /**
     * Every version, newest first (at most $limit): version, kind, who and when, the reason, whether it is confirmed and by
     * whom, what changed against the version before it (`changes`: field, label, before, after; the seed lists what it
     * copied; a confirm or unconfirm lists nothing unless something is wrong), and `review`: the check of a confirmation
     * (state, decided_by_name), if any.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = self::HISTORY_LIMIT): array
    {
        $rows = array_map(self::row(...), $this->db->all('SELECT p.*, s.display_name AS saved_by_name, c.display_name AS confirmed_by_name FROM company_profile p '
            . 'LEFT JOIN staff_user s ON s.id = p.saved_by LEFT JOIN staff_user c ON c.id = p.confirmed_by ORDER BY p.version DESC LIMIT ' . ($limit + 1)));
        $reviews = [];
        foreach ($this->db->all("SELECT t.subject_id, t.state, x.display_name AS decided_by_name FROM review_task t LEFT JOIN staff_user x ON x.id = t.decided_by "
            . "WHERE t.subject_type = 'company' ORDER BY t.id") as $t) {
            $reviews[(int) $t['subject_id']] = ['state' => (string) $t['state'], 'decided_by_name' => $t['decided_by_name']];
        }
        $out = [];
        foreach (array_slice($rows, 0, $limit) as $i => $r) {
            $before = $rows[$i + 1] ?? null;
            $changes = [];
            foreach (self::FIELDS as $k => $label) {
                $old = $before === null ? self::EMPTY[$k] : $before[$k];
                if ($r[$k] !== $old) {
                    $changes[] = ['field' => $k, 'label' => $label, 'before' => self::shown($k, $old), 'after' => self::shown($k, $r[$k])];
                }
            }
            $out[] = $r + ['changes' => $changes, 'review' => $reviews[$r['version']] ?? null];
        }
        return $out;
    }

    /**
     * What changed between version $version and the version in use (field, label, before, after as the history shows them):
     * what a person whose form was drawn at $version did not see.
     *
     * @return list<array{field: string, label: string, before: string, after: string}>
     */
    public function changesSince(int $version): array
    {
        $then = $this->db->one('SELECT * FROM company_profile WHERE version = ?', [$version]);
        $then = $then === null ? self::EMPTY : self::row($then);
        $now = $this->current();
        $out = [];
        foreach (self::FIELDS as $k => $label) {
            if ($then[$k] !== $now[$k]) {
                $out[] = ['field' => $k, 'label' => $label, 'before' => self::shown($k, $then[$k]), 'after' => self::shown($k, $now[$k])];
            }
        }
        return $out;
    }

    /**
     * The rejected change $p (the details in use, or a PO's company snapshot) still carries, or null (I98): task, version,
     * note, decided_by_name, values (field => the rejected value).
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>|null
     */
    public function rejectedIn(array $p): ?array
    {
        if (!$this->hasRejections()) {
            return null;
        }
        return self::taintedBy($p, $this->rejections($this->versions()));
    }

    /**
     * Approved orders not yet received (OPEN_ORDER_STATES) whose company snapshot carries a change a reviewer rejected (I98):
     * document_id, number, state, task (the rejected check). Newest first.
     *
     * @return list<array{document_id: int, number: string, state: string, task: int}>
     */
    public function ordersWithRejectedDetails(): array
    {
        if (!$this->hasRejections()) {
            return [];
        }
        $rejections = $this->rejections($this->versions());
        if ($rejections === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count(self::OPEN_ORDER_STATES), '?'));
        $out = [];
        foreach ($this->db->all("SELECT d.id, d.number, p.state, p.company_snapshot FROM purchase_order p JOIN document d ON d.id = p.document_id "
            . "WHERE p.state IN ({$in}) AND p.company_snapshot IS NOT NULL ORDER BY d.id DESC", self::OPEN_ORDER_STATES) as $r) {
            $snap = json_decode((string) $r['company_snapshot'], true);
            $hit = is_array($snap) ? self::taintedBy($snap, $rejections) : null;
            if ($hit !== null) {
                $out[] = ['document_id' => (int) $r['id'], 'number' => (string) $r['number'], 'state' => (string) $r['state'], 'task' => $hit['task']];
            }
        }
        return $out;
    }

    /**
     * Checks and tidies what a person typed, all fields at once: the normalised values, and the reason (null when empty). One
     * 422 company_invalid lists every problem (detail.errors: field => a sentence; detail.values: the values as tidied as they
     * could be). See docs/decisions.md I92.
     *
     * @param array<string, mixed> $in
     * @return array{values: array<string, mixed>, reason: ?string}
     */
    public static function check(array $in, ?string $reason = null): array
    {
        $errors = [];
        $text = static function (string $k) use ($in): string {
            $v = $in[$k] ?? '';
            return is_string($v) ? $v : (is_int($v) ? (string) $v : '');
        };
        $v = [];
        $v['legal_name'] = self::oneLine($text('legal_name'), self::NAME_MAX, 'legal_name', $errors);
        $v['trading_name'] = self::oneLine($text('trading_name'), self::NAME_MAX, 'trading_name', $errors);

        $raw = $text('company_number');
        $cn = mb_check_encoding($raw, 'UTF-8') ? strtoupper((string) preg_replace('/[\s\-]+/u', '', $raw)) : '';
        if ($cn !== '' && preg_match(self::COMPANY_NUMBER_PATTERN, $cn) !== 1) {
            $errors['company_number'] = self::COMPANY_NUMBER_HELP;
            $cn = trim($raw);
        }
        $v['company_number'] = $cn;
        $v['address'] = self::lines($text('address'), 'address', $errors);

        $raw = $text('vat_number');
        $vn = mb_check_encoding($raw, 'UTF-8') ? strtoupper((string) preg_replace('/[\s.\-]+/u', '', $raw)) : '';
        if (preg_match('/^([0-9]{9}|[0-9]{12})$/D', $vn) === 1) {
            $vn = 'GB' . $vn;
        }
        if ($vn !== '' && preg_match('/^(GB|XI)([0-9]{9}|[0-9]{12})$/D', $vn) !== 1) {
            $errors['vat_number'] = self::VAT_HELP;
            $vn = trim($raw);
        }
        $choice = $in['vat_registered'] ?? null;
        $choice = match (true) {
            $choice === true, $choice === 'yes', $choice === '1', $choice === 1 => 'yes',
            $choice === false, $choice === 'no', $choice === '0', $choice === 0 => 'no',
            $choice === null, $choice === '' => '',
            default => 'bad',
        };
        $registered = null;
        if ($choice === 'bad') {
            $errors['vat_registered'] = 'Choose "VAT registered", "Not VAT registered" or "Not known yet".';
        } elseif ($choice === 'yes') {
            $registered = true;
            if ($vn === '') {
                $errors['vat_number'] = 'You chose "VAT registered": type the VAT number, or choose "Not VAT registered" or "Not known yet".';
            }
        } elseif ($choice === 'no') {
            $registered = false;
            if ($vn !== '') {
                $errors['vat_number'] = 'You chose "Not VAT registered": clear the VAT number, or choose "VAT registered".';
            }
        } elseif ($vn !== '') {
            $registered = true; // a number typed says "registered"
        }
        $v['vat_registered'] = $registered;
        $v['vat_number'] = $vn;

        $raw = $text('phone');
        $phone = mb_check_encoding($raw, 'UTF-8') ? trim((string) preg_replace('/\s+/u', ' ', $raw)) : '';
        if ($phone !== '' && (mb_strlen($phone) > self::PHONE_MAX || preg_match('/^\+?[0-9 ()\-.]+$/D', $phone) !== 1
            || !in_array(strlen((string) preg_replace('/\D/', '', $phone)), range(7, 15), true))) {
            $errors['phone'] = self::PHONE_HELP;
        }
        $v['phone'] = $phone;

        $raw = $text('email');
        $email = mb_check_encoding($raw, 'UTF-8') ? trim($raw) : '';
        if ($email !== '' && (mb_strlen($email) > self::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'] = 'This is not an e-mail address (for example buying@example.co.uk).';
        }
        $v['email'] = $email;
        $v['delivery_address'] = self::lines($text('delivery_address'), 'delivery_address', $errors);

        if ($reason !== null) {
            if (!mb_check_encoding($reason, 'UTF-8')) {
                $errors['reason'] = 'The reason is not valid text: type it again.';
                $reason = '';
            }
            $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
            if (preg_match('/[\x00-\x1F\x7F]/', $reason) === 1) {
                $errors['reason'] = 'The reason contains a control character: type it again.';
            } elseif (mb_strlen($reason) > self::REASON_MAX) {
                $errors['reason'] = 'The reason is at most ' . self::REASON_MAX . ' characters.';
            }
            $reason = $reason === '' ? null : $reason;
        }
        if ($errors !== []) {
            throw new CwException('company_invalid', 'Some details need correcting: ' . implode(' ', $errors), 422, ['errors' => $errors, 'values' => $v]);
        }
        return ['values' => $v, 'reason' => $reason];
    }

    /**
     * The FIELDS of $p as check() tidies them, problems or not (what a save of them would store).
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    public static function tidy(array $p): array
    {
        $in = self::fields($p);
        $in['vat_registered'] = match ($in['vat_registered']) {
            true => 'yes',
            false => 'no',
            default => '',
        };
        try {
            return self::check($in)['values'];
        } catch (CwException $e) {
            /** @var array<string, mixed> $values */
            $values = $e->detail['values'];
            return $values;
        }
    }

    /**
     * Whether stored details pass check() but are not in its tidy form (a seed copied from the old settings): a confirmation
     * needs one save first (422 company_unsaved), so the page offers no confirm button.
     *
     * @param array<string, mixed> $p
     */
    public static function unsaved(array $p): bool
    {
        return self::problems($p) === [] && self::tidy($p) !== self::fields($p);
    }

    /**
     * What a confirmation still needs, in the words people read: legal name, company number, registered address, a VAT number
     * or the explicit "not VAT registered", purchasing e-mail, delivery address (trading name and phone are optional).
     *
     * @param array<string, mixed> $p
     * @return list<string>
     */
    public static function missing(array $p): array
    {
        $out = [];
        foreach (['legal_name', 'company_number', 'address'] as $k) {
            if (trim((string) ($p[$k] ?? '')) === '') {
                $out[] = self::FIELDS[$k];
            }
        }
        if (($p['vat_registered'] ?? null) === null || (($p['vat_registered'] ?? null) === true && trim((string) ($p['vat_number'] ?? '')) === '')) {
            $out[] = 'VAT number (or "not VAT registered")';
        }
        foreach (['email', 'delivery_address'] as $k) {
            if (trim((string) ($p[$k] ?? '')) === '') {
                $out[] = self::FIELDS[$k];
            }
        }
        return $out;
    }

    /**
     * The problems of the stored details (a seeded value copied from the old settings may break today's rules), field =>
     * sentence; [] when they pass check().
     *
     * @param array<string, mixed> $p
     * @return array<string, string>
     */
    public static function problems(array $p): array
    {
        $in = self::fields($p);
        $in['vat_registered'] = match ($in['vat_registered']) {
            true => 'yes',
            false => 'no',
            default => '',
        };
        try {
            self::check($in);
        } catch (CwException $e) {
            return is_array($e->detail['errors'] ?? null) ? $e->detail['errors'] : ['legal_name' => $e->getMessage()];
        }
        return [];
    }

    /** "GB 123 4567 89" (and " 012" for a branch) of a normalised UK VAT number; anything else as it is. */
    public static function formatVat(string $v): string
    {
        if (preg_match('/^(GB|XI)(\d{3})(\d{4})(\d{2})(\d{3})?$/D', $v, $m) !== 1) {
            return $v;
        }
        return "{$m[1]} {$m[2]} {$m[3]} {$m[4]}" . (isset($m[5]) && $m[5] !== '' ? " {$m[5]}" : '');
    }

    /**
     * Whether the check digits of a normalised UK VAT number add up (HMRC's weighted modulus 97, old and "9755" schemes; a
     * branch's 12 digits are checked on their first 9). A warning only, never a refusal (I92).
     */
    public static function vatChecksumOk(string $v): bool
    {
        if (preg_match('/^(?:GB|XI)(\d{9})(?:\d{3})?$/D', $v, $m) !== 1) {
            return false;
        }
        $d = array_map('intval', str_split($m[1]));
        $sum = 10 * $d[7] + $d[8];
        foreach ([8, 7, 6, 5, 4, 3, 2] as $i => $w) {
            $sum += $w * $d[$i];
        }
        return $sum % 97 === 0 || ($sum + 55) % 97 === 0;
    }

    /**
     * The first character of $s that a purchase order cannot print, or null. The PDF's core font (Helvetica) is
     * Windows-1252 (PdfWriter::text): Western European letters, £, €, curly quotes and dashes print; Polish ł, Greek, Chinese,
     * emoji or a right-to-left override would come out as "?" or vanish, so they are refused rather than silently changed.
     */
    public static function unprintable(string $s): ?string
    {
        foreach (mb_str_split($s, 1, 'UTF-8') as $ch) {
            if ($ch === "\n") {
                continue;
            }
            $b = @iconv('UTF-8', 'Windows-1252', $ch);
            if (!is_string($b) || $b === '' || preg_match('/[\x00-\x1F\x7F\x81\x8D\x8F\x90\x9D]/', $b) === 1) {
                return $ch;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $p @return array<string, mixed> the FIELDS of a profile, in order */
    public static function fields(array $p): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $k) {
            $out[$k] = $p[$k] ?? self::EMPTY[$k];
        }
        return $out;
    }

    /**
     * The WATCHED fields of $p (a version, or a PO's company snapshot) as check() tidies them; a snapshot from before 0013
     * has no vat_registered: a VAT number says registered.
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    public static function watched(array $p): array
    {
        if (!array_key_exists('vat_registered', $p)) {
            $p['vat_registered'] = trim((string) ($p['vat_number'] ?? '')) !== '' ? true : null;
        }
        return array_intersect_key(self::tidy($p), array_flip(self::WATCHED));
    }

    /** A field's value as the history shows it. */
    public static function shown(string $field, mixed $v): string
    {
        if ($field === 'vat_registered') {
            return match ($v) {
                true => 'VAT registered',
                false => 'not VAT registered',
                default => 'not known yet',
            };
        }
        if ($field === 'vat_number') {
            return self::formatVat((string) $v);
        }
        return (string) $v;
    }

    /** @param list<string> $roles */
    public static function rolesPhrase(array $roles): string
    {
        return (count($roles) === 1 ? 'your role (' : 'your roles (') . ($roles === [] ? 'none' : implode(', ', $roles)) . ')';
    }

    /**
     * The people involved in confirmation $version: who saved a change after its baseline up to it, and who confirmed it.
     * They may not decide its check, and, once it is rejected, may not confirm its rejected values again (I94, I98).
     *
     * @param array<int, array<string, mixed>>|null $rows versions() when the caller has them
     * @return list<int>
     */
    public function involved(int $version, ?array $rows = null): array
    {
        $rows ??= $this->versions();
        $row = $rows[$version] ?? null;
        if ($row === null) {
            return [];
        }
        $from = (int) ($row['baseline_version'] ?? 0);
        $ids = [];
        foreach ($rows as $v => $r) {
            if ($v > $from && $v < $version && $r['kind'] === 'change' && $r['saved_by'] !== null) {
                $ids[] = (int) $r['saved_by'];
            }
        }
        if ($row['confirmed_by'] !== null) {
            $ids[] = (int) $row['confirmed_by'];
        }
        return array_values(array_unique($ids));
    }

    // ------------------------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> every version, typed, keyed and ordered by version */
    private function versions(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT * FROM company_profile ORDER BY version') as $r) {
            $r = self::row($r);
            $out[$r['version']] = $r;
        }
        return $out;
    }

    private function hasRejections(): bool
    {
        return $this->db->value("SELECT 1 FROM review_task WHERE subject_type = 'company' AND state = 'rejected' LIMIT 1") !== null;
    }

    /**
     * The rejected checks still in force (I98), oldest first: task, version (the confirmation checked), note, decided_by_name,
     * decided_at, values (field => the rejected value: each watched field the confirmation changed against its baseline, empty
     * values left out), involved. A rejection is lifted by a later confirmation, by someone not involved in it, of details that
     * carry its values (another reviewer decided they are right after all).
     *
     * @param array<int, array<string, mixed>> $rows versions()
     * @return list<array<string, mixed>>
     */
    private function rejections(array $rows): array
    {
        $out = [];
        foreach ($this->db->all("SELECT t.id, t.subject_id, t.decision_note, t.decided_at, x.display_name AS decided_by_name FROM review_task t "
            . "LEFT JOIN staff_user x ON x.id = t.decided_by WHERE t.subject_type = 'company' AND t.state = 'rejected' ORDER BY t.id") as $t) {
            $v = (int) $t['subject_id'];
            $row = $rows[$v] ?? null;
            if ($row === null) {
                continue;
            }
            $now = self::watched($row);
            $then = self::watched($row['baseline_version'] !== null ? ($rows[(int) $row['baseline_version']] ?? self::EMPTY) : self::EMPTY);
            $values = [];
            foreach (self::WATCHED as $k) {
                if ($now[$k] !== $then[$k] && $now[$k] !== '' && $now[$k] !== null) {
                    $values[$k] = $now[$k];
                }
            }
            if ($values === []) {
                continue;
            }
            $r = ['task' => (int) $t['id'], 'version' => $v, 'note' => $t['decision_note'], 'decided_by_name' => $t['decided_by_name'],
                'decided_at' => (string) $t['decided_at'], 'values' => $values, 'involved' => $this->involved($v, $rows)];
            $lifted = false;
            foreach ($rows as $c) {
                if ($c['kind'] === 'confirm' && $c['confirmed_by'] !== null && (string) $c['confirmed_at'] > $r['decided_at']
                    && !in_array((int) $c['confirmed_by'], $r['involved'], true) && self::taintedBy($c, [$r]) !== null) {
                    $lifted = true;
                    break;
                }
            }
            if (!$lifted) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * The first of $rejections whose rejected value $p carries, or null.
     *
     * @param array<string, mixed> $p
     * @param list<array<string, mixed>> $rejections
     * @return array<string, mixed>|null
     */
    private static function taintedBy(array $p, array $rejections): ?array
    {
        if ($rejections === []) {
            return null;
        }
        $w = self::watched($p);
        foreach ($rejections as $r) {
            foreach ($r['values'] as $k => $value) {
                if ($w[$k] === $value) {
                    return $r;
                }
            }
        }
        return null;
    }

    /**
     * The baseline of a confirmation of version $upTo (I94): the newest confirmed version before it that carries no rejected
     * value; the empty details (version 0) when every earlier confirmed version carries one; null when nothing was ever
     * confirmed (the first confirmation is checked by nobody).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param list<array<string, mixed>> $rejections
     * @return array<string, mixed>|null
     */
    private static function baseline(array $rows, array $rejections, int $upTo): ?array
    {
        $any = false;
        foreach (array_reverse($rows, true) as $v => $r) {
            if ($v > $upTo || !$r['confirmed']) {
                continue;
            }
            $any = true;
            if (self::taintedBy($r, $rejections) === null) {
                return $r;
            }
        }
        return $any ? self::EMPTY + ['version' => 0] : null;
    }

    /**
     * Who saved a change of a watched field after version $from, up to $to.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<int>
     */
    private static function watchedChangers(array $rows, int $from, int $to): array
    {
        $ids = [];
        $prev = self::watched($rows[$from] ?? self::EMPTY);
        foreach ($rows as $v => $r) {
            if ($v <= $from || $v > $to) {
                continue;
            }
            $w = self::watched($r);
            if ($r['kind'] === 'change' && $r['saved_by'] !== null && $w !== $prev) {
                $ids[] = (int) $r['saved_by'];
            }
            $prev = $w;
        }
        return array_values(array_unique($ids));
    }

    /** @return list<int> the active people who could decide a check (company.confirm, never admin) */
    private function deciders(): array
    {
        $roles = Permissions::MAP['company.confirm'];
        $in = implode(',', array_fill(0, count($roles), '?'));
        return array_map('intval', $this->db->column(
            "SELECT DISTINCT r.staff_user_id FROM staff_role r JOIN staff_user u ON u.id = r.staff_user_id AND u.is_active = 1 WHERE r.revoked_at IS NULL "
            . "AND r.role IN ({$in}) AND NOT EXISTS (SELECT 1 FROM staff_role a WHERE a.staff_user_id = r.staff_user_id AND a.role = 'admin' AND a.revoked_at IS NULL)",
            $roles,
        ));
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function row(array $r): array
    {
        foreach (['legal_name', 'trading_name', 'company_number', 'address', 'vat_number', 'phone', 'email', 'delivery_address'] as $k) {
            $r[$k] = (string) $r[$k];
        }
        $r['version'] = (int) $r['version'];
        $r['vat_registered'] = $r['vat_registered'] === null ? null : (int) $r['vat_registered'] === 1;
        $r['confirmed'] = (int) $r['confirmed'] === 1;
        $r['baseline_version'] = ($r['baseline_version'] ?? null) === null ? null : (int) $r['baseline_version'];
        return $r;
    }

    /** @param array<string, mixed> $cur */
    private static function checkVersion(array $cur, int $expected, string $what): void
    {
        if ($cur['version'] !== $expected) {
            $who = $cur['saved_by_name'] ?? (str_starts_with((string) $cur['saved_actor'], 'system:') ? 'the set-up' : ($cur['saved_actor'] ?? 'someone'));
            throw new CwException('company_changed', "Someone changed the company details while you had them open ({$who} saved version {$cur['version']} at "
                . substr((string) $cur['saved_at'], 0, 16) . " UTC): nothing was {$what}.", 409, ['version' => $cur['version'], 'expected_version' => $expected]);
        }
    }

    /**
     * Adds version $version. A second insert of the same version (two people saving at the same moment) is a duplicate key:
     * 409 company_changed, and the caller's transaction (with its audit row) rolls back.
     *
     * @param array<string, mixed> $v the FIELDS
     */
    private function insert(Db $db, int $version, string $kind, array $v, ?Caller $confirmer, ?int $baseline, ?string $reason, Caller $caller): void
    {
        $now = Clock::db(($this->clock)());
        try {
            $db->exec(
                'INSERT INTO company_profile (version, kind, legal_name, trading_name, company_number, vat_registered, vat_number, address, phone, email, '
                . 'delivery_address, confirmed, confirmed_by, confirmed_actor, confirmed_at, baseline_version, reason, saved_by, saved_actor, saved_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$version, $kind, $v['legal_name'], $v['trading_name'], $v['company_number'], $v['vat_registered'] === null ? null : ($v['vat_registered'] ? 1 : 0),
                    $v['vat_number'], $v['address'], $v['phone'], $v['email'], $v['delivery_address'], $confirmer !== null ? 1 : 0, $confirmer?->staffUserId,
                    $confirmer?->actor, $confirmer !== null ? $now : null, $baseline, $reason, $caller->staffUserId, $caller->actor, $now],
            );
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === 1062) {
                throw new CwException('company_changed', 'Someone else saved the company details at the same moment: nothing was saved.', 409);
            }
            if (Db::driverCode($e) === 1264) {
                // A version number at the top of its range: only a row added outside this screen does that (invariant C1).
                throw new CwException('company_versions_broken', 'The history of the company details is damaged, so nothing was saved: ask the person '
                    . 'who looks after CW to check it (the nightly checks name the problem).', 500);
            }
            throw $e;
        }
    }

    private function openReview(Db $db, int $version, Caller $caller): int
    {
        $now = ($this->clock)();
        return $db->insert(
            'INSERT INTO review_task (subject_type, subject_id, kind, reason, units, opened_by, opened_actor, opened_at, due_at) '
            . "VALUES ('company', ?, 'review', 'company_changed', NULL, ?, ?, ?, ?)",
            [$version, $caller->staffUserId, $caller->actor, Clock::db($now), Clock::db($now->modify('+' . self::REVIEW_DUE_DAYS . ' days'))],
        );
    }

    /**
     * The caller as staff with their live roles (re-read inside the transaction, I11), holding $perm when given; never admin.
     *
     * @return array{id: int, roles: list<string>}
     */
    private function staff(Caller $caller, ?string $perm): array
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'The company details are changed by staff on the Company details screen.', 403);
        }
        $roles = StaffRoles::active($this->db, $caller->staffUserId);
        if ($perm !== null) {
            if (in_array('admin', $roles, true)) {
                throw new CwException('admin_cannot_edit', 'Admin manages people and roles only: a reviewer adds, changes and confirms the company details.', 403);
            }
            if (!Permissions::can($roles, $perm)) {
                throw new CwException('role_not_allowed', ucfirst(self::rolesPhrase($roles))
                    . ($perm === 'company.confirm' ? ' cannot confirm the company details.' : ' cannot change the company details.'), 403);
            }
        }
        return ['id' => $caller->staffUserId, 'roles' => $roles];
    }

    /**
     * One line of text: whitespace runs become one space, trimmed. Refused (a sentence in $errors): not UTF-8, a line break,
     * another control character, longer than $max, a character the PDF cannot print.
     *
     * @param array<string, string> $errors
     */
    private static function oneLine(string $raw, int $max, string $field, array &$errors): string
    {
        $the = 'The ' . self::FIELDS[$field];
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $errors[$field] = "{$the} is not valid text: type it again.";
            return '';
        }
        $v = self::nfc($raw);
        if (preg_match('/[\r\n]/', $v) === 1) {
            $errors[$field] = "{$the} must be one line.";
        }
        $v = (string) preg_replace('/[\t\r\n ]+/', ' ', $v);
        $control = preg_match('/[\x00-\x1F\x7F\x{0080}-\x{009F}]/u', $v) === 1;
        $v = trim($v);
        if ($control) {
            $errors[$field] ??= "{$the} contains a control character: type it again.";
        } elseif (mb_strlen($v) > $max) {
            $errors[$field] ??= "{$the} is at most {$max} characters (this one has " . mb_strlen($v) . ').';
        } elseif (($bad = self::unprintable($v)) !== null) {
            $errors[$field] ??= self::unprintableSentence($the, $bad);
        }
        return $v;
    }

    /**
     * An address: one line per line (CR LF and CR read as LF, tabs as spaces, spaces collapsed, blank lines dropped), at most
     * ADDRESS_LINES lines of ADDRESS_LINE_MAX characters, printable on the PDF.
     *
     * @param array<string, string> $errors
     */
    private static function lines(string $raw, string $field, array &$errors): string
    {
        $label = self::FIELDS[$field];
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $errors[$field] = "The {$label} is not valid text: type it again.";
            return '';
        }
        $v = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], self::nfc($raw));
        $control = preg_match('/[\x00-\x09\x0B-\x1F\x7F\x{0080}-\x{009F}]/u', $v) === 1;
        $lines = [];
        foreach (explode("\n", $v) as $l) {
            $l = trim((string) preg_replace('/ {2,}/', ' ', $l));
            if ($l !== '') {
                $lines[] = $l;
            }
        }
        $out = implode("\n", $lines);
        if ($control) {
            $errors[$field] = "The {$label} contains a control character: type it again.";
        } elseif (count($lines) > self::ADDRESS_LINES) {
            $errors[$field] = "The {$label} has at most " . self::ADDRESS_LINES . ' lines (this one has ' . count($lines) . ').';
        } else {
            foreach ($lines as $i => $l) {
                if (mb_strlen($l) > self::ADDRESS_LINE_MAX) {
                    $errors[$field] = 'Line ' . ($i + 1) . " of the {$label} is longer than " . self::ADDRESS_LINE_MAX . ' characters: split it over two lines.';
                    break;
                }
            }
            if (!isset($errors[$field]) && ($bad = self::unprintable($out)) !== null) {
                $errors[$field] = self::unprintableSentence("The {$label}", $bad);
            }
        }
        return $out;
    }

    private static function unprintableSentence(string $subject, string $ch): string
    {
        $shown = preg_match('/^[\p{L}\p{N}\p{S}\p{P}]$/u', $ch) === 1 ? "\"{$ch}\"" : 'an invisible character';
        return "{$subject} contains {$shown}, which a purchase order cannot print (the PDF has Western European letters only, such as é, ü, ß, £ and €): "
            . 'type a plain letter instead.';
    }

    /** Unicode NFC when the intl extension is there (a typed "e" + combining accent becomes the one letter é, which prints). */
    private static function nfc(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            return is_string($n) ? $n : $s;
        }
        return $s;
    }
}
