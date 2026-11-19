<?php

declare(strict_types=1);

namespace CW\Live;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;

/**
 * Who changed what on a draft, line by line (the live drafts plan, step 4, 4.1; docs/decisions.md LS5-LS7, LS11): one draft_change row (0072)
 * per change a page saved by itself (a line set, added, removed, put back; a head box) and per whole save that moved every line (the supplier,
 * the lines replaced from a file), and one per refused "same line at the same moment" (op conflict: the measure of how often two people meet,
 * the plan's 7.4). The page's History card groups them; the other open pages read the newest in words; an add or an undo sent twice (its key,
 * op_key) is done once.
 *
 *   audit    the audit log (append-only, kept seven years) gets ONE row per person per draft per clock hour (the first change of that hour,
 *            decided here by the person's draft_change rows of the hour): the detail is in draft_change, which the nightly clean-up prunes
 *            (Ops\Housekeeping: housekeeping.draft_change_keep_days after the document is no longer a draft). Confirm, cancel and the whole
 *            saves audit as before.
 *   time     created_at is CW's clock (the services' $clock: the tests' fixed one; in production the server's, as the database's).
 *   reads    by (kind, record_id, id): a draft's rows, newest first, bounded (READ); names by primary key.
 */
final class LiveChanges
{
    public const OPS = ['set', 'add', 'remove', 'restore', 'header', 'supplier', 'replace', 'conflict'];
    /** A page's key of an add or an undo (draft_change.op_key), and its tab: 16 lower-case hex digits. */
    public const KEY = '/^[0-9a-f]{16}$/D';
    /** The most rows a page's History or its words read at once (the newest). */
    public const READ = 500;
    /** The most changes in words one answer carries (the newest; the page says one at a time). */
    public const EVENTS = 20;
    /** The audit action of a person's changes of the hour (one row per person per draft per hour). */
    public const AUDIT = 'po.live_edit';

    /** The start of $now's clock hour ("2026-11-19 10:00:00.000000"; a DATETIME(6) text of CW's UTC clock). */
    public static function hourStart(string $now): string
    {
        return substr($now, 0, 13) . ':00:00.000000';
    }

    /**
     * Records one change: the hour's audit row when it is the person's first change of the draft in this clock hour, then the draft_change
     * row. Inside the caller's transaction (the document row locked: the person's rows of the hour cannot change meanwhile). Returns its id.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function record(Db $db, Caller $caller, string $kind, int $recordId, string $op, ?int $uid, ?string $field, ?array $before, ?array $after,
        ?string $opKey, int $version, ?string $tab, string $now): int
    {
        if (!in_array($op, self::OPS, true) || $op === 'conflict' || $caller->staffUserId === null) {
            throw new \InvalidArgumentException("not a change to record: {$op}");
        }
        $first = $db->value("SELECT 1 FROM draft_change WHERE kind = ? AND record_id = ? AND staff_user_id = ? AND op <> 'conflict' AND created_at >= ? LIMIT 1",
            [$kind, $recordId, $caller->staffUserId, self::hourStart($now)]) === null;
        if ($first) {
            Audit::write($db, $caller, self::AUDIT, 'document', (string) $recordId, null, ['version' => $version, 'op' => $op, 'from' => self::hourStart($now)]);
        }
        return self::insert($db, $caller->staffUserId, $kind, $recordId, $op, $uid, $field, $before, $after, $opKey, $version, $tab, $now);
    }

    /**
     * Records a refused "same line (or box) at the same moment" (op conflict): what the person tried ($tried), the version the draft was at.
     * Called after the refused transaction rolled back (its own statement). A failure here never hides the refusal: it is only counted.
     *
     * @param array<string, mixed> $tried
     */
    public static function conflict(Db $db, Caller $caller, string $kind, int $recordId, ?int $uid, ?string $field, array $tried, ?string $tab, string $now): void
    {
        if ($caller->staffUserId === null) {
            return;
        }
        try {
            $version = (int) ($db->value('SELECT version FROM document WHERE id = ?', [$recordId]) ?? 0);
            self::insert($db, $caller->staffUserId, $kind, $recordId, 'conflict', $uid, $field, null, $tried, null, $version, $tab, $now);
        } catch (\PDOException) {
            // The measure only: the person is told of the clash whatever happens here.
        }
    }

    /**
     * The change an add or an undo of this key made already (a retry: done once), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function done(Db $db, string $kind, int $recordId, string $opKey): ?array
    {
        return $db->one('SELECT * FROM draft_change WHERE kind = ? AND record_id = ? AND op_key = ?', [$kind, $recordId, $opKey]);
    }

    /** The newest change of a draft (0: none): a page that shows it is told only of later ones. */
    public static function newest(Db $db, string $kind, int $recordId): int
    {
        return (int) ($db->value('SELECT MAX(id) FROM draft_change WHERE kind = ? AND record_id = ?', [$kind, $recordId]) ?? 0);
    }

