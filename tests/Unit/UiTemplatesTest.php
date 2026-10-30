<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\StaffIdentity;
use CW\Ui\HomeTasks;
use CW\Ui\Html;
use CW\Ui\Sections;
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
    private const HELPERS = ['e', 'n', 'dec', 'dt', 'u', 'pct', 'partial', 'word', 'say', 'money', 'day', 'when', 'jobs', 'chip', 'stateChip', 'intro', 'explain',
        'cards', 'empty', 'head'];

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
        foreach (['layout', 'login', 'password', 'matching_coverage', 'queue', 'listing', 'listing_form', 'listing_decide', 'item', 'search', 'pending', 'pending_actions', 'pending_decision', 'error',
            'home', 'people', 'person', 'documents', 'document', 'reviews', 'reasons', 'series', 'settings', 'suppliers', 'supplier', 'supplier_form',
            'supplier_items', 'supplier_item', 'supplier_item_form', 'purchase_orders', 'purchase_order', 'purchase_order_edit', 'reorder', 'reorder_item',
            'reorder_brands', 'reorder_anomalies', 'sales_history', 'company', 'company_form', 'duplicates', 'duplicate_group', 'item_cards', 'item_card_form',
            'item_cards_import', 'barcode_reviews', 'cards', 'receipts', 'receipt', 'receipt_edit', 'receipt_files', 'bench_list', 'incidents',
            'setting', 'config_history', 'approvals', 'reason', 'warehouses', 'warehouse', 'sites', 'integrity', 'audit', 'access', 'staff_sheet', 'enrol',
            'staff_requests', 'new_code', 'stock', 'stock_movements', 'mapping_overview', 'store_products', 'store_seg', 'bulk_bar', 'bulk_confirm', 'bulk_result',
            'stock_ops', 'stock_op', 'stock_op_fields', 'stock_accounts', 'decide_box', 'stock_reservations', 'stock_reservation', 'by_product', 'by_product_map',
            'by_product_options', // By Item's quick match: the fragment app.js draws into a cell (the owner's request of 20 Oct 2026)
            'by_item_preview', 'new_variants', 'one_at_a_time',
            'simple_match', 'simple_match_options', // Products › Simple matching and its cell's choices (docs/decisions.md SM1-SM9)
            'po_products', // the purchase order's product box: its choices (docs/decisions.md PX8)
            'invoice_edit', 'doc_totals', 'doc_party', 'doc_bar', // the supplier invoice on one page and the shared document pieces (docs/decisions.md PV)
            'po_action', 'si_action', // the approved design: one action of a saved order or invoice, in its bar or under More (docs/decisions.md PV19)
            'store_new_preview', // Store Products' "Create new warehouse products for these" (docs/decisions.md NF7)
            'listing_check', 'check_fold', // the lists' Check & match fold and its fragment (docs/decisions.md CM1)
            'stock_counts', 'stock_count_sheets', 'stock_count_reviews', 'stock_count', 'stock_count_before',
            'sales_orders', 'sales_order', 'sales_dispatch', 'sales_returns', // pack D
            'sales_order_bar', // an order's bar in its head (docs/decisions.md W10)
            'home_find', // Home's Find box (docs/decisions.md HV1; the bell went with HM_Home's shell, DC7)
            // Suppliers and buying on the approved patterns (docs/decisions.md W9): the bars, the supplier form's field, the products board
            'supplier_bar', 'supplier_field', 'supplier_items_board', 'supplier_items_bar', 'return_bar', 'return_files', 'reorder_bar', 'low_stock_bar',
            'recheck_bar', 'export_bar',
            'page_frame'] as $t) { // design v5: what the statement draws under itself
            self::assertContains($t . '.php', $names);
        }
        self::assertSame([], array_filter($names, static fn (string $n): bool => preg_match('/^[a-z][a-z_]*\.php$/', $n) !== 1), 'names View::render accepts');
    }

    public function testEveryPrintedValueGoesThroughAHelper(): void
    {
        $seen = 0;
        foreach (self::templates() as $name => $src) {
            foreach (self::echoes($src) as $expr) {
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

    /**
     * Codex's ninth review of ui-v5 (an older gap): the echo lint trusts a helper by its name, so a template that rebinds one prints
     * what it likes: `<?php $e = static fn ($v) => $v; ?><?= $e($row['name']) ?>` printed a raw value. In a template's code a helper
     * variable is only ever called (`$e(`); every other use is refused (helperMisuse()): an assignment, a loop, `catch` or parameter
     * variable, a reference, destructuring, `global` or `static`, a closure's `use`, `unset`, a by-reference argument. So is what writes
     * a variable by its name: `extract()` (also through `call_user_func` or a name PHP folds from literals) and `$$x`, `${'e'}`. And a
     * template declares nothing (Codex's review of this lint): a namespace, a named function, a class, `define()` or `class_alias()`
     * could replace what a helper runs (Html::e's unqualified `htmlspecialchars()` and `ENT_QUOTES` resolve in CW\Ui first).
     */
    public function testAHelperIsOnlyEverCalled(): void
    {
        foreach (self::templates() as $name => $src) {
            self::assertSame([], self::helperMisuse($src), "{$name}: a helper variable used other than by calling it");
        }
        // The lint itself. Codex's template: the echo lint reads its echo alone and accepts it; the write before it is refused.
        $codex = "<?php \$e = static fn (\$v) => \$v; ?>\n<?= \$e(\$row['name']) ?>\n";
        self::assertTrue(self::onlyHelperCalls(trim(self::echoes($codex)[0])), 'the echo alone is a helper call');
        self::assertSame(['line 1: `$e` then `=`, not a call'], self::helperMisuse($codex));
        self::assertNotSame([], self::helperMisuse("<?php \$f = function (\$e) { ?><p><?= \$e(\$row['name']) ?></p><?php }; \$f('strval'); ?>"),
            'a parameter named like a helper, its body across blocks');
        foreach (['$e .= \'\';', '$e ??= \'strval\';', '$e++;', '$e /* a note */ = \'strval\';', // assignment, compound assignment
            'foreach ($rows as $e): endforeach;', 'foreach ($rows as $k => $e): endforeach;', 'foreach ($rows as &$e): endforeach;',
            'foreach ($rows as [\'a\' => $e]): endforeach;', // loops
            '$x = &$e; $x = \'strval\';', '$x = [&$e];', '$f = fn (&$x) => 1; $f($e);', // references
            '[$e] = [\'strval\'];', '[, $e] = [1, \'strval\'];', '[\'k\' => $e] = $parts;', 'list($e) = [\'strval\'];', 'list(, list($e)) = $parts;',
            'global $e;', 'static $e = \'strval\';', '$f = function () use (&$e) { $e = \'strval\'; }; $f();', 'unset($e);',
            'preg_match(\'/(.*)/\', $s, $e);', 'try { } catch (\Throwable $e) { }', '$f = fn ($e) => $e($row[\'name\']);',
            // What writes a variable by its name (PHP refuses extract() called through a variable, not these).
            'extract([\'e\' => \'strval\']);', '\extract([\'e\' => \'strval\']);', 'EXTRACT([\'e\' => \'strval\']);', 'namespace\extract([\'e\' => \'strval\']);',
            'call_user_func(\'extract\', [\'e\' => \'strval\']);', 'call_user_func_array(\'extract\', [[\'e\' => \'strval\']]);',
            '(\'ext\' . \'ract\')([\'e\' => \'strval\']);', '\'extract\'([\'e\' => \'strval\']);', '"extract"([\'e\' => \'strval\']);',
            '$$name = \'strval\';', '${\'e\'} = \'strval\';',
            // Codex's review of this lint: what replaces what a helper runs (Html::e's unqualified htmlspecialchars() and ENT_QUOTES
            // resolve in CW\Ui first; a class not loaded yet can be declared first).
            'namespace CW\Ui; function htmlspecialchars($v, ...$o) { return $v; }', 'define(\'CW\Ui\ENT_QUOTES\', 0);', 'function f() { }',
            'function &f() { }', 'class_alias(\'Fake\', \'CW\Ui\Words\');', 'final class Words { }', 'readonly class Words { }', 'interface I { }', 'trait T { }', 'enum E { }'] as $code) {
            self::assertNotSame([], self::helperMisuse('<?php ' . $code . ' ?>'), "refused: {$code}");
        }
        self::assertSame(['line 1: a declaration (`namespace`)', 'line 2: a declaration (`function`)'],
            self::helperMisuse("<?php namespace CW\\Ui;\nfunction htmlspecialchars(\$v, ...\$options) { return \$v; }\n?><?= \$e(\$row['name']) ?>"),
            'Codex\'s template: the escaping function replaced');
        foreach (self::HELPERS as $helper) {
            self::assertNotSame([], self::helperMisuse("<?php \${$helper} = 'strval'; ?>"), "refused: \${$helper} = ...");
        }
        foreach (["<?= \$e(\$row['name']) ?>", "<p><?= \$e /* why */ (\$x) . \$n(\$y) ?></p>", "<?php \$chips = \$chip('done', \$t) . \$explain('k'); ?>",
            "<?php foreach (\$uses as \$use): ?><?= \$e(\$use['name']) ?><?php endforeach; ?>",
            "<?php \$title = static fn (array \$r): string => \$r['t']; \$every = \$explained = 1; ?><?= \$e(\$title(\$row)) ?>",
            "<p>\$e = 1 is markup</p><?php /* \$e = 1; extract(\$x); */ ?><?= \$e('\$e = 1, \$\$x, extract()') ?>",
            // Codex's review of this lint: a control structure's condition is no callee; `::class` and `new class` declare nothing.
            "<?php if (\$ok) (\$label = 'ok'); ?><?= \$e(\$label) ?>", "<?php if ((\$a)) { } elseif (\$b) (\$c = 1); foreach (\$rows as \$r) (\$d = 1); while (\$x) (\$y = 2); ?>",
            "<?php for (\$i = 0; \$i < 2; \$i++) (\$j = \$i); switch (\$k) { } (\$m = 1); ?>", "<?= \$e(\\CW\\Ui\\Words::class) ?><?php \$o = new class { }; ?>",
            // Its second review: a closure returning by reference, an anonymous class with a modifier or an attribute declare no name.
            "<?php \$f = function &() { static \$x = 1; return \$x; }; ?><?= \$e(\$f()) ?>", "<?php \$o = new readonly class { }; \$p = new #[\\AllowDynamicProperties] class { }; ?>"] as $tpl) {
            self::assertSame([], self::helperMisuse($tpl), "accepted: {$tpl}");
        }
    }

    public function testNoInlineScriptStyleOrEventHandlerAndNothingThirdParty(): void
    {
        foreach (self::templates() as $name => $src) {
            $html = (string) preg_replace('/<\?.*?\?>/s', '', $src); // the markup around the code
            preg_match_all('/<script\b[^>]*>/i', $html, $scripts);
            foreach ($scripts[0] as $tag) {
                self::assertSame('layout.php', $name, 'only the layout loads the script');
                // The one script, by its versioned URL (Assets::url, the code stripped from $html leaves the empty attribute).
                self::assertMatchesRegularExpression('#^<script src="" defer>$#', $tag);
                self::assertStringContainsString('<script src="<?= $e(\\CW\\Ui\\Assets::url(\'app.js\')) ?>" defer></script>', $src);
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
            'search', 'item', 'item_bar', 'mapping_overview', 'store_products', 'store_seg', 'bulk_bar', 'bulk_confirm', 'bulk_result', 'by_product', 'by_product_map',
            'by_product_options', 'by_item_preview', 'new_variants', 'one_at_a_time', 'listing_check', 'check_fold', 'search_note', 'store_new_preview',
            'simple_match', 'simple_match_options'] as $name) {
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
            'barcode_reviews', 'stock', 'stock_movements', 'stock_reservations', 'stock_reservation',
            'sales_orders', 'sales_order', 'sales_dispatch', 'sales_returns', // pack D (docs/decisions.md PD13)
            'sales_order_bar', 'report_sales', // wave w10: an order's bar; Sales by Store on the report pattern (docs/decisions.md W10)
            'po_supplier_needs', 'po_products', // pack PO (PO3); PO on one page (PX8): the product box's choices
            'invoice_edit', 'doc_totals', 'doc_party', 'doc_bar', // the supplier invoice on one page, the shared document pieces (PV)
            'po_action', 'si_action', // the approved design: a saved order's or invoice's actions (PV19)
            // Suppliers and buying on the approved patterns (docs/decisions.md W9)
            'supplier_bar', 'supplier_field', 'supplier_items_board', 'supplier_items_bar', 'return_bar', 'return_files', 'reorder_bar', 'low_stock_bar',
            'recheck_bar', 'export_bar', 'arrivals', 'invoices_timed', 'reorder_suppliers', 'reorder_low_stock'] as $name) {
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

    /**
     * The delivery pages (IM6: Receive + invoice, a delivery and its editor, the goods-in bench, incidents; the plain-words pass of
     * U85-U90): every table is a `table.stack` whose cells say what they are on a phone, times are UK time in the screens' one form
     * ($when, not $dt or main's $uk), no "(GBP)", and no word is typed in the template: every word comes from Words.
     */
    public function testTheDeliveryPagesTurnIntoCardsAndTakeEveryWordFromWords(): void
    {
        $templates = self::templates();
        foreach (['receipts', 'receipt', 'receipt_edit', 'receipt_files', 'bench_list', 'incidents', 'receipt_split', 'receipt_new', 'gi_more'] as $name) { // receipt_split: pack PO; receipt_edit, receipt_new, gi_more: one page (GV)
            $src = $templates[$name . '.php'];
            preg_match_all('/(<div class="scroll">\s*)?<table\b([^>]*)>/i', $src, $tables, PREG_SET_ORDER);
            foreach ($tables as $t) {
                self::assertMatchesRegularExpression('/class="[^"]*\bstack\b/', $t[2], "{$name}: {$t[0]} is cards on a phone");
            }
            preg_match_all('/<td\b[^>]*>/i', $src, $cells);
            foreach ($cells[0] as $td) {
                self::assertMatchesRegularExpression('/data-label=|class="[^"]*\bc-(status|next|head)\b/', $td, "{$name}: {$td} says what it is on a phone");
            }
            foreach (['(UTC)', '$dt(', '$uk(', '(GBP)', 'MiB'] as $old) {
                self::assertStringNotContainsString($old, $src, "{$name}: {$old}");
            }
            $text = (string) preg_replace(['/<\?.*?\?>/s', '/<[^>]*>/', '/&[a-z]+;/'], ' ', $src);
            self::assertSame([], preg_match_all('/[A-Za-z]{2,}/', $text, $m) > 0 ? $m[0] : [], "{$name}: a word typed in the template instead of taken from Words");
        }
        // The browser's prompts of these pages come from Words too (app.js reads them from data- attributes).
        self::assertStringContainsString('data-unsaved-text=', $templates['receipt_edit.php']);
        self::assertStringContainsString('data-not-here=', $templates['receipt_edit.php']);
        self::assertArrayNotHasKey('receipt_bench.php', $templates, 'the bench check is part of the one delivery page (docs/decisions.md GV2)');
        self::assertStringContainsString('data-too-big=', $templates['receipt_files.php']);
    }

    /**
     * The set-it-yourself pages (0019; docs/decisions.md Y36): every table is a `table.stack` whose cells say what they are on a phone,
     * times are UK time ($when), and no word is typed in the template: every word comes from Words (the website commands come from
     * Admin\Sites, in <code>). The QR code is markup only: no image, no SVG, no inline style.
     */
    public function testTheSetItYourselfPagesTurnIntoCardsAndTakeEveryWordFromWords(): void
    {
        $templates = self::templates();
        foreach (['setting', 'config_history', 'approvals', 'reasons', 'reason', 'warehouses', 'warehouse', 'sites', 'integrity', 'audit', 'access', 'staff_sheet',
            'enrol', 'new_code', 'staff_requests', 'people', 'person', 'settings'] as $name) {
            $src = $templates[$name . '.php'];
            preg_match_all('/<table\b([^>]*)>/i', $src, $tables, PREG_SET_ORDER);
            foreach ($tables as $t) {
                self::assertMatchesRegularExpression('/class="[^"]*\bstack\b/', $t[1], "{$name}: {$t[0]} is cards on a phone");
            }
            preg_match_all('/<td\b[^>]*>/i', $src, $cells);
            foreach ($cells[0] as $td) {
                self::assertMatchesRegularExpression('/data-label=|class="[^"]*\bc-(status|next|head)\b/', $td, "{$name}: {$td} says what it is on a phone");
            }
            foreach (['(UTC)', '$dt(', '<svg', '<img', 'data:'] as $old) {
                self::assertStringNotContainsString($old, $src, "{$name}: {$old}");
            }
            if (in_array($name, ['people', 'person', 'settings'], true)) {
                continue; // older pages: their words were checked by the start and settings pass (U41)
            }
            $text = (string) preg_replace(['/<\?.*?\?>/s', '/<[^>]*>/', '/&[a-z]+;/'], ' ', $src);
            self::assertSame([], preg_match_all('/[A-Za-z]{2,}/', $text, $m) > 0 ? $m[0] : [], "{$name}: a word typed in the template instead of taken from Words");
        }
        self::assertStringContainsString('<i class="d"></i>', $templates['staff_sheet.php'], 'the QR code is one element per module');
        self::assertStringContainsString('<i class="d"></i>', $templates['new_code.php'], 'the person\'s own page draws its fresh code the same way');
    }

    /**
     * The stock records' pages (pack A1: Stock In, Stock Out, Adjustments, Transfers, Releases, the balance owed; docs/decisions.md SO10):
     * every table is a `table.stack` whose cells say what they are on a phone, times are UK time, no "(GBP)", and no word is typed in the
     * template: every word comes from Words (a kind's words are handed in from Words::STOCK_KIND).
     */
    public function testTheStockRecordPagesTurnIntoCardsAndTakeEveryWordFromWords(): void
    {
        $templates = self::templates();
        // The counting pages of pack A2a (Stock > Counts) are held to the same rules.
        foreach (['stock_ops', 'stock_op', 'stock_op_fields', 'stock_op_bar', 'stock_op_files', 'stock_accounts', 'decide_box',
            'stock_counts', 'stock_count_sheets', 'stock_count_reviews', 'stock_count', 'stock_count_before'] as $name) {
            $src = $templates[$name . '.php'];
            preg_match_all('/<table\b([^>]*)>/i', $src, $tables, PREG_SET_ORDER);
            foreach ($tables as $t) {
                self::assertMatchesRegularExpression('/class="[^"]*\bstack\b/', $t[1], "{$name}: {$t[0]} is cards on a phone");
            }
            preg_match_all('/<td\b[^>]*>/i', $src, $cells);
            foreach ($cells[0] as $td) {
                self::assertMatchesRegularExpression('/data-label=|class="[^"]*\bc-(status|next|head)\b/', $td, "{$name}: {$td} says what it is on a phone");
            }
            foreach (['(UTC)', '$dt(', '(GBP)', '<svg', '<img', 'data:'] as $old) {
                self::assertStringNotContainsString($old, $src, "{$name}: {$old}");
            }
            $text = (string) preg_replace(['/<\?.*?\?>/s', '/<[^>]*>/', '/&[a-z]+;/'], ' ', $src);
            self::assertSame([], preg_match_all('/[A-Za-z]{2,}/', $text, $m) > 0 ? $m[0] : [], "{$name}: a word typed in the template instead of taken from Words");
        }
        self::assertStringContainsString('enctype="multipart/form-data"', $templates['stock_op_files.php'], 'a record\'s files are uploaded');
        self::assertStringContainsString("\$partial('stock_op_files'", $templates['stock_op.php'], 'on its page');
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
     * I14 and the owner's request of 8 Oct 2026, in HM_Home's and HM_Phone's shell (docs/decisions.md DC7): the layout draws the person's
     * sidebar (the plain name, each entry's words and a count with words for a screen reader, the current section marked), the person,
     * Change password and Sign out at its bottom, the phone's top bar (the name and the initials that open the menu), the current section's
     * page bar with its tabs (a tab not built yet without a link, the current tab marked, counts; where the page's Show replaces the tab's
     * segments they head the row's More, DC5), the page's create button, the phone's bottom bar after the sidebar, and the two strips.
     */
    public function testTheLayoutDrawsTheSidebarTabsAndSegmentsOfTheRoles(): void
    {
        $who = new StaffIdentity(7, 'b@test.invalid', 'Bea <b>', ['buyer', 'mapping_lead'], false, 'sid');
        $view = new View(View::defaultDir(), ['csrf' => 'tok', 'who' => $who]);
        $f = Sections::frame($who->roles, ['linking_pending' => 3], '/ui/review', ['queue' => 'pending']);
        $html = $view->page('home', ['canFind' => true] + self::homeVars($who),
            ['title' => 'Home', 'active' => '', 'notice' => null, 'nav' => $f['nav'], 'pagebar' => $f['pagebar'], 'segments' => $f['segments'],
                'actions' => $f['actions'], 'searchBox' => true, 'tabs' => $f['phone'], 'testSystem' => false, 'switchedOff' => null]);
        $start = (int) strpos($html, '<nav class="side" id="menu" aria-label="Main">');
        self::assertGreaterThan(0, $start);
        $nav = substr($html, $start, (int) strpos($html, '</nav>', $start) - $start);
        // The sidebar of HM_Home (docs/decisions.md HV2): Home, "Every day", "More", each a list named by its heading.
        self::assertStringContainsString('<ul class="nav">' . "\n" . '      <li><a class="nav-item" href="/ui/">'
            . '<span class="nav-label">Home</span></a></li>' . "\n" . '    </ul>', $nav);
        self::assertStringContainsString('<p class="side-label" id="nav-daily">Every day</p>' . "\n" . '    <ul class="nav" aria-labelledby="nav-daily">', $nav);
        self::assertStringContainsString('<p class="side-label" id="nav-more">More</p>' . "\n" . '    <ul class="nav" aria-labelledby="nav-more">', $nav);
        self::assertStringContainsString('<a class="nav-item" href="/ui/purchasing/orders"><span class="nav-label">'
            . 'Purchase orders</span></a>', $nav, 'a daily entry: a tab of a section');
        self::assertStringContainsString('<a class="nav-item open" href="/ui/match" aria-current="true">'
            . '<span class="nav-label">Matching</span><span class="count" title="3 waiting for you">3<span class="visually-hidden"> waiting for you</span></span></a>',
            $nav, 'the current section, open, with what waits in it; it holds the page (DC28)');
        // The open section's pages as sub-items, the current page's segments one more indent (DC28: no tab row); the page shown has no link
        // (CV13), the set-up and specialist pages are marked as such (HV3).
        self::assertSame(['Home', 'Counts', 'Approvals', 'Purchase orders', 'All products', 'Stock', 'Purchasing', 'Sales', 'Matching', 'Simple matching', 'Mapping',
            'To review', 'Spot check', 'Second approval', 'Store products', 'By item', 'New variants', 'Duplicates', 'Reports', 'Settings'],
            preg_match_all('#<span class="nav-label">([^<]*)</span>#', $nav, $labels) > 0 ? $labels[1] : [], 'a buyer\'s and matching lead\'s entries');
        self::assertStringContainsString('<ul class="nav-sub" aria-label="Matching">' . "\n"
            . '          <li><a class="nav-sub-item" href="/ui/match"><span class="nav-label">Simple matching</span></a>' . "\n" . '          </li>' . "\n"
            . '          <li><a class="nav-sub-item" aria-current="true"><span class="nav-label">Mapping</span><span class="count" title="3 waiting for your second OK">3', $nav);
        self::assertStringContainsString('<ul class="nav-sub nav-seg" aria-label="Mapping">' . "\n"
            . '              <li><a class="nav-sub-item" href="/ui/review"><span class="nav-label">To review</span></a></li>' . "\n"
            . '              <li><a class="nav-sub-item" href="/ui/review/samples"><span class="nav-label">Spot check</span></a></li>' . "\n"
            . '              <li><a class="nav-sub-item" aria-current="page"><span class="nav-label">Second approval</span><span class="count" title="3 waiting for your second OK">3', $nav);
        self::assertStringContainsString('<a class="nav-sub-item more" href="/ui/review/products"><span class="nav-label">By item</span></a>', $nav);
        self::assertSame(1, substr_count($nav, 'aria-current="page"'), 'one current entry');
        self::assertStringNotContainsString('menu-group', $nav, 'no folding sections, no sub-items');
        self::assertStringContainsString('<span class="role">Buyer · Matching lead</span>', $nav, 'the jobs in words, not role codes');
        self::assertStringContainsString('href="/ui/password"', $nav, 'Change password at the bottom of the sidebar, in the person\'s menu');
        self::assertStringContainsString('<form class="signout" method="post" action="/ui/logout">', $nav);
        self::assertStringContainsString('<details class="user-menu">', $nav, 'the person\'s menu is a <details>: no script needed');
        self::assertStringNotContainsString('side-find', $nav, 'HM_Home: no find link in the sidebar (DC7; Home\'s Find box finds)');
        self::assertStringNotContainsString('icon-btn bell', $html, 'HM_Home: no bell (DC7; the Approvals entry carries the count)');
        self::assertStringNotContainsString('/ui/#about', $html, 'no help link to an About box Home no longer has (HV1)');
        self::assertStringContainsString('Bea &lt;b&gt;', $html);
        self::assertStringNotContainsString('buyer, mapping_lead', $html, 'no role codes in the frame');
        // The phone's top bar of HM_Phone (before the sidebar; hidden from 700 px): the name and the initials, which open the menu; the menu
        // button (shown only where no bottom bar is, the stylesheet: PI_New_Phone).
        $me = (int) strpos($html, '<a class="appbar-me" href="#menu" aria-controls="menu" title="Signed in as Bea &lt;b&gt;"><span aria-hidden="true">B&lt;</span>');
        self::assertGreaterThan(0, $me);
        self::assertLessThan($start, $me);
        self::assertStringNotContainsString('appbar-page', $html, 'HM_Phone: the name, not the page\'s');
        self::assertStringContainsString('<a class="icon-btn menu-btn" href="#menu" aria-controls="menu">', $html);
        // The statement (HM_Home): today's date as the eyebrow, the greeting (no full stop, DC2), the Find box on its right; then the section's
        // tabs under it, inside the page.
        $head = substr($html, (int) strpos($html, '<header class="page-head">'));
        self::assertStringStartsWith('<header class="page-head"><div class="ph-text"><p class="ph-eyebrow">' . Html::e(Html::today()) . '</p><div class="ph-title"><h1>'
            . '<span class="stmt">Good morning, Bea</span></h1></div></div><div class="ph-actions"><form class="hm-find" method="get" action="/ui/search" role="search">',
            $head, 'Home\'s eyebrow is today\'s date; ONE Find box');
        self::assertStringNotContainsString('<p class="lede">', substr($head, 0, (int) strpos($head, '</header>')), 'no sentence under the greeting');
        self::assertStringNotContainsString(Html::e(Words::SECTION_DESC['matching']), $html, 'one sentence: the section\'s own line is gone');
        self::assertStringNotContainsString('<nav class="tabs', $html, 'no tab row and no segment row on any page (DC28)');
        self::assertStringNotContainsString('seg-item', $html);
        self::assertStringNotContainsString('Phase', $html, 'no phase codes');
        self::assertStringContainsString('<a class="skip" href="#main">', $html, 'a skip link first');
        self::assertStringContainsString('<main id="main"', $html);
        // The phone's bottom bar (the owner's, kept in design v5) comes after the sidebar.
        $tabs = (int) strpos($html, '<nav class="tabbar" aria-label="Main tasks">');
        self::assertGreaterThan($start, $tabs);
        self::assertStringContainsString('<a href="/ui/"><span class="ico ico-home" aria-hidden="true"></span><span>Home</span></a>' . "\n"
            . '  <a href="/ui/stock/counts"><span class="ico ico-counts" aria-hidden="true"></span><span>Counts</span></a>' . "\n"
            . '  <a href="/ui/documents"><span class="ico ico-approvals" aria-hidden="true"></span><span>Approvals</span></a>' . "\n",
            substr($html, $tabs), 'HM_Phone: Home, Goods in (not a buyer\'s), Counts, Approvals; a page under More marks none');
        self::assertStringContainsString('<a href="#menu"><span class="ico ico-more" aria-hidden="true"></span><span>More</span></a>', substr($html, $tabs),
            'More opens the whole menu');
        self::assertStringNotContainsString('test-system', $html);
        self::assertStringNotContainsString('admin-off', $html);
        self::assertStringNotContainsString('<svg', $html, 'the icons are the sprite drawn by the stylesheet: a page has no svg (UiSecurityTest::assertInert)');
        self::assertStringNotContainsString('class="frame-only"', $html, 'the page drew its statement, so the layout draws no frame of its own');

        // A tab not built yet is in the sidebar with no link and its "Soon" (HV3's words).
        $f = Sections::frame(['buyer'], [], '/ui/stock');
        $html = $view->page('home', self::homeVars($who), ['title' => 'x', 'active' => '', 'notice' => null, 'nav' => $f['nav'], 'pagebar' => $f['pagebar'],
            'segments' => [], 'actions' => [], 'searchBox' => true, 'tabs' => $f['phone'], 'testSystem' => false, 'switchedOff' => null]);
        self::assertStringContainsString('<span class="nav-sub-item more soon" aria-disabled="true" title="' . Words::UI['soon_title'] . '"><span class="nav-label">Quality</span>'
            . '<span class="count soon">' . Words::UI['soon'] . '</span></span>', $html);
        self::assertStringContainsString('<a class="nav-sub-item" aria-current="page"><span class="nav-label">Overview</span></a>', $html, 'the page shown: no link (CV13)');
        // No bell (HM_Home; DC7): the inbox's count is on the menu's Approvals, HM_Home's magenta pill.
        $f = Sections::frame(['stock_controller'], ['approvals_inbox' => 4], '/ui/stock');
        $html = $view->page('home', self::homeVars($who), ['title' => 'x', 'active' => '', 'notice' => null, 'nav' => $f['nav'], 'pagebar' => $f['pagebar'],
            'segments' => [], 'actions' => [], 'searchBox' => true, 'tabs' => $f['phone'], 'testSystem' => false, 'switchedOff' => null]);
        self::assertStringNotContainsString('icon-btn bell', $html);
        self::assertStringNotContainsString('bell-n', $html);
        self::assertStringContainsString('<span class="nav-label">Approvals</span><span class="count" title="4 waiting for you">4', $html, 'the menu\'s Approvals: the inbox\'s count');
        $f = Sections::frame(['buyer'], [], '/ui/purchasing/orders');
        $html = $view->page('home', self::homeVars($who), ['title' => 'x', 'active' => '', 'notice' => null, 'nav' => $f['nav'], 'pagebar' => $f['pagebar'],
            'segments' => [], 'actions' => $f['actions'], 'searchBox' => true, 'tabs' => $f['phone'], 'testSystem' => false, 'switchedOff' => null]);
        // The page's create button on the right of its statement, alone (DC4: no second More of the section's other "New …").
        self::assertStringContainsString('<div class="ph-actions"><a class="btn primary" href="/ui/purchasing/orders/new"><span class="ico ico-plus" aria-hidden="true"></span>'
            . '<span>New purchase order</span></a>' . "\n" . '</div>', $html);
        self::assertStringNotContainsString(Words::UI['more_new'], $html);
        self::assertStringNotContainsString('/ui/purchasing/suppliers/new', $html);
        self::assertSame(1, substr_count($html, 'btn primary'), 'one main button');
        self::assertStringNotContainsString('split', $html);
        $f = Sections::frame(['goods_in'], [], '/ui/receiving');
        $one = $view->page('home', self::homeVars($who), ['title' => 'x', 'active' => '', 'notice' => null, 'nav' => $f['nav'], 'pagebar' => $f['pagebar'],
            'segments' => $f['segments'], 'actions' => $f['actions'], 'searchBox' => true, 'tabs' => $f['phone'], 'testSystem' => false, 'switchedOff' => null]);
        self::assertStringContainsString('<a class="btn primary" href="/ui/receiving/new"><span class="ico ico-plus" aria-hidden="true"></span><span>New delivery</span></a>', $one,
            'one action: the main button alone');
        self::assertStringNotContainsString('<details class="more">', $one);
        self::assertStringNotContainsString('class="flow"', $html, 'no buying-flow strip (DC5)');

        // The test system and the owner's account with Admin: the two strips.
        $owner = new StaffIdentity(9, 'o@test.invalid', 'Owner', ['admin', 'mapping_lead', 'reviewer'], false, 'sid');
        $f = Sections::frame($owner->roles, [], '/ui/');
        $html = (new View(View::defaultDir(), ['csrf' => 'tok', 'who' => $owner]))->page('home', self::homeVars($owner), ['title' => 'Home', 'active' => '', 'notice' => null,
            'nav' => $f['nav'], 'pagebar' => $f['pagebar'], 'segments' => [], 'actions' => [], 'searchBox' => true, 'tabs' => $f['phone'],
            'testSystem' => true, 'switchedOff' => Words::switchedOffNote($owner->roles)]);
        self::assertStringContainsString('<p class="strip test-system" role="note">TEST SYSTEM: nothing here is real</p>', $html);
        self::assertStringContainsString('Your Reviewer and Matching lead jobs are switched off because this account also has Admin. '
            . 'Ask Fazil to take Admin off this account.', $html);
        self::assertStringContainsString('<details class="help"><summary aria-label="What does &quot;switched off&quot; mean?"', $html, 'with its "?"');
        self::assertStringContainsString('<span class="role">Admin · Matching lead (off) · Reviewer (off)</span>', $html);
        self::assertStringNotContainsString('<nav class="tabs"', $html, 'Home has no tabs');


        $none = new StaffIdentity(8, 'n@test.invalid', 'Nobody', [], false, 'sid');
        $html = (new View(View::defaultDir(), ['csrf' => 'tok', 'who' => $none]))->page('home', self::homeVars($none),
            ['title' => 'Home', 'active' => 'home', 'notice' => null, 'nav' => [], 'tabs' => [], 'searchBox' => false]);
        self::assertStringNotContainsString('<ul class="nav">', $html);
        self::assertStringNotContainsString('<nav class="tabbar"', $html);
        self::assertStringNotContainsString('href="/ui/search"', $html);
        self::assertStringContainsString(Words::TASK['no_job']['text'], $html);
        self::assertStringContainsString('<form class="signout" method="post" action="/ui/logout">', $html, 'sign out is always there');
    }

    /**
     * Every page opens with its statement, drawn by ONE helper ($head; the approved mockups, docs/decisions.md DC1-DC3): the section as an
     * UPPERCASE eyebrow, the heading (no full stop), the state chips, its "?" help and, for a person who can only look, the "?" that says who
     * changes it (no sentence under the heading), the create button on the right; then the section's tabs. No template writes an <h1> of its
     * own.
     */
    public function testEveryPageOpensWithItsStatement(): void
    {
        $heads = 0;
        foreach (self::templates() as $name => $src) {
            self::assertStringNotContainsString('<h1', $src, "{$name}: the heading is the statement's (\$head)");
            $heads += min(1, substr_count($src, '$head('));
        }
        self::assertGreaterThanOrEqual(115, $heads, 'every page template draws its statement');

        // The helper in a page of its own (a template dir with the layout, the frame and the create button).
        $dir = sys_get_temp_dir() . '/cw-head-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            foreach (['layout', 'page_frame', 'split'] as $t) {
                copy(View::defaultDir() . '/' . $t . '.php', $dir . '/' . $t . '.php');
            }
            file_put_contents($dir . '/page.php', '<?= $head($title, intro: \'orders\', look: $look, chips: $chip(\'done\', $title), aside: $explain(\'second_ok\', \'x\'), tag: $tag) ?>' . "\n");
            $who = new StaffIdentity(7, 'b@test.invalid', 'Bea', ['goods_in'], false, 'sid');
            $f = Sections::frame($who->roles, [], '/ui/receiving');
            $html = (new View($dir, ['csrf' => 'tok', 'who' => $who]))->page('page', ['title' => 'PO-1 <b>', 'look' => 'Buyers', 'tag' => 'x&y'],
                ['title' => 'x', 'active' => '', 'notice' => null, 'nav' => $f['nav'], 'pagebar' => $f['pagebar'], 'segments' => $f['segments'],
                    'actions' => $f['actions'], 'searchBox' => false, 'tabs' => [], 'testSystem' => false, 'switchedOff' => null]);
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }
        self::assertStringContainsString('<header class="page-head"><div class="ph-text"><p class="ph-eyebrow">Purchasing<span class="ph-tag"> · x&amp;y</span></p>'
            . '<div class="ph-title"><h1><span class="stmt">PO-1 &lt;b&gt;</span> <span class="chip done">PO-1 &lt;b&gt;</span></h1><details class="help">', $html,
            'the eyebrow is the section, the heading and its chips escaped');
        self::assertStringContainsString('<details class="help look-only"><summary aria-label="' . Words::UI['look_help'] . '" title="' . Words::UI['look_help'] . '"></summary>'
            . '<div class="help-body"><p>You can look; Buyers change this.</p></div></details></div></div><div class="ph-actions"><a class="btn primary" href="/ui/receiving/new">', $html,
            'no sentence: who changes it is the "?" after the page\'s own; the create button on the right');
        self::assertStringNotContainsString('<p class="lede">', $html);
        self::assertStringNotContainsString('<nav class="tabs"', $html, 'no tab row under the statement (DC28)');
        self::assertSame(1, substr_count($html, '<h1'));
        self::assertStringNotContainsString('frame-only', $html);

        // The statement itself: the heading as typed, no full stop added; the parts it leaves out are not drawn.
        self::assertSame('<header class="page-head"><div class="ph-text"><p class="ph-eyebrow">Error 404</p><div class="ph-title"><h1><span class="stmt">Is it the same?</span>'
            . '</h1></div></div></header>', Html::statement('Error 404', 'Is it the same?'));
        self::assertStringContainsString('<span class="stmt">Orders</span>', Html::statement('', 'Orders'));
        self::assertStringNotContainsString('ph-eyebrow', Html::statement('', 'Orders'));
        self::assertSame('<p class="lede">A <span class="lede-more">B &amp; C</span></p>', Html::lede('A', 'B & C'));
        self::assertSame('', Html::lede('', ''));
        self::assertSame('Monday 19 October', Html::today(new \DateTimeImmutable('2026-10-18 23:30:00', new \DateTimeZone('UTC'))), 'London time (BST)');
    }

    /**
     * Design conformance (the owner, 30 Oct 2026: "strictly follow our design guidelines"; the approved mockups of 29 Oct 2026; docs/decisions.md
     * DC1-DC8), one pin per shared difference of the audit's section A: the heading has no full stop and no sentence under it (only the steps of
     * View::INTRO_KEPT keep their line), who may change a look-only page is a "?" beside it, a record's eyebrow is its list with "›"; the page's
     * "+ New" is in the header with no second More of the section's other create actions and no buying-flow strip; where the page's Show
     * replaces its tab's segments they head the tab row's More; the shell is HM_Home's and HM_Phone's (the plain name, words without icons, no
     * bell, the person's initials opening the menu); every page name is in sentence case.
     */
    public function testTheSharedPartsFollowTheApprovedMockups(): void
    {
        // DC2: no full stop, drawn or typed.
        self::assertSame('<header class="page-head"><div class="ph-text"><p class="ph-eyebrow">Purchasing</p><div class="ph-title"><h1><span class="stmt">Purchase orders</span>'
            . '</h1></div></div></header>', Html::statement('Purchasing', 'Purchase orders'));
        // DC3: a record's eyebrow is its list, a link and "›".
        self::assertSame('<header class="page-head"><div class="ph-text"><p class="ph-eyebrow ph-back"><a href="/ui/stock/counts">Counts</a><span aria-hidden="true"> ›</span></p>'
            . '<div class="ph-title"><h1><span class="stmt">Count sheet CNT-1</span></h1></div></div></header>',
            Html::statement('Stock', 'Count sheet CNT-1', back: ['/ui/stock/counts', 'Counts']));
        // DC1: who may change a look-only page, under a "?".
        self::assertSame('<details class="help look-only"><summary aria-label="' . Words::UI['look_help'] . '" title="' . Words::UI['look_help'] . '"></summary>'
            . '<div class="help-body"><p>You can look; Buyers change this.</p></div></details>', Html::lookOnly(sprintf(Words::UI['look_only'], 'Buyers')));
        self::assertSame('', Html::lookOnly(''));
        foreach (View::INTRO_KEPT as $page) {
            self::assertArrayHasKey($page, Words::PAGE_INTRO, "{$page}: a kept intro is a page's intro");
        }
        foreach (Words::INTRO_LOOK as $page => $line) {
            self::assertStringContainsString($line, implode(' ', Words::PAGE_INTRO[$page]), "{$page}: its look-only line is its intro's own words");
        }

        $dir = sys_get_temp_dir() . '/cw-dc-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            foreach (['layout', 'page_frame', 'split'] as $t) {
                copy(View::defaultDir() . '/' . $t . '.php', $dir . '/' . $t . '.php');
            }
            file_put_contents($dir . '/page.php', '<?= $head($title, intro: $pi, look: $lk) ?>' . "\n");
            $buyer = new StaffIdentity(7, 'b@test.invalid', 'Bea Smith', ['buyer'], false, 'sid');
            $page = static function (StaffIdentity $who, string $path, array $vars) use ($dir): string {
                $f = Sections::frame($who->roles, ['incidents_open' => 2], $path);
                return (new View($dir, ['csrf' => 'tok', 'who' => $who]))->page('page', $vars, ['title' => 'x', 'active' => '', 'notice' => null, 'nav' => $f['nav'],
                    'pagebar' => $f['pagebar'], 'segments' => $f['segments'], 'actions' => $f['actions'], 'searchBox' => true, 'tabs' => $f['phone'],
                    'testSystem' => false, 'switchedOff' => null]);
            };
            $orders = $page($buyer, '/ui/purchasing/orders', ['title' => Words::MENU['orders'], 'pi' => 'orders', 'lk' => 'Buyers']);
            $kept = $page($buyer, '/ui/purchasing/orders', ['title' => 'x', 'pi' => 'password', 'lk' => null]);
            $goodsIn = $page(new StaffIdentity(8, 'g@test.invalid', 'Gus', ['goods_in'], false, 'sid'), '/ui/receiving',
                ['title' => Words::MENU['receiving'], 'pi' => 'receiving', 'lk' => null]);
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }
        $head = substr($orders, (int) strpos($orders, '<header class="page-head">'), (int) strpos($orders, '</header>', (int) strpos($orders, '<header class="page-head">'))
            - (int) strpos($orders, '<header class="page-head">'));
        self::assertStringNotContainsString('<p class="lede">', $orders, 'DC1: no sentence under the heading');
        self::assertStringNotContainsString(Html::e(Words::PAGE_INTRO['orders'][0]), $orders);
        self::assertStringContainsString('<h1><span class="stmt">Purchase orders</span></h1><details class="help look-only">', $head, 'DC1: the look-only line is a "?"');
        self::assertStringContainsString('<p class="lede">' . Html::e(Words::PAGE_INTRO['password'][0]) . ' <span class="lede-more">', $kept, 'DC1: a kept step\'s rule');
        // DC4: "+ New" in the header, alone: no second More of the section's other create actions.
        self::assertStringContainsString('<div class="ph-actions"><a class="btn primary" href="/ui/purchasing/orders/new"><span class="ico ico-plus" aria-hidden="true"></span>'
            . '<span>New purchase order</span></a>' . "\n" . '</div>', $orders);
        self::assertStringNotContainsString(Words::UI['more_new'], $orders);
        self::assertCount(1, Sections::frame(['buyer', 'purchasing_manager'], [], '/ui/purchasing/orders')['actions'], 'DC4: the page\'s own create action only');
        // DC5: no buying-flow strip.
        self::assertStringNotContainsString('class="flow"', $orders);
        self::assertArrayNotHasKey('flow', (array) Sections::frame(['buyer'], [], '/ui/purchasing/orders')['pagebar']);
        // DC28: no tab row and no segment row; the sidebar holds Goods in's pages, its segments one more indent, each with its count.
        self::assertStringNotContainsString('<nav class="tabs', $goodsIn);
        self::assertStringContainsString('<ul class="nav-sub nav-seg" aria-label="' . Words::MENU['goods_in'] . '">' . "\n"
            . '              <li><a class="nav-sub-item" aria-current="page"><span class="nav-label">' . Words::SEGMENT['receiving'] . '</span></a></li>' . "\n"
            . '              <li><a class="nav-sub-item" href="/ui/receiving/bench"><span class="nav-label">' . Words::SEGMENT['bench'] . '</span></a></li>' . "\n"
            . '              <li><a class="nav-sub-item" href="/ui/receiving/incidents"><span class="nav-label">' . Words::SEGMENT['incidents'] . '</span><span class="count"', $goodsIn);
        self::assertSame(['To receive', 'Checking', 'Issues', 'Duty register', 'Arriving'], array_column(Sections::frame(['goods_in'], [], '/ui/receiving/bench')['segments'], 'label'));
        // Every page the tab rows reached (each tab's first page the person may open, and each page of a tab with segments) is in that
        // person's sidebar tree when they are in its section (the owner: keep every page reachable; the other pages of a tab are reached
        // from that tab's page, as before).
        foreach (['buyer', 'goods_in', 'purchasing_desk', 'stock_controller', 'reviewer', 'mapping_lead', 'admin', 'auditor', 'accountant'] as $role) {
            foreach (Sections::visible([$role]) as $sectionKey => $tabs) {
                foreach ($tabs as $tab) {
                    foreach ($tab['soon'] ? [] : ($tab['segments'] && count($tab['pages']) > 1 ? $tab['pages'] : [$tab['pages'][0]]) as $pg) {
                        $fr = Sections::frame([$role], [], (string) $pg['path'], $pg['query'] ?? []);
                        if ($sectionKey === 'dashboard') {
                            continue;
                        }
                        $open = array_values(array_filter($fr['nav'], static fn (array $i): bool => $i['open']));
                        self::assertCount(1, $open, "{$role} {$pg['path']}: one open section");
                        $reach = [];
                        foreach ($open[0]['subs'] as $sub) {
                            $reach[] = Html::url((string) $sub['href'], $sub['query']);
                            foreach ($sub['segs'] as $seg) {
                                $reach[] = Html::url((string) $seg['href'], $seg['query']);
                            }
                        }
                        self::assertContains(Html::url((string) $pg['path'], $pg['query'] ?? []), $reach, "{$role}: {$pg['path']} is in the sidebar");
                    }
                }
            }
        }
        // DC7: the shell of HM_Home and HM_Phone.
        self::assertStringNotContainsString('brand-mark', $orders, 'the plain name, no CW tile');
        self::assertStringNotContainsString('icon-btn bell', $orders, 'no bell: the Approvals entry carries the count');
        self::assertStringNotContainsString('side-find', $orders);
        self::assertSame(0, preg_match('#<a class="nav-item"[^>]*><span class="ico#', $orders), 'the menu is words only');
        self::assertStringContainsString('<a class="brand" href="/ui/"><span class="brand-name">' . Words::UI['brand'] . '</span></a>', $orders);
        self::assertStringContainsString('<a class="appbar-me" href="#menu" aria-controls="menu" title="Signed in as Bea Smith"><span aria-hidden="true">BS</span>', $orders,
            'HM_Phone: the initials open the menu (Sign out is there)');
        self::assertStringContainsString('<form class="signout" method="post" action="/ui/logout">', $orders);
        // DC6: every page name in sentence case (the proper names excepted).
        $proper = ['CW', 'ERPNext', 'VAT', 'UK', 'OK', 'Vape', 'Go', 'Excel', 'CSV', 'PDF', 'PO'];
        foreach (['SECTION', 'NAV', 'MENU', 'SEGMENT', 'NEW', 'PAGE_TITLE'] as $group) {
            foreach (constant(Words::class . '::' . $group) as $key => $text) {
                foreach (array_slice(preg_split('/\s+/u', (string) $text) ?: [], 1) as $w) {
                    self::assertFalse(preg_match('/^[A-Z][a-z]/u', $w) === 1 && !in_array(rtrim($w, ':,.'), $proper, true), "{$group}.{$key} \"{$text}\": sentence case");
                }
            }
        }
        // DC4: no board draws a create button of its own in its toolbar (the header has it).
        self::assertFalse(defined(\CW\Ui\Context::class . '::OWN_TOOLBAR'));
        foreach (['purchase_orders', 'suppliers', 'receipts', 'stock_ops', 'stock_counts'] as $t) {
            self::assertStringNotContainsString('newActions', self::templates()[$t . '.php'], "{$t}: the header draws its \"+ New\"");
        }
    }

    /**
     * Codex review of ui-v5, finding 5: `$head` prints its `chips`, `meta` and `aside` as they are (they hold chips and help the
     * helpers made), so the echo lint's "a helper call" is not enough for them. Each must be built only of helper calls, string
     * literals, `.` and parentheses; a raw value may only stand in the condition of a ternary, never in what it prints.
     */
    public function testTheStatementsRawPartsAreOnlyHelperOutput(): void
    {
        $seen = 0;
        foreach (self::templates() as $name => $src) {
            foreach (self::headCalls($src) as $call) {
                self::assertNotContains(self::UNREADABLE, $call, "{$name}: a \$head call the lint cannot read (a double-quoted or heredoc string, or no closing bracket)");
                $args = self::headArgs($call);
                self::assertSame([], array_filter(array_keys($args), static fn (string $k): bool => str_starts_with($k, 'unpacked')),
                    "{$name}: no unpacked argument in \$head (the lint cannot see which parameter it fills)");
                foreach ($args as $arg => $expr) {
                    if (in_array($arg, ['chips', 'meta', 'aside'], true)) {
                        $seen++;
                        self::assertTrue(self::onlyHelperOutput($expr), "{$name}: `{$arg}: {$expr}` prints something that is not a helper's output");
                    }
                }
            }
        }
        self::assertGreaterThan(30, $seen, 'the lint found the raw parts');
        // The lint itself.
        foreach (['$chip(\'done\', $t)', '$stateChip(\'PO_STATE\', $s) . ($a !== null ? \' \' . $stateChip(\'REVIEW_STATE\', $a) : \'\')',
            '\'<span class="muted">(\' . $e($sku[\'code\']) . \')</span>\'', '$x[\'on\'] === 1 ? $chip(\'done\', $a) : $chip(\'off\', $b)',
            '$explain(\'k\', \\CW\\Ui\\Words::THING[\'second\'])', '($a ? $e($b) : $word(\'X\', \'y\')) . \' · \' . ($c ? $say(\'X\', \'z\', $n) : \'\')'] as $ok) {
            self::assertTrue(self::onlyHelperOutput($ok), $ok);
        }
        foreach (['$row[\'name\']', '$a ? $row[\'name\'] : \'\'', '$chip(\'done\', $t) . $title', '\\CW\\Ui\\Words::say(\'X\', \'y\', $n)', '$e($a) . strtoupper($b)',
            '$a ? $e($b) : $c', '"<b>$name</b>"',
            // Codex's sixth review: $partial draws any template with the values given (the layout prints its `body` as it is).
            '$partial(\'layout\', [\'body\' => $row[\'name\']])', '$partial(\'split\', [\'actions\' => $a])', '$chip(\'done\', $a'] as $bad) {
            self::assertFalse(self::onlyHelperOutput($bad), $bad);
        }
        // Codex's second and third reviews: a comment before the name or holding brackets, the raw parts given by position, an unpacked
        // array: each is read as what it is, and refused.
        foreach (['\'Product\', /* state */ meta: $row[\'name\']', '\'Product\', null, null, $row[\'name\']', '\'P\', null, null, \'\', $row[\'n\']',
            '\'P\', aside: # x' . "\n" . '$row[\'n\']', '\'P\', /* ) . $e( */ meta: $row[\'name\']'] as $call) {
            $calls = self::headCalls('<?= $head(' . $call . ') ?>');
            self::assertCount(1, $calls, $call);
            $raw = array_intersect_key(self::headArgs($calls[0]), array_flip(['chips', 'meta', 'aside']));
            self::assertNotSame([], $raw, $call);
            self::assertContains(false, array_map(static fn (string $x): bool => self::onlyHelperOutput($x), $raw), "{$call}: refused");
        }
        // Codex's fourth review: a bracket inside a double-quoted string ended the call early, so what came after it was never read.
        // A double-quoted or heredoc string, or a call that never closes, is unreadable to the lint, and refused.
        foreach (['"$title)", meta: $row[\'name\']', '"$title)", chips: $row[\'name\']', '"$title)", aside: $row[\'name\']', '"{$t}", meta: $chip(\'done\', $a)',
            "<<<T\n)\nT, meta: \$row['n']",
            // Codex's fifth review: an attribute's `#[` is one token and its `]` another; any part a template does not use is refused.
            '(#[A] fn() => \'Product\')(), meta: $row[\'name\']', '(#[A] fn() => \'P\')(), chips: $row[\'n\']', '(#[A] fn() => \'P\')(), aside: $row[\'n\']',
            'match ($k) { 1 => \'a\' }, meta: $row[\'n\']', '(function () { return \'P\'; })(), meta: $row[\'n\']', '`ls`, meta: $row[\'n\']'] as $call) {
            self::assertContains(self::UNREADABLE, self::headCalls('<?= $head(' . $call . ') ?>')[0], "{$call}: unreadable, refused");
        }
        self::assertContains(self::UNREADABLE, self::headCalls('<?= $head(\'P\', meta: $chip(\'done\', $a) ?>')[0], 'a call that never closes is refused');
        foreach (['\'Product\', ...[\'meta\' => $row[\'name\']]', '...$parts'] as $call) {
            self::assertNotSame([], array_filter(array_keys(self::headArgs(self::headCalls('<?= $head(' . $call . ') ?>')[0])),
                static fn (string $k): bool => str_starts_with($k, 'unpacked')), "{$call}: an unpacked argument is refused");
        }
        self::assertSame(['title' => '$t', 'intro' => '\'x\'', 'chips' => '$chip ( \'done\' , $a )'],
            self::headArgs(self::headCalls('<?= $head($t, \'x\', chips: $chip(\'done\', $a)) ?>')[0]),
            'an argument given by position is the parameter at its place (the tokens joined by spaces)');
        self::assertCount(2, self::headCalls("<p>\$head( in words</p>\n<?= \$head('A') ?>\n<?php /* \$head('B') */ ?>\n<?= \$head('C', meta: \$chip('done', ')')) ?>"),
            'only the calls in the code: not in the markup, not in a comment');
    }

    /**
     * The `$head(...)` calls of a template, each as its argument tokens (no whitespace, no comment): read with PHP's own tokenizer, so
     * a bracket in a string or a comment is never taken for the end of a call, and markup outside `<?php ?>` is never read. A call may
     * hold only the plain parts the templates use (HEAD_TOKENS, HEAD_CHARS: variables, names, single-quoted strings, comparisons,
     * casts, brackets, `.`, `?`, `:`); anything else (a double-quoted or heredoc string, an attribute, a closure, a `match`, braces)
     * is UNREADABLE, as is a call that never closes.
     *
     * @return list<list<string>>
     */
    private static function headCalls(string $src): array
    {
        $toks = [];
        foreach (token_get_all($src) as $tok) {
            if (!(is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                $toks[] = $tok;
            }
        }
        $out = [];
        $n = count($toks);
        for ($i = 0; $i < $n - 1; $i++) {
            if (!(is_array($toks[$i]) && $toks[$i][1] === '$head' && $toks[$i + 1] === '(')) {
                continue;
            }
            $depth = 1;
            $args = [];
            for ($j = $i + 2; $j < $n && $depth > 0; $j++) {
                $tok = $toks[$j];
                if (is_array($tok)) {
                    $args[] = in_array($tok[0], self::HEAD_TOKENS, true) ? $tok[1] : self::UNREADABLE;
                    continue;
                }
                $depth += in_array($tok, ['(', '['], true) ? 1 : (in_array($tok, [')', ']'], true) ? -1 : 0);
                if ($depth > 0) {
                    $args[] = in_array($tok, self::HEAD_CHARS, true) ? $tok : self::UNREADABLE;
                }
            }
            if ($depth > 0) {
                $args[] = self::UNREADABLE; // the call never closes
            }
            $out[] = $args;
            $i = $j - 1;
        }
        return $out;
    }

    /** The token types a `$head` call may hold (headCalls()); T_ELLIPSIS is read so that an unpacked argument can be refused by name. */
    private const HEAD_TOKENS = [T_VARIABLE, T_STRING, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED,
        T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL,
        T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_BOOLEAN_AND, T_BOOLEAN_OR, T_COALESCE, T_INT_CAST, T_STRING_CAST, T_ELLIPSIS];
    /** The one-character tokens a `$head` call may hold. */
    private const HEAD_CHARS = ['(', ')', '[', ']', ',', ':', '?', '.', '!', '<', '>', '+', '-'];

    /** The token headCalls() puts where a call holds what the lint cannot read (it fails the lint). */
    private const UNREADABLE = "\x01unreadable";

    /** The parameters of View's `head` helper, in order (an argument given by position is the one at its place). */
    private const HEAD_PARAMS = ['title', 'intro', 'look', 'chips', 'meta', 'aside', 'lede', 'more', 'tag', 'eyebrow'];

    /**
     * The arguments of a `$head(...)` call (its tokens, headCalls()), by parameter (name => expression): a named one by its name, one
     * given by position by its place in HEAD_PARAMS, an unpacked one (`...$x`) as `unpacked<n>` (which parameter it fills is unknown).
     *
     * @param list<string> $toks
     * @return array<string, string>
     */
    private static function headArgs(array $toks): array
    {
        $parts = [[]];
        $depth = 0;
        foreach ($toks as $text) {
            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                $parts[] = [];
                continue;
            }
            $parts[count($parts) - 1][] = $text;
        }
        $out = [];
        foreach ($parts as $i => $part) {
            if ($part === []) {
                continue;
            }
            if ($part[0] === '...') {
                $out['unpacked' . $i] = implode(' ', array_slice($part, 1));
            } elseif (count($part) >= 2 && preg_match('/^[a-z]+$/D', $part[0]) === 1 && $part[1] === ':') {
                $out[$part[0]] = implode(' ', array_slice($part, 2));
            } else {
                $out[self::HEAD_PARAMS[$i] ?? 'extra' . $i] = implode(' ', $part);
            }
        }
        return $out;
    }

    /** @return list<array{0: string, 1: int}> the PHP tokens of an expression (no whitespace, no comment), with their offsets */
    private static function tokens(string $expr): array
    {
        $out = [];
        $pos = -6; // after the '<?php ' prefix
        foreach (token_get_all('<?php ' . $expr) as $tok) {
            $text = is_array($tok) ? $tok[1] : $tok;
            if ($pos >= 0 && !(is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                // The text of a double-quoted string is marked, so a bracket in it never counts as one of the code's.
                $out[] = [is_array($tok) && $tok[0] === T_ENCAPSED_AND_WHITESPACE ? "\x02" . $text : $text, $pos];
            }
            $pos += strlen($text);
        }
        return $out;
    }

    /** True when an expression prints only helper output: helper calls, string literals, `.`, parentheses and ternaries (any condition). */
    private static function onlyHelperOutput(string $expr): bool
    {
        $toks = array_column(self::tokens($expr), 0);
        return self::safeExpr($toks);
    }

    /** @param list<string> $t */
    private static function safeExpr(array $t): bool
    {
        if ($t === []) {
            return false;
        }
        // A ternary at the top: the condition is free, both branches must be safe.
        $q = self::topLevel($t, '?');
        if ($q !== null) {
            $rest = array_slice($t, $q + 1);
            $c = self::topLevel($rest, ':');
            return $c !== null && self::safeExpr(array_slice($rest, 0, $c)) && self::safeExpr(array_slice($rest, $c + 1));
        }
        // A concatenation of safe terms.
        $term = [];
        $depth = 0;
        foreach ([...$t, '.'] as $tok) {
            if ($tok === '.' && $depth === 0) {
                if (!self::safeTerm($term)) {
                    return false;
                }
                $term = [];
                continue;
            }
            $depth += in_array($tok, ['(', '[', '{'], true) ? 1 : (in_array($tok, [')', ']', '}'], true) ? -1 : 0);
            $term[] = $tok;
        }
        return $depth === 0 && $term === []; // a term left open never reaches its end
    }

    /** @param list<string> $t */
    private static function safeTerm(array $t): bool
    {
        if (count($t) === 1 && preg_match('/^\'(?:[^\'\\\\]|\\\\.)*\'$/s', $t[0]) === 1) {
            return true; // a single-quoted literal
        }
        if (count($t) >= 3 && $t[0] === '(' && end($t) === ')' && self::closes($t)) {
            return self::safeExpr(array_slice($t, 1, -1));
        }
        // Not $partial (it draws any template with the values it is given) and not $head itself.
        $helpers = array_map(static fn (string $h): string => '$' . $h, array_diff(self::HELPERS, ['head', 'partial']));
        return count($t) >= 3 && in_array($t[0], $helpers, true) && $t[1] === '(' && end($t) === ')' && self::closes(array_slice($t, 1));
    }

    /** @param list<string> $t whether $t[0] is closed by its last token (one balanced group) */
    private static function closes(array $t): bool
    {
        $depth = 0;
        foreach ($t as $i => $tok) {
            $depth += in_array($tok, ['(', '[', '{'], true) ? 1 : (in_array($tok, [')', ']', '}'], true) ? -1 : 0);
            if ($depth === 0 && $i < count($t) - 1) {
                return false;
            }
        }
        return $depth === 0;
    }

    /** @param list<string> $t @return int|null the first $tok at nesting depth 0 */
    private static function topLevel(array $t, string $tok): ?int
    {
        $depth = 0;
        foreach ($t as $i => $x) {
            if ($x === $tok && $depth === 0) {
                return $i;
            }
            $depth += in_array($x, ['(', '[', '{'], true) ? 1 : (in_array($x, [')', ']', '}'], true) ? -1 : 0);
        }
        return null;
    }

    /** The variables DashboardController gives home.php, for $who with nothing waiting (no database here). @return array<string, mixed> */
    private static function homeVars(StaffIdentity $who): array
    {
        $home = HomeTasks::build($who->id, $who->roles, []);
        return ['greeting' => 'Good morning, Bea', 'tasks' => array_map(\CW\Ui\Controller\DashboardController::row(...), $home['jobs']),
            'notes' => array_map(\CW\Ui\Controller\DashboardController::row(...), $home['notes']), 'figures' => [], 'canFind' => false, 'later' => ''];
    }

    /** Codex's sixth review of ui-v5: the layout prints its `body` as it is, so only View::page() draws it, never the $partial helper. */
    public function testThePartialHelperNeverDrawsTheLayout(): void
    {
        $dir = sys_get_temp_dir() . '/cw-partial-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            copy(View::defaultDir() . '/layout.php', $dir . '/layout.php');
            file_put_contents($dir . '/page.php', '<?= $partial(\'layout\', [\'body\' => $raw, \'title\' => \'t\', \'notice\' => null]) ?>' . "\n");
            $view = new View($dir, ['csrf' => 'tok', 'who' => null]);
            try {
                $view->render('page', ['raw' => '<b>RAW</b>']);
                self::fail('the layout was drawn through $partial');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('layout', $e->getMessage());
            }
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }
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
        $list = array_map(\CW\Ui\Controller\DashboardController::row(...), [
            ['key' => 'spot_check', 'job' => 1, 'hero' => true, 'tone' => 'needs', 'chip' => 'Needs you', 'title' => 'Check <20>', 'count' => 0, 'unit' => 'of 20 checked',
                'progress' => [0, 20], 'text' => 'If all 20 are right, the rest are confirmed together.', 'what' => 'You see one at a time.',
                'href' => '/ui/review/samples/1', 'button' => 'Check the next one'],
            ['key' => 'bulk', 'tone' => 'waiting', 'chip' => 'Waiting', 'title' => 'Bulk link', 'quiet' => true],
        ]);
        // Home's "Waiting for you" (HM_Home, docs/decisions.md HV1): per task its number in its colour, its name, one plain line, one button.
        $html = $view->render('cards', ['list' => $list, 'notes' => false]);
        self::assertStringStartsWith('<ol class="hm-list">', $html);
        self::assertSame(2, substr_count($html, '<li class="hm-row'));
        self::assertStringContainsString('<li class="hm-row needs" data-card="spot_check">' . "\n" . '    <span class="hm-n" aria-hidden="true">0/20</span>', $html,
            'the number in the task\'s colour: a spot check\'s answered and of');
        self::assertStringContainsString('<h3 class="hm-t">Check &lt;20&gt;</h3>', $html);
        self::assertStringContainsString('<p class="hm-d"><span class="visually-hidden"><span class="hm-said">0 of 20 checked</span>. </span>If all 20 are right, the rest are '
            . 'confirmed together.</p>', $html, 'a screen reader hears the number with its unit; one plain line');
        self::assertStringNotContainsString('You see one at a time.', $html, 'no "What happens:" line (HV1)');
        self::assertStringContainsString('<a class="btn primary" href="/ui/review/samples/1">Check the next one</a>', $html, 'the first job: the page\'s main action');
        self::assertStringContainsString('<span class="hm-n" aria-hidden="true">!</span>', $html, 'a task without a number');
        self::assertStringContainsString('<span class="hm-said">Waiting</span>', $html, '... says its state');
        self::assertSame(1, substr_count($html, 'class="btn'), 'the second row has no button');
        $notes = $view->render('cards', ['list' => [$list[1]], 'notes' => true]);
        self::assertStringStartsWith('<ul class="hm-list hm-notes" aria-labelledby="notes-h">', $notes, 'the notes: a list under "Good to know"');
        self::assertSame('<span class="chip info">x &amp; y</span>', Html::chip('nonsense', 'x & y'), 'an unknown tone is info');
        $help = Html::help('how_sure', 'How sure');
        self::assertStringStartsWith('<details class="help"><summary aria-label="What does &quot;How sure&quot; mean?"', $help);
        self::assertStringContainsString('<strong>Strong match:</strong>', $help);
        self::assertStringNotContainsString('**', $help);
        self::assertSame('<div class="empty"><p class="empty-title">No &lt;orders&gt;</p><p>Add a supplier first.</p>'
            . '<p><a class="btn" href="/ui/purchasing/suppliers/new">Add a supplier</a></p></div>', // plain: the page's main is its create button (DS5)
            Html::emptyState('No <orders>', 'Add a supplier first.', '/ui/purchasing/suppliers/new', 'Add a supplier'));
        self::assertSame('', Html::intro(''));
        self::assertSame('<p class="lede">Choose a new password of at least 12 characters. You stay signed in here.</p>', Html::intro(Words::intro('password')));
    }

    /**
     * True when $expr is one or more helper calls joined by `.`: `$e($x)`, `$e($a) . $e($b)`. Read from its PHP tokens (no whitespace,
     * no comment; the text of a double-quoted string is never a bracket), so a bracket in a comment or a string cannot make a raw value
     * look like part of a call (Codex's seventh review of ui-v5).
     */
    private static function onlyHelperCalls(string $expr): bool
    {
        $t = array_column(self::tokens($expr), 0);
        $helpers = array_map(static fn (string $h): string => '$' . $h, self::HELPERS);
        $term = [];
        $depth = 0;
        foreach ([...$t, '.'] as $tok) {
            if ($tok === '.' && $depth === 0) {
                if (!(count($term) >= 3 && in_array($term[0], $helpers, true) && $term[1] === '(' && end($term) === ')' && self::closes(array_slice($term, 1)))) {
                    return false;
                }
                $term = [];
                continue;
            }
            $depth += in_array($tok, ['(', '[', '{'], true) ? 1 : (in_array($tok, [')', ']', '}'], true) ? -1 : 0);
            $term[] = $tok;
        }
        return $t !== [] && $depth === 0 && $term === []; // an unclosed call never reaches its end
    }

    /**
     * Where a template's code uses a helper variable other than by calling it, writes a variable by its name, or declares something,
     * as "line <n>: what" (testAHelperIsOnlyEverCalled). Read with PHP's tokenizer, whitespace and comments skipped: markup, comments
     * and strings hold none. A call whose name is computed (`('ext' . 'ract')(...)`, `'extract'(...)`) is refused: PHP folds the
     * literals into a direct call. A `)` that closes a control structure's condition is no callee: `if ($ok) ($x = 1);`.
     *
     * @return list<string>
     */
    private static function helperMisuse(string $src): array
    {
        $helpers = array_map(static fn (string $h): string => '$' . $h, self::HELPERS);
        $toks = array_values(array_filter(token_get_all($src), static fn (array|string $t): bool
            => !(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))));
        $out = [];
        $line = 1;
        $parens = []; // for each open `(`: whether it holds a control structure's condition
        $closedCondition = false; // whether the token before is the `)` of one
        foreach ($toks as $i => $tok) {
            $line = is_array($tok) ? $tok[2] : $line;
            $prev = $toks[$i - 1] ?? null;
            $next = $toks[$i + 1] ?? null;
            $ref = $next === '&' || (is_array($next) && in_array($next[0], [T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true));
            $afterRef = $toks[$i + ($ref ? 2 : 1)] ?? null; // what follows a `function` keyword, past a `&`
            if (is_array($tok) && $tok[0] === T_VARIABLE && in_array($tok[1], $helpers, true) && $next !== '(') {
                $out[] = "line {$line}: `{$tok[1]}` then `" . ($next === null ? 'the end' : trim(is_array($next) ? $next[1] : $next)) . '`, not a call';
            } elseif ($tok === '$') {
                $out[] = "line {$line}: a variable variable";
            } elseif (is_array($tok) && in_array($tok[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE], true)
                && preg_match('/(?:^|\\\\)(?:extract|call_user_func|call_user_func_array|define|class_alias)$/iD', $tok[1]) === 1) {
                $out[] = "line {$line}: {$tok[1]}";
            } elseif (is_array($tok) && (in_array($tok[0], [T_NAMESPACE, T_INTERFACE, T_TRAIT, T_ENUM], true)
                || ($tok[0] === T_CLASS && is_array($next) && $next[0] === T_STRING) || ($tok[0] === T_FUNCTION && $afterRef !== '('))) {
                $out[] = "line {$line}: a declaration (`{$tok[1]}`)"; // a closure (`function (`, `function &(`), `::class`, `new class` name none
            } elseif ($tok === '(' && (($prev === ')' && !$closedCondition) || in_array($prev, [']', '"'], true)
                || (is_array($prev) && in_array($prev[0], [T_CONSTANT_ENCAPSED_STRING, T_END_HEREDOC], true)))) {
                $out[] = "line {$line}: a call of a computed name";
            }
            if ($tok === '(') {
                $parens[] = is_array($prev) && in_array($prev[0], [T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_DECLARE], true);
            }
            $closedCondition = $tok === ')' && array_pop($parens) === true;
        }
        return $out;
    }

    /** @return list<string> the code inside <?php ... ?> and <?= ... ?> */
    private static function phpBlocks(string $src): array
    {
        return self::blocks($src, [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO]);
    }

    /** @return list<string> what each `<?= ... ?>` of a template prints (its expression, comments kept: tokens() drops them) */
    private static function echoes(string $src): array
    {
        return self::blocks($src, [T_OPEN_TAG_WITH_ECHO]);
    }

    /**
     * The code of each block opened by one of $opens, up to the `?>` PHP itself closes it with (Codex's eighth review of ui-v5: a `?>`
     * inside a block comment does not close it, so a regex up to the first `?>` read less than PHP runs).
     *
     * @param list<int> $opens
     * @return list<string>
     */
    private static function blocks(string $src, array $opens): array
    {
        $out = [];
        $code = null;
        foreach (token_get_all($src) as $tok) {
            $type = is_array($tok) ? $tok[0] : null;
            if ($code === null) {
                if ($type !== null && in_array($type, $opens, true)) {
                    $code = '';
                }
                continue;
            }
            if ($type === T_CLOSE_TAG) {
                $out[] = $code;
                $code = null;
                continue;
            }
            $code .= is_array($tok) ? $tok[1] : $tok;
        }
        if ($code !== null) {
            $out[] = $code; // a block the file ends in
        }
        return $out;
    }

    /** The button variants and sizes there are (docs/decisions.md DS1: the parts board TP_Parts); a .btn with none of them is the plain one. */
    private const BUTTON_PARTS = ['primary', 'soft', 'ghost', 'ok', 'risky', 'sm', 'lg'];

    /**
     * The classes the one tabs, buttons and status components replaced (docs/decisions.md DS1-DS6): no template, no class a controller hands
     * a template, no class app.js sets may name one of them again, and the stylesheet keeps no rule for them.
     */
    private const REMOVED = [
        // buttons: the variants mapped onto the five (DS4) and the split create button
        'secondary', 'danger', 'danger-outline', 'success', 'dark', 'dashed', 'xs', 'full', 'big', 'button', 'primary-link', 'link', 'split', 'split-main', 'split-more',
        'doc-more', 'doc-more-menu',
        // tabs: the black segmented pills and every other pill row (DS2)
        'seg-item', 'pills', 'pill', 'pills-label', 'tab-count', 'seg-links', 'state-pills', 'show-pills', 'period-pills', 'dispatch-pills',
        // status: the old soft-tag words and the unused status classes (DS3)
        'band', 'status', 'status-mapped', 'status-active', 'status-suggested', 'status-unmapped', 'status-pending_approval', 'status-draft',
        'status-quarantined', 'status-inactive', 'mark',
    ];

    /**
     * Each element of a template that has a class: its tag, its line, the classes its markup names (the literal ones, those between PHP blocks
     * inside the attribute: `<?php if ($x): ?>primary<?php endif; ?>`, and a PHP string it prints: `'primary'`, not an array key) and its attributes.
     *
     * @return list<array{tag: string, line: int, classes: list<string>, attrs: string}>
     */
    private static function classed(string $src): array
    {
        $php = '<\?(?:php|=).*?\?>';
        preg_match_all('#<([a-z][a-z0-9]*)\b((?:' . $php . '|"(?:[^"<]|' . $php . ')*"|\'[^\']*\'|[^>"\'])*)>#s', $src, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        preg_match_all('#' . $php . '#s', $src, $code, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($tags as $t) {
            foreach ($code[0] as [$block, $at]) {
                if ($t[0][1] > $at && $t[0][1] < $at + strlen($block)) {
                    continue 2; // markup PHP builds as a string: its classes are read from the string (testNoViewUsesARemovedClass)
                }
            }
            if (preg_match('#\bclass="((?:[^"<]|' . $php . ')*)"#s', $t[2][0], $c) !== 1) {
                continue;
            }
            $words = (string) preg_replace('#' . $php . '#s', ' ', $c[1]);
            preg_match_all('#' . $php . '#s', $c[1], $blocks);
            foreach ($blocks[0] as $block) {
                preg_match_all("/(?<!\\[)'([a-z][a-z0-9 _-]*)'(?!\\])/", $block, $lits); // a string the block prints, not an array key
                $words .= ' ' . implode(' ', $lits[1]);
            }
            $out[] = ['tag' => strtolower($t[1][0]), 'line' => substr_count(substr($src, 0, $t[0][1]), "\n") + 1,
                'classes' => array_values(array_unique(preg_split('/\s+/', trim($words), -1, PREG_SPLIT_NO_EMPTY) ?: [])), 'attrs' => $t[2][0]];
        }
        return $out;
    }

    /**
     * The one set of tabs, buttons and status parts (the owner, 29 Oct 2026: "tab change follow this design pattern and button all design
     * follow this"; docs/decisions.md DS1-DS6), on the source of every template and of every class the PHP and the script hand a page: no
     * removed class anywhere; a button is a .btn with at most one variant and one size from the parts board (the controls with their own
     * look are named); a tab row is a nav.tabs of a.tab; a soft tag names only a tone.
     */
    public function testNoViewUsesARemovedClass(): void
    {
        $own = ['grp-toggle', 'side-link', 'bulkbar-close', 'po-default']; // a group's fold, the person's menu, the bulk bar's x, the hidden default
        $tones = Words::TONES;
        $checked = 0;
        foreach (self::templates() as $name => $src) {
            foreach (self::classed($src) as $el) {
                $where = "{$name} line {$el['line']} <{$el['tag']} class=\"" . implode(' ', $el['classes']) . '">';
                self::assertSame([], array_values(array_intersect($el['classes'], self::REMOVED)), "{$where}: a class the one components replaced");
                $isButton = $el['tag'] === 'button' || in_array('btn', $el['classes'], true);
                if ($isButton && array_intersect($el['classes'], $own) === []) {
                    $checked++;
                    self::assertContains('btn', $el['classes'], "{$where}: every button is the one .btn component");
                    $variants = array_intersect($el['classes'], ['primary', 'soft', 'ghost', 'ok', 'risky']);
                    $sizes = array_intersect($el['classes'], ['sm', 'lg']);
                    $conditional = str_contains($el['attrs'], '<?php');
                    self::assertLessThanOrEqual($conditional ? 2 : 1, count($variants), "{$where}: one variant");
                    self::assertLessThanOrEqual(1, count($sizes), "{$where}: one size");
                }
                if (in_array('tag', $el['classes'], true)) {
                    self::assertSame([], array_values(array_diff($el['classes'], ['tag', 'sm-wait', ...$tones])), "{$where}: a soft tag names a tone, nothing else");
                }
                if (in_array('tab', $el['classes'], true)) {
                    self::assertContains($el['tag'], ['a', 'span'], "{$where}: a tab is a link (a tab not built yet a span)");
                }
                if ($el['tag'] === 'nav' && array_intersect($el['classes'], ['seg', 'store-seg', 'legend', 'show-tabs', 'state-tabs', 'period-tabs']) !== []) {
                    self::assertContains('tabs', $el['classes'], "{$where}: every tab-like row is the one tabs component");
                }
            }
            // Markup a template's PHP builds as a string (a chip line, a soft tag beside a heading): its literal classes.
            foreach (self::phpBlocks($src) as $block) {
                preg_match_all('/class="([a-z0-9 _-]*)/', $block, $built);
                foreach ($built[1] as $words) {
                    $classes = preg_split('/\s+/', trim($words), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    self::assertSame([], array_values(array_intersect($classes, self::REMOVED)), "{$name}: PHP builds class=\"{$words}…\"");
                    if (in_array('tag', $classes, true)) {
                        self::assertSame([], array_values(array_diff($classes, ['tag', ...$tones])), "{$name}: PHP builds a soft tag that names more than a tone");
                    }
                }
            }
            if (preg_match_all('#<nav\b[^>]*class="([^"]*)"#', $src, $navs) > 0) {
                foreach ($navs[1] as $cls) {
                    $words = preg_split('/\s+/', $cls) ?: [];
                    // A nav is the sidebar, the phone bar, a pager, or a row of tabs: nothing else draws a choice of lists.
                    self::assertNotSame([], array_intersect($words, ['tabs', 'side', 'tabbar', 'pager', 'rows-choice']), "{$name}: <nav class=\"{$cls}\">");
                }
            }
        }
        self::assertGreaterThan(400, $checked, 'every button of every template was looked at');
        // The classes a controller hands a template (bulk actions), the empty state's button, the soft tags' tones, what app.js sets.
        $handed = 0;
        foreach (glob(dirname(View::defaultDir()) . '/Controller/*.php') ?: [] as $file) {
            preg_match_all("/'class' => (.*)$/m", (string) file_get_contents($file), $m);
            foreach ($m[1] as $rest) {
                $value = (preg_split("/, '[a-z_]+' =>/", $rest) ?: [''])[0]; // up to the next key of the array
                preg_match_all("/'([a-z -]*)'/", $value, $lits);
                foreach ($lits[1] as $lit) {
                    $handed++;
                    self::assertSame([], array_values(array_intersect(preg_split('/\s+/', trim($lit), -1, PREG_SPLIT_NO_EMPTY) ?: [], self::REMOVED)), basename($file) . ": 'class' => '{$lit}'");
                }
            }
            preg_match_all("/\\['tone' => '([a-z]*)'/", (string) file_get_contents($file), $t);
            foreach ($t[1] as $tone) {
                self::assertContains($tone, ['', ...$tones], basename($file) . ": a tag's tone is one of the seven");
            }
        }
        self::assertGreaterThanOrEqual(3, $handed, 'the bulk actions\' classes were read');
        self::assertStringContainsString('<p><a class="btn" href="', (string) file_get_contents(dirname(View::defaultDir()) . '/Html.php'), 'the empty state\'s next step is a plain button');
        $js = (string) file_get_contents(\CW\Ui\Assets::dir() . '/app.js');
        preg_match_all("/(?:className = |classList\\.(?:add|toggle|remove)\\()'([a-z -]+)'/", $js, $set);
        self::assertNotSame([], $set[1]);
        foreach ($set[1] as $value) {
            self::assertSame([], array_values(array_intersect(explode(' ', $value), self::REMOVED)), "app.js sets '{$value}'");
        }
        self::assertStringContainsString("b.className = 'btn ghost sm reveal';", $js, 'the password\'s Show is a quiet small button');
        self::assertStringContainsString("var menus = 'details.user-menu[open], details.tb-pop[open], details.more[open]';", $js, 'Esc and a click elsewhere close More');
        // The stylesheet keeps no rule for a removed class, and names no button variant but the five and the two sizes.
        $css = (string) file_get_contents(\CW\Ui\Assets::dir() . '/app.css');
        $rules = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        foreach (self::REMOVED as $class) {
            self::assertDoesNotMatchRegularExpression('/\.' . preg_quote($class, '/') . '(?![a-z0-9_-])/', $rules, "app.css: a rule for .{$class}");
        }
        preg_match_all('/\.btn\.([a-z][a-z0-9-]*)/', $rules, $variants);
        self::assertSame(self::BUTTON_PARTS, array_values(array_intersect(self::BUTTON_PARTS, array_unique($variants[1]))), 'each part has its rule');
        self::assertSame([], array_values(array_diff(array_unique($variants[1]), self::BUTTON_PARTS)), 'and nothing else is a button variant');
    }

    /**
     * The forms of a template whose main buttons submit them a page may draw several of (docs/decisions.md DS5, AV13): a work queue whose
     * rows are each one small form answered in turn (Simple matching's Apply; Lines to place's Place is the data's choice, checked on the
     * rendered page), and the OK of each record's decision where a page lists several records. template => a piece of the form's action.
     */
    private const ROW_MAIN = [
        'simple_match' => '$apply',
        'staff_requests' => '/ui/staff-requests/',
        'company' => '/ui/reference/company/reviews/',
        'purchase_order' => '/ui/documents/reviews/',
        'receipt' => '/ui/documents/reviews/',
        'supplier' => '/ui/purchasing/suppliers/tasks/',
    ];

    /**
     * Where a template's main (magenta) buttons are, as "where => how many": each form (`form #<id>` or `form at line <n>`) counts the main
     * buttons that submit it (inside it, or naming it with form="..."), and "outside a form" counts the main links and fold buttons (they
     * submit nothing) and the buttons of a part drawn inside its caller's form. A form that only filters or searches (method GET, or no
     * method) is named `GET form …`, and a form a loop draws once per row ` (each row)` after its name, with its action: neither may hold a
     * main button (the Codex review of DS1-DS6, AV13), but the forms of ROW_MAIN. Only a class that names `primary` in the markup itself is
     * counted: one named under a condition (`<?php if ($primary): ?>`: the data decides, a document's next step, the Dashboard's first job)
     * is left to the check of the rendered pages (tests/Support/MainButtons, on every page the kernel tests open). The branches of one
     * if / elseif / else count as the largest; what a loop draws counts as many.
     *
     * @param array<string, string> $actions filled with each form's action attribute as written (form key => action)
     * @return array<string, int>
     */
    private static function mainButtons(string $src, array &$actions = []): array
    {
        $php = '<\?(?:php|=).*?\?>';
        $re = '#(?<tag><(?<close>/?)(?<name>form|button|a|summary|input)\b(?<attrs>(?:' . $php . '|"(?:[^"<]|' . $php . ')*"|\'[^\']*\'|[^>"\'])*)>)'
            . '|(?<ctl><\?php\s+(?<kw>if|elseif|else|endif|foreach|endforeach|for|endfor|while|endwhile)\b.*?\?>)#s';
        preg_match_all($re, $src, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $tokens = [];
        foreach ($all as $m) {
            $line = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
            if (isset($m['kw']) && $m['kw'][1] >= 0) {
                $tokens[] = ['kw' => $m['kw'][0], 'line' => $line];
                continue;
            }
            $name = strtolower($m['name'][0]);
            $attrs = $m['attrs'][0];
            if ($name === 'form') {
                $id = preg_match('/\bid="([^"<]*)"/', $attrs, $i) === 1 ? $i[1] : null;
                $get = preg_match('/\bmethod="post"/i', $attrs) !== 1;
                $action = preg_match('#\baction="((?:[^"<]|' . $php . ')*)"#s', $attrs, $a) === 1 ? $a[1] : '';
                $tokens[] = ['kw' => $m['close'][0] === '/' ? 'form_close' : 'form_open', 'line' => $line,
                    'key' => ($get ? 'GET ' : '') . ($id !== null ? "form #{$id}" : "form at line {$line}"), 'action' => $action];
                continue;
            }
            if ($m['close'][0] === '/' || preg_match('#\bclass="((?:[^"<]|' . $php . ')*)"#s', $attrs, $c) !== 1) {
                continue;
            }
            // The classes the attribute names whatever the data: what is under an if inside the attribute is the data's choice.
            $always = (string) preg_replace('#<\?php\s+if\b.*?<\?php\s+endif;\s*\?>#s', ' ', $c[1]);
            $literal = preg_split('/\s+/', trim((string) preg_replace('#' . $php . '#s', ' ', $always)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (!in_array('primary', $literal, true)) {
                continue; // not a main button, or one only under a condition (the rendered pages' check)
            }
            $owned = in_array($name, ['button', 'input'], true);
            $form = $owned && preg_match('/\bform="([^"<]*)"/', $attrs, $f) === 1 ? "form #{$f[1]}" : null;
            $tokens[] = ['kw' => 'main', 'line' => $line, 'owned' => $owned, 'form' => $form];
        }
        $counts = [];
        $open = null; // the form being read
        $n = 0;
        $loops = 0; // how many loops the walk is inside
        $walk = function (int $i, array $stop) use (&$walk, &$counts, &$open, &$actions, &$loops, $tokens, &$n): int {
            while ($i < count($tokens)) {
                $t = $tokens[$i];
                if (in_array($t['kw'], $stop, true)) {
                    return $i;
                }
                if ($t['kw'] === 'form_open') {
                    $open = (str_contains($t['key'], 'form #') ? $t['key'] : $t['key'] . ' #' . (++$n)) . ($loops > 0 ? ' (each row)' : '');
                    $counts[$open] ??= 0;
                    $actions[$open] = $t['action'];
                } elseif ($t['kw'] === 'form_close') {
                    $open = null;
                } elseif ($t['kw'] === 'main') {
                    $key = $t['form'] ?? ($t['owned'] && $open !== null ? $open : 'outside a form');
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                } elseif ($t['kw'] === 'if') {
                    // Each branch from the counts before the if; after it, the largest of each.
                    $before = $counts;
                    $openBefore = $open;
                    $best = $counts;
                    $j = $i + 1;
                    while (true) {
                        $counts = $before;
                        $open = $openBefore;
                        $j = $walk($j, ['elseif', 'else', 'endif']);
                        foreach ($counts as $k => $v) {
                            $best[$k] = max($best[$k] ?? 0, $v);
                        }
                        $after = $open;
                        if ($j >= count($tokens) || $tokens[$j]['kw'] === 'endif') {
                            break;
                        }
                        $j++;
                    }
                    $counts = $best;
                    $open = $after;
                    $i = $j;
                } elseif (in_array($t['kw'], ['foreach', 'for', 'while'], true)) {
                    // A loop draws its body again and again: read it twice (a form it opens is a new form each time, drawn on each row).
                    $end = 'end' . $t['kw'];
                    $loops++;
                    $j = $walk($i + 1, [$end]);
                    $walk($i + 1, [$end]);
                    $loops--;
                    $i = $j;
                }
                $i++;
            }
            return $i;
        };
        $walk(0, []);
        return array_filter($counts, static fn (int $c): bool => $c > 0);
    }

    /**
     * What breaks the one main action in a template's source (mainButtons()): a form with two main buttons, two main links outside the forms,
     * a main button in a filter or search form, a main button repeated on every row (but ROW_MAIN's).
     *
     * @return list<string>
     */
    private static function mainButtonProblems(string $name, string $src): array
    {
        $name = basename($name, '.php');
        $actions = [];
        $out = [];
        foreach (self::mainButtons($src, $actions) as $where => $count) {
            if (str_starts_with($where, 'GET ')) {
                $out[] = "{$name}: {$where} is a filter or a search, yet holds a main button";
            } elseif (str_ends_with($where, ' (each row)') && !(isset(self::ROW_MAIN[$name]) && str_contains($actions[$where] ?? '', self::ROW_MAIN[$name]))) {
                $out[] = "{$name}: {$where} draws a main button on every row";
            } elseif ($count > 1) {
                $out[] = "{$name}: {$where} holds {$count} main buttons";
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The tab of the page shown never navigates (docs/decisions.md CV13; Codex's review of 7cfcc89: the count sheet's current "Every line"
     * tab reloaded the sheet and lost a typed quantity). In every template a tab (`a.tab`) draws its link only when it is not the page shown:
     * its one href comes right after the `else` (or the `if (!…)`) of that test, so the current one is the tab's look and nothing to click,
     * with or without the script (tests/Support/CurrentTabs checks the pages as served). And the pages whose form keeps typed values ask
     * before a link leaves (app.js, CV14): their forms carry data-unsaved, their downloads `download` (a download does not leave),
     * "Leave without saving" data-leave (it says what it does).
     */
    public function testTheTabOfThePageShownHasNoLinkAndTheEditorsAskBeforeALinkLeaves(): void
    {
        $tabs = 0;
        foreach (self::templates() as $name => $src) {
            foreach (self::classed($src) as $el) {
                if ($el['tag'] !== 'a' || !in_array('tab', $el['classes'], true)) {
                    continue;
                }
                $tabs++;
                $where = "{$name} line {$el['line']}";
                if (in_array('tab-back', $el['classes'], true)) {
                    // The item page's way back to its open tab (docs/decisions.md IV16): never current, hidden (CSS) unless a #fragment showed
                    // another part; a link to the open tab is then a link to a part that is not shown.
                    self::assertSame('item.php', $name, "{$where}: only the item page draws a tab's twin");
                    self::assertStringNotContainsString('aria-current', $el['attrs'], "{$where}: never the current tab");
                    continue;
                }
                self::assertSame(1, substr_count($el['attrs'], ' href='), "{$where}: one href");
                self::assertMatchesRegularExpression('/<\?php (?:else|if \(![^?]*\)): \?> href=/', $el['attrs'], "{$where}: the href only when it is not the page shown");
            }
        }
        self::assertGreaterThanOrEqual(15, $tabs, 'every tab row was read (fewer since the boards\' Show menus replaced the show tabs, W11.3, W11.8)');
        $templates = self::templates();
        foreach (['stock_count', 'receipt_edit', 'stock_op', 'handover', 'purchase_order_edit', 'invoice_edit', 'approvals_record', 'item', 'item_groups'] as $name) {
            $src = $templates[$name . '.php'];
            self::assertStringContainsString('data-unsaved="1"', $src, "{$name}: its form keeps typed values, so a link asks first");
            preg_match_all('/<a\b(?:<\?(?:php|=).*?\?>|[^>])*>/s', $src, $links);
            $downloads = 0;
            foreach ($links[0] as $a) {
                if (preg_match('/href="[^"]*\.(?:csv|xlsx)\b/', $a) === 1) {
                    $downloads++;
                    self::assertStringContainsString(' download', $a, "{$name}: {$a} is a download: it does not leave the page");
                }
            }
            if (in_array($name, ['stock_count', 'purchase_order_edit'], true)) {
                self::assertSame(1, $downloads, "{$name}: its download was read");
            }
        }
        self::assertMatchesRegularExpression('/<a class="btn ghost" href="[^"]*" data-leave>/', $templates['doc_bar.php'], '"Leave without saving" never asks');
    }

    /**
     * One main action (the owner, 29 Oct 2026: "button all design follow this"; the parts board: "One main button per page"; docs/decisions.md
     * DS5, AV13): in every template a form holds at most one main (magenta) button, at most one main link or fold button stands outside the
     * forms, a filter or search form holds none, and a main button is not repeated row by row (but the documented row answers). The rendered
     * pages are checked too (tests/Support/MainButtons, run by the kernel's browser on every page a test opens).
     */
    public function testEveryPageHasAtMostOneMainButtonPerForm(): void
    {
        $forms = 0;
        $problems = [];
        foreach (self::templates() as $name => $src) {
            $forms += count(self::mainButtons($src));
            array_push($problems, ...self::mainButtonProblems($name, $src));
        }
        self::assertSame([], $problems);
        self::assertGreaterThan(60, $forms, 'the main buttons were found');
        // The count itself: two in one form; the branches of one if; a loop; a button naming its form from outside; a link.
        self::assertSame(['form at line 1 #1' => 2], self::mainButtons('<form method="post"><button class="btn primary">A</button><button class="btn primary">B</button></form>'));
        self::assertSame(['form at line 1 #1' => 1], self::mainButtons('<form method="post"><?php if ($a): ?><button class="btn primary">A</button><?php elseif ($b): ?>'
            . '<button class="btn primary">B</button><?php else: ?><button class="btn">C</button><?php endif; ?></form>'));
        self::assertSame(['form at line 1 #1' => 2], self::mainButtons('<form method="post"><?php foreach ($rows as $r): ?><button class="btn primary">A</button><?php endforeach; ?></form>'));
        self::assertSame(['form #f' => 2], self::mainButtons('<form id="f" method="post"><button class="btn primary">A</button></form><button form="f" class="btn primary">B</button>'));
        self::assertSame(['outside a form' => 2], self::mainButtons('<a class="btn primary" href="/a">A</a><form method="post"><a class="btn primary" href="/b">B</a></form>'));
        self::assertSame([], self::mainButtons('<button class="btn<?php if ($primary): ?> primary<?php endif; ?>">A</button><a class="btn" href="/">B</a>'), 'the data decides: the rendered check');
        // The Codex review of DS1-DS6 (AV13): a primary Apply of a filter beside a primary Save; a primary button on every row's own form.
        $page = '<form class="toolbar" method="get" action="/ui/x"><button class="btn primary">Apply</button></form>'
            . '<form method="post" action="/ui/x/save"><button class="btn primary">Save</button></form>';
        self::assertSame(['GET form at line 1 #1' => 1, 'form at line 1 #2' => 1], self::mainButtons($page));
        self::assertSame(['x: GET form at line 1 #1 is a filter or a search, yet holds a main button'], self::mainButtonProblems('x', $page));
        self::assertSame(['x: GET form at line 1 #1 is a filter or a search, yet holds a main button'],
            self::mainButtonProblems('x', '<form action="/ui/x"><button class="btn primary">Show</button></form>'), 'no method is a GET form');
        $rows = '<?php foreach ($rows as $r): ?><form method="post" action="<?= $u(\'/ui/arriving/\' . $r[\'id\'] . \'/receive\') ?>"><button class="btn primary">Receive</button></form><?php endforeach; ?>';
        self::assertSame(['form at line 1 #1 (each row)' => 1, 'form at line 1 #2 (each row)' => 1], self::mainButtons($rows));
        self::assertSame(['x: form at line 1 #1 (each row) draws a main button on every row', 'x: form at line 1 #2 (each row) draws a main button on every row'],
            self::mainButtonProblems('x', $rows));
        $ok = '<?php foreach ($requests as $r): ?><form method="post" action="<?= $u(\'/ui/staff-requests/\' . $r[\'id\'] . \'/approve\') ?>"><button class="btn primary">OK</button></form><?php endforeach; ?>';
        self::assertSame([], self::mainButtonProblems('staff_requests', $ok), 'the OK of each record\'s decision (DS5)');
        self::assertNotSame([], self::mainButtonProblems('x', $ok), 'only where it is documented');
        // The same two cases on a rendered page (tests/Support/MainButtons).
        self::assertCount(1, \CW\Tests\Support\MainButtons::problems($page));
        self::assertStringContainsString('filter (GET)', \CW\Tests\Support\MainButtons::problems($page)[0]);
        $drawn = '<form method="post" action="/ui/arriving/1/receive"><button class="btn primary">Receive</button></form>'
            . '<form method="post" action="/ui/arriving/2/receive"><button class="btn primary">Receive</button></form>';
        self::assertSame(['a row action repeated (/ui/arriving/{n}/receive): 2 main buttons (Receive | Receive)'], \CW\Tests\Support\MainButtons::problems($drawn));
        self::assertSame([], \CW\Tests\Support\MainButtons::problems(str_replace('/ui/arriving/', '/ui/staff-requests/', str_replace('/receive', '/approve', $drawn))),
            'the OK of each record\'s decision');
        self::assertSame([], \CW\Tests\Support\MainButtons::problems('<form method="post" action="/ui/queues/suspense/1/pick"><button class="btn primary">Place</button></form>'
            . '<form method="post" action="/ui/queues/suspense/2/pick"><button class="btn primary">Place</button></form>'), 'Lines to place\'s Place (LP_Queue)');
        self::assertSame([], \CW\Tests\Support\MainButtons::problems('<form method="post" action="/ui/review/listing/7/decide"><button class="btn primary">Yes</button></form>'
            . '<form method="post" action="/ui/review/listing/7/decide"><button class="btn primary">Save</button></form>'), 'one record\'s two forms (a quick Yes beside the full form): one per form');
    }

    /**
     * Stock moves and hand-overs on the approved patterns (wave w8, docs/decisions.md W8; the shared parts docs/dev.md lists): each page's
     * template carries its pattern's parts, and none carries the old toolbar's Filter pop-over, the "How it works" fold, the old figure tiles
     * or the old facts list.
     *   1 board: one search and one Show menu (`.tb-state`), each group a coloured heading with its count over a card with the 6 px edge, the
     *     status cell filled with its colour, "+ New …" at the end of the open group where a new one starts here;
     *   2 record: the status block in the head with its action bar at the right, the figures strip, the paper card (the party and its facts);
     *     a document's card also has its lines, its files on the left and its totals on the right, then its history;
     *   3 one-page form: the fields in two columns, the lines board, the totals, the bar at the bottom (Leave, Save, the main action), typed
     *     values guarded (the stock records' and hand-overs' one form of OV, kept as it is);
     *   5 report: the figures strip and the board(s) with the coloured edge; a long list has the toolbar (one search, the Show row).
     */
    public function testTheStockPagesFollowTheApprovedPatterns(): void
    {
        $t = self::templates();
        $toolbar = ['<form class="toolbar" method="get"', '<div class="tb-search">', '<div class="tb-state">'];
        $pins = [
            'board' => [...$toolbar, '<div class="grp-block doc-group ', '<div class="table-wrap doc-board" id="g-', '<td class="c-status doc-st ',
                '<th scope="row" class="c-head c-item"><a class="o-name row-link"'],
            'new' => ['<fieldset class="doc-grid">', '<details class="fold new-order drawer" id="new"'],
            'record' => ['<div class="doc-head">', 'side: $bar', '<section class="doc-progress rp-figs"', '<article class="doc-view"', "\$partial('doc_party'"],
            'document' => ['<div class="doc-foot">', "\$partial('doc_totals'", 'checks-history"', '<table class="stack list board doc-lines'],
            'files' => ['id="files"', "\$partial('stock_op_files'"],
            'form' => ['<fieldset class="doc-grid">', '<table class="stack list board doc-lines', 'data-unsaved="1"', '<div class="actions sticky doc-bar">',
                'data-leave>', '<span class="doc-bar-note">'],
            'report' => ['<section class="doc-progress rp-figs"', 'class="table-wrap doc-board'],
            'toolbar' => $toolbar,
        ];
        $pages = [
            'stock_ops' => ['board', 'new'], 'repacks' => ['board', 'new'], 'handovers' => ['board', 'new'], 'handover_customers' => ['board', 'new'],
            'stock_reservations' => ['board'], 'stock_op' => ['record', 'document', 'files', 'form'], 'handover' => ['record', 'document', 'form'],
            'handover_customer' => ['record'], 'handover_balances' => ['report'], 'stock_accounts' => ['report'], 'stock_movements' => ['report', 'toolbar'],
            'stock' => ['report', 'toolbar'],
        ];
        foreach ($pages as $name => $patterns) {
            $src = $t[$name . '.php'] ?? '';
            foreach ($patterns as $p) {
                foreach ($pins[$p] as $part) {
                    self::assertStringContainsString($part, $src, "{$name}: the {$p} pattern's {$part}");
                }
            }
            foreach (['class="tb-pop"', 'class="fold how"', 'class="kpis', '<dl class="wide', 'ico-filter'] as $old) {
                self::assertStringNotContainsString($old, $src, "{$name}: {$old} is the old layout");
            }
            if (in_array('board', $patterns, true)) {
                self::assertSame(1, substr_count($src, '<div class="tb-state">'), "{$name}: one Show row");
                self::assertSame(1, preg_match_all('/<input id="tb-q" type="search"/', $src), "{$name}: one search box");
            }
        }
        // The create form's fields are grid cells: a label holds its own control, or sits with it (and its error) in a div.field.
        foreach (['stock_op_fields', 'handover_fields', 'handover_customer_fields', 'repacks'] as $name) {
            $src = $t[$name . '.php'];
            self::assertSame(preg_match_all('#<label for="(?!tb-)#', $src), preg_match_all('#<div class="field[^"]*">\s*<label for=#', $src), "{$name}: a control outside its label's cell");
        }
        // The new pieces close what they open (a stray </div> in a bar pushes the next step out of the head).
        foreach (['stock_op_bar', 'handover_bar', 'customer_bar', 'stock_op_files', 'stock_op', 'handover', 'handover_customer', 'handover_balances', 'stock_accounts'] as $name) {
            foreach (['div', 'details', 'form', 'section', 'table', 'article', 'fieldset'] as $tag) {
                self::assertSame(preg_match_all('/<' . $tag . '\b/', $t[$name . '.php']), substr_count($t[$name . '.php'], '</' . $tag . '>'), "{$name}: every <{$tag}> closed");
            }
        }
        // The stock records' and hand-overs' one form (OV): Save first, the final button the main one, both in the bar at the bottom of the form.
        foreach (['stock_op', 'handover'] as $name) {
            self::assertMatchesRegularExpression('#<div class="actions sticky doc-bar">\s*<a class="btn ghost" href="[^"]*" data-leave>.*?</a>\s*<span class="doc-bar-note">.*?</span>\s*'
                . '<button class="btn" type="submit" name="action" value="save">.*?</button>\s*<button type="submit" name="action" value="post" class="btn primary">#s',
                $t[$name . '.php'], "{$name}: Leave, the note, Save, then the final button");
        }
    }

    /**
     * Suppliers and buying on the approved patterns (wave w9, docs/decisions.md W9; the shared parts docs/dev.md lists): each page's template
     * carries its pattern's parts, and none carries the old toolbar's Filter pop-over, the "How it works" fold or the old facts list.
     *   1 board: the toolbar (one Show row; one search where the list has one), each group a coloured heading with its count over a card with
     *     the 6 px edge, the status cell filled with its colour, the row's link, "+ New …" at the end of the open group where one starts here;
     *   2 record: the status block in the head with its action bar at the right, the figures strip, the paper card (the party and its facts),
     *     the supplier's four tabs;
     *   3 one-page form: the fields in two columns, typed values guarded, the bar at the bottom with "Leave without saving" and the main action;
     *   5 report: the figures strip and the boards with the coloured edge.
     */
    public function testTheBuyingPagesFollowTheApprovedPatterns(): void
    {
        $t = self::templates();
        $board = ['<div class="grp-block doc-group ', '<button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-', '<span class="grp-count">',
            '<div class="table-wrap doc-board" id="g-', '<td class="c-status doc-st '];
        $pins = [
            'board' => $board,
            'toolbar' => ['<form class="toolbar" method="get"', '<div class="tb-state">'],
            'search' => ['<div class="tb-search">', '<input id="tb-q" type="search"'],
            'row_link' => ['<a class="o-name row-link"'],
            'add_row' => ['<tr class="grp-add">', '<a class="add-item"'],
            'new' => ['<details class="fold new-order drawer" id="new"', '<form class="doc-details" method="post"', '<fieldset class="doc-grid">'],
            'record' => ['<div class="doc-head">', 'side: $bar', '<section class="doc-progress rp-figs"', '<article class="doc-view"', "\$partial('doc_party'"],
            'related' => ['<section class="doc-related"', '<div class="board-head"><h2 id="', '<div class="table-wrap doc-board '],
            'tabs' => ['<nav class="tabs record-tabs" id="record-tabs"', ' aria-current="page"', '<?php if (!$t[\'current\']): ?> href="'],
            'form' => ['<form class="doc-details" method="post"', '<fieldset class="doc-grid', 'data-unsaved="1"', 'data-unsaved-text="'],
            'report' => ['<section class="doc-progress rp-figs"', '<div class="grp-block doc-group ', 'class="table-wrap doc-board'],
        ];
        $pages = [
            'suppliers' => ['board', 'toolbar', 'search', 'row_link', 'add_row'], 'supplier_items_board' => ['board', 'row_link', 'add_row'],
            'supplier' => ['record', 'tabs', 'related'], 'supplier_form' => ['form'],
            'supplier_returns' => ['board', 'toolbar', 'search', 'row_link', 'add_row', 'new'], 'supplier_return' => ['record', 'form', 'related'],
            'invoices' => ['board', 'toolbar', 'search', 'row_link', 'add_row', 'new'],
            'invoices_to_match' => ['board', 'toolbar', 'row_link'], 'invoices_not_invoiced' => ['board', 'toolbar', 'search', 'row_link'],
            'invoices_timed' => ['board', 'row_link'], 'arrivals' => ['board', 'row_link'], 'incidents' => ['board', 'toolbar'],
            'duty_register' => ['report', 'toolbar', 'search'], 'reorder' => ['report', 'toolbar', 'search'], 'reorder_low_stock' => ['report', 'toolbar', 'search'],
            'reorder_brands' => ['report'], 'reorder_anomalies' => ['report'], 'reorder_suppliers' => ['report'], 'sales_history' => ['report'],
        ];
        foreach ($pages as $name => $patterns) {
            $src = $t[$name . '.php'] ?? '';
            foreach ($patterns as $p) {
                foreach ($pins[$p] as $part) {
                    self::assertStringContainsString($part, $src, "{$name}: the {$p} pattern's {$part}");
                }
            }
            foreach (['class="tb-pop"', 'class="fold how"', 'ico-filter', '<dl class="wide', 'class="kpis'] as $old) {
                self::assertStringNotContainsString($old, $src, "{$name}: {$old} is the old layout");
            }
            if (in_array('board', $patterns, true) && in_array('toolbar', $patterns, true)) {
                self::assertSame(1, substr_count($src, '<div class="tb-state">'), "{$name}: one Show row");
                self::assertLessThanOrEqual(1, substr_count($src, '<input id="tb-q" type="search"'), "{$name}: one search box");
            }
        }
        // A row that holds its own form keeps it in td.c-next, raised above the row's link (main's shared rule, W8).
        foreach (['arrivals', 'invoices_not_invoiced'] as $name) {
            self::assertMatchesRegularExpression('#<td class="c-next">\s*(<\?php[^>]*\?>\s*)?(<a class="btn sm"|<form)#', $t[$name . '.php'], "{$name}: the row's own action in td.c-next");
        }
        // A one-page form's bar: "Leave without saving" first, the main action last (the supplier's one Save; a return's draft: Save the lines,
        // then "Make the return final", which names its own form).
        self::assertStringContainsString("\$partial('doc_bar', ['back' =>", $t['supplier_form.php']);
        self::assertSame(1, substr_count($t['supplier_form.php'], "'primary' => true"), 'the supplier form: one Save');
        self::assertMatchesRegularExpression('#<div class="doc-bar">\s*<a class="btn ghost" href="[^"]*" data-leave>.*?</a>\s*<span class="doc-bar-note">.*?</span>\s*'
            . '(<\?php if \(\$lines !== \[\]\): \?>\s*)?<button class="btn" type="submit" form="ret-lines">.*?</button>\s*(<\?php endif; \?>\s*)?'
            . '<button type="submit" class="btn primary" form="ret-post">#s', $t['supplier_return.php'], 'the draft: Leave, the note, Save the lines, then Make final');
        // The supplier form: 27 fields in three sections, the country a picker of every country (what CW keeps: its two-letter code).
        preg_match_all("/\\\$field\\('([a-z0-9_]+)'/", $t['supplier_form.php'], $fields);
        self::assertSame(array_keys(\CW\Suppliers\Suppliers::FIELDS), array_values(array_intersect(array_keys(\CW\Suppliers\Suppliers::FIELDS), $fields[1])), 'every field');
        self::assertCount(27, $fields[1]);
        self::assertSame(['details', 'terms', 'dd'], preg_match_all("/^    '([a-z]+)' => \\[\$/m", $t['supplier_form.php'], $sec) > 0 ? $sec[1] : []);
        self::assertStringContainsString("\$field('country', 'country', 'country')", $t['supplier_form.php']);
        self::assertStringContainsString('\CW\Ui\Words::COUNTRY as $code => $name', $t['supplier_field.php']);
        self::assertCount(249, Words::COUNTRY, 'ISO 3166-1');
        foreach (array_keys(Words::COUNTRY) as $code) {
            self::assertMatchesRegularExpression('/^[A-Z]{2}$/', $code, 'what Suppliers keeps');
        }
        // What to buy: ONE "Create draft orders", in the bar at the bottom of its form; the sales data's upkeep under More in the head.
        self::assertSame(1, substr_count($t['reorder.php'] . $t['reorder_bar.php'], "\$word('REORDER', 'create')"));
        self::assertMatchesRegularExpression("#<div class=\"doc-bar\">\\s*<span class=\"doc-bar-note\">.*?</span>\\s*<button type=\"submit\" class=\"btn primary\"><\\?= \\\$word\\('REORDER', 'create'\\) \\?></button>#s",
            $t['reorder.php']);
        self::assertStringContainsString("\$partial('reorder_bar'", $t['reorder.php']);
        foreach (['/ui/purchasing/reorder/refresh', '/ui/purchasing/reorder/recalculate', '/ui/purchasing/reorder/brands', '/ui/purchasing/reorder/anomalies',
            '/ui/purchasing/sales-history', '/ui/purchasing/reorder.csv'] as $upkeep) {
            self::assertStringContainsString($upkeep, $t['reorder_bar.php'], "under More: {$upkeep}");
            self::assertStringNotContainsString($upkeep, $t['reorder.php'], "not on the page itself: {$upkeep}");
        }
        self::assertStringContainsString('<details class="more">', $t['reorder_bar.php']);
        self::assertStringNotContainsString(' primary', $t['reorder_bar.php'], 'nothing in the head is a main action');
        // The new pieces close what they open (a stray </div> in a bar pushes the next step out of the head).
        foreach (['supplier_bar', 'supplier_field', 'supplier_items_board', 'supplier_items_bar', 'return_bar', 'return_files', 'reorder_bar', 'low_stock_bar',
            'recheck_bar', 'export_bar', ...array_keys($pages)] as $name) {
            foreach (['div', 'details', 'form', 'section', 'table', 'article', 'fieldset', 'nav'] as $tag) {
                self::assertSame(preg_match_all('/<' . $tag . '\b/', $t[$name . '.php']), substr_count($t[$name . '.php'], '</' . $tag . '>'), "{$name}: every <{$tag}> closed");
            }
        }
    }

    /**
     * Wave w10 (docs/decisions.md W10): the Sales pages on the approved patterns. Store orders, Dispatch and Returns are boards (one Show
     * row, no Filter pop-over, coloured groups over cards, the solid status cell); an order is the record page (status block and its bar in
     * the head, the strip, the paper card with the store beside the facts and the units as its lines, the history as groups); Sales by
     * Store is the report (the strip over the boards). None posts anything.
     */
    public function testTheSalesPagesFollowTheApprovedPatterns(): void
    {
        $t = self::templates();
        $board = ['<form class="toolbar" method="get"', '<div class="tb-state">', '<select id="tb-show" name="', '<div class="grp-block doc-group ',
            '<button class="grp-toggle" type="button" aria-expanded="true" aria-controls="', '<div class="table-wrap doc-board" id="', '<td class="c-status doc-st '];
        $pins = [
            'sales_orders' => [...$board, '<a class="o-name row-link"', 'class="stack list board doc-list sales-orders"', '<div class="tb-end">'],
            'sales_dispatch' => [...$board, '<a class="o-name row-link"', 'class="stack list board doc-list dispatch-waiting"', 'class="stack list board doc-list dispatch-sent"',
                'class="stack list board doc-list dispatch-undone"', '<section class="doc-progress rp-figs"'],
            'sales_returns' => [...$board, 'class="stack list board doc-list sales-returns"', '<section class="doc-progress rp-figs"', '<div class="tb-end">'],
            'sales_order' => ['<div class="doc-head">', 'side: $bar', 'chips: $chip($tone, $chipWord)', '<section class="doc-progress rp-figs"', '<article class="doc-view"',
                "\$partial('doc_party'", 'class="stack list board doc-lines sales-units"', '<td class="c-status doc-st ', '<section class="doc-related"',
                '<div class="grp-block doc-group info">', 'class="table-wrap doc-board" id="u-'],
            'sales_order_bar' => ['<div class="doc-actions" role="group"'],
            'report_sales' => ['<form class="toolbar" method="get"', '<div class="tb-state">', '<label class="tb-day">', '<div class="tb-end">',
                '<section class="doc-progress rp-figs"', '<section class="doc-related"', '<div class="table-wrap doc-board info">', 'class="stack list board doc-list report-sales"',
                'class="stack list board doc-list report-days"', '<tr class="grp-foot">'],
        ];
        foreach ($pins as $name => $parts) {
            $src = $t[$name . '.php'] ?? '';
            foreach ($parts as $part) {
                self::assertStringContainsString($part, $src, "{$name}: {$part}");
            }
            foreach (['class="tb-pop"', 'class="kpis', 'class="home-tiles"', '<dl class="wide', 'ico-filter', 'class="foot"', 'show-tabs', 'row-strips', 'method="post"'] as $old) {
                self::assertStringNotContainsString($old, $src, "{$name}: {$old} is the old layout");
            }
            // A status is a solid cell on a board, the head's block on the record: never a small chip in a row.
            self::assertSame($name === 'sales_order' ? 1 : 0, substr_count($src, '$chip('), "{$name}: the status is a solid cell or the head's block");
            foreach (['div', 'details', 'form', 'section', 'table', 'article', 'nav'] as $tag) {
                self::assertSame(preg_match_all('/<' . $tag . '\b/', $src), substr_count($src, '</' . $tag . '>'), "{$name}: every <{$tag}> closed");
            }
        }
        foreach (['sales_orders', 'sales_dispatch', 'sales_returns'] as $name) {
            self::assertSame(1, substr_count($t[$name . '.php'], '<div class="tb-state">'), "{$name}: one Show row");
        }
        self::assertSame(2, substr_count($t['sales_orders.php'], '<label class="tb-day">'), 'the days typed: From and Until, in the Show row');
    }

    /**
     * Typed values on the buying pages are never lost without a word (docs/decisions.md W9.15; the Codex review of a96ff5b: a return's line
     * changed, then "Save details", and the change was gone). In each page's templates (with its bar and files partials), every branch: a form
     * that keeps typed values (a POST form with a field to type in or choose; an upload is its own send, as main's files forms) carries main's
     * guard in DOC's words, and on a page with such a form and another form, every form carries data-leaves (it says so first when another
     * form holds typed values; CV14, IV13). The kernel's BuyingPatternsScreensTest checks the pages as served.
     */
    public function testTheBuyingPagesGuardTypedValuesAndEveryFormThatLeavesAsksFirst(): void
    {
        $t = self::templates();
        $pages = [
            'suppliers' => ['suppliers'], 'supplier' => ['supplier', 'supplier_bar'], 'supplier_form' => ['supplier_form'],
            'supplier_items' => ['supplier_items', 'supplier_items_bar', 'supplier_items_board'], 'supplier_returns' => ['supplier_returns'],
            'supplier_return' => ['supplier_return', 'return_bar', 'return_files'], 'invoices' => ['invoices'], 'invoices_to_match' => ['invoices_to_match', 'recheck_bar'],
            'invoices_not_invoiced' => ['invoices_not_invoiced'], 'invoices_timed' => ['invoices_timed'], 'arrivals' => ['arrivals'], 'incidents' => ['incidents'],
            'duty_register' => ['duty_register'], 'reorder' => ['reorder', 'reorder_bar'], 'reorder_low_stock' => ['reorder_low_stock', 'low_stock_bar'],
            'reorder_brands' => ['reorder_brands'], 'reorder_anomalies' => ['reorder_anomalies'], 'reorder_suppliers' => ['reorder_suppliers'], 'sales_history' => ['sales_history'],
            // The Products pages (wave w11, docs/decisions.md W11): the same rule.
            'item_cards' => ['item_cards'], 'item_new' => ['item_new'], 'items_by_hand' => ['items_by_hand'], 'families' => ['families'], 'family' => ['family', 'family_bar'],
            'packs' => ['packs'], 'pack_links' => ['pack_links'], 'barcode_reviews' => ['barcode_reviews'],
        ];
        $tagRe = '(?:<\?(?:php|=).*?\?>|[^>])*>';
        $counts = [];
        foreach ($pages as $page => $names) {
            $forms = [];
            foreach ($names as $name) {
                $src = $t[$name . '.php'];
                preg_match_all('/<form\b' . $tagRe . '/s', $src, $m, PREG_OFFSET_CAPTURE);
                foreach ($m[0] as [$tag, $at]) {
                    $end = strpos($src, '</form>', $at);
                    self::assertNotFalse($end, "{$name}: a form is closed");
                    $body = substr($src, $at + strlen($tag), $end - $at - strlen($tag));
                    preg_match_all('/<(?:input|select|textarea)\b' . $tagRe . '/s', $body, $fields);
                    $typed = 0;
                    $upload = false;
                    foreach ($fields[0] as $f) {
                        $type = preg_match('/^<input\b.*?\btype="([a-z]+)"/s', $f, $ty) === 1 ? $ty[1] : (str_starts_with($f, '<input') ? 'text' : 'choice');
                        $upload = $upload || $type === 'file';
                        $typed += in_array($type, ['hidden', 'submit', 'button', 'file'], true) ? 0 : 1;
                    }
                    $typed += substr_count($body, "\$partial('supplier_field'");
                    $forms[] = ['where' => $name . ' line ' . (substr_count(substr($src, 0, $at), "\n") + 1), 'tag' => $tag,
                        'typed' => str_contains($tag, 'method="post"') && !$upload && $typed > 0];
                }
            }
            $guarded = 0;
            foreach ($forms as $f) {
                if ($f['typed']) {
                    $guarded++;
                    self::assertStringContainsString(' data-unsaved="1" data-unsaved-text="<?= $word(\'DOC\', \'unsaved\') ?>"', $f['tag'], "{$f['where']}: what is typed here is guarded");
                } else {
                    self::assertStringNotContainsString('data-unsaved', $f['tag'], "{$f['where']}: nothing to keep");
                }
            }
            if ($guarded > 0 && count($forms) > 1) {
                foreach ($forms as $f) {
                    self::assertStringContainsString(' data-leaves="1"', $f['tag'], "{$f['where']}: it leaves the page, so it asks first when another form holds typed values");
                }
            }
            $counts[$page] = $guarded;
        }
        self::assertSame(['supplier' => 5, 'supplier_form' => 1, 'supplier_returns' => 2, 'supplier_return' => 6, 'invoices' => 1, 'incidents' => 1, 'reorder' => 1,
            'reorder_low_stock' => 1, 'reorder_brands' => 1, 'reorder_anomalies' => 1, 'reorder_suppliers' => 1,
            'item_new' => 1, 'items_by_hand' => 1, 'families' => 1, 'family' => 3, 'packs' => 1, 'barcode_reviews' => 1], array_filter($counts), 'every guarded form was read');
    }

    /**
     * The supplier form's Save is unnamed, as it was before the one-page form (docs/decisions.md W9.18; the Codex review of a96ff5b): doc_bar
     * names a button and gives it its value only when the caller passes one, and every other page's bar still passes its `action`.
     */
    public function testTheSupplierFormsSaveIsUnnamedAndTheOtherBarsKeepTheirAction(): void
    {
        $t = self::templates();
        self::assertStringContainsString('<button type="submit"<?php if (isset($b[\'value\'])): ?> name="<?= $e($b[\'name\'] ?? \'action\') ?>" value="<?= $e($b[\'value\']) ?>"<?php endif; ?> class="btn',
            $t['doc_bar.php']);
        $calls = 0;
        foreach (['purchase_order_edit', 'invoice_edit', 'receipt_edit', 'supplier_form', 'item_new'] as $name) {
            self::assertSame(1, preg_match("/\\\$partial\\('doc_bar', \\[(.*?)\\]\\) \\?>/s", $t[$name . '.php'], $m), "{$name}: its bar");
            // Its buttons: in the call, or built before it ($buttons, the delivery's page).
            $src = $m[1] . (preg_match_all('/^\s*\$buttons(?:\[\])? = .*$/m', $t[$name . '.php'], $built) > 0 ? implode("\n", $built[0]) : '');
            $buttons = substr_count($src, "'label' =>");
            self::assertGreaterThan(0, $buttons, "{$name}: its buttons");
            self::assertSame(in_array($name, ['supplier_form', 'item_new'], true) ? 0 : $buttons, substr_count($src, "'value' =>"), "{$name}: what its buttons send (Add Item's: nothing, W11.2)");
            $calls++;
        }
        self::assertSame($calls, preg_match_all("/\\\$partial\\('doc_bar'/", implode('', $t)), 'every bar was read');
    }

    /**
     * Wave w11 (docs/decisions.md W11): the Products pages on the approved patterns. All Products, the items made by hand, Families, Pack items
     * and Pack links are boards (no Filter pop-over or filter chips: the filters are plain inputs in the one Show row, one Clear); Add Item is the
     * one-page form (name, brand and barcode first, the legal and the buying details in folds, the bar at the bottom); a family is the record
     * page; Barcodes is the inbox (the list on the left, one record open with its one decision).
     */
    public function testTheProductPagesFollowTheApprovedPatterns(): void
    {
        $t = self::templates();
        $pins = [
            'board' => ['<div class="grp-block doc-group ', '<button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-', '<span class="grp-count">',
                '<div class="table-wrap doc-board" id="g-', '<td class="c-status doc-st '],
            'toolbar' => ['<form class="toolbar', '" method="get"', '<div class="tb-state">', '<select id="tb-show" name="'],
            'search' => ['<div class="tb-search">', '<input id="tb-q" type="search"'],
            'row_link' => ['<a class="o-name row-link"'],
            'add_row' => ['<tr class="grp-add">', '<a class="add-item"'],
            'new' => ['<details class="fold new-order drawer" id="new"', '<form class="doc-details" method="post"', '<fieldset class="doc-grid">'],
            'record' => ['<div class="doc-head">', 'side: $bar', '<section class="doc-progress rp-figs"', '<article class="doc-view"', "\$partial('doc_party'"],
            'form' => ['<form class="doc-details item-new" id="new" method="post" action="/ui/items/new" data-unsaved="1"', '<fieldset class="doc-grid">',
                '<details class="fold" id="new-legal"', '<details class="fold" id="new-buying"', "\$partial('doc_bar', ['back' => '/ui/items/cards'", "side: ''"],
            'inbox' => ['<div class="ap-inbox">', '<section class="card ap-list"', '<div class="ap-group ', '<a class="ap-row', '<section class="ap-record ',
                '<form class="ap-answer" id="ap-answer" method="post"', '<button type="submit" form="ap-answer" class="btn primary">', '<p class="ap-next">'],
        ];
        $pages = [
            'item_cards' => ['board', 'toolbar', 'search', 'row_link'], 'item_new' => ['form'], 'items_by_hand' => ['board', 'toolbar', 'row_link', 'add_row'],
            'families' => ['board', 'toolbar', 'search', 'row_link', 'add_row', 'new'], 'family' => ['record'],
            'packs' => ['board', 'toolbar', 'search', 'row_link', 'add_row', 'new'], 'pack_links' => ['board', 'row_link'], 'barcode_reviews' => ['inbox', 'toolbar', 'search'],
        ];
        foreach ($pages as $name => $patterns) {
            $src = $t[$name . '.php'] ?? '';
            foreach ($patterns as $p) {
                foreach ($pins[$p] as $part) {
                    self::assertStringContainsString($part, $src, "{$name}: the {$p} pattern's {$part}");
                }
            }
            foreach (['class="tb-pop"', 'filters-on', 'filter-chips', 'tb-clear', 'class="fold how"', 'ico-filter', 'show-tabs', 'row-strips', 'class="kpis', 'summary-line',
                '<dl class="wide', 'class="card made-by-hand"', 'class="card barcode-review"'] as $old) {
                self::assertStringNotContainsString($old, $src, "{$name}: {$old} is the old layout");
            }
            if (in_array('toolbar', $patterns, true)) {
                self::assertSame(1, substr_count($src, '<div class="tb-state">'), "{$name}: one Show row");
                self::assertLessThanOrEqual(1, substr_count($src, '<input id="tb-q" type="search"'), "{$name}: one search box");
            }
            if (in_array('board', $patterns, true)) {
                self::assertSame(0, substr_count($src, '$chip(') + substr_count($src, '$stateChip('), "{$name}: a status is the solid cell, a small fact a soft tag");
                self::assertStringNotContainsString('rp-figs', $src, "{$name}: the approved board has no figures strip");
                self::assertStringNotContainsString('board-title', $src, "{$name}: nor a heading of its own over the groups");
            }
        }
        // All Products: the filters are plain inputs in the Show row (the card, the kind, four ticks) and ONE Clear takes them all off.
        self::assertSame(4, substr_count($t['item_cards.php'], '<label class="choice tb-check"><input type="checkbox" name="'));
        self::assertSame(1, substr_count($t['item_cards.php'], "\$word('CARDS', 'clear_filters')"));
        // Add Item: name, brand and barcode come first; the made-by-hand list (and its check) is on its own view, not under the form.
        preg_match_all('/<input id="[^"]+" type="text" name="([a-z_]+)"/', $t['item_new.php'], $names);
        self::assertSame(['name', 'brand', 'barcode'], array_slice($names[1], 0, 3));
        self::assertStringNotContainsString('/check', $t['item_new.php']);
        self::assertStringContainsString('<td class="c-next">', $t['items_by_hand.php']);
        self::assertSame(1, substr_count($t['item_new.php'], "'primary' => true"), 'one main button');
        // A family's bar: Add items the main one, the rest under More.
        self::assertStringContainsString('<div class="doc-actions" role="group"', $t['family_bar.php']);
        self::assertStringContainsString('<details class="more"<?php if ($renamed): ?> open<?php endif; ?>>', $t['family_bar.php'], 'More, open again on a refused rename (W11.13)');
        self::assertStringContainsString('<summary class="btn primary">', $t['family_bar.php']);
        foreach (['item_cards', 'item_new', 'items_by_hand', 'families', 'family', 'family_bar', 'packs', 'pack_links', 'barcode_reviews'] as $name) {
            foreach (['div', 'details', 'form', 'section', 'table', 'article', 'fieldset', 'nav', 'ul'] as $tag) {
                self::assertSame(preg_match_all('/<' . $tag . '\b/', $t[$name . '.php'] ?? ''), substr_count($t[$name . '.php'] ?? '', '</' . $tag . '>'), "{$name}: every <{$tag}> closed");
            }
        }
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
        // Codex's seventh review: a bracket in a comment, or in a double-quoted string, is not the code's.
        self::assertFalse(self::onlyHelperCalls("\$e(/* ( */'') . \$row['name'] // )\n"));
        self::assertFalse(self::onlyHelperCalls('$e("$x)") . $raw'));
        self::assertTrue(self::onlyHelperCalls('$e(/* a note */ $x) . $n($y) // the count'));
        self::assertFalse(self::onlyHelperCalls(''));
        // Codex's eighth review: an echo is read up to the closing tag PHP itself ends it with, not one inside a block comment.
        $tpl = "<p><?= \$e('') /* ?> */ . \$row['name'] ?></p>";
        self::assertSame([" \$e('') /* ?> */ . \$row['name'] "], self::echoes($tpl));
        self::assertFalse(self::onlyHelperCalls(trim(self::echoes($tpl)[0])), 'refused: the raw value after the comment is read');
        self::assertSame([" \$e(\$a) ", "\$b = 1; "], self::phpBlocks("<?= \$e(\$a) ?><i><?php \$b = 1; ?>"), 'the open tag keeps its own space');
        self::assertSame('&lt;b&gt;', Html::e('<b>'));
    }
}
