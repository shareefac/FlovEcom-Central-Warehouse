<?php

declare(strict_types=1);

namespace CW\Schema;

use CW\Db;
use CW\DbSettings;

/**
 * Table- and column-level grants for the application login (cw_app), converged idempotently:
 *  - every table: SELECT, INSERT, UPDATE, DELETE
 *  - tables whose rows are never removed (NO_DELETE: listings, items, listing profiles, staff):
 *    SELECT, INSERT, UPDATE (design A.1 I1 "the app DB user has no DELETE" on the link table)
 *  - append-only tables (APPEND_ONLY): SELECT, INSERT only
 *  - append-only tables with a few mutable state columns (UPDATE_COLUMNS): SELECT, INSERT, plus
 *    UPDATE on exactly those columns (e.g. match_decision may only change state, applied_at and
 *    second_by; decisions are never rewritten or deleted)
 *  - schema_migrations and the seeded reference lists (READ_ONLY): SELECT only (migrations run as the admin login)
 *  - no database-level (db.*) grant, so a table added later has no rights until apply() runs.
 * Grants are compared with mysql.tables_priv / mysql.columns_priv and only the difference is
 * granted/revoked, so there is never a window in which the app loses its rights.
 */
final class Grants
{
    /**
     * stock_value_seq / stock_value_ledger: the value sequence and the value journal are history (C0, I3, I5).
     * stored_file / document_file: a stored file and its attachment to a document are added, never changed or removed
     * (the file store keeps every document at least 7 years, I23).
     * document_posting: the write-once record of each posting (posted_hash and the content it covers), which the app login
     * can add but never rewrite, so a posted document changed afterwards is found even when its own columns were (I33).
     */
    public const APPEND_ONLY = ['stock_ledger', 'audit_log', 'match_run', 'match_reject', 'stock_value_seq', 'stock_value_ledger',
        'stored_file', 'document_file', 'document_posting'];
    /**
     * Append-only tables whose listed columns are the only ones the app may UPDATE (column-level
     * grant): a proposal's status, a decision's settlement, the end of a link period, an item's value
     * clock (Stock::assignValueSeq's INSERT ... ON DUPLICATE KEY UPDATE needs INSERT + UPDATE of
     * last_seq; the item id is never rewritten and a clock row is never deleted, I3), a role grant's revocation
     * (who held which role when stays readable: a grant is revoked, never rewritten or deleted, I10), a number series'
     * last number (I20), a review task's decision (I19), and a document's state columns: its identity (id, type,
     * creator, creation time, the document it reverses) is frozen and a document is never deleted (I17).
     */
    public const UPDATE_COLUMNS = [
        'match_proposal' => ['status'],
        'match_decision' => ['applied_at', 'second_by', 'state'],
        'listing_map_history' => ['closed_by_decision_id', 'valid_to'],
        'stock_value_clock' => ['last_seq'],
        'staff_role' => ['revoked_at', 'revoked_by'],
        'number_series' => ['last_no'],
        'review_task' => ['state', 'decided_by', 'decided_at', 'decision_note'],
        'document' => ['number', 'status', 'version', 'external_ref', 'doc_date', 'warehouse_id', 'reason_code', 'note', 'updated_at',
            'submitted_by', 'submitted_at', 'posted_by', 'posted_actor', 'posted_at', 'posted_hash', 'cancelled_by', 'cancelled_at',
            'cancel_reason', 'review_state'],
    ];
    /** reason_code / document_type: seeded reference lists, changed only by a migration (I22, I19). */
    public const READ_ONLY = ['schema_migrations', 'reason_code', 'document_type'];
    /**
     * Rows the app never deletes: a listing (reservation_unit.listing_id has no FK, so deleting a listing
     * that only ever sold while unlinked would orphan its holding-ledger units), an item (merged, never
     * deleted: M10), a listing's profile, a staff account (deactivated, never deleted: decisions name it).
     */
    public const NO_DELETE = ['channel_listing', 'listing_profile', 'sku', 'staff_user'];
    public const FULL = ['Select', 'Insert', 'Update', 'Delete'];

    /** @return list<string> privileges (mysql.tables_priv spelling) the app login should hold on $table */
    public static function desired(string $table): array
    {
        if (in_array($table, self::READ_ONLY, true)) {
            return ['Select'];
        }
        if (in_array($table, self::APPEND_ONLY, true) || isset(self::UPDATE_COLUMNS[$table])) {
            return ['Select', 'Insert'];
        }
        if (in_array($table, self::NO_DELETE, true)) {
            return ['Select', 'Insert', 'Update'];
        }
        return self::FULL;
    }

    /** @return array<string, list<string>> column => column privileges (mysql.columns_priv spelling) the app login should hold */
    public static function desiredColumns(string $table): array
    {
        $out = [];
        foreach (self::UPDATE_COLUMNS[$table] ?? [] as $column) {
            $out[$column] = ['Update'];
        }
        return $out;
    }

