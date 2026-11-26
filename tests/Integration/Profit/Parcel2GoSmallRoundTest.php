<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\CwException;
use CW\Profit\P2gInvoice;
use CW\Profit\Parcel2Go;
use CW\Tests\Support\P2gFixtures;
use CW\Tests\Support\P2gTestCase;

/**
 * CW-1160, the small round after Fable's review of 45f71c1 + 5e6cc57 (G1-G10; the lead's decisions of 26 Nov 2026), each shown failing first on
 * 5e6cc57: a list naming another account's label is refused whole (G2); a label known only from the export is judged by its basket and parcels,
 * not by the export's service text (G3); one bill's failure in the replan does not stop the others (G7); a label before the shop's start with
 * its number known moves to an order when that order's parcel comes (G10).
 */
final class Parcel2GoSmallRoundTest extends P2gTestCase
{
    private const EVRI = 'Evri Europe Standard Parcelshop';
    private const RM = 'Royal Mail Tracked 48 Small Parcel';

    public function testAListNamingAnotherAccountsLabelIsRefusedWhole(): void
    {
        // G2: the key reads a list in which a label CW knows as the OTHER account's appears: nothing of the list is stored (whose key is it?)
        $this->on();
        $this->on('p2g_vapebig');
        $this->pull([P2gFixtures::apiLine(150000301, 96000301, null, 1, 'OrderProcess', self::RM)], [], [], 'p2g_vapebig');
        try {
            $this->pull([P2gFixtures::apiLine(150000302, 95000302, null), P2gFixtures::apiLine(150000301, 96000301, null, 1, 'OrderProcess', self::RM)]);
            self::fail('a list naming another account\'s label is refused');
        } catch (CwException $e) {
            self::assertSame('p2g_key_refused', $e->errorCode);
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM p2g_line WHERE order_line_id = 150000302'), 'nothing of the list stored');
        self::assertSame('failed', (string) self::$db->value("SELECT r.state FROM p2g_run r JOIN carrier_account a ON a.id = r.account_id WHERE a.key_name = 'p2g_vapeandgo' "
            . "AND r.kind = 'pull' ORDER BY r.id DESC LIMIT 1"));
    }

    public function testALabelKnownOnlyFromTheExportIsJudgedByItsBasketAndParcelsNotItsServiceText(): void
    {
        // G3: the export's Service text may not be the invoice's description: a label only the export brought is not refused for it
        $this->on();
        $known = [];
        for ($i = 0; $i < 5; $i++) {
            $known[] = P2gFixtures::apiLine(150000310 + $i, null, null);
        }
        $this->pull($known);
        $dir = sys_get_temp_dir() . '/cw_p2g_sr_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            $f = $dir . '/e.csv';
            $h = fopen($f, 'wb');
            fputcsv($h, ['Reference', 'Created', 'Service', 'TrackingNumber'], ',', '"', '');
            for ($i = 0; $i < 5; $i++) {
                fputcsv($h, ['P2G' . (150000310 + $i), '22/11/2026 10:00', self::EVRI, ''], ',', '"', '');
            }
            fputcsv($h, ['P2G150000315', '24/11/2026 10:00', 'Evri ParcelShop (Europe)', 'H00TST0000000315'], ',', '"', '');
            fclose($h);
            $this->p2g()->import(self::sys(), 'p2g_vapeandgo', $f);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        $acct = Parcel2Go::account(self::$db, 'p2g_vapeandgo');
        self::assertSame('stored', $this->p2g()->store(self::sys(), $acct, P2gInvoice::read(P2gFixtures::invoiceText(95000315, '24/11/2026',
            [[150000315, self::EVRI, 1, 300, 0]]))), 'the export-only label is priced by its basket\'s invoice');
        self::assertSame(['priced', 'report'], [self::p2gLine(150000315)['price_state'], self::p2gLine(150000315)['service_from']]);
        $this->assertClean();
    }

    public function testOneBillsFailureInTheReplanDoesNotStopTheOthers(): void
    {
        // G7: two bills with money on the shop's line; the orders come; placing the first fails: the second is placed, the run then fails (said)
        $this->on();
        $this->pull([P2gFixtures::apiLine(150000321, 95000321, '2026-11-24T10:00:00Z'), P2gFixtures::apiLine(150000322, 95000322, '2026-11-24T10:00:00Z')],
            [95000321 => P2gFixtures::invoiceText(95000321, '24/11/2026', [[150000321, self::EVRI, 1, 300, 0]]),
                95000322 => P2gFixtures::invoiceText(95000322, '24/11/2026', [[150000322, self::EVRI, 1, 500, 0]])],
            [150000321 => ['H00TST0000000321'], 150000322 => ['H00TST0000000322']]);
        self::assertSame(['shop|vpg|no_parcel' => 800], $this->where());
        self::order($this->vpg, '1570321', '2026-11-23T09:00:00Z', ['H00TST0000000321' => '2026-11-24T09:30:00Z']);
        self::order($this->vpg, '1570322', '2026-11-23T09:00:00Z', ['H00TST0000000322' => '2026-11-24T09:30:00Z']);
        $first = (int) self::$db->value("SELECT id FROM carrier_invoice WHERE invoice_no = 'TX-95000321'");
        self::$db->exec("CREATE TRIGGER p2g_sr_fail BEFORE INSERT ON carrier_allocation FOR EACH ROW BEGIN IF NEW.invoice_id = {$first} THEN "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a made-up failure of one bill'; END IF; END");
        try {
            try {
                $this->pull([]);
                self::fail('the run says the bill that failed');
            } catch (\Throwable) {
            }
        } finally {
            self::$db->exec('DROP TRIGGER IF EXISTS p2g_sr_fail');
        }
        self::assertSame(['order|vpg|label' => 500, 'shop|vpg|no_parcel' => 300], $this->where(), 'the other bill was placed');
        $this->pull([]);
        self::assertSame(['order|vpg|label' => 800], $this->where(), 'the next run places the one that failed');
        $this->assertClean();
    }

    public function testALabelBeforeTheShopsStartMovesToAnOrderWhenItsParcelComes(): void
    {
        // G10: a Vape Big account label of 10 Nov (before Vape Big's start) with its number known and no CW parcel yet: before CW start; the Vape
        // and Go order with that number comes later: the money goes to it
        $this->on('p2g_vapebig');
        $this->clockAt = '2026-11-12 12:00:00';
        $this->pull([P2gFixtures::apiLine(150000331, 96000331, '2026-11-10T10:00:00Z', 1, 'OrderProcess', self::RM)], [96000331 => P2gFixtures::invoiceText(96000331,
            '10/11/2026', [[150000331, self::RM, 1, 319, 64]])], [150000331 => ['YD000000331GB']], 'p2g_vapebig');
        self::assertSame(['before_start|vb|before_start' => 319], $this->where());
        self::order($this->vpg, '1569331', '2026-11-09T09:00:00Z', ['YD000000331GB' => '2026-11-11T09:30:00Z']);
        $this->clockAt = '2026-11-13 12:00:00';
        $this->pull([], [], [], 'p2g_vapebig');
        self::assertSame(['order|vpg|label' => 319], $this->where());
        $this->assertClean();
    }
}
