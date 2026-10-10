<?php

declare(strict_types=1);

namespace CW\Admin;

use CW\Db;

/**
 * The nightly checks of the configuration the screens change (0019, docs/decisions.md Y2): called by Invariants::nightly(), which
 * bin/invariants.php runs. Not part of Invariants::check(): tests set a setting or a rule with the admin login for one test and put
 * it back afterwards, which these checks would rightly call a change without a history. Read-only; at most MAX_PER_CHECK violations
 * per check.
 *
 *  K1. the versions of each subject are exactly 1..n, version 1 a baseline or an add.
 *  K2. every row that has a history equals its latest version (the tracked fields, ConfigHistory::TRACKED), and every subject with
 *      a history still exists (nothing is ever deleted, Q9).
 *  K3. who: a version made by a person (`staff:<id>`) names that person in staff_user_id; one made by a job names nobody.
 *  K4. every live configuration row (setting, reason, kind of record, warehouse, place) has a history (review finding M8): the app
 *      login may INSERT warehouses, places and reasons, so a row added around the services (with no `add` version) is found; a
 *      migration that adds one writes its baseline (docs/dev.md rule 5).
 */
final class ConfigInvariants
{
    private const MAX_PER_CHECK = 50;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        return [...self::sequence($db), ...self::current($db), ...self::who($db), ...self::covered($db)];
    }

    /** @return list<string> K1 */
    private static function sequence(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            "SELECT subject_type, subject_key, MIN(version) AS lo, MAX(version) AS hi, COUNT(*) AS n, "
            . "SUM(version = 1 AND action IN ('baseline', 'add')) AS firsts FROM config_change GROUP BY subject_type, subject_key "
            . 'HAVING lo <> 1 OR hi <> n OR firsts <> 1 ORDER BY subject_type, subject_key LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "config {$r['subject_type']} {$r['subject_key']}: versions {$r['lo']}..{$r['hi']} in {$r['n']} rows (expected 1..{$r['n']}, starting with a baseline or an add)";
        }
        return $v;
    }

    /** @return list<string> K2 */
    private static function current(Db $db): array
    {
        $v = [];
        $latest = [];
        foreach ($db->all(
            'SELECT c.subject_type, c.subject_key, c.version, CAST(c.state AS CHAR) AS state FROM config_change c '
            . 'JOIN (SELECT subject_type, subject_key, MAX(version) AS v FROM config_change GROUP BY subject_type, subject_key) m '
            . '  ON m.subject_type = c.subject_type AND m.subject_key = c.subject_key AND m.v = c.version',
        ) as $r) {
            $state = json_decode((string) $r['state'], true);
            $latest[(string) $r['subject_type']][(string) $r['subject_key']] = ['version' => (int) $r['version'], 'state' => is_array($state) ? $state : []];
        }
        foreach ($latest as $type => $subjects) {
            $live = self::liveRows($db, $type);
            foreach ($subjects as $key => $h) {
                if (count($v) >= self::MAX_PER_CHECK) {
                    return $v;
                }
                $key = (string) $key;
                if (!isset($live[$key])) {
                    $v[] = "config {$type} {$key}: it has a history (version {$h['version']}) but the row is gone (nothing is ever deleted)";
                    continue;
                }
                $want = ConfigHistory::normalise($type, $h['state']);
                if ($live[$key] !== $want) {
                    $diff = [];
                    foreach ($want as $field => $value) {
                        if ($live[$key][$field] !== $value) {
                            $diff[] = $field . ' ' . json_encode($value) . ' -> ' . json_encode($live[$key][$field]);
                        }
                    }
                    $v[] = "config {$type} {$key}: changed outside its history since version {$h['version']} (" . implode(', ', $diff) . ')';
                }
            }
        }
        return $v;
    }

    /** @return list<string> K4 */
    private static function covered(Db $db): array
    {
        $v = [];
        $known = [];
        foreach ($db->all('SELECT DISTINCT subject_type, subject_key FROM config_change') as $r) {
            $known[(string) $r['subject_type']][(string) $r['subject_key']] = true;
        }
        foreach (ConfigHistory::TYPES as $type) {
            foreach (array_keys(self::liveRows($db, $type)) as $key) {
                if (!isset($known[$type][(string) $key])) {
                    $v[] = "config {$type} {$key}: the row has no history (added outside the screens and the migrations' baselines)";
                    if (count($v) >= self::MAX_PER_CHECK) {
                        return $v;
                    }
                }
            }
        }
        return $v;
    }

    /** @return list<string> K3 */
    private static function who(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            "SELECT id, subject_type, subject_key, version, actor, staff_user_id FROM config_change "
            . "WHERE NOT ((actor LIKE 'staff:%' AND staff_user_id IS NOT NULL AND actor = CONCAT('staff:', staff_user_id)) "
            . "  OR (actor NOT LIKE 'staff:%' AND staff_user_id IS NULL)) ORDER BY id LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "config {$r['subject_type']} {$r['subject_key']} version {$r['version']}: actor {$r['actor']} does not match staff "
                . ($r['staff_user_id'] ?? 'NULL');
        }
        return $v;
    }

    /** @return array<string, array<string, int|string|null>> every live row of a type, normalised, by its key */
    private static function liveRows(Db $db, string $type): array
    {
        $sql = match ($type) {
            'setting' => 'SELECT setting_key AS k, CAST(value_json AS CHAR) AS value, provisional FROM app_setting',
            'reason' => 'SELECT code AS k, label, CAST(applies_to AS CHAR) AS applies_to, CAST(direction AS CHAR) AS direction, needs_note, is_gift, '
                . 'system_only, is_active, sort_order, needs_given_to, below_zero FROM reason_code',
            'document_rule' => 'SELECT code AS k, CAST(review_rule AS CHAR) AS review_rule, review_limit_units, review_due_days, '
                . 'CAST(approval_rule AS CHAR) AS approval_rule, approval_limit_units, CAST(reject_action AS CHAR) AS reject_action, '
                . 'size_approval, size_units, size_value FROM document_type',
            'warehouse' => 'SELECT code AS k, code, name, is_sellable, is_active, CAST(stock_owner AS CHAR) AS stock_owner, owner_entity, is_system, note FROM warehouse',
            'location' => "SELECT CONCAT(w.code, '/', l.code) AS k, w.code AS warehouse, l.code, l.name, l.is_active, l.note FROM warehouse_location l "
                . 'JOIN warehouse w ON w.id = l.warehouse_id',
            default => null,
        };
        if ($sql === null) {
            return [];
        }
        $out = [];
        foreach ($db->all($sql) as $r) {
            $out[(string) $r['k']] = ConfigHistory::normalise($type, $r);
        }
        return $out;
    }
}
