<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Caller;
use CW\Schema\Grants;
use CW\Staff\SecretBox;
use CW\Staff\StaffAdmin;
use CW\Staff\Totp;

/**
 * Base for the staff-screen tests (tests/Integration/Ui*Test.php). They drive the real /ui through
 * Apache + php-fpm on the staging box: the name-based vhost cw-ui.staging.invalid on 127.0.0.1:8080
 * (deploy/staging/install_ui.sh), served from /opt/cw-ui as the app login cw_app against the
 * ui slot's schema cw_test_ui. Fixtures are written directly with the admin connection; every
 * answer is checked over HTTP and, where it matters, in the database.
 *
 * In any other slot the vhost serves a different schema than the one under test, so these tests
 * are skipped there (CW_UI_SCHEMA overrides the served schema's name).
 */
abstract class UiTestCase extends MappingTestCase
{
    public const DEFAULT_URL = 'http://127.0.0.1:8080';
    public const DEFAULT_HOST = 'cw-ui.staging.invalid';
    public const SERVED_SCHEMA = 'cw_test_ui';

    private static bool $granted = false;
    private static ?SecretBox $box = null;
    private int $userSeq = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $served = getenv('CW_UI_SCHEMA') ?: self::SERVED_SCHEMA;
        if (TestDb::name() !== $served) {
            self::markTestSkipped("the UI vhost serves {$served}; these tests run in slot ui (schema " . TestDb::name() . ')');
        }
        if (!self::$granted) {
            // The UI connects as cw_app, whose rights are table grants per schema: converge them on
            // the freshly migrated test schema (grants survive the DROP/CREATE of each run).
            $user = TestDb::config()->appDbUser();
            self::assertNotNull($user, 'app.env has no db_user');
            Grants::apply(self::$db, TestDb::name(), $user);
            self::$granted = true;
        }
    }

    /** A browser with an empty cookie jar. */
    protected function browser(): UiClient
    {
        return new UiClient(rtrim(getenv('CW_UI_URL') ?: self::DEFAULT_URL, '/'), getenv('CW_UI_HOST') ?: self::DEFAULT_HOST);
    }

    private static function box(): SecretBox
    {
        if (self::$box === null) {
            $key = TestDb::config()->get('ui_secret_key');
            self::assertNotNull($key, 'app.env has no ui_secret_key (deploy/staging/install_ui.sh creates it)');
            self::$box = SecretBox::fromBase64($key);
        }
        return self::$box;
    }

    /**
     * A staff account created the way bin/create_staff.php does it, with its password and TOTP secret
     * kept for the test (never printed).
     *
     * @return array{id: int, email: string, role: string, password: string, secret: string}
     */
    protected function uiUser(string $role, bool $mustChange = false, ?string $email = null): array
    {
        $n = ++$this->userSeq;
        $email ??= "ui-{$role}-{$n}@test.invalid";
        $made = (new StaffAdmin(self::$db))->create(Caller::system('ui_test'), $email, $role, self::box(), ucfirst($role) . " {$n}");
        parse_str((string) parse_url($made['otpauth'], PHP_URL_QUERY), $query);
        self::assertIsString($query['secret'] ?? null);
        if (!$mustChange) {
            self::$db->exec('UPDATE staff_user SET password_must_change = 0 WHERE id = ?', [$made['id']]);
        }
        return ['id' => $made['id'], 'email' => $made['email'], 'role' => $role, 'password' => $made['password'], 'secret' => $query['secret']];
    }

    /** The code an authenticator shows now (a step earlier or later with $stepOffset). */
    protected static function code(string $secret, int $stepOffset = 0): string
    {
        return Totp::code($secret, intdiv(time(), Totp::PERIOD) + $stepOffset);
    }

    /** The fields of the login form, after a GET (which sets the pre-login cookie). @return array<string, string> */
    protected function loginFields(UiClient $web): array
    {
        $page = $web->get('/ui/login');
        self::assertSame(200, $page->status, $page->describe());
        $fields = $page->form('/ui/login');
        self::assertNotSame('', $fields['csrf'] ?? '', 'the login form carries a CSRF token');
        return $fields;
    }

    /** Submits the login form. A used TOTP step is forgotten first, so one test can sign in several times. */
    protected function attemptLogin(UiClient $web, string $email, string $password, string $code, bool $forgetStep = true): UiResponse
    {
        $fields = $this->loginFields($web);
        if ($forgetStep) {
            self::$db->exec('UPDATE staff_user SET totp_last_step = NULL WHERE email = ?', [$email]);
        }
        return $web->post('/ui/login', ['csrf' => $fields['csrf'], 'email' => $email, 'password' => $password, 'code' => $code]);
    }

    /** Signs a person in and returns the browser (asserts the 303 to the dashboard, or to the password page when told). */
    protected function signIn(array $user, ?UiClient $web = null, string $lands = '/ui/'): UiClient
    {
        $web ??= $this->browser();
        $r = $this->attemptLogin($web, $user['email'], $user['password'], self::code($user['secret']));
        self::assertSame(303, $r->status, 'sign-in: ' . $r->describe());
        self::assertSame($lands, $r->location());
        self::assertArrayHasKey('cw_session', $web->cookies);
        return $web;
    }

    /** The CSRF token of the signed-in pages (from the sign-out form every page carries). */
    protected function token(UiClient $web): string
    {
        $r = $web->get('/ui/');
        self::assertSame(200, $r->status, $r->describe());
        $t = $r->form('/ui/logout')['csrf'] ?? '';
        self::assertNotSame('', $t);
        return $t;
    }

    // ---- fixtures --------------------------------------------------------------------------

    /**
     * A listing of $site with its profile (and matching features), unmapped unless a sku is given.
     *
     * @param array<string, mixed> $p product_title, variant_title, brand, barcodes (list), attributes (list of name/value),
     *                                price, units_30d, units_365d, perma_link, features (array)
     */
    protected function profiled(Caller $site, string $variant, array $p = [], ?int $sku = null): int
    {
        $id = $this->listing($site, $variant, $sku);
        self::$db->exec(
            'INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, attributes, barcodes, price, perma_link, units_30d, units_365d, features, features_version) '
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'n2.0')",
            [
                $id, $p['product_title'] ?? "Product {$variant}", $p['variant_title'] ?? null, $p['brand'] ?? null,
                isset($p['attributes']) ? json_encode(['items' => $p['attributes']], JSON_THROW_ON_ERROR) : null,
                json_encode($p['barcodes'] ?? [], JSON_THROW_ON_ERROR), $p['price'] ?? null, $p['perma_link'] ?? null,
                $p['units_30d'] ?? 0, $p['units_365d'] ?? 0,
                json_encode($p['features'] ?? new \stdClass(), JSON_THROW_ON_ERROR),
            ],
        );
        return $id;
    }

    /** Sets the identity card of an item. @param array<string, scalar|null> $card */
    protected function card(int $sku, array $card): void
    {
        $sets = [];
        $params = [];
        foreach ($card as $k => $v) {
            self::assertContains($k, ['name', 'brand', 'strength_mg', 'nic_type', 'line', 'form', 'flavour', 'volume_ml', 'puffs', 'pack_units']);
            $sets[] = "`{$k}` = ?";
            $params[] = $v;
        }
        $params[] = $sku;
        self::$db->exec('UPDATE sku SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    /** A barcode of an item. */
    protected function barcode(int $sku, string $code): void
    {
        self::$db->exec('INSERT INTO sku_barcode (barcode, sku_id, source) VALUES (?, ?, ?)', [$code, $sku, 'test']);
    }

    /** What the escaping tests plant in every free-text field. */
    protected const EVIL = '<script>alert("x")</script>';
    protected const EVIL_ATTR = '"><img src=x onerror=alert(1)>';

    protected static function auditCount(string $action, ?int $staffId = null): int
    {
        return $staffId === null
            ? (int) self::$db->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action])
            : (int) self::$db->value('SELECT COUNT(*) FROM audit_log WHERE action = ? AND staff_user_id = ?', [$action, $staffId]);
    }

    /** An unmapped listing with a profile and an open proposal of $band (the listing becomes "suggested"). @param array<string, mixed> $profile @param array<string, mixed> $proposal */
    protected function queued(Caller $site, string $variant, string $band, ?int $sku, array $profile = [], array $proposal = [], string $run = 'test-run'): int
    {
        $id = $this->profiled($site, $variant, $profile);
        $this->propose($id, $band, $sku, $proposal, true, $run);
        return $id;
    }

    /**
     * The fields the decide form of a listing page would submit (as the browser prefills them).
     *
     * @param array<string, scalar|null> $query the queue context of the link that led there (queue, channel, ...)
     * @return array<string, string>
     */
    protected function decideForm(UiClient $web, int $listingId, array $query = []): array
    {
        $r = $web->get('/ui/review/listing/' . $listingId, $query);
        self::assertSame(200, $r->status, $r->describe());
        $form = $r->form('/ui/review/listing/' . $listingId . '/decide');
        self::assertNotSame([], $form, 'the listing page has no decide form: ' . $r->describe());
        return $form;
    }

    // ---- assertions on answers -----------------------------------------------------------------

    /** Every answer of the UI carries the same hard headers (a page, an error, a redirect or an asset). */
    protected static function assertHardened(UiResponse $r, string $what): void
    {
        self::assertSame("default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'", $r->header('content-security-policy'), "{$what}: CSP");
        self::assertSame('nosniff', $r->header('x-content-type-options'), "{$what}: nosniff");
        if ($r->status !== 304) {
            self::assertSame('DENY', $r->header('x-frame-options'), "{$what}: X-Frame-Options");
            self::assertSame('same-origin', $r->header('referrer-policy'), "{$what}: Referrer-Policy");
        }
        self::assertNull($r->header('strict-transport-security'), "{$what}: no HSTS over plain HTTP");
        self::assertNull($r->header('x-powered-by'), "{$what}: no X-Powered-By");
    }

    /**
     * A page that shows hostile text must show it as text: no script but the one asset, no event
     * handler or style attribute, no image, frame or embed, and no javascript: link.
     */
    protected static function assertInert(UiResponse $r, string $what): void
    {
        self::assertSame(200, $r->status, "{$what}: " . $r->describe());
        self::assertSame(1, substr_count(strtolower($r->body), '<script'), "{$what}: the asset tag is the only script tag");
        self::assertSame(0, substr_count(strtolower($r->body), '<img'), "{$what}: raw img tag");
        $dom = $r->dom();
        $xp = new \DOMXPath($dom);
        $scripts = $dom->getElementsByTagName('script');
        self::assertSame(1, $scripts->length, "{$what}: one script element only");
        $script = $scripts->item(0);
        self::assertInstanceOf(\DOMElement::class, $script);
        self::assertSame('/ui/assets/app.js', $script->getAttribute('src'), $what);
        self::assertSame('', trim($script->textContent), "{$what}: no inline script");
        foreach (['img', 'iframe', 'frame', 'object', 'embed', 'svg', 'style', 'base', 'audio', 'video', 'source', 'math', 'applet'] as $tag) {
            self::assertSame(0, $dom->getElementsByTagName($tag)->length, "{$what}: <{$tag}> element");
        }
        foreach ($xp->query('//*') ?: [] as $el) {
            self::assertInstanceOf(\DOMElement::class, $el);
            foreach ($el->attributes ?? [] as $attr) {
                $name = strtolower($attr->name);
                self::assertFalse(str_starts_with($name, 'on'), "{$what}: <{$el->tagName} {$name}>");
                self::assertNotSame('style', $name, "{$what}: inline style on <{$el->tagName}>");
                if (in_array($name, ['href', 'src', 'action', 'formaction', 'data', 'xlink:href'], true)) {
                    self::assertDoesNotMatchRegularExpression('/^\s*(javascript|data|vbscript):/i', $attr->value, "{$what}: <{$el->tagName} {$name}>");
                }
            }
        }
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            self::assertNotSame('refresh', strtolower($meta->getAttribute('http-equiv')), $what);
        }
    }
}
