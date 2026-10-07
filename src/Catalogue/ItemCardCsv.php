<?php

declare(strict_types=1);

namespace CW\Catalogue;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Output\CsvReader;
use CW\Output\CsvWriter;

/**
 * The item cards as a CSV file people fill in Excel, and its import (IM3 "Bulk: CSV export and import"; docs/decisions.md I110,
 * I120).
 *
 * Export (CsvWriter: UTF-8 with a BOM, formula-safe, I25): one row per item of the list's filters, in list order, with the
 * read-only columns people need to work (name, catalogue brand, stock held, confirmation, warnings) and `card_version`.
 *
 * Import (CsvReader: comma, semicolon or TAB, Windows-1252 read as such, gzip guarded, I45):
 *  - `code` is required (CW-000123, or 123); the card columns (IMPORT_FIELDS) are optional; `card_version`, when given, must
 *    be the card's version now (a row whose card someone changed on the screen since the export is refused, never
 *    overwritten); the export's read-only columns (IGNORED) are ignored; any other column is refused (a typo such as
 *    "nicotine" must not be dropped silently: 400 bad_file).
 *  - An EMPTY cell changes nothing (clearing a value is done on the item page). A cell the export protected against formulas
 *    ("'=..." / "'-...") is read without that apostrophe.
 *  - Two steps. The CHECK reads every row against the cards as they are (a few bulk reads, no lock, no write: ItemCards::check and
 *    ItemCards::plan, the same rules as a save) and is the whole of a dry run. A real run then SAVES the rows that change a card,
 *    in one transaction, each through ItemCards::save (version re-checked under the card's lock); a row whose card changed
 *    between the two steps refuses the whole file. So an untouched or mostly unchanged export costs almost nothing, and the cap is
 *    on the rows that CHANGE a card ($maxChanges: 2,000 on the screen, I120), not on the rows of the file.
 *  - A file with any refused row changes NOTHING: the report lists every problem ("row N (CW-...), column: message"; N = the
 *    data row, spreadsheet row N + 1), and the person imports the corrected file again. Dry run (the default) never writes a card.
 *  - Each changed card is an ItemCards write of kind `import` (history, audit item_card.change): a flavour from a file is
 *    `proposed`; a file never confirms a card. One import_run row (kind item_cards) records every run, dry runs included (on the
 *    screen, a real run refused as a whole is recorded by the screen after its transaction rolled back); a real run is audited
 *    item_card.import. $origin (via: screen | cli, the OS user of a CLI run) goes into both.
 */
