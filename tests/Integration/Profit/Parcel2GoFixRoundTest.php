<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Profit\DeliveryBills;
use CW\Profit\DeliveryInvariants;
use CW\Profit\P2gApi;
use CW\Profit\P2gInvoice;
use CW\Profit\Parcel2Go;
use CW\Profit\RoyalMailBills;
use CW\Tests\Support\DpdCsv;
use CW\Tests\Support\FakeP2gHttp;
use CW\Tests\Support\FakePdfText;
use CW\Tests\Support\P2gFixtures;
use CW\Tests\Support\P2gTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW-1160, the final review's fix round (Codex astra H1-H8, M9-M15; Fable F1-F12; the lead's decisions 1-5 of 26 Nov 2026), each shown
 * failing first on 0c9b679: the page's and the tool's totals through each row's own allocations; another carrier's later cost on a Parcel2Go
 * parcel kept and said (needs a look, DB15) while Royal Mail's undo count is its own; the reader's price rows checked against the label's own
 * service and basket, an Extra Payment of a top-up refused; an unpaid label priced by its basket; a duplicate in the list; one bad label never
 * stops the run; the printed parcel count gives the label its slots; the gap kept with the labels and cleared only by an export that covers it;
 * every main-shop outcome before the shop's start is before CW start; an invoice refreshed by its basket; 404 tried again, an API refusal due
 * again a day later; an export's number replaces an unpinned API number; the verify resolves again without the pins it wrote; the tool's control
 * modes exit 4 when busy and its options are strict.
 */
final class Parcel2GoFixRoundTest extends P2gTestCase
{
    private const EVRI = 'Evri Europe Standard Parcelshop';
    private const RM = 'Royal Mail Tracked 48 Small Parcel';

    /** One run with a route of the test's own (Parcel2Go's answers scripted). @param \Closure(string, string): array{0: int, 1: mixed} $route */
    private function pullRoute(\Closure $route, \CW\Profit\P2gPdfText $pdf, string $key = 'p2g_vapeandgo', ?float $deadline = null): array
    {
        $http = new FakeP2gHttp($route);
        $api = new P2gApi(P2gFixtures::key($key), $http, static function (int $s): void {
        });
        return [$this->p2g()->run(self::sys(), $key, $api, $pdf, $deadline), $http];
    }

    private static function invoiceAnswer(int $tx): array
    {
        return [200, ['OrderId' => $tx, 'Base64EncodedInvoice' => base64_encode(P2gFixtures::pdf($tx))]];
    }

    public function testThePageAndTheToolsTotalsAreTheBillsNet(): void
    {
        // F1 / M14: a basket of two labels and an Extra Payment of a third: the account's money by where it went adds up to the bills' net (it was
        // counted once per label of the basket: k times)
        $this->on();
        self::order($this->vpg, '1570101', '2026-11-23T09:00:00Z', ['H00TST0000000101' => '2026-11-24T09:30:00Z']);
        $this->pull([P2gFixtures::apiLine(150000103, 95000103, '2026-11-20T10:00:00Z')], [95000103 => P2gFixtures::invoiceText(95000103, '20/11/2026',
            [[150000103, self::EVRI, 1, 400, 0]])], [150000103 => ['H00TST0000000103']]);
        $this->pull([P2gFixtures::apiLine(150000101, 95000101, '2026-11-24T10:00:00Z'), P2gFixtures::apiLine(150000102, 95000101, '2026-11-24T10:00:00Z')],
            [95000101 => P2gFixtures::invoiceText(95000101, '24/11/2026', [[150000101, self::EVRI, 1, 300, 0], [150000102, self::EVRI, 1, 500, 0],
                [150000103, 'Extra Payment', 1, 200, 0]])], [150000101 => ['H00TST0000000101'], 150000102 => ['H00TST0000000102']]);
        $s = Parcel2Go::status(self::$db, 'p2g_vapeandgo');
        self::assertSame(1400, $s['bills_net']);
        self::assertSame(1400, array_sum(array_column($s['money'], 'pence')), 'the money by where it went = the bills\' net (each row once)');
        $byDest = [];
        foreach ($s['money'] as $m) {
            $byDest[$m['dest'] . '|' . $m['how']] = [$m['lines'], $m['pence']];
        }
        self::assertSame(['order|label' => [1, 300], 'shop|no_parcel' => [2, 1100]], $byDest, 'each label counted once, with its own rows');
        $this->assertClean();
    }

