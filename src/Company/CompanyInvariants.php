<?php

declare(strict_types=1);

namespace CW\Company;

use CW\Auth\Permissions;
use CW\Db;

/**
 * The nightly checks of the company details (0013; called at the end of CW\Invariants::check, so bin/invariants.php, the
 * hammer and every stock test run them; docs/decisions.md I91). company_profile is append-only for the app login, which
 * stops a version being rewritten but not a made-up one being ADDED: these checks find such a row. Read-only; at most
 * MAX_PER_CHECK violations per check.
 *
 *  C1. the versions are exactly 1..n, and only version 1 is a seed.
 *  C2. a confirm or unconfirm version has the same nine fields as the version before it; a confirm was saved by the person
 *      who confirmed it, and its baseline_version names an earlier confirmed version.
 *  C3. every version has exactly one audit row of its own, written by the actor that saved it: the seed company.change by
 *      system:migrate, a change company.change, a confirm company.confirm (entity_id = the version), an unconfirm the
 *      company.review whose unconfirmed_version it is.
 *  C4. who: a change was saved by a person who held a role with company.edit then, a confirm by one with company.confirm,
 *      an unconfirm by one with company.confirm (the reviewer who rejected), none of them admin then (staff_role history,
 *      with CLOCK_SLACK for the app's and the database's clocks); every check (review_task subject `company`) is on a confirm
 *      version, opened by its confirmer, and decided by nobody involved in it (CompanyDetails::involved()).
 */
final class CompanyInvariants
{
    private const MAX_PER_CHECK = 50;
    /** Seconds of tolerance between the app's clock (saved_at) and the database's (staff_role.granted_at). */
    private const CLOCK_SLACK = 300;
    private const FIELDS = ['legal_name', 'trading_name', 'company_number', 'vat_registered', 'vat_number', 'address', 'phone', 'email', 'delivery_address'];

    /** @return list<string> */
    public static function check(Db $db): array
    {
        $rows = [];
        foreach ($db->all('SELECT *, UNIX_TIMESTAMP(saved_at) AS saved_ts FROM company_profile ORDER BY version') as $r) {
            $rows[(int) $r['version']] = $r;
        }
        if ($rows === [] && $db->value("SELECT 1 FROM review_task WHERE subject_type = 'company' LIMIT 1") === null) {
            return [];
        }
        return array_slice([...self::sequence($rows), ...self::sameDetails($rows), ...self::audited($db, $rows), ...self::people($db, $rows)], 0, 4 * self::MAX_PER_CHECK);
    }

    /** @param array<int, array<string, mixed>> $rows @return list<string> C1 */
    private static function sequence(array $rows): array
    {
        $v = [];
        $n = count($rows);
        if ($n > 0 && (array_key_first($rows) !== 1 || array_key_last($rows) !== $n)) {
            $v[] = 'company details: versions ' . array_key_first($rows) . '..' . array_key_last($rows) . " in {$n} rows (expected 1..{$n}, no gap)";
        }
        foreach ($rows as $ver => $r) {
            if ($r['kind'] === 'seed' && $ver !== 1) {
                $v[] = "company details version {$ver} is a seed (only version 1 is)";
            }
        }
        return $v;
    }

    /** @param array<int, array<string, mixed>> $rows @return list<string> C2 */
    private static function sameDetails(array $rows): array
    {
        $v = [];
        foreach ($rows as $ver => $r) {
            if (count($v) >= self::MAX_PER_CHECK) {
                break;
            }
            if (in_array($r['kind'], ['confirm', 'unconfirm'], true)) {
                $prev = $rows[$ver - 1] ?? null;
                $diff = $prev === null ? ['the version before it'] : array_values(array_filter(self::FIELDS,
                    static fn (string $k): bool => ($r[$k] === null ? null : (string) $r[$k]) !== ($prev[$k] === null ? null : (string) $prev[$k])));
                if ($diff !== []) {
                    $v[] = "company details version {$ver} ({$r['kind']}) differs from version " . ($ver - 1) . ' in ' . implode(', ', $diff)
                        . ': a confirmation changes nothing';
                }
            }
            if ($r['kind'] === 'confirm' && !((string) $r['confirmed_by'] === (string) $r['saved_by'] && (string) $r['confirmed_actor'] === (string) $r['saved_actor'])) {
                $v[] = "company details version {$ver} was confirmed by " . ($r['confirmed_actor'] ?? 'nobody') . " but saved by {$r['saved_actor']}";
            }
            if ($r['baseline_version'] !== null) {
                $b = $rows[(int) $r['baseline_version']] ?? null;
                if ($b === null || (int) $b['confirmed'] !== 1) {
                    $v[] = "company details version {$ver} was compared with version {$r['baseline_version']}, which " . ($b === null ? 'does not exist' : 'is not confirmed');
                }
            }
        }
        return $v;
    }

