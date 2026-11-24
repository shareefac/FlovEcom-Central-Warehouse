<?php

declare(strict_types=1);

namespace CW\Expenses;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Profit\ProfitSettings;

/**
 * The regular costs (the brief's point 3; docs/decisions.md EX5-EX7): costs with no invoice that go out every week, month or year (the
 * owner's weekly list from the business owner; Cloudflare yearly), each for ONE shop, counted per day (RegularCostMath) from its first day to
 * its last (none: no end). The amount is as given; a line "includes 20% VAT" (picked for you) or has "no VAT" (the owner, 24 Nov 2026;
 * docs/decisions.md EX38): the choice is kept on the line (vat_included) apart from its rate (vat_rate_bp, the shops' rate
 * profit.vat_rate_pct of the day it is set, which may be 0: the final review of 70ab3d2, astra MEDIUM; EX54), and only the amount less its VAT
 * (RegularCostMath::vat) is shared per day and taken off the profit. Only the three shops take a line (ExpenseViews::SHOP_CODES; EX44).
 *
 *   add        a new line
 *   newAmount  "the amount changes from <day>": the line ends the day before and a new line starts that day with the new amount and the
 *              rest of the old one (its name, shop, group, how often, note, last day), naming it (replaces_id): the old days keep the old
 *              amount; the same amount at the same VAT changes nothing (EX46). The amount, shop, how often and first day of a line never
 *              change in place (Grants).
 *   change     its name, group, VAT choice, note and last day (an end put on, moved or taken off)
 *   delete     a mistake: a mark with a reason; out of the profit on every day, kept with its history
 *
 * Only the Expenses job writes (ExpenseAccess, checked again inside each write); every write kept in expense_history and audit_log.
 */
