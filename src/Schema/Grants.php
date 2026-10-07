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
     * supplier_item_price: a supplier item's price history (I-2, I43): prices are added, never rewritten.
     * po_posting: the write-once anchor of an approved PO's module content (I-2, I50; invariant P2, like I33).
     * match_proposal_basis / key_sample / key_sample_member: what a proposal was made against, and a spot-check sample with
     * the population it was drawn from: the bulk confirm trusts them, so the app login can add them but never rewrite them (M27, M28).
     * company_profile: the company details printed on POs, one row per saved or confirmed version (0013, I91): a version is
     * added, never rewritten or removed, so who changed and who confirmed what stays readable.
     * key_bulk_hold: a listing held back from every Key bulk confirm, and the release of a hold (0014, M30): a release is a row
     * of its own, so the bulk confirm trusts the holds and who held or released what and why stays readable.
     * item_card_change: the history of every item card write (0016, I101): the app login may UPDATE item_card itself, so the
     * nightly invariants compare each card with its last history row, which the app login can add but never rewrite.
     * grn_posting: the write-once anchor of a posted goods receipt's module content (0017, I128; invariant G3, like po_posting).
     * item_selling_mode_log: every write of an item's selling mode (0017, I137): the app login may UPDATE item_selling_mode, so
     * the nightly invariants compare it with its last log row (G6).
     * item_channel_mode_log: every write of an item's selling mode on one site (0018, IM10, I151): the app login may UPDATE
     * item_channel_mode, so the nightly invariants compare it with its last log row (W1).
     */
    public const APPEND_ONLY = ['stock_ledger', 'audit_log', 'match_run', 'match_reject', 'stock_value_seq', 'stock_value_ledger',
        'stored_file', 'document_file', 'document_posting', 'supplier_item_price', 'po_posting', 'match_proposal_basis', 'key_sample',
        'key_sample_member', 'company_profile', 'key_bulk_hold', 'item_card_change', 'grn_posting', 'item_selling_mode_log', 'item_channel_mode_log'];
    /**
     * Append-only tables whose listed columns are the only ones the app may UPDATE (column-level
     * grant): a proposal's status, a decision's settlement, the end of a link period, an item's value
     * clock (Stock::assignValueSeq's INSERT ... ON DUPLICATE KEY UPDATE needs INSERT + UPDATE of
     * last_seq; the item id is never rewritten and a clock row is never deleted, I3), a role grant's revocation
     * (who held which role when stays readable: a grant is revoked, never rewritten or deleted, I10), a number series'
     * last number (I20), a review task's decision (I19), and a document's state columns: its identity (id, type,
     * creator, creation time, the document it reverses) is frozen and a document is never deleted (I17). A barcode review's
     * decision (0016, I107): what was found (the barcode, the items, the listing) is frozen, a row is never deleted. An incident's
     * resolution (0017, I131): what was found (the receipt line, the item, the units, where they went) is frozen, a row is never
     * deleted.
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
        'barcode_review' => ['status', 'decision', 'decided_units', 'decided_by', 'decided_actor', 'decided_at', 'note'],
        'incident' => ['status', 'resolution', 'resolved_by', 'resolved_actor', 'resolved_at'],
    ];
    /**
     * reason_code / document_type: seeded reference lists, changed only by a migration (I22, I19). app_setting: changed by
     * bin/settings.php with the admin login (I38; the company details moved to company_profile in 0013, I91); vat_code: by a
     * migration (I-2).
     */
    public const READ_ONLY = ['schema_migrations', 'reason_code', 'document_type', 'app_setting', 'vat_code'];
    /**
     * Rows the app never deletes: a listing (reservation_unit.listing_id has no FK, so deleting a listing
     * that only ever sold while unlinked would orphan its holding-ledger units), an item (merged, never
     * deleted: M10), a listing's profile, a staff account (deactivated, never deleted: decisions name it), a supplier and a
     * supplier item (deactivated, never deleted: POs and the price history name them, I-2), an ERPNext seed import's run and
     * a PO's header (cancelled, never deleted: I-2, I50). po_line keeps FULL rights: a draft's lines are replaced (its rows go
     * with their document_line, ON DELETE CASCADE). A sales-history import batch (its row stays when a later batch replaces its
     * days: the history of what was loaded, I-2 I61) and an anomaly window (ended, never deleted: who excluded which days stays
     * readable, I66). The sales-history tables, the reorder settings and reorder_demand keep FULL rights: an import replaces
     * its days, the demand is rebuilt (DELETE + INSERT in one transaction). An item card (0016, I101): changed, never removed.
     * sku_barcode keeps FULL rights: a person removes a barcode from an item (the removal is recorded in barcode_review and
     * audit_log, and the barcode can then go to another item: it is the table's key). A goods receipt's header (cancelled or
     * reversed, never deleted: 0017, I127) and an item's selling mode (changed, never removed: its log keeps every write, I137), also
     * per site (item_channel_mode, 0018, I151).
     * grn_line keeps FULL rights: a draft's lines are replaced (its rows go with their document_line, ON DELETE CASCADE).
     */
    public const NO_DELETE = ['channel_listing', 'listing_profile', 'sku', 'staff_user', 'supplier', 'supplier_item', 'import_run', 'purchase_order',
        'sales_import_batch', 'demand_anomaly', 'item_card', 'goods_receipt', 'item_selling_mode', 'item_channel_mode'];
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