    /** @param array<int, array<string, mixed>> $rows @return list<string> C3 */
    private static function audited(Db $db, array $rows): array
    {
        $own = [];
        foreach ($db->all("SELECT action, entity_id, actor, detail FROM audit_log WHERE entity_type = 'company_profile' AND action IN "
            . "('company.change', 'company.confirm', 'company.review') ORDER BY id") as $a) {
            $detail = json_decode((string) $a['detail'], true);
            $ver = match ((string) $a['action']) {
                'company.review' => is_array($detail) && is_int($detail['unconfirmed_version'] ?? null) ? $detail['unconfirmed_version'] : null,
                default => ctype_digit((string) $a['entity_id']) ? (int) $a['entity_id'] : null,
            };
            if ($ver !== null) {
                $own[$ver][] = ['action' => (string) $a['action'], 'actor' => (string) $a['actor']];
            }
        }
        $v = [];
        foreach ($rows as $ver => $r) {
            if (count($v) >= self::MAX_PER_CHECK) {
                break;
            }
            $want = match ((string) $r['kind']) {
                'seed', 'change' => 'company.change',
                'confirm' => 'company.confirm',
                default => 'company.review',
            };
            $actor = $r['kind'] === 'seed' ? 'system:migrate' : (string) $r['saved_actor'];
            $mine = array_values(array_filter($own[$ver] ?? [], static fn (array $a): bool => $a['action'] === $want));
            if (count($mine) !== 1 || $mine[0]['actor'] !== $actor) {
                $v[] = "company details version {$ver} ({$r['kind']}) has " . count($mine) . " {$want} audit rows" . (count($mine) === 1 ? " by {$mine[0]['actor']}, not {$actor}" : ' (expected 1)');
            }
        }
        return $v;
    }

    /** @param array<int, array<string, mixed>> $rows @return list<string> C4 */
    private static function people(Db $db, array $rows): array
    {
        $grants = [];
        foreach ($db->all('SELECT staff_user_id, CAST(role AS CHAR) AS role, UNIX_TIMESTAMP(granted_at) AS g, UNIX_TIMESTAMP(revoked_at) AS r FROM staff_role') as $g) {
            $grants[(int) $g['staff_user_id']][] = ['role' => (string) $g['role'], 'from' => (float) $g['g'], 'to' => $g['r'] === null ? null : (float) $g['r']];
        }
        // Whether $id held one of $roles at $t (generous), or admin at $t (strict: only a clear overlap counts).
        $held = static function (int $id, array $roles, float $t) use ($grants): bool {
            foreach ($grants[$id] ?? [] as $g) {
                if (in_array($g['role'], $roles, true) && $g['from'] <= $t + self::CLOCK_SLACK && ($g['to'] === null || $g['to'] >= $t - self::CLOCK_SLACK)) {
                    return true;
                }
            }
            return false;
        };
        $admin = static function (int $id, float $t) use ($grants): bool {
            foreach ($grants[$id] ?? [] as $g) {
                if ($g['role'] === 'admin' && $g['from'] <= $t - self::CLOCK_SLACK && ($g['to'] === null || $g['to'] > $t + self::CLOCK_SLACK)) {
                    return true;
                }
            }
            return false;
        };
        $edit = Permissions::MAP['company.edit'];
        $confirm = Permissions::MAP['company.confirm'];
        $v = [];
        foreach ($rows as $ver => $r) {
            if (count($v) >= self::MAX_PER_CHECK || $r['kind'] === 'seed') {
                continue;
            }
            $who = $r['saved_by'] === null ? null : (int) $r['saved_by'];
            $t = (float) $r['saved_ts'];
            $need = $r['kind'] === 'change' ? $edit : $confirm;
            if ($who === null || !$held($who, $need, $t) || $admin($who, $t)) {
                $v[] = "company details version {$ver} ({$r['kind']}) was saved by " . ($who === null ? 'nobody' : "staff {$who}") . ', who did not hold '
                    . ($r['kind'] === 'change' ? 'company.edit' : 'company.confirm') . " (and no admin) at {$r['saved_at']}";
            }
        }
        $svc = new CompanyDetails($db);
        $typed = [];
        foreach ($rows as $ver => $r) {
            $typed[$ver] = ['kind' => (string) $r['kind'], 'saved_by' => $r['saved_by'] === null ? null : (int) $r['saved_by'],
                'confirmed_by' => $r['confirmed_by'] === null ? null : (int) $r['confirmed_by'],
                'baseline_version' => $r['baseline_version'] === null ? null : (int) $r['baseline_version']];
        }
        foreach ($db->all("SELECT id, subject_id, state, opened_by, decided_by FROM review_task WHERE subject_type = 'company' ORDER BY id LIMIT 1000") as $t) {
            if (count($v) >= 2 * self::MAX_PER_CHECK) {
                break;
            }
            $row = $typed[(int) $t['subject_id']] ?? null;
            if ($row === null || $row['kind'] !== 'confirm' || $row['confirmed_by'] === null || (int) ($t['opened_by'] ?? 0) !== $row['confirmed_by']) {
                $v[] = "company check {$t['id']} is on version {$t['subject_id']}, which " . ($row === null ? 'does not exist' : ($row['kind'] !== 'confirm'
                    ? "is a {$row['kind']}" : 'its opener did not confirm'));
                continue;
            }
            if ($t['decided_by'] !== null && in_array((int) $t['decided_by'], $svc->involved((int) $t['subject_id'], $typed), true)) {
                $v[] = "company check {$t['id']} ({$t['state']}) was decided by staff {$t['decided_by']}, who was involved in the change";
            }
        }
        return $v;
    }
}
