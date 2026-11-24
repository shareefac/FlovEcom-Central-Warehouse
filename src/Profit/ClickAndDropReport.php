<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * Click & Drop's "Manifested Orders" report (or its "Despatched Orders" report: the same columns, some missing and others in other places),
 * the .xls its Reports page gives, read into label rows (docs/decisions.md DL48). Pure: no database, no file kept (its sha256 only).
 *
 * The first row names the columns; every column is read by its NAME (REQUIRED), never its place. The report also holds the customer, company,
 * address lines, city, postcode, country, notification email and mobile, department and tags: those columns are never read into anything
 * (their cells are dropped as they are met, XlsFile hands each cell over one by one) and nothing of them is returned or kept.
 *
 * Kept per label, each only in its known shape (anything else is kept as nothing): the tracking number (upper case, in Royal Mail's shape: two letters,
 * nine digits, GB; a row without one is skipped), Click & Drop's order number, the channel reference (up to 9 digits, or # and digits: the shop order the
 * label carried), the channel as the code of one of the account's known channels ("other" for any other name), the service code, the package
 * size (letter, large_letter, parcel, other), the declared weight in grams, the manifest number and its time and UK day (an Excel date: Click
 * & Drop's own clock, UK time), and the Royal Mail sales order of a Special Delivery manifest (10 digits; the Tracked labels carry a long id
 * there: not kept). A tracking number met twice: the first row kept, the second skipped.
 */
final class ClickAndDropReport
{
    /** The columns read, by their names in the report => the field they fill. */
    public const REQUIRED = ['Order number' => 'source_id', 'Manifest number' => 'manifest_no', 'Channel' => 'channel', 'Channel reference' => 'reference',
        'Manifest date' => 'manifested_at', 'Weight (kg)' => 'weight', 'Package size' => 'package', 'Service code' => 'service_code',
        'Tracking number' => 'tracking_no', 'Sales order number' => 'sales_order'];
    /** The package sizes kept (the report's words => CW's). */
    public const PACKAGES = ['parcel' => 'parcel', 'large letter' => 'large_letter', 'letter' => 'letter'];
    /** Excel's day 0 (its dates count days from it). */
    private const EPOCH = '1899-12-30';

    /**
     * @param array<string, string> $channels the account's Click & Drop channels: exact name => code (carrier_channel)
     * @return array{rows: int, skipped: int, labels: list<array{tracking_no: string, source_id: ?int, reference: ?string, channel_code: ?string,
     *   service_code: ?string, package: ?string, weight_g: ?int, created_on: null, manifested_at: ?string, manifest_no: ?int, manifest_day: ?string,
     *   sales_order: ?string, ref_unusable: bool}>, sha256: string, kind: string, row_days: list<?string>, foreign: int} kind: manifested (Company and
     *   Department columns there) or despatched; row_days: the manifest day of every row with a tracking number or a manifest date (null: no
     *   manifest date); foreign: the substantive rows of no channel or of one not the account's
     */
    public static function read(string $bytes, array $channels): array
    {
        $header = [];
        $want = null;
        $rows = [];
        $seen = [];
        XlsFile::firstSheet($bytes, static function (int $row, int $col, string|float|int|bool|null $value) use (&$header, &$want, &$rows, &$seen): void {
            if ($row === 0) {
                if (is_string($value)) {
                    $header[$col] = $value;
                }
                return;
            }
            $want ??= self::columns($header);
            $seen[$row] = true;
            if (isset($want[$col])) {
                $rows[$row][$want[$col]] = $value; // only the columns read: a personal cell is dropped here, as it is met
            }
        });
        $want ??= self::columns($header);
        ksort($rows);
        // the report's kind (the Codex check r9 H1; DL84): Click & Drop's Manifested Orders report has a Company and a Department column, its
        // Despatched Orders report neither; only the first covers days
        $names = array_map(static fn (string $h): string => strtolower(trim((string) preg_replace('/\s+/u', ' ', $h))), $header);
        $kind = in_array('company', $names, true) && in_array('department', $names, true) ? 'manifested' : 'despatched';
        // the manifest day of EVERY row with a tracking number or a manifest date, a row skipped below too (the Codex check r9 H3): null, a
        // tracking number with no manifest date; the import refuses a report with a row outside its From..To
        $rowDays = [];
        foreach ($rows as $r) {
            $at = self::when($r['manifested_at'] ?? null);
            if ($at !== null || trim(self::text($r['tracking_no'] ?? null)) !== '') {
                $rowDays[] = $at === null ? null : substr($at, 0, 10);
            }
        }
        // the channel of EVERY substantive row (any read cell not empty), before any row is skipped for its tracking number (the final review,
        // F4: a foreign report whose tracking numbers are all unusable would cover days with no label): a row of no channel or of one the account
        // does not have is counted, and the import refuses the report whole
        $foreign = 0;
        foreach ($rows as $r) {
            $any = false;
            foreach ($r as $v) {
                if (trim(self::text($v)) !== '') {
                    $any = true;
                    break;
                }
            }
            $channel = trim(self::text($r['channel'] ?? null));
            if ($any && ($channel === '' || !isset($channels[$channel]))) {
                $foreign++;
            }
        }
        $labels = [];
        $skipped = 0;
        $tracked = [];
        foreach ($rows as $r) {
            $l = self::label($r, $channels);
            if ($l === null || isset($tracked[$l['tracking_no']])) {
                $skipped++;
                continue;
            }
            $tracked[$l['tracking_no']] = true;
            $labels[] = $l;
        }
        $skipped += count($seen) - count($rows); // rows with none of the read columns: no tracking number either
        return ['rows' => count($seen), 'skipped' => $skipped, 'labels' => $labels, 'sha256' => hash('sha256', $bytes), 'kind' => $kind, 'row_days' => $rowDays,
            'foreign' => $foreign];
    }

