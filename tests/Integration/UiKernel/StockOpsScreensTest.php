<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\StockOps\StockOps;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The stock records' screens (pack A1; docs/decisions.md SO10-SO11) through the real /ui kernel as cw_app, with the production document
 * types (the real ADJ): the Stock tabs; each kind's list, its create form (one draft however often it is sent), its record page (add a
 * product by CW number, change the lines, make it final, cancel it); a stock out's "given to"; the transfer note and release invoice
 * PDFs; the balance owed with a payment and its reversal; who may open and post what, and the CSRF token on every POST.
 */
final class StockOpsScreensTest extends KernelUiTestCase
{
    protected function handlers(Db $db): array
    {
        return DocumentHandlers::all($db);
    }

    private function vpg2(): int
    {
        return (int) self::$db->insert("INSERT INTO warehouse (code, name, is_sellable, is_active, stock_owner, owner_entity, sort_order) VALUES ('VPGTWO', 'VPG 2 room', 0, 1, 'other', 'VPG Two Ltd', 40)");
    }

    /** Starts a record of $kind through its create form (its fields as drawn, with $over), and returns its page's path. @param array<string, string> $over */
    private function start(\CW\Tests\Support\KernelBrowser $web, string $kind, array $over = []): string
    {
        $list = $web->get(StockOps::PATHS[$kind]);
        self::assertSame(200, $list->status, $list->describe());
        $form = $list->form(StockOps::PATHS[$kind], true);
        self::assertArrayHasKey('form_key', $form, 'the create form is there for someone who keeps records');
        $r = $web->post(StockOps::PATHS[$kind], $over + $form);
        self::assertSame(303, $r->status, $r->describe());
        $again = $web->post(StockOps::PATHS[$kind], $over + $form);
        self::assertSame($r->location(), $again->location(), 'one draft however often the form is sent');
        return (string) parse_url((string) $r->location(), PHP_URL_PATH);
    }

    /** Posts a record page's form (its fields as drawn, with $over). @param array<string, string> $over */
    private function submit(\CW\Tests\Support\KernelBrowser $web, string $page, string $action, array $over = []): UiResponse
    {
        $p = $web->get($page);
        self::assertSame(200, $p->status, $p->describe() . ' ' . implode(' | ', self::$log));
        $form = $p->form($page . '/' . $action, true);
        self::assertNotSame([], $form, "no {$action} form: " . $p->describe());
        return $web->post($page . '/' . $action, $over + $form);
    }

    public function testTheStockTabsAndTheirLists(): void
    {
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get('/ui/stock/in');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame([['Overview', '/ui/stock'], ['Movements', '/ui/stock/movements'], ['Stock In', '/ui/stock/in'], ['Stock Out', '/ui/stock/out'],
            ['Transfers', '/ui/stock/transfers'], ['Adjustments', '/ui/stock/adjustments'], ['Counts', null], ['Reservations', null], ['Quality', null]],
            array_map(static fn (array $t): array => [$t['label'], $t['href']], self::sectionTabs($page)));
        self::assertSame(['Stock', 'Stock In'], [self::currentSection($page), self::currentTab($page)]);
        self::assertStringContainsString(Words::STOCK_KIND['in']['none'], $page->text());
        foreach (['/ui/stock/out', '/ui/stock/adjustments', '/ui/stock/transfers', '/ui/stock/releases', '/ui/stock/accounts'] as $path) {
            self::assertSame(200, $web->get($path)->status, $path);
        }
        self::assertSame(['Transfers', 'Releases', 'Balance owed'], array_column(self::segments($web->get('/ui/stock/releases')), 'label'));
        // A viewer sees the stock but not the records; a buyer reads them but keeps none.
        $viewer = $this->signIn($this->uiUser('viewer'));
        self::assertSame(403, $viewer->get('/ui/stock/in')->status);
        $buyer = $this->signIn($this->uiUser('buyer'));
        $b = $buyer->get('/ui/stock/in');
        self::assertSame(200, $b->status);
        self::assertFalse($b->hasForm('/ui/stock/in'), 'no create form for someone who does not keep stock records');
        self::assertStringContainsString(Words::intro('stock_in', Words::whoCan('doc.SIN.post')), $b->text());
    }

