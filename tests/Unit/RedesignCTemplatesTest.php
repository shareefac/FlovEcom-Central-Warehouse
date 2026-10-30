<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Ui\View;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * The redesign, step 2 part C (docs/decisions.md RD-C1-RD-C16; the approved canvas of 30 Oct 2026: Listings, ByItem, Reports, Approvals,
 * Settings, Main): each page's parts pinned in its template. The rendered pages: tests/Integration/UiKernel/RedesignCScreensTest.php.
 */
final class RedesignCTemplatesTest extends TestCase
{
    /** @return array<string, string> name (no .php) => source */
    private static function t(): array
    {
        $out = [];
        foreach (glob(View::defaultDir() . '/*.php') ?: [] as $file) {
            $out[basename($file, '.php')] = (string) file_get_contents($file);
        }
        return $out;
    }

    private static function balanced(string $name, string $src): void
    {
        foreach (['div', 'details', 'form', 'section', 'table', 'article', 'nav', 'ul', 'dl', 'select'] as $tag) {
            self::assertSame(preg_match_all('/<' . $tag . '\b/', $src), substr_count($src, '</' . $tag . '>'), "{$name}: every <{$tag}> closed");
        }
    }

    /** RD-C1-RD-C6: Check a match is ONE comparison card, the find and units under it, the note under More, the same one form. */
    public function testCheckAMatchIsOneComparisonCardAndTheSameOneForm(): void
    {
        $l = self::t()['listing'];
        self::balanced('listing', $l);
        foreach (['<section class="card mt-compare" aria-label="<?= $word(\'MATCH\', \'compare\') ?>">', '<div class="mt-row mt-heads">',
            '<div class="mt-side mt-store">', '<div class="mt-side mt-item">', '<span class="mt-dot" aria-hidden="true"></span>',
            '<div class="mt-row mt-fact <?php if ($same): ?>same<?php else: ?>diff<?php endif; ?>" data-state="<?= $e($r[\'state\']) ?>">',
            '<span class="mt-mark same" role="img" aria-label="<?= $word(\'MATCH\', \'mark_same\') ?>">✓</span>',
            '<span class="mt-mark diff" role="img" aria-label="<?= $word(\'MATCH\', \'mark_differs\') ?>">!</span>',
            '<div class="mt-pick">', '<div class="field mt-find" id="f-pick">', '<div class="field mt-units">', 'name="units_per_item"', 'form="mt-find"',
            '<form id="mt-find" method="get"', '<details class="mt-others" id="cand">', "\$partial('listing_more', \$more)", 'data-progress="'] as $part) {
            self::assertStringContainsString($part, $l, "listing: {$part}");
        }
        foreach (['class="mt-photo"', '<div class="mt-cards', 'section class="card mt-card ', 'mt-fill'] as $old) {
            self::assertStringNotContainsString($old, $l, "listing: {$old} is the old layout");
        }
        // ONE decision form; its answers by the button pressed; Enter answers nothing; the note a field of it under the bar's More (RD-C4).
        self::assertSame(1, substr_count($l, '<form class="mt-form" id="decide" method="post" action="/ui/review/listing/'));
        self::assertStringContainsString('<button type="submit" class="po-default" disabled hidden tabindex="-1" aria-hidden="true">', $l);
        foreach (['name="action" value="link" class="btn primary"', 'name="action" value="reject" class="btn"', 'name="action" value="new_item" class="btn"',
            'name="action" value="ignore" class="btn"', 'form="mark-landing" class="btn"', 'name="expected_map_version"', 'name="proposal_id"', 'name="sku_id"',
            'name="seen"'] as $part) {
            self::assertStringContainsString($part, $l, "listing: {$part}");
        }
        self::assertSame(1, substr_count($l, 'name="reason" maxlength="<?= $e($max_reason) ?>" value="<?= $e($form[\'reason\']) ?>"'), 'one note of the decision form');
        $more = strpos($l, '<div class="more-menu">' . "\n" . '<?php if ($one): ?>' . "\n" . '        <div class="field mt-note">');
        self::assertNotFalse($more, 'the note is the first part of the bar\'s More');
        self::assertStringContainsString('|| $changeOpen || $noteOpen): ?> open<?php endif; ?>>', $l, 'a refused note opens More');
        self::assertStringNotContainsString('type="radio"', $l);
        // The bar: Skip, More, Not the same, Same item, in that order.
        $bar = substr($l, (int) strpos($l, '<div class="doc-bar mt-bar" id="f-action">'));
        $at = static fn (string $s): int => (int) strpos($bar, $s);
        self::assertLessThan($at('<details class="more"'), $at("\$word('MATCH', 'skip')"));
        self::assertLessThan($at('name="action" value="reject"'), $at('<details class="more"'));
        self::assertLessThan($at('name="action" value="link"'), $at('name="action" value="reject"'));
        self::assertSame(['question' => 'Is this the same item?', 'to_match' => '%s left to check', 'find' => 'Not this one? Find another item',
            'units' => '1 sale on the store is', 'units_of' => 'of our item', 'skip' => 'Skip', 'not_same' => 'Not the same', 'same' => 'Same item', 'our_item' => 'Our item'],
            array_intersect_key(Words::MATCH, array_flip(['question', 'to_match', 'find', 'units', 'units_of', 'our_item', 'skip', 'not_same', 'same'])), 'the artboard\'s words');
    }