    /**
     * The places of the columns read, from the first row's names.
     *
     * @param array<int, string> $header
     * @return array<int, string> column => field
     */
    private static function columns(array $header): array
    {
        $byName = [];
        foreach ($header as $col => $name) {
            $n = strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
            foreach (self::REQUIRED as $want => $field) {
                if ($n === strtolower($want)) {
                    if (isset($byName[$want])) {
                        throw self::refuse("the column '{$want}' is there twice");
                    }
                    $byName[$want] = $col;
                }
            }
        }
        foreach (array_keys(self::REQUIRED) as $want) {
            if (!isset($byName[$want])) {
                throw self::refuse("it has no column '{$want}': is it Click & Drop's Manifested Orders report?");
            }
        }
        $out = [];
        foreach ($byName as $want => $col) {
            $out[$col] = self::REQUIRED[$want];
        }
        return $out;
    }

    /**
     * One row's label, each field only in its known shape; null: no usable tracking number.
     *
     * @param array<string, string|float|int|bool|null> $r
     * @param array<string, string> $channels
     * @return array<string, mixed>|null
     */
    private static function label(array $r, array $channels): ?array
    {
        $tracking = strtoupper(trim(self::text($r['tracking_no'] ?? null)));
        if (preg_match('/^[A-Z]{2}[0-9]{9}GB$/D', $tracking) !== 1) { // Royal Mail's shape only (a phone number is never kept: DL72)
            return null;
        }
        $refCell = $r['reference'] ?? null;
        $ref = trim(self::text($refCell));
        $channel = trim(self::text($r['channel'] ?? null));
        $service = strtoupper(trim(self::text($r['service_code'] ?? null)));
        $package = strtolower(trim(self::text($r['package'] ?? null)));
        $sales = trim(self::text($r['sales_order'] ?? null));
        $manifestNo = null;
        if (preg_match('/^M?0*(\d{1,9})$/Di', trim(self::text($r['manifest_no'] ?? null)), $m) === 1) {
            $manifestNo = (int) $m[1];
        }
        $at = self::when($r['manifested_at'] ?? null);
        if ($at === null) {
            $manifestNo = null; // a manifest number with no usable time names no day: neither is kept
        }
        $source = self::text($r['source_id'] ?? null);
        return [
            'tracking_no' => $tracking,
            'source_id' => preg_match('/^[1-9][0-9]{0,17}$/D', $source) === 1 ? (int) $source : null,
            'reference' => preg_match('/^#?[0-9]{1,9}$/D', $ref) === 1 ? $ref : null,
            // a reference given but in no known shape: only this flag is kept, never the text (the final review, F6): such a label is never put on
            // an order by its tracking number alone (LabelDestinations: held, ref_unusable)
            // ... and a Boolean or an error in the cell is a value given too, never "no reference" (the astra re-check of b581e70, F6)
            'ref_unusable' => is_bool($refCell) || $refCell === XlsFile::ERROR_CELL || ($ref !== '' && preg_match('/^#?[0-9]{1,9}$/D', $ref) !== 1),
            'channel_code' => $channel === '' ? null : ($channels[$channel] ?? 'other'),
            // letters then a digit (TPL48HV, SD1): a word there is never a service (the Codex check r2, new MEDIUM 4)
            'service_code' => preg_match('/^[A-Z]{1,6}[0-9][A-Z0-9]{0,9}$/D', $service) === 1 ? $service : null,
            'package' => $package === '' ? null : (self::PACKAGES[$package] ?? 'other'),
            'weight_g' => self::grams($r['weight'] ?? null),
            'created_on' => null,
            'manifested_at' => $at,
            'manifest_no' => $manifestNo,
            'manifest_day' => $at === null ? null : substr($at, 0, 10),
            'sales_order' => preg_match('/^[0-9]{10}$/D', $sales) === 1 ? $sales : null,
        ];
    }