    public function testAStockInIsStartedFilledInAndMadeFinalOnItsPage(): void
    {
        $sku = $this->item('strict', 4, 'Gamma pod 2ml');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $this->start($web, 'in', ['reason_code' => 'found', 'external_ref' => 'shelf C']);
        $r = $this->submit($web, $page, 'add', ['q' => 'CW-' . $sku, 'qty' => '6', 'cost' => '1.50']);
        self::assertSame(303, $r->status, $r->describe());
        $added = $web->follow($r);
        self::assertStringContainsString(Words::say('STOCK_NOTICE', 'added', 'Gamma pod 2ml', 6, 1), $added->text());
        $r = $this->submit($web, $page, 'lines', ['u_1' => '7']);
        self::assertSame(303, $r->status, $r->describe());
        $r = $this->submit($web, $page, 'post');
        self::assertSame(303, $r->status, $r->describe());
        $done = $web->follow($r);
        self::assertStringContainsString(Words::say('STOCK_NOTICE', 'posted', 'SIN-000001'), $done->text());
        self::assertSame(11, (int) self::$db->value("SELECT on_hand FROM stock_balance WHERE sku_id = ? AND warehouse_id = (SELECT id FROM warehouse WHERE code = 'MAIN')", [$sku]));
        // The list shows it as final; the movements and the product page say which record and why.
        $list = $web->get('/ui/stock/in');
        self::assertStringContainsString('SIN-000001', $list->text());
        $moves = $web->get('/ui/stock/movements');
        self::assertStringContainsString(Words::STOCK_KIND['in']['one'], $moves->text());
        self::assertStringContainsString('Found (no supplier document)', $moves->text());
        self::assertStringContainsString('Found (no supplier document)', $web->get('/ui/items/' . $sku)->text());
        // Cancelled by a new record that takes the units off again.
        $r = $this->submit($web, $page, 'reverse', ['reason_code' => 'entered_in_error']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('STOCK_NOTICE', 'reversed', 'SIN-000002'), $web->follow($r)->text());
        self::assertSame(4, (int) self::$db->value("SELECT on_hand FROM stock_balance WHERE sku_id = ? AND warehouse_id = (SELECT id FROM warehouse WHERE code = 'MAIN')", [$sku]));
        // The CSV of the list.
        $csv = $web->get('/ui/stock/in.csv');
        self::assertSame(200, $csv->status);
        self::assertStringContainsString('SIN-000001', $csv->body);
    }

