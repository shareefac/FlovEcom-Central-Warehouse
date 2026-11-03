<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Counts\CountReviews;
use CW\Db;
use CW\Queues\Corrections;
use CW\Queues\ModeChanges;
use CW\Queues\QueueRules;
use CW\Ui\Controller\InboxController;

/**
 * Approvals' "Answered today" (the approved Approvals artboard's last Show choice, docs/decisions.md SS3): what was answered since midnight
 * UK time, by anyone, in the kinds the person answers; read only, each kind from its own table (who answered and when are on every row the
 * answer closes: resolved_by / resolved_at, decided_by / decided_at). Newest answer first, at most PAGE a kind. A row says what it was,
 * how it ended, who answered and when; it links the kind's own page (the record is not waiting any more, so the inbox cannot open it).
 *
 * The answered rows are read by their time, which no index holds (the tables are indexed for what waits): each read walks the kind's
 * closed rows. Only this Show choice reads them, never the inbox's every view.
 */
final class AnsweredToday
{
    public const PAGE = QueueRules::PAGE;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The groups of the kinds given (InboxController::kinds, in its order), each as the inbox draws a group; a kind with no answer today is
     * left out.
     *
     * @param list<string> $kinds
     * @return list<array<string, mixed>>
     */
    public function groups(array $kinds, \DateTimeImmutable $now): array
    {
        $since = SalesViews::since($now)['today'];
        $groups = [];
        foreach ($kinds as $kind) {
            $rows = $this->rows($kind, $since);
            if ($rows === []) {
                continue;
            }
            $names = QueueRules::names($this->db, array_column($rows, 'by'));
            $out = [];
            foreach (array_slice($rows, 0, self::PAGE) as $i => $r) {
                $who = $r['by'] === null ? Words::RD_INBOX['by_system'] : ($names[(int) $r['by']] ?? Words::RD_INBOX['by_someone']);
                $out[] = ['key' => 'answered-' . $kind . '-' . $i, 'kind' => $kind, 'title' => $r['title'] !== '' ? $r['title'] : Words::INBOX['kind_' . $kind],
                    'meta' => Words::say('RD_INBOX', 'answered_meta', Words::RD_INBOX['outcome_' . $r['outcome']] ?? Words::RD_INBOX['outcome_resolved'], $who,
                        Html::clock($r['at'], $now)),
                    'href' => InboxController::KINDS[$kind]['page'], 'open' => false];
            }
            $groups[] = ['kind' => $kind, 'id' => 'answered-' . $kind, 'name' => Words::INBOX['kind_' . $kind], 'tone' => InboxController::KINDS[$kind]['tone'],
                'count' => count($rows), 'more' => count($rows) > self::PAGE, 'shown' => min(self::PAGE, count($rows)), 'href' => InboxController::KINDS[$kind]['page'], 'rows' => $out];
        }
        return $groups;
    }