final class ItemCardCsv
{
    public const EXPORT_COLUMNS = [
        ['code', 'text'], ['name', 'text'], ['catalogue_brand', 'text'], ['stock_held', 'number'],
        ['product_type', 'text'], ['liquid_ml', 'number'], ['nicotine_mg', 'number'], ['duty_liable', 'text'], ['single_use', 'text'],
        ['ecid', 'text'], ['manufacturer', 'text'], ['brand', 'text'], ['flavour', 'text'], ['flavour_status', 'text'], ['discontinued', 'text'],
        ['card_version', 'number'], ['confirmed', 'text'], ['confirmed_by', 'text'], ['confirmed_at', 'text'], ['warnings', 'text'],
    ];
    /** The columns an import writes (ItemCards::FIELDS). */
    public const IMPORT_FIELDS = ['product_type', 'liquid_ml', 'nicotine_mg', 'duty_liable', 'single_use', 'ecid', 'manufacturer', 'brand', 'flavour', 'discontinued'];
    /** The export's read-only columns: read past, never written. */
    public const IGNORED = ['name', 'catalogue_brand', 'stock_held', 'flavour_status', 'confirmed', 'confirmed_by', 'confirmed_at', 'warnings'];
    /** The screen reads up to the whole catalogue (about 15,000 items): an unchanged row costs almost nothing (I120). */
    public const UI_MAX_ROWS = 20_000;
    /** The rows that CHANGE a card, on the screen: about 14 ms each, inside the UI pool's 60-second request (I112, I120). */
    public const UI_MAX_CHANGES = 2_000;
    public const CLI_MAX_ROWS = 20_000;
    /** The CLI's default cap on changed rows (--max-changes, up to CLI_MAX_ROWS): the cards it changes stay locked until it ends. */
    public const CLI_MAX_CHANGES = 5_000;
    public const CLI_MAX_BYTES = 33_554_432;
    /** The file-level refusals (no row was looked at) the screen records after FormOnce's transaction rolled back. */
    public const FILE_REFUSALS = ['bad_file', 'too_large', 'too_many_rows', 'too_many_changes'];
    public const REPORT_ERRORS = 200;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The CSV of the list rows (ItemCardList::rows).
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public static function export(iterable $rows): string
    {
        $csv = new CsvWriter(self::EXPORT_COLUMNS);
        foreach ($rows as $r) {
            $hasCard = $r['card_sku_id'] !== null;
            $st = $hasCard ? ItemRules::status($r) : ['level' => null, 'blocked' => [], 'warnings' => []];
            $warnings = implode('; ', array_filter([$st['blocked'] === [] ? '' : 'BLOCKED: ' . ItemRules::labels($st['blocked']),
                $st['warnings'] === [] ? '' : 'warning: ' . ItemRules::labels($st['warnings'])]));
            $csv->add([
                (string) $r['code'], (string) $r['name'], $r['catalogue_brand'] === null ? null : (string) $r['catalogue_brand'], (int) $r['held'],
                $r['product_type'] === null ? null : (ItemRules::TYPES[(string) $r['product_type']] ?? (string) $r['product_type']),
                $r['liquid_ml'] === null ? null : (string) $r['liquid_ml'], $r['nicotine_mg'] === null ? null : (string) $r['nicotine_mg'],
                ItemCards::yesNoLabel($r['duty_liable']), ItemCards::yesNoLabel($r['single_use']), $r['ecid'], $r['manufacturer'], $r['brand'], $r['flavour'],
                $r['flavour_status'], $hasCard ? ItemCards::yesNoLabel($r['discontinued']) : 'no', $hasCard ? (int) $r['version'] : 0,
                !$hasCard ? 'no card' : ($r['confirmed_at'] !== null ? 'yes' : ($r['first_confirmed_at'] !== null ? 'changed since confirmed' : 'no')),
                $r['confirmed_by_name'], $r['confirmed_at'] === null ? null : substr((string) $r['confirmed_at'], 0, 19),
                $warnings === '' ? null : $warnings,
            ]);
        }
        return $csv->output();
    }

    /**
     * Checks (and with $apply, imports) a file. Returns the run: run_id, apply, applied (true when cards were written), rows,
     * changed, unchanged, errors (row, code, column, message; at most REPORT_ERRORS, `errors_total` counts them all), changes
     * (row, code, fields, unconfirmed; the first 500), ignored_columns. 400 bad_file / 413 too_large / too_many_rows /
     * too_many_changes (the file itself), 403 as ItemCards::editor.
     *
     * @param array<string, string> $origin how the run was started (via: screen | cli; os_user): kept in import_run and the audit
     * @return array<string, mixed>
     */
    public function import(Caller $caller, string $path, string $fileName, bool $apply, int $maxBytes = CsvReader::DEFAULT_MAX_BYTES,
        ?int $maxRows = self::UI_MAX_ROWS, ?int $maxChanges = self::UI_MAX_CHANGES, array $origin = ['via' => 'screen']): array
    {
        ItemCards::editor($this->db, $caller);
        $sha = hash_file('sha256', $path);
        if ($sha === false) {
            throw new CwException('bad_file', 'the file could not be read', 400);
        }
        $name = self::fileName($fileName);
        $runId = $this->startRun($caller, $name, $sha, $apply);
        $report = ['run_id' => $runId, 'apply' => $apply, 'applied' => false, 'file' => $name, 'sha256' => $sha, 'rows' => 0, 'changed' => 0, 'unchanged' => 0,
            'errors' => [], 'errors_total' => 0, 'changes' => [], 'ignored_columns' => [], 'origin' => $origin];
        try {
            $table = CsvReader::table($path, $maxBytes, $maxRows);
            $header = $table['header'];
            if (!in_array('code', $header, true)) {
                throw new CwException('bad_file', 'the file needs a "code" column (the CW code of each item, as the export has it)', 400);
            }
            $unknown = array_values(array_diff($header, ['code', 'card_version'], self::IMPORT_FIELDS, self::IGNORED));
            if ($unknown !== []) {
                throw new CwException('bad_file', 'the file has columns the item cards do not know: ' . implode(', ', $unknown) . ' (the columns are '
                    . implode(', ', ['code', 'card_version', ...self::IMPORT_FIELDS]) . ')', 400, ['columns' => $unknown]);
            }
            $report['ignored_columns'] = array_values(array_intersect($header, self::IGNORED));
            $rows = iterator_to_array($table['rows'], true);
            $work = $this->check($rows, $report);
            if ($maxChanges !== null && count($work) > $maxChanges) {
                throw new CwException('too_many_changes', 'This file would change ' . number_format(count($work)) . ' item cards; at most '
                    . number_format($maxChanges) . ' are changed in one import. Nothing was imported: delete the rows you did not change, or import the '
                    . 'file in parts (filter the list, for example by product type or brand, and download each part).', 413,
                    ['changes' => count($work), 'max_changes' => $maxChanges]);
            }
        } catch (CwException $e) {
            $this->finish($runId, 'failed', $report + ['refused' => $e->getMessage()]);
            throw $e;
        }
        if ($apply && $report['errors_total'] === 0) {
            $this->write($caller, $work, $runId, $report);
        }
        $this->finish($runId, $apply && $report['errors_total'] > 0 ? 'failed' : 'done', $report);
        return $report;
    }

