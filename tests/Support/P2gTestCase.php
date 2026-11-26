<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Caller;
use CW\CwException;
use CW\Profit\DeliveryBills;
use CW\Profit\DeliveryInvariants;
use CW\Profit\OrderSnapshots;
use CW\Profit\P2gApi;
use CW\Profit\P2gInvoice;
use CW\Profit\Parcel2Go;
use CW\Profit\ProfitDays;
use CW\Invariants;

/**
 * The Parcel2Go money tests' base (CW-1160, docs/decisions.md PGL1-PGL12): three shops with their first reservations (Vape and Go 28 Oct, Vape Big
 * and Electrofag 17 Nov), the two accounts (off), a clock, orders with parcels, one run of the job with Parcel2Go's answers scripted (never a
 * request to Parcel2Go), where the money is, and the rebuild + delivery checks.
 */
abstract class P2gTestCase extends StockTestCase
{
    protected int $vpg;
    protected int $vb;
    protected int $ef;
    protected string $clockAt = '2026-11-25 12:00:00';
    /** @var list<string> the start of invariant lines a test makes ON PURPOSE (and asserts itself): every other line still fails it */
    protected array $expectedInvariants = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->vpg = (int) $this->site('vapeandgo', 'live')->channelId;
        $this->vb = (int) $this->site('vapebig', 'live')->channelId;
        $this->ef = (int) $this->site('electrofag', 'live')->channelId;
        foreach ([[$this->vpg, '2026-10-28 10:00:00'], [$this->vb, '2026-11-17 09:00:00'], [$this->ef, '2026-11-17 09:00:00']] as [$ch, $at]) {
            self::$db->exec("INSERT INTO reservation (channel_id, order_ref, status, committed_at, created_at) VALUES (?, '9000000', 'committed', ?, ?)", [$ch, $at, $at]);
        }
        P2gFixtures::accounts(self::$db, $this->vpg, $this->vb, $this->ef);
    }

    /** The nightly invariant check after every test (StockTestCase), but for the lines the test made on purpose. */
    protected function assertPostConditions(): void
    {
        $left = array_values(array_filter(Invariants::check(self::$db), fn (string $l): bool => array_filter($this->expectedInvariants,
            static fn (string $p): bool => str_starts_with($l, $p)) === []));
        self::assertSame([], $left, 'invariants violated');
    }

    protected static function sys(): Caller
    {
        return Caller::system('p2g_test');
    }

    protected function p2g(): Parcel2Go
    {
        $now = $this->clockAt;
        return new Parcel2Go(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable($now, new \DateTimeZone('UTC')));
    }

    protected function on(string $key = 'p2g_vapeandgo', string $from = '2026-11-01'): void
    {
        $this->p2g()->switchOn(self::sys(), $key, $from);
    }

    /** An order of one goods line with its parcels (tracking => label time), stored and worked out now. */
    protected static function order(int $ch, string $ref, ?string $paid, array $parcels, string $carrier = 'manual'): void
    {
        $p = [];
        $i = 0;
        foreach ($parcels as $tracking => $label) {
            $p[] = ['parcel_ref' => 'odl:' . ((int) $ref * 10 + $i++), 'tracking_no' => (string) $tracking, 'carrier' => $carrier, 'service_code' => '', 'service' => '',
                'status' => 'generated', 'label_at' => $label, 'shipped_at' => $label, 'manual' => $carrier === 'manual', 'partial' => false];
        }
        (new OrderSnapshots(self::$db))->store($ch, $ref, ['v' => 1, 'version' => 1, 'order_no' => $ref, 'status' => 'completed', 'placed_at' => '2026-11-01T09:00:00Z',
            'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => (string) (8000000 + (int) $ref), 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false,
                'refund_inc' => 0, 'free' => false]],
            'delivery' => ['method_id' => '5', 'method' => 'Parcel2Go', 'charged_inc' => 399, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 1599,
            'parcels' => $p], 'api');
    }

    /**
     * One run of the job with Parcel2Go's answers scripted: the recent list, the baskets' invoice texts (by transaction), the tracking numbers
     * Parcel2Go names (by P2G number; none: 404).
     *
     * @param list<array<string, mixed>> $lines
     * @param array<int, string> $invoices transaction => text
     * @param array<int, list<string>> $tracking
     * @return array{0: array<string, mixed>, 1: FakeP2gHttp}
     */
    protected function pull(array $lines, array $invoices = [], array $tracking = [], string $key = 'p2g_vapeandgo'): array
    {
        $pdf = new FakePdfText();
        foreach ($invoices as $tx => $text) {
            $pdf->texts[P2gFixtures::pdf($tx)] = $text;
        }
        $http = new FakeP2gHttp(static function (string $m, string $p) use ($lines, $invoices, $tracking): array {
            if ($p === '/api/me/orders/detail') {
                return [200, ['Orders' => $lines]];
            }
            if (preg_match('#^/api/tracking/([0-9]+)$#', $p, $x) === 1) {
                return isset($tracking[(int) $x[1]]) ? [200, ['Results' => array_map(static fn (string $t): array => ['ParcelNumber' => $t, 'Description' => 'Test Person signed'],
                    $tracking[(int) $x[1]])]] : [404, ''];
            }
            if (preg_match('#^/api/invoices/([0-9]+)\?referenceType=OrderId$#', $p, $x) === 1 && isset($invoices[(int) $x[1]])) {
                return [200, ['OrderId' => (int) $x[1], 'Base64EncodedInvoice' => base64_encode(P2gFixtures::pdf((int) $x[1]))]];
            }
            return [404, ''];
        });
        $api = new P2gApi(P2gFixtures::key($key), $http, static function (int $s): void {
        });
        return [$this->p2g()->run(self::sys(), $key, $api, $pdf), $http];
    }

    /** @return array<string, int> where the live Parcel2Go money is: "dest|shop|how" => pence */
    protected function where(): array
    {
        $out = [];
        foreach (self::$db->all("SELECT a.dest, a.channel_id, a.how, SUM(a.pence) AS p FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id AND i.status = 'live' "
            . "AND i.carrier = 'parcel2go' WHERE a.ended_at IS NULL GROUP BY a.dest, a.channel_id, a.how") as $r) {
            $shop = match ((int) ($r['channel_id'] ?? 0)) {
                $this->vpg => 'vpg', $this->vb => 'vb', $this->ef => 'ef', default => '',
            };
            $out[$r['dest'] . '|' . $shop . '|' . $r['how']] = (int) $r['p'];
        }
        ksort($out);
        return $out;
    }

    /** @return array<string, int> a shop's delivery figures kept on its days: "day|field" => pence */
    protected static function days(int $ch): array
    {
        $out = [];
        foreach (self::$db->all('SELECT day, delivery_cost, delivery_other FROM profit_day WHERE channel_id = ? ORDER BY day', [$ch]) as $r) {
            foreach (['delivery_cost', 'delivery_other'] as $f) {
                if ((int) $r[$f] !== 0) {
                    $out[$r['day'] . '|' . $f] = (int) $r[$f];
                }
            }
        }
        return $out;
    }

    /** Every day of the three shops is what a rebuild works out; the delivery checks (Parcel2Go's too) find nothing. */
    protected function assertClean(): void
    {
        foreach ([$this->vpg, $this->vb, $this->ef] as $ch) {
            for ($d = '2026-11-01'; $d <= '2026-11-30'; $d = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d')) {
                self::assertSame([], array_values(array_filter(ProfitDays::differences(self::$db, $ch, $d), static fn (string $l): bool => !str_contains($l, 'row of zeros'))),
                    "shop {$ch} day {$d}: the kept figures are what a rebuild works out");
            }
        }
        self::assertSame([], DeliveryInvariants::check(self::$db), 'the delivery checks');
    }

    protected static function p2gLine(int $olid): array
    {
        return self::$db->one('SELECT * FROM p2g_line WHERE order_line_id = ?', [$olid]) ?? [];
    }
}
