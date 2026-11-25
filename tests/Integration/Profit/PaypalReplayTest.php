<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Profit\PaypalApi;
use CW\Profit\PaypalFees;
use CW\Profit\PaypalKey;
use CW\Profit\ProfitDays;
use CW\Tests\Support\FakePaypalHttp;
use CW\Tests\Support\StockTestCase;

/**
 * The real data replayed (CW-1172; docs/decisions.md PF19): PayPal's 14,302 rows of Vape and Go, 1 Oct - 24 Nov 2026, as the lead read them
 * on 24 Nov, loaded through the real classes (a fake PayPal serving them page by page, window by window: no network). The file is never in
 * git and holds no payer, name or item text (the builder's reduced copy: only the fields CW reads; an invoice or custom field not in the
 * shop's form replaced by a word of another form); the test is skipped without it (CW_PAYPAL_REPLAY = its path on the test box, removed
 * after the run). Each 7-digit order number gets a made-up paid order of the shop on its first sale's UK day.
 *
 * It checks the owner's October Balance report to the penny (sales' fees 6,449.95; refunds' fees back 81.16; the chargebacks and their fees),
 * no code not understood, every penny counted once (the days add up to the counted rows), a second run that changes nothing, and a rebuild of
 * every day from scratch that gives the same pence; it times the read, the store and the decisions at full size (CW_PAYPAL_REPLAY_TIMES: a
 * file the timings are written to).
 */
final class PaypalReplayTest extends StockTestCase
{
    /** @var array<string, string> */
    private array $before = [];

    protected function tearDown(): void
    {
        foreach ($this->before as $k => $v) {
            self::$db->exec('UPDATE app_setting SET value_json = ? WHERE setting_key = ?', [$v, $k]);
        }
        parent::tearDown();
    }

