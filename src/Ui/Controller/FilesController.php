<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Files\FileStore;
use CW\Ui\Context;
use CW\Ui\HtmlResponse;

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
        $me = $ctx->me();
        // Scoped like the pages that link them (I85): a supplier's evidence needs suppliers.view; a file attached only to
        // purchase orders (the PDF as sent) needs purchasing.view.
        $kind = $ctx->db->value('SELECT kind FROM stored_file WHERE id = ?', [$id]);
        if ($kind === 'supplier_check' && !$me->can('suppliers.view')) {
            return $ctx->error(403, 'role_not_allowed', 'a supplier\'s evidence is shown to people with access to the suppliers');
        }
        if (!$me->can('purchasing.view') && $ctx->db->value("SELECT 1 FROM document_file df JOIN document d ON d.id = df.document_id WHERE df.file_id = ? AND d.doc_type = 'PO' LIMIT 1", [$id]) !== null
            && $ctx->db->value("SELECT 1 FROM document_file df JOIN document d ON d.id = df.document_id WHERE df.file_id = ? AND d.doc_type <> 'PO' LIMIT 1", [$id]) === null) {
            return $ctx->error(403, 'role_not_allowed', 'this file belongs to a purchase order: it is shown to people with access to Purchasing');
        }
        $file = $ctx->files()->read($id);
        $mime = (string) $file['meta']['mime'];
        return self::download($file['bytes'], $mime, FileStore::downloadName((string) $file['meta']['original_name'], $mime));
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