    /**
     * Records a run the screen refused as a whole after FormOnce's transaction rolled the import's own record back (I120): one
     * import_run row, status failed, with the refusal.
     *
     * @param array<string, string> $origin
     */
    public function recordRefused(Caller $caller, string $path, string $fileName, bool $apply, CwException $e, array $origin = ['via' => 'screen']): int
    {
        $name = self::fileName($fileName);
        $sha = is_file($path) ? hash_file('sha256', $path) : false;
        $runId = $this->startRun($caller, $name, $sha === false ? hash('sha256', '') : $sha, $apply);
        $this->finish($runId, 'failed', ['file' => $name, 'apply' => $apply, 'applied' => false, 'rows' => 0, 'changed' => 0, 'unchanged' => 0, 'errors' => [],
            'errors_total' => 0, 'ignored_columns' => [], 'origin' => $origin, 'refused' => $e->getMessage()]);
        return $runId;
    }

    /**
     * Step 1: every row against the cards as they are now, without a lock or a write. Fills the report (rows, unchanged, errors,
     * changes) and returns the rows that change a card.
     *
     * @param array<int, array<string, string>> $rows data row number => cells
     * @param array<string, mixed> $report
     * @return list<array{row: int, sku: int, code: string, version: int, input: array<string, string>}>
     */
    private function check(array $rows, array &$report): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (preg_match('/^(?:cw-?)?0*([0-9]{1,10})$/iD', trim(self::cell((string) ($row['code'] ?? ''))), $m) === 1) {
                $ids[(int) $m[1]] = true;
            }
        }
        $skus = [];
        $cards = [];
        foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($this->db->all("SELECT s.id, s.code, s.merged_into_sku_id, m.code AS merged_code FROM sku s LEFT JOIN sku m ON m.id = s.merged_into_sku_id "
                . "WHERE s.id IN ({$in})", $chunk) as $r) {
                $skus[(int) $r['id']] = $r;
            }
            foreach ($this->db->all("SELECT * FROM item_card WHERE sku_id IN ({$in})", $chunk) as $r) {
                $cards[(int) $r['sku_id']] = ItemCards::fromRow($r);
            }
        }
        $work = [];
        $seen = [];
        foreach ($rows as $n => $row) {
            $report['rows']++;
            $code = trim(self::cell((string) ($row['code'] ?? '')));
            $err = static function (?string $column, string $message) use (&$report, $n, &$code): void {
                $report['errors_total']++;
                if (count($report['errors']) < self::REPORT_ERRORS) {
                    $report['errors'][] = ['row' => (int) $n, 'code' => $code === '' ? null : $code, 'column' => $column, 'message' => $message];
                }
            };
            if (preg_match('/^(?:cw-?)?0*([0-9]{1,10})$/iD', $code, $m) !== 1) {
                $err('code', $code === '' ? 'the code is empty' : "\"{$code}\" is not a CW code (CW-000123)");
                continue;
            }
            $sku = $skus[(int) $m[1]] ?? null;
            if ($sku === null) {
                $err('code', "there is no item {$code}");
                continue;
            }
            $skuId = (int) $sku['id'];
            $code = (string) $sku['code'];
            if (isset($seen[$skuId])) {
                $err('code', "{$code} is also on row {$seen[$skuId]}: one row per item");
                continue;
            }
            $seen[$skuId] = (int) $n;
            $card = $cards[$skuId] ?? ItemCards::blankCard($skuId);
            $given = trim((string) ($row['card_version'] ?? ''));
            if ($given !== '') {
                if (preg_match('/^[0-9]{1,9}$/D', $given) !== 1) {
                    $err('card_version', "\"{$given}\" is not a card version (a whole number from the export)");
                    continue;
                }
                if ((int) $given !== $card['version']) {
                    $err('card_version', "the card changed since the export (version {$card['version']} now, the file has {$given}): export again or empty the cell");
                    continue;
                }
            }
            $input = [];
            foreach (self::IMPORT_FIELDS as $f) {
                $v = trim(self::cell((string) ($row[$f] ?? '')));
                if ($v !== '') {
                    $input[$f] = $v;
                }
            }
            if ($input === []) {
                $report['unchanged']++;
                continue;
            }
            if ($sku['merged_into_sku_id'] !== null) {
                $err(null, "{$code} was merged into " . ($sku['merged_code'] ?? 'another item') . ": change that item's card instead");
                continue;
            }
            try {
                $plan = ItemCards::plan($card, ItemCards::check($input), 'import');
            } catch (CwException $e) {
                $fields = is_array($e->detail['errors'] ?? null) ? $e->detail['errors'] : ['' => $e->getMessage()];
                foreach ($fields as $column => $message) {
                    $err($column === '' ? null : (string) $column, (string) $message);
                }
                continue;
            }
            if ($plan['changes'] === []) {
                $report['unchanged']++;
                continue;
            }
            $report['changed']++;
            if (count($report['changes']) < 500) {
                $report['changes'][] = ['row' => (int) $n, 'code' => $code, 'fields' => array_keys($plan['changes']), 'unconfirmed' => $plan['unconfirm']];
            }
            $work[] = ['row' => (int) $n, 'sku' => $skuId, 'code' => $code, 'version' => $card['version'], 'input' => $input];
        }
        return $work;
    }

    /**
     * Step 2 of a real run with no refused row: the changing rows saved in one transaction (inside a caller's transaction, the
     * screen's FormOnce: a savepoint of its own, since a joined transaction() would roll nothing back). A row whose card changed
     * since the check (409), or is locked by someone for too long (1205), refuses the whole file. A deadlock is left to the
     * outermost transaction(), which runs the unit again.
     *
     * @param list<array{row: int, sku: int, code: string, version: int, input: array<string, string>}> $work
     * @param array<string, mixed> $report
     */
    private function write(Caller $caller, array $work, int $runId, array &$report): void
    {
        $cards = new ItemCards($this->db);
        $body = function (Db $db) use ($caller, $work, $runId, $cards, &$report): void {
            $report['applied'] = false; // a deadlock retry runs the whole unit again
            foreach ($work as $w) {
                try {
                    $cards->save($caller, $w['sku'], $w['version'], $w['input'], 'import', ['import_run' => $runId, 'row' => $w['row']]);
                } catch (CwException | \PDOException $e) {
                    if ($e instanceof \PDOException && Db::driverCode($e) !== 1205) {
                        throw $e;
                    }
                    $report['errors_total']++;
                    $report['errors'][] = ['row' => $w['row'], 'code' => $w['code'], 'column' => null, 'message' => $e instanceof CwException
                        ? $e->getMessage() . ' (the card changed while the file was being imported: nothing was imported; import it again)'
                        : 'someone is changing this card right now: nothing was imported; import the file again in a minute'];
                    throw new ImportRolledBack();
                }
            }
            Audit::write($db, $caller, 'item_card.import', 'import_run', (string) $runId, null, ['file' => $report['file'], 'sha256' => $report['sha256'],
                'rows' => $report['rows'], 'changed' => $report['changed'], 'unchanged' => $report['unchanged'], 'origin' => $report['origin']]);
            $report['applied'] = true;
        };
        $pdo = $this->db->pdo();
        try {
            if ($this->db->inTransaction()) {
                $pdo->exec('SAVEPOINT item_card_import');
                try {
                    $body($this->db);
                    $pdo->exec('RELEASE SAVEPOINT item_card_import');
                } catch (\Throwable $e) {
                    // After a deadlock InnoDB has rolled the whole transaction back and the savepoint is gone: rethrow the 1213 itself,
                    // so the caller's transaction() retries the unit (a failed ROLLBACK TO would replace it with a 1305).
                    if (!Db::isDeadlock($e)) {
                        try {
                            $pdo->exec('ROLLBACK TO SAVEPOINT item_card_import');
                        } catch (\PDOException) {
                            // the transaction is gone: the original error says why
                        }
                    }
                    throw $e;
                }
            } else {
                $this->db->transaction($body);
            }
        } catch (ImportRolledBack) {
            $report['applied'] = false;
        }
    }

    /** The text of a cell without the apostrophe CsvWriter puts before a would-be formula (=, +, -, @, their full-width forms, TAB, CR). */
    public static function cell(string $v): string
    {
        if (preg_match('/^\'(?=[\s\x{3000}]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]|[\t\r])/u', $v) === 1) {
            return substr($v, 1);
        }
        return $v;
    }

    /**
     * A report as CSV (the CLI's --report): every error, then every change.
     *
     * @param array<string, mixed> $report
     */
    public static function reportCsv(array $report): string
    {
        $csv = new CsvWriter([['row', 'number'], ['code', 'text'], ['status', 'text'], ['column', 'text'], ['message', 'text']]);
        foreach ($report['errors'] as $e) {
            $csv->add([$e['row'], $e['code'], 'refused', $e['column'], $e['message']]);
        }
        foreach ($report['changes'] as $c) {
            $csv->add([$c['row'], $c['code'], $report['applied'] ? 'changed' : 'would change', implode(' ', $c['fields']), $c['unconfirmed'] ? 'the card is no longer confirmed' : null]);
        }
        return $csv->output();
    }

    private static function fileName(string $fileName): string
    {
        $name = mb_substr(basename(str_replace('\\', '/', $fileName)), 0, 255);
        return $name === '' ? 'item-cards.csv' : $name;
    }

    private function startRun(Caller $caller, string $name, string $sha, bool $apply): int
    {
        return $this->db->insert("INSERT INTO import_run (kind, file_name, file_sha256, dry_run, actor, staff_user_id) VALUES ('item_cards', ?, ?, ?, ?, ?)",
            [$name, $sha, $apply ? 0 : 1, $caller->actor, $caller->staffUserId]);
    }

    /** @param array<string, mixed> $report */
    private function finish(int $runId, string $status, array $report): void
    {
        $summary = ['file' => $report['file'], 'apply' => $report['apply'], 'applied' => $report['applied'], 'errors_total' => $report['errors_total'],
            'errors' => array_slice($report['errors'], 0, 20), 'ignored_columns' => $report['ignored_columns'], 'origin' => $report['origin'] ?? null]
            + (isset($report['refused']) ? ['refused' => $report['refused']] : []);
        $this->db->exec('UPDATE import_run SET status = ?, rows_read = ?, updated = ?, skipped = ?, failed = ?, summary = ?, finished_at = UTC_TIMESTAMP(6) WHERE id = ?',
            [$status, $report['rows'], $report['applied'] ? $report['changed'] : 0, $report['unchanged'], $report['errors_total'],
                json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $runId]);
    }
}

