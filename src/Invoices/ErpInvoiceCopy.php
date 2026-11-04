<?php

declare(strict_types=1);

namespace CW\Invoices;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Idempotency;
use CW\PurchaseOrders\PoMath;
use CW\Settings;
use CW\Suppliers\ErpSeedImport;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;

/**
 * The copies of ERPNext purchase invoices (CW-917, the owner, 4 Nov 2026: "917 do it"; docs/decisions.md EC1-EC16). Every Purchase
 * Invoice staff submit in ERPNext (company erp_copy.company, posted on or after erp_copy.from_date) is kept in CW as a posted supplier
 * invoice (a debit note as a supplier credit note) with its supplier, number, date, totals and lines (item, units, pack price), so CW holds
 * the suppliers and their prices on the switch-over day. A copy NEVER moves stock and NEVER feeds the cost: the stock reached CW already
 * through the shop's relay (goods_in / supplier_return relay lines, doc_ref = the ERPNext name); the copy is supplier_invoice.origin
 * 'erpnext', receive_mode NULL, no delivery, no order, no value event (SupplierInvoiceHandler refuses a copy that would, InvoiceInvariants
 * V8 checks it every night).
 *
 * run() (bin/copy_erp_invoices.php, cron every 30 minutes) does nothing while erp_copy.on is off. Otherwise it reads ERPNext (ErpApiReader:
 * GET only, from the cursor its last run reached, else from the from date), then for each invoice ONE transaction (a dry run: the same
 * transaction, rolled back, so it writes nothing):
 *
 *   - keep what was read (erp_pi, erp_pi_line; a re-read with the same content changes nothing);
 *   - cancelled in ERPNext (docstatus 2): its copy is cancelled (Documents::reverseBySystem, reason erp_cancelled), the credit notes that
 *     credit it first; never copied: skipped;
 *   - an amendment ("-1"): the copy of the invoice it amends is cancelled first (erp_cancelled), then it is copied as any invoice;
 *   - re-read with other content after it was copied: the copy (and its credit notes) is cancelled (erp_changed) and it is copied again;
 *   - else copied whole or not at all: the supplier by supplier.erp_name (made as a draft when erp_copy.create_suppliers is on), every
 *     stock row's item and units (resolve()), the rows that are not stock as service, freight or other amounts, its taxes that are not VAT
 *     as lines, a rounding line so the lines add up to ERPNext's total; a debit note as a credit note crediting the copy of the invoice it
 *     returns against, row by row. One row that cannot be placed and the whole invoice waits (state waiting, `problem` says why, tried again
 *     on every run): never half copied. Waiting ones are listed on Purchases > Supplier invoices > From ERPNext, never in Approvals;
 *   - copied: the supplier's last price of each item whose item and units are certain (the relay booked it in those units, or the ERPNext
 *     item is linked to it) becomes an `invoice` price (erp_copy.update_prices).
 *
 * Then the copies whose stock the relay had not booked yet are looked at again (relayRecheck()): what the relay booked since is kept on
 * their lines, and their match is worked out again in the same transaction (InvoiceMatch reads it: matched, or "not booked in CW").
 *
 * The writer is the system caller system:erp_copy (Documents::SYSTEM_JOBS): no person, nothing for Approvals.
 */
