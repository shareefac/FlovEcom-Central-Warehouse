<?php

declare(strict_types=1);

namespace CW\Ui;

/**
 * The two static files of the UI (public/ui/assets/app.css, app.js), served by the front controller
 * so no other file of public/ can ever be reached. Same-origin only (the CSP allows nothing else).
 *
 * The layout links them with their version (url(): `?v=` the start of the content's hash), and that URL is
 * cached by the browser for a year (`immutable`): a page view asks for neither file again, and a deploy that
 * changes a file changes its URL, so it still shows at once. Any other request (no `v`, or the `v` of an older
 * file) is revalidated with the ETag as before, also when Apache's compression added "-gzip" to it.
 */
final class Assets
{
    private const FILES = ['app.css' => 'text/css; charset=utf-8', 'app.js' => 'text/javascript; charset=utf-8'];
    /** The versioned URL never changes its content: a year, and the browser need not ask again (RFC 8246). */
    public const IMMUTABLE = 'public, max-age=31536000, immutable';
    /** Length of the version in the URL (hex characters of the content's sha256). */
    private const VERSION_LENGTH = 16;

    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/public/ui/assets';
    }

    /** The URL the layout links an asset by: its path and version (`/ui/assets/app.css?v=0123456789abcdef`). */
    public static function url(string $name): string
    {
        $hash = isset(self::FILES[$name]) ? self::hashOf(self::dir() . '/' . $name) : null;
        return '/ui/assets/' . $name . ($hash === null ? '' : '?v=' . substr($hash, 0, self::VERSION_LENGTH));
    }

    /** sha256 of a file's content, once per file and request; null when it cannot be read. */
    private static function hashOf(string $file): ?string
    {
        static $seen = [];
        if (!array_key_exists($file, $seen)) {
            $hash = is_file($file) ? hash_file('sha256', $file) : false;
            $seen[$file] = $hash === false ? null : $hash;
        }
        return $seen[$file];
    }

    /** The answer for one asset request, with the same hard headers as every page. */
    public static function serve(UiRequest $req): HtmlResponse
    {
        return Kernel::secure(self::respond($req), $req->secure);
    }

    private static function respond(UiRequest $req): HtmlResponse
    {
        if ($req->method !== 'GET' && $req->method !== 'HEAD') {
            return self::plain(405, 'method not allowed')->withHeader('Allow', 'GET, HEAD');
        }
        if (preg_match('#^/ui/assets/([a-z0-9]+\.(?:css|js))$#D', $req->path, $m) !== 1 || !isset(self::FILES[$m[1]])) {
            return self::plain(404, 'not found');
        }
        $file = self::dir() . '/' . $m[1];
        $body = is_file($file) ? file_get_contents($file) : false;
        if ($body === false) {
            return self::plain(404, 'not found');
        }
        $hash = hash('sha256', $body);
        $etag = '"' . substr($hash, 0, 32) . '"';
        // Only the URL of this very content may be kept for a year; an old or made-up version is revalidated.
        $cache = ($req->query['v'] ?? null) === substr($hash, 0, self::VERSION_LENGTH) ? self::IMMUTABLE : 'no-cache';
        $res = (new HtmlResponse(200, $body, self::FILES[$m[1]]))
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', $cache)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', Kernel::CSP);
        if (self::matches($req->header('if-none-match'), $etag)) {
            return (new HtmlResponse(304, '', self::FILES[$m[1]]))->withHeader('ETag', $etag)->withHeader('Cache-Control', $cache)
                ->withHeader('X-Content-Type-Options', 'nosniff');
        }
        return $res;
    }

    /**
     * Whether an If-None-Match names $etag: one tag or a list, weak (W/) or not, with or without the suffix Apache's mod_deflate
     * adds to the ETag of a compressed answer ("…-gzip", DeflateAlterETag AddSuffix; "-br" for mod_brotli). Without this a
     * browser's revalidation never matched and every page view downloaded both files again.
     */
    private static function matches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null) {
            return false;
        }
        foreach (explode(',', $ifNoneMatch) as $tag) {
            $tag = (string) preg_replace('/^W\//', '', trim($tag));
            if ($tag === $etag || preg_replace('/-(?:gzip|br)"$/D', '"', $tag) === $etag) {
                return true;
            }
        }
        return false;
    }

    private static function plain(int $status, string $text): HtmlResponse
    {
        return (new HtmlResponse($status, $text . "\n", 'text/plain; charset=utf-8'))
            ->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('Content-Security-Policy', Kernel::CSP);
    }
}
