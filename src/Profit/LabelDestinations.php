<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;
use CW\Reports\ReportRefresh;

/**
 * Where a label's cost belongs (shipping plan v3 section 2; docs/decisions.md DL57, DL58). Reads only.
 *
 * On an ORDER only when ALL of these hold (else HELD, in no shop's profit, with the reason): (a) the label is a carrier_label row of THIS
 * account; (b) its tracking number is on exactly ONE CW parcel (not removed, with a label time whose UK day is at most WINDOW_BEFORE days
 * before the label's manifest day and at most WINDOW_AFTER after it: two_parcels); (c) that parcel's shop is a party the account serves on
 * that day (other_shop); (d) the parcel's carrier fits: royal_mail, or a manual label with a Royal Mail number (carrier); (e) when the label
 * carries a reference it is the parcel's order number (# and digits never is: ref_differs), and when its channel's rules name a party, that is
 * the parcel's shop (shop_differs). Never by the reference alone.
 *
 * A label on NO CW parcel goes by the account's party rules (CarrierSettings::ruleParty; channel, then the reference's form): a party with a
 * shop in CW on that day: before CW start (the day on or before the shop's start, or the reference an order number below its first order:
 * DL10, DL30) or the shop's "not on an order" (a label made on Click & Drop's own site); a party with no shop (or not yet in CW on that day):
 * its own line; no party, or a party the account does not serve that day: held (shop_unknown, not_served).
 *
 * A label whose price is already on an order stays on that order (sticky: as DPD's DL7, a cost put on an order is never moved to another).
 */
final class LabelDestinations
{
    /** The reasons a label is held (a fixed list: shown on the bill's page). */
    public const HELD = ['two_parcels', 'other_shop', 'carrier', 'ref_differs', 'shop_differs', 'shop_unknown', 'not_served'];
    /** What a plan names for an order it saw at two snapshots: no write passes with it (it is tried again). */
    public const CONFLICT = 'conflict';
    /** A Royal Mail tracking number's shape (a manual label in CW with one may be a Royal Mail label of the account: check d). */
    public const RM_TRACKING = '/^[A-Z]{2}[0-9]{9}GB$/D';

