<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\Auth\StaffIdentity;
use CW\Ui\Html;
use CW\Ui\View;
use PHPUnit\Framework\TestCase;

/**
 * Lints the /ui templates (src/Ui/views): nothing reaches the page except through an escaping
 * helper, and nothing in them needs a relaxed CSP (no inline script, style or event handler).
 * The integration tests prove the same on rendered pages; this fails earlier, on the source,
 * for a screen no test happens to reach.
 */
final class UiTemplatesTest extends TestCase
{
    private const HELPERS = ['e', 'n', 'dec', 'dt', 'u', 'pct', 'partial'];

    /** @return array<string, string> file name => source */
    private static function templates(): array
    {
        $out = [];
        foreach (glob(View::defaultDir() . '/*.php') ?: [] as $file) {
            $out[basename($file)] = (string) file_get_contents($file);
        }
        return $out;
    }

    public function testThereAreTemplatesToCheck(): void
    {
        $names = array_keys(self::templates());
        foreach (['layout', 'login', 'password', 'dashboard', 'queue', 'listing', 'item', 'search', 'pending', 'pending_actions', 'pending_decision', 'error',
            'home', 'people', 'person', 'documents', 'document', 'reviews', 'reasons', 'series', 'settings', 'suppliers', 'supplier', 'supplier_form',
            'supplier_items', 'supplier_item', 'supplier_item_form', 'purchase_orders', 'purchase_order', 'purchase_order_edit', 'reorder', 'reorder_item',
            'reorder_brands', 'reorder_anomalies', 'sales_history', 'company', 'company_form', 'duplicates', 'duplicate_group'] as $t) {
            self::assertContains($t . '.php', $names);
        }
        self::assertSame([], array_filter($names, static fn (string $n): bool => preg_match('/^[a-z][a-z_]*\.php$/', $n) !== 1), 'names View::render accepts');
    }

    public function testEveryPrintedValueGoesThroughAHelper(): void
    {
        $seen = 0;
        foreach (self::templates() as $name => $src) {
            preg_match_all('/<\?=(.*?)\?>/s', $src, $m);
            foreach ($m[1] as $expr) {
                $seen++;
                $expr = trim($expr);
                if ($name === 'layout.php' && $expr === '$body') {
                    continue; // the already-escaped page body
                }
                self::assertTrue(self::onlyHelperCalls($expr), "{$name}: `<?= {$expr} ?>` prints something that is not a helper call");
            }
        }
        self::assertGreaterThan(100, $seen, 'the lint found the echoes');
    }

    public function testTemplatesNeverEchoOrRunCodeOutsideTheHelpers(): void
    {
        foreach (self::templates() as $name => $src) {
            $php = implode("\n", self::phpBlocks($src));
            self::assertDoesNotMatchRegularExpression('/\b(echo|print|printf|print_r|var_dump|var_export|exit|die|eval|include|require|include_once|require_once|file_get_contents|file_put_contents|fopen|system|exec|shell_exec|passthru|popen|proc_open|header|setcookie|unserialize)\b/i', $php, "{$name}: a template only lays out what it is given");
            self::assertStringNotContainsString('<?php echo', $src, $name);
            self::assertStringNotContainsString('<?php print', $src, $name);
            self::assertStringNotContainsString('$_GET', $src, $name);
            self::assertStringNotContainsString('$_POST', $src, $name);
            self::assertStringNotContainsString('$_SERVER', $src, $name);
            self::assertStringNotContainsString('$_COOKIE', $src, $name);
        }
    }

