<?php

declare(strict_types=1);

namespace CW\Live;

use CW\Auth\Sessions;
use CW\CwException;
use CW\Db;
use CW\Settings;

/**
 * Who is here on a draft (the live drafts plan, step 3; the owner, 18 Nov 2026: "we need to show each other if each others are in the same
 * window as like in google sheets where we know which line they are pressed in"; docs/decisions.md LV1-LV12).
 *
 * Each open page of a draft (a tab, named by a random id printed in the page: newTab()) polls a short POST (Ui\Controller\LiveController):
 * where its person is (a line's own id, document_line.uid, and the box, or a header box), whether they are active or away and typing.
 * beat() answers who else has the draft open, where, in which colour and whether they may change it, the draft's version and how soon to
 * poll again; or, when the document is no longer a draft, what it became. One small table (draft_presence, 0070), no APCu, no long poll:
 *
 *   per poll   the settings (one read by primary key), the document (by primary key; its poster's or canceller's name by primary key),
 *              the draft's recent rows (the index (kind, record_id, seen_at): those that can still be shown, newest first, at most
 *              MAX_ROWS; each joined to its person and its session by primary key); every read carries MAX_EXECUTION_TIME(QUERY_MS). Then
 *              at most one write: the poll's own row, only when its place, state, typing or right to change the draft differs from the
 *              row read, or the row is REFRESH_SECONDS old or not among those read (needsWrite()).
 *   here       an active row seen within goneAfter() seconds, an away row within AWAY_SECONDS, of a session that has not ended (signed
 *              out, signed out by a manager, a password changed, past the idle or absolute limit) and a person not switched off; the poller's own tabs are
 *              nobody (no "you are here too"); a person on two tabs is shown once (an active tab first, then the newest). Only the
 *              place is shown, never what is typed; nothing personal beyond the display name.
 *   colours    1..COLOURS, decided here so every screen agrees (colours(): over everyone here, the poller too).
 *   leaving    a page left sends bye (its row goes); signing out removes the session's rows (forget(), from the sign-out); the nightly
 *              clean-up removes rows older than KEEP_HOURS (Ops\Housekeeping).
 *   off        live.mode = off: the answer is {"mode": "off"} and nothing is read or written (the switch undoes the step without a deploy).
 *
 * The route is passive (Ui\Route::$passive): a poll never moves the session's last activity unless it says active=1, so a page left open
 * is signed out after the idle limit like any other.
 */
final class Presence
{
    /** The pages that show who is here: kind => the document type and the page's path (step 3: purchase orders). */
    public const KINDS = ['po' => ['type' => 'PO', 'path' => '/ui/purchasing/orders/%d']];
    /** The settings (0070, Settings::RULES) => their defaults: the step is off until the owner switches it on. */
    public const SETTINGS = ['live.mode' => 'off', 'live.poll_seconds' => 3, 'live.alone_seconds' => 10, 'live.gone_seconds' => 25];
    public const MODES = ['off', 'presence', 'on'];
    /** An away row (no key or mouse for a while, the tab hidden) is shown this long after its last poll. */
    public const AWAY_SECONDS = 600;
    /** A row whose place and state did not change is written again when it is this old (so others know the page is still open). */
    public const REFRESH_SECONDS = 10;
    /** The network's few seconds on top of a person's next poll (goneAfter()). */
    public const SLACK_SECONDS = 5;
    /** Rows older than this go in the nightly clean-up. */
    public const KEEP_HOURS = 24;
    public const COLOURS = 8;
    /** The time limit of each read of a poll, in milliseconds. */
    public const QUERY_MS = 500;
    /** The most rows of one draft a poll reads (the newest): a page that sends many tabs cannot make every poll of the draft heavy. */
    public const MAX_ROWS = 100;
    /**
     * The page's own rhythm (step 3b, the plan's 3.4; printed in the page, so app.js holds no number of its own): no key or mouse for
     * IDLE_AWAY_SECONDS, the page says away; for AWAY_SECONDS, it stops checking ("Paused. Click to carry on.") and says goodbye; it says
     * active=1 at most once every ACTIVE_SECONDS and only after a key or the mouse (LV4); an answer slower than SLOW_MS, or none, doubles the
     * wait up to BACKOFF_SECONDS; the quick check is spread by JITTER_MS either way, so pages opened together do not check together.
     */
    public const IDLE_AWAY_SECONDS = 120;
    public const ACTIVE_SECONDS = 60;
    public const BACKOFF_SECONDS = 30;
    public const SLOW_MS = 2000;
    public const JITTER_MS = 300;
    /** The page says who came and who left (its polite live region) at most once in this many seconds (the plan's 2.4). */
    public const SAY_SECONDS = 5;
    /**
     * A place: "<line id>:<box>" (a line's box: qty, price, upp, item, ...) or "h:<box>" (a header box: h:note, h:expected_date, ...); a box is
     * lower-case letters and _, 24 characters at most with its "h:" (draft_presence.focus_field).
     */
    private const FOCUS = '/^(?:(h:[a-z][a-z_]{0,21})|([1-9][0-9]{0,9}):([a-z][a-z_]{0,23}))$/D';
    private const TAB = '/^[0-9a-f]{16}$/D';
    private const UID_MAX = 4294967295;

