<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Db;
use CW\Profit\P2gKey;

/**
 * Parcel2Go in the tests (docs/decisions.md PGL1-PGL12): the two accounts as 0085 seeds them on live (TestDb::clean empties carrier_account, so
 * each test makes them), a key of made-up values, Parcel2Go's API served from scripted answers (FakeP2gHttp: never a request to Parcel2Go),
 * and invoice texts in the layout of the owner's real sample of 26 Nov 2026 (TX-<transaction>, a row per label and a name-and-address line
 * after it) with MADE-UP names, addresses, P2G numbers and prices only.
 */
final class P2gFixtures
{
    public const PERSON = 'Test Person';
    public const ADDRESS = '1 Test Street, Testtown, TT1 1TT, Germany...';

    /**
     * The two Parcel2Go accounts (off), their parties and the three shops each serves, as 0085 makes them.
     *
     * @return array{vpg: int, vb: int} carrier_account ids
     */
    public static function accounts(Db $db, int $vpg, int $vb, int $ef): array
    {
        $party = static fn (string $code, string $name, int $ch): int => (int) ($db->value('SELECT id FROM delivery_party WHERE code = ?', [$code])
            ?? $db->insert('INSERT INTO delivery_party (code, name, channel_id) VALUES (?, ?, ?)', [$code, $name, $ch]));
        $p = ['vapeandgo' => $party('vapeandgo', 'Vape and Go', $vpg), 'vapebig' => $party('vapebig', 'Vape Big', $vb), 'electrofag' => $party('electrofag', 'Electrofag', $ef)];
        $ids = [];
        foreach (['vpg' => ['p2g-vapeandgo', 'Parcel2Go (Vape and Go)', 'p2g_vapeandgo', 'vapeandgo'], 'vb' => ['p2g-vapebig', 'Parcel2Go (Vape Big)', 'p2g_vapebig', 'vapebig']]
            as $k => [$no, $name, $key, $main]) {
            $ids[$k] = $db->insert("INSERT INTO carrier_account (carrier, account_no, name, bill_format, label_source, key_name, main_party_id, state) VALUES ('parcel2go', ?, ?, "
                . "'p2g_api', 'p2g_api', ?, ?, 'off')", [$no, $name, $key, $p[$main]]);
            foreach ($p as $pid) {
                $db->exec("INSERT INTO carrier_account_party (account_id, party_id, from_day) VALUES (?, ?, '2020-01-01')", [$ids[$k], $pid]);
            }
        }
        return $ids;
    }

    public static function key(string $account = 'p2g_vapeandgo'): P2gKey
    {
        return P2gKey::fromArray(['client_id' => 'test-client-' . $account, 'client_secret' => 'Made-Up-Secret-0123', 'account' => $account,
            'api_base' => 'https://www.parcel2go.com'], $account);
    }

    /** One line of the API's recent list, with the personal fields Parcel2Go sends (made up) that CW must never keep. @return array<string, mixed> */
    public static function apiLine(int $olid, ?int $tx, ?string $paid, int $parcels = 1, string $source = 'OrderProcess', string $service = 'Evri Europe Standard Parcelshop'): array
    {
        $ps = [];
        for ($i = 1; $i <= $parcels; $i++) {
            $ps[] = ['Id' => $olid * 10 + $i, 'Contents' => 'Test contents ' . self::PERSON, 'PrintInStoreBarcode' => null, 'Links' => []];
        }
        return ['Courier' => 'Evri', 'Service' => $service, 'Source' => $source, 'ServiceSlug' => 'p2g-slug-' . strtolower(substr(md5($service), 0, 6)),
            'OrderLineId' => $olid, 'TransactionId' => $tx, 'PaidDate' => $paid, 'Parcels' => $ps, 'ValueOfContents' => 41.25,
            'DeliveryAddress' => ['ContactName' => self::PERSON, 'Email' => 'test.person@example.com', 'Phone' => '07700 900123', 'Property' => '1', 'Street' => 'Test Street',
                'Town' => 'Testtown', 'Postcode' => 'TT1 1TT', 'CountryIsoCode' => 'DEU', 'Country' => 'Germany'],
            'Hash' => 'hashOf' . $olid . 'abc=', 'Links' => [['Name' => 'invoice', 'Link' => 'https://www.parcel2go.com/doc/' . $olid]], 'Extras' => []];
    }

    /**
     * An invoice's text as `pdftotext -layout` gives the real one: rows [P2G number, description, parcels, net pence, VAT pence].
     *
     * @param list<array{0: int, 1: string, 2: int, 3: int, 4: int}> $rows
     */
    public static function invoiceText(int $tx, string $date, array $rows, ?int $net = null, ?int $vat = null, ?int $total = null): string
    {
        $m = static fn (int $p): string => '£' . number_format($p / 100, 2, '.', ',');
        $out = ["Parcel2Go.com Ltd                                                                                    Page 1 of 1", 'The Cube Coe Street Bolton BL3 6BU', '', '',
            ' Vape and Go Test', "                                                                       Invoice No.                  TX-{$tx}", ' Unit 1', '',
            " VAT No. GB000000000                                                   Date                          {$date}", '',
            ' Test Billing Street                                                   Payment Method                    PrePay', ' Testtown', ' TT1 1TT', '', '',
            ' Details                                                                         Parcels    Net         VAT',
            '                                                                                           Amount      Amount'];
        foreach ($rows as [$olid, $desc, $parcels, $n, $v]) {
            $left = " P2G{$olid} - {$desc}";
            $out[] = str_pad($left, 85) . str_pad((string) $parcels, 6) . str_pad($m($n), 13) . $m($v);
            $out[] = ' ' . self::PERSON . ' ' . self::ADDRESS;
        }
        $net ??= array_sum(array_column($rows, 3));
        $vat ??= array_sum(array_column($rows, 4));
        $total ??= $net + $vat;
        array_push($out, '', '', str_pad('', 65) . str_pad('Net', 38) . $m($net), '', str_pad('', 65) . str_pad('VAT', 40) . $m($vat), '',
            str_pad('', 65) . str_pad('Invoice Total', 38) . $m($total), '', 'VAT No. GB 000 0000 00 Registration No. 00000000');
        return implode("\n", $out) . "\n";
    }

    /** The made-up PDF bytes of a basket's invoice (FakePdfText knows its text). */
    public static function pdf(int $tx): string
    {
        return '%PDF-1.4 test invoice TX-' . $tx;
    }
}