    /**
     * @param array<string, mixed> $account CarrierSettings::account()
     * @param array<int, array<string, mixed>> $labels by id: tracking_no, reference, channel_code, manifest_day
     * @param array<int, array<string, mixed>> $sticky label id => its order destination now (live allocations on an order)
     * @param array<string, string>|null $versions filled: each order a label was put on here (not sticky), "channel|lower-case ref" => the
     *   snapshot it was read at ("version|updated_at": DeliveryBills::orderVersions), so a write can tell the order changed since (DL68)
     * @return array<int, array{dest: string, channel_id: ?int, order_ref: ?string, parcel_ref: ?string, party_id: ?int, held_reason: ?string}>
     */
    public static function resolve(Db $db, array $account, array $labels, array $sticky, ?array &$versions = null): array
    {
        $versions = [];
        $parties = CarrierSettings::parties($db, (int) $account['id']);
        $rules = CarrierSettings::rules($db, (int) $account['id']);
        $byChannel = [];
        foreach ($parties as $pid => $p) {
            if ($p['channel_id'] !== null) {
                $byChannel[$p['channel_id']] = $pid;
            }
        }
        $need = [];
        foreach ($labels as $id => $l) {
            if (!isset($sticky[$id])) {
                $need[(string) $l['tracking_no']] = true;
            }
        }
        $parcels = self::parcels($db, array_keys($need));
        $out = [];
        $first = [];
        foreach ($labels as $id => $l) {
            if (isset($sticky[$id])) {
                $out[$id] = $sticky[$id];
                continue;
            }
            $day = (string) ($l['manifest_day'] ?? '');
            $fits = [];
            foreach ($parcels[(string) $l['tracking_no']] ?? [] as $p) {
                $gap = $day === '' ? 0 : DeliveryBills::daysBetween((string) $p['label_day'], $day);
                if ($gap >= -DeliveryBills::WINDOW_AFTER && $gap <= DeliveryBills::WINDOW_BEFORE) {
                    $fits[strtolower($p['channel_id'] . '|' . $p['order_ref'] . '|' . $p['parcel_ref'])] = $p;
                }
            }
            $ruled = CarrierSettings::ruleParty($rules, $l['channel_code'] ?? null, $l['reference'] ?? null);
            if (count($fits) > 1) {
                $out[$id] = self::held('two_parcels');
                continue;
            }
            if (count($fits) === 1) {
                $p = array_values($fits)[0];
                $pid = $byChannel[(int) $p['channel_id']] ?? null;
                $ref = $l['reference'] ?? null;
                $carrier = strtolower((string) $p['carrier']);
                $out[$id] = match (true) {
                    $pid === null || !self::serves($parties[$pid], $day) => self::held('other_shop'),
                    // royal_mail; or a label made by hand (carrier manual, or none given with the manual flag) with a Royal Mail number; never
                    // another carrier's parcel, manual flag or not (the Codex check r1, MEDIUM 9)
                    !($carrier === 'royal_mail' || (($carrier === 'manual' || ($carrier === '' && (int) $p['is_manual'] === 1))
                        && preg_match(self::RM_TRACKING, (string) $l['tracking_no']) === 1)) => self::held('carrier'),
                    $ref !== null && (str_starts_with((string) $ref, '#') || DeliveryBills::orderNumber((string) $ref) !== DeliveryBills::orderNumber((string) $p['order_no']))
                        => self::held('ref_differs'),
                    ($l['channel_code'] ?? null) !== null && $ruled !== null && $ruled !== $pid => self::held('shop_differs'),
                    default => ['dest' => 'order', 'channel_id' => (int) $p['channel_id'], 'order_ref' => (string) $p['order_ref'], 'parcel_ref' => (string) $p['parcel_ref'],
                        'party_id' => null, 'held_reason' => null],
                };
                if ($out[$id]['dest'] === 'order') {
                    // an order seen at two snapshots in one plan (its parcels are read 500 numbers a statement, it was stored again in between)
                    // names no snapshot a write could pass with: CONFLICT (the Codex check r1, HIGH 3)
                    $vk = (int) $p['channel_id'] . '|' . strtolower((string) $p['order_ref']);
                    $seen = $p['snapshot_version'] . '|' . $p['updated_at'];
                    $versions[$vk] = isset($versions[$vk]) && $versions[$vk] !== $seen ? self::CONFLICT : $seen;
                }
                continue;
            }
            if ($ruled === null) {
                $out[$id] = self::held('shop_unknown');
                continue;
            }
            if (!isset($parties[$ruled]) || !self::served($parties[$ruled], $day)) {
                $out[$id] = self::held('not_served');
                continue;
            }
            $party = $parties[$ruled];
            if ($party['channel_id'] === null || ($party['channel_from'] !== null && $day < $party['channel_from'])) {
                $out[$id] = ['dest' => 'party', 'channel_id' => null, 'order_ref' => null, 'parcel_ref' => null, 'party_id' => $ruled, 'held_reason' => null];
                continue;
            }
            $ch = (int) $party['channel_id'];
            $first[$ch] ??= null;
            $before = DeliveryBills::beforeStart($db, $ch, $day, $l['reference'] ?? null, $first[$ch]);
            $out[$id] = ['dest' => $before ? 'before_start' : 'shop', 'channel_id' => $ch, 'order_ref' => null, 'parcel_ref' => null, 'party_id' => null, 'held_reason' => null];
        }
        ksort($out);
        ksort($versions, SORT_STRING);
        return $out;
    }

    /**
     * The CW parcels with these tracking numbers (not removed, with a label time), with their order's number.
     *
     * @param list<string> $tracking
     * @return array<string, list<array<string, mixed>>>
     */
    public static function parcels(Db $db, array $tracking): array
    {
        $out = [];
        foreach (array_chunk(array_map('strval', $tracking), 500) as $chunk) {
            foreach ($db->all('SELECT p.channel_id, p.order_ref, p.parcel_ref, p.tracking_no, p.carrier, p.is_manual, p.label_at, o.order_no, o.snapshot_version, '
                . 'o.updated_at FROM sale_parcel p '
                . 'JOIN sale_order o ON o.channel_id = p.channel_id AND o.order_ref = p.order_ref WHERE p.tracking_no IN (' . implode(', ', array_fill(0, count($chunk), '?'))
                . ') AND p.removed_at IS NULL AND p.label_at IS NOT NULL', $chunk) as $p) {
                $out[strtoupper((string) $p['tracking_no'])][] = $p + ['label_day' => ReportRefresh::ukDay((string) $p['label_at'])];
            }
        }
        return $out;
    }

    /** Whether the account serves the party on the day (a FROM day on or before it). @param array<string, mixed> $party */
    private static function served(array $party, string $day): bool
    {
        foreach ($party['from'] as $from) {
            if ($day !== '' && $from <= $day) {
                return true;
            }
        }
        return false;
    }

    /** served(), and the party's shop is in CW on the day (a party that joined CW later: from its FROM day). @param array<string, mixed> $party */
    private static function serves(array $party, string $day): bool
    {
        return self::served($party, $day) && ($party['channel_from'] === null || $day >= $party['channel_from']);
    }

    /** @return array{dest: string, channel_id: null, order_ref: null, parcel_ref: null, party_id: null, held_reason: string} */
    private static function held(string $reason): array
    {
        return ['dest' => 'held', 'channel_id' => null, 'order_ref' => null, 'parcel_ref' => null, 'party_id' => null, 'held_reason' => $reason];
    }
}
