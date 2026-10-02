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
        $file = $ctx->files()->read($ctx->id());
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