    public function testAnotherCarriersLaterCostOnAParcel2GoParcelIsKeptAndSaid(): void
    {
        // H1 / F10 (the lead's decision 2): the DPD and Royal Mail matchers are not changed; when DPD's bill later puts its cost on a parcel Parcel2Go's
        // money is on, both real costs stay and the health alert and DB15 name the parcel
        $this->on();
        self::order($this->vpg, '1570110', '2026-11-23T09:00:00Z', ['15501234567811' => '2026-11-24T09:30:00Z'], 'dpd');
        $this->pull([P2gFixtures::apiLine(150000110, 95000110, '2026-11-24T10:00:00Z', 1, 'OrderProcess', 'DPD Next Day')], [95000110 => P2gFixtures::invoiceText(95000110,
            '24/11/2026', [[150000110, 'DPD Next Day', 1, 600, 120]])], [150000110 => ['15501234567811']]);
        self::assertSame(600, DeliveryBills::orderCost(self::$db, $this->vpg, '1570110'));
        (new DeliveryBills(self::$db))->upload(self::sys(), 'dpd', DpdCsv::file([DpdCsv::line('24/11/2026', '15501234567811', 499)]));
        self::assertSame(600 + 499, DeliveryBills::orderCost(self::$db, $this->vpg, '1570110'), 'both real costs stay');
        $look = array_values(array_filter(Parcel2Go::health(self::$db, new \DateTimeImmutable('2026-11-25 12:00:00', new \DateTimeZone('UTC'))),
            static fn (array $p): bool => $p['kind'] === 'p2g_needs_look'));
        self::assertCount(1, $look);
        self::assertStringContainsString('1570110', $look[0]['detail'], 'the order is named, to look at');
        self::assertNotSame([], array_filter(DeliveryInvariants::check(self::$db), static fn (string $l): bool => str_starts_with($l, 'DB15') && str_contains($l, '1570110')));
        $this->expectedInvariants = ['DB15 parcel odl:15701100 of order 1570110 '];
    }

    public function testRoyalMailsUndoCountsOnlyItsOwnAllocations(): void
    {
        // F12 (RoyalMailBills.php:565): Parcel2Go's live allocations are not Royal Mail's to undo
        $this->on();
        $this->pull([P2gFixtures::apiLine(150000120, 95000120, '2026-11-24T10:00:00Z')], [95000120 => P2gFixtures::invoiceText(95000120, '24/11/2026',
            [[150000120, self::EVRI, 1, 300, 0]])], [150000120 => ['H00TST0000000120']]);
        $rm = new RoyalMailBills(self::$db);
        $rm->setHold(self::sys(), null, 'rmundo-p2g-0001');
        try {
            self::assertSame(['loading' => 0, 'live' => 0, 'unended' => 0], $rm->cleanupForUndo(self::sys(), 'rmundo-p2g-0001'));
        } finally {
            $rm->setHold(self::sys(), 'rmundo-p2g-0001', null);
        }
    }

    public function testAPriceRowMustBeTheLabelsOwnServiceAndBasketAndATopUpsExtraIsRefused(): void
    {
        // H3 / F2 / M10 (the lead's decision 4)
        $this->on();
        $acct = Parcel2Go::account(self::$db, 'p2g_vapeandgo');
        $this->pull([P2gFixtures::apiLine(150000130, 95000130, null, 1, 'OrderProcess', self::RM), P2gFixtures::apiLine(150000131, null, null, 1, 'AutoTopup'),
            P2gFixtures::apiLine(150000132, 95000132, null)]);
        $code = static function (\Closure $fn): string {
            try {
                $fn();
            } catch (CwException $e) {
                return $e->errorCode . ':' . ($e->detail['refuse'] ?? '');
            }
            return 'stored';
        };
        self::assertSame('p2g_bill_conflict:service_differs', $code(fn () => $this->p2g()->store(self::sys(), $acct, P2gInvoice::read(P2gFixtures::invoiceText(95000130, '24/11/2026',
            [[150000130, self::EVRI, 1, 300, 0]])))), 'the label is Royal Mail on Parcel2Go\'s list: an Evri price row is not its price');
        self::assertSame('p2g_bill_conflict:other_basket', $code(fn () => $this->p2g()->store(self::sys(), $acct, P2gInvoice::read(P2gFixtures::invoiceText(95000999, '24/11/2026',
            [[150000132, self::EVRI, 1, 300, 0]])))), 'its service matches but it is in another basket');
        self::assertSame('p2g_bill_conflict:topup', $code(fn () => $this->p2g()->store(self::sys(), $acct, P2gInvoice::read(P2gFixtures::invoiceText(95000132, '24/11/2026',
            [[150000132, self::EVRI, 1, 300, 0], [150000131, 'Extra Payment', 1, 50000, 0]])))), 'an Extra Payment of a top-up');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM carrier_invoice WHERE carrier = 'parcel2go'"), 'nothing stored');
        self::assertSame('stored', $code(fn () => $this->p2g()->store(self::sys(), $acct, P2gInvoice::read(P2gFixtures::invoiceText(95000130, '24/11/2026',
            [[150000130, self::RM, 1, 300, 60]])))));
    }

