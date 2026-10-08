<?php

declare(strict_types=1);

namespace CW\Admin;

use CW\CwException;
use CW\Db;

/**
 * The audit log viewer (G07, docs/decisions.md Y32): audit_log searched by date (always a window, so a search reads an index range:
 * ix_audit_created), person (ix_audit_staff), record (ix_audit_entity), action (a prefix, within the window) and site, newest first,
 * a page at a time (keyset on the id), and the same search as a CSV file. Read only (the log is append-only for the app login).
 */
final class AuditSearch
{
    public const PAGE = 100;
    /** The most rows one CSV file holds (narrow the search for more). */
    public const CSV_MAX = 20000;
    /** The longest window one search reads. */
    public const MAX_DAYS = 366;
    public const DEFAULT_DAYS = 7;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The filters of a search from what a page sent (all optional): from, to (Y-m-d, UK days are near enough: the window is whole
     * UTC days, to inclusive), who ('staff:<id>', 'system', 'site'), record (an entity type), id (its id), action (a prefix of
     * letters, dots and _), before (an id: the next page). 400 bad_filter for a value that cannot be one.
     *
     * @param array<string, mixed> $q
     * @return array{from: string, to: string, who: ?string, record: ?string, id: ?string, action: ?string, before: ?int}
     */
    public static function filters(array $q, string $today): array
    {
        $date = static function (mixed $v, string $field): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            $v = trim((string) $v);
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v, new \DateTimeZone('UTC'));
            if ($d === false || $d->format('Y-m-d') !== $v) {
                throw new CwException('bad_filter', "{$field} must be a date (YYYY-MM-DD)", 400, ['field' => $field]);
            }
            return $v;
        };
        $to = $date($q['to'] ?? null, 'to') ?? $today;
        $from = $date($q['from'] ?? null, 'from')
            ?? (new \DateTimeImmutable($to, new \DateTimeZone('UTC')))->modify('-' . (self::DEFAULT_DAYS - 1) . ' days')->format('Y-m-d');
        if ($from > $to) {
            throw new CwException('bad_filter', 'the first day is after the last day', 400, ['field' => 'from']);
        }
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%a') + 1;
        if ($days > self::MAX_DAYS) {
            throw new CwException('bad_filter', 'search at most ' . self::MAX_DAYS . ' days at a time', 400, ['field' => 'from']);
        }
        $who = trim((string) ($q['who'] ?? ''));
        if ($who !== '' && preg_match('/^(staff:[1-9][0-9]{0,9}|system|site)$/D', $who) !== 1) {
            throw new CwException('bad_filter', 'pick who from the list', 400, ['field' => 'who']);
        }
        $record = trim((string) ($q['record'] ?? ''));
        if ($record !== '' && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $record) !== 1) {
            throw new CwException('bad_filter', 'pick the kind of record from the list', 400, ['field' => 'record']);
        }
        $id = trim((string) ($q['id'] ?? ''));
        if ($id !== '' && (mb_strlen($id) > 64 || preg_match('/[\x00-\x1F\x7F]/', $id) === 1)) {
            throw new CwException('bad_filter', 'a record number is at most 64 characters', 400, ['field' => 'id']);
        }
        if ($id !== '' && $record === '') {
            throw new CwException('bad_filter', 'pick the kind of record for the number', 400, ['field' => 'record']);
        }
        $action = trim((string) ($q['action'] ?? ''));
        if ($action !== '' && preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $action) !== 1) {
            throw new CwException('bad_filter', 'what was done is a word like setting or staff.roles', 400, ['field' => 'action']);
        }
        $before = trim((string) ($q['before'] ?? ''));
        if ($before !== '' && preg_match('/^[1-9][0-9]{0,18}$/D', $before) !== 1) {
            throw new CwException('bad_filter', 'that page does not exist', 400, ['field' => 'before']);
        }
        return ['from' => $from, 'to' => $to, 'who' => $who === '' ? null : $who, 'record' => $record === '' ? null : $record,
            'id' => $id === '' ? null : $id, 'action' => $action === '' ? null : $action, 'before' => $before === '' ? null : (int) $before];
    }

    /**
     * One page of entries, newest first, and the id the next page starts before (null: none older).
     *
     * @param array{from: string, to: string, who: ?string, record: ?string, id: ?string, action: ?string, before: ?int} $f
     * @return array{rows: list<array<string, mixed>>, next: ?int}
     */
    public function page(array $f, int $limit = self::PAGE): array
    {
        $rows = $this->rows($f, $limit + 1);
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $next = (int) $rows[count($rows) - 1]['id'];
        }
        return ['rows' => $rows, 'next' => $next];
    }

    /**
     * Every entry of a search for the CSV file (at most CSV_MAX; `more` says whether there were more).
     *
     * @param array{from: string, to: string, who: ?string, record: ?string, id: ?string, action: ?string, before: ?int} $f
     * @return array{rows: list<array<string, mixed>>, more: bool}
     */
    public function export(array $f): array
    {
        $rows = $this->rows($f, self::CSV_MAX + 1);
        $more = count($rows) > self::CSV_MAX;
        return ['rows' => $more ? array_slice($rows, 0, self::CSV_MAX) : $rows, 'more' => $more];
    }

    /**
     * The people who appear as actors, for the "who" list (everyone with an account: a short table).
     *
     * @return list<array{id: int, name: string}>
     */
    public function people(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['display_name']],
            $this->db->all('SELECT id, display_name FROM staff_user ORDER BY display_name, id'));
    }

    /**
     * @param array{from: string, to: string, who: ?string, record: ?string, id: ?string, action: ?string, before: ?int} $f
     * @return list<array<string, mixed>>
     */
    private function rows(array $f, int $limit): array
    {
        $where = ['a.created_at >= ?', 'a.created_at < ? + INTERVAL 1 DAY'];
        $args = [$f['from'] . ' 00:00:00', $f['to'] . ' 00:00:00'];
        if ($f['who'] !== null) {
            if (str_starts_with($f['who'], 'staff:')) {
                $where[] = 'a.staff_user_id = ?';
                $args[] = (int) substr($f['who'], 6);
            } elseif ($f['who'] === 'site') {
                $where[] = 'a.channel_id IS NOT NULL';
            } else {
                $where[] = "a.actor LIKE 'system:%'";
            }
        }
        if ($f['record'] !== null) {
            $where[] = 'a.entity_type = ?';
            $args[] = $f['record'];
            if ($f['id'] !== null) {
                $where[] = 'a.entity_id = ?';
                $args[] = $f['id'];
            }
        }
        if ($f['action'] !== null) {
            $where[] = '(a.action = ? OR a.action LIKE ?)';
            $args[] = $f['action'];
            $args[] = str_replace(['%', '_'], ['\\%', '\\_'], rtrim($f['action'], '.')) . '.%';
        }
        if ($f['before'] !== null) {
            $where[] = 'a.id < ?';
            $args[] = $f['before'];
        }
        $out = [];
        foreach ($this->db->all(
            'SELECT a.id, a.actor, a.staff_user_id, u.display_name, a.channel_id, c.name AS site, a.action, a.entity_type, a.entity_id, a.ip, '
            . 'CAST(a.detail AS CHAR) AS detail, a.created_at FROM audit_log a LEFT JOIN staff_user u ON u.id = a.staff_user_id '
            . 'LEFT JOIN channel c ON c.id = a.channel_id WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC LIMIT ' . max(1, $limit),
            $args,
        ) as $r) {
            $out[] = ['id' => (int) $r['id'], 'actor' => (string) $r['actor'], 'staff_user_id' => $r['staff_user_id'] === null ? null : (int) $r['staff_user_id'],
                'person' => $r['display_name'] === null ? null : (string) $r['display_name'], 'site' => $r['site'] === null ? null : (string) $r['site'],
                'action' => (string) $r['action'], 'entity_type' => $r['entity_type'] === null ? null : (string) $r['entity_type'],
                'entity_id' => $r['entity_id'] === null ? null : (string) $r['entity_id'], 'ip' => $r['ip'] === null ? null : (string) $r['ip'],
                'detail' => $r['detail'] === null ? null : (string) $r['detail'], 'created_at' => (string) $r['created_at']];
        }
        return $out;
    }
}