    public const SETTINGS_SQL = 'SELECT /*+ MAX_EXECUTION_TIME(' . self::QUERY_MS . ') */ setting_key, CAST(value_json AS CHAR) AS v FROM app_setting '
        . 'WHERE setting_key IN (?, ?, ?, ?)';
    /** The document and the name of who made it final, sent it for its OK or stopped it. */
    public const DOCUMENT_SQL = 'SELECT /*+ MAX_EXECUTION_TIME(' . self::QUERY_MS . ') */ d.doc_type, d.status, d.version, d.number, u.display_name AS by_name '
        . 'FROM document d LEFT JOIN staff_user u ON u.id = (CASE d.status WHEN \'cancelled\' THEN d.cancelled_by WHEN \'awaiting_approval\' THEN d.submitted_by '
        . 'ELSE d.posted_by END) WHERE d.id = ?';
    /**
     * The rows of one draft that can still be shown, newest first, at most MAX_ROWS (the index (kind, record_id, seen_at)), with their
     * people and their sessions: switched-off people and ended sessions left out ($live: Auth\Sessions::liveSql('s'), the limits of this
     * request: not revoked, within the idle and absolute limits), and the database's clock. The parameters: kind, record id, the longest a
     * row is shown (max(goneAfter, AWAY_SECONDS)), AWAY_SECONDS for an away row, goneAfter for an active one: the cut-offs of isHere() are
     * applied before the LIMIT, so rows no longer shown never take the place of one that is.
     */
    public static function rowsSql(string $live): string
    {
        return 'SELECT /*+ MAX_EXECUTION_TIME(' . self::QUERY_MS . ') JOIN_ORDER(p, u, s) INDEX(p ix_draft_presence_record_seen) */ p.tab, p.staff_user_id, '
            . 'p.session_id, p.focus_uid, p.focus_field, p.state, p.typing, p.edits, p.seen_at, NOW(6) AS db_now, u.display_name FROM draft_presence p '
            . 'JOIN staff_user u ON u.id = p.staff_user_id AND u.is_active = 1 JOIN staff_session s ON s.id = p.session_id AND ' . $live . ' '
            . 'WHERE p.kind = ? AND p.record_id = ? AND p.seen_at > NOW(6) - INTERVAL ? SECOND '
            . "AND (p.state = 'away' AND p.seen_at > NOW(6) - INTERVAL ? SECOND OR p.state = 'active' AND p.seen_at > NOW(6) - INTERVAL ? SECOND) "
            . 'ORDER BY p.seen_at DESC LIMIT ' . self::MAX_ROWS;
    }

    private readonly Sessions $sessions;

    /** @param Sessions|null $sessions the request's (Ui\Context::sessions(): the session limits read once), else a new one */
    public function __construct(private readonly Db $db, ?Sessions $sessions = null)
    {
        $this->sessions = $sessions ?? new Sessions($db);
    }

