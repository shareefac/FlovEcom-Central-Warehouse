<?php

declare(strict_types=1);

namespace CW\Arrivals;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Invoices\SupplierInvoices;

/**
 * The timed postings of supplier invoices (pack J, docs/decisions.md PJ10, PJ11; bin/submit_invoices.php, every minute): every draft
 * invoice whose time (supplier_invoice.submit_at, set by a person) has come and whose last attempt did not fail is made final through
 * SupplierInvoices::submit, as the person who set the time (their roles checked again then), oldest time first.
 *
 *  - Once: the due ones are read without locks, then each goes through the posting, which locks the draft and checks its version;
 *    a draft changed, re-timed, stopped or made final meanwhile (by a person, or an overlapping run) is skipped, not counted as a
 *    failure (the failure is recorded only on the version and time the run read). The posting clears the time in its own
 *    transaction. Runs do not overlap (the job's named lock, Ops\Cli).
 *  - A failure is kept on the invoice (submit_failed_at, submit_error: the refusal in words) and audited (invoice.submit_failed); it is
 *    listed with the timed invoices, the alerts name it (Ops\Alerts: invoice_submit_failed) and it is not tried again until a person
 *    sets the time again (or makes it final, or stops it).
 */
final class InvoiceSchedule
{
    public const BATCH = 100;

    /** @param (\Closure(): FileStore)|null $files */
    public function __construct(private readonly Db $db, private readonly ?\Closure $files = null)
    {
    }

    /**
     * Makes final the invoices due at $now. $onError($id, $e): told of each failure (the job logs it).
     *
     * @return array{posted: list<int>, failed: list<int>, skipped: list<int>}
     */
    public function runDue(\DateTimeImmutable $now, int $limit = self::BATCH, ?\Closure $onError = null): array
    {
        $due = $this->db->all('SELECT si.document_id, si.submit_by, si.submit_at, d.version FROM supplier_invoice si JOIN document d ON d.id = si.document_id AND d.status = ? '
            . 'WHERE si.submit_at IS NOT NULL AND si.submit_at <= ? AND si.submit_failed_at IS NULL ORDER BY si.submit_at, si.document_id LIMIT ' . max(1, $limit),
            ['draft', Clock::db($now)]);
        $out = ['posted' => [], 'failed' => [], 'skipped' => []];
        foreach ($due as $r) {
            $id = (int) $r['document_id'];
            $by = (int) $r['submit_by'];
            $invoices = new SupplierInvoices($this->db, new Documents($this->db, DocumentHandlers::all($this->db)), $this->files);
            try {
                $doc = $invoices->submit(Caller::staff($by), $id, (int) $r['version']);
                $out['posted'][] = $id;
                if ($doc->status === 'awaiting_approval') {
                    // Sent to a reviewer for its OK first (an approval rule is on): what a person's posting does too; the time is used up.
                    $this->db->exec('UPDATE supplier_invoice SET submit_at = NULL, submit_by = NULL, submit_actor = NULL WHERE document_id = ?', [$id]);
                }
            } catch (\Throwable $e) {
                // Review finding 4 (Codex, 15 Oct): the failure is this attempt's only while the draft is still the version it read and
                // keeps the time it read (both checked under the draft's lock): one changed, re-timed, stopped or made final meanwhile is
                // skipped, its new time left alone.
                $why = $e instanceof CwException ? $e->getMessage() : 'the posting failed: ' . get_class($e);
                $kept = $this->db->transaction(function (Db $db) use ($id, $r, $now, $why, $e): bool {
                    $state = $db->one('SELECT d.status, d.version, si.submit_at, si.submit_failed_at FROM document d JOIN supplier_invoice si ON si.document_id = d.id '
                        . 'WHERE d.id = ? FOR UPDATE', [$id]);
                    if ($state === null || $state['status'] !== 'draft' || (int) $state['version'] !== (int) $r['version'] || $state['submit_at'] !== $r['submit_at']
                        || $state['submit_failed_at'] !== null) {
                        return false;
                    }
                    $db->exec('UPDATE supplier_invoice SET submit_failed_at = ?, submit_error = ? WHERE document_id = ?', [Clock::db($now), mb_substr($why, 0, 500), $id]);
                    Audit::write($db, Caller::system('submit_invoices'), 'invoice.submit_failed', 'document', (string) $id, null,
                        ['error' => $e instanceof CwException ? $e->errorCode : get_class($e), 'message' => mb_substr($why, 0, 500)]);
                    return true;
                });
                if (!$kept) {
                    $out['skipped'][] = $id; // changed, re-timed, stopped or made final meanwhile
                    continue;
                }
                $out['failed'][] = $id;
                if ($onError !== null) {
                    $onError($id, $e);
                }
            }
        }
        return $out;
    }

    /**
     * The timed invoices whose last attempt failed (the alerts' problem).
     *
     * @return list<array{id: int, external_ref: ?string, failed_at: string}>
     */
    public static function failed(Db $db): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['document_id'], 'external_ref' => $r['external_ref'], 'failed_at' => (string) $r['submit_failed_at']],
            $db->all("SELECT si.document_id, d.external_ref, si.submit_failed_at FROM supplier_invoice si JOIN document d ON d.id = si.document_id AND d.status = 'draft' "
                . 'WHERE si.submit_failed_at IS NOT NULL ORDER BY si.submit_failed_at, si.document_id LIMIT 50'));
    }
}
