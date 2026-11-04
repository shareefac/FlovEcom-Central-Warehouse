<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Config;
use CW\CwException;
use CW\Invoices\ErpApiReader;
use CW\Invoices\ErpInvoiceCopy;
use CW\Tests\Support\ErpFakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * The read-only ERPNext client of the invoice copy job and ERPNext's invoice in CW's terms (CW-917; docs/decisions.md EC1, EC3), with no
 * network and no database: the three GETs it makes (the filtered, paged list from the cursor; one invoice; the items), CW's own API user in
 * the Authorization header and nowhere else, an ERPNext refusal in words without the secret, app.env's three keys (a missing one named,
 * never a value), and normalise(): the relay's line numbers count stock rows only, VAT apart from the other charges, a deducting tax or
 * totals that do not add up flagged, and a hash that ignores the status and the names shown.
 */
final class ErpApiReaderTest extends TestCase
{
    public function testItListsPageByPageFromTheCursorWithItsOwnUserAndReadsOnlyByGet(): void
    {
        $t = new ErpFakeTransport();
        foreach (['PI-A' => '2026-10-01 09:00:00', 'PI-B' => '2026-10-02 09:00:00', 'PI-C' => '2026-10-03 09:00:00.5', 'PI-OLD' => '2026-09-30 09:00:00'] as $n => $m) {
            $t->invoices[$n] = ['name' => $n, 'company' => 'VAPE AND GO', 'docstatus' => 1, 'posting_date' => '2026-09-15', 'modified' => $m, 'items' => []];
        }
        $t->invoices['PI-OTHER'] = ['name' => 'PI-OTHER', 'company' => 'VPG 2', 'docstatus' => 1, 'posting_date' => '2026-10-01', 'modified' => '2026-10-04 09:00:00', 'items' => []];
        $t->invoices['PI-DRAFT'] = ['name' => 'PI-DRAFT', 'company' => 'VAPE AND GO', 'docstatus' => 0, 'posting_date' => '2026-10-01', 'modified' => '2026-10-04 09:00:00', 'items' => []];
        $r = new ErpApiReader($t, 'https://erp.example.test/', 'abc123key', 'secret456', 5, 2);
        self::assertSame([['name' => 'PI-A', 'modified' => '2026-10-01 09:00:00.000000'], ['name' => 'PI-B', 'modified' => '2026-10-02 09:00:00.000000'],
            ['name' => 'PI-C', 'modified' => '2026-10-03 09:00:00.500000']], $r->changed('VAPE AND GO', '2026-09-01', '2026-10-01 00:00:00.000000'));
        self::assertCount(2, $t->calls, 'pages of 2: the second page is short, so it stops');
        $first = $t->calls[0];
        self::assertStringStartsWith('https://erp.example.test/api/resource/Purchase%20Invoice?', $first['url']);
        self::assertSame([['company', '=', 'VAPE AND GO'], ['docstatus', 'in', [1, 2]], ['posting_date', '>=', '2026-09-01'], ['modified', '>=', '2026-10-01 00:00:00.000000']],
            json_decode((string) $first['query']['filters'], true));
        self::assertSame(['["name","modified"]', 'modified asc, name asc', '0', '2'], [$first['query']['fields'], $first['query']['order_by'], $first['query']['limit_start'],
            $first['query']['limit_page_length']]);
        self::assertSame(['Authorization: token abc123key:secret456', 'Accept: application/json'], $first['headers']);
        foreach ($t->calls as $c) {
            self::assertStringNotContainsString('secret456', $c['url'], 'the secret travels in the header only');
        }
        self::assertCount(1, $r->changed('VAPE AND GO', '2026-09-01', null, 1), 'at most the limit');
        self::assertSame('PI-B', $r->invoice('PI-B')['name']);
        self::assertSame('https://erp.example.test/api/resource/Purchase%20Invoice/PI-B', end($t->calls)['url']);
        $t->items = ['A' => ['is_stock_item' => 1, 'custom_variant_id' => ' 123 '], 'FREIGHT' => ['is_stock_item' => 0, 'custom_variant_id' => null]];
        self::assertSame(['A' => ['is_stock_item' => true, 'custom_variant_id' => '123'], 'FREIGHT' => ['is_stock_item' => false, 'custom_variant_id' => null]],
            $r->items(['A', 'FREIGHT', 'A', 'GONE']));
        self::assertSame(['["name","is_stock_item","custom_variant_id"]', [['name', 'in', ['A', 'FREIGHT', 'GONE']]]],
            [end($t->calls)['query']['fields'], json_decode((string) end($t->calls)['query']['filters'], true)]);
        self::assertFalse(method_exists($t, 'post') || (new \ReflectionClass(\CW\Invoices\ErpTransport::class))->hasMethod('post'), 'the transport has a GET and nothing else');
    }

