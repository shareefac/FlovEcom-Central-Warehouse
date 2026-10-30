<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Auth\Login;
use CW\Auth\LoginLimiter;
use CW\Auth\Sessions;
use CW\Caller;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Schema\Grants;
use CW\Staff\SecretBox;
use CW\Staff\StaffAdmin;
use CW\Staff\Totp;
use CW\Tests\Support\Documents\FixtureAdjustmentHandler;
use CW\Ui\Kernel;

/**
 * Base of the in-process screen tests (tests/Integration/UiKernel, tests/Integration/Auth,
 * tests/Integration/Staff): the real /ui kernel (routing, sessions, CSRF, roles, controllers,
 * templates) driven by KernelBrowser, connected as the app login (cw_app, with its converged
 * grants) to this slot's test schema, so they run in every slot. The HTTP tests of
 * tests/Integration/Ui*Test.php (Apache + php-fpm) need slot `ui`'s vhost.
 */
abstract class KernelUiTestCase extends MappingTestCase
{
    protected static Db $appDb;
    protected static string $uiKey;
    protected static SecretBox $box;
    /** @var list<string> */
    protected static array $log = [];
    private int $userSeq = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $config = TestDb::config();
        $user = $config->appDbUser();
        self::assertNotNull($user, 'app.env has no db_user');
        Grants::apply(self::$db, TestDb::name(), $user);
        self::$appDb = Db::connect($config->dbApp()->withDatabase(TestDb::name()));
        $key = $config->get('ui_secret_key');
        self::assertNotNull($key, 'app.env has no ui_secret_key');
        self::$uiKey = $key;
        self::$box = SecretBox::fromBase64($key);
    }

    /** The kernel as production builds it (DocumentHandlers::all), with the document types of handlers(). */
    protected function kernel(): Kernel
    {
        $db = self::$appDb;
        $key = self::$uiKey;
        return new Kernel(static fn (): Db => $db, static fn (): string => $key, static function (string $m): void {
            self::$log[] = $m;
        }, fn (Db $db): array => $this->handlers($db));
    }

    /**
     * The live document types of the screens under test: production's, with the test-only ADJ (I27) in place of the real adjustment
     * (pack A1) for the screen tests written before it (they post ADJ records without reasons). A test of the stock records returns
     * DocumentHandlers::all($db) itself.
     *
     * @return array<string, \CW\Documents\DocumentHandler>
     */
    protected function handlers(Db $db): array
    {
        return array_replace(DocumentHandlers::all($db), ['ADJ' => new FixtureAdjustmentHandler($db)]);
    }

    protected function browser(string $ip = '198.51.100.20'): KernelBrowser
    {
        return new KernelBrowser($this->kernel(), $ip);
    }

    /** The sign-in service as the UI builds it, on the app connection. */
    protected function loginService(?Db $db = null): Login
    {
        $db ??= self::$appDb;
        return new Login($db, new Sessions($db), new LoginLimiter($db), self::$box);
    }

    /**
     * A staff account made the way bin/create_staff.php makes it, holding $roles (one role or several).
     *
     * @param string|list<string> $roles
     * @return array{id: int, email: string, roles: list<string>, password: string, secret: string}
     */
    protected function uiUser(string|array $roles, bool $mustChange = false): array
    {
        $roles = is_string($roles) ? [$roles] : $roles;
        $label = implode('-', $roles);
        $n = ++$this->userSeq;
        $email = "k-{$label}-{$n}@test.example"; // a real person's address: `.invalid` marks a placeholder account (I35)
        $made = (new StaffAdmin(self::$db))->create(Caller::system('kernel_test'), $email, $roles, self::$box, ucfirst($label) . " {$n}");
        parse_str((string) parse_url($made['otpauth'], PHP_URL_QUERY), $query);
        self::assertIsString($query['secret'] ?? null);
        if (!$mustChange) {
            self::$db->exec('UPDATE staff_user SET password_must_change = 0 WHERE id = ?', [$made['id']]);
        }
        return ['id' => $made['id'], 'email' => $made['email'], 'roles' => $made['roles'], 'password' => $made['password'], 'secret' => $query['secret']];
    }

    protected static function code(string $secret, int $stepOffset = 0): string
    {
        return Totp::code($secret, intdiv(time(), Totp::PERIOD) + $stepOffset);
    }

    /** Signs in through the real login form (GET for the pre-login cookie + token, then POST). */
    protected function signIn(array $user, ?KernelBrowser $web = null, string $lands = '/ui/'): KernelBrowser
    {
        $web ??= $this->browser();
        $page = $web->get('/ui/login');
        self::assertSame(200, $page->status, $page->describe());
        $csrf = $page->form('/ui/login')['csrf'] ?? '';
        self::assertNotSame('', $csrf);
        self::$db->exec('UPDATE staff_user SET totp_last_step = NULL WHERE id = ?', [$user['id']]);
        $r = $web->post('/ui/login', ['csrf' => $csrf, 'email' => $user['email'], 'password' => $user['password'], 'code' => self::code($user['secret'])]);
        self::assertSame(303, $r->status, 'sign-in: ' . $r->describe());
        self::assertSame($lands, $r->location());
        self::assertArrayHasKey('cw_session', $web->cookies);
        return $web;
    }

    /** The session-bound CSRF token (from the sign-out form every signed-in page carries). */
    protected function token(KernelBrowser $web): string
    {
        $r = $web->get('/ui/');
        self::assertSame(200, $r->status, $r->describe());
        $t = $r->form('/ui/logout')['csrf'] ?? '';
        self::assertNotSame('', $t);
        return $t;
    }

    /** A listing with a profile (unmapped unless $sku). @param array<string, mixed> $p */
    protected function profiled(Caller $site, string $variant, array $p = [], ?int $sku = null, int $u = 1): int
    {
        $id = $this->listing($site, $variant, $sku, $u);
        self::$db->exec(
            'INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, attributes, barcodes, price, perma_link, units_30d, units_365d, features, features_version) '
            . "VALUES (?, ?, ?, ?, NULL, ?, NULL, NULL, ?, ?, ?, 'n2.0')",
            [$id, $p['product_title'] ?? "Product {$variant}", $p['variant_title'] ?? null, $p['brand'] ?? null,
                json_encode($p['barcodes'] ?? [], JSON_THROW_ON_ERROR), $p['units_30d'] ?? 0, $p['units_365d'] ?? 0,
                json_encode($p['features'] ?? new \stdClass(), JSON_THROW_ON_ERROR)],
        );
        return $id;
    }

    /** An unmapped listing with a profile and an open proposal (listing becomes suggested). @param array<string, mixed> $profile @param array<string, mixed> $proposal */
    protected function queued(Caller $site, string $variant, string $band, ?int $sku, array $profile = [], array $proposal = []): int
    {
        $id = $this->profiled($site, $variant, $profile);
        $this->propose($id, $band, $sku, $proposal, true);
        return $id;
    }

    /** The fields the decide form of a listing page submits. @param array<string, scalar|null> $query @return array<string, string> */
    protected function decideForm(KernelBrowser $web, int $listingId, array $query = []): array
    {
        $r = $web->get('/ui/review/listing/' . $listingId, $query);
        self::assertSame(200, $r->status, $r->describe());
        $form = $r->form('/ui/review/listing/' . $listingId . '/decide');
        self::assertNotSame([], $form, 'no decide form: ' . $r->describe());
        return $form;
    }

    /**
     * The sidebar of a page as the person sees it (Ui\Sections; owner's request of 8 Oct 2026): section name => its link and the
     * count shown after it (0 when none). The name leaves out the words only a screen reader hears (.visually-hidden) and the count.
     *
     * @return array<string, array{href: string, count: int}>
     */
    protected static function nav(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $navs = $xp->query('//nav[@aria-label="Main"]');
        self::assertLessThanOrEqual(1, $navs === false ? 0 : $navs->length, 'one main menu per page');
        $out = [];
        foreach ($xp->query('//nav[@aria-label="Main"]/ul[@class="nav"]/li/a[contains(concat(" ", @class, " "), " nav-item ")]') ?: [] as $a) {
            /** @var \DOMElement $a */
            $label = trim((string) $xp->evaluate('string(span[@class="nav-label"])', $a));
            $out[$label] = ['href' => $a->getAttribute('href'), 'count' => (int) self::visibleText($xp, $xp->query('span[contains(concat(" ", @class, " "), " count ")]', $a)->item(0))];
        }
        return $out;
    }

    /**
     * The sidebar entry marked current, or null. Its aria-current is exact (the Codex review of f4d1126, DC32): "true" when one of the
     * section's pages in the tree is the page (it holds it), "page" when it is the page itself (Home); one link of the menu is the page.
     */
    protected static function currentSection(UiResponse $r): ?string
    {
        $xp = new \DOMXPath($r->dom());
        $a = $xp->query('//nav[@aria-label="Main"]//a[contains(concat(" ", @class, " "), " nav-item ") and @aria-current]')->item(0);
        $held = $xp->query('//nav[@aria-label="Main"]//ul[contains(concat(" ", @class, " "), " nav-sub ")]//*[@aria-current]')->length > 0;
        if ($a instanceof \DOMElement) {
            self::assertSame($held ? 'true' : 'page', $a->getAttribute('aria-current'), 'the entry is the page, or holds it');
        }
        self::assertLessThanOrEqual(1, $xp->query('//nav[@aria-label="Main"]//*[@aria-current="page"]')->length, 'one link of the menu is the page');
        return $a === null ? null : trim((string) $xp->evaluate('string(span[@class="nav-label"])', $a));
    }

    /**
     * The current section's pages in the sidebar (docs/decisions.md DC28: the owner's "use sidebar and sub section", no tab row): the
     * section's own ones (a set-up or specialist page, HV3, only when it is the current one; moreTabs() has the others): label, link
     * (null for the page shown: CV13), count and whether it is the current one.
     *
     * @return list<array{label: string, href: ?string, count: int, current: bool}>
     */
    protected static function sectionTabs(UiResponse $r): array
    {
        return array_values(array_map(static fn (array $t): array => ['label' => $t['label'], 'href' => $t['href'], 'count' => $t['count'], 'current' => $t['current']],
            array_filter(self::subItems($r), static fn (array $t): bool => !$t['more'] || $t['current'])));
    }

    /**
     * The section's set-up, import and specialist pages in the sidebar (HV3), but the current one: label, link and count, in order.
     *
     * @return list<array{label: string, href: ?string, count: int}>
     */
    protected static function moreTabs(UiResponse $r): array
    {
        return array_values(array_map(static fn (array $t): array => ['label' => $t['label'], 'href' => $t['href'], 'count' => $t['count']],
            array_filter(self::subItems($r), static fn (array $t): bool => $t['more'] && !$t['current'])));
    }

    /** @return list<array{label: string, href: ?string, count: int, current: bool, more: bool}> every page of the current section in the sidebar */
    private static function subItems(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//nav[@aria-label="Main"]//ul[@class="nav-sub"]/li/*[contains(concat(" ", @class, " "), " nav-sub-item ")]') ?: [] as $t) {
            /** @var \DOMElement $t */
            if ($t->hasAttribute('aria-current')) {
                // Exact (DC32): "true" when one of its segments is the page, else "page".
                $segPage = $xp->query('following-sibling::ul//a[@aria-current="page"]', $t)->length > 0;
                self::assertSame($segPage ? 'true' : 'page', $t->getAttribute('aria-current'), 'a page of the section is the page, or holds it');
            }
            $out[] = ['label' => trim((string) $xp->evaluate('string(span[@class="nav-label"])', $t)), 'href' => $t->hasAttribute('href') ? $t->getAttribute('href') : null,
                'count' => (int) self::visibleText($xp, $xp->query('span[contains(concat(" ", @class, " "), " count ")]', $t)->item(0)),
                'current' => $t->hasAttribute('aria-current'), 'more' => str_contains(' ' . $t->getAttribute('class') . ' ', ' more ')];
        }
        return $out;
    }

    /** @return list<string> the labels of the page's tabs (sectionTabs) */
    protected static function tabLabels(UiResponse $r): array
    {
        return array_column(self::sectionTabs($r), 'label');
    }

    /** The label of the current tab, or null. */
    protected static function currentTab(UiResponse $r): ?string
    {
        foreach (self::sectionTabs($r) as $t) {
            if ($t['current']) {
                return $t['label'];
            }
        }
        return null;
    }

    /**
     * The current page's segments: the sidebar's second level under the current page (DC28): label, link (null for the segment of the page
     * shown: CV13), count, current.
     *
     * @return list<array{label: string, href: ?string, count: int, current: bool}>
     */
    protected static function segments(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//nav[@aria-label="Main"]//ul[contains(concat(" ", @class, " "), " nav-seg ")]/li/a') ?: [] as $a) {
            /** @var \DOMElement $a */
            $out[] = ['label' => trim((string) $xp->evaluate('string(span[@class="nav-label"])', $a)), 'href' => $a->hasAttribute('href') ? $a->getAttribute('href') : null,
                'count' => (int) self::visibleText($xp, $xp->query('span[contains(concat(" ", @class, " "), " count ")]', $a)->item(0)),
                'current' => $a->hasAttribute('aria-current')];
            if ($a->hasAttribute('aria-current')) {
                self::assertSame('page', $a->getAttribute('aria-current'), 'a segment that is current is the page (DC32)');
            }
        }
        return $out;
    }

    /**
     * The store choice of a page's Show row (docs/decisions.md DC33: the store tab row went; the same `channel` values): each store, the
     * address the Show button sends with it chosen ($path and the row's fields as the page drew them, empty ones left out, in the form's
     * order: as a browser sends a GET form), its count (the option's "(N)"), whether it is the one shown. The row is a GET form of the main
     * part; its button submits it without a script.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: bool}>
     */
    protected static function storeChoice(UiResponse $r, string $path): array
    {
        $xp = new \DOMXPath($r->dom());
        // The Show row of before, or (Reserved stock since the redesign, docs/decisions.md RD-A7) the head's More's filter form.
        $forms = $xp->query('//main//form[@method="get" and (contains(concat(" ", @class, " "), " toolbar ") or contains(concat(" ", @class, " "), " list-filters "))]'
            . '[.//select[@name="channel"]]');
        self::assertLessThanOrEqual(1, $forms->length, 'one Show row holds the store');
        $form = $forms->item(0);
        if (!$form instanceof \DOMElement) {
            return [];
        }
        self::assertSame(1, $xp->query('.//button[@type="submit"]', $form)->length, 'the Show button sends it without a script');
        $fields = [];
        foreach ($xp->query('.//input[@name] | .//select[@name]', $form) as $el) {
            /** @var \DOMElement $el */
            $type = strtolower($el->getAttribute('type'));
            if ($el->hasAttribute('disabled') || (in_array($type, ['checkbox', 'radio'], true) && !$el->hasAttribute('checked'))) {
                continue;
            }
            $value = $el->getAttribute('value');
            if ($el->nodeName === 'select') {
                $chosen = $xp->query('option[@selected]', $el)->item(0) ?? $xp->query('option', $el)->item(0);
                $value = $chosen instanceof \DOMElement ? $chosen->getAttribute('value') : '';
            }
            $fields[] = [$el->getAttribute('name'), $value];
        }
        $out = [];
        foreach ($xp->query('.//select[@name="channel"]/option', $form) as $o) {
            /** @var \DOMElement $o */
            preg_match('/^(.*?)\s*\((\d[\d,]*)\)$/u', trim((string) preg_replace('/\s+/u', ' ', $o->textContent)), $m);
            $query = [];
            foreach ($fields as [$name, $value]) {
                $value = $name === 'channel' ? $o->getAttribute('value') : $value;
                if ($value !== '') {
                    $query[] = rawurlencode($name) . '=' . rawurlencode($value);
                }
            }
            $out[] = [$m[1] ?? trim($o->textContent), $path . ($query === [] ? '' : '?' . implode('&', $query)), (int) str_replace(',', '', $m[2] ?? '0'),
                $o->hasAttribute('selected')];
        }
        return $out;
    }

    /** The phone tab bar of a page: its labels and links, in order. @return list<array{label: string, href: string}> */
    protected static function tabs(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//nav[@aria-label="Main tasks"]/a') ?: [] as $a) {
            /** @var \DOMElement $a */
            $out[] = ['label' => trim((string) $xp->evaluate('string(span[not(@class)])', $a)), 'href' => $a->getAttribute('href')];
        }
        return $out;
    }

    /** The text of a node without what only a screen reader hears ('' for none). */
    private static function visibleText(\DOMXPath $xp, ?\DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        $text = '';
        foreach ($xp->query('.//text()[not(ancestor::*[contains(concat(" ", @class, " "), " visually-hidden ")])]', $node) ?: [] as $t) {
            $text .= $t->textContent;
        }
        return trim((string) preg_replace('/[\s,]+/u', '', $text));
    }

    protected static function statusOf(UiResponse $r): string
    {
        return "HTTP {$r->status} " . ($r->location() ?? substr($r->text(), 0, 200));
    }
}