    /**
     * @return list<string> human-readable list of the changes made (empty when already converged)
     */
    public static function apply(Db $admin, string $database, string $user, string $host = '%'): array
    {
        $account = self::account($user, $host);
        $schema = Db::ident($database);
        $changes = [];

        $dbLevel = $admin->value('SELECT 1 FROM mysql.db WHERE User = ? AND Host = ? AND Db = ?', [$user, $host, $database]);
        if ($dbLevel !== null) {
            $admin->pdo()->exec("REVOKE ALL PRIVILEGES ON {$schema}.* FROM {$account}");
            $changes[] = "revoked database-level grant on {$database}.*";
        }

        $tables = $admin->column(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
            [$database],
        );
        $current = [];
        foreach ($admin->all('SELECT Table_name, Table_priv FROM mysql.tables_priv WHERE User = ? AND Host = ? AND Db = ?', [$user, $host, $database]) as $r) {
            $privs = (string) $r['Table_priv'];
            $current[(string) $r['Table_name']] = $privs === '' ? [] : explode(',', $privs);
        }

        foreach ($tables as $table) {
            $table = (string) $table;
            $want = self::desired($table);
            $have = $current[$table] ?? [];
            $add = array_values(array_diff($want, $have));
            $remove = array_values(array_diff($have, $want));
            $target = $schema . '.' . Db::ident($table);
            if ($add !== []) {
                $admin->pdo()->exec('GRANT ' . self::privList($add) . " ON {$target} TO {$account}");
                $changes[] = 'granted ' . self::privList($add) . " on {$table}";
            }
            if ($remove !== []) {
                $admin->pdo()->exec('REVOKE ' . self::privList($remove) . " ON {$target} FROM {$account}");
                $changes[] = 'revoked ' . self::privList($remove) . " on {$table}";
            }
            unset($current[$table]);
        }
        // Column-level grants, read after the table-level changes (a table-level REVOKE UPDATE also
        // drops that table's column-level UPDATE grants).
        $currentColumns = [];
        foreach ($admin->all('SELECT Table_name, Column_name, Column_priv FROM mysql.columns_priv WHERE User = ? AND Host = ? AND Db = ?', [$user, $host, $database]) as $r) {
            $privs = (string) $r['Column_priv'];
            $currentColumns[(string) $r['Table_name']][(string) $r['Column_name']] = $privs === '' ? [] : explode(',', $privs);
        }
        foreach ($tables as $table) {
            $table = (string) $table;
            array_push($changes, ...self::applyColumns($admin, $schema . '.' . Db::ident($table), $account, $table, $currentColumns[$table] ?? []));
        }
        // Grants left on tables that no longer exist.
        foreach (array_keys($current) as $gone) {
            $admin->pdo()->exec("REVOKE ALL PRIVILEGES ON {$schema}." . Db::ident((string) $gone) . " FROM {$account}");
            $changes[] = "revoked stale grant on dropped table {$gone}";
        }
        return $changes;
    }

    /**
     * Converges the column-level privileges of one table: grants what is missing, revokes the rest.
     *
     * @param array<string, list<string>> $have column => privileges held now
     * @return list<string>
     */
    private static function applyColumns(Db $admin, string $target, string $account, string $table, array $have): array
    {
        $want = self::desiredColumns($table);
        $grant = [];
        $revoke = [];
        foreach (array_unique([...array_keys($want), ...array_keys($have)]) as $column) {
            $column = (string) $column;
            foreach (array_diff($want[$column] ?? [], $have[$column] ?? []) as $priv) {
                $grant[$priv][] = $column;
            }
            foreach (array_diff($have[$column] ?? [], $want[$column] ?? []) as $priv) {
                $revoke[$priv][] = $column;
            }
        }
        $changes = [];
        foreach (['GRANT' => $grant, 'REVOKE' => $revoke] as $verb => $byPriv) {
            ksort($byPriv);
            foreach ($byPriv as $priv => $columns) {
                sort($columns);
                $list = implode(', ', array_map(static fn (string $c): string => Db::ident($c), $columns));
                $admin->pdo()->exec($verb . ' ' . strtoupper($priv) . " ({$list}) ON {$target} " . ($verb === 'GRANT' ? 'TO' : 'FROM') . " {$account}");
                $changes[] = ($verb === 'GRANT' ? 'granted ' : 'revoked ') . strtoupper($priv) . ' (' . implode(', ', $columns) . ") on {$table}";
            }
        }
        return $changes;
    }

    public static function account(string $user, string $host = '%'): string
    {
        if (!DbSettings::isValidIdentifier($user) || preg_match('/^[A-Za-z0-9.%:_-]{1,255}$/', $host) !== 1) {
            throw new \InvalidArgumentException('invalid account name');
        }
        return "'{$user}'@'{$host}'";
    }

    /** @param list<string> $privs */
    private static function privList(array $privs): string
    {
        return implode(', ', array_map('strtoupper', $privs));
    }
}
