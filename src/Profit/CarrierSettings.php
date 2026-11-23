<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;
use CW\Db;

/**
 * The carrier settings (0078; docs/decisions.md DL43-DL47) as the pooled bills service reads them: an account, its parties (each from a day),
 * its Click & Drop channels, its party rules, its carrier's products and weight bands. Read only; nothing here names an account, a shop or a
 * price (the owner, 23 Nov 2026, Q5: ONE account-generic build).
 */
final class CarrierSettings
{
    /**
     * An account with its main party's shop.
     *
     * @return array{id: int, carrier: string, account_no: string, name: string, bill_format: string, label_source: string, key_name: ?string,
     *   main_party_id: int, main_channel: int, state: string, family: string}
     */
    public static function account(Db $db, int $id): array
    {
        $a = $db->one('SELECT a.*, p.channel_id AS main_channel, c.family FROM carrier_account a JOIN delivery_party p ON p.id = a.main_party_id '
            . 'JOIN carrier c ON c.code = a.carrier WHERE a.id = ?', [$id]) ?? throw new CwException('not_found', 'there is no such carrier account', 404, ['field' => 'account']);
        if ($a['main_channel'] === null) {
            throw new CwException('bad_settings', "the main party of carrier account {$a['account_no']} has no shop in CW: tell the CW team", 500);
        }
        return ['id' => (int) $a['id'], 'carrier' => (string) $a['carrier'], 'account_no' => (string) $a['account_no'], 'name' => (string) $a['name'],
            'bill_format' => (string) $a['bill_format'], 'label_source' => (string) $a['label_source'], 'key_name' => $a['key_name'] === null ? null : (string) $a['key_name'],
            'main_party_id' => (int) $a['main_party_id'], 'main_channel' => (int) $a['main_channel'], 'state' => (string) $a['state'], 'family' => (string) $a['family']];
    }

    /** @return list<array<string, mixed>> the pooled accounts (Royal Mail's), by id */
    public static function pooledAccounts(Db $db): array
    {
        return array_map(static fn (array $r): array => self::account($db, (int) $r['id']),
            $db->all("SELECT a.id FROM carrier_account a JOIN carrier c ON c.code = a.carrier WHERE c.family = 'pooled' ORDER BY a.id"));
    }

    /**
     * The carrier's products: code => kind and the labels' service code.
     *
     * @return array<string, array{kind: string, service_code: ?string, name: string}>
     */
    public static function products(Db $db, string $carrier): array
    {
        $out = [];
        foreach ($db->all('SELECT product_code, kind, service_code, name FROM carrier_product WHERE carrier = ? ORDER BY product_code', [$carrier]) as $r) {
            $out[(string) $r['product_code']] = ['kind' => (string) $r['kind'], 'service_code' => $r['service_code'] === null ? null : (string) $r['service_code'],
                'name' => (string) $r['name']];
        }
        return $out;
    }

    /** @return array<string, list<int>> product => its bands' top weights (grams), ascending */
    public static function bands(Db $db, string $carrier): array
    {
        $out = [];
        foreach ($db->all('SELECT product_code, max_grams FROM carrier_band WHERE carrier = ? ORDER BY product_code, band_no', [$carrier]) as $r) {
            $out[(string) $r['product_code']][] = (int) $r['max_grams'];
        }
        return $out;
    }

    /**
     * The parties the account serves: party => its shop, the day it joined CW, and the days the account serves it from.
     *
     * @return array<int, array{code: string, name: string, channel_id: ?int, channel_from: ?string, from: list<string>}>
     */
    public static function parties(Db $db, int $accountId): array
    {
        $out = [];
        foreach ($db->all('SELECT p.id, p.code, p.name, p.channel_id, p.channel_from, ap.from_day FROM carrier_account_party ap JOIN delivery_party p ON p.id = ap.party_id '
            . 'WHERE ap.account_id = ? ORDER BY p.id, ap.from_day', [$accountId]) as $r) {
            $out[(int) $r['id']] ??= ['code' => (string) $r['code'], 'name' => (string) $r['name'], 'channel_id' => $r['channel_id'] === null ? null : (int) $r['channel_id'],
                'channel_from' => $r['channel_from'] === null ? null : (string) $r['channel_from'], 'from' => []];
            $out[(int) $r['id']]['from'][] = (string) $r['from_day'];
        }
        return $out;
    }

    /** @return array<string, string> the account's Click & Drop channels: exact name => code */
    public static function channels(Db $db, int $accountId): array
    {
        $out = [];
        foreach ($db->all('SELECT code, name FROM carrier_channel WHERE account_id = ? ORDER BY code', [$accountId]) as $r) {
            $out[(string) $r['name']] = (string) $r['code'];
        }
        return $out;
    }

    /** @return list<array{channel_code: ?string, ref_form: ?string, party_id: ?int}> the party rules, first first */
    public static function rules(Db $db, int $accountId): array
    {
        return array_map(static fn (array $r): array => ['channel_code' => $r['channel_code'] === null ? null : (string) $r['channel_code'],
            'ref_form' => $r['ref_form'] === null ? null : (string) $r['ref_form'], 'party_id' => $r['party_id'] === null ? null : (int) $r['party_id']],
            $db->all('SELECT channel_code, ref_form, party_id FROM carrier_party_rule WHERE account_id = ? ORDER BY priority', [$accountId]));
    }

    /**
     * The party a label's channel and reference name by the account's rules (the first rule that fits; DL46): null when no rule fits or the
     * rule names nobody. Never a tracking number's prefix.
     *
     * @param list<array{channel_code: ?string, ref_form: ?string, party_id: ?int}> $rules
     */
    public static function ruleParty(array $rules, ?string $channel, ?string $reference): ?int
    {
        foreach ($rules as $r) {
            if ($r['channel_code'] !== null && $r['channel_code'] !== $channel) {
                continue;
            }
            if ($r['ref_form'] !== null && !self::refForm($r['ref_form'], $reference)) {
                continue;
            }
            return $r['party_id'];
        }
        return null;
    }

    /** Whether a reference has a form: hash_digits (# and digits) or digits_N (exactly N digits). */
    public static function refForm(string $form, ?string $reference): bool
    {
        if ($reference === null) {
            return false;
        }
        if ($form === 'hash_digits') {
            return preg_match('/^#[0-9]{1,10}$/D', $reference) === 1;
        }
        if (preg_match('/^digits_([1-9][0-9]?)$/D', $form, $m) === 1) {
            return preg_match('/^[0-9]{' . (int) $m[1] . '}$/D', $reference) === 1;
        }
        return false;
    }
}