    /** A cell as text: a whole number without its ".0", a string as it is, anything else empty. */
    private static function text(string|float|int|bool|null $v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return is_finite($v) && floor($v) === $v && abs($v) < 1e15 ? sprintf('%.0f', $v) : (string) $v;
        }
        return is_string($v) ? $v : '';
    }

    /** Kilograms as grams (rounded); null: not a number, or below 0. */
    private static function grams(string|float|int|bool|null $v): ?int
    {
        if (is_string($v) && preg_match('/^\s*\d{1,6}(\.\d{1,6})?\s*$/D', $v) === 1) {
            $v = (float) $v;
        }
        if (!is_int($v) && !is_float($v)) {
            return null;
        }
        if (!is_finite((float) $v) || $v < 0 || $v > 1_000_000) {
            return null;
        }
        return (int) round($v * 1000);
    }

    /**
     * A manifest time as 'Y-m-d H:i:s.000000' (Click & Drop's clock, UK time, as given): an Excel date (days since 30 Dec 1899, to the
     * nearest second) or "dd/mm/yyyy hh:mm[:ss]"; null: neither.
     */
    private static function when(string|float|int|bool|null $v): ?string
    {
        $utc = new \DateTimeZone('UTC');
        if (is_int($v) || is_float($v)) {
            $v = (float) $v;
            if (!is_finite($v) || $v < 1 || $v > 2_958_465) {
                return null;
            }
            $secs = (int) round($v * 86400);
            return (new \DateTimeImmutable(self::EPOCH . ' 00:00:00', $utc))->modify('+' . intdiv($secs, 86400) . ' days')
                ->modify('+' . ($secs % 86400) . ' seconds')->format('Y-m-d H:i:s') . '.000000';
        }
        if (is_string($v) && preg_match('/^\s*(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?\s*$/D', $v, $m) === 1) {
            [, $d, $mo, $y, $h, $i] = $m;
            $s = $m[6] ?? '0';
            if (!checkdate((int) $mo, (int) $d, (int) $y) || (int) $h > 23 || (int) $i > 59 || (int) ($s === '' ? 0 : $s) > 59) {
                return null;
            }
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d.000000', (int) $y, (int) $mo, (int) $d, (int) $h, (int) $i, (int) ($s === '' ? 0 : $s));
        }
        return null;
    }

    private static function refuse(string $why): CwException
    {
        return new CwException('bad_file', 'Click & Drop report not read: ' . $why . (str_ends_with($why, '?') ? '' : '.') . ' Nothing was saved.', 422);
    }
}
