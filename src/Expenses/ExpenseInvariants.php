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
 *  EX-I2. its latest version holds what the row holds now: an expense's shop, supplier, type, invoice date and number, money, note, file and
 *         delete's reason; a regular cost's name, shop, group, how often, amount, VAT choice and rate, first and last day, note, the line it continues and delete's
 *         reason; a supplier's name and whether it is in use; a type's kind, name, whether it is in use and its place (the Codex check r1, M14).
 *  EX-I3. a regular cost that continues another (replaces_id) is of the same shop and how often and starts after that one's first day; no two
 *         lines of one chain of new amounts (through deleted lines too), neither deleted, count on one day.
 *  EX-I4. the file an expense names is in expense_file for that expense (the file rules ask expense_file: EX40).
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
        $differs = static fn (array $cols): string => implode(' OR ', array_map(static fn (string $c): string => "NOT ({$j($c)} <=> {$col($c)})", $cols));
        // Every field the screens show and the profit reads, a delete's reason exactly (the Codex check r1, M14), and the suppliers' and types'
        // rows too.
        $checked = ['expense' => ['expense', ['channel_id', 'supplier_id', 'type_id', 'invoice_date', 'invoice_no', 'net_pence', 'vat_pence', 'total_pence', 'note',
                'file_id', 'deleted_reason', 'deleted_at', 'deleted_actor', 'deleted_by']],
            'regular_cost' => ['regular_cost', ['name', 'channel_id', 'type_id', 'every', 'amount_pence', 'vat_included', 'vat_rate_bp', 'from_day', 'until_day', 'note', 'replaces_id',
                'deleted_reason', 'deleted_at', 'deleted_actor', 'deleted_by']],
            'expense_supplier' => ['supplier', ['name', 'is_active']],
            'expense_type' => ['type', ['kind', 'name', 'is_active', 'sort_order']]];
        foreach ($checked as $table => [$subject, $cols]) {
            $deleted = in_array('deleted_reason', $cols, true) ? ' OR (t.deleted_at IS NULL) <> (t.deleted_reason IS NULL)' : '';
            foreach ($db->all("SELECT t.id FROM {$table} t JOIN expense_history h ON h.subject = ? AND h.subject_id = t.id AND h.version = t.version "
                . "WHERE {$differs($cols)}{$deleted} ORDER BY t.id LIMIT " . self::MAX_PER_CHECK, [$subject]) as $r) {
                $v[] = "EX-I2 {$table} {$r['id']}: the row is not what its latest history version says (changed around CW)";
            }
        }
        foreach ($db->all('SELECT n.id, n.replaces_id FROM regular_cost n JOIN regular_cost o ON o.id = n.replaces_id '
            . 'WHERE o.channel_id <> n.channel_id OR o.every <> n.every OR n.from_day <= o.from_day ORDER BY n.id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "EX-I3 regular cost {$r['id']}: it continues line {$r['replaces_id']} but not of the same shop and how often, or starts no later";
        }
        // Never two amounts of one cost on one day: any two lines of one chain of new amounts (through deleted lines too: the Codex check r1, H1),
        // neither deleted, whose days meet.
        foreach ($db->all('WITH RECURSIVE c (id, root) AS (SELECT id, id FROM regular_cost WHERE replaces_id IS NULL UNION ALL '
            . 'SELECT r.id, c.root FROM regular_cost r JOIN c ON r.replaces_id = c.id) '
            . 'SELECT a.id AS a, b.id AS b FROM c ca JOIN c cb ON cb.root = ca.root AND cb.id > ca.id JOIN regular_cost a ON a.id = ca.id '
            . 'JOIN regular_cost b ON b.id = cb.id WHERE a.deleted_at IS NULL AND b.deleted_at IS NULL '
            . "AND a.from_day <= COALESCE(b.until_day, '9999-12-31') AND b.from_day <= COALESCE(a.until_day, '9999-12-31') ORDER BY b.id, a.id LIMIT "
            . self::MAX_PER_CHECK) as $r) {
            $v[] = "EX-I3 regular cost {$r['b']}: line {$r['a']} of the same cost counts on some of its days too";
        }
        foreach ($db->all('SELECT e.id FROM expense e WHERE e.file_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM expense_file f WHERE f.expense_id = e.id '
            . 'AND f.file_id = e.file_id) ORDER BY e.id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "EX-I4 expense {$r['id']}: its file is not in expense_file (the file rules would not know it)";
        }
        return $v;
    }
}
