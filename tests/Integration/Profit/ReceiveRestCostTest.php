<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Clock;
use CW\Profit\CostEngine;
use CW\Tests\Integration\Arrivals\ArrivalsTestCase;
use CW\Tests\Integration\Arrivals\RestFixtures;

/**
 * CW-1155 "Receive the rest" and the buying price (docs/decisions.md RR14; the lead's decision 7): a receipt of the rest of an invoice is
 * priced by the invoice line its rows name (written by its posting, never changed), never by the n-th line of the item: one item on two
 * lines at two prices, only the second short, the rest comes at the second's price; each part is its own layer at that line's landed price
 * (£1.00 in the real case); taken back, it leaves at that price.
 */
final class ReceiveRestCostTest extends ArrivalsTestCase
{
    use RestFixtures;

    private function engine(int $ahead = 0): CostEngine
    {
        return new CostEngine(self::$db, static fn (): \DateTimeImmutable => Clock::now()->modify('+' . (CostEngine::LAG_SECONDS + 60 + $ahead) . ' seconds'));
    }

    private static function cost(int $sku): ?int
    {
        $v = self::$db->value('SELECT unit_cost_e4 FROM item_cost WHERE sku_id = ?', [$sku]);
        return $v === null ? null : (int) $v;
    }

    private function start(): void
    {
        $plan = $this->engine()->plan("item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n", 'vapeandgo');
        $this->engine()->seed($plan['prices'], Clock::db(Clock::now()->modify('-1 hour')));
    }

    /** @return list<array{0: string, 1: int, 2: int, 3: ?int, 4: ?int}> kind, units, price, ref document, ref line of a receipt's cost events */
    private static function events(int $grnId): array
    {
        return array_map(static fn (array $e): array => [(string) $e['kind'], (int) $e['units'], (int) $e['price_e4'], $e['ref_document_id'] === null ? null
            : (int) $e['ref_document_id'], $e['ref_line'] === null ? null : (int) $e['ref_line']],
            self::$db->all('SELECT kind, units, price_e4, ref_document_id, ref_line FROM item_cost_event WHERE document_id = ? ORDER BY id', [$grnId]));
    }

    public function testOneItemOnTwoLinesAtTwoPricesTheRestTakesTheOwedLinesPrice(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Two Prices Ltd');
        $sku = self::makeSku('Two prices item');
        $this->start();
        $p = $this->finalInvoice($who['desk'], (int) $who['supplier']['id'], [[$sku, 4, 1, '2.0000'], [$sku, 3, 1, '5.0000']]);
        $g = $this->firstReceipt($who['desk'], $p->id, [2 => ['short_units' => '3']]);
        $this->engine()->run();
        self::assertSame([['receipt', 4, 20000, $p->id, 1]], self::events($g->id), 'the four of line 1 at £2');
        self::assertSame([['line' => 2, 'units' => 3, 'amount' => '15.00']], $this->owed($p->id));
        $r = $this->restReceipt($who['desk'], $p->id);
        $this->engine()->run();
        self::assertSame([['receipt', 3, 50000, $p->id, 2]], self::events($r->id), 'the three of line 2 at £5 (the n-th line of the item gave £2)');
        self::assertSame(32857, self::cost($sku), '(4 x £2 + 3 x £5) / 7');
        self::assertSame(32857, $this->engine(60)->rebuild($sku), 'the same written again');
        $rev = $this->docs->reverse($who['desk'], $r->id, 'entered_in_error', null);
        $this->engine()->run();
        self::assertSame([['receipt_reversal', 3, 50000, $r->id, 1]], array_map(static fn (array $e): array => [$e[0], $e[1], $e[2], $e[3], $e[4]], self::events($rev->id)),
            'taken back at the price it came in at');
        self::assertSame(20000, self::cost($sku));
    }

    /** The real case: 6 on the shelf, line 19 (5 at £1.00) comes 3 then 2: each part its own layer at £1.00. */
    public function testTheRealCaseRestComesAtTheInvoicePrice(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Real Price Ltd');
        $sku = self::makeSku('Real case liquid');
        $other = self::makeSku('Real case other');
        $this->book('goods_in', $sku, 6, 'MAIN', null, '1.000000');
        $this->start();
        $p = $this->finalInvoice($who['desk'], (int) $who['supplier']['id'], [[$other, 2, 1, '19.0000'], [$sku, 5, 1, '1.0000']]);
        $this->firstReceipt($who['desk'], $p->id, [2 => ['short_units' => '5']]);
        $r1 = $this->restReceipt($who['desk'], $p->id, [1 => ['short_units' => '2']]);
        $r2 = $this->restReceipt($who['desk'], $p->id);
        $this->engine()->run();
        self::assertSame([['receipt', 3, 10000, $p->id, 2]], self::events($r1->id));
        self::assertSame([['receipt', 2, 10000, $p->id, 2]], self::events($r2->id));
        self::assertSame([11, 'matched'], [$this->onHand($sku), $this->stored($p->id)['match_state']]);
    }
}