    public function testAnUnpaidLabelPricedByItsBasketADuplicateInTheListAndOneBadLabel(): void
    {
        // H6 / F3: a label the list showed unpaid is priced by its sibling's basket (paid_at and its state in one write); the same label twice in
        // the list; a label whose handling throws never stops the run
        $this->on();
        $this->pull([P2gFixtures::apiLine(150000140, 95000140, null)]);
        self::assertSame('unpaid', self::p2gLine(150000140)['price_state']);
        $text = P2gFixtures::invoiceText(95000140, '24/11/2026', [[150000140, self::EVRI, 1, 300, 0], [150000141, self::EVRI, 1, 500, 0]]);
        $pdf = new class ($text) implements \CW\Profit\P2gPdfText {
            public function __construct(private readonly string $text)
            {
            }

            public function text(string $pdf): string
            {
                return $pdf === P2gFixtures::pdf(95000140) ? $this->text : throw new \RuntimeException('a fault in CW while reading this label');
            }
        };
        $sibling = P2gFixtures::apiLine(150000141, 95000140, '2026-11-24T10:00:00Z');
        $bad = P2gFixtures::apiLine(150000142, 95000142, '2026-11-24T09:00:00Z');
        [$r] = $this->pullRoute(static fn (string $m, string $p): array => match (true) {
            $p === '/api/me/orders/detail' => [200, ['Orders' => [$sibling, $sibling, $bad]]],
            str_contains($p, '/api/invoices/95000140?') => self::invoiceAnswer(95000140),
            str_contains($p, '/api/invoices/95000142?') => self::invoiceAnswer(95000142),
            default => [404, ''],
        }, $pdf);
        self::assertSame('ok', $r['result']);
        self::assertSame(['priced', '2026-11-24'], [self::p2gLine(150000140)['price_state'], self::p2gLine(150000140)['paid_day']]);
        self::assertSame('priced', self::p2gLine(150000141)['price_state']);
        self::assertSame(['due', 'error'], [self::p2gLine(150000142)['price_state'], self::p2gLine(150000142)['refuse_code']], 'its own fault, the run went on');
        $this->assertClean();
    }

    public function testAnUnpaidLabelTheExportBringsIsDueWithItsDay(): void
    {
        // H6: a label recorded unpaid that left the recent list: its export row gives its day and makes it due
        $this->on();
        $known = [];
        for ($i = 0; $i < 5; $i++) {
            $known[] = P2gFixtures::apiLine(150000150 + $i, null, null);
        }
        $this->pull($known);
        $dir = sys_get_temp_dir() . '/cw_p2g_fr_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            $f = $dir . '/e.csv';
            $h = fopen($f, 'wb');
            fputcsv($h, ['Reference', 'Created', 'Service', 'TrackingNumber'], ',', '"', '');
            for ($i = 0; $i < 5; $i++) {
                fputcsv($h, ['P2G' . (150000150 + $i), '22/11/2026 10:00', self::EVRI, 'H00TST00000001' . (50 + $i)], ',', '"', '');
            }
            fclose($h);
            $this->p2g()->import(self::sys(), 'p2g_vapeandgo', $f);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        self::assertSame(['due', '2026-11-22'], [self::p2gLine(150000150)['price_state'], self::p2gLine(150000150)['paid_day']]);
    }

