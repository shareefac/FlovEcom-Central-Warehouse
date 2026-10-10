<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Auth\Permissions;
use CW\Company\CompanyDetails;
use CW\Mapping\KeyHold;
use CW\Ops\IntegrityRuns;
use CW\Mapping\KeySample;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Reorder\ReorderList;
use CW\Staff\RoleRequests;
use CW\Staff\StaffAdmin;
use CW\Staff\StaffRoles;

/**
 * The counts behind Home's cards (plan §3.2; Ui\HomeTasks turns them into cards). Reads only, and only the facts the signed-in
 * person's jobs use (HomeTasks::needs): a buyer's Home runs no matching query. Where a menu badge counts the same thing
 * (Context::badges, Context::checks), the badge's number is used, computed once per request. The few new counts are single
 * COUNT(*) queries on indexed columns. The two that are not run only for a matching lead and only on what exists: the lead's
 * own spot checks still waiting (KeySample::status, newest SAMPLES) and the set-aside lists (one query per spot check that has
 * them, newest HELD_SAMPLES). The duplicates card has the badge's count only: finding the biggest seller's group means
 * building every open group (Duplicates::openGroups), too slow for the first page after sign-in, so its button opens the list,
 * which shows the biggest sellers first.
 */
final class HomeCounts
{
    /** How many of a lead's newest spot checks Home looks at. */
    public const SAMPLES = 5;
    /** How many spot checks with set-aside matches Home looks at (newest first). */
    public const HELD_SAMPLES = 10;
    /** "Background checks due soon": the next check within this many days (the supplier list's `due` filter). */
    public const DUE_DAYS = Controller\SuppliersController::DUE_WINDOW_DAYS;

    /** @var array<string, array<int, int>>|null */
    private ?array $bandCounts = null;
    /** @var array<int, array{sample_id: int, name: string, open: int}>|null sample id => set-aside matches still waiting */
    private ?array $held = null;

    public function __construct(private readonly Context $ctx)
    {
    }