final class ErpInvoiceCopy
{
    public const JOB = 'erp_copy';
    public const SWITCH = 'erp_copy.on';
    public const COMPANY = 'erp_copy.company';
    public const FROM = 'erp_copy.from_date';
    public const CREATE_SUPPLIERS = 'erp_copy.create_suppliers';
    public const UPDATE_PRICES = 'erp_copy.update_prices';
    /** The relay's store: ERPNext's purchase invoices reach CW through Vape and Go's shop (its variant numbers are on ERPNext's items). */
    public const CHANNEL = ErpSeedImport::VPG_CHANNEL;
    public const STATES = ['waiting', 'copied', 'reversed', 'skipped'];
    /** Why an invoice waits (tried again on every run; Ui\Words::ERP_COPY_WHY). */
    public const WAITS = ['unknown_supplier', 'no_item', 'fractional_qty', 'units_differ', 'bad_price', 'taxes_unclear', 'duplicate_number', 'original_waiting',
        'original_missing', 'return_unmatched', 'return_without_invoice', 'refused'];
    /** Why an invoice is never copied. */
    public const SKIPS = ['before_start', 'other_company', 'cancelled_before_copy', 'original_skipped'];
    public const PROBLEMS = [...self::WAITS, ...self::SKIPS];
    /** Waiting invoices tried again in one run (oldest first), and copies looked at again for the relay. */
    public const RETRY_MAX = 2000;
    public const RECHECK_MAX = 500;
    public const LIST_LIMIT = 200;
    /** A row that is not stock (or a charge) with one of these words is freight; any other is a service (a negative one: other). */
    public const FREIGHT_WORDS = '/\b(freight|carriage|shipping|delivery|postage|courier|haulage)\b/i';
    private const DRY_RUN = 'erp_copy_dry_run';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    private readonly Documents $docs;
    private readonly SupplierInvoices $invoices;
    private readonly Caller $job;
    private ?int $channelId = null;
    private bool $channelRead = false;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
        $this->docs = new Documents($db, DocumentHandlers::all($db, $this->clock), $this->clock);
        $this->invoices = new SupplierInvoices($db, $this->docs, null, $this->clock);
        $this->job = Caller::system(self::JOB);
    }

    public static function actor(): string
    {
        return 'system:' . self::JOB;
    }

    /**
     * The settings in force now (read on every call), with their defaults when a row is missing.
     *
     * @return array{on: bool, company: string, from: string, create_suppliers: bool, update_prices: bool}
     */
    public function settings(): array
    {
        $s = new Settings($this->db);
        $get = static fn (string $k, mixed $d): mixed => $s->has($k) ? $s->get($k) : $d;
        $company = trim((string) $get(self::COMPANY, 'VAPE AND GO'));
        $from = $get(self::FROM, '2026-09-01');
        return ['on' => (bool) $get(self::SWITCH, false), 'company' => $company === '' ? 'VAPE AND GO' : $company,
            'from' => is_string($from) && $from !== '' ? $from : '2026-09-01', 'create_suppliers' => (bool) $get(self::CREATE_SUPPLIERS, true),
            'update_prices' => (bool) $get(self::UPDATE_PRICES, true)];
    }

    // ------------------------------------------------------------------------------------------
    // The run
    // ------------------------------------------------------------------------------------------

    /**
     * One run. $apply false: a dry run (every invoice's transaction rolled back: nothing is written, not even the run row). $from: read
     * from this posting date, every change since it (not from the cursor). $limit: at most this many invoices read from ERPNext.
     * Returns what it did: ['state' => 'off'] while the switch is off (nothing read), else the counts and the outcome of each invoice.
     *
     * @param (\Closure(string): void)|null $progress
     * @return array<string, mixed>
     */
    public function run(ErpApiReader $reader, bool $apply, ?string $from = null, ?int $limit = null, ?\Closure $progress = null): array
    {
        $s = $this->settings();
        if (!$s['on']) {
            return ['state' => 'off'];
        }
        $fromAt = $from === null ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        if ($from !== null && ($fromAt === false || $fromAt->format('Y-m-d') !== $from)) {
            throw new \InvalidArgumentException('--from must be a date, YYYY-MM-DD');
        }
        $fromDate = $from ?? $s['from'];
        $s['from'] = $fromDate; // --from: this run copies from that date (the back-fill)
        $cursor = $from === null ? $this->cursor($s['company'], $fromDate) : null;
        $runId = $apply ? $this->db->insert('INSERT INTO erp_copy_run (actor, company, from_date, cursor_from) VALUES (?, ?, ?, ?)',
            [self::actor(), $s['company'], $fromDate, $cursor[0] ?? null]) : null;
        $sum = ['state' => 'ran', 'dry_run' => !$apply, 'company' => $s['company'], 'from' => $fromDate, 'cursor_from' => $cursor[0] ?? null,
            'cursor_to' => $cursor[0] ?? null, 'cursor_name' => $cursor[1] ?? null, 'read' => 0, 'outcomes' => [], 'counts' => [], 'problems' => [],
            'suppliers_created' => 0, 'prices' => 0, 'relay_rechecked' => 0, 'retried' => 0, 'stopped' => false];
        try {
            $listed = $reader->changed($s['company'], $fromDate, $cursor, $limit);
            $docs = [];
            foreach ($listed as $l) {
                $docs[$l['name']] = $reader->invoice($l['name']);
                $sum['read']++;
                // The list is in (modified, name) order: its last invoice is where the next run goes on from.
                $sum['cursor_to'] = $l['modified'];
                $sum['cursor_name'] = $l['name'];
            }
            // A copy whose last attempt was refused (its cancellation, say) is read again from ERPNext: past the cursor nothing else would
            // bring it back (the Codex re-check of 6102935, NEW 1).
            foreach ($this->db->column("SELECT name FROM erp_pi WHERE state = 'copied' AND problem IS NOT NULL ORDER BY read_at, id LIMIT " . self::RETRY_MAX) as $again) {
                if (!isset($docs[(string) $again])) {
                    $docs[(string) $again] = $reader->invoice((string) $again);
                    $sum['read']++;
                }
            }
            // The invoice a debit note returns against and the one an amendment replaces, when this run did not read it and CW never did.
            foreach ($docs as $d) {
                foreach (['return_against', 'amended_from'] as $k) {
                    $o = is_string($d[$k] ?? null) && $d[$k] !== '' ? $d[$k] : null;
                    if ($o !== null && !isset($docs[$o]) && $this->db->value('SELECT 1 FROM erp_pi WHERE name = ?', [$o]) === null) {
                        $docs[$o] = $reader->invoice($o);
                        $sum['read']++;
                    }
                }
            }
            $codes = [];
            foreach ($docs as $d) {
                foreach ((array) $d['items'] as $it) {
                    if (is_array($it) && is_string($it['item_code'] ?? null)) {
                        $codes[$it['item_code']] = true;
                    }
                }
            }
            $codes = array_map('strval', array_keys($codes)); // a numeric code became an int key (the Codex review of 0ccc04c, MEDIUM 2)
            $items = $codes === [] ? [] : $reader->items($codes);
            foreach ($codes as $c) {
                if (!isset($items[(string) $c])) {
                    // Fail closed: without it, which rows are stock (and so the relay's line numbers) is not known. ERPNext never deletes an
                    // item a submitted invoice names: the read-only user cannot read it (EC1).
                    throw new CwException('erp_read_failed', "ERPNext did not give the item {$c}: check that the read-only user may read Items", 502);
                }
            }
            $batch = [];
            foreach ($docs as $name => $d) {
                $batch[] = self::normalise($d, $items);
            }
            usort($batch, static fn (array $a, array $b): int => [$a['is_return'], $a['amended_from'] !== null, $a['posting_date'], $a['name']]
                <=> [$b['is_return'], $b['amended_from'] !== null, $b['posting_date'], $b['name']]);
            $seen = [];
            $wouldCopy = [];
            foreach ($batch as $n) {
                if (!Settings::flag($this->db, self::SWITCH, false)) {
                    $sum['stopped'] = true; // switched off while it ran: the rest waits for the next run
                    break;
                }
                $seen[$n['name']] = true;
                $this->count($sum, $this->processOne($n, $apply, $s, $wouldCopy), $wouldCopy);
                if ($progress !== null) {
                    $progress($n['name']);
                }
            }
            // The invoices still waiting from earlier runs (an item linked since, a supplier made since, their invoice copied since): the ones
            // tried longest ago first (each try sets read_at, so none is left out for good: the Codex review of 0ccc04c, MEDIUM 6), then the
            // invoices before their debit notes and amendments.
            if (!$sum['stopped']) {
                $retry = $this->db->all("SELECT name, is_return, amended_from, posting_date, id FROM erp_pi WHERE state = 'waiting' ORDER BY read_at, id LIMIT " . self::RETRY_MAX);
                usort($retry, static fn (array $a, array $b): int => [(int) $a['is_return'], $a['amended_from'] !== null, (string) $a['posting_date'], (int) $a['id']]
                    <=> [(int) $b['is_return'], $b['amended_from'] !== null, (string) $b['posting_date'], (int) $b['id']]);
                foreach ($retry as $w) {
                    if (isset($seen[(string) $w['name']])) {
                        continue;
                    }
                    $n = $this->stored((string) $w['name']);
                    if ($n === null) {
                        continue;
                    }
                    $sum['retried']++;
                    $this->count($sum, $this->processOne($n, $apply, $s, $wouldCopy), $wouldCopy);
                }
                $sum['relay_rechecked'] = $this->relayRecheck($apply);
            }
            if ($runId !== null) {
                // Stopped (switched off while it ran): the cursor stays, so the next run reads again what this one did not finish.
                $this->db->exec("UPDATE erp_copy_run SET status = 'done', cursor_to = ?, cursor_name = ?, summary = CAST(? AS JSON), finished_at = ? WHERE id = ?",
                    [$sum['stopped'] ? ($cursor[0] ?? null) : $sum['cursor_to'], $sum['stopped'] ? ($cursor[1] ?? null) : $sum['cursor_name'],
                        Idempotency::json(self::summaryRow($sum)), $this->nowDb(), $runId]);
            }
        } catch (\Throwable $e) {
            if ($runId !== null) {
                try {
                    $this->db->exec("UPDATE erp_copy_run SET status = 'failed', summary = CAST(? AS JSON), finished_at = ? WHERE id = ?",
                        [Idempotency::json(self::summaryRow($sum) + ['error' => $e instanceof CwException ? $e->errorCode : get_class($e),
                            'message' => mb_substr($e->getMessage(), 0, 300)]), $this->nowDb(), $runId]);
                } catch (\Throwable) {
                    // the connection is gone: the run stays `running`, which says it did not finish
                }
            }
            throw $e;
        }
        $sum['run'] = $runId;
        return $sum;
    }

    /**
     * Where the last finished run of this company and from date stopped: the modified time and name of the last ERPNext invoice it read
     * (null: none yet: read everything since the date). The next run reads what comes after it (ErpApiReader::changed()).
     *
     * @return array{0: string, 1: string}|null
     */
    public function cursor(string $company, string $fromDate): ?array
    {
        $r = $this->db->one("SELECT cursor_to, cursor_name FROM erp_copy_run WHERE status = 'done' AND company = ? AND from_date = ? AND cursor_to IS NOT NULL "
            . 'ORDER BY id DESC LIMIT 1', [$company, $fromDate]);
        return $r === null ? null : [substr((string) $r['cursor_to'], 0, 26), (string) ($r['cursor_name'] ?? '')];
    }

    /** @return array<string, mixed>|null the newest run row */
    public function lastRun(): ?array
    {
        return $this->db->one('SELECT * FROM erp_copy_run ORDER BY id DESC LIMIT 1');
    }

    /**
     * One invoice in ONE transaction (a dry run rolls it back). A refusal outside the copy itself (a cancellation refused, a supplier that
     * cannot be made) is noted on the row in a transaction of its own (problem `refused`) and the run goes on.
     *
     * @param array<string, mixed> $n normalise()
     * @param array{on: bool, company: string, from: string, create_suppliers: bool, update_prices: bool} $s
     * @param array<string, true> $wouldCopy
     * @return array<string, mixed> the outcome
     */
    private function processOne(array $n, bool $apply, array $s, array $wouldCopy): array
    {
        $out = null;
        try {
            return $this->db->transaction(function () use ($n, $apply, $s, $wouldCopy, &$out): array {
                $out = $this->step($n, $s, null);
                if (!$apply) {
                    throw new CwException(self::DRY_RUN, 'a dry run: everything it did is rolled back', 200);
                }
                return $out;
            });
        } catch (CwException $e) {
            if ($e->errorCode === self::DRY_RUN) {
                return $out;
            }
            $problem = ['code' => 'refused', 'detail' => mb_substr($e->errorCode . ': ' . $e->getMessage(), 0, 255), 'line' => null];
            if (!$apply) {
                return ['name' => $n['name'], 'outcome' => 'waiting', 'problem' => 'refused', 'detail' => $problem['detail']];
            }
            return $this->db->transaction(fn (): array => $this->step($n, $s, $problem));
        }
    }

    /**
     * What happens to one invoice, inside its transaction (the class docblock). $forced: a refusal of an earlier attempt, noted instead
     * of any action.
     *
     * @param array<string, mixed> $n
     * @param array{on: bool, company: string, from: string, create_suppliers: bool, update_prices: bool} $s
     * @param array{code: string, detail: ?string, line: ?int}|null $forced
     * @return array<string, mixed>
     */
    private function step(array $n, array $s, ?array $forced): array
    {
        $now = $this->nowDb();
        $row = $this->db->one('SELECT * FROM erp_pi WHERE name = ? FOR UPDATE', [$n['name']]);
        $new = $row === null;
        $changed = $new || $row['content_hash'] !== $n['hash'];
        if ($new) {
            $id = $this->db->insert('INSERT INTO erp_pi (name, company, supplier, supplier_name, bill_no, bill_date, posting_date, due_date, is_return, return_against, '
                . 'amended_from, docstatus, erp_modified, net_total, vat_total, grand_total, charges, content_hash, first_read_at, read_at, state_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS JSON), ?, ?, ?, ?)',
                [$n['name'], $n['company'], $n['supplier'], $n['supplier_name'], $n['bill_no'], $n['bill_date'], $n['posting_date'], $n['due_date'], $n['is_return'],
                    $n['return_against'], $n['amended_from'], $n['docstatus'], $n['modified'], $n['net_total'], $n['vat_total'], $n['grand_total'],
                    Idempotency::json($n['charges']), $n['hash'], $now, $now, $now]);
            $this->writeLines($id, $n['lines']);
            $row = (array) $this->db->one('SELECT * FROM erp_pi WHERE id = ? FOR UPDATE', [$id]);
        } else {
            $this->db->exec('UPDATE erp_pi SET docstatus = ?, erp_modified = ?, supplier_name = ?, read_at = ? WHERE id = ?',
                [$n['docstatus'], $n['modified'], $n['supplier_name'], $now, (int) $row['id']]);
        }
        $id = (int) $row['id'];
        $out = ['name' => $n['name'], 'outcome' => 'unchanged', 'problem' => null, 'detail' => null, 'is_return' => (bool) $n['is_return'],
            'return_against' => $n['return_against'], 'new' => $new, 'did' => []];
        $state = (string) $row['state'];
        if ($forced !== null) {
            // What was read is kept even when the attempt was refused (the Codex review of 0ccc04c, MEDIUM 4): a later retry from what CW kept
            // must never copy an older content. A live copy keeps its content (and its old hash: the change is tried again on the next read).
            if (!$new && $changed && $state !== 'copied' && $state !== 'reversed') {
                $this->keepContent($id, $n);
            }
            return $this->problem($id, $state, $forced['code'], $forced['line'], $forced['detail'], $out);
        }
        // A copy whose content changed in ERPNext: cancelled first (its credit notes before it), then copied again below.
        if ($state === 'copied' && $changed && (int) $n['docstatus'] === 1) {
            $this->reverseCopy($row, 'erp_changed', $out);
            $state = 'waiting';
        }
        if (!$new && $state !== 'copied' && $state !== 'reversed') {
            $this->keepContent($id, $n); // what ERPNext says now (and what its items say: a stock item, a variant set since)
        }
        if ((int) $n['docstatus'] === 2) {
            if ($state === 'copied') {
                $this->reverseCopy($row, 'erp_cancelled', $out);
                return $out;
            }
            return $state === 'reversed' || ($state === 'skipped' && $row['problem'] === 'cancelled_before_copy') ? $out
                : $this->problem($id, $state, 'cancelled_before_copy', null, null, $out, 'skipped');
        }
        if ($state === 'copied' || $state === 'reversed') {
            return $out; // the same content as its copy; or its copy was cancelled with ERPNext's and it came back unchanged
        }
        if ($n['company'] !== $s['company']) {
            return $this->problem($id, $state, 'other_company', null, $n['company'], $out, 'skipped');
        }
        if ($n['posting_date'] < $s['from']) {
            return $this->problem($id, $state, 'before_start', null, $n['posting_date'], $out, 'skipped');
        }
        if ($n['amended_from'] !== null) {
            $this->amended((string) $n['amended_from'], $out);
        }
        if ($n['taxes_problem'] !== null) {
            return $this->problem($id, $state, 'taxes_unclear', null, $n['taxes_problem'], $out);
        }
        // The supplier.
        $supplierId = $this->db->value('SELECT id FROM supplier WHERE erp_name = ?', [$n['supplier']]);
        if ($supplierId === null) {
            if (!$s['create_suppliers']) {
                return $this->problem($id, $state, 'unknown_supplier', null, $n['supplier'], $out);
            }
            $supplierId = (new Suppliers($this->db, null, $this->clock))->createFromErp($this->job, (string) $n['supplier'], $n['supplier_name'])['id'];
            $out['did'][] = 'supplier_created';
        }
        $supplierId = (int) $supplierId;
        $this->db->exec('UPDATE erp_pi SET supplier_id = ? WHERE id = ?', [$supplierId, $id]);
        // A debit note's invoice.
        $original = null;
        if ((int) $n['is_return'] === 1) {
            if ($n['return_against'] === null) {
                return $this->problem($id, $state, 'return_without_invoice', null, null, $out);
            }
            $original = $this->db->one('SELECT * FROM erp_pi WHERE name = ? FOR UPDATE', [$n['return_against']]);
            if ($original === null) {
                return $this->problem($id, $state, 'original_missing', null, (string) $n['return_against'], $out);
            }
            if ($original['state'] === 'skipped') {
                return $this->problem($id, $state, 'original_skipped', null, (string) $n['return_against'], $out, 'skipped');
            }
            if ($original['state'] !== 'copied') {
                return $this->problem($id, $state, 'original_waiting', null, (string) $n['return_against'], $out);
            }
        }
        $plan = $this->plan($n, $supplierId, $original);
        if (isset($plan['problem'])) {
            return $this->problem($id, $state, $plan['problem'], $plan['line'] ?? null, $plan['detail'] ?? null, $out);
        }
        // The copy: whole or not at all (a refusal of the posting rolls back to here and the invoice waits).
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT erp_copy');
        try {
            $doc = $this->invoices->createCopy($this->job, (int) $n['is_return'] === 1 ? 'credit_note' : 'invoice', $supplierId, $plan['header'], $plan['lines'],
                (string) $n['name']);
        } catch (CwException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT erp_copy');
            return $this->problem($id, $state, $e->errorCode === 'duplicate_invoice' ? 'duplicate_number' : 'refused', null,
                mb_substr($e->errorCode . ': ' . $e->getMessage(), 0, 255), $out);
        }
        $pdo->exec('RELEASE SAVEPOINT erp_copy');
        $this->db->exec("UPDATE erp_pi SET state = 'copied', problem = NULL, problem_line = NULL, problem_detail = NULL, document_id = ?, copied_hash = content_hash, "
            . 'state_at = ? WHERE id = ?', [$doc->id, $now, $id]);
        foreach ($plan['resolved'] as $idx => $r) {
            $this->db->exec('UPDATE erp_pi_line SET sku_id = ?, u = ?, units = ?, via = ?, relay_line_id = ?, relay_units = ?, line_no = ? WHERE erp_pi_id = ? AND idx = ?',
                [$r['sku_id'], $r['u'], $r['units'], $r['via'], $r['relay_line_id'], $r['relay_units'], $r['line_no'], $id, $idx]);
        }
        if ((int) $n['is_return'] === 0) {
            (new InvoiceMatch($this->db))->refresh([$doc->id], $now); // now that the copy's lines name what the relay booked (EC9)
            if ($s['update_prices']) {
                $out['prices'] = $this->prices($supplierId, $doc->id, (string) $plan['header']['invoice_date'], (string) $n['name'], $plan['resolved'], $n['lines']);
            }
        }
        Audit::write($this->db, $this->job, 'erp_copy.copy', 'erp_pi', (string) $id, null, ['name' => $n['name'], 'document' => $doc->id, 'number' => $doc->number]);
        $out['outcome'] = 'copied';
        $out['document'] = $doc->id;
        $out['number'] = $doc->number;
        return $out;
    }

    /**
     * Notes why an invoice waits ($state waiting) or is skipped ('skipped'); a copied invoice keeps its copy and gets the problem noted (a
     * refusal of its cancellation, say). An invoice already in that state with that problem changes nothing.
     *
     * @param array<string, mixed> $out
     * @return array<string, mixed>
     */
    private function problem(int $id, string $state, string $code, ?int $line, ?string $detail, array $out, string $to = 'waiting'): array
    {
        $detail = $detail === null ? null : mb_substr($detail, 0, 255);
        $keep = in_array($state, ['copied', 'reversed'], true);
        $target = $keep ? $state : $to;
        $cur = $this->db->one('SELECT state, problem, problem_line, problem_detail FROM erp_pi WHERE id = ?', [$id]);
        if (!($cur !== null && $cur['state'] === $target && $cur['problem'] === $code && (string) ($cur['problem_line'] ?? '') === (string) ($line ?? '')
            && (string) ($cur['problem_detail'] ?? '') === (string) ($detail ?? ''))) {
            $this->db->exec('UPDATE erp_pi SET state = ?, problem = ?, problem_line = ?, problem_detail = ?, state_at = ? WHERE id = ?',
                [$target, $code, $line, $detail, $this->nowDb(), $id]);
            if (!$out['new'] || $target !== 'waiting') {
                $out['did'][] = 'problem';
            }
        }
        $out['outcome'] = $keep ? $state : $target;
        $out['problem'] = $code;
        $out['detail'] = $detail;
        $out['line'] = $line;
        return $out;
    }

    /**
     * Cancels the copy of an erp_pi row (its credit notes first, EC7): reason erp_cancelled (the ERPNext invoice was cancelled or amended:
     * the row is `reversed`, and so are its credit notes' rows) or erp_changed (it changed: the row waits to be copied again, and its
     * credit notes' rows wait for it).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $out
     */
    private function reverseCopy(array $row, string $reason, array &$out): void
    {
        $docId = (int) $row['document_id'];
        $now = $this->nowDb();
        $cancel = $reason === 'erp_cancelled';
        foreach ($this->db->all("SELECT d.id FROM supplier_invoice si JOIN document d ON d.id = si.document_id AND d.status = 'posted' WHERE si.credits_document_id = ? "
            . "AND si.kind = 'credit_note' ORDER BY d.id", [$docId]) as $cn) {
            $cnRow = $this->db->one('SELECT * FROM erp_pi WHERE document_id = ? FOR UPDATE', [(int) $cn['id']]);
            $rev = $this->docs->reverseBySystem($this->job, (int) $cn['id'], $reason, 'ERPNext ' . (string) $row['name']);
            if ($cnRow !== null) {
                $this->db->exec($cancel
                    ? "UPDATE erp_pi SET state = 'reversed', reversal_document_id = ?, problem = NULL, problem_line = NULL, problem_detail = NULL, state_at = ? WHERE id = ?"
                    : "UPDATE erp_pi SET state = 'waiting', document_id = NULL, copied_hash = NULL, reversal_document_id = ?, problem = 'original_waiting', problem_line = NULL, "
                        . 'problem_detail = ?, state_at = ? WHERE id = ?',
                    $cancel ? [$rev->id, $now, (int) $cnRow['id']] : [$rev->id, mb_substr((string) $row['name'], 0, 255), $now, (int) $cnRow['id']]);
                if (!$cancel) {
                    $this->db->exec('UPDATE erp_pi_line SET sku_id = NULL, u = NULL, units = NULL, via = NULL, relay_line_id = NULL, relay_units = NULL, line_no = NULL WHERE erp_pi_id = ?',
                        [(int) $cnRow['id']]);
                }
            }
            $out['did'][] = 'credit_note_reversed';
        }
        $rev = $this->docs->reverseBySystem($this->job, $docId, $reason, 'ERPNext ' . (string) $row['name']);
        if ($cancel) {
            $this->db->exec("UPDATE erp_pi SET state = 'reversed', reversal_document_id = ?, problem = NULL, problem_line = NULL, problem_detail = NULL, state_at = ? WHERE id = ?",
                [$rev->id, $now, (int) $row['id']]);
            $out['outcome'] = 'reversed';
        } else {
            $this->db->exec("UPDATE erp_pi SET state = 'waiting', document_id = NULL, copied_hash = NULL, reversal_document_id = ?, state_at = ? WHERE id = ?",
                [$rev->id, $now, (int) $row['id']]);
            $this->db->exec('DELETE FROM erp_pi_line WHERE erp_pi_id = ?', [(int) $row['id']]);
            $out['did'][] = 'recopy';
        }
        Audit::write($this->db, $this->job, 'erp_copy.reverse', 'erp_pi', (string) $row['id'], null, ['name' => $row['name'], 'document' => $docId, 'reversal' => $rev->id,
            'reason' => $reason]);
        $out['did'][] = 'reversed';
    }

    /** An amendment: the copy of the invoice it amends is cancelled (that invoice is cancelled in ERPNext); never copied: skipped. @param array<string, mixed> $out */
    private function amended(string $name, array &$out): void
    {
        $o = $this->db->one('SELECT * FROM erp_pi WHERE name = ? FOR UPDATE', [$name]);
        if ($o === null) {
            return;
        }
        if ($o['state'] === 'copied') {
            $this->reverseCopy($o, 'erp_cancelled', $out);
            $out['outcome'] = 'unchanged';
            $out['did'][] = 'amended_reversed';
        } elseif ($o['state'] === 'waiting') {
            $this->db->exec("UPDATE erp_pi SET state = 'skipped', problem = 'cancelled_before_copy', problem_line = NULL, problem_detail = NULL, state_at = ? WHERE id = ?",
                [$this->nowDb(), (int) $o['id']]);
        }
    }

    // ------------------------------------------------------------------------------------------
    // The plan of a copy
    // ------------------------------------------------------------------------------------------

    /**
     * The header and lines of the copy of $n, or the first problem (['problem' => code, 'line' => idx, 'detail' => text]). Read only.
     *
     * @param array<string, mixed> $n
     * @param array<string, mixed>|null $original the erp_pi row a debit note returns against (copied)
     * @return array<string, mixed>
     */
    private function plan(array $n, int $supplierId, ?array $original): array
    {
        $return = (int) $n['is_return'] === 1;
        $sign = $return ? -1 : 1;
        $lines = [];
        $resolved = [];
        $sumE2 = 0;
        $origLines = [];
        $creditLeft = [];
        if ($original !== null) {
            foreach ($this->db->all('SELECT l.*, dl.qty AS doc_qty FROM erp_pi_line l LEFT JOIN document_line dl ON dl.document_id = ? AND dl.line_no = l.line_no '
                . 'WHERE l.erp_pi_id = ? AND l.line_no IS NOT NULL ORDER BY l.idx', [(int) $original['document_id'], (int) $original['id']]) as $o) {
                $origLines[] = $o;
                $creditLeft[(int) $o['line_no']] = (int) $o['doc_qty'] - $this->invoices->creditedUnits((int) $original['document_id'], (int) $o['line_no']);
            }
        }
        foreach ($n['lines'] as $l) {
            $idx = (int) $l['idx'];
            $amountE2 = $sign * InvoiceMath::e2((string) $l['net_amount']);
            if ((int) $l['is_stock'] !== 1 || self::isZero((string) $l['stock_qty'])) {
                if ($amountE2 === 0) {
                    continue;
                }
                $lines[] = ['kind' => $amountE2 < 0 ? 'other' : (preg_match(self::FREIGHT_WORDS, $l['item_code'] . ' ' . ($l['item_name'] ?? '')) === 1 ? 'freight' : 'service'),
                    'amount' => InvoiceMath::fromE2($amountE2), 'description' => self::words(($l['item_name'] ?? '') !== '' ? (string) $l['item_name'] : (string) $l['item_code'])];
                $sumE2 += $amountE2;
                continue;
            }
            $r = $this->resolve($n, $l);
            if (isset($r['problem'])) {
                return $r + ['line' => $idx];
            }
            if (bccomp((string) $l['net_rate'], '0', 6) < 0) {
                return ['problem' => 'bad_price', 'line' => $idx, 'detail' => (string) $l['net_rate']];
            }
            // Packs of the ERPNext purchase unit when they are whole packs of CW units; else CW units at the row's unit price.
            $qty = self::absDec((string) $l['qty']);
            $upp = bcmul((string) $l['conversion_factor'], (string) $r['u'], 6);
            if (self::isWhole($qty) && self::isWhole($upp) && (int) $upp >= 1 && (int) $qty * (int) $upp === $r['units'] && (int) $upp <= PoMath::MAX_UNITS_PER_PACK) {
                $packs = (int) $qty;
                $perPack = (int) $upp;
                $price = self::round4(self::absDec((string) $l['net_rate']));
                $exact = true;
            } else {
                $packs = $r['units'];
                $perPack = 1;
                $price = self::round4(bcdiv(self::absDec((string) $l['net_amount']), (string) $r['units'], 8));
                $exact = false;
            }
            if ($packs < 1 || $packs > PoMath::MAX_PACKS) {
                return ['problem' => 'fractional_qty', 'line' => $idx, 'detail' => (string) $l['qty']];
            }
            $line = ['kind' => 'goods', 'sku_id' => $r['sku_id'], 'packs' => $packs, 'units_per_pack' => $perPack, 'pack_price' => $price,
                'purchase_unit' => mb_substr($exact && ($l['uom'] ?? '') !== '' ? (string) $l['uom'] : 'each', 0, 32), 'description' => null];
            if ($return) {
                $of = $this->creditOf($l, $r, $origLines, $creditLeft);
                if ($of === null) {
                    return ['problem' => 'return_unmatched', 'line' => $idx, 'detail' => (string) $l['item_code']];
                }
                $creditLeft[$of] -= $r['units'];
                $line['credit_of_line_no'] = $of;
                $line['entry'] = 'credit';
            }
            $lines[] = $line;
            $sumE2 += PoMath::lineAmountE2($packs, PoMath::e4($price));
            $resolved[$idx] = $r + ['line_no' => count($lines), 'exact' => $exact];
        }
        // ERPNext's own total of its rows, to the penny: the difference of the pack prices' rounding is an "other" line.
        $rowsE2 = $sign * InvoiceMath::e2((string) $n['net_total']);
        if ($rowsE2 !== $sumE2) {
            $lines[] = ['kind' => 'other', 'amount' => InvoiceMath::fromE2($rowsE2 - $sumE2), 'description' => 'Rounding to the ERPNext total'];
        }
        $chargesE2 = 0;
        foreach ($n['charges'] as $c) {
            $a = $sign * InvoiceMath::e2((string) $c['amount']);
            if ($a === 0) {
                continue;
            }
            $lines[] = ['kind' => $a < 0 ? 'other' : (preg_match(self::FREIGHT_WORDS, (string) $c['description']) === 1 ? 'freight' : 'service'),
                'amount' => InvoiceMath::fromE2($a), 'description' => self::words((string) $c['description'])];
            $chargesE2 += $a;
        }
        $netE2 = $rowsE2 + $chargesE2;
        $vatE2 = $sign * InvoiceMath::e2((string) $n['vat_total']);
        if ($netE2 < 0 || $vatE2 < 0) {
            return ['problem' => 'taxes_unclear', 'detail' => 'net ' . InvoiceMath::fromE2($netE2) . ', VAT ' . InvoiceMath::fromE2($vatE2)];
        }
        if ($lines === []) {
            return ['problem' => 'refused', 'detail' => 'no rows'];
        }
        $date = (string) ($n['bill_date'] ?? $n['posting_date']);
        $number = trim((string) ($n['bill_no'] ?? ''));
        $header = ['invoice_number' => mb_substr($number === '' ? (string) $n['name'] : $number, 0, SupplierInvoices::NUMBER_MAX), 'invoice_date' => $date,
            'net_total' => InvoiceMath::fromE2($netE2), 'vat_total' => InvoiceMath::fromE2($vatE2), 'note' => 'ERPNext ' . $n['name']];
        if ($return) {
            $header['credits_id'] = (int) $original['document_id'];
        } elseif ($n['due_date'] !== null && $n['due_date'] >= $date) {
            $header['due_date'] = $n['due_date'];
        }
        return ['header' => $header, 'lines' => $lines, 'resolved' => $resolved];
    }

    /**
     * The CW item and units of one stock row (EC3), or ['problem' => code, 'detail' => ...]:
     *   1. the relay line already booked for it (Vape and Go, goods_in or supplier_return, doc_ref = the ERPNext name, line_index = its place
     *      among the invoice's stock rows): its item; the units per ERPNext stock unit from the item's link (sku_erp_item, else the Vape and Go
     *      listing) when it names that item, else from what the relay booked; units_differ when |stock_qty| x u is not what the relay booked
     *      (the relay counts the PURCHASE unit: a box bought as 1 is 1 x u there);
     *   2. else the ERPNext item's CW link (sku_erp_item) x its units_per_item;
     *   3. else the Vape and Go listing of the item's variant (Item.custom_variant_id, else its code, as the ERPNext add-on sends it) x its
     *      units_per_item (linked or quarantined, as the relay takes it).
     *
     * @param array<string, mixed> $n
     * @param array<string, mixed> $l
     * @return array{sku_id: int, u: int, units: int, via: string, relay_line_id: ?int, relay_units: ?int}|array{problem: string, detail?: string}
     */
    private function resolve(array $n, array $l): array
    {
        $stockQty = self::absDec((string) $l['stock_qty']);
        $code = (string) $l['item_code'];
        $variant = ($l['variant_id'] ?? null) !== null && $l['variant_id'] !== '' ? (string) $l['variant_id'] : $code;
        $link = $this->db->one('SELECT e.sku_id, e.units_per_item, s.merged_into_sku_id FROM sku_erp_item e JOIN sku s ON s.id = e.sku_id WHERE e.item_code = ?', [$code]);
        $listing = $this->channel() === null ? null : $this->db->one('SELECT l.sku_id, l.units_per_item, l.status FROM channel_listing l WHERE l.channel_id = ? '
            . 'AND l.external_variant_id = ?', [$this->channel(), mb_substr($variant, 0, 64)]);
        $relay = $this->channel() === null || $l['stock_index'] === null ? null : $this->db->one('SELECT id, CAST(outcome AS CHAR) AS outcome, sku_id, units FROM relay_line '
            . 'WHERE channel_id = ? AND kind = ? AND doc_ref = ? AND line_index = ?', [$this->channel(), (int) $n['is_return'] === 1 ? 'supplier_return' : 'goods_in',
                (string) $n['name'], (int) $l['stock_index']]);
        if ($relay !== null && $relay['outcome'] === 'booked' && $relay['sku_id'] !== null && $relay['units'] !== null) {
            $sku = (int) $relay['sku_id'];
            $booked = abs((int) $relay['units']);
            $u = match (true) {
                $link !== null && (int) $link['sku_id'] === $sku => (int) $link['units_per_item'],
                $listing !== null && (int) ($listing['sku_id'] ?? 0) === $sku => (int) $listing['units_per_item'],
                default => null,
            };
            if ($u === null) {
                // No link names the relay's item now: its units per ERPNext unit are known only from what the relay booked, and only when the
                // purchase unit is the stock unit (the relay counts the purchase unit; the Codex review of 0ccc04c, MEDIUM 5).
                $qty = self::absDec((string) $l['qty']);
                $per = self::isZero($qty) ? '0' : bcdiv((string) $booked, $qty, 6);
                if (bccomp($qty, $stockQty, 6) !== 0 || !self::isWhole($per) || (int) $per < 1) {
                    return ['problem' => 'units_differ', 'detail' => "relay {$booked}"];
                }
                $u = (int) $per;
            }
            $units = bcmul($stockQty, (string) $u, 6);
            if (!self::isWhole($units)) {
                return ['problem' => 'fractional_qty', 'detail' => (string) $l['stock_qty']];
            }
            if ((int) $units !== $booked) {
                return ['problem' => 'units_differ', 'detail' => (int) $units . ' / relay ' . $booked];
            }
            return ['sku_id' => $sku, 'u' => $u, 'units' => (int) $units, 'via' => 'relay', 'relay_line_id' => (int) $relay['id'], 'relay_units' => $booked];
        }
        [$sku, $u, $via] = match (true) {
            $link !== null && $link['merged_into_sku_id'] === null => [(int) $link['sku_id'], (int) $link['units_per_item'], 'erp_item'],
            $listing !== null && in_array($listing['status'], ['mapped', 'quarantined'], true) && $listing['sku_id'] !== null
                => [(int) $listing['sku_id'], (int) $listing['units_per_item'], 'listing'],
            default => [null, null, null],
        };
        if ($sku === null || $u < 1) {
            return ['problem' => 'no_item', 'detail' => mb_substr($code . ($variant !== $code ? " (variant {$variant})" : ''), 0, 255)];
        }
        if ($this->db->value('SELECT 1 FROM sku WHERE id = ? AND merged_into_sku_id IS NULL', [$sku]) === null) {
            return ['problem' => 'no_item', 'detail' => mb_substr($code . ' (merged item)', 0, 255)];
        }
        $units = bcmul($stockQty, (string) $u, 6);
        if (!self::isWhole($units) || (int) $units < 1) {
            return ['problem' => 'fractional_qty', 'detail' => (string) $l['stock_qty']];
        }
        return ['sku_id' => $sku, 'u' => $u, 'units' => (int) $units, 'via' => $via, 'relay_line_id' => null, 'relay_units' => null];
    }

    /**
     * The line of the copy of the invoice a debit note's row credits: the row it names (ERPNext's purchase_invoice_item), else the first row
     * of the same item with enough units left to credit; null when none.
     *
     * @param array<string, mixed> $l
     * @param array{sku_id: int, units: int} $r
     * @param list<array<string, mixed>> $origLines
     * @param array<int, int> $left
     */
    private function creditOf(array $l, array $r, array $origLines, array $left): ?int
    {
        foreach ([true, false] as $byRow) {
            foreach ($origLines as $o) {
                $no = (int) $o['line_no'];
                if ((int) $o['sku_id'] !== $r['sku_id'] || ($left[$no] ?? 0) < $r['units']) {
                    continue;
                }
                if (!$byRow || (($l['return_of'] ?? null) !== null && $o['row_name'] === $l['return_of'])) {
                    return $no;
                }
            }
        }
        return null;
    }

    /**
     * The supplier's prices of a copied invoice (EC13): for every goods line whose item and units are certain (the relay booked it in those
     * units, or the ERPNext item is linked to it) and whose pack is a whole ERPNext purchase unit, an `invoice` price of the supplier item
     * (supplier, item, pack; made when there is none: an alternative supply, never preferred) on the invoice's date, the copy named; the
     * last price moves when it is the newest (SupplierItems' rule, S4). Returns how many prices were recorded.
     *
     * @param array<int, array<string, mixed>> $resolved idx => resolve() + line_no, exact
     * @param list<array<string, mixed>> $lines normalise()'s rows
     */
    private function prices(int $supplierId, int $documentId, string $on, string $erpName, array $resolved, array $lines): int
    {
        $byIdx = [];
        foreach ($lines as $l) {
            $byIdx[(int) $l['idx']] = $l;
        }
        $n = 0;
        $now = $this->nowDb();
        $actor = self::actor();
        foreach ($resolved as $idx => $r) {
            if (!$r['exact'] || !in_array($r['via'], ['relay', 'erp_item'], true)) {
                continue;
            }
            $l = $byIdx[$idx];
            $upp = (int) bcmul((string) $l['conversion_factor'], (string) $r['u'], 0);
            $price = self::round4(self::absDec((string) $l['net_rate']));
            $si = $this->db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND units_per_pack = ? FOR UPDATE', [$supplierId, $r['sku_id'], $upp]);
            if ($si === null) {
                $siId = $this->db->insert('INSERT INTO supplier_item (supplier_id, sku_id, purchase_unit, units_per_pack, is_preferred, is_active, version, created_by, '
                    . 'created_actor, created_at, updated_by, updated_actor, updated_at) VALUES (?, ?, ?, ?, 0, 1, 1, NULL, ?, ?, NULL, ?, ?)',
                    [$supplierId, $r['sku_id'], mb_substr(($l['uom'] ?? '') !== '' ? (string) $l['uom'] : 'each', 0, SupplierItems::PURCHASE_UNIT_MAX), $upp, $actor, $now,
                        $actor, $now]);
                Audit::write($this->db, $this->job, 'supplier_item.create', 'supplier_item', (string) $siId, null, ['supplier_id' => $supplierId, 'sku_id' => $r['sku_id'],
                    'units_per_pack' => $upp, 'from_erpnext' => $erpName]);
                $si = (array) $this->db->one('SELECT * FROM supplier_item WHERE id = ? FOR UPDATE', [$siId]);
            }
            $siId = (int) $si['id'];
            $unit = SupplierItems::unitPrice($price, $upp);
            $priceId = $this->db->insert('INSERT INTO supplier_item_price (supplier_item_id, pack_price, units_per_pack, unit_price, source, source_ref, document_id, effective_on, '
                . "recorded_by, recorded_actor, recorded_at) VALUES (?, ?, ?, ?, 'invoice', ?, ?, ?, NULL, ?, ?)",
                [$siId, $price, $upp, $unit, mb_substr($erpName, 0, 191), $documentId, $on, $actor, $now]);
            $last = $si['last_price_on'] === null || $on >= (string) $si['last_price_on'];
            if ($last) {
                $this->db->exec("UPDATE supplier_item SET last_pack_price = ?, last_price_on = ?, last_price_source = 'invoice', version = version + 1, updated_by = NULL, "
                    . 'updated_actor = ?, updated_at = ? WHERE id = ?', [$price, $on, $actor, $now, $siId]);
            }
            Audit::write($this->db, $this->job, 'supplier_item.price', 'supplier_item', (string) $siId, null, ['price_id' => $priceId, 'pack_price' => $price,
                'unit_price' => $unit, 'effective_on' => $on, 'source' => 'invoice', 'source_ref' => $erpName, 'last_price' => $last]);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------------------------------
    // What the relay booked since a copy was made
    // ------------------------------------------------------------------------------------------

    /**
     * The copies with stock rows the relay had not booked when they were made, newest first (at most RECHECK_MAX): a row the relay booked
     * since, for the same item, gets what it booked (relay_line_id, relay_units), and the copy's match is worked out again in the same
     * transaction. A dry run counts them only. Returns how many copies changed.
     */
    public function relayRecheck(bool $apply): int
    {
        if ($this->channel() === null) {
            return 0;
        }
        $changed = 0;
        // Only the copies the relay has booked a row of since (the join on relay_line's key): none is left out behind others that wait (the
        // Codex review of 0ccc04c, MEDIUM 7).
        foreach ($this->db->column("SELECT DISTINCT p.id FROM erp_pi p JOIN erp_pi_line l ON l.erp_pi_id = p.id JOIN relay_line r ON r.channel_id = ? "
            . "AND r.kind = IF(p.is_return = 1, 'supplier_return', 'goods_in') AND r.doc_ref = p.name AND r.line_index = l.stock_index AND r.outcome = 'booked' "
            . "AND r.sku_id = l.sku_id WHERE p.state = 'copied' AND l.line_no IS NOT NULL AND l.relay_units IS NULL ORDER BY p.id LIMIT " . self::RECHECK_MAX,
            [$this->channel()]) as $pid) {
            $did = $this->db->transaction(function (Db $db) use ($pid, $apply): bool {
                $p = $db->one("SELECT * FROM erp_pi WHERE id = ? AND state = 'copied' FOR UPDATE", [(int) $pid]);
                if ($p === null) {
                    return false;
                }
                $any = false;
                foreach ($db->all('SELECT * FROM erp_pi_line WHERE erp_pi_id = ? AND line_no IS NOT NULL AND sku_id IS NOT NULL AND relay_units IS NULL AND stock_index IS NOT NULL',
                    [(int) $pid]) as $l) {
                    $r = $db->one("SELECT id, sku_id, units FROM relay_line WHERE channel_id = ? AND kind = ? AND doc_ref = ? AND line_index = ? AND outcome = 'booked'",
                        [$this->channel(), (int) $p['is_return'] === 1 ? 'supplier_return' : 'goods_in', (string) $p['name'], (int) $l['stock_index']]);
                    if ($r === null || (int) $r['sku_id'] !== (int) $l['sku_id'] || $r['units'] === null) {
                        continue;
                    }
                    if ($apply) {
                        $db->exec('UPDATE erp_pi_line SET relay_line_id = ?, relay_units = ? WHERE erp_pi_id = ? AND idx = ?', [(int) $r['id'], abs((int) $r['units']), (int) $pid,
                            (int) $l['idx']]);
                    }
                    $any = true;
                }
                if ($any && $apply && (int) $p['is_return'] === 0) {
                    (new InvoiceMatch($db))->refresh([(int) $p['document_id']], $this->nowDb());
                }
                return $any;
            });
            $changed += $did ? 1 : 0;
        }
        return $changed;
    }

    // ------------------------------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------------------------------

    /**
     * The "From ERPNext" view: the invoices waiting or with a problem first (oldest first), then the newest copied, cancelled and skipped
     * ones; at most LIST_LIMIT.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $show = 'waiting'): array
    {
        $where = match ($show) {
            'copied' => "p.state = 'copied'",
            'reversed' => "p.state = 'reversed'",
            'skipped' => "p.state = 'skipped'",
            default => "(p.state = 'waiting' OR (p.problem IS NOT NULL AND p.state <> 'skipped'))",
        };
        return $this->db->all('SELECT p.id, p.name, p.supplier, p.supplier_name, p.bill_no, p.bill_date, p.posting_date, p.is_return, p.return_against, p.docstatus, '
            . 'p.net_total, p.vat_total, p.grand_total, p.state, p.problem, p.problem_line, p.problem_detail, p.document_id, p.state_at, p.read_at, d.number, d.doc_type, '
            . 's.name AS cw_supplier FROM erp_pi p LEFT JOIN document d ON d.id = p.document_id LEFT JOIN supplier s ON s.id = p.supplier_id WHERE ' . $where
            . ($show === 'waiting' ? ' ORDER BY p.posting_date, p.id' : ' ORDER BY p.state_at DESC, p.id DESC') . ' LIMIT ' . self::LIST_LIMIT);
    }

    /** @return array<string, int> state => how many (waiting counts those with a problem too) */
    public function counts(): array
    {
        $out = array_fill_keys(self::STATES, 0);
        foreach ($this->db->all('SELECT state, COUNT(*) AS n FROM erp_pi GROUP BY state') as $r) {
            $out[(string) $r['state']] = (int) $r['n'];
        }
        return $out;
    }

    /** An erp_pi row as normalise() gives it (to try a waiting invoice again without reading ERPNext). @return array<string, mixed>|null */
    public function stored(string $name): ?array
    {
        $p = $this->db->one('SELECT * FROM erp_pi WHERE name = ?', [$name]);
        if ($p === null) {
            return null;
        }
        $charges = json_decode((string) ($p['charges'] ?? '[]'), true);
        $lines = [];
        foreach ($this->db->all('SELECT * FROM erp_pi_line WHERE erp_pi_id = ? ORDER BY idx', [(int) $p['id']]) as $l) {
            $lines[] = ['idx' => (int) $l['idx'], 'row_name' => $l['row_name'], 'item_code' => (string) $l['item_code'], 'item_name' => $l['item_name'],
                'is_stock' => (int) $l['is_stock'], 'stock_index' => $l['stock_index'] === null ? null : (int) $l['stock_index'], 'variant_id' => $l['variant_id'],
                'qty' => (string) $l['qty'], 'stock_qty' => (string) $l['stock_qty'], 'uom' => $l['uom'], 'conversion_factor' => (string) $l['conversion_factor'],
                'net_rate' => (string) $l['net_rate'], 'net_amount' => (string) $l['net_amount'], 'return_of' => $l['return_of']];
        }
        return ['name' => (string) $p['name'], 'company' => (string) $p['company'], 'supplier' => (string) $p['supplier'], 'supplier_name' => $p['supplier_name'],
            'bill_no' => $p['bill_no'], 'bill_date' => $p['bill_date'], 'posting_date' => (string) $p['posting_date'], 'due_date' => $p['due_date'],
            'is_return' => (int) $p['is_return'], 'return_against' => $p['return_against'], 'amended_from' => $p['amended_from'], 'docstatus' => (int) $p['docstatus'],
            'modified' => substr((string) $p['erp_modified'], 0, 26), 'net_total' => (string) $p['net_total'], 'vat_total' => (string) $p['vat_total'],
            'grand_total' => (string) $p['grand_total'], 'charges' => is_array($charges) ? $charges : [],
            // What normalise() found wrong with its taxes is kept as its problem (it is part of its hash): a retry never forgets it.
            'taxes_problem' => $p['problem'] === 'taxes_unclear' ? (string) ($p['problem_detail'] ?? 'taxes') : null, 'lines' => $lines,
            'hash' => (string) $p['content_hash']];
    }

    // ------------------------------------------------------------------------------------------
    // ERPNext's invoice in CW's terms
    // ------------------------------------------------------------------------------------------

    /**
     * One ERPNext Purchase Invoice (as ErpApiReader::invoice() gives it) in the copy's terms: its header (GBP amounts: ERPNext's base_*), its
     * rows (stock or not by $items: ERPNext's Item.is_stock_item, and each stock row's place among the stock rows: the relay's line index),
     * its taxes split into VAT (rows whose account or description says VAT) and the other charges that add to its total, and the hash of what
     * a copy is made from (not its status, not the names shown, not what ERPNext's items say). A tax row that deducts, or totals that do not
     * add up, leave `taxes_problem` set.
     *
     * @param array<string, mixed> $doc
     * @param array<string, array{is_stock_item: bool, custom_variant_id: ?string}> $items
     * @return array<string, mixed>
     */
    public static function normalise(array $doc, array $items): array
    {
        $str = static fn (mixed $v, int $max = 140): ?string => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
        $date = static function (mixed $v): ?string {
            $v = is_string($v) ? substr(trim($v), 0, 10) : '';
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
        };
        $name = $str($doc['name'] ?? null) ?? throw new CwException('erp_read_failed', 'an ERPNext purchase invoice without its name', 502);
        $posting = $date($doc['posting_date'] ?? null) ?? throw new CwException('erp_read_failed', "ERPNext's purchase invoice {$name} has no posting date", 502);
        $lines = [];
        $stock = 0;
        foreach (array_values((array) ($doc['items'] ?? [])) as $i => $it) {
            if (!is_array($it)) {
                continue;
            }
            $code = $str($it['item_code'] ?? null) ?? '';
            $info = $items[$code] ?? ['is_stock_item' => false, 'custom_variant_id' => null];
            $isStock = $info['is_stock_item'] ? 1 : 0;
            $lines[] = ['idx' => (int) ($it['idx'] ?? $i + 1), 'row_name' => $str($it['name'] ?? null), 'item_code' => $code, 'item_name' => $str($it['item_name'] ?? null, 255),
                'is_stock' => $isStock, 'stock_index' => $isStock === 1 ? $stock++ : null, 'variant_id' => $info['custom_variant_id'],
                'qty' => self::dec($it['qty'] ?? 0, 6), 'stock_qty' => self::dec($it['stock_qty'] ?? ($it['qty'] ?? 0), 6), 'uom' => $str($it['uom'] ?? null),
                'conversion_factor' => self::dec($it['conversion_factor'] ?? 1, 9), 'net_rate' => self::dec($it['base_net_rate'] ?? ($it['net_rate'] ?? 0), 6),
                'net_amount' => self::dec($it['base_net_amount'] ?? ($it['net_amount'] ?? 0), 2), 'return_of' => $str($it['purchase_invoice_item'] ?? null)];
        }
        usort($lines, static fn (array $a, array $b): int => $a['idx'] <=> $b['idx']);
        // The stock rows' places follow ERPNext's row order (idx), as the add-on sends them.
        $stock = 0;
        foreach ($lines as $k => $l) {
            $lines[$k]['stock_index'] = $l['is_stock'] === 1 ? $stock++ : null;
        }
        $vatE2 = 0;
        $charges = [];
        $problem = null;
        foreach (array_values((array) ($doc['taxes'] ?? [])) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $amountE2 = InvoiceMath::e2(self::dec($t['base_tax_amount_after_discount_amount'] ?? ($t['base_tax_amount'] ?? ($t['tax_amount'] ?? 0)), 2));
            if (($t['add_deduct_tax'] ?? 'Add') === 'Deduct') {
                $problem ??= 'a tax row deducts';
                $amountE2 = -$amountE2;
            }
            if (!in_array((string) ($t['category'] ?? 'Total'), ['Total', 'Valuation and Total'], true)) {
                continue; // a row for the value only is not in the supplier's total, VAT or not (the Codex review of 0ccc04c, MEDIUM 8)
            }
            $words = trim((string) ($t['account_head'] ?? '') . ' ' . (string) ($t['description'] ?? ''));
            if (preg_match('/\bVAT\b/i', $words) === 1) {
                $vatE2 += $amountE2;
            } else {
                $charges[] = ['description' => mb_substr($str($t['description'] ?? null, 255) ?? $str($t['account_head'] ?? null, 255) ?? 'Charge', 0, 255),
                    'amount' => InvoiceMath::fromE2($amountE2)];
            }
        }
        $net = self::dec($doc['base_net_total'] ?? ($doc['net_total'] ?? 0), 2);
        $grand = self::dec($doc['base_grand_total'] ?? ($doc['grand_total'] ?? 0), 2);
        $chargesE2 = array_sum(array_map(static fn (array $c): int => InvoiceMath::e2($c['amount']), $charges));
        // To the penny: the copy's total is its net + VAT, and must be ERPNext's (the Codex review of 0ccc04c, LOW 9).
        if (InvoiceMath::e2($net) + $vatE2 + $chargesE2 !== InvoiceMath::e2($grand)) {
            $problem ??= 'the rows, the VAT and the charges do not add up to its total';
        }
        $n = ['name' => $name, 'company' => $str($doc['company'] ?? null) ?? '', 'supplier' => $str($doc['supplier'] ?? null) ?? '', 'supplier_name' => $str($doc['supplier_name'] ?? null),
            'bill_no' => $str($doc['bill_no'] ?? null), 'bill_date' => $date($doc['bill_date'] ?? null), 'posting_date' => $posting, 'due_date' => $date($doc['due_date'] ?? null),
            'is_return' => (int) ($doc['is_return'] ?? 0) === 1 ? 1 : 0, 'return_against' => $str($doc['return_against'] ?? null),
            'amended_from' => $str($doc['amended_from'] ?? null), 'docstatus' => (int) ($doc['docstatus'] ?? 0) === 2 ? 2 : 1,
            'modified' => ErpApiReader::modified((string) ($doc['modified'] ?? '1970-01-01 00:00:00')), 'net_total' => $net, 'vat_total' => InvoiceMath::fromE2($vatE2),
            'grand_total' => $grand, 'charges' => $charges, 'taxes_problem' => $problem, 'lines' => $lines];
        if ($n['supplier'] === '') {
            throw new CwException('erp_read_failed', "ERPNext's purchase invoice {$name} has no supplier", 502);
        }
        $n['hash'] = hash('sha256', Idempotency::canonicalJson(['company' => $n['company'], 'supplier' => $n['supplier'], 'bill_no' => $n['bill_no'],
            'bill_date' => $n['bill_date'], 'posting_date' => $n['posting_date'], 'due_date' => $n['due_date'], 'is_return' => $n['is_return'],
            'return_against' => $n['return_against'], 'amended_from' => $n['amended_from'], 'net_total' => $n['net_total'], 'vat_total' => $n['vat_total'],
            'grand_total' => $n['grand_total'], 'charges' => $charges, 'taxes_problem' => $problem,
            'lines' => array_map(static fn (array $l): array => [$l['idx'], $l['row_name'], $l['item_code'], $l['qty'], $l['stock_qty'], $l['uom'], $l['conversion_factor'],
                $l['net_rate'], $l['net_amount'], $l['return_of']], $lines)]));
        return $n;
    }

    /**
     * The log lines of a run (bin/copy_erp_invoices.php): one summary line, then each invoice that waits or was skipped (at most 50).
     *
     * @param array<string, mixed> $r run()
     * @return list<string>
     */
    public static function logLines(array $r): array
    {
        $c = $r['counts'];
        $problems = [];
        foreach ($r['problems'] as $code => $n) {
            $problems[] = "{$code}:{$n}";
        }
        $out = [sprintf('%sread=%d %s=%d waiting=%d reversed=%d skipped=%d unchanged=%d suppliers_%s=%d prices=%d retried=%d relay_rechecked=%d from=%s cursor=%s->%s%s%s',
            $r['dry_run'] ? 'DRY RUN (nothing written) ' : '', $r['read'], $r['dry_run'] ? 'would_copy' : 'copied', $c['copied'] ?? 0, $c['waiting'] ?? 0,
            $c['reversed'] ?? 0, $c['skipped'] ?? 0, $c['unchanged'] ?? 0, $r['dry_run'] ? 'would_create' : 'created', $r['suppliers_created'], $r['prices'], $r['retried'],
            $r['relay_rechecked'], $r['from'], $r['cursor_from'] ?? 'start', $r['cursor_to'] ?? 'start', $problems === [] ? '' : ' problems=' . implode(',', $problems),
            $r['stopped'] ? ' STOPPED (switched off while it ran)' : '')];
        $shown = 0;
        foreach ($r['outcomes'] as $o) {
            if (!in_array($o['outcome'], ['waiting', 'skipped'], true) || ($o['problem'] ?? null) === null) {
                continue;
            }
            if (++$shown > 50) {
                $out[] = '  ... and more: Purchases > Supplier invoices > From ERPNext lists them all';
                break;
            }
            $out[] = sprintf('  %s: %s (%s%s%s)', $o['name'], $o['outcome'], $o['problem'], ($o['line'] ?? null) !== null ? ', row ' . $o['line'] : '',
                ($o['detail'] ?? null) !== null ? ': ' . $o['detail'] : '');
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------------

    /** What was read, over what was kept, for an invoice that is not copied (its header, its hash, its lines). @param array<string, mixed> $n */
    private function keepContent(int $id, array $n): void
    {
        $this->db->exec('UPDATE erp_pi SET company = ?, supplier = ?, bill_no = ?, bill_date = ?, posting_date = ?, due_date = ?, is_return = ?, return_against = ?, '
            . 'amended_from = ?, net_total = ?, vat_total = ?, grand_total = ?, charges = CAST(? AS JSON), content_hash = ? WHERE id = ?',
            [$n['company'], $n['supplier'], $n['bill_no'], $n['bill_date'], $n['posting_date'], $n['due_date'], $n['is_return'], $n['return_against'],
                $n['amended_from'], $n['net_total'], $n['vat_total'], $n['grand_total'], Idempotency::json($n['charges']), $n['hash'], $id]);
        $this->db->exec('DELETE FROM erp_pi_line WHERE erp_pi_id = ?', [$id]);
        $this->writeLines($id, $n['lines']);
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(int $erpPiId, array $lines): void
    {
        foreach (array_chunk($lines, 200) as $chunk) {
            $params = [];
            foreach ($chunk as $l) {
                array_push($params, $erpPiId, $l['idx'], $l['row_name'], mb_substr((string) $l['item_code'], 0, 140), $l['item_name'], $l['is_stock'], $l['stock_index'],
                    $l['variant_id'], $l['qty'], $l['stock_qty'], $l['uom'], $l['conversion_factor'], $l['net_rate'], $l['net_amount'], $l['return_of']);
            }
            $this->db->exec('INSERT INTO erp_pi_line (erp_pi_id, idx, row_name, item_code, item_name, is_stock, stock_index, variant_id, qty, stock_qty, uom, conversion_factor, '
                . 'net_rate, net_amount, return_of) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')), $params);
        }
    }

    /**
     * @param array<string, mixed> $sum
     * @param array<string, mixed> $out
     * @param array<string, true> $wouldCopy
     */
    private function count(array &$sum, array $out, array &$wouldCopy): void
    {
        $o = (string) $out['outcome'];
        if ($o === 'copied') {
            $wouldCopy[(string) $out['name']] = true;
        }
        // A dry run rolls each invoice back: a debit note whose invoice this dry run would copy would be copied right after it.
        if ($sum['dry_run'] && $o === 'waiting' && in_array($out['problem'] ?? null, ['original_waiting', 'original_missing'], true)
            && isset($wouldCopy[(string) ($out['return_against'] ?? '')])) {
            $o = 'copied';
            $out['outcome'] = 'copied';
            $out['problem'] = null;
        }
        if ($o === 'unchanged' && in_array('reversed', $out['did'] ?? [], true)) {
            $o = 'reversed';
        }
        $sum['counts'][$o] = ($sum['counts'][$o] ?? 0) + 1;
        if (($out['problem'] ?? null) !== null) {
            $sum['problems'][$out['problem']] = ($sum['problems'][$out['problem']] ?? 0) + 1;
        }
        $sum['suppliers_created'] += in_array('supplier_created', $out['did'] ?? [], true) ? 1 : 0;
        $sum['prices'] += (int) ($out['prices'] ?? 0);
        $sum['outcomes'][] = $out;
    }

    /** @param array<string, mixed> $sum @return array<string, mixed> what the run row keeps */
    private static function summaryRow(array $sum): array
    {
        return ['read' => $sum['read'], 'counts' => $sum['counts'], 'problems' => $sum['problems'], 'suppliers_created' => $sum['suppliers_created'],
            'prices' => $sum['prices'], 'retried' => $sum['retried'], 'relay_rechecked' => $sum['relay_rechecked'], 'stopped' => $sum['stopped']];
    }

    private function channel(): ?int
    {
        if (!$this->channelRead) {
            $v = $this->db->value('SELECT id FROM channel WHERE code = ?', [self::CHANNEL]);
            $this->channelId = $v === null ? null : (int) $v;
            $this->channelRead = true;
        }
        return $this->channelId;
    }

    /** A number as ERPNext gives it (a JSON number or a numeric string) as a decimal string of $scale places (half away from zero). */
    public static function dec(mixed $v, int $scale): string
    {
        if (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/D', trim($v)) === 1) {
            $s = trim($v);
        } elseif (is_int($v) || is_float($v)) {
            $s = is_float($v) ? sprintf('%.' . ($scale + 3) . 'F', $v) : (string) $v;
        } else {
            $s = '0';
        }
        $neg = str_starts_with($s, '-');
        $half = '0.' . str_repeat('0', $scale) . '5';
        $r = $neg ? bcsub($s, $half, $scale) : bcadd($s, $half, $scale);
        return bccomp($r, '0', $scale) === 0 ? bcadd('0', '0', $scale) : $r;
    }

    private static function round4(string $v): string
    {
        return PoMath::fromE4((int) bcadd(bcmul($v, '10000', 6), '0.5', 0));
    }

    private static function absDec(string $v): string
    {
        return ltrim($v, '-');
    }

    private static function isWhole(string $v): bool
    {
        return bccomp($v, bcadd($v, '0', 0), 9) === 0;
    }

    private static function isZero(string $v): bool
    {
        return bccomp($v, '0', 9) === 0;
    }

    /** Words for a line's description: one line, at most SupplierInvoices::DESCRIPTION_MAX characters. */
    private static function words(string $v): string
    {
        $v = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));
        return mb_substr($v === '' ? 'Charge' : $v, 0, SupplierInvoices::DESCRIPTION_MAX);
    }

    private function nowDb(): string
    {
        return Clock::db(($this->clock)());
    }
}