    public function testARefusalIsSaidInWordsWithoutTheSecretAndTheSecretIsNeverShown(): void
    {
        $t = new ErpFakeTransport();
        $t->status = 403;
        $r = new ErpApiReader($t, 'https://erp.example.test', 'abc123key', 'secret456');
        try {
            $r->changed('VAPE AND GO', '2026-09-01', null);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame(['erp_read_failed', 'ERPNext answered HTTP 403 (PermissionError): check the read-only user and its rights'], [$e->errorCode, $e->getMessage()]);
        }
        $t->status = null;
        $this->expectExceptionObjectLike('erp_read_failed', fn () => $r->invoice('NOPE'));
        ob_start();
        var_dump($r);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString('secret456', $dump);
        self::assertStringNotContainsString('secret456', print_r($r, true));
        $this->expectExceptionObjectLike('erp_read_bad_url', static fn () => new ErpApiReader($t, 'http://erp.example.test', 'abc123key', 'secret456'));
        $this->expectExceptionObjectLike('erp_read_bad_url', static fn () => new ErpApiReader($t, 'https://user:pw@erp.example.test', 'abc123key', 'secret456'));
        $this->expectExceptionObjectLike('erp_read_bad_key', static fn () => new ErpApiReader($t, 'https://erp.example.test', 'abc 123', 'secret456'));
    }

    public function testTheThreeKeysComeFromAppEnvAndAMissingOneIsNamedNeverShown(): void
    {
        $ok = ErpApiReader::fromConfig(Config::fromArrays([], ['ERP_READ_URL' => 'https://erp.example.test', 'erp_read_key' => 'abc123key', 'erp_read_secret' => 'secret456']),
            new ErpFakeTransport());
        self::assertSame(['base' => 'https://erp.example.test', 'auth' => '(hidden)'], $ok->__debugInfo());
        try {
            ErpApiReader::fromConfig(Config::fromArrays([], ['erp_read_url' => 'https://erp.example.test', 'erp_read_secret' => 'secret456']), new ErpFakeTransport());
            self::fail('a missing key refuses');
        } catch (CwException $e) {
            self::assertSame(['erp_read_unconfigured', 503, ['erp_read_key']], [$e->errorCode, $e->httpStatus, $e->detail['missing']]);
            self::assertStringNotContainsString('secret456', $e->getMessage());
            self::assertStringNotContainsString('erp.example.test', $e->getMessage());
        }
    }