    public function testThePrintedParcelCountGivesTheLabelItsSlots(): void
    {
        // H7: the list gave no Parcels (one slot); the invoice prints 2 parcels: two slots, £10.01 shared 501 + 500, the second waits for its number
        $this->on();
        self::order($this->vpg, '1570160', '2026-11-23T09:00:00Z', ['H00TST0000000160' => '2026-11-24T09:30:00Z']);
        $line = P2gFixtures::apiLine(150000160, 95000160, '2026-11-24T10:00:00Z');
        unset($line['Parcels']);
        $this->pull([$line], [95000160 => P2gFixtures::invoiceText(95000160, '24/11/2026', [[150000160, self::EVRI, 2, 1001, 0]])], [150000160 => ['H00TST0000000160']]);
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM p2g_parcel WHERE order_line_id = 150000160'));
        self::assertSame(['held||no_tracking' => 500, 'order|vpg|label' => 501], $this->where());
        $this->assertClean();
    }

    public function testTheGapIsKeptWithTheLabelsAndClearsOnlyWhenAnExportCoversIt(): void
    {
        // H8 / F11: the gap is written with the labels (a run killed later keeps it); an export of other days leaves it; one covering it clears it
        $this->on();
        $this->pull([P2gFixtures::apiLine(150000170, null, '2026-11-10T10:00:00Z')]);
        $full = [];
        for ($i = 1; $i <= 25; $i++) {
            $full[] = P2gFixtures::apiLine(150000200 + $i, null, '2026-11-2' . ($i % 4) . 'T10:00:00Z');
        }
        $seen = null;
        $this->clockAt = '2026-11-25 13:00:00';
        $this->pullRoute(static function (string $m, string $p) use ($full, &$seen): array {
            if ($p === '/api/me/orders/detail') {
                return [200, ['Orders' => $full]];
            }
            $seen ??= (string) self::$db->value('SELECT CONCAT(gap_seen, \'|\', gap_from, \'|\', gap_to) FROM p2g_run ORDER BY id DESC LIMIT 1');
            return [404, ''];
        }, new FakePdfText());
        self::assertSame('1|2026-11-10|2026-11-20', $seen, 'written with the labels, before anything else is asked');
        $gap = static fn (): bool => in_array('p2g_gap', array_column(Parcel2Go::health(self::$db, new \DateTimeImmutable('2026-11-25 14:00:00', new \DateTimeZone('UTC'))),
            'kind'), true);
        self::assertTrue($gap());
        $dir = sys_get_temp_dir() . '/cw_p2g_gap_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $csv = static function (array $days) use ($dir): string {
            $f = $dir . '/e' . count($days) . '.csv';
            $h = fopen($f, 'wb');
            fputcsv($h, ['Reference', 'Created', 'TrackingNumber'], ',', '"', '');
            foreach ($days as $n => $d) {
                fputcsv($h, ['P2G' . (150000201 + $n), $d . ' 10:00', ''], ',', '"', '');
            }
            fclose($h);
            return $f;
        };
        try {
            $this->clockAt = '2026-11-25 13:30:00';
            $this->p2g()->import(self::sys(), 'p2g_vapeandgo', $csv(['21/11/2026', '22/11/2026', '23/11/2026', '20/11/2026', '21/11/2026']));
            self::assertTrue($gap(), 'an export of 20-23 Nov does not cover 10-20 Nov');
            $this->p2g()->import(self::sys(), 'p2g_vapeandgo', $csv(['09/11/2026', '22/11/2026', '23/11/2026', '20/11/2026', '21/11/2026', '20/11/2026']));
            self::assertFalse($gap(), 'an export of 9-23 Nov covers it');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testEveryMainShopOutcomeBeforeTheShopsStartIsBeforeCwStart(): void
    {
        // M9 / F7: a Vape Big label of 10 Nov with no tracking number after the wait, and one on two CW parcels: before CW start, never Vape Big's line
        $this->on('p2g_vapebig');
        self::order($this->vpg, '1569100', '2026-11-09T09:00:00Z', ['YD000000190GB' => '2026-11-10T09:30:00Z']);
        self::order($this->vpg, '1569101', '2026-11-09T09:00:00Z', ['YD000000190GB' => '2026-11-10T09:40:00Z']);
        $this->clockAt = '2026-11-20 12:00:00';
        $this->pull([P2gFixtures::apiLine(150000180, 96000180, '2026-11-10T10:00:00Z', 1, 'OrderProcess', self::RM), P2gFixtures::apiLine(150000181, 96000180,
            '2026-11-10T10:00:00Z', 1, 'OrderProcess', self::RM)], [96000180 => P2gFixtures::invoiceText(96000180, '10/11/2026', [[150000180, self::RM, 1, 319, 64],
            [150000181, self::RM, 1, 319, 64]])], [150000181 => ['YD000000190GB']], 'p2g_vapebig');
        self::assertSame(['before_start|vb|before_start' => 638], $this->where());
        self::assertSame([], self::days($this->vb));
        $this->assertClean();
    }

    public function testARefreshedInvoiceReplacesTheOldOnlyWhenItReads(): void
    {
        // M11: a priced basket's invoice read again by its basket (--refresh-invoice): a corrected £7 replaces £5; an unreadable one leaves £5 live
        $this->on();
        $this->pull([P2gFixtures::apiLine(150000190, 95000190, '2026-11-24T10:00:00Z')], [95000190 => P2gFixtures::invoiceText(95000190, '24/11/2026',
            [[150000190, self::EVRI, 1, 500, 0]])], [150000190 => ['H00TST0000000190']]);
        $refresh = function (string $text): string {
            $api = new P2gApi(P2gFixtures::key(), new FakeP2gHttp(static fn (string $m, string $p): array => self::invoiceAnswer(95000190)), static function (int $s): void {
            });
            try {
                return $this->p2g()->refreshInvoice(self::sys(), 'p2g_vapeandgo', $api, new FakePdfText([P2gFixtures::pdf(95000190) => $text]), 95000190);
            } catch (CwException $e) {
                return $e->errorCode;
            }
        };
        self::assertSame('p2g_invoice_refused', $refresh('not an invoice £1.00'));
        self::assertSame(500, (int) self::$db->value("SELECT net_pence FROM carrier_invoice WHERE carrier = 'parcel2go' AND status = 'live'"), 'the old stays live');
        self::assertSame('replaced', $refresh(P2gFixtures::invoiceText(95000190, '24/11/2026', [[150000190, self::EVRI, 1, 700, 0]])));
        self::assertSame(['700|live', '500|superseded'], array_map('strval', self::$db->column("SELECT CONCAT(net_pence, '|', status) FROM carrier_invoice WHERE carrier = 'parcel2go' "
            . 'ORDER BY id DESC')));
        $this->assertClean();
    }

    public function testA404IsTriedAgainAndAnApiRefusalIsDueAgainADayLater(): void
    {
        // F4: 404, 408, 409, 425 are a later try; a label refused by the API (403) is due again once a day
        $this->on();
        $this->clockAt = '2026-11-24 12:00:00';
        $this->pull([P2gFixtures::apiLine(150000200, 95000200, '2026-11-24T10:00:00Z'), P2gFixtures::apiLine(150000201, 95000201, '2026-11-24T10:00:00Z')]);
        self::assertSame(['due', 'api_404'], [self::p2gLine(150000200)['price_state'], self::p2gLine(150000200)['refuse_code']], '404: a later try');
        self::$db->exec("UPDATE p2g_line SET price_state = 'refused', refuse_code = 'api_403' WHERE order_line_id = 150000201");
        $this->clockAt = '2026-11-24 18:00:00';
        $this->pull([]);
        self::assertSame('refused', self::p2gLine(150000201)['price_state']);
        $this->clockAt = '2026-11-25 13:00:00';
        $this->pull([]);
        self::assertSame('due', self::p2gLine(150000201)['price_state'], 'a day later: due again');
    }

    public function testAnExportNumberReplacesAnApiNumberOnAnUnpinnedParcel(): void
    {
        // F8: the tracking API's number on a parcel no order took is replaced by the export's; a pinned parcel's never
        $this->on();
        $lines = [];
        for ($i = 0; $i < 5; $i++) {
            $lines[] = P2gFixtures::apiLine(150000210 + $i, null, null);
        }
        $tracking = [];
        for ($i = 0; $i < 5; $i++) {
            $tracking[150000210 + $i] = ['H00API00000002' . (10 + $i)];
        }
        self::$db->exec('INSERT INTO p2g_line (account_id, order_line_id, source, kind, paid_at, paid_day, price_state, first_at, updated_at) SELECT id, 150000210, '
            . "'api', 'parcel', '2026-11-24 10:00:00', '2026-11-24', 'due', NOW(6), NOW(6) FROM carrier_account WHERE key_name = 'p2g_vapeandgo'");
        self::$db->exec("INSERT INTO p2g_parcel (account_id, order_line_id, parcel_no, tracking_no, tracking_source) SELECT id, 150000210, 1, 'H00API0000000210', 'tracking_api' "
            . "FROM carrier_account WHERE key_name = 'p2g_vapeandgo'");
        $this->pull($lines);
        $dir = sys_get_temp_dir() . '/cw_p2g_trk_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            $f = $dir . '/e.csv';
            $h = fopen($f, 'wb');
            fputcsv($h, ['Reference', 'Created', 'TrackingNumber'], ',', '"', '');
            for ($i = 0; $i < 5; $i++) {
                fputcsv($h, ['P2G' . (150000210 + $i), '24/11/2026 10:00', 'H00CSV00000002' . (10 + $i)], ',', '"', '');
            }
            fclose($h);
            $this->p2g()->import(self::sys(), 'p2g_vapeandgo', $f);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        self::assertSame(['H00CSV0000000210', 'report'], array_values(array_map('strval', (array) self::$db->one('SELECT tracking_no, tracking_source FROM p2g_parcel '
            . 'WHERE order_line_id = 150000210'))));
    }

    public function testTheVerifyResolvesAgainWithoutThePinsOfItsOwnWrite(): void
    {
        // H5 / F9: a second CW parcel with the label's number after the plan: resolved again WITHOUT the pin the write made, it is two parcels
        $this->on();
        self::order($this->vpg, '1570220', '2026-11-23T09:00:00Z', ['H00TST0000000220' => '2026-11-24T09:30:00Z']);
        $this->pull([P2gFixtures::apiLine(150000220, 95000220, '2026-11-24T10:00:00Z')], [95000220 => P2gFixtures::invoiceText(95000220, '24/11/2026',
            [[150000220, self::EVRI, 1, 500, 0]])], [150000220 => ['H00TST0000000220']]);
        self::order($this->vb, '60220', '2026-11-23T09:00:00Z', ['H00TST0000000220' => '2026-11-24T09:40:00Z']);
        $acct = Parcel2Go::account(self::$db, 'p2g_vapeandgo');
        $line = self::p2gLine(150000220);
        $m = new \ReflectionMethod(Parcel2Go::class, 'planBill');
        $rows = [['invoice_id' => 0, 'row_no' => 1, 'order_line_id' => 150000220, 'net' => 500, 'day' => '2026-11-24', 'kind' => 'price']];
        $pinned = $m->invoke($this->p2g(), self::$db, $acct, $rows, [150000220 => $line]);
        $fresh = $m->invoke($this->p2g(), self::$db, $acct, $rows, [150000220 => $line], ['150000220:1' => true]);
        self::assertSame('label', $pinned['dests']['150000220:1']['how'], 'its pin');
        self::assertSame('two_parcels', $fresh['dests']['150000220:1']['how'], 'resolved again without the pin: two CW parcels have its number');
    }

    public function testTheToolsControlModesExitBusyAndItsOptionsAreStrict(): void
    {
        // H4 / F6: a control mode that finds the Parcel2Go lock held exits 4 (never 0); a misspelt option is a usage error (never the pull)
        $this->on();
        $other = TestDb::connect();
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', ['cw_p2g:' . TestDb::name()]));
        try {
            $r = self::cli('--account=p2g_vapeandgo', '--retry-refused');
            self::assertSame(4, $r['code'], $r['err']);
        } finally {
            $other->value('SELECT RELEASE_LOCK(?)', ['cw_p2g:' . TestDb::name()]);
        }
        $bad = self::cli('--account=p2g_vapeandgo', '--swich-off');
        self::assertSame(2, $bad['code'], 'unknown option: usage');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM p2g_run WHERE kind = \'pull\''), 'no pull ran');
    }

    /** @return array{code: int, out: string, err: string} */
    private static function cli(string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', "{$root}/bin/p2g_pull.php", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }
}
