<?php

declare(strict_types=1);

namespace CW\Ui;

/**
 * The two static files of the UI (public/ui/assets/app.css, app.js), served by the front controller
 * so no other file of public/ can ever be reached. Same-origin only (the CSP allows nothing else);
 * revalidated with an ETag so a deploy shows at once.
 */
final class Assets
{
    private const FILES = ['app.css' => 'text/css; charset=utf-8', 'app.js' => 'text/javascript; charset=utf-8'];

    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/public/ui/assets';
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
        $etag = '"' . substr(hash('sha256', $body), 0, 32) . '"';
        $res = (new HtmlResponse(200, $body, self::FILES[$m[1]]))
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', Kernel::CSP);
        if ($req->header('if-none-match') === $etag) {
            return (new HtmlResponse(304, '', self::FILES[$m[1]]))->withHeader('ETag', $etag)->withHeader('Cache-Control', 'no-cache')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        }
        return $res;
    }

    private static function plain(int $status, string $text): HtmlResponse
    {
        return (new HtmlResponse($status, $text . "\n", 'text/plain; charset=utf-8'))
            ->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('Content-Security-Policy', Kernel::CSP);
    }
}
