<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Db;

/**
 * The carrier settings of a Royal Mail account as 0078 seeds them on live (TestDb::clean empties them, so each test makes its own), and
 * SYNTHETIC labels in the shape the readers give them. Made-up tracking and order numbers only.
 */
final class RmFixtures
{
    public const ACCOUNT = '0706703000';
    public const CHANNELS = ['api' => 'Vape and Go NW Limited - API (Vape and Go NW Limited)',
        'woocommerce' => 'Vape and Go NW Limited - WooCommerce (Vape and Go NW Limited)', 'manual' => 'Manual Order Entry (Vape and Go NW Limited)'];

    /**
     * 0706703000: main party Vape and Go, serving Vape and Go, Electrofag and Hayati Vapes (no shop) from 2020, its channels and its rules
     * (manual names nobody, WooCommerce and # Hayati, 7 digits Vape and Go; with Electrofag: the API channel and 4 digits below its first CW
     * order number 5457 its before CW start, DL78).
     *
     * @return array{account: int, vpg_party: int, ef_party: ?int, hayati: int}
     */
    public static function account(Db $db, int $vpg, ?int $ef = null, string $state = 'active', string $number = self::ACCOUNT, bool $covered = true): array
    {
        $party = static fn (string $code, string $name, ?int $ch, ?int $first = null): int => (int) ($db->value('SELECT id FROM delivery_party WHERE code = ?', [$code])
            ?? $db->insert('INSERT INTO delivery_party (code, name, channel_id, first_order_no) VALUES (?, ?, ?, ?)', [$code, $name, $ch, $first]));
        $p = $party('vapeandgo', 'Vape and Go', $vpg);
        $e = $ef === null ? null : $party('electrofag', 'Electrofag', $ef, 5457);
        $h = $party('hayativapes', 'Hayati Vapes', null);
        // phase 1 is report-only (DL84): no account has a key name
        $a = $db->insert("INSERT INTO carrier_account (carrier, account_no, name, bill_format, label_source, key_name, main_party_id, state) VALUES ('royal_mail', ?, "
            . "'Royal Mail OBA', 'rm_oba_page', 'click_and_drop', NULL, ?, ?)", [$number, $p, $state]);
        foreach (array_filter([$p, $e, $h]) as $party) {
            $db->exec("INSERT INTO carrier_account_party (account_id, party_id, from_day) VALUES (?, ?, '2020-01-01')", [$a, $party]);
        }
        foreach (self::CHANNELS as $code => $name) {
            // a channel's name is one account's only (0078): another account's fixture names its own
            $db->exec('INSERT INTO carrier_channel (account_id, code, name) VALUES (?, ?, ?)', [$a, $code, $number === self::ACCOUNT ? $name : $name . ' ' . $number]);
        }
        $db->exec('INSERT INTO carrier_party_rule (account_id, priority, channel_code, ref_form, party_id) VALUES '
            . "(?, 1, 'manual', NULL, NULL), (?, 2, 'woocommerce', NULL, ?), (?, 3, NULL, 'hash_digits', ?), (?, 4, NULL, 'digits_7', ?)", [$a, $a, $h, $a, $h, $a, $p]);
        if ($e !== null) {
            $db->exec("INSERT INTO carrier_party_rule (account_id, priority, channel_code, ref_form, party_id, before_first) VALUES (?, 5, 'api', 'digits_4', ?, 1)", [$a, $e]);
        }
        if ($covered) {
            // its days covered by a report import (a day moves money only so: DL84): July to December 2026, so a test of the planning needs no
            // import; a test of the wait takes the days it needs away (uncover())
            self::covered($db, $a, '2026-07-01', '2026-12-31');
        }
        return ['account' => $a, 'vpg_party' => $p, 'ef_party' => $e, 'hayati' => $h];
    }

    /**
     * Labels stored as a source gives them, with no day covered (RoyalMailBills::importLabels is private: only the report's import reaches it in
     * the app, the Codex check r9 H2; a test of the planning gives its labels so).
     *
     * @param list<array<string, mixed>> $labels
     * @return array<string, mixed>
     */
    public static function labels(\CW\Profit\RoyalMailBills $rm, \CW\Caller $caller, int $account, array $labels, string $source = 'report'): array
    {
        return (new \ReflectionMethod($rm, 'importLabels'))->invoke($rm, $caller, $account, $labels, $source);
    }

    /**
     * A report's labels with the days it covers, as the import of a read report gives them (RoyalMailBills::importReportLabels is private).
     *
     * @param list<array<string, mixed>> $labels
     * @return array<string, mixed>
     */
    public static function report(\CW\Profit\RoyalMailBills $rm, \CW\Caller $caller, int $account, array $labels, string $from, string $to, ?string $sha256 = null,
        ?int $rows = null): array
    {
        return (new \ReflectionMethod($rm, 'importReportLabels'))->invoke($rm, $caller, $account, $labels, $from, $to,
            $sha256 ?? hash('sha256', json_encode([$labels, $from, $to], JSON_THROW_ON_ERROR)), $rows ?? count($labels), array_map(static fn (array $l): ?string
                => $l['manifest_day'] ?? null, $labels));
    }

