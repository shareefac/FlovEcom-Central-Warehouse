<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Auth\Permissions;
use CW\Company\CompanyDetails;
use CW\Mapping\KeyHold;
use CW\Mapping\KeySample;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Reorder\ReorderList;
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
                'checks' => $this->ctx->checks() ?? ['approval' => 0, 'review' => 0],
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
                default => throw new \InvalidArgumentException("no Home fact {$key}"),
            };
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
        $out = ['test' => [], 'reviewers' => 0, 'clashes' => []];
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