    public function testNormaliseCountsStockRowsSplitsTheVatAndHashesWhatACopyIsMadeOf(): void
    {
        $doc = ['name' => 'PI-1', 'company' => 'VAPE AND GO', 'supplier' => 'S1', 'supplier_name' => 'Supplier One', 'bill_no' => ' B1 ', 'bill_date' => '2026-10-01',
            'posting_date' => '2026-10-02', 'docstatus' => 1, 'modified' => '2026-10-02 10:00:00', 'base_net_total' => 37.5, 'base_grand_total' => 50.0,
            'items' => [
                ['idx' => 2, 'item_code' => 'FREIGHT', 'item_name' => 'Carriage', 'qty' => 1, 'stock_qty' => 1, 'conversion_factor' => 1, 'base_net_rate' => 7.5, 'base_net_amount' => 7.5],
                ['idx' => 1, 'item_code' => 'A', 'item_name' => 'Liquid A', 'qty' => 2, 'stock_qty' => 24, 'conversion_factor' => 12, 'uom' => 'Box', 'base_net_rate' => 10.004,
                    'base_net_amount' => 20.01, 'name' => 'row-a'],
                ['idx' => 3, 'item_code' => 'B', 'qty' => 1, 'stock_qty' => 1, 'base_net_rate' => 10, 'base_net_amount' => 10],
            ],
            'taxes' => [['account_head' => 'VAT - VPG', 'description' => 'VAT 20%', 'category' => 'Total', 'add_deduct_tax' => 'Add', 'base_tax_amount_after_discount_amount' => 7.5],
                ['account_head' => 'Freight and Forwarding Charges - VPG', 'description' => 'Delivery', 'category' => 'Valuation and Total', 'add_deduct_tax' => 'Add',
                    'base_tax_amount_after_discount_amount' => 5],
                ['account_head' => 'Insurance - VPG', 'description' => 'Insurance', 'category' => 'Valuation', 'add_deduct_tax' => 'Add', 'base_tax_amount_after_discount_amount' => 2]]];
        $items = ['A' => ['is_stock_item' => true, 'custom_variant_id' => '501'], 'B' => ['is_stock_item' => true, 'custom_variant_id' => null],
            'FREIGHT' => ['is_stock_item' => false, 'custom_variant_id' => null]];
        $n = ErpInvoiceCopy::normalise($doc, $items);
        self::assertSame([[1, 'A', 1, 0, '501'], [2, 'FREIGHT', 0, null, null], [3, 'B', 1, 1, null]],
            array_map(static fn (array $l): array => [$l['idx'], $l['item_code'], $l['is_stock'], $l['stock_index'], $l['variant_id']], $n['lines']),
            'rows in ERPNext\'s order; the relay\'s line numbers count the stock rows only');
        self::assertSame(['2.000000', '24.000000', '12.000000000', '10.004000', '20.01', 'row-a'], [$n['lines'][0]['qty'], $n['lines'][0]['stock_qty'],
            $n['lines'][0]['conversion_factor'], $n['lines'][0]['net_rate'], $n['lines'][0]['net_amount'], $n['lines'][0]['row_name']]);
        self::assertSame(['B1', '37.50', '7.50', '50.00', [['description' => 'Delivery', 'amount' => '5.00']], null], [$n['bill_no'], $n['net_total'], $n['vat_total'],
            $n['grand_total'], $n['charges'], $n['taxes_problem']], 'VAT apart; a charge to the total is a line; a charge to the value only is not');
        $same = ErpInvoiceCopy::normalise(['docstatus' => 2, 'modified' => '2026-11-01 10:00:00', 'supplier_name' => 'Renamed'] + $doc, $items);
        self::assertSame($n['hash'], $same['hash'], 'the status, the time and the names shown are not what a copy is made of');
        self::assertSame(2, $same['docstatus']);
        $other = $doc;
        $other['items'][1]['base_net_rate'] = 10.01;
        self::assertNotSame($n['hash'], ErpInvoiceCopy::normalise($other, $items)['hash']);
        $deduct = $doc;
        $deduct['taxes'][1]['add_deduct_tax'] = 'Deduct';
        self::assertSame('a tax row deducts', ErpInvoiceCopy::normalise($deduct, $items)['taxes_problem']);
        $off = ['base_grand_total' => 60.0] + $doc;
        self::assertSame('the rows, the VAT and the charges do not add up to its total', ErpInvoiceCopy::normalise($off, $items)['taxes_problem']);
        self::assertSame(['1.01', '-1.01', '0.00', '12.3457', '3.00'], [ErpInvoiceCopy::dec(1.005, 2), ErpInvoiceCopy::dec(-1.005, 2), ErpInvoiceCopy::dec(-0.001, 2),
            ErpInvoiceCopy::dec('12.34565', 4), ErpInvoiceCopy::dec(3, 2)]);
    }

    public function testTheLogLinesOfARun(): void
    {
        $r = ['dry_run' => false, 'read' => 3, 'counts' => ['copied' => 1, 'waiting' => 1, 'skipped' => 1], 'problems' => ['no_item' => 1, 'before_start' => 1],
            'suppliers_created' => 1, 'prices' => 2, 'retried' => 0, 'relay_rechecked' => 0, 'from' => '2026-09-01', 'cursor_from' => null,
            'cursor_to' => '2026-10-02 10:00:00.000000', 'stopped' => false, 'outcomes' => [
                ['name' => 'PI-1', 'outcome' => 'copied', 'problem' => null],
                ['name' => 'PI-2', 'outcome' => 'waiting', 'problem' => 'no_item', 'line' => 2, 'detail' => 'X'],
                ['name' => 'PI-3', 'outcome' => 'skipped', 'problem' => 'before_start', 'line' => null, 'detail' => '2026-08-01']]];
        self::assertSame(['read=3 copied=1 waiting=1 reversed=0 skipped=1 unchanged=0 suppliers_created=1 prices=2 retried=0 relay_rechecked=0 from=2026-09-01 '
            . 'cursor=start->2026-10-02 10:00:00.000000 problems=no_item:1,before_start:1', '  PI-2: waiting (no_item, row 2: X)', '  PI-3: skipped (before_start: 2026-08-01)'],
            ErpInvoiceCopy::logLines($r));
    }

    private function expectExceptionObjectLike(string $code, callable $fn): void
    {
        try {
            $fn();
        } catch (CwException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
            return;
        }
        self::fail("expected {$code}");
    }
}