    /**
     * The changes after $since (oldest first, at most EVENTS: the newest), not the conflicts, not those of the page asking ($tab): what the other
     * open pages say in words. Each with who (display_name).
     *
     * @return list<array<string, mixed>>
     */
    public static function since(Db $db, string $kind, int $recordId, int $since, ?string $tab): array
    {
        $rows = $db->all('SELECT c.id, c.staff_user_id, c.tab, c.op, c.line_uid, c.field, CAST(c.before_json AS CHAR) AS before_json, CAST(c.after_json AS CHAR) AS after_json, '
            . "c.created_at, u.display_name FROM draft_change c LEFT JOIN staff_user u ON u.id = c.staff_user_id WHERE c.kind = ? AND c.record_id = ? AND c.id > ? AND c.op <> 'conflict' "
            . 'AND (c.tab IS NULL OR c.tab <> ?) ORDER BY c.id DESC LIMIT ' . self::EVENTS, [$kind, $recordId, $since, $tab ?? '']);
        return array_reverse(array_map(self::decode(...), $rows));
    }

    /**
     * A draft's changes for its History card, newest first (at most READ), not the conflicts; each with who.
     *
     * @return list<array<string, mixed>>
     */
    public static function history(Db $db, string $kind, int $recordId): array
    {
        try {
            $rows = $db->all('SELECT c.id, c.staff_user_id, c.tab, c.op, c.line_uid, c.field, CAST(c.before_json AS CHAR) AS before_json, CAST(c.after_json AS CHAR) AS after_json, '
                . "c.created_at, u.display_name FROM draft_change c LEFT JOIN staff_user u ON u.id = c.staff_user_id WHERE c.kind = ? AND c.record_id = ? AND c.op <> 'conflict' "
                . 'ORDER BY c.id DESC LIMIT ' . self::READ, [$kind, $recordId]);
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === 1146) {
                return []; // the code a moment before 0072
            }
            throw $e;
        }
        return array_map(self::decode(...), $rows);
    }

    /**
     * Rows (newest first) in groups for the History card: one group per run of one person's changes in the same minute, newest first; each
     * with its time (the run's newest), who, how many lines it touched, whether it touched the head, and its rows (oldest first).
     *
     * @param list<array<string, mixed>> $rows history()'s
     * @return list<array{at: string, staff_user_id: int, name: string, lines: int, head: bool, rows: list<array<string, mixed>>}>
     */
    public static function group(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $n = count($groups) - 1;
            $who = (int) $r['staff_user_id'];
            if ($n >= 0 && $groups[$n]['staff_user_id'] === $who && substr($groups[$n]['first'], 0, 16) === substr((string) $r['created_at'], 0, 16)) {
                $groups[$n]['rows'][] = $r;
                $groups[$n]['first'] = (string) $r['created_at'];
                continue;
            }
            $groups[] = ['at' => (string) $r['created_at'], 'first' => (string) $r['created_at'], 'staff_user_id' => $who, 'name' => (string) $r['name'], 'rows' => [$r]];
        }
        $out = [];
        foreach ($groups as $g) {
            $uids = [];
            $head = false;
            foreach ($g['rows'] as $r) {
                if ($r['op'] === 'header' || $r['op'] === 'supplier') {
                    $head = true;
                } elseif ($r['line_uid'] !== null) {
                    $uids[(int) $r['line_uid']] = true;
                } else {
                    $uids['r' . $r['id']] = true;
                }
            }
            $out[] = ['at' => $g['at'], 'staff_user_id' => $g['staff_user_id'], 'name' => $g['name'], 'lines' => count($uids), 'head' => $head, 'rows' => array_reverse($g['rows'])];
        }
        return $out;
    }

    /** A tab as the page sent it, or null (none, or not 16 lower-case hex: it is only who sent it, never a refusal). */
    public static function tab(?string $tab): ?string
    {
        return $tab !== null && preg_match(self::KEY, $tab) === 1 ? $tab : null;
    }

    /** A page's key of an add or an undo (400 bad_key otherwise: without it a retry could add twice). */
    public static function key(?string $key): string
    {
        if ($key === null || preg_match(self::KEY, $key) !== 1) {
            throw new CwException('bad_key', 'an add or an undo carries the page\'s key: 16 lower-case hex digits', 400, ['field' => 'k']);
        }
        return $key;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private static function insert(Db $db, int $staffId, string $kind, int $recordId, string $op, ?int $uid, ?string $field, ?array $before, ?array $after, ?string $opKey,
        int $version, ?string $tab, string $now): int
    {
        $json = static fn (?array $v): ?string => $v === null ? null : json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $db->insert('INSERT INTO draft_change (kind, record_id, staff_user_id, tab, op, line_uid, field, before_json, after_json, op_key, doc_version, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$kind, $recordId, $staffId, self::tab($tab), $op, $uid, $field, $json($before), $json($after), $opKey, $version, $now]);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function decode(array $r): array
    {
        $j = static function (mixed $v): ?array {
            $d = is_string($v) ? json_decode($v, true) : null;
            return is_array($d) ? $d : null;
        };
        return ['id' => (int) $r['id'], 'staff_user_id' => (int) $r['staff_user_id'], 'name' => (string) ($r['display_name'] ?? '#' . $r['staff_user_id']),
            'tab' => $r['tab'] === null ? null : (string) $r['tab'], 'op' => (string) $r['op'], 'line_uid' => $r['line_uid'] === null ? null : (int) $r['line_uid'],
            'field' => $r['field'] === null ? null : (string) $r['field'], 'before' => $j($r['before_json']), 'after' => $j($r['after_json']), 'created_at' => (string) $r['created_at']];
    }
}