final class RegularCosts
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * $in: name, shop (channel id), type (expense_type id of kind regular), vat (inc: the amount includes VAT at the shops' rate, also when
     * missing | none), every (week|month|year), amount (pounds as typed), from, until (YYYY-MM-DD; '' or null: no end), note. $same: the
     * confirmation the form carries (the ids of the lines it was asked about: detail.confirm of the 409; EX43). $rateBp: the rate a line
     * including VAT takes, given by a server tool that checked it once for all its lines (bin/seed_regular_costs.php; EX47); null: the
     * setting's of now.
     *
     * @param array<string, mixed> $in
     */
    public function add(Caller $caller, array $in, string $same = '', ?int $rateBp = null): int
    {
        return $this->db->transaction(function (Db $db) use ($caller, $in, $same, $rateBp): int {
            ExpenseAccess::mayEdit($db, $caller);
            // Every add of a regular cost one after the other (the groups' rows, in their order: few, and only written by Settings > Expense types,
            // which takes one of them alone), so the check below sees an add made a moment before (the Codex check r2, H2, H4).
            $db->all("SELECT id FROM expense_type WHERE kind = 'regular' ORDER BY id FOR UPDATE");
            $name = Expenses::name((string) ($in['name'] ?? ''));
            $shop = self::id($in['shop'] ?? null);
            if ($shop === null || !ExpenseViews::isBusinessShop($db, $shop)) {
                throw new CwException('choose_shop', 'choose the shop this cost is for', 422, ['field' => 'shop']);
            }
            $type = $this->group($db, $in['type'] ?? null, null);
            if ($rateBp !== null && ($caller->staffUserId !== null || $rateBp < 0 || $rateBp > 10000)) {
                throw new \LogicException('a rate is given only by a server tool, 0 to 100%');
            }
            [$included, $vat] = self::vatChoice($db, $in['vat'] ?? null, null, $rateBp);
            $every = (string) ($in['every'] ?? '');
            if (!in_array($every, RegularCostMath::EVERY, true)) {
                throw new CwException('bad_every', 'choose how often: every week, every month or every year', 422, ['field' => 'every']);
            }
            $amount = ExpenseMoney::pence((string) ($in['amount'] ?? ''));
            if ($amount === null || $amount < 1) {
                throw new CwException('bad_amount', 'the amount is not an amount in pounds and pence above 0', 422, ['field' => 'amount']);
            }
            $from = self::day((string) ($in['from'] ?? ''), 'bad_from', 'from');
            $until = self::until($in['until'] ?? null, $from);
            $note = self::note($in['note'] ?? null);
            // The same cost twice (a form sent twice, the seed and a person at once, a line typed in again): the same name (any case) in the same
            // shop on days that meet, not deleted: refused unless the person says it is another one, for exactly those (the Codex check r2, H2,
            // H4; EX28, EX43).
            self::checkSame($db, $shop, $name, $from, $until, null, $same, null);
            $now = Clock::db(Clock::now());
            $id = $db->insert('INSERT INTO regular_cost (name, channel_id, type_id, every, amount_pence, vat_included, vat_rate_bp, from_day, until_day, note, version, '
                . 'created_by, created_actor, created_at, updated_by, updated_actor, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)',
                [$name, $shop, $type, $every, $amount, $included, $vat, $from, $until, $note, $caller->staffUserId, $caller->actor, $now, $caller->staffUserId,
                    $caller->actor, $now]);
            ExpenseHistory::record($db, $caller, 'regular_cost', $id, 1, 'add', null, self::state($db, $id), null);
            return $id;
        });
    }

    /**
     * The amount changes from $from: this line ends on the day before (its days keep its amount) and a new line starts on $from, with the same
     * VAT choice (a line including VAT: at the shops' rate of today, EX38, EX54). Returns the new line's id; the same amount at the same VAT as
     * the line's changes nothing and returns the line's own id (never a split that moves a penny between its days: the final review of 70ab3d2,
     * astra MEDIUM; EX46). 422 bad_from when $from is not after the line's first day; 409 ended when the line has no day left from $from.
     */
    public function newAmount(Caller $caller, int $id, int $seen, string $amount, string $from): int
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $seen, $amount, $from): int {
            ExpenseAccess::mayEdit($db, $caller);
            $row = $this->locked($db, $id, $seen);
            $pence = ExpenseMoney::pence($amount);
            if ($pence === null || $pence < 1) {
                throw new CwException('bad_amount', 'the amount is not an amount in pounds and pence above 0', 422, ['field' => 'amount']);
            }
            $from = self::day($from, 'bad_from', 'from');
            if ($from <= (string) $row['from_day']) {
                throw new CwException('bad_from', 'the new amount starts after the first day of this line (to change it from its first day, delete the line and add it again)',
                    422, ['field' => 'from']);
            }
            if ($row['until_day'] !== null && $from > (string) $row['until_day']) {
                throw new CwException('ended', 'this line ended before that day: add a new line instead', 409, ['field' => 'from']);
            }
            $included = (int) $row['vat_included'];
            $vat = $included === 1 ? (new ProfitSettings($db))->vatBp() : 0;
            if ($pence === (int) $row['amount_pence'] && $vat === (int) $row['vat_rate_bp']) {
                return $id;
            }
            $before = self::state($db, $id);
            $version = (int) $row['version'] + 1;
            $now = Clock::db(Clock::now());
            $db->exec('UPDATE regular_cost SET until_day = ?, version = ?, updated_by = ?, updated_actor = ?, updated_at = ? WHERE id = ?',
                [RegularCostMath::addDays($from, -1), $version, $caller->staffUserId, $caller->actor, $now, $id]);
            ExpenseHistory::record($db, $caller, 'regular_cost', $id, $version, 'end', $before, self::state($db, $id), null);
            $new = $db->insert('INSERT INTO regular_cost (name, channel_id, type_id, every, amount_pence, vat_included, vat_rate_bp, from_day, until_day, note, replaces_id, '
                . 'version, created_by, created_actor, created_at, updated_by, updated_actor, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)',
                [$row['name'], $row['channel_id'], $row['type_id'], $row['every'], $pence, $included, $vat, $from, $row['until_day'], $row['note'], $id, $caller->staffUserId,
                    $caller->actor, $now, $caller->staffUserId, $caller->actor, $now]);
            ExpenseHistory::record($db, $caller, 'regular_cost', $new, 1, 'add', null, self::state($db, $new), null);
            return $new;
        });
    }

    /**
     * Its name, group, VAT choice, note and last day ($in: name, type, vat: inc | none, missing: as it is; note, until: '' = no end). A line
     * marked "no VAT" that is now marked as including it takes the shops' rate of today; one that includes VAT keeps its own rate (EX38, EX54).
     * A VAT choice changed here changes every day of the line (the page says so: a VAT change from a day is an end and a new line). $same: as
     * add(). False when nothing is different.
     *
     * @param array<string, mixed> $in
     */
    public function change(Caller $caller, int $id, int $seen, array $in, string $same = ''): bool
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $seen, $in, $same): bool {
            ExpenseAccess::mayEdit($db, $caller);
            $row = $this->locked($db, $id, $seen);
            $name = Expenses::name((string) ($in['name'] ?? ''));
            $type = $this->group($db, $in['type'] ?? null, (int) $row['type_id']);
            [$included, $vat] = self::vatChoice($db, $in['vat'] ?? null, [(int) $row['vat_included'], (int) $row['vat_rate_bp']], null);
            $until = self::until($in['until'] ?? null, (string) $row['from_day']);
            $note = self::note($in['note'] ?? null);
            // A line continued by a new amount ends before the first later line of its chain that is not deleted starts, a deleted line in
            // between too (the Codex check r1, H1): never two amounts on one day (EX7, EX19).
            $next = self::nextFrom($db, $id);
            if ($next !== null && ($until === null || $until >= (string) $next)) {
                throw new CwException('overlaps', 'a new amount follows this line from ' . (string) $next . ': its last day is before that day', 422, ['field' => 'until',
                    'next' => (string) $next]);
            }
            if ($name === (string) $row['name'] && $type === (int) $row['type_id'] && $included === (int) $row['vat_included'] && $vat === (int) $row['vat_rate_bp']
                && $until === $row['until_day'] && $note === $row['note']) {
                return false;
            }
            // A new name or end that makes it meet another line of the same name in its shop (one added on its own, not of its chain): the same
            // question as an add (EX28; the Codex check r3, H3), unless the person says it is another cost, for exactly those (EX43).
            if ($name !== (string) $row['name'] || $until !== $row['until_day']) {
                $db->all("SELECT id FROM expense_type WHERE kind = 'regular' ORDER BY id FOR UPDATE");
                self::checkSame($db, (int) $row['channel_id'], $name, (string) $row['from_day'], $until, $id, $same, 'until');
            }
            $before = self::state($db, $id);
            $version = (int) $row['version'] + 1;
            $db->exec('UPDATE regular_cost SET name = ?, type_id = ?, vat_included = ?, vat_rate_bp = ?, until_day = ?, note = ?, version = ?, updated_by = ?, '
                . 'updated_actor = ?, updated_at = ? WHERE id = ?', [$name, $type, $included, $vat, $until, $note, $version, $caller->staffUserId, $caller->actor,
                    Clock::db(Clock::now()), $id]);
            ExpenseHistory::record($db, $caller, 'regular_cost', $id, $version, 'change', $before, self::state($db, $id), null);
            return true;
        });
    }

    /** A line put in by mistake: deleted by a mark with a reason (3-500 characters); out of the profit on every day. */
    public function delete(Caller $caller, int $id, int $seen, string $reason): void
    {
        $this->db->transaction(function (Db $db) use ($caller, $id, $seen, $reason): void {
            ExpenseAccess::mayEdit($db, $caller);
            $row = $this->locked($db, $id, $seen);
            $why = ExpenseHistory::reason($reason);
            $before = self::state($db, $id);
            $version = (int) $row['version'] + 1;
            $now = Clock::db(Clock::now());
            $db->exec('UPDATE regular_cost SET deleted_at = ?, deleted_by = ?, deleted_actor = ?, deleted_reason = ?, version = ?, updated_by = ?, updated_actor = ?, '
                . 'updated_at = ? WHERE id = ?', [$now, $caller->staffUserId, $caller->actor, $why, $version, $caller->staffUserId, $caller->actor, $now, $id]);
            ExpenseHistory::record($db, $caller, 'regular_cost', $id, $version, 'delete', $before, self::state($db, $id), $why);
        });
    }

    /**
     * The first day of the earliest line of $id's whole chain of new amounts (its root and every line continuing it, any branch, through deleted
     * lines) that starts after $id starts and is not deleted, or null (the Codex check r2, H1: a second new amount on the same line makes a
     * branch).
     */
    public static function nextFrom(Db $db, int $id): ?string
    {
        $root = self::root($db, $id);
        $v = $db->value('WITH RECURSIVE d (id) AS (SELECT ? UNION ALL SELECT r.id FROM regular_cost r JOIN d ON r.replaces_id = d.id) '
            . 'SELECT MIN(r.from_day) FROM d JOIN regular_cost r ON r.id = d.id JOIN regular_cost me ON me.id = ? '
            . 'WHERE r.id <> me.id AND r.deleted_at IS NULL AND r.from_day > me.from_day', [$root, $id]);
        return $v === null ? null : (string) $v;
    }

    /** The first line of $id's chain of new amounts (the one continuing no other). */
    public static function root(Db $db, int $id): int
    {
        $v = $db->value('WITH RECURSIVE u (id, up, n) AS (SELECT id, replaces_id, 0 FROM regular_cost WHERE id = ? UNION ALL '
            . 'SELECT r.id, r.replaces_id, u.n + 1 FROM regular_cost r JOIN u ON r.id = u.up) SELECT id FROM u WHERE up IS NULL LIMIT 1', [$id]);
        return $v === null ? $id : (int) $v;
    }

    /** @return array<string, int|string|null> the tracked state of a line (its history keeps it), with its shop's and group's names */
    public static function state(Db $db, int $id): array
    {
        $r = $db->one('SELECT r.name, r.channel_id, c.name AS shop, r.type_id, t.name AS type, r.every, r.amount_pence, r.vat_included, r.vat_rate_bp, r.from_day, '
            . 'r.until_day, r.note, '
            . 'r.replaces_id, '
            . 'r.deleted_reason, r.deleted_at, r.deleted_actor, r.deleted_by FROM regular_cost r JOIN channel c ON c.id = r.channel_id JOIN expense_type t ON t.id = r.type_id WHERE r.id = ?', [$id])
            ?? throw new \LogicException("regular cost {$id} vanished");
        return ['name' => (string) $r['name'], 'channel_id' => (int) $r['channel_id'], 'shop' => (string) $r['shop'], 'type_id' => (int) $r['type_id'],
            'type' => (string) $r['type'], 'every' => (string) $r['every'], 'amount_pence' => (int) $r['amount_pence'], 'vat_included' => (int) $r['vat_included'],
            'vat_rate_bp' => (int) $r['vat_rate_bp'],
            'from_day' => (string) $r['from_day'],
            'until_day' => $r['until_day'] === null ? null : (string) $r['until_day'], 'note' => $r['note'] === null ? null : (string) $r['note'],
            'replaces_id' => $r['replaces_id'] === null ? null : (int) $r['replaces_id'], 'deleted_reason' => $r['deleted_reason'] === null ? null : (string) $r['deleted_reason'],
            // Who deleted it and when, compared every night (the Codex check r2, M11).
            'deleted_at' => $r['deleted_at'] === null ? null : (string) $r['deleted_at'], 'deleted_actor' => $r['deleted_actor'] === null ? null : (string) $r['deleted_actor'],
            'deleted_by' => $r['deleted_by'] === null ? null : (int) $r['deleted_by']];
    }

    /** @return array<string, mixed> the line, locked, at the version the page showed, not deleted */
    private function locked(Db $db, int $id, int $seen): array
    {
        // Every write to one chain of new amounts one after the other: its first line is locked first (the Codex check r2, H1).
        $root = self::root($db, $id);
        if ($root !== $id) {
            $db->value('SELECT id FROM regular_cost WHERE id = ? FOR UPDATE', [$root]);
        }
        $row = $db->one('SELECT * FROM regular_cost WHERE id = ? FOR UPDATE', [$id]) ?? throw new CwException('not_found', 'there is no such regular cost', 404);
        if ($row['deleted_at'] !== null) {
            throw new CwException('deleted', 'this regular cost was deleted', 409);
        }
        if ((int) $row['version'] !== $seen) {
            throw Expenses::meanwhile($db, 'regular_cost', $id, (int) $row['version'], $seen);
        }
        return $row;
    }

    /** A group (an expense_type of kind regular) switched on, or the line's own one. */
    private function group(Db $db, mixed $in, ?int $current): int
    {
        $type = self::id($in);
        $t = $type === null ? null : $db->one("SELECT id, is_active FROM expense_type WHERE id = ? AND kind = 'regular'", [$type]);
        if ($t === null) {
            throw new CwException('choose_type', 'choose the group of the cost', 422, ['field' => 'type']);
        }
        if ((int) $t['is_active'] !== 1 && $type !== $current) {
            throw new CwException('type_off', 'that group is switched off: choose another', 422, ['field' => 'type']);
        }
        return $type;
    }

    /**
     * The VAT choice and rate a line keeps for the choice $in: inc (also when missing on an add) = [1, the line's own rate when it includes VAT
     * already ($current), else $rateBp given by a server tool, else the shops' rate of today (profit.vat_rate_pct; 0 allowed)]; none = [0, 0];
     * missing on a change: as it is. The choice is kept apart from the rate, so a line including VAT at a rate of 0 stays one (EX54). 422
     * bad_vat_choice.
     *
     * @param array{0: int, 1: int}|null $current [vat_included, vat_rate_bp] of the line changed
     * @return array{0: int, 1: int}
     */
    private static function vatChoice(Db $db, mixed $in, ?array $current, ?int $rateBp): array
    {
        $choice = $in === null || $in === '' ? ($current === null ? 'inc' : null) : $in;
        if ($choice === null) {
            return $current ?? [0, 0];
        }
        return match ($choice) {
            'inc' => $current !== null && $current[0] === 1 ? $current : [1, $rateBp ?? (new ProfitSettings($db))->vatBp()],
            'none' => [0, 0],
            default => throw new CwException('bad_vat_choice', 'choose whether the amount includes VAT', 422, ['field' => 'vat']),
        };
    }

    /**
     * 409 same_cost when lines of this name (any case) in this shop, not deleted, other than $self, count on days meeting $from..$until, unless
     * $same confirms exactly those: a digest of the line and of the WHOLE set it meets, in order, worked out again here (Expenses::confirmation;
     * no cap of 20: the fix round 2 of 8dbee81, Codex C1; EX28, EX43, EX58). detail.matches: every one met (id, name, from, until), each linked
     * by the forms. Call under the groups' locks.
     */
    private static function checkSame(Db $db, int $shop, string $name, string $from, ?string $until, ?int $self, string $same, ?string $field): void
    {
        $rows = $db->all("SELECT id, name, from_day, until_day FROM regular_cost WHERE channel_id = ? AND name = ? AND deleted_at IS NULL AND id <> ? "
            . "AND from_day <= COALESCE(?, '9999-12-31') AND COALESCE(until_day, '9999-12-31') >= ? ORDER BY id", [$shop, $name, $self ?? 0, $until, $from]);
        if ($rows === []) {
            return;
        }
        $matches = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'from' => (string) $r['from_day'],
            'until' => $r['until_day'] === null ? null : (string) $r['until_day']], $rows);
        $confirm = Expenses::confirmation('regular_cost', [$shop, $name, $from, $until ?? '', $self ?? 0], array_column($matches, 'id'));
        if (hash_equals($confirm, $same)) {
            return;
        }
        throw new CwException('same_cost', 'a regular cost of this name in this shop counts on these days already', 409,
            ['other' => $matches[0]['id'], 'from' => $matches[0]['from'], 'matches' => $matches, 'confirm' => $confirm] + ($field === null ? [] : ['field' => $field]));
    }

    private static function id(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        return is_string($v) && preg_match('/^[1-9]\d{0,9}$/D', $v) === 1 ? (int) $v : null;
    }

    private static function day(string $s, string $code, string $field): string
    {
        $s = trim($s);
        if (!RegularCostMath::isDay($s) || $s < Expenses::FIRST_DAY || $s > '2099-12-31') {
            throw new CwException($code, 'that is not a day', 422, ['field' => $field]);
        }
        return $s;
    }

    private static function until(mixed $v, string $from): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return null;
        }
        $d = self::day($s, 'bad_until', 'until');
        if ($d < $from) {
            throw new CwException('bad_until', 'the last day is on or after the first day', 422, ['field' => 'until']);
        }
        return $d;
    }

    private static function note(mixed $v): ?string
    {
        $note = trim((string) ($v ?? ''));
        if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > Expenses::NOTE_MAX || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note) === 1) {
            throw new CwException('bad_note', 'the note is at most ' . Expenses::NOTE_MAX . ' characters', 422, ['field' => 'note']);
        }
        return $note === '' ? null : $note;
    }
}