    /** A new tab's id (printed in the page; the page sends it with every poll). */
    public static function newTab(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * The settings in force (one read by primary key). A row that is missing (a schema before 0070) or outside its rule (only SQL could
     * store one) reads as its default, each on its own.
     *
     * @return array{mode: string, poll: int, alone: int, gone: int}
     */
    public static function settings(Db $db): array
    {
        $stored = [];
        foreach ($db->all(self::SETTINGS_SQL, array_keys(self::SETTINGS)) as $r) {
            $stored[(string) $r['setting_key']] = (string) $r['v'];
        }
        return self::settingsFrom($stored);
    }

    /**
     * The settings from their stored JSON text (CAST(value_json AS CHAR)), with their defaults and rules (Settings::RULES).
     *
     * @param array<string, string> $stored
     * @return array{mode: string, poll: int, alone: int, gone: int}
     */
    public static function settingsFrom(array $stored): array
    {
        $mode = self::SETTINGS['live.mode'];
        $raw = json_decode(trim($stored['live.mode'] ?? ''), false, 2);
        if (is_string($raw) && in_array($raw, Settings::RULES['live.mode']['in'], true)) {
            $mode = $raw;
        }
        $int = static function (string $key) use ($stored): int {
            $raw = trim($stored[$key] ?? '');
            $rule = Settings::RULES[$key];
            return preg_match('/^\d{1,9}$/D', $raw) === 1 && (int) $raw >= (int) $rule['min'] && (int) $raw <= (int) $rule['max']
                ? (int) $raw : (int) self::SETTINGS[$key];
        };
        return ['mode' => $mode, 'poll' => $int('live.poll_seconds'), 'alone' => $int('live.alone_seconds'), 'gone' => $int('live.gone_seconds')];
    }

    /**
     * After how many seconds without a poll an active person is gone: live.gone_seconds, but never before their next poll could have written
     * their row (it is written at most every REFRESH_SECONDS; a page polls every poll_seconds while someone else is here, alone_seconds when
     * alone), plus SLACK_SECONDS for the network.
     *
     * @param array{mode: string, poll: int, alone: int, gone: int} $s
     */
    public static function goneAfter(array $s): int
    {
        return max($s['gone'], max($s['poll'], $s['alone']) + self::REFRESH_SECONDS + self::SLACK_SECONDS);
    }

    /**
     * How soon the page polls again, in milliseconds: quickly while someone else is here (active or away: my row stays fresh for them),
     * slowly when alone.
     *
     * @param array{mode: string, poll: int, alone: int, gone: int} $s
     */
    public static function pollMs(array $s, bool $othersHere): int
    {
        return ($othersHere ? $s['poll'] : $s['alone']) * 1000;
    }

    /**
     * What a poll sent, checked: tab (16 hex: 400 bad_tab otherwise: the row cannot be named), focus ("7:qty" = line 7's packs box,
     * "h:note" = a header box; anything else is no place, never a refusal), state (away, or active), typing and bye (1, or not).
     *
     * @param array<string, mixed> $post
     * @return array{tab: string, uid: ?int, field: ?string, state: string, typing: int, bye: bool}
     */
    public static function input(array $post): array
    {
        $text = static fn (string $k): ?string => isset($post[$k]) && is_string($post[$k]) ? $post[$k] : null;
        $tab = $text('tab');
        if ($tab === null || preg_match(self::TAB, $tab) !== 1) {
            throw new CwException('bad_tab', 'the page did not say which open page this is', 400);
        }
        $uid = null;
        $field = null;
        $focus = $text('focus');
        if ($focus !== null && preg_match(self::FOCUS, $focus, $m) === 1) {
            if ($m[1] !== '') {
                $field = $m[1];
            } elseif ((int) $m[2] <= self::UID_MAX) {
                $uid = (int) $m[2];
                $field = $m[3];
            }
        }
        return ['tab' => $tab, 'uid' => $uid, 'field' => $field, 'state' => $text('state') === 'away' ? 'away' : 'active',
            'typing' => $text('typing') === '1' ? 1 : 0, 'bye' => $text('bye') === '1'];
    }

    /**
     * Whether a row is shown: active and seen less than $goneSeconds ago, or away and seen less than AWAY_SECONDS ago (the database's clock:
     * $seenAt and $now are its DATETIME(6) texts).
     */
    public static function isHere(string $state, string $seenAt, string $now, int $goneSeconds): bool
    {
        $limit = match ($state) {
            'active' => $goneSeconds,
            'away' => self::AWAY_SECONDS,
            default => null,
        };
        return $limit !== null && self::ageMicro($seenAt, $now) < $limit * 1_000_000;
    }

    /**
     * Whether the poll writes its row: there is none; or its place, state, typing or right to change the draft differ from the row read; or
     * the row is REFRESH_SECONDS old.
     *
     * @param array<string, mixed>|null $row the row read (draft_presence columns, as the database returns them)
     * @param array{tab: string, uid: ?int, field: ?string, state: string, typing: int, bye: bool} $in
     */
    public static function needsWrite(?array $row, array $in, bool $edits, string $now): bool
    {
        if ($row === null) {
            return true;
        }
        $uid = $row['focus_uid'] === null ? null : (int) $row['focus_uid'];
        $field = $row['focus_field'] === null ? null : (string) $row['focus_field'];
        return $uid !== $in['uid'] || $field !== $in['field'] || (string) $row['state'] !== $in['state'] || (int) $row['typing'] !== $in['typing']
            || (int) $row['edits'] !== ($edits ? 1 : 0) || self::ageMicro((string) $row['seen_at'], $now) >= self::REFRESH_SECONDS * 1_000_000;
    }

    /**
     * Each person's colour, 1..COLOURS, the same on every screen: in id order, a person takes id % COLOURS + 1, or the next free colour when
     * a lower id has it; past COLOURS people a colour is shared (the person's own one).
     *
     * @param list<int> $staffIds
     * @return array<int, int> id => colour, in id order
     */
    public static function colours(array $staffIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $staffIds)));
        sort($ids);
        $out = [];
        $taken = [];
        foreach ($ids as $id) {
            $want = $id % self::COLOURS + 1;
            $c = $want;
            for ($i = 0; $i < self::COLOURS && isset($taken[$c]); $i++) {
                $c = $c % self::COLOURS + 1;
            }
            if (isset($taken[$c])) {
                $c = $want;
            }
            $taken[$c] = true;
            $out[$id] = $c;
        }
        return $out;
    }

    /** The page of a kind's record (where a page goes once its draft is no longer one). */
    public static function pagePath(string $kind, int $recordId): string
    {
        return sprintf(self::KINDS[$kind]['path'] ?? throw new \InvalidArgumentException("no live page {$kind}"), $recordId);
    }

    /**
     * One poll of $kind's record by a person's session: their row written when needed (needsWrite), and the answer (class comment). 404
     * unknown_document when the record is not a document of the kind's type.
     *
     * @param array{tab: string, uid: ?int, field: ?string, state: string, typing: int, bye: bool} $in input()
     * @param bool $edits whether the person may change the draft (Documents::mayPost for the kind's type: the caller's roles of this request)
     * @return array<string, mixed>
     */
    public function beat(string $kind, int $recordId, int $staffUserId, string $sessionId, bool $edits, array $in): array
    {
        $type = self::KINDS[$kind]['type'] ?? throw new \InvalidArgumentException("no live page {$kind}");
        $s = self::settings($this->db);
        if ($s['mode'] === 'off') {
            return ['mode' => 'off'];
        }
        $doc = $this->db->one(self::DOCUMENT_SQL, [$recordId]);
        if ($doc === null || $doc['doc_type'] !== $type) {
            throw new CwException('unknown_document', 'there is no such document', 404);
        }
        $head = ['v' => (int) $doc['version'], 'status' => (string) $doc['status'], 'mode' => $s['mode']];
        if ($in['bye']) {
            $this->db->exec('DELETE FROM draft_presence WHERE kind = ? AND record_id = ? AND tab = ? AND session_id = ?', [$kind, $recordId, $in['tab'], $sessionId]);
            return $head + ['bye' => true];
        }
        if ($doc['status'] !== 'draft') {
            return $head + ['number' => $doc['number'] === null ? null : (string) $doc['number'], 'by' => $doc['by_name'] === null ? null : (string) $doc['by_name'],
                'to' => self::pagePath($kind, $recordId)];
        }
        $gone = self::goneAfter($s);
        $rows = $this->db->all(self::rowsSql($this->sessions->liveSql('s')), [$kind, $recordId, max($gone, self::AWAY_SECONDS), self::AWAY_SECONDS, $gone]);
        $now = null;
        $mine = null;
        foreach ($rows as $r) {
            $now ??= (string) $r['db_now'];
            if ($r['tab'] === $in['tab']) {
                $mine = $r;
            }
        }
        // My row when it is not among those read (none yet, too old, or past the newest MAX_ROWS) is written; a tab of another session is never
        // taken over (a tab id never leaves its page; still): write() guards each column by the session.
        if ($mine === null || ($mine['session_id'] === $sessionId && self::needsWrite($mine, $in, $edits, (string) $now))) {
            $this->write($kind, $recordId, $staffUserId, $sessionId, $edits, $in);
        }
        // Who is here: everyone but me, once a person (an active tab first, then the newest), shown by the rules of isHere().
        $people = [];
        foreach ($rows as $r) {
            $id = (int) $r['staff_user_id'];
            if ($id === $staffUserId || !self::isHere((string) $r['state'], (string) $r['seen_at'], (string) $r['db_now'], $gone)) {
                continue;
            }
            $best = $people[$id] ?? null;
            if ($best === null || ($r['state'] === 'active' && $best['state'] !== 'active')
                || ($r['state'] === $best['state'] && (string) $r['seen_at'] > (string) $best['seen_at'])) {
                $people[$id] = $r;
            }
        }
        ksort($people);
        $colours = self::colours([$staffUserId, ...array_keys($people)]);
        $here = [];
        foreach ($people as $id => $r) {
            $away = $r['state'] !== 'active';
            $here[] = ['id' => $id, 'name' => (string) $r['display_name'], 'c' => $colours[$id], 'state' => $away ? 'away' : 'active',
                'edits' => (int) $r['edits'] === 1, 'uid' => $away || $r['focus_uid'] === null ? null : (int) $r['focus_uid'],
                'field' => $away || $r['focus_field'] === null ? null : (string) $r['focus_field'], 'typing' => !$away && (int) $r['typing'] === 1];
        }
        return $head + ['poll_ms' => self::pollMs($s, $here !== []), 'here' => $here];
    }

    /**
     * Removes every row of a session (signing out; Ui\Controller\AuthController::logout, after the session ended). Returns how many went.
     */
    public static function forget(Db $db, string $sessionId): int
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $sessionId) !== 1) {
            return 0;
        }
        return $db->exec('DELETE FROM draft_presence WHERE session_id = ?', [$sessionId]);
    }

    /**
     * The poll's own row, written as it is now (seen_at = the database's clock). A new row, or the same session's row in place; a row of
     * the same tab held by another session is left as it was (the guard of each assignment).
     *
     * @param array{tab: string, uid: ?int, field: ?string, state: string, typing: int, bye: bool} $in
     */
    private function write(string $kind, int $recordId, int $staffUserId, string $sessionId, bool $edits, array $in): void
    {
        $set = [];
        foreach (['focus_uid', 'focus_field', 'state', 'typing', 'edits', 'seen_at'] as $c) {
            $set[] = "{$c} = IF(draft_presence.session_id = new.session_id, new.{$c}, draft_presence.{$c})";
        }
        $this->db->exec(
            'INSERT INTO draft_presence (kind, record_id, tab, staff_user_id, session_id, focus_uid, focus_field, state, typing, edits, seen_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(6)) AS new ON DUPLICATE KEY UPDATE ' . implode(', ', $set),
            [$kind, $recordId, $in['tab'], $staffUserId, $sessionId, $in['uid'], $in['field'], $in['state'], $in['typing'], $edits ? 1 : 0],
        );
    }

    /** Microseconds from $then to $now (DATETIME(6) texts of the database's UTC clock); negative when $then is later. */
    private static function ageMicro(string $then, string $now): int
    {
        $parse = static function (string $t): int {
            $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', strlen($t) === 19 ? $t . '.000000' : $t, new \DateTimeZone('UTC'))
                ?: throw new \UnexpectedValueException('not a database time: ' . mb_substr($t, 0, 32));
            return (int) $d->format('U') * 1_000_000 + (int) $d->format('u');
        };
        return $parse($now) - $parse($then);
    }
}