    /** RD-C7-RD-C10: Product matching's two tabs, the row of chips, the one filter CW has, More with every other matching page. */
    public function testProductMatchingHasTwoTabsAChipPerStoreAndItsMore(): void
    {
        $t = self::t();
        foreach (['by_product', 'by_product_new', 'by_product_bar', 'by_product_tabs'] as $name) {
            self::balanced($name, $t[$name]);
        }
        self::assertStringContainsString('<nav class="tabs bi-tabs" aria-label="<?= $word(\'RD_MATCH\', \'tabs\') ?>">', $t['by_product_tabs']);
        self::assertSame(2, substr_count($t['by_product_tabs'], '<a class="tab"'));
        foreach (['by_product', 'by_product_new'] as $name) {
            self::assertStringContainsString("\$partial('by_product_tabs'", $t[$name], $name);
            self::assertStringContainsString("\$partial('by_product_bar'", $t[$name], $name);
            self::assertStringContainsString("\$head(\\CW\\Ui\\Words::RD_MATCH['title']", $t[$name], $name);
            self::assertStringContainsString('<section class="card bi-list', $t[$name], $name);
        }
        $b = $t['by_product'];
        foreach (['<form class="bi-find" method="get" action="/ui/review/products"', '<select id="bi-cov" name="cov"', '<a class="bi-clear" href="<?= $e($clear_link) ?>">',
            '<div class="bi-row', '<div class="bi-item">', '<div class="bi-stores">', '<a class="bi-chip on', '<a class="bi-chip wait"', '<a class="bi-chip open suggested"',
            '<details class="quick-pick bi-quick" data-label="<?= $e($c[\'store\']) ?>" data-quick-pick', '<summary class="bi-chip open"', '<span class="bi-chip open off"',
            "\$say('RD_MATCH', 'stores_n', \$r['on'], count(\$stores))", 'name="pick_<?= $e($r[\'id\']) ?>"', 'name="cells_<?= $e($r[\'id\']) ?>"',
            '<form class="qp-form" method="get"', "\$partial('bulk_bar'"] as $part) {
            self::assertStringContainsString($part, $b, "by_product: {$part}");
        }
        // One filter: the page's `cov` (its store choices name the store); no Brand, Kind or Stock select (RD-C10).
        self::assertSame(1, substr_count($b, '<select '), 'By item: one filter select');
        foreach (['name="brand"', 'name="kind"', 'name="stock"', 'class="tabs show-tabs"', 'grp-block doc-group', 'c-status doc-st', 'rows-choice'] as $old) {
            self::assertStringNotContainsString($old, $b, "by_product: {$old}");
        }
        $n = $t['by_product_new'];
        foreach (['<input type="hidden" name="tab" value="new">', '<select id="nw-store" name="channel"', "\$say('RD_MATCH', 'store_n', \$s['name'], \$s['count'])",
            '<span class="chip <?php if ($r[\'likely\']): ?>needs bi-hint likely<?php else: ?>waiting bi-hint<?php endif; ?>">', '<a class="btn" href="<?= $e($r[\'link\']) ?>"><?= $word(\'RD_MATCH\', \'match\') ?></a>',
            "\\CW\\Ui\\Words::RD_MATCH['match_all']"] as $part) {
            self::assertStringContainsString($part, $n, "by_product_new: {$part}");
        }
        $bar = $t['by_product_bar'];
        self::assertStringContainsString('<details class="more">', $bar);
        self::assertSame(1, substr_count($bar, 'btn primary'), 'one main button: Match all new');
        self::assertSame(['Product matching', 'By item', 'New on the stores', 'Match all new', 'Clear filters', '2 of 3 stores'],
            [Words::RD_MATCH['title'], Words::RD_MATCH['tab_items'], Words::RD_MATCH['tab_new'], Words::RD_MATCH['match_all'], Words::RD_MATCH['clear'],
                Words::say('RD_MATCH', 'stores_n', 2, 3)]);
    }