    public function testNoInlineScriptStyleOrEventHandlerAndNothingThirdParty(): void
    {
        foreach (self::templates() as $name => $src) {
            $html = (string) preg_replace('/<\?.*?\?>/s', '', $src); // the markup around the code
            preg_match_all('/<script\b[^>]*>/i', $html, $scripts);
            foreach ($scripts[0] as $tag) {
                self::assertSame('layout.php', $name, 'only the layout loads the script');
                self::assertMatchesRegularExpression('#^<script src="/ui/assets/app\.js" defer>$#', $tag);
            }
            self::assertDoesNotMatchRegularExpression('#<script\b[^>]*>(?!\s*</script>)[^<]#i', $html, "{$name}: inline script");
            self::assertDoesNotMatchRegularExpression('/<style\b/i', $html, "{$name}: style element");
            self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, "{$name}: style attribute");
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, "{$name}: event handler attribute");
            self::assertDoesNotMatchRegularExpression('/javascript\s*:/i', $src, "{$name}: javascript: URL");
            self::assertDoesNotMatchRegularExpression('#(?:src|href|action|data|poster|srcset)\s*=\s*["\']?\s*(?:https?:)?//#i', $html, "{$name}: a third-party or protocol-relative reference");
            self::assertDoesNotMatchRegularExpression('/<(iframe|object|embed|base|meta\s+http-equiv)\b/i', $html, "{$name}: embedding element");
        }
    }

    public function testEveryPostFormCarriesTheCsrfToken(): void
    {
        $posts = 0;
        foreach (self::templates() as $name => $src) {
            preg_match_all('#<form\b([^>]*)>(.*?)</form>#is', $src, $forms, PREG_SET_ORDER);
            foreach ($forms as $f) {
                if (preg_match('/type="file"/i', $f[2]) === 1) {
                    // An upload (I-2): a multipart POST, else the browser sends the file name only.
                    self::assertMatchesRegularExpression('/method="post"/i', $f[1], "{$name}: a file upload is a POST");
                    self::assertMatchesRegularExpression('#enctype="multipart/form-data"#i', $f[1], "{$name}: a form with a file field must be multipart");
                }
                if (preg_match('/method="post"/i', $f[1]) === 1) {
                    $posts++;
                    self::assertMatchesRegularExpression('#<input type="hidden" name="csrf" value="<\?= \$e\(\$csrf\) \?>">#', $f[2], "{$name}: a POST form without the csrf field");
                } else {
                    self::assertMatchesRegularExpression('/method="get"/i', $f[1], "{$name}: a form that is neither GET nor POST");
                    self::assertStringNotContainsString('name="csrf"', $f[2], "{$name}: a token in a GET form would end up in URLs and logs");
                }
            }
        }
        self::assertGreaterThanOrEqual(11, $posts, 'login, password, logout, decide, approve, withdraw, person roles, person active, '
            . 'document approve, document reject, document reverse');
    }

    public function testThePageBodiesRenderWithHostileValuesInert(): void
    {
        // The helpers themselves, as the templates get them from View (no database needed).
        $view = new View(View::defaultDir(), ['csrf' => 'tok"><b>', 'who' => null]);
        $evil = '<script>alert(1)</script>"\'&';
        $html = $view->render('login', ['error' => $evil, 'email' => $evil]);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;&quot;&apos;&amp;', $html);
        self::assertStringNotContainsString('tok"><b>', $html);
        self::assertStringContainsString('tok&quot;&gt;&lt;b&gt;', $html);
    }

    /** I14: the layout draws the person's menu: live items as links (current one marked), placeholders as text, never links. */
    public function testTheLayoutDrawsTheMenuOfTheRoles(): void
    {
        $who = new StaffIdentity(7, 'b@test.invalid', 'Bea <b>', ['buyer', 'mapper'], false, 'sid');
        $view = new View(View::defaultDir(), ['csrf' => 'tok', 'who' => $who]);
        $html = $view->page('home', ['name' => $who->displayName, 'roles' => $who->rolesLabel(), 'noRoles' => false, 'sections' => Permissions::menu($who->roles)],
            ['title' => 'Home', 'active' => 'review', 'notice' => null, 'menu' => Permissions::menu($who->roles), 'badges' => ['linking_pending' => 3], 'searchBox' => true]);
        $nav = substr($html, (int) strpos($html, '<nav class="menu"'), (int) strpos($html, '</nav>') - (int) strpos($html, '<nav class="menu"'));
        self::assertStringContainsString('<span class="menu-label">Linking</span>', $nav);
        self::assertStringContainsString('<span class="menu-label">Purchasing</span>', $nav);
        self::assertStringContainsString('<a href="/ui/review?queue=Key" aria-current="page">Review</a>', $nav);
        self::assertStringContainsString('<a href="/ui/review?queue=pending">Second approval <span class="badge">3</span></a>', $nav);
        self::assertSame(0, substr_count($nav, '&middot; coming in Phase I-2</span>'), 'every Phase I-2 item is live since the reorder task');
        self::assertStringContainsString('<a href="/ui/purchasing/suppliers">Suppliers</a>', $nav, 'live since the I-2 suppliers task');
        self::assertStringContainsString('<a href="/ui/purchasing/orders">Purchase orders</a>', $nav, 'live since the I-2 pos task');
        self::assertStringContainsString('<a href="/ui/purchasing/reorder">Reorder list</a>', $nav, 'live since the I-2 reorder task');
        self::assertStringContainsString('<a href="/ui/purchasing/sales-history">Sales history</a>', $nav, 'live since the I-2 reorder task');
        self::assertDoesNotMatchRegularExpression('#<a [^>]*>[^<]*coming in Phase#', $html, 'a placeholder is never a link');
        self::assertStringNotContainsString('Admin', $nav);
        self::assertStringContainsString('<span class="role">buyer, mapper</span>', $html);
        self::assertStringContainsString('Bea &lt;b&gt;', $html);
        self::assertStringContainsString('action="/ui/search"', $html);

        $none = new StaffIdentity(8, 'n@test.invalid', 'Nobody', [], false, 'sid');
        $html = (new View(View::defaultDir(), ['csrf' => 'tok', 'who' => $none]))->page('home', ['name' => 'Nobody', 'roles' => 'no roles', 'noRoles' => true, 'sections' => []],
            ['title' => 'Home', 'active' => 'home', 'notice' => null, 'menu' => [], 'badges' => [], 'searchBox' => false]);
        self::assertStringNotContainsString('<nav class="menu"', $html);
        self::assertStringNotContainsString('action="/ui/search"', $html);
        self::assertStringContainsString('You have no roles yet: ask an admin', $html);
    }

    /** True when $expr is one or more helper calls joined by `.`: `$e($x)`, `$e($a) . $e($b)`. */
    private static function onlyHelperCalls(string $expr): bool
    {
        $i = 0;
        $len = strlen($expr);
        $re = '/\G\s*\$(' . implode('|', self::HELPERS) . ')\(/A';
        while (true) {
            if (preg_match($re, $expr, $m, 0, $i) !== 1) {
                return false;
            }
            $i += strlen($m[0]);
            $depth = 1;
            $quote = null;
            for (; $i < $len && $depth > 0; $i++) {
                $c = $expr[$i];
                if ($quote !== null) {
                    if ($c === '\\') {
                        $i++;
                    } elseif ($c === $quote) {
                        $quote = null;
                    }
                } elseif ($c === "'" || $c === '"') {
                    $quote = $c;
                } elseif ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    $depth--;
                }
            }
            if ($depth !== 0) {
                return false;
            }
            $rest = ltrim(substr($expr, $i));
            if ($rest === '') {
                return true;
            }
            if ($rest[0] !== '.') {
                return false;
            }
            $i = $len - strlen($rest) + 1;
        }
    }

    /** @return list<string> the code inside <?php ... ?> and <?= ... ?> */
    private static function phpBlocks(string $src): array
    {
        preg_match_all('/<\?(?:php|=)(.*?)\?>/s', $src, $m);
        return $m[1];
    }

    public function testTheLintItselfCatchesWhatItIsFor(): void
    {
        self::assertTrue(self::onlyHelperCalls('$e($r["a"])'));
        self::assertTrue(self::onlyHelperCalls('$e(implode(\', \', $r[\'x\'])) . $n($y)'));
        self::assertTrue(self::onlyHelperCalls('$u(\'/ui/x/\' . $id, [\'q\' => ")"])'));
        self::assertFalse(self::onlyHelperCalls('$title'));
        self::assertFalse(self::onlyHelperCalls('$e($a) . $b'));
        self::assertFalse(self::onlyHelperCalls('$b . $e($a)'));
        self::assertFalse(self::onlyHelperCalls('htmlspecialchars($a)'));
        self::assertFalse(self::onlyHelperCalls('$e($a'));
        self::assertFalse(self::onlyHelperCalls('$e($a) $e($b)'));
        self::assertSame('&lt;b&gt;', Html::e('<b>'));
    }
}
