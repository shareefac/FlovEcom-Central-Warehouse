<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Clock;
use CW\Expenses\Expenses;
use CW\Ops\AlertDispatch;
use CW\Profit\GpFees;
use CW\Profit\GpInvoices;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Settings;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW-1174 round 2 (docs/decisions.md GPF15, GPF16): the health alert of the card fees (the job not run for an hour, a month's invoice not
 * entered 12 days after the month's end, an expense that may be an entered invoice), in staff words; and a spread racing real snapshots of
 * the same days on another connection: no snapshot fails or waits past its own wait, and once the queued months are spread again the month
 * equals the invoice to the penny, the days are what a rebuild says and the nightly checks are clean.
 */
final class GpFeesHealthTest extends StockTestCase
{
    private static function sys(): Caller
    {
        return Caller::system('test');
    }

    private function set(string $key, string $value): void
    {
        (new Settings(self::$db))->set(self::sys(), $key, $value, 'set for the test');
    }

    /** @return list<string> the kinds the card fees say at $now */
    private static function kinds(\DateTimeImmutable $now): array
    {
        return array_column(GpFees::health(self::$db, $now), 'kind');
    }

    private function store(Caller $site, string $ref, string $paid, int $grand, int $version = 1000): void
    {
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, $ref, ['v' => 1, 'version' => $version, 'order_no' => '', 'status' => 'completed',
            'placed_at' => $paid, 'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => [], 'delivery' => null,
            'order_refund_inc' => 0, 'grand_inc' => $grand, 'parcels' => []], 'api');
    }

    public function testTheJobNotRunIsSaid(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $now = Clock::now();
        self::assertSame([], self::kinds($now->modify('+3 hours')), 'not switched on: not watched');
        $this->set('profit.gp_from_vpg_online', '2026-11-01');
        self::assertSame([], self::kinds($now->modify('+30 minutes')), 'just switched on: its first run follows');
        self::assertSame(['gp_not_run'], self::kinds($now->modify('+2 hours')), 'switched on two hours ago, never run');
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame([], self::kinds($now->modify('+50 minutes')));
        $late = GpFees::health(self::$db, $now->modify('+2 hours'));
        self::assertSame(['gp_not_run'], array_column($late, 'kind'), 'no good run for over an hour');
        self::assertSame('gp_not_run:vpg_online', $late[0]['key']);
        self::assertStringContainsString('Vape and Go Online', AlertDispatch::line($late[0]));
        self::assertSame($vpg->channelId, $late[0]['channel_id']);
    }

    public function testAMonthsInvoiceNotEnteredTwelveDaysAfterIsSaid(): void
    {
        $this->site('vapeandgo', 'live');
        $this->set('profit.gp_from_vpg_online', '2026-10-01');
        $this->set('profit.gp_merchant_vpg_online', '55829291');
        (new GpFees(self::$db))->run(self::sys());
        $at = static fn (string $uk): \DateTimeImmutable => new \DateTimeImmutable($uk . 'T12:00:00Z');
        self::assertNotContains('gp_invoice_overdue', self::kinds($at('2026-11-12')), 'October\'s invoice is due by 12 Nov');
        $p = array_values(array_filter(GpFees::health(self::$db, $at('2026-11-13')), static fn (array $p): bool => $p['kind'] === 'gp_invoice_overdue'));
        self::assertCount(1, $p);
        self::assertSame('2026-10', $p[0]['data']['months']);
        self::assertStringContainsString('2026-10', AlertDispatch::line($p[0]));
        (new GpInvoices(self::$db))->add(self::sys(), ['account' => 'vpg_online', 'month' => '2026-10', 'merchant_no' => '55829291', 'invoice_date' => '2026-10-31',
            'sales' => '0', 'net' => '25.00', 'vat' => '0', 'total' => '25.00']);
        self::assertNotContains('gp_invoice_overdue', self::kinds($at('2026-11-13')), 'entered');
        self::assertNotContains('gp_invoice_overdue', self::kinds($at('2026-12-12')), 'November\'s is due by 12 Dec');
        $this->set('profit.gp_invoice_late_days', '20');
        self::assertNotContains('gp_invoice_overdue', self::kinds($at('2026-12-15')), 'the setting moves it');
        self::assertContains('gp_invoice_overdue', self::kinds($at('2026-12-21')));
        $this->set('profit.gp_until_vpg_online', '2026-10-31');
        self::assertNotContains('gp_invoice_overdue', self::kinds($at('2026-12-21')), 'no month after its last day');
    }

    public function testAnExpenseThatMayBeAnEnteredInvoiceIsSaid(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->set('profit.gp_from_vpg_online', '2026-11-01');
        $this->set('profit.gp_merchant_vpg_online', '55829291');
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        (new GpFees(self::$db))->run(self::sys());
        (new GpInvoices(self::$db))->add(self::sys(), ['account' => 'vpg_online', 'month' => '2026-11', 'merchant_no' => '55829291', 'invoice_date' => '2026-11-30',
            'sales' => '50.00', 'net' => '1.00', 'vat' => '0', 'total' => '1.00', 'current_ok' => '1']);
        $now = Clock::now()->modify('+5 minutes');
        self::assertNotContains('gp_double_count', self::kinds($now));
        $fees = (int) self::$db->value("SELECT id FROM expense_type WHERE kind = 'expense' AND name = 'Fees'");
        (new Expenses(self::$db))->add(self::sys(), ['shop' => (string) $vpg->channelId, 'supplier' => 'GlobalPay', 'new_supplier' => '1', 'type' => (string) $fees,
            'invoice_date' => '2026-11-27', 'invoice_no' => '', 'net' => '7.00', 'vat' => '0', 'note' => '']);
        $p = array_values(array_filter(GpFees::health(self::$db, $now), static fn (array $p): bool => $p['kind'] === 'gp_double_count'));
        self::assertCount(1, $p);
        self::assertSame(1, $p[0]['data']['count']);
        self::assertStringContainsString('£7.00', AlertDispatch::line($p[0]));
    }

    public function testASpreadRacingSnapshotsOfTheSameDaysStaysToThePenny(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $ch = (int) $vpg->channelId;
        $this->set('profit.gp_from_vpg_online', '2026-11-01');
        $this->set('profit.gp_merchant_vpg_online', '55829291');
        // 1,500 card orders on 11 Nov (the size of the largest real day), counted by the job's day pass
        $rows = [];
        for ($i = 0; $i < 1500; $i++) {
            $rows[] = [$ch, (string) (8000000 + $i), 'completed', '2026-11-11 10:00:00', '2026-11-11', 'globalpay', 2000, 500 + ($i * 37) % 9500, 1,
                '2026-11-11 10:00:00', '2026-11-11 10:00:00'];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            self::$db->exec('INSERT INTO sale_order (channel_id, order_ref, status, paid_at, day, gateway, vat_bp, grand_inc, snapshot_version, first_at, updated_at) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')), array_merge(...$chunk));
        }
        $fees = new GpFees(self::$db);
        $fees->syncDay('vpg_online', '2026-11-11');
        $takings = (int) self::$db->value('SELECT SUM(base_pence) FROM gp_fee_order');
        $inv = new GpInvoices(self::$db);
        $form = ['account' => 'vpg_online', 'month' => '2026-11', 'merchant_no' => '55829291', 'invoice_date' => '2026-11-30', 'gap_ok' => '1', 'current_ok' => '1', 'vat' => '0',
            'sales' => sprintf('%d.%02d', intdiv($takings, 100), $takings % 100)];
        $id = $inv->add(self::sys(), $form + ['net' => '512.34', 'total' => '512.34'])['id'];
        // the snapshot job on another connection: 60 card orders made and changed again and again (other amounts, moved between 11 and 12 Nov)
        $pipes = [];
        $worker = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', __DIR__ . '/gp_snapshot_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, dirname(__DIR__, 3), ['CW_SLOT' => (string) getenv('CW_SLOT'), 'CW_DB_NAME' => TestDb::name()] + getenv());
        self::assertIsResource($worker);
        fwrite($pipes[0], json_encode(['channel' => $ch, 'refs' => 60, 'days' => ['2026-11-11', '2026-11-12'], 'until_ms' => 8000, 'gap_ms' => 15], JSON_THROW_ON_ERROR) . "\n");
        fclose($pipes[0]);
        self::assertSame('running', trim((string) fgets($pipes[1])));
        $spreads = [];
        $deadline = microtime(true) + 7.5;
        for ($k = 1; microtime(true) < $deadline; $k++) {
            $seen = (int) self::$db->value('SELECT version FROM gp_invoice WHERE id = ?', [$id]);
            $net = sprintf('512.%02d', 34 + $k % 60);
            $inv->change(self::sys(), $id, $seen, $form + ['net' => $net, 'total' => $net], 'the race, change ' . $k);
            $spreads[] = (string) self::$db->value('SELECT spread_state FROM gp_invoice WHERE id = ?', [$id]);
            $fees->run(self::sys());
        }
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = trim((string) stream_get_contents($pipes[2]));
        proc_close($worker);
        $res = json_decode((string) substr($out, (int) strrpos("\n" . $out, "\n")), true);
        self::assertIsArray($res, $out . ' ' . $err);
        self::assertGreaterThan(50, $res['stores'], 'the worker stored snapshots all the while');
        self::assertSame(0, $res['failed'], 'no snapshot failed or waited past its own wait: ' . json_encode($res['errors'] ?? []));
        self::assertGreaterThan(2, count($spreads), 'the invoice was spread again and again meanwhile');
        // once the queued months are spread again: the month to the penny, the days as a rebuild says, the nightly checks clean
        for ($i = 0; $i < 5 && ((int) self::$db->value('SELECT COUNT(*) FROM gp_due') > 0 || self::$db->value('SELECT spread_state FROM gp_invoice WHERE id = ?', [$id]) !== 'done'); $i++) {
            $fees->run(self::sys());
        }
        $net = (int) self::$db->value('SELECT net_pence FROM gp_invoice WHERE id = ?', [$id]);
        self::assertSame('done', (string) self::$db->value('SELECT spread_state FROM gp_invoice WHERE id = ?', [$id]));
        self::assertSame($net, (int) self::$db->value("SELECT SUM(fee_pence) FROM gp_fee_order WHERE state = 'actual' AND invoice_id = ?", [$id]),
            'every card order of the month carries its share: the month is the invoice to the penny');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM gp_fee_order WHERE state = 'estimate' AND day BETWEEN '2026-11-01' AND '2026-11-30'"));
        self::assertSame($net, (int) self::$db->value("SELECT SUM(gp_fee) FROM profit_day WHERE channel_id = ? AND day BETWEEN '2026-11-01' AND '2026-11-30'", [$ch]));
        foreach (['2026-11-11', '2026-11-12'] as $d) {
            self::assertSame([], array_values(array_filter(ProfitDays::differences(self::$db, $ch, $d), static fn (string $l): bool => !str_contains($l, 'row of zeros'))),
                "a rebuild of {$d} gives the same figures");
        }
    }
}
