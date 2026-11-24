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
    public static function account(Db $db, int $vpg, ?int $ef = null, string $state = 'active', string $number = self::ACCOUNT, bool $pulled = true): array
    {
        $party = static fn (string $code, string $name, ?int $ch, ?int $first = null): int => (int) ($db->value('SELECT id FROM delivery_party WHERE code = ?', [$code])
            ?? $db->insert('INSERT INTO delivery_party (code, name, channel_id, first_order_no) VALUES (?, ?, ?, ?)', [$code, $name, $ch, $first]));
        $p = $party('vapeandgo', 'Vape and Go', $vpg);
        $e = $ef === null ? null : $party('electrofag', 'Electrofag', $ef, 5457);
        $h = $party('hayativapes', 'Hayati Vapes', null);
        $a = $db->insert("INSERT INTO carrier_account (carrier, account_no, name, bill_format, label_source, key_name, main_party_id, state) VALUES ('royal_mail', ?, "
            . "'Royal Mail OBA', 'rm_oba_page', 'click_and_drop', 'click_and_drop_0706703000', ?, ?)", [$number, $p, $state]);
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
        if ($pulled) {
            // its days read by a successful pull (an account with a key waits for that on every day: the Codex check r4, HIGH; DL80): July to
            // December 2026, so a test of the planning needs no pull; a test of the wait takes the days it needs away
            self::pulled($db, $a, '2026-07-01', '2026-12-31');
        }
        return ['account' => $a, 'vpg_party' => $p, 'ef_party' => $e, 'hayati' => $h];
    }

    /** The days a successful pull read, as RoyalMailBills::pull() records them. */
    public static function pulled(Db $db, int $account, string $from, string $to): void
    {
        $days = [];
        for ($d = $from; $d <= $to; $d = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d')) {
            $days[] = $d;
        }
        foreach (array_chunk($days, 200) as $chunk) {
            $db->exec('INSERT IGNORE INTO carrier_label_pull_day (account_id, day, read_at) VALUES ' . implode(', ', array_fill(0, count($chunk), "(?, ?, '2026-12-31 00:00:00')")),
                array_merge(...array_map(static fn (string $d): array => [$account, $d], $chunk)));
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
