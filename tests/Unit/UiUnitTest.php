<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Csrf;
use CW\Auth\LoginLimiter;
use CW\Auth\Permissions;
use CW\Auth\StaffIdentity;
use CW\ConfigException;
use CW\CwException;
use CW\Staff\SecretBox;
use CW\Ui\Assets;
use CW\Ui\Compare;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Kernel;
use CW\Ui\Route;
use CW\Ui\Router;
use CW\Ui\UiRequest;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/** The /ui pieces that need neither a database nor a web server. */
final class UiUnitTest extends TestCase
{
    private static function request(string $method = 'GET', string $path = '/ui/login', array $headers = [], bool $secure = false): UiRequest
    {
        return new UiRequest($method, $path, [], [], [], $headers, '127.0.0.1', $secure);
    }

    // ---- Html ------------------------------------------------------------------------------------------

    public function testEscapingIsTotalForTextAndQuotedAttributes(): void
    {
        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', Html::e('<script>alert(1)</script>'));
        self::assertSame('&quot;&gt; &lt;img src=x onerror=alert(1)&gt; &apos;&amp;', Html::e('"> <img src=x onerror=alert(1)> \'&'));
        self::assertSame('', Html::e(null));
        self::assertSame('', Html::e(false));
        self::assertSame('1', Html::e(true));
        self::assertSame('0', Html::e(0));
        self::assertSame('1.5', Html::e(1.5));
        self::assertSame('&amp;lt;', Html::e('&lt;'), 'no double-decoding loophole: an entity in the data is shown as typed');
        self::assertSame("a\u{FFFD}(b", Html::e("a\xC3\x28b"), 'invalid UTF-8 is substituted, never passed through and never dropping the whole string');
        self::assertSame('ünï ☃', Html::e('ünï ☃'));
        $this->expectException(\InvalidArgumentException::class);
        Html::e(['a']);
    }

    public function testNumbersPercentagesDatesAndDecimals(): void
    {
        self::assertSame('1,234,567', Html::int(1234567));
        self::assertSame('1,234', Html::int('1234'));
        self::assertSame('0', Html::int(0));
        self::assertSame('-', Html::int(null));
        self::assertSame('-', Html::int(''));
        self::assertSame('6', Html::dec('6.00'));
        self::assertSame('0.5', Html::dec('0.50'));
        self::assertSame('10', Html::dec('10'), 'no zeros stripped from an integer');
        self::assertSame('100', Html::dec('100.000'));
        self::assertSame('', Html::dec(null));
        self::assertSame('', Html::dec(''));
        self::assertSame('2026-09-30 16:41', Html::dt('2026-09-30 16:41:07.123456'));
        self::assertSame('', Html::dt(null));
        self::assertSame('', Html::dt(''));
        self::assertSame('75.0%', Html::pct(3, 4));
        self::assertSame('37.5%', Html::pct(60, 160));
        self::assertSame('0.0%', Html::pct(0, 5));
        self::assertSame('100.0%', Html::pct(5, 5));
        self::assertSame('-', Html::pct(0, 0), 'nothing sold: no percentage');
        self::assertSame('-', Html::pct(3, 0));
        self::assertSame('1,234.6%', Html::pct(12345.6, 1000));
    }

    public function testUrlsAreBuiltEncodedWithoutEmptyValues(): void
    {
        self::assertSame('/ui/review', Html::url('/ui/review'));
        self::assertSame('/ui/review?queue=Key', Html::url('/ui/review', ['queue' => 'Key', 'channel' => '', 'min' => null, 'x' => false]));
        self::assertSame('/ui/review?q=a%20b%26c%3Dd', Html::url('/ui/review', ['q' => 'a b&c=d']));
        self::assertSame('/ui/s?q=%3Cscript%3E', Html::url('/ui/s', ['q' => '<script>']));
        self::assertSame('/ui/review?min=0', Html::url('/ui/review', ['min' => 0]), 'zero is a value');
        self::assertSame('/ui/s?q=%C3%BC', Html::url('/ui/s', ['q' => 'ü']));
    }