    public function testAStockOutRefusesASampleWithoutGivenToInWords(): void
    {
        $sku = $this->item('strict', 4, 'Delta liquid');
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $page = $this->start($web, 'out', ['reason_code' => 'sample']);
        $this->submit($web, $page, 'add', ['q' => (string) $sku, 'qty' => '2']);
        $r = $this->submit($web, $page, 'post');
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::STOCK_ERROR['given_to_required'], $r->text());
        $r = $this->submit($web, $page, 'details', ['given_to' => 'Sam at the shop']);
        self::assertSame(303, $r->status, $r->describe());
        $r = $this->submit($web, $page, 'post');
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString('Sam at the shop', $web->get('/ui/stock/movements')->text(), 'the movement names who it was given to');
        // Too many: never below zero for a protected product.
        $page2 = $this->start($web, 'out', ['reason_code' => 'trade_sale']);
        $this->submit($web, $page2, 'add', ['q' => (string) $sku, 'qty' => '5']);
        $r = $this->submit($web, $page2, 'post');
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::say('STOCK_ERROR', 'below_zero', 1, (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]), 2, 5), $r->text());
    }

    public function testATransferNoteAndAReleaseInvoiceAndTheBalanceOwed(): void
    {
        $vpg2 = $this->vpg2();
        $sku = $this->item('strict', 0, 'Epsilon kit');
        $sc = $this->signIn($this->uiUser('stock_controller'));
        // The room's stock is recorded with a stock in.
        $in = $this->start($sc, 'in', ['warehouse' => (string) $vpg2, 'reason_code' => 'opening_stock']);
        $this->submit($sc, $in, 'add', ['q' => (string) $sku, 'qty' => '30']);
        self::assertSame(303, $this->submit($sc, $in, 'post')->status);
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $rel = $this->start($desk, 'release', ['warehouse' => (string) $vpg2, 'external_ref' => 'VT-101']);
        $r = $this->submit($desk, $rel, 'add', ['q' => (string) $sku, 'qty' => '10', 'cost' => '2.25']);
        self::assertSame(303, $r->status, $r->describe());
        $r = $this->submit($desk, $rel, 'post');
        self::assertSame(303, $r->status, $r->describe());
        $pdf = $desk->get($rel . '/pdf');
        self::assertSame('application/pdf', $pdf->header('content-type'));
        self::assertStringStartsWith('%PDF-1.', $pdf->body);

        $acc = $desk->get('/ui/stock/accounts');
        self::assertSame(200, $acc->status, $acc->describe());
        self::assertStringContainsString('VPG Two Ltd', $acc->text());
        self::assertStringContainsString('£22.50', $acc->text(), 'the balance owed: 10 x 2.25');
        $form = $acc->form('/ui/stock/accounts/' . $vpg2 . '/payments');
        $r = $desk->post('/ui/stock/accounts/' . $vpg2 . '/payments', ['amount' => '30', 'reference' => 'BACS 9'] + $form);
        self::assertSame(409, $r->status, 'more than owed');
        self::assertStringContainsString(Words::say('ACCOUNT_ERROR', 'more_than_owed', '£22.50'), $r->text());
        $r = $desk->post('/ui/stock/accounts/' . $vpg2 . '/payments', ['amount' => '20.00', 'reference' => 'BACS 9'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('ACCOUNTS', 'paid', '£20.00', '£2.50'), $desk->follow($r)->text());
        $entry = (int) self::$db->value("SELECT id FROM other_account_entry WHERE kind = 'payment'");
        $r = $desk->post('/ui/stock/accounts/payments/' . $entry . '/reverse', ['csrf' => $this->token($desk), 'reason' => 'wrong account']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('22.50', $this->balance($vpg2));
        // A stock controller looks but records no payment.
        self::assertSame(403, $sc->post('/ui/stock/accounts/' . $vpg2 . '/payments', ['csrf' => $this->token($sc), 'amount' => '1', 'paid_on' => gmdate('Y-m-d'),
            'reference' => 'x'])->status);

        // A transfer note.
        self::$db->exec("INSERT INTO warehouse (code, name, is_sellable, is_active, stock_owner, sort_order) VALUES ('SHOP', 'Shop room', 0, 1, 'own', 45)");
        $shop = (int) self::$db->value("SELECT id FROM warehouse WHERE code = 'SHOP'");
        $t = $this->start($sc, 'transfer', ['to_warehouse' => (string) $shop]);
        $this->submit($sc, $t, 'add', ['q' => (string) $sku, 'qty' => '3']);
        self::assertSame(303, $this->submit($sc, $t, 'post')->status);
        $note = $sc->get($t . '/pdf');
        self::assertSame('application/pdf', $note->header('content-type'));
    }

    public function testEveryPostNeedsItsJobAndTheCsrfToken(): void
    {
        $sku = $this->item('strict', 4);
        $sc = $this->signIn($this->uiUser('stock_controller'));
        $page = $this->start($sc, 'adjust');
        $id = (int) basename($page);
        $buyer = $this->signIn($this->uiUser('buyer'));
        $admin = $this->signIn($this->uiUser('admin'));
        foreach (['details', 'lines', 'add', 'post', 'cancel', 'reverse', 'withdraw'] as $action) {
            self::assertSame(403, $buyer->post($page . '/' . $action, ['csrf' => $this->token($buyer), 'version' => '1'])->status, "buyer {$action}");
            self::assertSame(403, $admin->post($page . '/' . $action, ['csrf' => $this->token($admin), 'version' => '1'])->status, "admin {$action}");
            self::assertSame(403, $sc->post($page . '/' . $action, ['version' => '1'])->status, "no token {$action}");
        }
        foreach (StockOps::PATHS as $kind => $base) {
            self::assertSame(403, $buyer->post($base, ['csrf' => $this->token($buyer), 'form_key' => str_repeat('a', 32)])->status, "buyer creates {$kind}");
            self::assertSame(403, $sc->post($base, ['form_key' => str_repeat('b', 32)])->status, "no token {$kind}");
        }
        self::assertSame(403, $sc->post('/ui/stock/accounts/1/payments', ['amount' => '1'])->status, 'no token, payment');
        // A record of another kind is not found under this kind's path.
        self::assertSame(404, $sc->get('/ui/stock/in/' . $id)->status);
        // The adjustment needs a reason on each line.
        $this->submit($sc, $page, 'add', ['q' => (string) $sku, 'qty' => '-1', 'reason' => 'damaged']);
        $r = $this->submit($sc, $page, 'post');
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(3, (int) self::$db->value('SELECT on_hand FROM stock_balance WHERE sku_id = ? AND warehouse_id = 1', [$sku]));
        self::assertSame('write_off', self::$db->value('SELECT movement_type FROM stock_ledger WHERE document_id = ?', [$id]));
    }

    private function balance(int $warehouse): string
    {
        return (string) self::$db->value('SELECT COALESCE(SUM(amount), 0) FROM other_account_entry WHERE warehouse_id = ?', [$warehouse]);
    }
}
