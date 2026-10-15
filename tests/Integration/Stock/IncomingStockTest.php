<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Db;
use CW\Invariants;
use CW\Tests\Support\Documents\FixtureDocuments;
use CW\Tests\Support\StockTestCase;

/**
 * Pack J (docs/decisions.md PJ1-PJ3): the `incoming` bucket of a balance. Stock.php stays the only writer: an invoice posted with
 * "count on arrival" books its units into incoming (movement `incoming`), the receipt against it takes them out again (`arrive`),
 * every change journalled. available = on_hand + incoming - allocated - held, so the sites see the units the moment the invoice is
 * final (the change feed), a sale may reserve them, and a receipt that comes in short lowers availability at once (an oversell is
 * flagged as for any other movement). incoming never goes below zero; the nightly checks add it up from the ledger.
 */
final class IncomingStockTest extends StockTestCase
{
    /** Fixture documents (posted, numbered; their kind does not matter to the stock writer). */
    private const DOC = ['document_id' => 9101, 'doc_ref' => 'ADJ-000001'];
    private const RECEIPT = ['document_id' => 9102, 'doc_ref' => 'ADJ-000002'];

    /**
     * The nightly checks of the stock core. The fixture documents here are not invoices, so they have no arrival rows: pack J's J1
     * and J4 (incoming against the invoices and receipts) are left to tests/Integration/Arrivals/ArrivalsTest.php.
     *
     * @return list<string>
     */
    private static function coreInvariants(): array
    {
        return array_values(array_filter(Invariants::check(self::$db), static fn (string $v): bool => !str_starts_with($v, 'J1 ') && !str_starts_with($v, 'J4 ')));
    }

    protected function assertPostConditions(): void
    {
        self::assertSame([], self::coreInvariants(), 'invariants violated');
    }

    private function inTx(callable $fn): mixed
    {
        return self::$db->transaction(static fn (Db $db): mixed => $fn($db));
    }

    /** @param list<array<string, mixed>> $moves */
    private function bookDoc(array $doc, array $moves): void
    {
        $this->inTx(fn (): array => $this->moves->bookForDocument(self::staff(), $doc, 'doc:' . $doc['document_id'] . ':post', $moves));
    }

    private function incoming(int $sku, string $warehouse = 'MAIN'): int
    {
        return (int) (self::$db->value('SELECT incoming FROM stock_balance WHERE warehouse_id = ? AND sku_id = ?', [self::warehouseId($warehouse), $sku]) ?? 0);
    }