    /** RD-C11, RD-C12: the five reports and the duty register on one layout. */
    public function testTheReportsShareOneLayout(): void
    {
        $t = self::t();
        foreach (['report_value', 'report_store', 'report_low', 'report_slow', 'report_sales', 'duty_register'] as $name) {
            $src = $t[$name];
            self::balanced($name, $src);
            foreach (["back: ['#all-reports', \\CW\\Ui\\Words::RD_REPORTS['all']]", '<form class="card rp-options" method="get"',
                '<button type="submit" class="btn primary"><?= $word(\'RD_REPORTS\', \'run\') ?></button>', '<article class="card rp-paper"',
                "\$partial('report_paper_head', ['company' => \$company,", '<table class="stack rp-table ', '<tr class="rp-total">', "\$partial('report_all', ['groups' => \$allReports])"] as $part) {
                self::assertStringContainsString($part, $src, "{$name}: {$part}");
            }
            foreach (['doc-progress rp-figs', 'class="toolbar"', 'doc-board', 'c-status doc-st', 'Print', '<meter'] as $old) {
                self::assertStringNotContainsString($old, $src, "{$name}: {$old}");
            }
            self::assertSame(1, substr_count($src, 'btn primary'), "{$name}: one main button, Run report");
        }
        self::assertStringContainsString('<a class="btn" href="<?= $e($href) ?>" title="<?= $word(\'RD_REPORTS\', \'export_title\') ?>" download><?= $word(\'RD_REPORTS\', \'export\') ?></a>',
            $t['report_bar']);
        self::assertStringContainsString('<section class="card rp-all" id="all-reports"', $t['report_all']);
        self::assertStringContainsString('<a class="rp-all-link" aria-current="page">', $t['report_all'], 'the report shown is not a link');
        self::assertStringContainsString('<tr class="rp-group"><th scope="rowgroup"', $t['report_sales']);
        self::assertStringContainsString('<tr class="rp-gtotal">', $t['report_sales']);
        self::assertSame(2, substr_count($t['duty_register'], '<tr class="rp-gtotal">'), 'the duty register\'s two groups have their totals');
        self::assertSame(['Inventory valuation summary', 'Stock summary by store', 'Inventory aging', 'Low stock', 'Sales by store', 'Duty register', 'Reorder suggestions'],
            array_values(array_intersect_key(Words::RD_REPORTS, array_flip(['stock_value', 'stock_by_store', 'slow_stock', 'low_stock', 'sales_by_store', 'duty', 'reorder']))));
    }