    /**
     * @param list<string> $keys HomeTasks::needs() of the person
     * @return array<string, mixed> fact => value (HomeTasks::build documents each)
     */
    public function facts(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = match ($key) {
                'company' => $this->company(),
                'checks' => $this->ctx->checks() ?? ['approval' => 0, 'review' => 0, 'deliveries' => 0],
                'samples' => $this->samples(),
                'held' => array_values($this->held()),
                'duplicates' => (int) ($this->ctx->badges()['linking_duplicates'] ?? (new Duplicates($this->ctx->db))->openCount()),
                'pending' => (int) ($this->ctx->badges()['linking_pending'] ?? 0),
                'bands' => $this->bandTotals(),
                'barcodes' => (int) ($this->ctx->badges()['barcodes_open'] ?? 0),
                'orders' => $this->orders(),
                'demand' => $this->ctx->db->value('SELECT 1 FROM reorder_demand LIMIT 1') !== null,
                'sales' => $this->salesLate(),
                'suppliers' => $this->suppliers(),
                'staff' => $this->staff(),
                'receiving' => $this->receiving(),
                'stock_ops' => $this->stockOps(),
                'incidents' => (int) ($this->ctx->badges()['incidents_open'] ?? 0),
                'integrity' => (new IntegrityRuns($this->ctx->db))->latest(),
                'staff_requests' => (new RoleRequests($this->ctx->db))->decidableCount($this->ctx->me()->id, $this->ctx->me()->roles),
                'watch' => $this->watch(),
                'setup_closed' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['display_name']],
                    $this->ctx->db->all('SELECT id, display_name FROM staff_user WHERE is_active = 1 AND setup_closed_at IS NOT NULL ORDER BY setup_closed_at DESC LIMIT 50')),
                default => throw new \InvalidArgumentException("no Home fact {$key}"),
            };
        }
        return $out;
    }

    /**
     * People added, sign-ins reset and approval rules made looser in the audit log's default window (AuditSearch::DEFAULT_DAYS: the
     * card's button opens the same days), newest first (review finding I1): when, who did it, whom, which rule. One indexed read
     * per action (ix_audit_action).
     *
     * @return list<array{at: string, action: string, who: ?string, person: ?string, rule: ?string}>
     */
    private function watch(): array
    {
        $db = $this->ctx->db;
        $out = [];
        foreach ($db->all(
            'SELECT a.id, a.created_at, a.action, a.entity_id, CAST(a.detail AS CHAR) AS detail, u.display_name AS who, p.display_name AS person FROM audit_log a '
            . 'LEFT JOIN staff_user u ON u.id = a.staff_user_id '
            . "LEFT JOIN staff_user p ON a.entity_type = 'staff_user' AND p.id = CAST(a.entity_id AS UNSIGNED) "
            . "WHERE a.action IN ('staff.create', 'staff.reset', 'setting.change', 'document_type.change') "
            . 'AND a.created_at > NOW(6) - INTERVAL ' . \CW\Admin\AuditSearch::DEFAULT_DAYS . ' DAY ORDER BY a.id DESC LIMIT 200',
        ) as $r) {
            $action = (string) $r['action'];
            $detail = json_decode((string) ($r['detail'] ?? ''), true);
            $detail = is_array($detail) ? $detail : [];
            $rule = null;
            if ($action === 'setting.change' || $action === 'document_type.change') {
                if (($detail['loosened'] ?? false) !== true) {
                    continue;
                }
                $key = (string) $r['entity_id'];
                $rule = $action === 'setting.change' ? (Words::RULE[$key]['title'] ?? Words::settingName($key)) : sprintf(Words::WATCH['rule_doc'], Words::docType($key, true));
            }
            $out[] = ['at' => (string) $r['created_at'], 'action' => $action, 'who' => $r['who'] === null ? null : (string) $r['who'],
                'person' => $r['person'] === null ? null : (string) $r['person'], 'rule' => $rule];
        }
        return $out;
    }

    /** @return array<string, array<int, int>> band => channel id => open proposals (Queries::bandCounts, once: the cards and Matching progress share it) */
    public function bandCounts(): array
    {
        return $this->bandCounts ??= $this->ctx->queries()->bandCounts();
    }

    /** @return array<string, int> band => open proposals on every website */
    private function bandTotals(): array
    {
        return array_map(static fn (array $byChannel): int => array_sum($byChannel), $this->bandCounts());
    }

    /** @return array{confirmed: bool, missing: list<string>} */
    private function company(): array
    {
        $p = $this->ctx->company()->current();
        return ['confirmed' => (bool) $p['confirmed'], 'missing' => CompanyDetails::missing($p)];
    }

    /**
     * The person's own spot checks still waiting for answers (verdict `waiting`: nothing failed, not all confirmed), each with
     * the next member to answer (by position) and about how many strong matches it would confirm together (its population
     * less the 20 and the matches set aside from it).
     *
     * @return list<array<string, mixed>>
     */
    private function samples(): array
    {
        $db = $this->ctx->db;
        $ks = new KeySample($db);
        $out = [];
        foreach ($db->column('SELECT id FROM key_sample WHERE created_by = ? ORDER BY id DESC LIMIT ' . self::SAMPLES, [$this->ctx->me()->id]) as $id) {
            $s = $ks->status((int) $id);
            if ($s['verdict'] !== 'waiting') {
                continue;
            }
            $next = null;
            foreach ($s['members'] as $m) {
                if ($m['state'] === 'open') {
                    $next = $m;
                    break;
                }
            }
            $held = $this->held()[(int) $s['id']]['open'] ?? 0;
            $out[] = ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'size' => (int) $s['size'], 'decided' => (int) $s['decided'],
                'rest' => max(0, (int) $s['population'] - (int) $s['size'] - $held),
                'next_listing' => $next === null ? null : (int) $next['listing_id'], 'next_position' => $next === null ? null : (int) $next['position']];
        }
        return $out;
    }

    /**
     * Matches set aside from a spot check that has not failed (a failed one's matches are all checked one at a time anyway), that
     * still wait for a decision; newest spot check first.
     *
     * @return array<int, array{sample_id: int, name: string, open: int}>
     */
    private function held(): array
    {
        if ($this->held !== null) {
            return $this->held;
        }
        $db = $this->ctx->db;
        $ids = array_map('intval', $db->column("SELECT DISTINCT sample_id FROM key_bulk_hold WHERE kind = 'hold' ORDER BY sample_id DESC LIMIT " . self::HELD_SAMPLES));
        $out = [];
        if ($ids !== []) {
            $verdicts = (new KeySample($db))->verdicts($ids);
            foreach ($ids as $sid) {
                if (($verdicts[$sid]['verdict'] ?? 'failed') === 'failed') {
                    continue;
                }
                $rows = KeyHold::ofSample($db, $sid);
                $open = count(array_filter($rows, static fn (array $h): bool => $h['open']));
                if ($open > 0) {
                    $out[$sid] = ['sample_id' => $sid, 'name' => (string) $rows[0]['sample'], 'open' => $open];
                }
            }
        }
        return $this->held = $out;
    }

    /** @return array{drafts: int, not_sent: int, not_ok: int} the person's own drafts; confirmed orders not sent; open orders a reviewer rejected */
    private function orders(): array
    {
        $db = $this->ctx->db;
        $open = "'" . implode("', '", PurchaseOrders::OPEN_STATES) . "'";
        return [
            'drafts' => (int) $db->value("SELECT COUNT(*) FROM document d WHERE d.doc_type = 'PO' AND d.status = 'draft' AND d.created_by = ?", [$this->ctx->me()->id]),
            'not_sent' => (int) $db->value("SELECT COUNT(*) FROM document d JOIN purchase_order po ON po.document_id = d.id "
                . "WHERE d.doc_type = 'PO' AND d.status = 'posted' AND po.state = 'approved'"),
            'not_ok' => (int) $db->value("SELECT COUNT(*) FROM document d JOIN purchase_order po ON po.document_id = d.id "
                . "WHERE d.doc_type = 'PO' AND d.status = 'posted' AND d.review_state = 'rejected' AND po.state IN ({$open})"),
        ];
    }

    /**
     * The person's own stock records (pack A1) not final yet: `drafts` (started, not made final) and `waiting` (sent for a reviewer's
     * OK), with the kind of the first of each (the card's button opens that kind's list). One query.
     *
     * @return array{drafts: int, waiting: int, drafts_kind: ?string, waiting_kind: ?string}
     */
    private function stockOps(): array
    {
        $out = ['drafts' => 0, 'waiting' => 0, 'drafts_kind' => null, 'waiting_kind' => null];
        foreach ($this->ctx->db->all("SELECT d.status, d.doc_type, COUNT(*) AS n FROM document d WHERE d.doc_type IN ('SIN', 'SOUT', 'ADJ', 'TRF', 'REL') "
            . "AND d.status IN ('draft', 'awaiting_approval') AND d.created_by = ? GROUP BY d.status, d.doc_type ORDER BY d.doc_type", [$this->ctx->me()->id]) as $r) {
            $k = $r['status'] === 'draft' ? 'drafts' : 'waiting';
            $out[$k] += (int) $r['n'];
            $out[$k . '_kind'] ??= \CW\StockOps\StockOps::kindOf((string) $r['doc_type']);
        }
        return $out;
    }

    /**
     * Deliveries not booked in yet (IM6): `bench` = with products, still waiting for the goods-in bench (the paperwork not answered,
     * or a line not checked; not one whose paperwork the bench found not right: that is the desk's to refuse); `to_post` = checked
     * at the bench (the paperwork looks right, every line checked), waiting to be booked in. One query (the drafts are few).
     *
     * @return array{bench: int, to_post: int}
     */
    private function receiving(): array
    {
        $r = $this->ctx->db->one(
            "SELECT COALESCE(SUM(x.lines_n > 0 AND NOT (x.paperwork_ok <=> 0) AND (x.paperwork_ok IS NULL OR x.unchecked > 0)), 0) AS bench, "
            . 'COALESCE(SUM(x.lines_n > 0 AND x.paperwork_ok = 1 AND x.unchecked = 0), 0) AS to_post, '
            . 'COALESCE(SUM(x.paperwork_ok <=> 0), 0) AS refused FROM (SELECT g.paperwork_ok, '
            . '(SELECT COUNT(*) FROM grn_line l WHERE l.document_id = d.id) AS lines_n, '
            . '(SELECT COUNT(*) FROM grn_line l WHERE l.document_id = d.id AND l.checked_at IS NULL) AS unchecked '
            . "FROM document d JOIN goods_receipt g ON g.document_id = d.id WHERE d.doc_type = 'GRN' AND d.status = 'draft') x",
        ) ?? [];
        return ['bench' => (int) ($r['bench'] ?? 0), 'to_post' => (int) ($r['to_post'] ?? 0), 'refused' => (int) ($r['refused'] ?? 0)];
    }

    /** Days the oldest late sales data is behind (What to buy's header: a website whose last day loaded is too old), or null. */
    private function salesLate(): ?int
    {
        $ctx = $this->ctx;
        $header = (new ReorderList($ctx->db, new PurchaseOrders($ctx->db, $ctx->documents(), $ctx->settings()), $ctx->settings()))->header();
        $late = array_column(array_filter($header['channels'], static fn (array $c): bool => $c['stale']), 'days_ago');
        return $late === [] ? null : (int) max($late);
    }

    /** @return array{drafts: int, due: int} draft suppliers; suppliers in use whose background check is due within DUE_DAYS or late */
    private function suppliers(): array
    {
        $db = $this->ctx->db;
        $by = (new \DateTimeImmutable($this->ctx->suppliers()->today()))->modify('+' . self::DUE_DAYS . ' days')->format('Y-m-d');
        return [
            'drafts' => (int) $db->value("SELECT COUNT(*) FROM supplier WHERE status = 'draft'"),
            'due' => (int) $db->value("SELECT COUNT(*) FROM supplier WHERE status IN ('active', 'pending_approval') AND dd_next_review_on <= ?", [$by]),
        ];
    }

    /**
     * For the admin (People and roles' warnings, plan C19): test accounts that can sign in, how many people can really approve
     * work (Reviewer, not switched off by Admin), and the people whose jobs Admin switches off.
     *
     * @return array{test: list<array{id: int, name: string}>, reviewers: int, clashes: list<array{id: int, name: string, off: list<string>}>}
     */
    private function staff(): array
    {
        $out = ['test' => [], 'reviewers' => 0, 'clashes' => [], 'min' => Controller\PeopleController::minReviewers($this->ctx->db)];
        foreach (StaffRoles::people($this->ctx->db) as $p) {
            if (!$p['is_active']) {
                continue;
            }
            if (StaffAdmin::isPlaceholder($p['email'])) {
                $out['test'][] = ['id' => $p['id'], 'name' => $p['display_name']];
            }
            $off = Permissions::switchedOff($p['roles']);
            if (in_array('reviewer', $p['roles'], true) && !in_array('reviewer', $off, true)) {
                $out['reviewers']++;
            }
            if ($off !== []) {
                $out['clashes'][] = ['id' => $p['id'], 'name' => $p['display_name'], 'off' => $off];
            }
        }
        return $out;
    }
}
