<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\Auth\StaffIdentity;
use CW\Ui\HomeTasks;
use CW\Ui\Html;
use CW\Ui\Tabs;
use CW\Ui\View;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * Lints the /ui templates (src/Ui/views): nothing reaches the page except through an escaping
 * helper, and nothing in them needs a relaxed CSP (no inline script, style or event handler).
 * The integration tests prove the same on rendered pages; this fails earlier, on the source,
 * for a screen no test happens to reach.
 */
final class UiTemplatesTest extends TestCase
{
    private const HELPERS = ['e', 'n', 'dec', 'dt', 'u', 'pct', 'partial', 'word', 'say', 'money', 'day', 'when', 'jobs', 'chip', 'stateChip', 'intro', 'explain', 'cards', 'empty'];

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
        foreach (['layout', 'login', 'password', 'matching_progress', 'queue', 'listing', 'listing_form', 'listing_decide', 'item', 'search', 'pending', 'pending_actions', 'pending_decision', 'error',
            'home', 'people', 'person', 'documents', 'document', 'reviews', 'reasons', 'series', 'settings', 'suppliers', 'supplier', 'supplier_form',
            'supplier_items', 'supplier_item', 'supplier_item_form', 'purchase_orders', 'purchase_order', 'purchase_order_edit', 'reorder', 'reorder_item',
            'reorder_brands', 'reorder_anomalies', 'sales_history', 'company', 'company_form', 'duplicates', 'duplicate_group', 'item_cards', 'item_card_form',
            'item_cards_import', 'barcode_reviews', 'cards'] as $t) {
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

    /**
     * Phone first (plan rule 14, F100, F422, F432, F450): every table of the start and settings pages is a `table.stack` (one card
     * per row under 640 px), and each of its data cells says what it is (`data-label`) unless it is the card's head or status.
     */
    public function testTheStartAndSettingsTablesTurnIntoCardsOnAPhone(): void
    {
        $templates = self::templates();
        foreach (['reviews', 'documents', 'document', 'settings', 'reasons', 'series', 'people', 'person'] as $name) {
            $src = $templates[$name . '.php'];
            preg_match_all('/<table\b[^>]*>/i', $src, $tables);
            self::assertNotSame([], $tables[0], $name);
            foreach ($tables[0] as $tag) {
                self::assertMatchesRegularExpression('/class="[^"]*\bstack\b/', $tag, "{$name}: {$tag}");
            }
            preg_match_all('/<td\b[^>]*>/i', $src, $cells);
            foreach ($cells[0] as $td) {
                self::assertMatchesRegularExpression('/data-label=|class="c-(status|next|head)"/', $td, "{$name}: {$td} says what it is on a phone");
            }
            self::assertStringNotContainsString('(UTC)', $src, "{$name}: UK time, never UTC");
            self::assertStringNotContainsString('$dt(', $src, "{$name}: \$when / \$day for people, \$dt is for files");
        }
    }

    /**
     * The matching pages (plan §6.9-6.12, 6.14-6.18, rules 2, 10, 14, 19): every list is a `table.stack` whose cells say what they are
     * on a phone (a comparison that must keep its columns scrolls inside a `.scroll` instead), dates are UK time, and no word is
     * typed in the template: every word comes from Words. On the product page this holds for its matching and stock parts (the
     * product card and the suppliers block belong to other areas).
     */
    public function testTheMatchingPagesTurnIntoCardsAndTakeEveryWordFromWords(): void
    {
        $templates = self::templates();
        foreach (['queue', 'listing', 'listing_form', 'listing_decide', 'pending', 'pending_actions', 'pending_decision', 'samples', 'sample', 'duplicates', 'duplicate_group',
            'search', 'item'] as $name) {
            $src = $templates[$name . '.php'];
            preg_match_all('/(<div class="scroll">\s*)?<table\b([^>]*)>/i', $src, $tables, PREG_SET_ORDER);
            foreach ($tables as $t) {
                self::assertTrue(preg_match('/class="[^"]*\bstack\b/', $t[2]) === 1 || $t[1] !== '', "{$name}: {$t[0]} is cards on a phone, or scrolls in its box");
            }
            if ($name !== 'duplicate_group') {
                preg_match_all('/<td\b[^>]*>/i', $src, $cells);
                foreach ($cells[0] as $td) {
                    self::assertMatchesRegularExpression('/data-label=|class="[^"]*\bc-(status|next|head)\b/', $td, "{$name}: {$td} says what it is on a phone");
                }
            }
            self::assertStringNotContainsString('(UTC)', $src, "{$name}: UK time, never UTC");
            self::assertStringNotContainsString('$dt(', $src, "{$name}: \$when / \$day for people, \$dt is for files");
            $text = (string) preg_replace(['/<\?.*?\?>/s', '/<[^>]*>/', '/&[a-z]+;/'], ' ', $src);
            self::assertSame([], preg_match_all('/[A-Za-z]{2,}/', $text, $m) > 0 ? $m[0] : [], "{$name}: a word typed in the template instead of taken from Words");
        }
    }

    /**
     * The buying pages and the product pages (plan §6.19-6.30, IM3; rules 2, 9, 10, 14, 19): every table is a `table.stack` whose
     * cells say what they are on a phone (or scrolls inside a `.scroll`), dates are UK time, money goes through $money / Html::money,
     * and no word is typed in the template: every word comes from Words (import column names come from the services, in <code>).
     */
    public function testTheBuyingAndProductPagesTurnIntoCardsAndTakeEveryWordFromWords(): void
    {
        $templates = self::templates();
        foreach (['purchase_orders', 'purchase_order', 'purchase_order_edit', 'reorder', 'reorder_item', 'reorder_brands', 'reorder_anomalies', 'sales_history',
            'suppliers', 'supplier', 'supplier_form', 'supplier_items', 'supplier_item', 'supplier_item_form', 'item_cards', 'item_card_form', 'item_cards_import',
            'barcode_reviews'] as $name) {
            $src = $templates[$name . '.php'];
            preg_match_all('/(<div class="scroll">\s*)?<table\b([^>]*)>/i', $src, $tables, PREG_SET_ORDER);
            foreach ($tables as $t) {
                self::assertTrue(preg_match('/class="[^"]*\bstack\b/', $t[2]) === 1 || $t[1] !== '', "{$name}: {$t[0]} is cards on a phone, or scrolls in its box");
            }
            preg_match_all('/<td\b[^>]*>/i', $src, $cells);
            foreach ($cells[0] as $td) {
                self::assertMatchesRegularExpression('/data-label=|class="[^"]*\bc-(status|next|head)\b/', $td, "{$name}: {$td} says what it is on a phone");
            }
            self::assertStringNotContainsString('(UTC)', $src, "{$name}: UK time, never UTC");
            self::assertStringNotContainsString('$dt(', $src, "{$name}: \$when / \$day for people, \$dt is for files");
            self::assertStringNotContainsString('(GBP)', $src, "{$name}: money is £ (plan F299)");
            $text = (string) preg_replace(['/<\?.*?\?>/s', '/<[^>]*>/', '/&[a-z]+;/'], ' ', $src);
            self::assertSame([], preg_match_all('/[A-Za-z]{2,}/', $text, $m) > 0 ? $m[0] : [], "{$name}: a word typed in the template instead of taken from Words");
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

    /**
     * I14, plan §2: the layout draws the person's task-based menu (Home first, live items as links, the current one marked, a
     * badge with words for a screen reader), the account panel with the jobs in words, the find box in the menu, the phone tab
     * bar after the menu, and the two strips. Nothing not built yet is in the menu.
     */
    public function testTheLayoutDrawsTheMenuOfTheRoles(): void
    {
        $who = new StaffIdentity(7, 'b@test.invalid', 'Bea <b>', ['buyer', 'mapper'], false, 'sid');
        $view = new View(View::defaultDir(), ['csrf' => 'tok', 'who' => $who]);
        $menu = Permissions::menu($who->roles);
        $html = $view->page('home', self::homeVars($who),
            ['title' => 'Home', 'active' => 'review', 'notice' => null, 'menu' => $menu, 'badges' => ['linking_pending' => 3], 'searchBox' => true,
                'tabs' => Tabs::of($menu), 'testSystem' => false, 'switchedOff' => null]);
        $start = (int) strpos($html, '<nav class="menu"');
        self::assertGreaterThan(0, $start);
        $nav = substr($html, $start, (int) strpos($html, '</nav>', $start) - $start);
        self::assertStringContainsString('<div class="menu-group menu-top">', $nav, 'Home: a single link, first');
        self::assertStringContainsString('<a href="/ui/">Home</a>', $nav);
        self::assertStringContainsString('<span class="menu-label">Match products</span>', $nav);
        self::assertStringContainsString('<span class="menu-label">Buying</span>', $nav);
        self::assertStringContainsString('<a href="/ui/review?queue=Key" aria-current="page">Products to match</a>', $nav);
        self::assertStringContainsString('<a href="/ui/review?queue=pending">Waiting for 2nd OK <span class="badge" title="3 waiting for your second OK">3'
            . '<span class="visually-hidden"> waiting for your second OK</span></span></a>', $nav, 'the badge says what the number is');
        self::assertStringContainsString('<a href="/ui/purchasing/suppliers">Suppliers</a>', $nav, 'live since the I-2 suppliers task');
        self::assertStringContainsString('<a href="/ui/purchasing/orders">Purchase orders</a>', $nav, 'live since the I-2 pos task');
        self::assertStringContainsString('<a href="/ui/purchasing/reorder">What to buy</a>', $nav, 'live since the I-2 reorder task');
        self::assertStringContainsString('<a href="/ui/purchasing/sales-history">Sales data</a>', $nav, 'live since the I-2 reorder task');
        self::assertStringContainsString('action="/ui/search"', $nav, 'the find box sits in the menu');
        self::assertStringNotContainsString('Phase', $html, 'nothing not built yet in the menu, and no phase codes');
        self::assertStringNotContainsString('class="soon"', $html);
        self::assertStringNotContainsString('Staff', $nav);
        self::assertStringContainsString('<span class="role">Buyer · Matcher</span>', $html, 'the jobs in words, not role codes');
        $account = substr($html, (int) strpos($html, '<details class="me">'), (int) strpos($html, '</details>') - (int) strpos($html, '<details class="me">'));
        self::assertStringNotContainsString('buyer, mapper', $account, 'no role codes in the frame');
        self::assertStringContainsString('Bea &lt;b&gt;', $html);
        self::assertStringContainsString('<form method="post" action="/ui/logout">', $html);
        self::assertStringContainsString('<a class="skip" href="#main">', $html, 'a skip link first');
        self::assertStringContainsString('<main id="main"', $html);
        // The tab bar comes after the menu (the slice above ends at the menu's own </nav>).
        $tabs = (int) strpos($html, '<nav class="tabbar" aria-label="Main tasks">');
        self::assertGreaterThan($start, $tabs);
        self::assertStringContainsString('<a href="/ui/review?queue=Key" aria-current="page">', substr($html, $tabs), 'the Matches tab is current');
        self::assertStringContainsString('<a href="#menu">', substr($html, $tabs), 'More opens the whole menu');
        self::assertStringNotContainsString('test-system', $html);
        self::assertStringNotContainsString('admin-off', $html);
        self::assertStringNotContainsString('<svg', $html, 'the icons are CSS shapes: a page has no svg (UiSecurityTest::assertInert)');

        // The test system and the owner's account with Admin: the two strips.
        $owner = new StaffIdentity(9, 'o@test.invalid', 'Owner', ['admin', 'mapping_lead', 'reviewer'], false, 'sid');
        $menu = Permissions::menu($owner->roles);
        $html = (new View(View::defaultDir(), ['csrf' => 'tok', 'who' => $owner]))->page('home', self::homeVars($owner), ['title' => 'Home', 'active' => 'home', 'notice' => null, 'menu' => $menu, 'badges' => [], 'searchBox' => true,
            'tabs' => Tabs::of($menu), 'testSystem' => true, 'switchedOff' => Words::switchedOffNote($owner->roles)]);
        self::assertStringContainsString('<p class="strip test-system" role="note">TEST SYSTEM: nothing here is real</p>', $html);
        self::assertStringContainsString('Your Reviewer and Matching lead jobs are switched off because this account also has Admin. '
            . 'Ask Fazil to take Admin off this account.', $html);
        self::assertStringContainsString('<details class="help"><summary aria-label="What does &quot;switched off&quot; mean?"', $html, 'with its "?"');
        self::assertStringContainsString('<span class="role">Admin · Matching lead (off) · Reviewer (off)</span>', $html);

        $none = new StaffIdentity(8, 'n@test.invalid', 'Nobody', [], false, 'sid');
        $html = (new View(View::defaultDir(), ['csrf' => 'tok', 'who' => $none]))->page('home', self::homeVars($none),
            ['title' => 'Home', 'active' => 'home', 'notice' => null, 'menu' => [], 'badges' => [], 'searchBox' => false]);
        self::assertStringNotContainsString('<nav class="menu"', $html);
        self::assertStringNotContainsString('<nav class="tabbar"', $html);
        self::assertStringNotContainsString('action="/ui/search"', $html);
        self::assertStringContainsString(Words::TASK['no_job']['text'], $html);
        self::assertStringContainsString('<form method="post" action="/ui/logout">', $html, 'sign out is always there');
    }

    /** The variables DashboardController gives home.php, for $who with nothing waiting (no database here). @return array<string, mixed> */
    private static function homeVars(StaffIdentity $who): array
    {
        $home = HomeTasks::build($who->id, $who->roles, []);
        return ['hello' => sprintf(Words::HOME['hello'], $who->displayName), 'myRoles' => $who->roles, 'tasks' => $home['jobs'], 'notes' => $home['notes'],
            'summary' => null, 'aboutOpen' => true, 'about' => Words::ABOUT, 'progress' => null, 'uses' => [], 'later' => ''];
    }

    /** A template variable named like a helper would silently replace it (or be replaced): View refuses it. */
    public function testAVariableNamedLikeAHelperIsRefused(): void
    {
        $view = new View(View::defaultDir(), ['csrf' => 'tok', 'who' => null]);
        foreach (self::HELPERS as $helper) {
            try {
                $view->render('error', ['status' => 404, 'code' => 'x', 'heading' => 'h', 'message' => 'm', 'rid' => 'r', $helper => 'oops']);
                self::fail("{$helper}: a variable named like a helper was accepted");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($helper, $e->getMessage());
            }
        }
    }

    /** The plain-words helpers: escaped, and only the markup they are for. */
    public function testTheWordHelpersEscapeAndDrawTheirParts(): void
    {
        $view = new View(View::defaultDir(), ['csrf' => 'tok', 'who' => null]);
        $list = [
            ['job' => 1, 'hero' => true, 'tone' => 'needs', 'chip' => 'Needs you', 'title' => 'Check <20>', 'count' => 0, 'unit' => 'of 20 checked',
                'progress' => [0, 20], 'text' => 'If all 20 are right, the rest are confirmed together.', 'what' => 'You see one at a time.',
                'href' => '/ui/review/samples/1', 'button' => 'Check the next one'],
            ['tone' => 'waiting', 'title' => 'Bulk link', 'quiet' => true],
        ];
        $html = $view->render('cards', ['list' => $list]);
        self::assertStringContainsString('<ol class="cards">', $html);
        self::assertSame(2, substr_count($html, '<li class="card task'));
        self::assertStringContainsString('<p class="task-step">Job 1 <span class="start-tag">Start here</span></p>', $html, 'B\'s job number; the first is "Start here"');
        self::assertStringContainsString('<h3>Check &lt;20&gt;</h3>', $html);
        self::assertStringContainsString('<span class="chip needs">Needs you</span>', $html);
        self::assertStringContainsString('<p class="task-what"><strong>What happens:</strong> You see one at a time.</p>', $html, 'B\'s "What happens:" line');
        self::assertStringContainsString('<a class="btn primary" href="/ui/review/samples/1">Check the next one</a>', $html);
        self::assertStringContainsString('<progress class="bar" value="0" max="20"', $html);
        self::assertSame(1, substr_count($html, 'class="btn'), 'the second card has no button');
        self::assertSame('<span class="chip info">x &amp; y</span>', Html::chip('nonsense', 'x & y'), 'an unknown tone is info');
        $help = Html::help('how_sure', 'How sure');
        self::assertStringStartsWith('<details class="help"><summary aria-label="What does &quot;How sure&quot; mean?"', $help);
        self::assertStringContainsString('<strong>Strong match:</strong>', $help);
        self::assertStringNotContainsString('**', $help);
        self::assertSame('<div class="empty"><p class="empty-title">No &lt;orders&gt;</p><p>Add a supplier first.</p>'
            . '<p><a class="btn primary" href="/ui/purchasing/suppliers/new">Add a supplier</a></p></div>',
            Html::emptyState('No <orders>', 'Add a supplier first.', '/ui/purchasing/suppliers/new', 'Add a supplier'));
        self::assertSame('', Html::intro(''));
        self::assertSame('<p class="lede">What is waiting for you today. Start with the top card.</p>', Html::intro(Words::intro('home')));
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
