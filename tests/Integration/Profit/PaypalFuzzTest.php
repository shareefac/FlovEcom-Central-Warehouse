<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Profit\OrderSnapshots;
use CW\Profit\PaypalApi;
use CW\Profit\PaypalFees;
use CW\Profit\PaypalInvariants;
use CW\Profit\PaypalKey;
use CW\Profit\ProfitDays;
use CW\Tests\Support\FakePaypalHttp;
use CW\Tests\Support\PaypalTx;
use CW\Tests\Support\StockTestCase;

/**
 * CW-1172 final review: random sequences (MADE-UP rows; seeded, so a failure names its seed and sequence) of PayPal reads and changes on a
 * small world (two Vape and Go orders, one Electrofag order): sales, refunds PayPal paid out, chargebacks (done, pending, denied, by invoice or
 * by reference), dispute fees referring to their chargeback, newer and older versions of a row, an invoice corrected to another shop's order,
 * the orders' refunds and payment changed in CW, Electrofag's form narrowed and put back. After each sequence:
 *   - the PayPal checks (PP1-PP5) find nothing and every day of both shops is what a rebuild works out;
 *   - converged: deciding every row of the sequence again changes nothing (no row left on an old order, an old budget or an old parent);
 *   - the chargebacks' refund offsets are what an independent working-out gives (the budget = the refunds CW counts less PayPal's own refunds,
 *     used by the countable chargebacks in time order: PPF34);
 *   - an older version read never changed a row.
 * CW_PP_FUZZ_N sequences (default 10 in the suite, about 17 s; the round's small run: 1,000, once).
 */
final class PaypalFuzzTest extends StockTestCase
{
    private const KEY = ['client_id' => 'AclientIDmadeUp0123456789', 'client_secret' => 'EsecretMadeUp0123456789xyz', 'api_base' => 'https://api-m.paypal.com',
        'shop' => 'vapeandgo', 'account' => 'vapeandgo', 'merchant_id' => 'TESTACCOUNT'];
    private const PP_NOW = '2026-11-25 05:20:00';

    /** @var array<string, string> */
    private array $settingsBefore = [];

    protected function tearDown(): void
    {
        foreach ($this->settingsBefore as $k => $v) {
            self::$db->exec('UPDATE app_setting SET value_json = ? WHERE setting_key = ?', [$v, $k]);
        }
        parent::tearDown();
    }

    private function setting(string $key, string $json): void
    {
        $this->settingsBefore[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = ? WHERE setting_key = ?', [$json, $key]);
    }

    private static function fees(): PaypalFees
    {
        return new PaypalFees(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::PP_NOW, new \DateTimeZone('UTC')));
    }

