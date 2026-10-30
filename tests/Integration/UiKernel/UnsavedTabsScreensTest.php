<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\CountsController;
use CW\Ui\Words;

/**
 * Typed values are never lost through a tab or a filter link (docs/decisions.md CV13, CV14; Codex's review of 7cfcc89: on a count sheet
 * showing "Every line", a quantity typed and not saved yet was lost by a click on the "Every line" tab, which reloaded the sheet with no
 * save and no question). The tab of the page shown has no link (KernelBrowser checks it on every page, tests/Support/CurrentTabs); every
 * other tab or link stays a link, and app.js says so in the page first (the same link again goes on) while a form marked data-unsaved holds
 * typed values: the count sheet, a delivery, a stock record, a hand-over, and now the purchase order and supplier invoice pages too. A
 * download does not leave the page; "Leave without saving" says what it does: neither stops.
 */
final class UnsavedTabsScreensTest extends KernelUiTestCase
{
    protected function handlers(Db $db): array
    {
        return DocumentHandlers::all($db);
    }

    /** @return list<array{0: string, 1: string, 2: bool}> each tab of the page's rows matching $nav: its words, its aria-current, whether it links */
    private static function tabRow(UiResponse $page, string $nav): array
    {
        $xp = new \DOMXPath($page->dom());
        $out = [];
        foreach ($xp->query($nav . '/*[contains(concat(" ", @class, " "), " tab ")]') ?: [] as $t) {
            /** @var \DOMElement $t */
            $out[] = [trim((string) preg_replace('/\s+/u', ' ', $t->textContent)), $t->getAttribute('aria-current'), $t->hasAttribute('href')];
        }
        return $out;
    }

    public function testTheSheetsShownFilterTabHasNoLinkAndTheQuantityFormAsksBeforeALinkLeaves(): void
    {
        $a = $this->item('strict', 10, 'Alpha pod 2ml');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $list = $web->get(CountsController::PATH);
        $r = $web->post(CountsController::PATH, ['mode' => 'ticked', 'pick_' . $a => '1'] + $list->form(CountsController::PATH));
        self::assertSame(303, $r->status, $r->describe());
        $sheet = (string) parse_url((string) $r->location(), PHP_URL_PATH);
        // Codex's case: "Every line" shown (also with a notice in the address, as after a save). The lines' choice is the sheet's Show
        // (docs/decisions.md DC35; CT_Sheet draws no tab row): a GET form of `show` with its Show button, the one shown selected (never a
        // link: CV13); it leaves the page, so app.js asks first while the quantities hold typed values (data-leaves, CV14).
        foreach ([[null, 'all'], ['todo', 'todo'], ['done', 'done']] as [$show, $chosen]) {
            $page = $web->get($sheet, ['show' => $show, 'notice' => 'lines', 'n' => '1']);
            $xp = new \DOMXPath($page->dom());
            self::assertSame([], self::tabRow($page, '//main//nav'), "show {$show}: no tab row over the lines");
            $form = '//main//form[@method="get" and @action="' . $sheet . '" and @data-leaves="1" and contains(@class, "toolbar")]';
            self::assertSame(1, $xp->query($form)->length, "show {$show}: the Show form, guarded");
            self::assertSame(array_keys(Words::COUNT_SHOW), array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'), iterator_to_array($xp->query($form . '//select[@name="show"]/option'))));
            self::assertSame(array_values(Words::COUNT_SHOW), array_map(static fn (\DOMElement $o): string => trim($o->textContent), iterator_to_array($xp->query($form . '//select[@name="show"]/option'))));
            self::assertSame([$chosen], array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'), iterator_to_array($xp->query($form . '//select[@name="show"]/option[@selected]'))));
            self::assertSame(1, $xp->query($form . '//button[@type="submit"]')->length, 'its Show button: no script needed');
            self::assertSame(0, $xp->query($form . '//*[@href]')->length);
        }
        $page = $web->get($sheet);
        $xp = new \DOMXPath($page->dom());
        // The section's tab and the segment lead back to the lists from this record: links, marked as where the person is.
        // (the sidebar's sub-items since DC28: the section's pages and the page's segments)
        self::assertSame([['label' => 'Counts', 'href' => '/ui/stock/counts', 'count' => 0, 'current' => true]], array_values(array_filter(self::sectionTabs($page), static fn (array $t): bool => $t['current'])));
        self::assertSame('/ui/stock/counts/sheets', (string) $xp->evaluate('string(//main//header[contains(@class, "page-head")]//p[contains(@class, "ph-back")]/a/@href)'),
            'the sheet is one of Count sheets: its eyebrow leads back to them (DC3)');
        // The quantity form is guarded (app.js asks before a link or another form leaves while it holds typed values); the paper copy is a
        // download, which does not leave the page.
        self::assertSame(1, $xp->query('//form[@id="count-lines" and @data-unsaved="1" and @data-unsaved-text="' . Words::COUNTS['unsaved_leave'] . '"]')->length);
        self::assertSame(1, $xp->query('//a[contains(@href, "/sheet.csv") and @download]')->length);
        self::assertSame(1, $xp->query('//body[@data-unsaved-link="' . Words::UI['unsaved_link'] . '"]')->length, 'the words a link\'s note says');
        // The board itself: its own tab and segment have no link.
        $board = $web->get(CountsController::PATH);
        self::assertSame([['Counts', null]], array_map(static fn (array $t): array => [$t['label'], $t['href']], array_values(array_filter(self::sectionTabs($board), static fn (array $t): bool => $t['current']))));
        self::assertSame([[Words::SEGMENT['counts'], null]], array_map(static fn (array $t): array => [$t['label'], $t['href']], array_values(array_filter(self::segments($board), static fn (array $t): bool => $t['current']))));
    }

    public function testTheOrderAndInvoicePagesAskBeforeALinkLeavesWithTypedValues(): void
    {
        $web = $this->signIn($this->uiUser(['buyer', 'purchasing_desk']));
        foreach (['/ui/purchasing/orders/new', '/ui/purchasing/invoices/new'] as $path) {
            $page = $web->get($path);
            self::assertSame(200, $page->status, $page->describe());
            $xp = new \DOMXPath($page->dom());
            self::assertSame(1, $xp->query('//form[contains(@class, "po-editor") and @data-unsaved="1" and @data-unsaved-text="' . Words::DOC['unsaved'] . '"]')->length,
                "{$path}: the page's form is guarded like a delivery's");
            self::assertSame(1, $xp->query('//div[contains(@class, "doc-bar")]/a[@data-leave]')->length, "{$path}: \"Leave without saving\" never asks");
            // The section's tab leads to the list (another page): a link, which asks first while the form holds typed values.
            $current = array_values(array_filter(self::sectionTabs($page), static fn (array $t): bool => $t['current']));
            self::assertCount(1, $current);
            self::assertNotNull($current[0]['href'], "{$path}: the list's page in the sidebar is a link");
        }
    }
}