    public function testOnlyPlainHttpLinksAreEverClickable(): void
    {
        self::assertSame('https://shop.example/p/1?a=b&c=d', Html::safeUrl('https://shop.example/p/1?a=b&c=d'));
        self::assertSame('http://shop.example/', Html::safeUrl('http://shop.example/'));
        foreach (['javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'data:text/html,<script>', 'vbscript:x', '//evil.test/', '/local', 'ftp://x.test/', ' https://x.test/', 'https://x.test/ y', 'https://x.test/"onmouseover="x', "https://x.test/'", 'https://x.test/<b>', "https://x.test/\nSet-Cookie: a=b", "https://x.test/\n", "https://x.test/\r\n", '', 'https://'] as $bad) {
            self::assertNull(Html::safeUrl($bad), $bad);
        }
        self::assertNull(Html::safeUrl(null));
    }

    public function testJsonAndListColumnsNeverBreakAPage(): void
    {
        self::assertSame(['a' => 1], Html::json('{"a":1}'));
        self::assertSame([], Html::json(null));
        self::assertSame([], Html::json(''));
        self::assertSame([], Html::json('{broken'));
        self::assertSame([], Html::json('"a string"'));
        self::assertSame([], Html::json('5'));
        self::assertSame(['a', '1', '2.5'], Html::strings(['a', 1, 2.5, null, ['x'], true, new \stdClass()]));
        self::assertSame([], Html::strings('x'));
        self::assertSame([], Html::strings(null));
    }

    // ---- HtmlResponse ----------------------------------------------------------------------------------

    public function testRedirectsStayOnThisSite(): void
    {
        $r = HtmlResponse::redirect('/ui/review?queue=Key&notice=x');
        self::assertSame(303, $r->status);
        self::assertSame('/ui/review?queue=Key&notice=x', $r->header('location'));
        self::assertSame(302, HtmlResponse::redirect('/ui/', 302)->status);
        foreach (['//evil.test/', 'https://evil.test/', 'ui/login', '', '/\\evil.test', "/ui/x\r\nSet-Cookie: a=b", "/ui/x\nX: y", '/ui\\x', 'javascript:alert(1)'] as $bad) {
            try {
                HtmlResponse::redirect($bad);
                self::fail('redirect accepted: ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testHeadersCannotBeInjected(): void
    {
        $r = new HtmlResponse(200, 'x');
        foreach ([["X-A", "b\r\nSet-Cookie: c=d"], ["X-A\r\nX-B", 'c'], ["X-A", "b\nc"]] as [$k, $v]) {
            try {
                $r->withHeader($k, $v);
                self::fail('header accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('text/html; charset=utf-8', $r->header('content-type'));
        self::assertNull($r->header('x-a'));
    }

    public function testCookiesAreHttpOnlySameSiteStrictAndSecureOnHttps(): void
    {
        $plain = (new HtmlResponse(200, ''))->withCookie('cw_session', 'abc-DEF_123', false, 1800)->headerValues('Set-Cookie')[0];
        self::assertSame('cw_session=abc-DEF_123; Path=/ui; HttpOnly; SameSite=Strict; Max-Age=1800', $plain);
        $https = (new HtmlResponse(200, ''))->withCookie('cw_session', 'abc', true)->headerValues('set-cookie')[0];
        self::assertSame('cw_session=abc; Path=/ui; HttpOnly; SameSite=Strict; Secure', $https);
        $gone = (new HtmlResponse(200, ''))->withoutCookie('cw_session', true)->headerValues('Set-Cookie')[0];
        self::assertSame('cw_session=; Path=/ui; HttpOnly; SameSite=Strict; Max-Age=0; Secure', $gone);
        $two = (new HtmlResponse(200, ''))->withCookie('a', '1', false)->withCookie('b', '2', false);
        self::assertCount(2, $two->headerValues('Set-Cookie'), 'Set-Cookie may repeat');
        foreach ([['a b', 'v'], ['a;b', 'v'], ['a', 'v;Domain=evil.test'], ['a', "v\r\nX: y"], ['a', 'v w'], ['a=', 'v'], ['', 'v'], ["a\n", 'v'], ['a', "v\n"]] as [$k, $v]) {
            try {
                (new HtmlResponse(200, ''))->withCookie($k, $v, false);
                self::fail('cookie accepted: ' . json_encode([$k, $v]));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    // ---- Kernel::secure and the failure pages ----------------------------------------------------------

    public function testEveryAnswerCarriesTheHardHeaders(): void
    {
        $r = Kernel::secure(new HtmlResponse(200, 'x'), false);
        self::assertSame("default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'", $r->header('Content-Security-Policy'));
        self::assertSame(Kernel::CSP, $r->header('Content-Security-Policy'));
        self::assertSame('nosniff', $r->header('X-Content-Type-Options'));
        self::assertSame('DENY', $r->header('X-Frame-Options'));
        self::assertSame('same-origin', $r->header('Referrer-Policy'), "'no-referrer' makes browsers post Origin: null (U24)");
        self::assertSame('same-origin', $r->header('Cross-Origin-Opener-Policy'));
        self::assertSame('same-origin', $r->header('Cross-Origin-Resource-Policy'));
        self::assertStringContainsString('camera=()', (string) $r->header('Permissions-Policy'));
        self::assertSame('no-store', $r->header('Cache-Control'));
        self::assertNull($r->header('Strict-Transport-Security'), 'HSTS only over HTTPS');
        $s = Kernel::secure(new HtmlResponse(200, 'x'), true);
        self::assertSame('max-age=31536000', $s->header('Strict-Transport-Security'));
        $cached = Kernel::secure((new HtmlResponse(200, 'x'))->withHeader('Cache-Control', 'no-cache'), false);
        self::assertSame(['no-cache'], $cached->headerValues('Cache-Control'), 'a page that chose its own caching keeps it');
    }

    public function testACorruptRouteOrNoDatabaseGivesAPlainPageNotATrace(): void
    {
        $logged = [];
        $kernel = new Kernel(
            static function (): never {
                throw new \PDOException('SQLSTATE[HY000] [2002] Connection refused to db-secret-host.example:25060');
            },
            static fn (): ?string => null,
            static function (string $m) use (&$logged): void {
                $logged[] = $m;
            },
        );
        $r = $kernel->handle(self::request('GET', '/ui/login'));
        self::assertSame(503, $r->status);
        self::assertSame('5', $r->header('Retry-After'));
        self::assertStringNotContainsString('db-secret-host', $r->body, 'the database address is for the log, not the page');
        self::assertStringNotContainsString('SQLSTATE', $r->body);
        self::assertStringContainsString(Words::ERROR['unavailable'], $r->body);
        self::assertStringContainsString('<h1>' . Words::ERROR_TITLE['503'] . '</h1>', $r->body);
        self::assertStringContainsString(Words::UI['quote_rid'] . ' <code>' . $r->header('X-Request-Id') . '</code>', $r->body, 'the number to quote, in words');
        self::assertNotNull($r->header('X-Request-Id'));
        self::assertStringContainsString($r->header('X-Request-Id') ?? '-', $logged[0] ?? '', 'the page shows the id that finds the log line');
        self::assertStringContainsString('db-secret-host', $logged[0] ?? '');
        self::assertSame(Kernel::CSP, $r->header('Content-Security-Policy'), 'error pages are hardened too');

        $config = new Kernel(
            static function (): never {
                throw new ConfigException('app.env is unreadable: /etc/cw/app.env');
            },
            static fn (): ?string => null,
            static function (string $m) use (&$logged): void {
                $logged[] = $m;
            },
        );
        $c = $config->handle(self::request('GET', '/ui/'));
        self::assertSame(503, $c->status);
        self::assertStringNotContainsString('/etc/cw', $c->body);

        $bug = new Kernel(
            static function (): never {
                throw new \RuntimeException('secret detail from a bug');
            },
            static fn (): ?string => null,
            static function (string $m) use (&$logged): void {
                $logged[] = $m;
            },
        );
        $b = $bug->handle(self::request('GET', '/ui/'));
        self::assertSame(500, $b->status);
        self::assertStringNotContainsString('secret detail', $b->body);
        self::assertStringContainsString(Html::e(Words::ERROR['internal']), $b->body);
        self::assertStringContainsString('data-code="internal"', $b->body);
        self::assertStringContainsString('secret detail', implode("\n", $logged));
    }

    /** On staging (app.env environment=staging) every page carries the test-system strip, the pages before a session too. */
    public function testTheTestSystemStrip(): void
    {
        $make = static fn (?\Closure $test): Kernel => new Kernel(static function (): never {
            throw new \RuntimeException('must not connect');
        }, static fn (): ?string => null, static function (): void {
        }, null, $test);
        $strip = '<p class="strip test-system" role="note">' . Words::UI['test_system'] . '</p>';
        self::assertStringContainsString($strip, $make(static fn (): bool => true)->handle(self::request('GET', '/ui/nothing-here'))->body);
        self::assertStringNotContainsString('test-system', $make(static fn (): bool => false)->handle(self::request('GET', '/ui/nothing-here'))->body);
        self::assertStringNotContainsString('test-system', $make(null)->handle(self::request('GET', '/ui/nothing-here'))->body);
        $broken = $make(static function (): never {
            throw new \RuntimeException('app.env unreadable');
        })->handle(self::request('GET', '/ui/nothing-here'));
        self::assertSame(404, $broken->status, 'a config that cannot be read does not break the page');
        self::assertStringNotContainsString('test-system', $broken->body);
    }

    public function testAnUnknownPathOrMethodNeverTouchesTheDatabase(): void
    {
        $touched = 0;
        $kernel = new Kernel(
            static function () use (&$touched): never {
                $touched++;
                throw new \RuntimeException('must not connect');
            },
            static fn (): ?string => null,
            static function (): void {
            },
        );
        $nf = $kernel->handle(self::request('GET', '/ui/nothing-here'));
        self::assertSame(404, $nf->status);
        $bad = $kernel->handle(self::request('GET', '/ui/review/listing/0'));
        self::assertSame(404, $bad->status, 'ids start at 1');
        $trav = $kernel->handle(self::request('GET', '/ui/../etc/passwd'));
        self::assertSame(404, $trav->status);
        $m = $kernel->handle(self::request('DELETE', '/ui/login'));
        self::assertSame(405, $m->status);
        self::assertSame('GET, POST', $m->header('Allow'));
        $p = $kernel->handle(self::request('GET', '/ui/review/listing/1/decide'));
        self::assertSame(405, $p->status, 'deciding is POST-only');
        self::assertSame('POST', $p->header('Allow'));
        self::assertSame(0, $touched);
        self::assertStringContainsString('<h1>' . Words::ERROR_TITLE['404'] . '</h1>', $nf->body);
        self::assertStringContainsString(Words::ERROR['not_found'], $nf->body);
        self::assertStringContainsString('data-code="not_found"', $nf->body, 'the code stays for support and tests, not as words');
        self::assertStringNotContainsString('no such page', $nf->body);
        self::assertStringContainsString(Words::ERROR['method_not_allowed'], $p->body);
        self::assertStringContainsString('<title>' . Words::ERROR_TITLE['405'] . ' - Central Warehouse</title>', $p->body, 'the tab says it too');
        foreach ([$nf, $bad, $trav, $m, $p] as $r) {
            self::assertSame(Kernel::CSP, $r->header('Content-Security-Policy'));
        }
    }

    // ---- Router ----------------------------------------------------------------------------------------

    public function testRouter(): void
    {
        $h = static fn (): HtmlResponse => new HtmlResponse(200, '');
        $r = new Router();
        $r->add('GET', '/ui/items/{id}', Route::ANY, $h)->add('POST', '/ui/items/{id}', Route::LEAD, $h)->add('GET', '/ui', Route::ANY, $h)->add('GET', '/ui/', Route::ANY, $h);
        [$route, $params] = $r->match('GET', '/ui/items/42');
        self::assertSame(['id' => '42'], $params);
        self::assertSame(Route::ANY, $route->access);
        [$head] = $r->match('HEAD', '/ui/items/42');
        self::assertSame('GET', $head->method, 'HEAD is answered like GET');
        [$post] = $r->match('POST', '/ui/items/7');
        self::assertSame(Route::LEAD, $post->access);
        foreach (['/ui/items/0', '/ui/items/007', '/ui/items/-1', '/ui/items/1.5', '/ui/items/1/', '/ui/items/', '/ui/items/1/x', '/ui/items/%31', '/ui/items/1%0a', "/ui/items/1\n", '/ui/items/1234567890123456789', '/UI/items/1', '/ui/items/a'] as $path) {
            try {
                $r->match('GET', $path);
                self::fail("{$path} matched");
            } catch (CwException $e) {
                self::assertSame(404, $e->httpStatus, $path);
            }
        }
        [, $big] = $r->match('GET', '/ui/items/999999999999999999');
        self::assertSame('999999999999999999', $big['id'], '18 digits fit an int');
        try {
            $r->match('PUT', '/ui/items/3');
            self::fail('405 expected');
        } catch (CwException $e) {
            self::assertSame(405, $e->httpStatus);
            self::assertSame(['GET', 'POST'], $e->detail['allow']);
        }
        [$root] = $r->match('GET', '/ui');
        [$slash] = $r->match('GET', '/ui/');
        self::assertSame('/ui', $root->pattern);
        self::assertSame('/ui/', $slash->pattern);

        // I11: access is public, any or a permission of the map; a typo fails when the route is added.
        $r->add('GET', '/ui/x', 'staff.view', $h);
        foreach (['decide', 'lead', 'admin', 'staff.View', 'staff.view ', '', 'doc.XX.post'] as $bad) {
            try {
                $r->add('GET', '/ui/y', $bad, $h);
                self::fail("access {$bad} accepted");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('access must be', $e->getMessage());
            }
        }
    }

    public function testEveryRealRouteHasADeliberateAccessLevel(): void
    {
        $kernel = new Kernel(static fn (): never => throw new \RuntimeException('no'), static fn (): ?string => null, static function (): void {
        });
        $byPath = [];
        foreach ($kernel->router()->routes() as $route) {
            $byPath[$route->method . ' ' . $route->pattern] = $route->access;
        }
        self::assertSame(Route::PUBLIC, $byPath['GET /ui/login']);
        self::assertSame(Route::PUBLIC, $byPath['POST /ui/login']);
        self::assertSame(Route::DECIDE, $byPath['POST /ui/review/listing/{id}/decide']);
        self::assertSame(Route::LEAD, $byPath['POST /ui/review/decision/{id}/approve']);
        self::assertSame(Route::DECIDE, $byPath['POST /ui/review/decision/{id}/withdraw']);
        self::assertSame(['mapping.decide', 'mapping.approve'], [Route::DECIDE, Route::LEAD]);
        self::assertSame(Route::ANY, $byPath['POST /ui/logout']);
        self::assertSame(Route::ANY, $byPath['GET /ui']);
        self::assertSame(Route::ANY, $byPath['GET /ui/'], 'everyone lands on /ui/: the dashboard or their home page');
        self::assertSame('linking.view', $byPath['GET /ui/review']);
        self::assertSame('linking.view', $byPath['GET /ui/review/listing/{id}']);
        self::assertSame('catalogue.view', $byPath['GET /ui/items/{id}']);
        self::assertSame('catalogue.view', $byPath['GET /ui/search']);
        // Duplicates (M34): everyone with the linking screens looks; merging, keeping separate and splitting are a mapping lead's.
        self::assertSame('linking.view', $byPath['GET /ui/review/duplicates']);
        self::assertSame('linking.view', $byPath['GET /ui/review/duplicates/{id}']);
        self::assertSame(Route::LEAD, $byPath['POST /ui/review/duplicates/{id}/decide']);
        self::assertSame(Route::LEAD, $byPath['POST /ui/review/duplicates/{id}/split']);
        $people = 0;
        foreach ($byPath as $key => $access) {
            self::assertTrue(in_array($access, [Route::PUBLIC, Route::ANY], true) || isset(Permissions::MAP[$access]), "{$key}: {$access}");
            if (str_starts_with($key, 'POST /ui/people')) {
                self::assertSame('staff.manage', $access, "{$key}: only an admin changes people");
                $people++;
            }
            if (str_starts_with($key, 'GET /ui/people')) {
                self::assertSame('staff.view', $access, "{$key}: admin and auditor look");
                $people++;
            }
            if (str_starts_with($key, 'POST ')) {
                self::assertNotSame('', $access, $key);
            }
            // GET /ui/logout only leads on (behaviour item 2): Home when signed in, the sign-in page when not; it shows and changes nothing.
            // GET/POST /ui/enrol (G06, Y20): a person sets their own password with their phone's code (Staff\Enrolment's throttle).
            // GET/POST /ui/new-code (Y40-Y43): the person's own page with a fresh code, opened only with the step cookie of that browser.
            if (!in_array($key, ['GET /ui/login', 'POST /ui/login', 'GET /ui/logout', 'GET /ui/enrol', 'POST /ui/enrol', 'GET /ui/new-code', 'POST /ui/new-code'], true)) {
                self::assertNotSame(Route::PUBLIC, $access, "{$key} must need a sign-in");
            }
            // The set-it-yourself pack (0019, Y1): every change of a setting, rule, reason or warehouse needs settings.manage.
            if (preg_match('#^POST /ui/reference/(settings|approvals|reasons|warehouses)#', $key) === 1) {
                self::assertSame('settings.manage', $access, $key);
            }
            if (str_starts_with($key, 'GET /ui/system/')) {
                self::assertContains($access, ['system.view', 'audit.view'], $key);
            }
            if (str_starts_with($key, 'POST /ui/review/')) {
                self::assertContains($access, [Route::DECIDE, Route::LEAD], "{$key}: a decision needs a deciding role");
            }
            // Documents (0008): reading needs documents.view, the queue and its decisions documents.review (the service
            // checks the task's kind, the type's doc.<TYPE>.post and the own-document rule again), the lists reference.view.
            if (str_starts_with($key, 'POST /ui/documents/reviews/') || $key === 'GET /ui/documents/reviews') {
                self::assertSame('documents.review', $access, $key);
            } elseif (str_starts_with($key, 'GET /ui/documents') || str_starts_with($key, 'GET /ui/files/') || $key === 'POST /ui/documents/{id}/reverse') {
                self::assertSame('documents.view', $access, $key);
            }
            // Everyone reads the reference lists and the company details; only the company form and its POSTs need more (I90).
            if (str_starts_with($key, 'GET /ui/reference/') && $key !== 'GET /ui/reference/company/edit') {
                self::assertSame('reference.view', $access, $key);
            }
            if (str_starts_with($key, 'POST /ui/reference/company')) {
                self::assertContains($access, ['company.edit', 'company.confirm'], "{$key}: a reviewer, never admin");
            }
        }
        self::assertSame('company.edit', $byPath['GET /ui/reference/company/edit']);
        self::assertSame('company.edit', $byPath['POST /ui/reference/company']);
        self::assertSame('company.confirm', $byPath['POST /ui/reference/company/confirm']);
        self::assertSame('company.confirm', $byPath['POST /ui/reference/company/reviews/{id}/approve']);
        self::assertSame('company.confirm', $byPath['POST /ui/reference/company/reviews/{id}/reject']);
        self::assertSame('reference.view', $byPath['GET /ui/reference/company/sample.pdf']);
        self::assertSame('public', $byPath['GET /ui/logout'], 'the sign-out address opened from the history leads on (behaviour item 2); it signs nobody out');
        self::assertSame(11, $people, 'GET /ui/people, GET /ui/people.csv, GET /ui/people/{id}, POST .../roles, POST .../active; since 0019 POST /ui/people '
            . '(add a person), .../sheet, .../authenticator, .../password, .../sign-out, .../request/withdraw');
        self::assertSame(Route::PUBLIC, $byPath['GET /ui/enrol']);
        self::assertSame(Route::PUBLIC, $byPath['GET /ui/new-code']);
        self::assertSame(Route::PUBLIC, $byPath['POST /ui/new-code']);
        self::assertSame('staff.approve', $byPath['POST /ui/staff-requests/{id}/approve']);
        self::assertSame('audit.view', $byPath['GET /ui/system/audit.csv']);
        self::assertArrayHasKey('POST /ui/people/{id}/roles', $byPath);
        self::assertArrayHasKey('POST /ui/people/{id}/active', $byPath);
    }

    // ---- UiRequest, roles ------------------------------------------------------------------------------

    public function testTheClientAddressIsRemoteAddrAndNothingElse(): void
    {
        $saved = [$_SERVER, $_GET, $_POST, $_COOKIE];
        try {
            $_SERVER = ['REQUEST_METHOD' => 'post', 'REQUEST_URI' => '/ui/login?x=1', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1', 'HTTP_X_REAL_IP' => '198.51.100.2',
                'HTTP_ORIGIN' => 'http://h.test', 'HTTP_SEC_FETCH_SITE' => 'same-origin', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
            $_GET = ['x' => '1'];
            $_POST = ['a' => ['nested'], 'b' => 'text'];
            $_COOKIE = ['cw_session' => 'tok', 'arr' => ['x']];
            $req = UiRequest::fromGlobals();
            self::assertSame('POST', $req->method);
            self::assertSame('/ui/login', $req->path);
            self::assertSame('203.0.113.9', $req->ip, 'a forged X-Forwarded-For cannot dodge the limiter or land in audit_log');
            self::assertFalse($req->secure);
            self::assertSame('http://h.test', $req->header('Origin'));
            self::assertSame('same-origin', $req->header('sec-fetch-site'));
            self::assertSame('application/x-www-form-urlencoded', $req->header('content-type'));
            self::assertSame('1', $req->param('x'));
            self::assertSame('text', $req->field('b'));
            self::assertNull($req->field('a'), 'an array value is absent, not an error');
            self::assertNull($req->param('nope'));
            self::assertSame('tok', $req->cookie('cw_session'));
            self::assertNull($req->cookie('arr'));

            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '//x?y', 'REMOTE_ADDR' => 'not-an-ip', 'HTTPS' => 'on'];
            $req = UiRequest::fromGlobals();
            self::assertSame('0.0.0.0', $req->ip);
            self::assertTrue($req->secure);
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => '2001:db8::1', 'HTTPS' => 'off'];
            $req = UiRequest::fromGlobals();
            self::assertSame('2001:db8::1', $req->ip);
            self::assertFalse($req->secure, 'HTTPS=off is not HTTPS');
        } finally {
            [$_SERVER, $_GET, $_POST, $_COOKIE] = $saved;
        }
    }

    public function testIdsAreStrictPositiveIntegers(): void
    {
        self::assertSame(5, UiRequest::id('5'));
        self::assertSame(123456789012345678, UiRequest::id('123456789012345678'));
        foreach ([null, '', '0', '05', '-1', '1.0', ' 1', '1 ', '1e3', '0x1', 'a', '1234567890123456789', "1\n"] as $bad) {
            self::assertNull(UiRequest::id($bad), json_encode($bad));
        }
    }

    public function testRolesMapToWhatTheyMayDo(): void
    {
        $who = static fn (string ...$roles): StaffIdentity => new StaffIdentity(1, 'a@b.test', 'A', $roles, false, 'sid');
        self::assertTrue($who('mapper')->canDecide());
        self::assertFalse($who('mapper')->isLead());
        self::assertTrue($who('mapping_lead')->canDecide());
        self::assertTrue($who('mapping_lead')->isLead());
        foreach (['viewer', 'admin', 'warehouse', '', 'MAPPER', 'buyer', 'reviewer'] as $other) {
            self::assertFalse($who($other)->canDecide(), $other);
            self::assertFalse($who($other)->isLead(), $other);
        }
        // Several roles: the union of what each may do.
        $both = $who('viewer', 'mapping_lead', 'reviewer');
        self::assertTrue($both->canDecide());
        self::assertTrue($both->isLead());
        self::assertTrue($both->can('documents.review'));
        self::assertTrue($both->can('linking.view'));
        self::assertFalse($both->can('staff.view'));
        self::assertTrue($both->has('reviewer'));
        self::assertFalse($both->has('buyer'));
        self::assertSame(['mapping_lead', 'reviewer', 'viewer'], $both->roles, 'sorted');
        self::assertSame('mapping_lead, reviewer, viewer', $both->rolesLabel());
        self::assertSame('Your roles (mapping_lead, reviewer, viewer)', $both->rolesPhrase());
        self::assertSame('your role (viewer)', $who('viewer')->rolesPhrase(true));
        self::assertSame(['buyer'], $who('buyer', 'buyer', '')->roles, 'unique, no empty names');
        $none = $who();
        self::assertSame('no roles', $none->rolesLabel());
        self::assertFalse($none->can('catalogue.view'), 'no roles, no pages');
        $this->expectException(\InvalidArgumentException::class);
        $both->can('linking.veiw');
    }

    // ---- Compare ---------------------------------------------------------------------------------------

    public function testCompareMarksOnlyRealConflicts(): void
    {
        $card = ['brand' => 'Elux', 'strength_mg' => '20', 'nic_type' => 'salt', 'line' => 'Legend', 'form' => 'disposable', 'flavour' => 'Blue Razz', 'volume_ml' => null, 'puffs' => '3500', 'pack_units' => 1];
        $sku = ['brand' => 'ELUX ', 'strength_mg' => '20.00', 'nic_type' => 'freebase', 'line' => '', 'form' => 'disposable', 'flavour' => 'razz blue', 'volume_ml' => '2.00', 'puffs' => 3500, 'pack_units' => '1'];
        $rows = [];
        foreach (Compare::rows($card, $sku, ['5060000000001'], ['5060000000002']) as $row) {
            $rows[$row['field']] = $row;
        }
        self::assertSame(array_merge(array_keys(Compare::LABELS), ['barcodes']), array_keys($rows));
        self::assertSame('same', $rows['brand']['state'], 'case and padding do not matter');
        self::assertSame('same', $rows['strength_mg']['state'], '20 and 20.00');
        self::assertSame('differs', $rows['nic_type']['state']);
        self::assertSame('unknown', $rows['line']['state'], 'a field one side does not know is not a conflict');
        self::assertSame('same', $rows['flavour']['state'], 'word order does not matter');
        self::assertSame('unknown', $rows['volume_ml']['state']);
        self::assertSame('same', $rows['puffs']['state']);
        self::assertSame('same', $rows['pack_units']['state']);
        self::assertSame('differs', $rows['barcodes']['state'], 'both known, none shared');
        self::assertSame('20', $rows['strength_mg']['listing']);
        self::assertSame('20', $rows['strength_mg']['item'], 'shown without trailing zeros');
        self::assertSame('', $rows['volume_ml']['listing']);

        $shared = Compare::rows([], [], ['1', '2'], ['2', '3']);
        self::assertSame('same', end($shared)['state'], 'one barcode in common');
        $none = Compare::rows([], [], [], ['2']);
        self::assertSame('unknown', end($none)['state']);
        self::assertSame('1, 2', end($shared)['listing']);
    }

    /**
     * The sites spell brands differently: the brand row says `differs` only when the distinctive words
     * really differ (review finding, data lens: brand was the only "differs" row on 305 of 936 Key
     * screens). Pairs are the most frequent ones of run3's Key and Check proposals on staging.
     */
    public function testBrandsSpeltDifferentlyAreNotConflicts(): void
    {
        foreach ([
            ['OXVA', 'OXVA Brand', 'same'], ['Doozy Vape Co', 'Doozy Vape', 'same'], ['Vaporesso', 'Vaporesso Vape Kits & Accessories', 'same'],
            ['Crystal Clear', 'Crystal Clear Nic Salts', 'same'], ['Big Bar', 'Big Bar Vapes', 'same'], ['Titan', 'Titan 10K Disposable Vapes', 'same'],
            ['Kingston', 'Kingston E-Liquids', 'same'], ['Nasty Juice', 'Nasty Juice Salt', 'same'], ['IVG', 'IVG Nic Salts', 'same'],
            ['Elux Vapes, Pods and E-Liquids', 'Elux Nic Salt (Legend Salts) E-Liquids', 'alike'], ['Crystal Bar', 'SKE Crystal Bar', 'alike'],
            ['Crystal Bar', 'Crystal Pro CP', 'alike'], ['RandM', 'R and M Tornado Salts', 'alike'],
            ['Vapes Bars', 'PIXL', 'differs'], ['Instapod', 'InstaFill', 'differs'], ['Elf Bar', 'Lost Mary', 'differs'],
            ['Crystal Bar', 'SKE Crystal Nic Salt E-Liquids', 'differs'], ['', 'Elux', 'unknown'],
        ] as [$a, $b, $state]) {
            self::assertSame($state, Compare::brandState($a === '' ? null : $a, $b), "{$a} / {$b}");
            self::assertSame($state, Compare::brandState($b, $a === '' ? null : $a), "{$b} / {$a}");
        }
        // A confirmed brand alias (alias kind brand) makes a rename the same brand.
        self::assertSame('differs', Compare::brandState('Crystal Pro Max', 'Hayati Pro Max'));
        self::assertSame('same', Compare::brandState('Crystal Pro Max', 'Hayati Pro Max', ['crystal pro max' => 'hayati pro max']));
        $rows = array_column(Compare::rows(['brand' => 'OXVA'], ['brand' => 'OXVA Brand'], ['04006381333931'], ['4006381333931']), 'state', 'field');
        self::assertSame(['same', 'same'], [$rows['brand'], $rows['barcodes']], 'barcodes compare on their GTIN key (leading zeros do not count)');
    }

    // ---- Csrf, limiter, assets -------------------------------------------------------------------------

    public function testCsrfTokensAreBoundToTheirOwnerAndTheKey(): void
    {
        $key = SecretBox::newKeyBase64();
        $csrf = Csrf::fromSecretKey($key);
        $pre = str_repeat('a', 43);
        $t = $csrf->forSession('session-1');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $t);
        self::assertSame($t, $csrf->forSession('session-1'), 'stable within a session');
        self::assertNotSame($t, $csrf->forSession('session-2'));
        self::assertTrue($csrf->validForSession('session-1', $t));
        self::assertFalse($csrf->validForSession('session-2', $t), 'worthless in another session');
        self::assertFalse($csrf->validForSession('session-1', null));
        self::assertFalse($csrf->validForSession('session-1', ''));
        self::assertFalse($csrf->validForSession('session-1', strtoupper($t)));
        self::assertFalse($csrf->validForSession('session-1', $t . '0'));
        self::assertTrue($csrf->validForPre($pre, $csrf->forPre($pre)));
        self::assertFalse($csrf->validForPre($pre, $t), 'a session token is no login-form token');
        self::assertFalse($csrf->validForSession($pre, $csrf->forPre($pre)), 'and the other way round');
        self::assertFalse($csrf->validForPre(null, $csrf->forPre('')));
        self::assertFalse($csrf->validForPre('', $csrf->forPre('')), 'no cookie, no token');
        self::assertFalse($csrf->validForPre($pre, null));
        self::assertFalse(Csrf::fromSecretKey(SecretBox::newKeyBase64())->validForSession('session-1', $t), 'another key');
        foreach (['', 'short', base64_encode('short'), '!!!!'] as $bad) {
            try {
                Csrf::fromSecretKey($bad);
                self::fail('key accepted: ' . $bad);
            } catch (ConfigException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTheLoginLimitsAreThePlanned(): void
    {
        self::assertSame(10, LoginLimiter::ACCOUNT_MAX, 'plan §11: lock after 10 failures');
        self::assertSame(15 * 60, LoginLimiter::WINDOW_SECONDS, '... for 15 minutes');
        self::assertGreaterThan(LoginLimiter::ACCOUNT_MAX, LoginLimiter::IP_MAX, 'an office shares one address');
    }

    public function testAssetsAreServedOnlyByNameAndRevalidated(): void
    {
        self::assertFileExists(Assets::dir() . '/app.css');
        self::assertFileExists(Assets::dir() . '/app.js');
        $css = Assets::serve(self::request('GET', '/ui/assets/app.css'));
        self::assertSame(200, $css->status);
        self::assertSame('text/css; charset=utf-8', $css->header('Content-Type'));
        self::assertSame('nosniff', $css->header('X-Content-Type-Options'));
        self::assertSame(Kernel::CSP, $css->header('Content-Security-Policy'));
        self::assertSame('DENY', $css->header('X-Frame-Options'));
        self::assertSame('no-cache', $css->header('Cache-Control'));
        self::assertSame(file_get_contents(Assets::dir() . '/app.css'), $css->body);
        $js = Assets::serve(self::request('GET', '/ui/assets/app.js'));
        self::assertSame('text/javascript; charset=utf-8', $js->header('Content-Type'));
        $etag = (string) $css->header('ETag');
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);
        $again = Assets::serve(self::request('GET', '/ui/assets/app.css', ['if-none-match' => $etag]));
        self::assertSame(304, $again->status);
        self::assertSame('', $again->body);
        self::assertSame(200, Assets::serve(self::request('GET', '/ui/assets/app.css', ['if-none-match' => '"other"']))->status);
        self::assertSame(200, Assets::serve(self::request('HEAD', '/ui/assets/app.css'))->status);
        self::assertSame(405, Assets::serve(self::request('POST', '/ui/assets/app.css'))->status);
        // Apache's compression adds "-gzip" to the ETag it passes on; the browser sends that back (weak or not, maybe in a list).
        foreach ([substr($etag, 0, -1) . '-gzip"', 'W/' . substr($etag, 0, -1) . '-gzip"', '"x", ' . $etag, substr($etag, 0, -1) . '-br"'] as $sent) {
            self::assertSame(304, Assets::serve(self::request('GET', '/ui/assets/app.css', ['if-none-match' => $sent]))->status, $sent);
        }
        self::assertSame(200, Assets::serve(self::request('GET', '/ui/assets/app.css', ['if-none-match' => substr($etag, 0, -2) . '-gzip"']))->status);
        foreach (['/ui/assets/', '/ui/assets/app.php', '/ui/assets/../index.php', '/ui/assets/..%2findex.php', '/ui/assets/app.css/', '/ui/assets/APP.css', '/ui/assets/other.css', '/ui/assets/app.css%00.php', '/ui/assets/sub/app.css', "/ui/assets/app.css\n"] as $path) {
            $r = Assets::serve(self::request('GET', $path));
            self::assertSame(404, $r->status, $path);
            self::assertSame(Kernel::CSP, $r->header('Content-Security-Policy'), $path);
            self::assertSame('text/plain; charset=utf-8', $r->header('Content-Type'), $path);
        }
    }

    public function testTheLayoutLinksTheAssetsByVersionAndThatUrlIsKeptForAYear(): void
    {
        foreach (['app.css', 'app.js'] as $name) {
            $hash = hash_file('sha256', Assets::dir() . '/' . $name);
            $url = Assets::url($name);
            self::assertSame('/ui/assets/' . $name . '?v=' . substr((string) $hash, 0, 16), $url);
            $req = new UiRequest('GET', '/ui/assets/' . $name, ['v' => substr((string) $hash, 0, 16)], [], [], [], '127.0.0.1', true);
            $r = Assets::serve($req);
            self::assertSame(200, $r->status);
            self::assertSame(Assets::IMMUTABLE, $r->header('Cache-Control'), 'the URL of this content never changes');
            self::assertNull($r->header('Pragma'));
            $etag = (string) $r->header('ETag');
            $again = Assets::serve(new UiRequest('GET', '/ui/assets/' . $name, ['v' => substr((string) $hash, 0, 16)], [], [], ['if-none-match' => $etag], '127.0.0.1', true));
            self::assertSame(304, $again->status);
            self::assertSame(Assets::IMMUTABLE, $again->header('Cache-Control'));
            // An older file's version (a page cached before a deploy) or a made-up one: today's file, revalidated as before.
            foreach (['0000000000000000', '', 'x', substr((string) $hash, 0, 15)] as $v) {
                $old = Assets::serve(new UiRequest('GET', '/ui/assets/' . $name, ['v' => $v], [], [], [], '127.0.0.1', true));
                self::assertSame(200, $old->status, $v);
                self::assertSame('no-cache', $old->header('Cache-Control'), $v);
            }
        }
        self::assertSame('/ui/assets/other.css', Assets::url('other.css'), 'only the two files have a version');
        $view = new \CW\Ui\View(\CW\Ui\View::defaultDir(), ['csrf' => '', 'who' => null]);
        $html = $view->page('error', ['status' => 404, 'code' => 'not_found', 'heading' => 'Not found', 'message' => 'm', 'rid' => 'r'],
            ['title' => 'Not found', 'active' => '', 'notice' => null, 'menu' => [], 'badges' => [], 'searchBox' => false, 'testSystem' => false]);
        self::assertStringContainsString('<link rel="stylesheet" href="' . Assets::url('app.css') . '">', $html);
        self::assertStringContainsString('<script src="' . Assets::url('app.js') . '" defer></script>', $html);
    }

    public function testTheAssetsNeedNothingFromOutsideAndNoEval(): void
    {
        $css = (string) file_get_contents(Assets::dir() . '/app.css');
        $js = (string) file_get_contents(Assets::dir() . '/app.js');
        self::assertDoesNotMatchRegularExpression('#(?:@import|url\(\s*["\']?(?:https?:)?//)#i', $css, 'the CSS fetches nothing from elsewhere');
        self::assertDoesNotMatchRegularExpression('/\b(eval|Function)\s*\(|new\s+Function|document\.write|innerHTML|outerHTML|insertAdjacentHTML|setTimeout\s*\(\s*["\']|setInterval\s*\(\s*["\']/', $js, 'nothing the CSP would have to allow, nothing that builds markup from strings');
        self::assertDoesNotMatchRegularExpression('#https?://#', $js, 'no third-party host');
        self::assertDoesNotMatchRegularExpression('/\b(fetch|XMLHttpRequest|WebSocket|sendBeacon|EventSource)\b/', $js, 'the script only enhances forms: it never talks to the network');
    }
}