    /** RD-C13: Approvals' Show = kind, kept through an answer; Next at the left of the answers. */
    public function testApprovalsShowsOneKindAndKeepsItThroughAnAnswer(): void
    {
        $t = self::t();
        self::balanced('approvals_show', $t['approvals_show']);
        self::assertStringContainsString('<form class="ap-show" method="get" action="/ui/approvals"', $t['approvals_show']);
        self::assertStringContainsString('<select id="ap-show" name="show">', $t['approvals_show']);
        self::assertStringContainsString("\$partial('approvals_show'", $t['approvals_inbox']);
        self::assertStringContainsString("\$u('/ui/approvals', ['open' => \$r['key'], 'show' => \$show])", $t['approvals_inbox']);
        self::assertStringContainsString('<div class="ap-inbox rd-inbox">', $t['approvals_inbox']);
        self::assertSame(2, substr_count($t['approvals_record'], '<input type="hidden" name="inbox_show" value="<?= $e($show) ?>">'), 'the answer and the fold carry it');
        $r = $t['approvals_record'];
        $actions = substr($r, (int) strpos($r, '<div class="ap-actions">'));
        self::assertLessThan(strpos($actions, '<details class="more"'), strpos($actions, '<p class="ap-next">'), 'Next at the left');
        self::assertLessThan(strpos($actions, 'class="btn primary"'), strpos($actions, '<details class="more"'), 'the main answer last');
    }

    /** RD-C14: every settings page draws the settings shell with its section list. */
    public function testEverySettingsPageDrawsTheSettingsShell(): void
    {
        $t = self::t();
        $pages = ['settings' => 'settings', 'setting' => 'settings', 'company' => 'company', 'company_form' => 'company', 'warehouses' => 'warehouses',
            'warehouse' => 'warehouses', 'sites' => 'sites', 'people' => 'people', 'person' => 'people', 'access' => 'people', 'access_permission' => 'people',
            'approvals' => 'approvals', 'reasons' => 'reasons', 'reason' => 'reasons', 'series' => 'series', 'integrity' => 'integrity', 'audit' => 'audit'];
        foreach ($pages as $name => $key) {
            $src = $t[$name];
            self::balanced($name, $src);
            self::assertStringContainsString("<div class=\"set-shell\">\n<?= \$partial('settings_nav', ['current' => '{$key}']) ?>\n<div class=\"set-main\">", $src, $name);
            self::assertLessThan(strpos($src, '<div class="set-shell">'), strpos($src, '$head('), "{$name}: the shell under the heading");
        }
        $nav = $t['settings_nav'];
        self::balanced('settings_nav', $nav);
        self::assertStringContainsString('ReferenceController::settingsSections($who)', $nav);
        self::assertSame(['company', 'warehouses', 'sites', 'people', 'approvals', 'reasons', 'settings', 'series', 'integrity', 'audit'],
            array_keys(\CW\Ui\Controller\ReferenceController::SETTINGS_SECTIONS), 'the artboard\'s order');
        self::assertStringContainsString('<a class="set-nav-item" aria-current="page">', $nav, 'the section shown is not a link');
        self::assertSame(['Company', 'Warehouses', 'Stores', 'Users and roles', 'Approval rules', 'Reasons', 'Rules and numbers', 'Numbering', 'System checks', 'Audit log'],
            array_values(array_diff_key(Words::RD_SETTINGS, ['sections' => 1])));
    }

    /** RD-C15: Home's buttons, tiles, "Waiting for you" rows as links, the Stores and Today cards; no Find box. */
    public function testHomeFollowsTheMainArtboard(): void
    {
        $t = self::t();
        $h = $t['home'];
        self::balanced('home', $h);
        foreach (['<section class="hm-tiles hm-figs"', '<a class="hm-tile hm-<?= $e($f[\'tone\']) ?>"', '<span class="hm-fig-n"><?= $e($f[\'shown\']) ?></span>',
            '<div class="hm-grid">', '<a class="hm-open" href="<?= $e($inbox) ?>">', '<section class="card hm-stores"', '<section class="card hm-today"',
            "\$partial('home_actions'"] as $part) {
            self::assertStringContainsString($part, $h, "home: {$part}");
        }
        self::assertArrayNotHasKey('home_find', $t, 'the Find box is the sidebar\'s (step 1)');
        self::assertStringNotContainsString('hm-find', $h);
        self::assertSame(1, substr_count($t['home_actions'], 'btn primary'), 'one main button: + New purchase order');
        self::assertStringContainsString('<a class="hm-go" href="<?= $e($c[\'href\']) ?>">', $t['cards']);
        self::assertStringNotContainsString('class="btn', $t['cards'], 'a row is a link, not a button');
    }
}
