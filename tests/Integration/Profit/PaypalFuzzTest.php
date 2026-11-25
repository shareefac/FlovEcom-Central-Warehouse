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
 * CW-1172 (final review; round 3: Codex astra re-check of 20faffa): random sequences (MADE-UP rows; seeded, so a failure names its seed and
 * sequence) of PayPal reads and changes on a small world of the ONE shared account: two Vape and Go orders, one Electrofag order, a Hayati
 * Vapes order (not a CW shop) and a Vape and Go number CW holds no order of; the real first fee days (Vape and Go 1 Nov, Electrofag 1 Dec,
 * the clock 6 Dec): sales, refunds PayPal paid out, chargebacks (done, pending, denied; by invoice or by reference to their sale) and their
 * reversals, dispute fees referring to their chargeback, newer and older versions of a row, an invoice corrected to another order (another
 * shop's too), the orders' refunds (at order level, or on a cancelled line that takes nothing off) and payment changed in CW, Electrofag's form
 * narrowed and put back, decisions with no PayPal read, versions of no update time, a guarded write starting from 1, 2 or 200 due rows (a
 * correction spread over several writes), the quick stop and the switch-on again (round 5: nothing of the account counts while it is off). Each sequence is its own account (fz<seq>: the same shops, forms and rules), so its work stays its
 * own size.
 *
 * The expected result is worked out INDEPENDENTLY of the code under test, from the generated truth alone (round 3): which order and shop each
 * row really is of (its invoice under the forms, else its reference to its sale or chargeback), whether it counts (status, the shop's first
 * fee day, Hayati outside, pending), where (its order, on the order's paid day for a sale's fee and its own day for the rest, or its shop's
 * "not on an order" line), what it costs, and a chargeback on an order whose refunds in CW (paid, not on a cancelled line) PayPal's own
 * refunds of it do not cover, or one paid before the first day read from PayPal with ANY refund recorded in CW (a cancelled line's too: round
 * 4): a look (refund_not_on_paypal) whose fee on its own row still counts on the order (round 4: chargebacks sometimes carry a fee). After
 * each sequence: every row = the truth; every day of both shops = the truth
 * of every sequence so far; converged (deciding again changes nothing); an older version read never changed a row; every 25th sequence and
 * the last: the PayPal checks (PP1-PP5) find nothing and the days are what a rebuild works out.
 * CW_PP_FUZZ_N sequences (default 10 in the suite; the round's small run: 1,000; the final tip: 5,000 once), CW_PP_FUZZ_SEED.
 */
final class PaypalFuzzTest extends StockTestCase
{
    private const KEY = ['client_id' => 'AclientIDmadeUp0123456789', 'client_secret' => 'EsecretMadeUp0123456789xyz', 'api_base' => 'https://api-m.paypal.com',
        'shop' => 'vapeandgo', 'merchant_id' => 'TESTACCOUNT'];
    private const PP_NOW = '2026-12-06 05:20:00';
    private const VPG_FROM = '2026-11-01';
    private const EF_FROM = '2026-12-01';
    private const WIDE = '"^([0-9]{4,6})-[0-9]{10}$"';
    private const FORMS = ['vapeandgo' => '/^([0-9]{7})-[0-9]{10}$/D', 'electrofag' => '/^([0-9]{4,6})-[0-9]{10}$/D', 'hayati' => '/^faaeeb-([0-9]{1,6})$/D'];

    /** @var array<string, string> */
    private array $settingsBefore = [];
    /** @var list<string> setting keys this test put in (the accounts') */
    private array $added = [];

    protected function tearDown(): void
    {
        foreach ($this->settingsBefore as $k => $v) {
            self::$db->exec('UPDATE app_setting SET value_json = ? WHERE setting_key = ?', [$v, $k]);
        }
        foreach (array_chunk($this->added, 500) as $chunk) {
            self::$db->exec('DELETE FROM app_setting WHERE setting_key IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk);
        }
        parent::tearDown();
    }

    private function setting(string $key, string $json): void
    {
        $this->settingsBefore[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = ? WHERE setting_key = ?', [$json, $key]);
    }

    /** The sequence's own account: the shared account's shops, main shop and merchant (its key names it). */
    private function account(string $account): void
    {
        foreach (['shops' => '"vapeandgo,electrofag,hayati"', 'main' => '"vapeandgo"', 'merchant' => '"TESTACCOUNT"'] as $what => $json) {
            $key = "profit.paypal_{$what}_{$account}";
            self::$db->exec("INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES (?, 'string', ?, 0, 'PPF21', 'the fuzz')",
                [$key, $json]);
            $this->added[] = $key;
        }
    }

    /** @param array<string, mixed> $o the order's truth */
    private static function order(int $ch, array $o): void
    {
        $lines = [['line_id' => (string) (8000000 + (int) $o['ref']), 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 6000, 'discount_inc' => 0, 'cancelled' => false,
            'refund_inc' => 0, 'free' => false]];
        if ($o['decoy']) {
            // a refund on a cancelled line: it takes nothing off the profit (final review a4), so it never makes a chargeback uncertain
            $lines[] = ['line_id' => (string) (9000000 + (int) $o['ref']), 'variant_id' => '40124', 'qty' => 1, 'price_inc' => 3000, 'discount_inc' => 0, 'cancelled' => true,
                'refund_inc' => 3000, 'free' => false];
        }
        (new OrderSnapshots(self::$db))->store($ch, (string) $o['ref'], ['v' => 1, 'version' => $o['ver'], 'order_no' => $o['no'], 'status' => 'completed',
            'placed_at' => '2026-11-10T09:00:00Z', 'paid_at' => $o['paid'] === null ? null : $o['paid'] . 'T10:00:00Z', 'gateway' => 'paypal', 'currency' => 'GBP',
            'parent_ref' => null, 'lines' => $lines, 'delivery' => ['method_id' => '5', 'method' => 'DPD', 'charged_inc' => 499, 'refund_inc' => 0],
            'order_refund_inc' => $o['refund'], 'grand_inc' => 6499, 'parcels' => []], 'api');
    }

    /** An order's paid day: mostly 18-24 Nov, sometimes before the first day read from PayPal (a Vape and Go order of 28 Oct), sometimes not paid. */
    private static function paidDay(string $k): ?string
    {
        $r = mt_rand(0, 9);
        return match (true) {
            $r < 2 => null,
            $r === 2 && $k !== 'e1' => '2026-10-28',
            default => sprintf('2026-11-%02d', mt_rand(18, 24)),
        };
    }

    public function testRandomSequencesMatchTheirOwnTruth(): void
    {
        $n = max(1, (int) (getenv('CW_PP_FUZZ_N') ?: 10));
        $seed = (int) (getenv('CW_PP_FUZZ_SEED') ?: 20261125);
        mt_srand($seed);
        $vpg = (int) $this->site('vapeandgo', 'live')->channelId;
        $ef = (int) $this->site('electrofag', 'live')->channelId;
        $chOf = ['vapeandgo' => $vpg, 'electrofag' => $ef];
        $this->setting('profit.paypal_from_vapeandgo', '"' . self::VPG_FROM . '"');
        $this->setting('profit.paypal_from_electrofag', '"' . self::EF_FROM . '"');
        $this->setting('profit.paypal_form_electrofag', self::WIDE);
        $caller = Caller::system('paypal_test');
        $http = new FakePaypalHttp([], '', null, '2026-12-06T02:00:00Z');
        $days = ['2026-10-28'];
        for ($d = '2026-11-14'; $d <= '2026-12-06'; $d = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d')) {
            $days[] = $d;
        }
        $expectDays = []; // "channel|day" => [fee_cost, fee_other], every finished sequence's truth
        $t0 = microtime(true);
        for ($s = 1; $s <= $n; $s++) {
            // the due rows a guarded write starts from: one, two, or the whole chunk (a correction then spreads over several writes)
            $chunk = [1, 2, PaypalFees::CHUNK][mt_rand(0, 2)];
            $fees = new PaypalFees(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::PP_NOW, new \DateTimeZone('UTC')), null, $chunk);
            $tag = "seed {$seed} sequence {$s} (chunk {$chunk})";
            $account = sprintf('fz%05d', $s);
            $this->account($account);
            $api = new PaypalApi(PaypalKey::fromArray(['account' => $account] + self::KEY, $account), $http, static function (int $sec): void {
            });
            // the world's truth: CW's orders, the Hayati order, a Vape and Go number of no CW order
            $orders = [];
            foreach (['v1' => 'vapeandgo', 'v2' => 'vapeandgo', 'e1' => 'electrofag'] as $k => $shop) {
                $i = ['v1' => 1, 'v2' => 2, 'e1' => 3][$k];
                $orders[$k] = ['shop' => $shop, 'ref' => (string) (700000 + $s * 10 + $i), 'no' => $shop === 'vapeandgo' ? (string) (1400000 + $s * 10 + $i)
                    : (string) (60000 + $s * 10 + $i), 'paid' => self::paidDay($k), 'refund' => [0, 0, 2000, 3000][mt_rand(0, 3)], 'decoy' => mt_rand(0, 3) === 0, 'ver' => 1];
                self::order($chOf[$shop], $orders[$k]);
            }
            $numbers = ['v1' => ['vapeandgo', $orders['v1']['no']], 'v2' => ['vapeandgo', $orders['v2']['no']], 'e1' => ['electrofag', $orders['e1']['no']],
                'h1' => ['hayati', (string) (3000 + $s)], 'vx' => ['vapeandgo', (string) (1490000 + $s)]];
            $inv = static function (string $t) use ($numbers): array {
                [$shop, $no] = $numbers[$t];
                return $shop === 'hayati' ? ['invoice_id' => 'faaeeb-' . $no, 'custom_field' => $no] : ['invoice_id' => $no . '-1763700000', 'custom_field' => $no . '-1763700000'];
            };
            $id = static fn (string $k): string => sprintf('FZ%05d%s', $s, $k);
            $pool = [];
            // every version PayPal gives is newer than the one before it (a strictly later update time, after every row's own time)
            $stamp = new \DateTimeImmutable('2026-12-05T21:00:00Z');
            $put = static function (string $key, array $row) use (&$pool, &$stamp): void {
                $stamp = $stamp->modify('+1 minute');
                $row['transaction_updated_date'] = $stamp->format('Y-m-d\TH:i:s\Z');
                $pool[$key] = $row;
            };
            $at = static fn (string $day, int $h): string => sprintf('%sT%02d:%02d:00Z', $day, $h, mt_rand(0, 59));
            $day = static fn (string $from, int $span): string => (new \DateTimeImmutable($from))->modify('+' . mt_rand(0, $span) . ' days')->format('Y-m-d');
            $put('S1', PaypalTx::row($id('S1'), 'T0006', $at('2026-11-20', 9), '60.00', '-1.50', null, 'S', $inv('v1')));
            $put('S2', PaypalTx::row($id('S2'), 'T0006', $at('2026-11-20', 10), '60.00', '-1.50', null, 'S', $inv('e1')));
            $put('S4', PaypalTx::row($id('S4'), 'T0006', $at('2026-11-20', 11), '25.00', '-0.80', null, 'S', $inv('h1')));
            if (mt_rand(0, 1) === 1) {
                $put('S3', PaypalTx::sale($id('S3'), $at('2026-11-21', 11), '20.00', '-0.60', null));
            }
            $sales = ['v1' => 'S1', 'e1' => 'S2', 'h1' => 'S4'];
            $cbs = [];
            for ($c = 1, $m = mt_rand(1, 3); $c <= $m; $c++) {
                $target = ['v1', 'v1', 'v2', 'e1', 'h1', 'vx'][mt_rand(0, 5)];
                $byRef = isset($sales[$target]) && mt_rand(0, 2) === 0;
                $cday = $day('2026-11-26', 8);
                $over = $byRef ? ['paypal_reference_id' => $id($sales[$target])] : $inv($target);
                $amount = '-' . [10, 20, 30, 40, 60][mt_rand(0, 4)] . '.00';
                // sometimes PayPal's fee on the chargeback's own row (round 4, H4: it counts even while the money is held for a look)
                $put('C' . $c, PaypalTx::row($id('C' . $c), 'T1201', $at($cday, 12), $amount, mt_rand(0, 2) === 0 ? '-5.00' : null, null, ['S', 'S', 'S', 'P', 'D'][mt_rand(0, 4)],
                    $over));
                $cbs[] = 'C' . $c;
                if (mt_rand(0, 3) === 0) {
                    // its reversal (money back), as it is targeted
                    $put('X' . $c, PaypalTx::row($id('X' . $c), 'T1202', $at((new \DateTimeImmutable($cday))->modify('+1 day')->format('Y-m-d'), 12), ltrim($amount, '-'),
                        null, null, 'S', $over));
                }
                if (mt_rand(0, 1) === 1) {
                    $put('F' . $c, PaypalTx::row($id('F' . $c), 'T0106', $at($cday, 13), '-14.00', null, null, 'S', ['paypal_reference_id' => $id('C' . $c)]));
                }
            }
            if (mt_rand(0, 1) === 1) {
                $put('R1', PaypalTx::row($id('R1'), 'T1107', $at($day('2026-11-22', 12), 8), '-' . [10, 20, 30][mt_rand(0, 2)] . '.00', '0.20', null, 'S',
                    $inv(['v1', 'v2', 'e1', 'h1'][mt_rand(0, 3)])));
            }
            $older = [];
            $narrowed = false;
            $read = function (array $served) use ($http, $fees, $caller, $api, $id, $tag, &$older): void {
                $before = [];
                foreach (array_keys($older) as $k) {
                    $before[$k] = self::$db->one('SELECT status, pp_updated_at, amount_pence FROM paypal_txn WHERE account = ? AND txn_id = ?', [$api->account(), $id($k)]);
                }
                $http->set(array_values($served));
                $fees->run($caller, $api, '2026-11-14', '2026-12-05', 'nightly');
                foreach ($before as $k => $b) {
                    // a version older than the one CW keeps never changes it (PPF37, PPF42: one of no update time is older than any dated one); a
                    // row CW first kept from an older version itself (an undated one) may be replaced by a dated one, which is newer than it
                    $at = isset($older[$k]['transaction_updated_date'])
                        ? (new \DateTimeImmutable((string) $older[$k]['transaction_updated_date']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u') : null;
                    if ($b !== null && $b['pp_updated_at'] !== null && ($at === null || (string) $b['pp_updated_at'] > $at)) {
                        self::assertSame($b, self::$db->one('SELECT status, pp_updated_at, amount_pence FROM paypal_txn WHERE account = ? AND txn_id = ?',
                            [$api->account(), $id($k)]), "{$tag}: an older version changed {$k}");
                    }
                }
                $older = [];
            };
            for ($op = 0, $ops = mt_rand(3, 6); $op < $ops; $op++) {
                switch (mt_rand(0, 7)) {
                    case 0: // a chargeback's status or amount changes (a newer version)
                        $k = $cbs[mt_rand(0, count($cbs) - 1)];
                        $pool[$k]['transaction_status'] = ['S', 'P', 'D'][mt_rand(0, 2)];
                        $pool[$k]['transaction_amount']['value'] = '-' . [10, 20, 30, 60][mt_rand(0, 3)] . '.00';
                        $put($k, $pool[$k]);
                        break;
                    case 1: // the first sale, or a chargeback of its own invoice, corrected to another order (another shop's too)
                        $byInvoice = array_values(array_filter($cbs, static fn (string $k): bool => isset($pool[$k]['invoice_id'])));
                        $k = mt_rand(0, 1) === 0 || $byInvoice === [] ? 'S1' : $byInvoice[mt_rand(0, count($byInvoice) - 1)];
                        $pool[$k] = $inv(['v1', 'v2', 'e1', 'h1'][mt_rand(0, 3)]) + $pool[$k];
                        $put($k, $pool[$k]);
                        break;
                    case 2: // an order's refunds or payment change in CW
                        $o = ['v1', 'v2', 'e1'][mt_rand(0, 2)];
                        $orders[$o]['ver']++;
                        $orders[$o]['refund'] = [0, 2000, 3000, 6000][mt_rand(0, 3)];
                        $orders[$o]['paid'] = self::paidDay($o);
                        self::order($chOf[$orders[$o]['shop']], $orders[$o]);
                        break;
                    case 3: // an older version of a row served (another status; or one of no update time, older than any dated one): never applied
                        $k = (string) array_rand($pool);
                        $o = $pool[$k];
                        $o['transaction_updated_date'] = (new \DateTimeImmutable((string) $o['transaction_initiation_date']))->format('Y-m-d\TH:i:s\Z');
                        if (mt_rand(0, 1) === 1) {
                            unset($o['transaction_updated_date']);
                        }
                        $o['transaction_status'] = $o['transaction_status'] === 'S' ? 'P' : 'S';
                        $older[$k] = $o;
                        break;
                    case 4: // Electrofag's form narrowed (its numbers of six digits only), or put back
                        $narrowed = !$narrowed;
                        $this->setting('profit.paypal_form_electrofag', $narrowed ? '"^([0-9]{6})-[0-9]{10}$"' : self::WIDE);
                        break;
                    case 5: // decided with no PayPal read (--decide)
                        $fees->decideAll($caller, $account, null);
                        break;
                    case 7: // the quick stop (every shop of the shared account cleared, --decide), then switched on again (round 5, PPF66): while
                        // it is off NOTHING of the account counts anywhere (a chargeback's fee held with its money too), and back on it counts again
                        $this->setting('profit.paypal_from_vapeandgo', '""');
                        $this->setting('profit.paypal_from_electrofag', '""');
                        $fees->decideAll($caller, $account, null);
                        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn WHERE account = ? AND alloc_dest IS NOT NULL', [$account]),
                            "{$tag}: switched off, nothing of the account counts");
                        $this->setting('profit.paypal_from_vapeandgo', '"' . self::VPG_FROM . '"');
                        $this->setting('profit.paypal_from_electrofag', '"' . self::EF_FROM . '"');
                        $fees->decideAll($caller, $account, null);
                        break;
                    default: // a PayPal refund of an order (read again: a newer version of the same refund, its order corrected)
                        $put('R2', PaypalTx::row($id('R2'), 'T1107', (string) ($pool['R2']['transaction_initiation_date'] ?? $at($day('2026-11-22', 12), 8)), '-20.00',
                            '0.30', null, 'S', $inv(['v1', 'v2', 'e1', 'h1'][mt_rand(0, 3)])));
                }
                $read(array_merge($pool, $older));
            }
            // the forms put back, and a last read of every row as PayPal has it now
            if ($narrowed) {
                $this->setting('profit.paypal_form_electrofag', self::WIDE);
            }
            $read($pool);

            // ---- the truth, worked out from the generated world alone ------------------------------------------------------------------
            $byTxn = [];
            foreach ($pool as $k => $r) {
                $byTxn[(string) $r['transaction_id']] = $k;
            }
            $capture = static function (?string $text): ?array {
                if ($text === null || $text === '') {
                    return null;
                }
                foreach (self::FORMS as $shop => $form) {
                    if (preg_match($form, $text, $mm) === 1) {
                        return [$shop, $mm[1]];
                    }
                }
                return null;
            };
            $kindOf = static fn (string $code): string => ['T0006' => 'sale', 'T1107' => 'refund', 'T1201' => 'chargeback', 'T1202' => 'chargeback', 'T0106' => 'fee'][$code];
            // whose order a row is: a refund, chargeback or fee by its reference to a row of this world (its sale; a fee's chargeback), else its invoice
            $whose = static function (string $k) use (&$whose, $pool, $byTxn, $capture, $kindOf): ?array {
                $r = $pool[$k];
                $ref = $r['paypal_reference_id'] ?? null;
                if ($kindOf((string) $r['transaction_event_code']) !== 'sale' && $ref !== null && isset($byTxn[$ref])) {
                    return $whose($byTxn[$ref]);
                }
                return $capture($r['invoice_id'] ?? null) ?? $capture($r['custom_field'] ?? null);
            };
            $orderOf = static function (?array $w) use ($orders): ?string {
                foreach ($orders as $k => $o) {
                    if ($w !== null && $o['shop'] === $w[0] && $o['no'] === $w[1]) {
                        return $k;
                    }
                }
                return null;
            };
            $pence = static fn (string $v): int => (int) round(((float) $v) * 100);
            $expected = [];
            $contrib = [];
            foreach ($pool as $k => $r) {
                $kind = $kindOf((string) $r['transaction_event_code']);
                $status = (string) $r['transaction_status'];
                $rday = substr((string) $r['transaction_initiation_date'], 0, 10);
                $amount = $pence((string) $r['transaction_amount']['value']);
                $fee = isset($r['fee_amount']) ? $pence((string) $r['fee_amount']['value']) : 0;
                $cost = in_array($kind, ['sale', 'refund'], true) ? -$fee : -($amount + $fee);
                $w = $whose($k);
                $e = ['count_state' => null, 'look_reason' => null, 'alloc_dest' => null, 'alloc_channel' => null, 'alloc_order_ref' => null, 'alloc_day' => null,
                    'alloc_pence' => 0];
                $shop = $w[0] ?? 'vapeandgo';
                $start = $shop === 'electrofag' ? self::EF_FROM : self::VPG_FROM;
                $ok = $orderOf($w);
                if ($status === 'D') {
                    $e['count_state'] = 'not_counted';
                } elseif ($shop === 'hayati') {
                    $e['count_state'] = 'outside';
                } elseif ($rday < $start) {
                    $e['count_state'] = 'before_start';
                } elseif ($status === 'P') {
                    $e['count_state'] = 'pending';
                } else {
                    $e['count_state'] = 'counts';
                    if ($kind === 'chargeback' && $ok !== null) {
                        $cw = $orders[$ok]['paid'] === null ? 0 : $orders[$ok]['refund'];
                        // paid before the first day read from PayPal (1 Nov): ANY refund recorded in CW (a cancelled line's too: round 4, H6) is not
                        // known to be on PayPal (PPF43)
                        $early = $orders[$ok]['paid'] !== null && $orders[$ok]['paid'] < self::VPG_FROM && ($orders[$ok]['refund'] > 0 || $orders[$ok]['decoy']);
                        $pp = 0;
                        foreach ($pool as $k2 => $r2) {
                            $s2 = (string) $r2['transaction_status'];
                            if ($kindOf((string) $r2['transaction_event_code']) === 'refund' && in_array($s2, ['S', 'V'], true) && $orderOf($whose($k2)) === $ok
                                && $pence((string) $r2['transaction_amount']['value']) < 0) {
                                $pp -= $pence((string) $r2['transaction_amount']['value']);
                            }
                        }
                        if ($early || $cw - $pp > 0) {
                            // its money held for a look; its fee on the same row counts on the order on its day (round 4, H4)
                            $e = ['count_state' => 'look', 'look_reason' => 'refund_not_on_paypal'] + $e;
                            if ($fee !== 0) {
                                $ch = $chOf[$shop];
                                $e = ['alloc_dest' => 'order', 'alloc_channel' => $ch, 'alloc_order_ref' => $orders[$ok]['ref'], 'alloc_day' => $rday, 'alloc_pence' => -$fee] + $e;
                                $contrib[$ch . '|' . $rday] ??= [0, 0];
                                $contrib[$ch . '|' . $rday][0] += -$fee;
                            }
                            $expected[$k] = $e;
                            continue;
                        }
                    }
                    if ($cost !== 0) {
                        $ch = $chOf[$shop];
                        if ($ok !== null) {
                            $e = ['alloc_dest' => 'order', 'alloc_channel' => $ch, 'alloc_order_ref' => $orders[$ok]['ref'], 'alloc_day' => $kind === 'sale' ? null : $rday,
                                'alloc_pence' => $cost] + $e;
                            $on = $kind === 'sale' ? $orders[$ok]['paid'] : $rday;
                            if ($on !== null) {
                                $contrib[$ch . '|' . $on] ??= [0, 0];
                                $contrib[$ch . '|' . $on][0] += $cost;
                            }
                        } else {
                            $e = ['alloc_dest' => 'shop', 'alloc_channel' => $ch, 'alloc_day' => $rday, 'alloc_pence' => $cost] + $e;
                            $contrib[$ch . '|' . $rday] ??= [0, 0];
                            $contrib[$ch . '|' . $rday][1] += $cost;
                        }
                    }
                }
                $expected[$k] = $e;
            }
            // every row of the sequence as the truth says
            foreach ($expected as $k => $e) {
                $got = self::$db->one('SELECT count_state, look_reason, alloc_dest, alloc_channel, alloc_order_ref, alloc_day, alloc_pence FROM paypal_txn '
                    . 'WHERE account = ? AND txn_id = ?', [$account, $id($k)]);
                self::assertNotNull($got, "{$tag}: {$k} stored");
                $got = ['count_state' => $got['count_state'], 'look_reason' => $got['look_reason'], 'alloc_dest' => $got['alloc_dest'],
                    'alloc_channel' => $got['alloc_channel'] === null ? null : (int) $got['alloc_channel'], 'alloc_order_ref' => $got['alloc_order_ref'],
                    'alloc_day' => $got['alloc_day'], 'alloc_pence' => (int) $got['alloc_pence']];
                ksort($e);
                ksort($got);
                if ($e !== $got) {
                    // the sequence's rows as CW holds them, for the failure's message (made-up rows: their CW states only)
                    $dump = array_map(static fn (array $r): string => implode(' ', array_map(static fn (mixed $v): string => $v === null ? '-' : (string) $v, $r)),
                        self::$db->all('SELECT SUBSTRING(txn_id, 8) AS k, event_code, status, count_state, look_reason, form_shop, order_no, matched_by, invoice_state, '
                        . 'read_sig, decided_sig, due, pp_conflict, alloc_dest, alloc_pence, version FROM paypal_txn WHERE account = ? ORDER BY id', [$account]));
                    $hist = array_map(static fn (array $r): string => implode(' ', array_map(static fn (mixed $v): string => $v === null ? '-' : (string) $v, $r)),
                        self::$db->all("SELECT SUBSTRING(t.txn_id, 8), h.version, h.what, h.run_id, JSON_UNQUOTE(JSON_EXTRACT(h.after_json, '$.count_state')), "
                        . "JSON_UNQUOTE(JSON_EXTRACT(h.after_json, '$.look_reason')), JSON_UNQUOTE(JSON_EXTRACT(h.after_json, '$.read_sig')) FROM paypal_txn_history h "
                        . 'JOIN paypal_txn t ON t.id = h.txn_row WHERE t.account = ? ORDER BY h.id', [$account]));
                    self::fail("{$tag}: {$k} is where its truth says: want " . json_encode($e) . ' got ' . json_encode($got) . "\nrows:\n" . implode("\n", $dump)
                        . "\nhistory:\n" . implode("\n", $hist) . "\nsig now " . PaypalFees::formsSig(PaypalFees::accountShops(self::$db, $account)));
                }
            }
            // every day of both shops: the truth of every sequence so far
            $touched = [];
            foreach ($orders as $o) {
                if ($o['paid'] !== null) {
                    $touched[$chOf[$o['shop']] . '|' . $o['paid']] = true;
                }
            }
            foreach ($contrib as $key => [$c, $o]) {
                $expectDays[$key] ??= [0, 0];
                $expectDays[$key][0] += $c;
                $expectDays[$key][1] += $o;
                $touched[$key] = true;
            }
            $have = [];
            foreach (self::$db->all('SELECT channel_id, day, fee_cost, fee_other FROM profit_day WHERE channel_id IN (?, ?) AND (fee_cost <> 0 OR fee_other <> 0)', [$vpg, $ef]) as $r) {
                $have[$r['channel_id'] . '|' . $r['day']] = [(int) $r['fee_cost'], (int) $r['fee_other']];
            }
            $want = array_filter($expectDays, static fn (array $v): bool => $v !== [0, 0]);
            ksort($want);
            ksort($have);
            self::assertSame($want, $have, "{$tag}: the profit days hold every sequence's truth");
            // converged: every row of the sequence decided again changes nothing
            self::$db->exec('UPDATE paypal_txn SET due = 1, due_since = UTC_TIMESTAMP(6) WHERE account = ?', [$account]);
            self::assertSame(0, $fees->decideAll($caller, $account, null), "{$tag}: deciding the rows again moved one (not converged)");
            // the checks and a rebuild of the days (the sequence's own days every 25th sequence; every day of both shops at the last)
            if ($s % 25 === 0 || $s === $n) {
                self::assertSame([], PaypalInvariants::check(self::$db), $tag);
                $check = $s === $n ? array_merge(...array_map(static fn (int $ch): array => array_map(static fn (string $d): string => $ch . '|' . $d, $days), [$vpg, $ef]))
                    : array_keys($touched);
                foreach ($check as $key) {
                    [$ch, $d] = explode('|', $key);
                    $diff = array_values(array_filter(ProfitDays::differences(self::$db, (int) $ch, $d), static fn (string $l): bool => !str_contains($l, 'row of zeros')));
                    self::assertSame([], $diff, "{$tag}: the days are a rebuild ({$key})");
                }
            }
        }
        fwrite(STDERR, sprintf("[paypal fuzz] %d sequences (seed %d) in %.1f s\n", $n, $seed, microtime(true) - $t0));
    }
}
