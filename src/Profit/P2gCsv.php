<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Clock;
use CW\CwException;
use CW\Output\CsvReader;

/**
 * Parcel2Go's "My Orders" export (CSV), read STRICTLY by its column names (docs/decisions.md PGL8): the lines and their tracking numbers for
 * the backfill (November: more lines than the API's recent list of 25) and whenever the job says lines may have been missed. Columns seen on
 * 24 Nov 2026 (owner's Chrome): Reference, MarketplaceStatus, Created, Source, Customer, Postcode, Country, Product, Value, Service, Sku,
 * Weight, L/W/H, Tags, TrackingStatus, TrackingNumber, CustomerReference. Kept: Reference (P2G<digits>), Created, Country, Service,
 * TrackingNumber. Customer, Postcode, Product, Value, Sku, Tags and CustomerReference are never read into anything; the file is not kept.
 * A row whose Reference is not a P2G number, or whose Created is not a date and time, refuses the WHOLE file (its row number, never its
 * content). Several rows of one Reference are its parcels.
 */
final class P2gCsv
{
    public const MAX_BYTES = 5_000_000;
    public const MAX_ROWS = 20_000;
    /** The columns read (CsvReader's lower-case names; "Tracking Number" and "TrackingNumber" are the same column). */
    public const NEED = ['reference', 'created', 'trackingnumber'];
    private const ALIASES = ['tracking_number' => 'trackingnumber', 'tracking_no' => 'trackingnumber'];

    /**
     * @return array{rows: int, sha256: string, lines: array<int, array{order_line_id: int, paid_at: string, country: ?string, service_slug: ?string,
     *   tracking: list<?string>}>}
     */
    public static function read(string $path): array
    {
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new CwException('p2g_csv', 'the Parcel2Go export is missing or larger than ' . self::MAX_BYTES . ' bytes: nothing was read', 413);
        }
        $sha = hash_file('sha256', $path);
        $t = CsvReader::table($path, self::MAX_BYTES, self::MAX_ROWS);
        $cols = [];
        foreach ($t['header'] as $h) {
            $cols[self::ALIASES[$h] ?? $h] = $h;
        }
        foreach (self::NEED as $c) {
            if (!isset($cols[$c])) {
                throw new CwException('p2g_csv', "the Parcel2Go export has no column {$c}: is it the My Orders export? Nothing was read", 400, ['column' => $c]);
            }
        }
        $lines = [];
        $rows = 0;
        foreach ($t['rows'] as $n => $r) {
            $rows++;
            $ref = strtoupper(trim($r[$cols['reference']] ?? ''));
            if (preg_match('/^P2G([0-9]{6,12})$/D', $ref, $m) !== 1) {
                throw new CwException('p2g_csv', "row {$n} of the Parcel2Go export has no P2G number in Reference: nothing was read", 400, ['row' => $n]);
            }
            $olid = (int) $m[1];
            $paid = self::created(trim($r[$cols['created']] ?? ''));
            if ($paid === null) {
                throw new CwException('p2g_csv', "row {$n} of the Parcel2Go export has a Created that is not a date and time: nothing was read", 400, ['row' => $n]);
            }
            $raw = trim($r[$cols['trackingnumber']] ?? '');
            $tracking = $raw === '' ? null : P2gApi::trackingNo($raw);
            $country = isset($cols['country']) ? strtoupper(trim($r[$cols['country']] ?? '')) : '';
            $service = isset($cols['service']) ? P2gApi::slug(trim($r[$cols['service']] ?? ''), 64) : null;
            if (isset($lines[$olid])) {
                $lines[$olid]['tracking'][] = $tracking;
                continue;
            }
            $lines[$olid] = ['order_line_id' => $olid, 'paid_at' => $paid, 'country' => preg_match('/^[A-Z]{3}$/D', $country) === 1 ? $country : null,
                'service_slug' => $service, 'tracking' => [$tracking]];
        }
        if ($lines === []) {
            throw new CwException('p2g_csv', 'the Parcel2Go export has no rows: nothing was read', 400);
        }
        ksort($lines);
        return ['rows' => $rows, 'sha256' => (string) $sha, 'lines' => $lines];
    }

    /** "24/11/2026 13:05", "24/11/2026 13:05:09", "2026-11-24 13:05:09", "2026-11-24T13:05:09", or the export page's own month-first layout with
     *  AM/PM, "11/26/2026 9:12:34 PM" (27 Nov 2026, every row of a real export: AM/PM marks it, so it is never read day first) (UK time) in UTC,
     *  or null. Each must print back exactly as given (no overflow: a 13th month or a 25:00 is refused). */
    public static function created(string $s): ?string
    {
        $uk = new \DateTimeZone('Europe/London');
        foreach (['!d/m/Y H:i', '!d/m/Y H:i:s', '!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d\TH:i:s', '!n/j/Y g:i:s A'] as $f) {
            $d = \DateTimeImmutable::createFromFormat($f, $s, $uk);
            if ($d !== false && \DateTimeImmutable::getLastErrors() === false && $d->format(substr($f, 1)) === $s) {
                return $d->setTimezone(Clock::utc())->format('Y-m-d H:i:s.u');
            }
        }
        return null;
    }
}
