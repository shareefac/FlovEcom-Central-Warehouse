<?php

declare(strict_types=1);

namespace CW\Schema;

use CW\Db;
use CW\DbSettings;

/**
 * Table-level grants for the application login (cw_app), converged idempotently:
 *  - every table: SELECT, INSERT, UPDATE, DELETE
 *  - append-only tables (stock_ledger, audit_log): SELECT, INSERT only
 *  - schema_migrations: SELECT only (migrations run as the admin login)
 *  - no database-level (db.*) grant, so a table added later has no rights until apply() runs.
 * Grants are compared with mysql.tables_priv and only the difference is granted/revoked,
 * so there is never a window in which the app loses its rights.
 */
final class Grants
{
    public const APPEND_ONLY = ['stock_ledger', 'audit_log'];
    public const READ_ONLY = ['schema_migrations'];
    public const FULL = ['Select', 'Insert', 'Update', 'Delete'];

    /** @return list<string> privileges (mysql.tables_priv spelling) the app login should hold on $table */
    public static function desired(string $table): array
    {
        if (in_array($table, self::READ_ONLY, true)) {
            return ['Select'];
        }
        if (in_array($table, self::APPEND_ONLY, true)) {
            return ['Select', 'Insert'];
        }
        return self::FULL;
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
        // Grants left on tables that no longer exist.
        foreach (array_keys($current) as $gone) {
            $admin->pdo()->exec("REVOKE ALL PRIVILEGES ON {$schema}." . Db::ident((string) $gone) . " FROM {$account}");
            $changes[] = "revoked stale grant on dropped table {$gone}";
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
