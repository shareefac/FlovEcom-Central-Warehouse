<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Reorder\SalesHistoryImport;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Integration\Reorder\ReorderFixtures;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The buying pages and the product pages in plain words (plan §6.19-6.30 and the IM3 glossary pass; rules 2, 4, 9, 10, 11, 14):
 * What to buy (and one product's "Why", brand settings, days left out), Sales data, the purchase orders (the list, a draft in
 * its editor, a draft someone else made, a confirmed order a reviewer checks), the suppliers (the list, one supplier, the form,
 * its products, one supplier's product, adding one), the product list, a product card and its form, Barcodes to check and
 * "Change many product cards", for a buyer, a purchasing manager, a reviewer, the purchasing desk, a stock controller and an
 * auditor. Each has its title and its one-sentence intro (for a person who can only look: who changes it), no code, no "UTC",
 * no phase code, no server command, no "Your role (…)", no "(GBP)" and no "∞", and every table turns into cards on a phone (a
 * table that keeps its columns scrolls in its own box).
 */
final class BuyingWordsTest extends KernelUiTestCase
{
    use ReorderFixtures;

    protected function tearDown(): void
    {
        $this->cleanReorderFixtures();
        parent::tearDown();
    }

    public function testTheBuyingAndProductPagesSpeakPlainWordsAndTurnIntoCardsOnAPhone(): void
    {
        $s = $this->listScenario();
        $this->stockpiling();
        $this->builder()->rebuild();
        $buyer = $this->uiUser('buyer');
        $manager = $this->uiUser('purchasing_manager');
        $reviewer = $this->uiUser('reviewer');
        $desk = $this->uiUser('purchasing_desk');
        $stock = $this->uiUser('stock_controller');
        $auditor = $this->uiUser('auditor');
        // Test accounts are named after their role codes; a person on staging has a name, not a code.
        self::$db->exec("UPDATE staff_user SET display_name = CONCAT('Person ', id) WHERE display_name LIKE '%\\\\_%'");

        // A supplier of the buyer's, approved; a product it sells; a draft order of the buyer's, and a confirmed one.
        $sup = new Suppliers(self::$db);
        $made = $sup->create(Caller::staff($buyer['id']), ['name' => 'Plain Supplies', 'address_line1' => '2 Mill Lane', 'postcode' => 'M1 1AA',
            'email' => 'sales@plain.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400), 'dd_checked_by' => (string) $buyer['id'],
            'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400), 'min_order_value' => '100.00']);
        $made = $sup->requestActivation(Caller::staff($buyer['id']), (int) $made['id'], (int) $made['version']);
        $sup->approve(Caller::staff($reviewer['id']), (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'",
            [(int) $made['id']]), null);
        $sku = self::makeSku('Plain pod 2ml');
        $si = (new SupplierItems(self::$db))->create(Caller::staff($buyer['id']), (int) $made['id'], $sku, ['units_per_pack' => '10', 'supplier_code' => 'PP-10'],
            ['pack_price' => '12.50']);
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $draft = $pos->createDraft(Caller::staff($buyer['id']), (int) $made['id'], []);
        $draft = $pos->saveDraft(Caller::staff($buyer['id']), $draft->id, $draft->version, [], [['kind' => 'item', 'sku_id' => $sku, 'supplier_item_id' => (int) $si['id'],
            'units_per_pack' => 10, 'packs' => 2, 'pack_price' => '12.50']]);
        $confirmed = $pos->createDraft(Caller::staff($buyer['id']), (int) $made['id'], []);
        $confirmed = $pos->saveDraft(Caller::staff($buyer['id']), $confirmed->id, $confirmed->version, [], [['kind' => 'item', 'sku_id' => $sku,
            'supplier_item_id' => (int) $si['id'], 'units_per_pack' => 10, 'packs' => 20, 'pack_price' => '12.50']]);
        $confirmed = $pos->approve(Caller::staff($buyer['id']), $confirmed->id, $confirmed->version);
        // A product card with a warning, and sales loaded for Sales data.
        (new ItemCards(self::$db))->save(Caller::staff($stock['id']), $sku, 0, ['product_type' => 'tank', 'liquid_ml' => '5', 'duty_liable' => 'no']);
        $title = static fn (int $id): string => (string) self::$db->value('SELECT name FROM sku WHERE id = ?', [$id]);
        $code = static fn (int $id): string => (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$id]);

        $pages = [
            [$buyer, '/ui/purchasing/reorder', Words::MENU['reorder'], 'reorder'],
            [$buyer, '/ui/purchasing/reorder?show=all', Words::MENU['reorder'], 'reorder'],
            [$buyer, '/ui/purchasing/reorder/items/' . $s['a'], Words::say('REORDER_ITEM', 'title', $title($s['a']), $code($s['a'])), 'reorder_item'],
            [$buyer, '/ui/purchasing/reorder/brands', Words::PAGE_TITLE['reorder_brands'], 'reorder_brands'],
            [$buyer, '/ui/purchasing/reorder/anomalies', Words::PAGE_TITLE['reorder_anomalies'], 'reorder_anomalies'],
            [$buyer, '/ui/purchasing/sales-history', Words::MENU['sales_history'], 'sales_history'],
            [$buyer, '/ui/purchasing/orders', Words::MENU['orders'], 'orders'],
            [$buyer, '/ui/purchasing/orders/' . $draft->id, Words::say('ORDER', 'title_draft', 'Plain Supplies'), 'order_draft'],
            [$buyer, '/ui/purchasing/orders/' . $confirmed->id, (string) $confirmed->number . ' – Plain Supplies', 'order'],
            [$buyer, '/ui/purchasing/suppliers', Words::MENU['suppliers'], 'suppliers'],
            [$buyer, '/ui/purchasing/suppliers/' . $made['id'], 'Plain Supplies (' . $made['code'] . ')', 'supplier'],
            [$buyer, '/ui/purchasing/suppliers/' . $made['id'] . '/edit', Words::say('SUPPLIER_FORM', 'title_edit', 'Plain Supplies'), 'supplier_form'],
            [$buyer, '/ui/purchasing/suppliers/new', Words::SUPPLIER_FORM['title_new'], 'supplier_form'],
            [$buyer, '/ui/purchasing/suppliers/' . $made['id'] . '/items', Words::say('SUPPLIER_ITEMS', 'title', 'Plain Supplies'), 'supplier_items'],
            [$buyer, '/ui/purchasing/suppliers/' . $made['id'] . '/items/new?q=plain', Words::say('SUPPLIER_ITEMS', 'add_title', 'Plain Supplies'), 'supplier_item_form'],
            [$buyer, '/ui/purchasing/supplier-items/' . $si['id'], Words::say('SUPPLIER_ITEMS', 'one_title', 'Plain pod 2ml', 'Plain Supplies'), 'supplier_item'],
            [$manager, '/ui/purchasing/orders/' . $draft->id, Words::say('ORDER', 'title_draft', 'Plain Supplies'), 'order'],
            [$manager, '/ui/items/cards', Words::MENU['cards'], 'cards'],
            [$manager, '/ui/items/cards/import', Words::PAGE_TITLE['card_import'], 'card_import'],
            [$reviewer, '/ui/purchasing/orders/' . $confirmed->id, (string) $confirmed->number . ' – Plain Supplies', 'order'],
            [$reviewer, '/ui/purchasing/reorder', Words::MENU['reorder'], null],
            [$reviewer, '/ui/purchasing/orders', Words::MENU['orders'], null],
            [$desk, '/ui/purchasing/suppliers', Words::MENU['suppliers'], null],
            [$desk, '/ui/purchasing/suppliers/' . $made['id'], 'Plain Supplies (' . $made['code'] . ')', 'supplier'],
            [$stock, '/ui/items/' . $sku, 'Plain pod 2ml (' . $code($sku) . ')', 'item'],
            [$stock, '/ui/items/' . $sku . '/card', Words::say('CARD', 'form_title', 'Plain pod 2ml') . ' (' . $code($sku) . ')', 'card_form'],
            [$stock, '/ui/items/barcodes', Words::MENU['barcodes'], 'barcodes'],
            [$auditor, '/ui/items/cards', Words::MENU['cards'], null],
            [$auditor, '/ui/purchasing/supplier-items/' . $si['id'], Words::say('SUPPLIER_ITEMS', 'one_title', 'Plain pod 2ml', 'Plain Supplies'), null],
        ];
        $browsers = [];
        foreach ($pages as $n => [$user, $path, $h1, $intro]) {
            $web = $browsers[$user['id']] ??= $this->signIn($user, $this->browser('198.51.100.' . (60 + count($browsers))));
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $page = $web->get((string) parse_url($path, PHP_URL_PATH), array_map('strval', $query));
            $where = "{$path} as " . implode('+', $user['roles']) . " (#{$n})";
            self::assertSame(200, $page->status, $where . ': ' . $page->describe());
            self::check($page, $where, $h1, $intro);
        }
    }

    /** One page: its title, its intro, no codes or technical words, tables as cards. */
    private static function check(UiResponse $page, string $where, string $title, ?string $intro): void
    {
        $xp = new \DOMXPath($page->dom());
        self::assertSame($title, trim((string) preg_replace('/\s+/u', ' ', (string) $xp->evaluate('string(//main//h1)'))), "{$where}: the title");
        $lede = trim((string) $xp->evaluate('string(//main//p[@class="lede"])'));
        if ($intro !== null) {
            self::assertStringStartsWith(Words::PAGE_INTRO[$intro][0], $lede, "{$where}: the intro (plan §4)");
        } else {
            self::assertStringContainsString('You can look;', $lede, "{$where}: who changes it, for a person who can only look");
        }
        foreach ($xp->query('//main//table') ?: [] as $table) {
            /** @var \DOMElement $table */
            $inScroll = $table->parentNode instanceof \DOMElement && str_contains(' ' . $table->parentNode->getAttribute('class') . ' ', ' scroll ');
            self::assertTrue($inScroll || preg_match('/\bstack\b/', $table->getAttribute('class')) === 1, "{$where}: a table that turns into cards on a phone");
        }
        // What a person reads: codes kept on purpose (import columns, file names) are in <code> or "Technical details"; e-mails are data.
        foreach (iterator_to_array($xp->query('//main//code | //main//details[contains(@class, "tech-details")]') ?: []) as $kept) {
            $kept->parentNode?->removeChild($kept);
        }
        $text = (string) preg_replace('/\S+@\S+/', '', trim((string) preg_replace('/\s+/u', ' ', (string) $xp->query('//main')->item(0)?->textContent)));
        self::assertDoesNotMatchRegularExpression('/\b[a-z]+_[a-z_]+\b/', $text, "{$where}: a code on the screen (rule 2)");
        foreach (['UTC', 'Phase I-', 'bin/', 'docs/', 'Your role', 'Your roles', '(GBP)', '∞', 'MiB', 'preferred supply', 'item card', 'Item card', 'listing',
            'Reorder list', 'Sales history', 'awaiting approval', 'Mark as sent', 'due diligence', 'Due diligence', 'Lead days'] as $word) {
            self::assertStringNotContainsString($word, $text, "{$where}: \"{$word}\"");
        }
    }
}