    private function setting(string $key, string $json): void
    {
        $this->before[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = ? WHERE setting_key = ?', [$json, $key]);
    }

    /** @param array<string, \DateTimeImmutable> $paid number => its first sale @return int orders made */
    private static function orders(int $ch, array $paid): int
    {
        foreach (array_chunk($paid, 500, true) as $chunk) {
            $params = [];
            foreach ($chunk as $no => $at) {
                $day = $at->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
                array_push($params, $ch, 'r' . $no, (string) $no, 'completed', $at->format('Y-m-d H:i:s.000000'), $at->format('Y-m-d H:i:s.000000'), $day, 'paypal', 2000, 1,
                    '2026-11-25 05:00:00.000000', '2026-11-25 05:00:00.000000');
            }
            self::$db->exec('INSERT INTO sale_order (channel_id, order_ref, order_no, status, placed_at, paid_at, day, gateway, vat_bp, snapshot_version, first_at, updated_at) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')), $params);
        }
        return count($paid);
    }

    /** Each day of the shop holds exactly its rows' money (a sale's fee on its order's day, the others on their own). */
    private static function assertDaysTie(int $ch): void
    {
        $byDay = [];
        foreach (self::$db->all("SELECT COALESCE(t.alloc_day, o.day) AS d, SUM(IF(t.alloc_dest = 'order', t.alloc_pence, 0)) AS c, SUM(IF(t.alloc_dest = 'shop', t.alloc_pence, 0)) AS s "
            . 'FROM paypal_txn t LEFT JOIN sale_order o ON o.channel_id = t.alloc_channel AND o.order_ref = t.alloc_order_ref WHERE t.alloc_dest IS NOT NULL AND t.alloc_channel = ? '
            . 'GROUP BY d', [$ch]) as $r) {
            $byDay[(string) $r['d']] = [(int) $r['c'], (int) $r['s']];
        }
        $kept = [];
        foreach (self::$db->all('SELECT day, fee_cost, fee_other FROM profit_day WHERE channel_id = ? AND (fee_cost <> 0 OR fee_other <> 0)', [$ch]) as $r) {
            $kept[(string) $r['day']] = [(int) $r['fee_cost'], (int) $r['fee_other']];
        }
        ksort($byDay);
        ksort($kept);
        self::assertSame($byDay, $kept);
    }

    public function testTheRealMonthsTieToThePennyTwiceAndRebuildTheSame(): void
    {
        $path = getenv('CW_PAYPAL_REPLAY');
        if (!is_string($path) || $path === '' || !is_file($path)) {
            self::markTestSkipped('CW_PAYPAL_REPLAY (the reduced real rows) is not on this box');
        }
        $times = [];
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $rows[] = json_decode($l, true, 16, JSON_THROW_ON_ERROR);
        }
        self::assertCount(14302, $rows);
        // ONE PayPal account takes Vape and Go's, Electrofag's and Hayati Vapes' payments (the owner, 25 Nov 2026; PF21): each shop's order
        // numbers have their own form; Vape and Go counts from 1 Oct here, Electrofag is switched on later in the test, Hayati Vapes is not in CW
        $ch = (int) $this->site('vapeandgo', 'live')->channelId;
        $ef = (int) $this->site('electrofag', 'live')->channelId;
        $this->setting('profit.paypal_from_vapeandgo', '"2026-10-01"');
        // a made-up paid order for each number of the two CW shops' forms, paid at its first sale
        $paid = ['vpg' => [], 'ef' => []];
        foreach ($rows as $r) {
            if (in_array($r['transaction_event_code'], ['T0005', 'T0006'], true) && preg_match('/^([0-9]{4}|[0-9]{7})-[0-9]{10}$/D', (string) ($r['invoice_id'] ?? ''), $m) === 1) {
                $at = (new \DateTimeImmutable((string) $r['transaction_initiation_date']))->setTimezone(new \DateTimeZone('UTC'));
                $k = strlen($m[1]) === 7 ? 'vpg' : 'ef';
                if (!isset($paid[$k][$m[1]]) || $at < $paid[$k][$m[1]]) {
                    $paid[$k][$m[1]] = $at;
                }
            }
        }
        $t = microtime(true);
        $times['orders_made'] = [self::orders($ch, $paid['vpg']), self::orders($ef, $paid['ef']), round(microtime(true) - $t, 2)];
        $chunks = [];
        $fees = new PaypalFees(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-25 05:20:00', new \DateTimeZone('UTC')),
            static function (int $n, float $s) use (&$chunks): void {
                $chunks[] = $s;
            });
        $key = PaypalKey::fromArray(['client_id' => 'AclientIDmadeUp0123456789', 'client_secret' => 'EsecretMadeUp0123456789xyz', 'api_base' => 'https://api-m.paypal.com',
            'shop' => 'vapeandgo', 'account' => 'vapeandgo'], 'vapeandgo');
        $http = new FakePaypalHttp($rows);
        $http->byUpdated = true; // as PayPal served them: a September hold's release (T1111) in the window of its release
        $mem = memory_get_peak_usage(true);
        $t = microtime(true);
        $run = $fees->run(Caller::system('paypal_replay'), new PaypalApi($key, $http, static function (int $s): void {
        }), '2026-10-01', '2026-11-24', 'backfill');
        $times['first_run'] = [round(microtime(true) - $t, 2), 'windows' => $run['windows'], 'pages' => $run['pages'], 'rows' => $run['read'], 'new' => $run['new'],
            'decided' => $run['allocated'], 'phases_s' => $run['seconds'], 'chunks' => count($chunks), 'chunk_max_s' => round(max($chunks), 3), 'chunk_avg_s' => round(array_sum($chunks) / count($chunks), 3),
            'peak_mb' => round(max($mem, memory_get_peak_usage(true)) / 1048576)];
        self::assertGreaterThanOrEqual(14302, $run['read'], 'a row whose update falls in another window is served there too (the same row, read again)');
        // 12 PayPal ids are a dispute hold and its release (T1110, T1111): two rows each, never one
        self::assertSame(14302, (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn'));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM paypal_txn WHERE count_state = 'look'"), 'every code understood');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn WHERE due = 1'));
        // the whole account's October, UK days, as the owner's Balance report: the sales' fees, the refunds' fees back, the chargebacks with
        // their fees (what each row costs, whichever shop it is)
        $oct = static fn (string $kinds): int => (int) self::$db->value("SELECT COALESCE(SUM(cost_pence), 0) FROM paypal_txn WHERE kind <> 'ignore' "
            . "AND day BETWEEN '2026-10-01' AND '2026-10-31' AND event_code IN ({$kinds})");
        self::assertSame(644995, $oct("'T0005', 'T0006'"), 'the sales\' fees of October: 2,584.30 + 3,865.65');
        self::assertSame(258430, $oct("'T0005'"));
        self::assertSame(-8116, $oct("'T1107'"), 'the refunds\' fees back: 81.16');
        self::assertSame(17271 - 68 + 11608, $oct("'T1106', 'T1201'"), 'the money taken back (288.79) less the fee given back on a reversal (0.68)');
        self::assertSame(5600 + 3600, $oct("'T0106', 'T0114'"), 'the chargeback fees (56.00) and the dispute fees (36.00)');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM paypal_txn WHERE event_code IN ('T1501', 'T1105', 'T1110', 'T1111', 'T0403', 'T0500', 'T0003', 'T0801') "
            . "AND count_state <> 'not_counted'"), 'holds, releases, bank moves, payments sent: never counted');
        // each shop by its invoice's form (the lead's October count on UTC days, 25 Nov: Vape and Go 6,575 sales, fees 5,699.03; Electrofag 603,
        // 611.90; Hayati Vapes 131, 108.71; no invoice 50 sales, 21.08)
        $byForm = static fn (string $cond): array => array_map('intval', (array) self::$db->one("SELECT COUNT(*) AS n, COALESCE(SUM(cost_pence), 0) AS p FROM paypal_txn "
            . "WHERE kind = 'sale' AND initiated_at >= '2026-10-01' AND initiated_at < '2026-11-01' AND {$cond}"));
        self::assertSame(['n' => 6575, 'p' => 569903], $byForm("form_shop = 'vapeandgo'"));
        self::assertSame(['n' => 603, 'p' => 61190], $byForm("form_shop = 'electrofag'"));
        self::assertSame(['n' => 131, 'p' => 10871], $byForm("form_shop = 'hayati'"));
        self::assertSame(['n' => 50, 'p' => 2108], $byForm('form_shop IS NULL'));
        // on UK days (the Balance report's): Vape and Go's own sales' fees of October
        self::assertSame(570826, (int) self::$db->value("SELECT SUM(cost_pence) FROM paypal_txn WHERE kind = 'sale' AND form_shop = 'vapeandgo' "
            . "AND day BETWEEN '2026-10-01' AND '2026-10-31'"));
        // Electrofag (not switched on) and Hayati Vapes (not in CW) are never on Vape and Go's lines
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM paypal_txn WHERE form_shop IN ('electrofag', 'hayati') AND count_state IN ('counts', 'pending')"));
        self::assertSame(['before_start', 'outside'], array_map('strval', self::$db->column("SELECT DISTINCT count_state FROM paypal_txn WHERE form_shop IN ('electrofag', 'hayati') "
            . "AND kind <> 'ignore' ORDER BY count_state")));
        // every penny once: Vape and Go's days hold exactly what its counted rows cost (every order here is paid)
        $counted = (int) self::$db->value("SELECT SUM(cost_pence) FROM paypal_txn WHERE count_state = 'counts'");
        self::assertSame($counted, (int) self::$db->value("SELECT SUM(cost_pence) FROM paypal_txn WHERE count_state = 'counts' AND shop_channel = ?", [$ch]));
        self::assertSame($counted, (int) self::$db->value('SELECT SUM(alloc_pence) FROM paypal_txn WHERE alloc_dest IS NOT NULL'));
        self::assertSame($counted, (int) self::$db->value('SELECT SUM(fee_cost) + SUM(fee_other) FROM profit_day WHERE channel_id = ?', [$ch]));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM profit_day WHERE channel_id = ? AND (fee_cost <> 0 OR fee_other <> 0)', [$ef]));
        self::assertDaysTie($ch);
        $times['money'] = ['counted_p' => $counted, 'on_orders_p' => (int) self::$db->value("SELECT SUM(alloc_pence) FROM paypal_txn WHERE alloc_dest = 'order'"),
            'not_on_order_p' => (int) self::$db->value("SELECT SUM(alloc_pence) FROM paypal_txn WHERE alloc_dest = 'shop'"),
            'nov_1_24_on_orders_p' => (int) self::$db->value("SELECT SUM(fee_cost) FROM profit_day WHERE channel_id = ? AND day BETWEEN '2026-11-01' AND '2026-11-24'", [$ch]),
            'nov_1_24_not_on_order_p' => (int) self::$db->value("SELECT SUM(fee_other) FROM profit_day WHERE channel_id = ? AND day BETWEEN '2026-11-01' AND '2026-11-24'", [$ch]),
            'match' => self::$db->all("SELECT kind, match_state, COUNT(*) AS n, SUM(alloc_pence) AS p FROM paypal_txn WHERE count_state = 'counts' GROUP BY kind, match_state ORDER BY kind, match_state"),
            'by_form' => self::$db->all("SELECT COALESCE(form_shop, '-') AS shop, kind, count_state, COUNT(*) AS n, SUM(cost_pence) AS cost FROM paypal_txn WHERE kind <> 'ignore' "
                . 'GROUP BY shop, kind, count_state ORDER BY shop, kind, count_state')];

        // a second run of the same rows: nothing new, nothing changed, nothing moved; the days the same
        $before = self::$db->all('SELECT day, fee_cost, fee_other FROM profit_day WHERE channel_id = ? ORDER BY day', [$ch]);
        $hist = (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn_history');
        $chunks = [];
        $t = microtime(true);
        $again = $fees->run(Caller::system('paypal_replay'), new PaypalApi($key, $http, static function (int $s): void {
        }), '2026-10-01', '2026-11-24', 'backfill');
        $times['second_run'] = [round(microtime(true) - $t, 2), 'new' => $again['new'], 'changed' => $again['changed'], 'decided' => $again['allocated'],
            'phases_s' => $again['seconds'], 'rematch_parts' => $fees->rematchParts];
        self::assertSame([$run['read'], 0, 0, 0], [$again['read'], $again['new'], $again['changed'], $again['allocated']]);
        self::assertSame($before, self::$db->all('SELECT day, fee_cost, fee_other FROM profit_day WHERE channel_id = ? ORDER BY day', [$ch]));
        self::assertSame($hist, (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn_history'));
        // the nightly's re-read of the last 7 days, timed
        $t = microtime(true);
        $nightly = $fees->run(Caller::system('paypal_replay'), new PaypalApi($key, $http, static function (int $s): void {
        }), $fees->nightlyFrom('vapeandgo'), '2026-11-24', 'nightly');
        $times['nightly_run'] = [round(microtime(true) - $t, 2), 'rows' => $nightly['read'], 'windows' => $nightly['windows'], 'phases_s' => $nightly['seconds']];

        // a rebuild of every day from scratch gives the same pence
        $t = microtime(true);
        for ($d = '2026-09-30'; $d <= '2026-11-25'; $d = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d')) {
            self::assertSame([], array_values(array_filter(ProfitDays::differences(self::$db, $ch, $d), static fn (string $l): bool => !str_contains($l, 'row of zeros'))), $d);
            ProfitDays::rebuild(self::$db, $ch, $d);
        }
        $times['rebuild_57_days'] = round(microtime(true) - $t, 2);
        self::assertSame(array_values(array_filter($before, static fn (array $r): bool => (int) $r['fee_cost'] !== 0 || (int) $r['fee_other'] !== 0)),
            self::$db->all('SELECT day, fee_cost, fee_other FROM profit_day WHERE channel_id = ? AND (fee_cost <> 0 OR fee_other <> 0) ORDER BY day', [$ch]));
        // Electrofag switched on from 1 Oct: the next decisions put its rows on its own orders and line, Vape and Go's days unchanged
        $vpgDays = self::$db->all('SELECT day, fee_cost, fee_other FROM profit_day WHERE channel_id = ? ORDER BY day', [$ch]);
        $this->setting('profit.paypal_from_electrofag', '"2026-10-01"');
        $chunks = [];
        $t = microtime(true);
        $n = $fees->locked('vapeandgo', static fn (): int => $fees->decideAll(Caller::system('paypal_replay'), 'vapeandgo', null));
        $times['electrofag_switched_on'] = [round(microtime(true) - $t, 2), 'decided' => $n, 'chunks' => count($chunks), 'chunk_max_s' => $chunks === [] ? 0 : round(max($chunks), 3)];
        self::assertSame(61190, (int) self::$db->value("SELECT SUM(alloc_pence) FROM paypal_txn WHERE kind = 'sale' AND alloc_channel = ? AND initiated_at >= '2026-10-01' "
            . "AND initiated_at < '2026-11-01'", [$ef]), 'Electrofag\'s October sales\' fees, on Electrofag');
        self::assertSame($vpgDays, self::$db->all('SELECT day, fee_cost, fee_other FROM profit_day WHERE channel_id = ? ORDER BY day', [$ch]));
        self::assertDaysTie($ef);
        $times['electrofag_money'] = self::$db->all("SELECT kind, alloc_dest, COUNT(*) AS n, SUM(alloc_pence) AS p FROM paypal_txn WHERE alloc_channel = ? GROUP BY kind, alloc_dest "
            . 'ORDER BY kind, alloc_dest', [$ef]);
        $t = microtime(true);
        $inv = \CW\Profit\PaypalInvariants::check(self::$db);
        $times['paypal_invariants'] = [round(microtime(true) - $t, 2), 'findings' => count($inv)];
        self::assertSame([], $inv);
        $out = getenv('CW_PAYPAL_REPLAY_TIMES');
        if (is_string($out) && $out !== '') {
            file_put_contents($out, json_encode($times, JSON_PRETTY_PRINT) . "\n");
        }
    }
}