    /**
     * One kind's answers since $since (a UTC database time), newest first, at most PAGE + 1: what it was (`title`), how it ended (`outcome`:
     * resolved, dismissed, approved, rejected; a store's choice joined, made_by_person, not_stock_by_person), who answered (`by`: a staff id, null for the system) and when (`at`).
     *
     * @return list<array{title: string, outcome: string, by: ?int, at: string}>
     */
    private function rows(string $kind, string $since): array
    {
        $lim = ' ORDER BY at DESC LIMIT ' . (self::PAGE + 1);
        [$sql, $params] = match ($kind) {
            // A count difference's answer is its resolution (CountReviews: accepted or rejected, both `resolved`); the kind lists only
            // CountReviews::LISTED_SOURCES (a VERIFY recount the system closes is none of its).
            'corrections' => ["SELECT s.name AS title, CAST(r.status AS CHAR) AS outcome, r.resolved_by AS by_id, r.resolved_at AS at FROM count_review r "
                . "JOIN sku s ON s.id = r.sku_id WHERE r.status <> 'open' AND r.resolved_at >= ? AND r.source = ?", [$since, Corrections::SOURCE]],
            'counts' => ["SELECT s.name AS title, CASE r.resolution WHEN 'rejected' THEN 'rejected' WHEN 'accepted' THEN 'approved' ELSE CAST(r.status AS CHAR) END AS outcome, "
                . "r.resolved_by AS by_id, r.resolved_at AS at FROM count_review r JOIN sku s ON s.id = r.sku_id WHERE r.status <> 'open' AND r.resolved_at >= ? "
                . 'AND r.source IN (' . implode(', ', array_fill(0, count(CountReviews::LISTED_SOURCES), '?')) . ')', [$since, ...CountReviews::LISTED_SOURCES]],
            'suspense' => ["SELECT COALESCE(g.description, g.doc_ref) AS title, CAST(g.status AS CHAR) AS outcome, g.resolved_by AS by_id, g.resolved_at AS at "
                . "FROM goods_in_suspense g WHERE g.status <> 'open' AND g.resolved_at >= ?", [$since]],
            'oversold' => ["SELECT s.name AS title, CAST(o.status AS CHAR) AS outcome, o.resolved_by AS by_id, o.resolved_at AS at FROM oversell_event o "
                . "JOIN sku s ON s.id = o.sku_id WHERE o.status <> 'open' AND o.resolved_at >= ?", [$since]],
            'modes' => ["SELECT COALESCE(s.name, cl.external_variant_id, '') AS title, CAST(p.status AS CHAR) AS outcome, p.resolved_by AS by_id, p.resolved_at AS at "
                . "FROM policy_review p LEFT JOIN sku s ON s.id = p.sku_id LEFT JOIN channel_listing cl ON cl.id = p.listing_id "
                . "WHERE p.source = ? AND p.status <> 'open' AND p.resolved_at >= ?", [ModeChanges::SOURCE, $since]],
            'landing' => ["SELECT o.order_ref AS title, CAST(o.status AS CHAR) AS outcome, o.resolved_by AS by_id, o.resolved_at AS at FROM landing_order o "
                . "WHERE o.status <> 'open' AND o.resolved_at >= ?", [$since]],
            // A store's choice a person answered (docs/decisions.md SC9; the job's own are not answers): joined, a new item, not stock.
            'new_products' => ["SELECT COALESCE(NULLIF(lp.variant_title, ''), lp.product_title, sc.external_variant_id) AS title, sc.outcome AS outcome, sc.settled_by AS by_id, "
                . 'sc.settled_at AS at FROM store_choice sc LEFT JOIN listing_profile lp ON lp.listing_id = sc.listing_id WHERE sc.settled_by IS NOT NULL AND sc.settled_at >= ?',
                [$since]],
            'staff' => ["SELECT u.display_name AS title, CAST(q.state AS CHAR) AS outcome, q.decided_by AS by_id, q.decided_at AS at FROM staff_role_request q "
                . "JOIN staff_user u ON u.id = q.staff_user_id WHERE q.state IN ('approved', 'rejected') AND q.decided_at >= ?", [$since]],
            'documents' => ["SELECT COALESCE(d.number, sp.name, '') AS title, CAST(t.state AS CHAR) AS outcome, t.decided_by AS by_id, t.decided_at AS at FROM review_task t "
                . "LEFT JOIN document d ON t.subject_type = 'document' AND d.id = t.subject_id LEFT JOIN supplier sp ON t.subject_type = 'supplier' AND sp.id = t.subject_id "
                . "WHERE t.state IN ('approved', 'rejected') AND t.decided_at >= ?", [$since]],
            default => ['', []],
        };
        if ($sql === '') {
            return [];
        }
        return array_map(static fn (array $r): array => ['title' => (string) ($r['title'] ?? ''), 'outcome' => (string) $r['outcome'],
            'by' => $r['by_id'] === null ? null : (int) $r['by_id'], 'at' => (string) $r['at']], $this->db->all($sql . $lim, $params));
    }

    /** Whether a Show value is this choice. */
    public static function is(mixed $show): bool
    {
        return $show === 'answered';
    }
}
