<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Files\FileStore;
use CW\Ui\Context;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * Stored files (`/ui/files/{id}`, documents.view) and the one way every download leaves the staff screens
 * (download(): stored files, document PDFs, the CSV lists).
 *
 * A download is an attachment (never shown inline: a stored file is someone else's content), named with an ASCII
 * fallback and its UTF-8 name (RFC 6266 filename*), and carries a second Content-Security-Policy, `sandbox`, enforced
 * together with the kernel's own (no script, no same-origin access even if a browser rendered it); Kernel::secure adds
 * X-Content-Type-Options nosniff and no-store. A stored file is re-hashed before it is sent (FileStore::read): one
 * that does not match its sha256 is a 500 page with the request id, logged, never served. A stored file is named
 * with the extension of its SNIFFED type (FileStore::downloadName: duty.hta sniffed as text is saved as duty.hta.txt),
 * and Unicode format characters (bidi overrides) never reach a file name (I36).
 */
final class FilesController
{
    public function show(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $refused = self::refusal($ctx, $id);
        if ($refused !== null) {
            return $refused;
        }
        $file = $ctx->files()->read($id);
        $mime = (string) $file['meta']['mime'];
        return self::download($file['bytes'], $mime, FileStore::downloadName((string) $file['meta']['original_name'], $mime));
    }

    /**
     * Why this person may not have stored file $id, as the page to answer with, or null when they may (I85, GV16, GV18, EX9, EX24). Every rule
     * is read in ONE moment of the database together with the file's own row, and a file that is not there is "no such file" before any rule
     * (the Codex check r2, H9: a request racing a store can never pass the rules before the file exists and read it after). The expenses'
     * download (ExpensesController::file) asks the same, so a file that is also a supplier's invoice copy needs invoices.view there too (r2, H8).
     */
    public static function refusal(Context $ctx, int $id): ?HtmlResponse
    {
        $me = $ctx->me();
        $why = \CW\Ops\Snapshot::read($ctx->db, static function (\CW\Db $db) use ($id, $me): ?array {
            $row = $db->one('SELECT kind FROM stored_file WHERE id = ?', [$id]);
            if ($row === null) {
                return [404, 'unknown_file', \CW\Ui\Words::error('unknown_file', 'there is no such file')];
            }
            $kind = (string) $row['kind'];
            // Scoped like the pages that link them (I85): a supplier's evidence needs suppliers.view; a supplier's invoice invoices.view; a file
            // attached only to purchase orders (the PDF as sent) needs purchasing.view. A supplier's evidence is one kept as one OR named as one
            // on a supplier (the same bytes kept first as another kind: one stored row, its first kind; the final review of 70ab3d2, astra
            // MEDIUM; docs/decisions.md EX49), so the rule never depends on which came first.
            if (!$me->can('suppliers.view') && ($kind === 'supplier_check'
                || $db->value('SELECT 1 FROM supplier WHERE dd_evidence_file_id = ? UNION ALL SELECT 1 FROM supplier WHERE import_route_file_id = ? LIMIT 1', [$id, $id]) !== null)) {
                return [403, 'role_not_allowed', Words::say('ERROR', 'file_suppliers', Words::whoCan('suppliers.view'))];
            }
            // A supplier's invoice shows its prices: the money permission's (invoices.view, not goods in; the Codex review of d18e699, GV16),
            // also when the same bytes were kept first as something else (one stored row, its first kind: GV18).
            if (!$me->can('invoices.view') && self::isInvoiceCopy($db, $id, $kind)) {
                return [403, 'role_not_allowed', Words::say('ERROR', 'file_invoices', Words::whoCan('invoices.view'))];
            }
            if (!$me->can('purchasing.view') && $db->value("SELECT 1 FROM document_file df JOIN document d ON d.id = df.document_id WHERE df.file_id = ? AND d.doc_type = 'PO' LIMIT 1", [$id]) !== null
                && $db->value("SELECT 1 FROM document_file df JOIN document d ON d.id = df.document_id WHERE df.file_id = ? AND d.doc_type <> 'PO' LIMIT 1", [$id]) === null) {
                return [403, 'role_not_allowed', Words::say('ERROR', 'file_orders', Words::whoCan('purchasing.view'))];
            }
            // An expense's invoice (0079, docs/decisions.md EX9) only for those who see the expenses (expenses.view), whatever else the file is:
            // kept as one, named by an expense now, or by any version of one (the Codex check r1, H11): expense_file holds every file any version
            // named, read by its file_id index (an ordinary download never reads the expenses' history: the final review of 70ab3d2, astra
            // MEDIUM; EX40).
            if (!$me->can(\CW\Expenses\ExpenseAccess::PERM_VIEW) && ($kind === \CW\Expenses\ExpenseFiles::KIND
                || $db->value('SELECT 1 FROM expense WHERE file_id = ? LIMIT 1', [$id]) !== null
                || $db->value('SELECT 1 FROM expense_file WHERE file_id = ? LIMIT 1', [$id]) !== null)) {
                return [403, 'role_not_allowed', Words::say('ERROR', 'file_expenses', Words::whoCan(\CW\Expenses\ExpenseAccess::PERM_VIEW))];
            }
            return null;
        });
        return $why === null ? null : $ctx->error($why[0], $why[1], $why[2]);
    }

    /**
     * Whether a stored file is a supplier's invoice copy: kept as one, or attached as one to any record (the same bytes kept first as a
     * photo stay one row of kind photo, GV18). The download and every list that links a file use this one rule.
     */
    public static function isInvoiceCopy(\CW\Db $db, int $fileId, ?string $kind): bool
    {
        return $kind === 'supplier_invoice'
            || $db->value("SELECT 1 FROM document_file WHERE file_id = ? AND role = 'supplier_invoice' LIMIT 1", [$fileId]) !== null;
    }

    /** A hardened download (class docblock). */
    public static function download(string $bytes, string $contentType, string $filename): HtmlResponse
    {
        return (new HtmlResponse(200, $bytes, $contentType))
            ->withHeader('Content-Disposition', self::disposition($filename))
            ->withHeader('Content-Security-Policy', 'sandbox');
    }

    /** `attachment; filename="<ASCII>"; filename*=UTF-8''<percent-encoded UTF-8>` of a cleaned file name. */
    public static function disposition(string $filename): string
    {
        $name = FileStore::cleanName($filename);
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._ ()+-]+/', '_', $name), ' ');
        if ($ascii === '' || trim($ascii, '_.') === '') {
            $ascii = 'download';
        }
        return 'attachment; filename="' . $ascii . "\"; filename*=UTF-8''" . rawurlencode($name);
    }
}
