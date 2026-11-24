<?php

declare(strict_types=1);

namespace CW\Expenses;

use CW\Db;

/**
 * The nightly checks of the expenses and regular costs (0079; docs/decisions.md EX18; called by CW\Invariants::check, so bin/invariants.php and
 * every stock test run them). Read-only; at most MAX_PER_CHECK findings per check. The app login may UPDATE these rows (in place, with their
 * history), so a change made around the services is found here, as the configuration's K1-K3 find one of the settings.
 *
 *  EX-I1. every expense, regular cost, supplier and type has its history: versions 1 to its own `version`, none missing, none after it.
 *  EX-I2. its latest version holds what the row holds now (an expense's shop, supplier, type, invoice date and number, money, file and whether
 *         it is deleted; a regular cost's shop, group, how often, amount, first and last day and whether it is deleted).
 *  EX-I3. a regular cost that continues another (replaces_id) is of the same shop and how often, starts after that one's first day, and (both
 *         not deleted) after that one's last day: never two amounts of one cost on one day.
 * Their cost: one pass over each table (a business's expenses: some hundreds a month).
 */
final class ExpenseInvariants
{
    private const MAX_PER_CHECK = 50;
    /** subject => [table, its history subject] */
    private const TABLES = ['expense' => 'expense', 'regular_cost' => 'regular_cost', 'expense_supplier' => 'supplier', 'expense_type' => 'type'];

    /** @return list<string> */
    public static function check(Db $db): array
    {
        $v = [];
        foreach (self::TABLES as $table => $subject) {
            foreach ($db->all("SELECT t.id, t.version, COUNT(h.id) AS n, COALESCE(MAX(h.version), 0) AS top FROM {$table} t LEFT JOIN expense_history h "
                . "ON h.subject = ? AND h.subject_id = t.id GROUP BY t.id, t.version HAVING n <> t.version OR top <> t.version ORDER BY t.id LIMIT "
                . self::MAX_PER_CHECK, [$subject]) as $r) {
                $v[] = "EX-I1 {$table} {$r['id']}: version {$r['version']}, its history has {$r['n']} versions up to {$r['top']}";
            }
        }
        // Compared as text, byte for byte (one collation both sides); a JSON null as SQL NULL.
        $j = static fn (string $c): string => "IF(JSON_TYPE(JSON_EXTRACT(h.state, '$.{$c}')) = 'NULL', NULL, "
            . "CAST(JSON_UNQUOTE(JSON_EXTRACT(h.state, '$.{$c}')) AS CHAR) COLLATE utf8mb4_bin)";
        $col = static fn (string $c): string => "CAST(t.{$c} AS CHAR) COLLATE utf8mb4_bin";
        $differs = static fn (array $cols): string => implode(' OR ', array_map(static fn (string $c): string => "NOT ({$j($c)} <=> {$col($c)})", $cols))
            . " OR (JSON_TYPE(JSON_EXTRACT(h.state, '$.deleted_reason')) = 'NULL') <> (t.deleted_at IS NULL)";
        $checked = ['expense' => ['channel_id', 'supplier_id', 'type_id', 'invoice_date', 'invoice_no', 'net_pence', 'vat_pence', 'total_pence', 'file_id'],
            'regular_cost' => ['channel_id', 'type_id', 'every', 'amount_pence', 'from_day', 'until_day']];
        foreach ($checked as $table => $cols) {
            foreach ($db->all("SELECT t.id FROM {$table} t JOIN expense_history h ON h.subject = ? AND h.subject_id = t.id AND h.version = t.version "
                . "WHERE {$differs($cols)} ORDER BY t.id LIMIT " . self::MAX_PER_CHECK, [$table]) as $r) {
                $v[] = "EX-I2 {$table} {$r['id']}: the row is not what its latest history version says (changed around CW)";
            }
        }
        foreach ($db->all('SELECT n.id, n.replaces_id FROM regular_cost n JOIN regular_cost o ON o.id = n.replaces_id '
            . 'WHERE o.channel_id <> n.channel_id OR o.every <> n.every OR n.from_day <= o.from_day OR (o.deleted_at IS NULL AND n.deleted_at IS NULL '
            . 'AND (o.until_day IS NULL OR o.until_day >= n.from_day)) ORDER BY n.id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "EX-I3 regular cost {$r['id']}: it continues line {$r['replaces_id']} but not of the same shop and how often, starts no later, or both count on one day";
        }
        return $v;
    }
}