    private static function order(int $ch, string $ref, string $no, ?string $paid, int $version, int $refundInc): void
    {
        (new OrderSnapshots(self::$db))->store($ch, $ref, ['v' => 1, 'version' => $version, 'order_no' => $no, 'status' => 'completed',
            'placed_at' => '2026-10-30T09:00:00Z', 'paid_at' => $paid, 'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => (string) (8000000 + (int) $ref), 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 6000, 'discount_inc' => 0, 'cancelled' => false,
                'refund_inc' => 0, 'free' => false]],
            'delivery' => ['method_id' => '5', 'method' => 'DPD', 'charged_inc' => 499, 'refund_inc' => 0], 'order_refund_inc' => $refundInc, 'grand_inc' => 6499,
            'parcels' => []], 'api');
    }

    public function testRandomSequencesStayConsistentConvergedAndOffsetAsWorkedOut(): void
    {
        $n = max(1, (int) (getenv('CW_PP_FUZZ_N') ?: 10));
        $seed = (int) (getenv('CW_PP_FUZZ_SEED') ?: 20261125);
        mt_srand($seed);
        $vpg = (int) $this->site('vapeandgo', 'live')->channelId;
        $ef = (int) $this->site('electrofag', 'live')->channelId;
        $this->setting('profit.paypal_from_vapeandgo', '"2026-11-01"');
        $this->setting('profit.paypal_from_electrofag', '"2026-11-01"');
        $this->setting('profit.paypal_merchant_vapeandgo', '"TESTACCOUNT"');
        $wide = '"^([0-9]{4,6})-[0-9]{10}$"';
        $this->setting('profit.paypal_form_electrofag', $wide);
        $http = new FakePaypalHttp([]);
        $api = new PaypalApi(PaypalKey::fromArray(self::KEY, 'vapeandgo'), $http, static function (int $s): void {
        });
        $caller = Caller::system('paypal_test');
        $t0 = microtime(true);
        for ($s = 1; $s <= $n; $s++) {
            $tag = "seed {$seed} sequence {$s}";
            // the world of this sequence: its own orders and PayPal ids
            $orders = [
                'v1' => ['ch' => $vpg, 'ref' => (string) (600000 + $s * 10 + 1), 'no' => (string) (1300000 + $s * 10 + 1), 'paid' => true, 'refund' => 0, 'ver' => 1],
                'v2' => ['ch' => $vpg, 'ref' => (string) (600000 + $s * 10 + 2), 'no' => (string) (1300000 + $s * 10 + 2), 'paid' => true, 'refund' => 0, 'ver' => 1],
                'e1' => ['ch' => $ef, 'ref' => (string) (600000 + $s * 10 + 3), 'no' => (string) (50000 + $s * 10 + 3), 'paid' => true, 'refund' => 0, 'ver' => 1],
            ];
            foreach ($orders as $k => $o) {
                $orders[$k]['refund'] = [0, 0, 2000, 3000, 6000][mt_rand(0, 4)];
                $orders[$k]['paid'] = mt_rand(0, 4) > 0;
                self::order($o['ch'], $o['ref'], $o['no'], $orders[$k]['paid'] ? '2026-11-0' . mt_rand(1, 4) . 'T10:00:00Z' : null, 1, $orders[$k]['refund']);
            }
            $id = static fn (string $k): string => sprintf('FZ%05d%s', $s, $k);
            $pool = [];
            $ver = [];
            $put = static function (string $key, array $row) use (&$pool, &$ver): void {
                $ver[$key] = ($ver[$key] ?? 0) + 1;
                $at = new \DateTimeImmutable((string) $row['transaction_initiation_date']);
                $row['transaction_updated_date'] = $at->modify('+' . $ver[$key] . ' minutes')->format('Y-m-d\TH:i:s\Z');
                $pool[$key] = $row;
            };
            $no = static fn (string $o): string => $orders[$o]['no'];
            $at = static fn (int $d, int $h): string => sprintf('2026-11-%02dT%02d:%02d:00Z', $d, $h, mt_rand(0, 59));
            $put('S1', PaypalTx::sale($id('S1'), $at(2, 9), '60.00', '-1.50', $no('v1')));
            $put('S2', PaypalTx::sale($id('S2'), $at(2, 10), '60.00', '-1.50', $no('e1')));
            if (mt_rand(0, 1) === 1) {
                $put('S3', PaypalTx::sale($id('S3'), $at(2, 11), '20.00', '-0.60', null));
            }
            $cbs = [];
            for ($c = 1, $m = mt_rand(1, 3); $c <= $m; $c++) {
                $target = ['v1', 'v1', 'v2', 'e1'][mt_rand(0, 3)];
                $byRef = mt_rand(0, 2) === 0;
                $row = PaypalTx::row($id('C' . $c), 'T1201', $at(3 + $c, 12), '-' . [10, 20, 30, 40, 60][mt_rand(0, 4)] . '.00', null, $byRef ? null : $no($target),
                    ['S', 'S', 'S', 'P', 'D'][mt_rand(0, 4)], $byRef ? ['paypal_reference_id' => $target === 'e1' ? $id('S2') : $id('S1')] : []);
                $put('C' . $c, $row);
                $cbs[] = 'C' . $c;
                if (mt_rand(0, 1) === 1) {
                    $put('F' . $c, PaypalTx::row($id('F' . $c), 'T0106', $at(3 + $c, 13), '-14.00', null, null, 'S', ['paypal_reference_id' => $id('C' . $c)]));
                }
            }
            if (mt_rand(0, 1) === 1) {
                $put('R1', PaypalTx::refund($id('R1'), $at(3, 8), '-' . [10, 20][mt_rand(0, 1)] . '.00', '0.20', $no(['v1', 'e1'][mt_rand(0, 1)])));
            }
            $older = [];
            $narrowed = false;
            for ($op = 0, $ops = mt_rand(3, 6); $op < $ops; $op++) {
                switch (mt_rand(0, 6)) {
                    case 0: // a chargeback's status or amount changes (a newer version)
                        $k = $cbs[mt_rand(0, count($cbs) - 1)];
                        $pool[$k]['transaction_status'] = ['S', 'P', 'D'][mt_rand(0, 2)];
                        $pool[$k]['transaction_amount']['value'] = '-' . [10, 20, 30, 60][mt_rand(0, 3)] . '.00';
                        $put($k, $pool[$k]);
                        break;
                    case 1: // a chargeback or the first sale corrected to another order (another shop's too)
                        $k = mt_rand(0, 1) === 0 ? 'S1' : $cbs[mt_rand(0, count($cbs) - 1)];
                        $o = ['v1', 'v2', 'e1'][mt_rand(0, 2)];
                        $pool[$k]['invoice_id'] = $pool[$k]['custom_field'] = $no($o) . '-1763700000';
                        $put($k, $pool[$k]);
                        break;
                    case 2: // an order's refunds or payment change in CW
                        $o = ['v1', 'v2', 'e1'][mt_rand(0, 2)];
                        $orders[$o]['ver']++;
                        $orders[$o]['refund'] = [0, 2000, 3000, 6000][mt_rand(0, 3)];
                        $orders[$o]['paid'] = mt_rand(0, 3) > 0;
                        self::order($orders[$o]['ch'], $orders[$o]['ref'], $orders[$o]['no'], $orders[$o]['paid'] ? '2026-11-03T10:00:00Z' : null, $orders[$o]['ver'],
                            $orders[$o]['refund']);
                        break;
                    case 3: // an older version of a row served (another status): never applied
                        $k = array_rand($pool);
                        $o = $pool[$k];
                        $o['transaction_updated_date'] = (new \DateTimeImmutable((string) $o['transaction_initiation_date']))->format('Y-m-d\TH:i:s\Z');
                        $o['transaction_status'] = $o['transaction_status'] === 'S' ? 'P' : 'S';
                        $older[$k] = $o;
                        break;
                    case 4: // Electrofag's form narrowed, or put back
                        $narrowed = !$narrowed;
                        $this->setting('profit.paypal_form_electrofag', $narrowed ? '"^([0-9]{6})-[0-9]{10}$"' : $wide);
                        break;
                    case 5: // decided with no PayPal read (--decide)
                        self::fees()->decideAll($caller, 'vapeandgo', null);
                        break;
                    default: // a PayPal refund of an order
                        $put('R2', PaypalTx::refund($id('R2'), $at(5, 8), '-20.00', '0.30', $no(['v1', 'v2', 'e1'][mt_rand(0, 2)])));
                }
                // a read of the days (the older versions, if any, served instead of the kept ones for this read only)
                $served = $pool;
                foreach ($older as $k => $o) {
                    $served[$k] = $o;
                }
                $before = [];
                foreach (array_keys($older) as $k) {
                    $before[$k] = self::$db->one('SELECT status, pp_updated_at, amount_pence FROM paypal_txn WHERE txn_id = ?', [$id($k)]);
                }
                $http->set(array_values($served));
                self::fees()->run($caller, $api, '2026-11-01', '2026-11-07', 'nightly');
                foreach ($before as $k => $b) {
                    if ($b !== null) {
                        self::assertSame($b, self::$db->one('SELECT status, pp_updated_at, amount_pence FROM paypal_txn WHERE txn_id = ?', [$id($k)]), "{$tag}: an older version changed {$k}");
                    }
                }
                $older = [];
            }
            if ($narrowed) {
                $this->setting('profit.paypal_form_electrofag', $wide);
                $http->set(array_values($pool));
                self::fees()->run($caller, $api, '2026-11-01', '2026-11-07', 'nightly');
            }
            // consistent: the checks, the days
            self::assertSame([], PaypalInvariants::check(self::$db), $tag);
            foreach ([$vpg, $ef] as $ch) {
                for ($d = 1; $d <= 7; $d++) {
                    $diff = array_values(array_filter(ProfitDays::differences(self::$db, $ch, sprintf('2026-11-%02d', $d)), static fn (string $l): bool => !str_contains($l, 'row of zeros')));
                    self::assertSame([], $diff, "{$tag}: the days are a rebuild");
                }
            }
            // converged: every row of the sequence decided again changes nothing
            $mine = array_map('intval', self::$db->column('SELECT id FROM paypal_txn WHERE txn_id LIKE ?', [sprintf('FZ%05d%%', $s)]));
            self::$db->exec('UPDATE paypal_txn SET due = 1, due_since = UTC_TIMESTAMP(6) WHERE id IN (' . implode(', ', array_fill(0, count($mine), '?')) . ')', $mine);
            self::assertSame(0, self::fees()->decideAll($caller, 'vapeandgo', null), "{$tag}: deciding the rows again moved one (not converged)");
            // the offsets, worked out on their own
            foreach ($orders as $o) {
                $row = self::$db->one('SELECT day, parent_ref, line_refund_inc + order_refund_inc + delivery_refund_inc AS r FROM sale_order WHERE channel_id = ? AND order_ref = ?',
                    [$o['ch'], $o['ref']]);
                $cw = $row === null || $row['day'] === null || $row['parent_ref'] !== null ? 0 : (int) $row['r'];
                $pp = (int) self::$db->value("SELECT COALESCE(SUM(-amount_pence), 0) FROM paypal_txn WHERE shop_channel = ? AND order_no = ? AND kind = 'refund' "
                    . "AND status IN ('S', 'V') AND count_state IN ('counts', 'before_start') AND amount_pence < 0", [$o['ch'], $o['no']]);
                $left = max(0, $cw - $pp);
                foreach (self::$db->all("SELECT id, amount_pence, refund_offset_pence, count_state, match_state FROM paypal_txn WHERE kind = 'chargeback' AND shop_channel = ? "
                    . "AND match_order_ref = ? AND count_state = 'counts' ORDER BY initiated_at, id", [$o['ch'], $o['ref']]) as $c) {
                    $use = -(int) $c['amount_pence'] > 0 ? min(-(int) $c['amount_pence'], $left) : 0;
                    $left -= $use;
                    self::assertSame($use, (int) $c['refund_offset_pence'], "{$tag}: chargeback row {$c['id']}'s refund offset");
                }
                self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM paypal_txn WHERE kind = 'chargeback' AND refund_offset_pence > 0 AND count_state <> 'counts' "
                    . 'AND txn_id LIKE ?', [sprintf('FZ%05d%%', $s)]), "{$tag}: an offset on a chargeback that does not count");
            }
        }
        fwrite(STDERR, sprintf("[paypal fuzz] %d sequences (seed %d) in %.1f s\n", $n, $seed, microtime(true) - $t0));
    }
}
