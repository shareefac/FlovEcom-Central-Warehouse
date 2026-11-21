<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Profit\OrderSnapshots;
use CW\Tests\Support\ApiKernelTestCase;

/**
 * The third final review of bc7e248 (build/A/codex_final3_astra_{a,c}.txt, c-HIGH1 / a-M1; final2/FIX_BRIEF_A4.md H3; docs/decisions.md
 * PR154), shown failing first on bc7e248: the live feed keeps the backfill's boundary. An Electrofag order placed the day before the store's
 * first reservation in CW (CW never held it: a return of it is booked through the historical path, which the profit does not match), sent by
 * PUT after go-live, is answered 200 out_of_range and never stored or queued (it was stored: a refund and its return then showed -£4); one
 * placed after the first reservation is stored. A backfill keeps it by refusing such a day file whole, unless --allow-unreserved.
 */
final class FinalReview3ApiTest extends ApiKernelTestCase
{
    /** @return array<string, mixed> */
    private static function body(string $placed, array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1763586245123, 'order_no' => '5101', 'status' => 'completed', 'placed_at' => $placed, 'paid_at' => $placed,
            'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => '9400001', 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 1200,
                'free' => false]],
            'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 1200, 'parcels' => []];
    }

    private static function held(int $channel, string $ref): int
    {
        return (int) self::$db->value('SELECT (SELECT COUNT(*) FROM order_snapshot_queue WHERE channel_id = ? AND order_ref = ?) '
            . '+ (SELECT COUNT(*) FROM order_snapshot WHERE channel_id = ? AND order_ref = ?) + (SELECT COUNT(*) FROM sale_order WHERE channel_id = ? AND order_ref = ?)',
            [$channel, $ref, $channel, $ref, $channel, $ref]);
    }

    public function testAnOrderPlacedBeforeTheStoresFirstReservationIsNotCountedByTheLiveFeed(): void
    {
        [$site, $key] = $this->apiSite('electrofag', 'live');
        $ch = (int) $site->channelId;
        // Electrofag's first reservation in CW: 16 Nov 2026 05:48 UK (= UTC).
        self::$db->exec("INSERT INTO reservation (channel_id, order_ref, status, committed_at, created_at) VALUES (?, '9300', 'committed', ?, ?)",
            [$ch, '2026-11-16 05:48:00.000000', '2026-11-16 05:48:00.000000']);
        self::assertSame('2026-11-16 05:48:00.000000', OrderSnapshots::firstReservation(self::$db, $ch));
        // Placed the day before, sent after go-live (a refund of it): taken, not counted.
        $early = self::body('2026-11-15T10:00:00Z');
        $env = self::envelope($this->call('PUT', '/v1/orders/5101/snapshot', $key, $early), 200);
        self::assertSame(['result' => 'out_of_range', 'version' => 1763586245123], $env['data'], 'a 2xx word the connector marks sent, never a retry');
        self::assertSame(0, self::held($ch, '5101'), 'never stored, never queued');
        self::assertSame(['result' => 'out_of_range', 'version' => 1763586245124],
            self::envelope($this->call('PUT', '/v1/orders/5101/snapshot', $key, self::body('2026-11-15T10:00:00Z', ['version' => 1763586245124])), 200)['data']);
        self::assertSame(0, self::held($ch, '5101'), 'again: never stored');
        // A moment before the first reservation: out of range; at or after it: stored.
        self::assertSame('out_of_range', self::envelope($this->call('PUT', '/v1/orders/5102/snapshot', $key,
            self::body('2026-11-16T05:47:59Z', ['order_no' => '5102', 'lines' => [['line_id' => '9400002'] + $early['lines'][0]]])), 200)['data']['result']);
        $late = self::body('2026-11-16T05:48:00Z', ['order_no' => '5103', 'lines' => [['line_id' => '9400003'] + $early['lines'][0]]]);
        self::assertSame(['result' => 'stored', 'version' => 1763586245123], self::envelope($this->call('PUT', '/v1/orders/5103/snapshot', $key, $late), 200)['data']);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM order_snapshot_queue WHERE channel_id = ? AND order_ref = ?', [$ch, '5103']));
        // A bad body is still a bad body (400), whatever its time.
        self::envelope($this->call('PUT', '/v1/orders/5104/snapshot', $key, self::body('2026-11-15T10:00:00Z', ['currency' => 'USD'])), 400, 'bad_snapshot');
        // The bulk path: a day file with such an order is refused whole by the importer (FinalReview2ToolsTest, PR148); --allow-unreserved
        // stores it (the only way in): the service itself stores what the importer gives it.
        $s = new OrderSnapshots(self::$db);
        self::assertSame('stored', $s->store($ch, '5106', self::body('2026-11-15T10:00:00Z', ['lines' => [['line_id' => '9400006'] + $early['lines'][0]]]), 'bulk')['result'],
            '--allow-unreserved: stored');
    }

    /** A store with no reservation in CW yet: every order is before its first one (as the backfill reads it): out_of_range, nothing stored. */
    public function testAStoreWithNoReservationYetCountsNoOrder(): void
    {
        [$site, $key] = $this->apiSite('vapebig', 'live');
        self::assertNull(OrderSnapshots::firstReservation(self::$db, (int) $site->channelId));
        self::assertSame('out_of_range', self::envelope($this->call('PUT', '/v1/orders/77001/snapshot', $key, self::body('2026-11-19T10:00:00Z')), 200)['data']['result']);
        self::assertSame(0, self::held((int) $site->channelId, '77001'));
    }
}