    /**
     * A SYNTHETIC Click & Drop report (.xls) of these labels (made-up customer cells), as RoyalMailBills::importReport reads it.
     *
     * @param list<array<string, mixed>> $labels RmFixtures::label() shapes
     * @param list<string> $leaveOut columns left out (a Despatched Orders report has no Company and no Department)
     */
    public static function reportFile(array $labels, array $leaveOut = []): string
    {
        $head = ['Order number', 'Batch number', 'Manifest number', 'Channel', 'Channel reference', 'Despatch date', 'Manifest date', 'Customer', 'Company',
            'Address line 1', 'Address line 2', 'City', 'Postcode', 'Country', 'Weight (kg)', 'Package size', 'Shipping service', 'Service code', 'Tracking number',
            'Tracking status', 'Sales order number', 'Department', 'Notification email address', 'Notification mobile number', 'Tags'];
        $serial = static fn (?string $at): ?float => $at === null ? null : ((new \DateTimeImmutable(substr($at, 0, 19), new \DateTimeZone('Europe/London')))->getTimestamp()
            + (new \DateTimeImmutable(substr($at, 0, 19), new \DateTimeZone('Europe/London')))->getOffset()
            - (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))->getTimestamp()) / 86400;
        $rows = [];
        foreach ($labels as $i => $l) {
            $cells = ['Order number' => 779500.0 + $i, 'Batch number' => 'B1', 'Manifest number' => $l['manifest_no'] === null ? '' : 'M' . $l['manifest_no'],
                'Channel' => self::CHANNELS[$l['channel_code'] ?? 'api'] ?? 'Somebody else', 'Channel reference' => array_key_exists('reference_cell', $l) ? $l['reference_cell'] : (string) ($l['reference'] ?? ''),
                'Despatch date' => $serial($l['manifested_at'] ?? null), 'Manifest date' => $serial($l['manifested_at'] ?? null), 'Customer' => 'Testy McTestface',
                'Company' => '', 'Address line 1' => '1 Imaginary Road', 'Address line 2' => '', 'City' => 'Nowhereton', 'Postcode' => 'ZZ9 9ZZ', 'Country' => 'Neverland',
                'Weight (kg)' => ($l['weight_g'] ?? 300) / 1000, 'Package size' => 'Parcel', 'Shipping service' => 'x', 'Service code' => (string) ($l['service_code'] ?? ''),
                'Tracking number' => (string) $l['tracking_no'], 'Tracking status' => 'x', 'Sales order number' => (string) ($l['sales_order'] ?? ''), 'Department' => '',
                'Notification email address' => 'nobody@example.invalid', 'Notification mobile number' => '07000000000', 'Tags' => ''];
            $rows[] = $cells;
        }
        $cols = array_values(array_diff($head, $leaveOut));
        return XlsWriter::file([$cols, ...array_map(static fn (array $c): array => array_map(static fn (string $k): mixed => $c[$k], $cols), $rows)]);
    }

    /** The days a report import covers, as RoyalMailBills::importReportLabels() records them (its labels given apart). @return int the import */
    public static function covered(Db $db, int $account, string $from, string $to): int
    {
        $days = [];
        for ($d = $from; $d <= $to; $d = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d')) {
            $days[] = $d;
        }
        $id = $db->insert("INSERT INTO carrier_report_import (account_id, from_day, to_day, file_sha256, rows_read, labels, staff_user_id, actor, imported_at) "
            . "VALUES (?, ?, ?, ?, 0, 0, NULL, 'system:rm_test', '2026-12-31 00:00:00')", [$account, $from, $to, str_repeat('f', 64)]);
        foreach (array_chunk($days, 200) as $chunk) {
            $db->exec('INSERT IGNORE INTO carrier_day_cover (account_id, day, import_id) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?)')),
                array_merge(...array_map(static fn (string $d): array => [$account, $d, $id], $chunk)));
        }
        return $id;
    }

    /** These days covered by no import any more (a test of the wait). */
    public static function uncover(Db $db, int $account, string ...$days): void
    {
        foreach ($days as $d) {
            $db->exec('DELETE FROM carrier_day_cover WHERE account_id = ? AND day = ?', [$account, $d]);
        }
    }

    /**
     * A label as the readers give it (a Tracked one manifested at 18:00 on the day unless told).
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    public static function label(string $tracking, string $service, string $day, ?string $ref, array $over = []): array
    {
        return $over + ['tracking_no' => $tracking, 'source_id' => null, 'reference' => $ref, 'channel_code' => 'api', 'service_code' => $service, 'package' => 'parcel',
            'weight_g' => 300, 'created_on' => $day . ' 10:00:00.000000', 'manifested_at' => $day . ' 18:00:00.000000', 'manifest_no' => 2288, 'manifest_day' => $day,
            'sales_order' => null];
    }
}