    public function testIncomingUnitsAreSoldOnTheSitesAndJournalled(): void
    {
        FixtureDocuments::posted(self::$db, 9101, 'ADJ', 1);
        $site = $this->site();
        $sku = $this->item('strict', 0);
        $this->listing($site, 'V1', $sku);
        $seq = (int) self::$db->value('SELECT COALESCE(MAX(seq), 0) FROM stock_change');
        self::assertSame(0, $this->view($site, 'V1')['available']);

        $this->bookDoc(self::DOC, [['type' => 'incoming', 'warehouse' => 'MAIN', 'lines' => [['document_line' => 1, 'sku_id' => $sku, 'qty' => 5]]]]);
        self::assertSame([5, 0], [$this->incoming($sku), $this->bal($sku)['on_hand']]);
        self::assertSame(5, $this->view($site, 'V1')['available'], 'the sites see the units on the invoice');
        self::assertSame(['incoming', 'incoming', 5, 5, 9101, 1], array_values(array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v, (array) self::$db->one(
            'SELECT bucket, movement_type, qty_delta, balance_after, document_id, document_line FROM stock_ledger WHERE document_id = 9101'))));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM stock_change WHERE seq > ? AND sku_id = ?', [$seq, $sku]), 'the change feed tells the sites');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_value_seq s JOIN stock_ledger l ON l.id = s.stock_ledger_id WHERE l.document_id = 9101'),
            'not on hand: no value sequence (IM8 values what is in the building)');

        // A sale reserves them (a strict item: refused when short).
        $this->ok($this->reserve($site, 'O1', [self::line('V1', 'u1', 'u2', 'u3')]));
        self::assertSame(2, $this->view($site, 'V1')['available']);
        $short = $this->reserve($site, 'O2', [self::line('V1', 'u4', 'u5', 'u6')]);
        self::assertSame(409, $short->status, json_encode($short->body));
        self::assertSame([], self::coreInvariants());
    }

    public function testTheReceiptTakesThemOutAndAShortReceiptIsAnOversell(): void
    {
        FixtureDocuments::posted(self::$db, 9101, 'ADJ', 1);
        FixtureDocuments::posted(self::$db, 9102, 'ADJ', 2);
        $site = $this->site();
        $sku = $this->item('strict', 0);
        $this->listing($site, 'V1', $sku);
        $this->bookDoc(self::DOC, [['type' => 'incoming', 'warehouse' => 'MAIN', 'lines' => [['document_line' => 1, 'sku_id' => $sku, 'qty' => 5]]]]);
        $this->ok($this->commit($site, 'O1', [self::line('V1', 'u1', 'u2', 'u3', 'u4', 'u5')]));
        $this->assertBal(0, 5, 0, $sku);
        self::assertSame(0, $this->view($site, 'V1')['available'], 'every unit on the invoice is sold');

        // Three of the five arrive: on hand +3, incoming -5. Available falls to -2: the sold units that will not come are flagged.
        $this->bookDoc(self::RECEIPT, [
            ['type' => 'goods_in', 'warehouse' => 'MAIN', 'lines' => [['document_line' => 1, 'sku_id' => $sku, 'qty' => 3]]],
            ['type' => 'arrive', 'warehouse' => 'MAIN', 'lines' => [['document_line' => 1, 'sku_id' => $sku, 'qty' => 5]]],
        ]);
        $this->assertBal(3, 5, 0, $sku);
        self::assertSame([0, -2], [$this->incoming($sku), $this->view($site, 'V1')['available']]);
        $e = self::$db->one("SELECT kind, shortfall, available_after FROM oversell_event WHERE sku_id = ? ORDER BY id DESC LIMIT 1", [$sku]);
        self::assertSame(['movement_short', 2, -2], [$e['kind'] ?? null, (int) ($e['shortfall'] ?? 0), (int) ($e['available_after'] ?? 0)]);

        // The receipt's reversal puts the units back to incoming (they are expected again) and takes the 3 off the shelf.
        $ref = FixtureDocuments::posted(self::$db, 9103, 'ADJ', 3, 9102);
        $this->inTx(fn (): int => $this->moves->reverseDocument(self::staff(), 9102, ['document_id' => 9103, 'doc_ref' => $ref], 'doc:9103:reverse'));
        $this->assertBal(0, 5, 0, $sku);
        self::assertSame(5, $this->incoming($sku));
    }

    public function testIncomingNeverGoesBelowZeroAndTheChecksAddItUp(): void
    {
        FixtureDocuments::posted(self::$db, 9101, 'ADJ', 1);
        FixtureDocuments::posted(self::$db, 9102, 'ADJ', 2);
        $sku = $this->item('strict', 0);
        $this->bookDoc(self::DOC, [['type' => 'incoming', 'warehouse' => 'MAIN', 'lines' => [['document_line' => 1, 'sku_id' => $sku, 'qty' => 2]]]]);
        try {
            $this->bookDoc(self::RECEIPT, [['type' => 'arrive', 'warehouse' => 'MAIN', 'lines' => [['document_line' => 1, 'sku_id' => $sku, 'qty' => 3]]]]);
            self::fail('incoming went below zero');
        } catch (\LogicException $e) {
            self::assertStringContainsString('incoming', $e->getMessage());
        }
        self::assertSame(2, $this->incoming($sku), 'nothing booked');
        // A site may never send them (documents only), nor carry a cost.
        try {
            $this->moves->record(self::staff(), ['type' => 'incoming', 'warehouse' => 'MAIN', 'doc_ref' => 'X-1', 'lines' => [['sku_id' => $sku, 'qty' => 1]]],
                $this->key('move'));
            self::fail('a movement outside a document moved incoming');
        } catch (\CW\CwException $e) {
            self::assertSame([400, 'bad_type'], [$e->httpStatus, $e->errorCode]);
        }

        // The nightly check adds incoming up from the ledger, like every other bucket.
        self::$db->exec('UPDATE stock_balance SET incoming = incoming + 1 WHERE warehouse_id = ? AND sku_id = ?', [self::warehouseId('MAIN'), $sku]);
        $v = implode("\n", self::coreInvariants());
        self::$db->exec('UPDATE stock_balance SET incoming = incoming - 1 WHERE warehouse_id = ? AND sku_id = ?', [self::warehouseId('MAIN'), $sku]);
        self::assertStringContainsString('incoming=3 but its ledger sums to 2', $v);
    }
}
