<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\CwException;
use CW\Db;
use CW\PurchaseOrders\PoLinesFile;
use CW\PurchaseOrders\PoMath;
use CW\Suppliers\SupplierItems;
use CW\Ui\Controller\PurchaseOrdersController;

/**
 * A document's lines as a standard grid (the owner, 6 Nov 2026: "purchase invoice and sales invoice, purchase order view style need to
 * standard feel, need also key shortcut: item new and delete, field switching, all need"; docs/decisions.md KG1-KG11): the server's half,
 * shared by the purchase order's and the supplier invoice's page (and, later, the sales invoice's). With the script, a line is filled in
 * place and every POST of the page sends ALL lines at once: the saved lines as before (each page's own fields), and the NEW lines in one
 * field, `new_lines`: JSON, a list of {si: one of the supplier's packs | sku: an item, q: the packs or qty as typed, p: the price as typed}.
 * One field however many lines (PHP's max_input_vars cannot cut them off, as the quick adjustment's `lines`, AG3); the server trusts none
 * of it: each page turns the lines into its service's lines and the service checks every rule, in the save's own transaction.
 * Without the script the field stays empty and the page works as before.
 */
final class DocGrid
{
    public const FIELD = 'new_lines';
    /** A typed figure is at most this long (a price box's maxlength). */
    private const TEXT_MAX = 16;

    /**
     * The new lines the form sent ($sent: the field as sent), in order. '' or none: no line. $strict (a save): anything but the script's shape is 400 bad_form, so a
     * line is never half read; more than twice the document's limit too (the service refuses past its limit in words; a page never sends
     * that many). Not $strict (a page drawn again): what can be read, at most twice the limit.
     *
     * @return list<array{si: ?int, sku: ?int, u: ?int, line: ?int, q: string, p: string}>
     */
    public static function read(mixed $sent, int $max, bool $strict = true): array
    {
        $raw = is_string($sent) ? trim($sent) : '';
        if ($raw === '') {
            return [];
        }
        $bad = static fn (): CwException => new CwException('bad_form', Words::ERROR['bad_form'], 400, ['field' => self::FIELD]);
        try {
            $list = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            if ($strict) {
                throw $bad();
            }
            return [];
        }
        if (!is_array($list) || !array_is_list($list) || ($strict && count($list) > 2 * $max)) {
            if ($strict) {
                throw $bad();
            }
            return [];
        }
        $out = [];
        foreach (array_slice($list, 0, 2 * $max) as $l) {
            $line = is_array($l) ? self::line($l) : null;
            if ($line === null) {
                if ($strict) {
                    throw $bad();
                }
                continue;
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * One new line as sent: exactly one of si / sku (a positive whole number), q and p as typed (text or a number; at most TEXT_MAX
     * characters); an item (sku) may say its items per pack (u: a case's barcode stands for its units, PX8); `line` N: it replaces saved line
     * N, removed in the same post (its item changed in place, KG22), and takes its place. Null when it is not one.
     *
     * @param array<mixed> $l
     * @return array{si: ?int, sku: ?int, u: ?int, line: ?int, q: string, p: string}|null
     */
    private static function line(array $l): ?array
    {
        $id = static fn (mixed $v): ?int => is_int($v) ? ($v > 0 ? $v : null) : (is_string($v) ? UiRequest::id($v) : null);
        $si = array_key_exists('si', $l) && $l['si'] !== null ? $id($l['si']) : null;
        $sku = array_key_exists('sku', $l) && $l['sku'] !== null ? $id($l['sku']) : null;
        if (($si === null) === ($sku === null) || (isset($l['si']) && $si === null) || (isset($l['sku']) && $sku === null)) {
            return null;
        }
        $u = null;
        if (($l['u'] ?? null) !== null) {
            $u = $id($l['u']);
            if ($u === null || $si !== null || $u > PoMath::MAX_UNITS_PER_PACK) {
                return null;
            }
        }
        $line = null;
        if (($l['line'] ?? null) !== null) {
            $line = $id($l['line']);
            if ($line === null || $line > 99999) {
                return null;
            }
        }
        $text = static fn (mixed $v): ?string => $v === null ? '' : (is_string($v) || is_int($v) || is_float($v) ? trim((string) $v) : null);
        $q = $text($l['q'] ?? null);
        $p = $text($l['p'] ?? null);
        if ($q === null || $p === null || mb_strlen($q) > self::TEXT_MAX || mb_strlen($p) > self::TEXT_MAX) {
            return null;
        }
        return ['si' => $si, 'sku' => $sku, 'u' => $u === 1 ? null : $u, 'line' => $line, 'q' => $q, 'p' => $p];
    }

    /**
     * The new lines as the field holds them (the script's shape: si, or sku and its items per pack when more than 1, then q and p), to draw
     * them again. @param list<array{si: ?int, sku: ?int, u: ?int, line: ?int, q: string, p: string}> $new
     */
    public static function json(array $new): string
    {
        if ($new === []) {
            return '';
        }
        return (string) json_encode(array_map(static fn (array $l): array => ($l['si'] !== null ? ['si' => $l['si']] : ['sku' => $l['sku']] + ($l['u'] !== null ? ['u' => $l['u']] : []))
            + ($l['line'] !== null ? ['line' => $l['line']] : []) + ['q' => $l['q'], 'p' => $l['p']], $new), JSON_THROW_ON_ERROR);
    }

    /** Packs (or a qty) as typed: a whole number, else null (the service's words refuse it). */
    public static function packs(string $q): ?int
    {
        return PoLinesFile::wholeNumber($q);
    }

    /**
     * The new lines drawn again after a refusal (every typed line kept: the owner's rule, KG4), each as the script draws it: the item's
     * name and CW number, the pack and their code (a pack of any supplier: another supplier's is shown as typed, with its refusal), the
     * figures as typed and the amount when they are figures. Two statements however many lines.
     *
     * @param list<array{si: ?int, sku: ?int, u: ?int, line: ?int, q: string, p: string}> $new
     * @return list<array{key: string, si: string, sku: string, u: string, line: string, q: string, p: string, name: string, code: string, item: ?int, pack: string, their: string, upp: int, amount: string}>
     */
    public static function rows(Db $db, array $new): array
    {
        $sis = array_values(array_unique(array_filter(array_column($new, 'si'))));
        $skus = array_values(array_unique(array_filter(array_column($new, 'sku'))));
        $bySi = [];
        foreach (array_chunk($sis, 1000) as $chunk) {
            foreach ($db->all('SELECT si.id, si.sku_id, si.supplier_code, si.purchase_unit, si.units_per_pack, s.code, s.name FROM supplier_item si JOIN sku s ON s.id = si.sku_id '
                . 'WHERE si.id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $bySi[(int) $r['id']] = $r;
            }
        }
        $bySku = [];
        foreach (array_chunk($skus, 1000) as $chunk) {
            foreach ($db->all('SELECT id, code, name FROM sku WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $bySku[(int) $r['id']] = $r;
            }
        }
        $out = [];
        foreach ($new as $j => $l) {
            $si = $l['si'] !== null ? ($bySi[$l['si']] ?? null) : null;
            $sku = $l['sku'] !== null ? ($bySku[$l['sku']] ?? null) : null;
            $upp = $si === null ? ($l['u'] ?? 1) : (int) $si['units_per_pack'];
            $out[] = ['key' => 'new:' . ($j + 1), 'si' => $l['si'] === null ? '' : (string) $l['si'], 'sku' => $l['sku'] === null ? '' : (string) $l['sku'],
                'u' => $l['u'] === null ? '' : (string) $l['u'], 'line' => $l['line'] === null ? '' : (string) $l['line'], 'q' => $l['q'],
                'p' => $l['p'], 'name' => (string) ($si['name'] ?? $sku['name'] ?? ''), 'code' => (string) ($si['code'] ?? $sku['code'] ?? ''),
                'item' => $si !== null ? (int) $si['sku_id'] : ($sku !== null ? (int) $sku['id'] : null),
                'pack' => $si === null ? PurchaseOrdersController::pack($upp > 1 ? 'case' : 'each', $upp) : PurchaseOrdersController::pack((string) $si['purchase_unit'], $upp),
                'their' => (string) ($si['supplier_code'] ?? ''), 'upp' => $upp, 'amount' => self::amount($l['q'], $l['p'])];
        }
        return $out;
    }

    /** packs x a pack's price, half-up to the penny (PoMath, as both services work a line out), in words; '' when either is not a figure. */
    public static function amount(string $q, string $p): string
    {
        $packs = self::packs($q);
        if ($packs === null || $packs > PoMath::MAX_PACKS || trim($p) === '') {
            return '';
        }
        try {
            return PoMath::money(PoMath::lineAmountE2($packs, PoMath::e4(SupplierItems::packPrice($p))));
        } catch (CwException | \RangeException | \InvalidArgumentException) {
            return '';
        }
    }

    /**
     * The row a refusal of a save is about: the row the page's own check named (detail `row`: 'line:N' a saved line by its number on the
     * page, 'new:J' the J-th new line), else by the place of its line in the list the page sent to the service ($keys: each line's row): a
     * service names the line it refuses by its place (detail `line`).
     *
     * @param list<string> $keys
     */
    public static function rowOf(CwException $e, array $keys): ?string
    {
        $row = $e->detail['row'] ?? null;
        if (is_string($row) && preg_match('/^(line|new):[1-9][0-9]{0,5}$/D', $row) === 1) {
            return $row;
        }
        $at = $e->detail['line'] ?? null;
        return is_int($at) && $at >= 1 && isset($keys[$at - 1]) ? $keys[$at - 1] : null;
    }

    /**
     * What a page draws of the grid besides its saved lines: the new lines typed (drawn again after a refusal, in the form's field too), the
     * lines the person removed (drawn hidden with their remove, $removed: the page's remove field of a saved line, `%d` its number), the row a
     * refusal is about and its words, the document's limit (the script adds no line past it).
     *
     * @param array<string, mixed> $typed the form as sent (none: the page as saved)
     * @return array{newRows: list<array<string, mixed>>, swapped: array<int, array<string, mixed>>, newJson: string, removed: array<int, true>, bad: ?string, badText: ?string, max: int}
     */
    public static function view(Db $db, array $typed, int $max, string $removed, ?string $bad = null, ?string $badText = null): array
    {
        $new = self::read($typed[self::FIELD] ?? null, $max, false);
        $gone = self::removedLines($typed, $removed);
        // A saved line whose item was changed in place (a new line that replaces it, KG22) is drawn as that new line, in its place.
        $swapped = [];
        $rows = [];
        foreach (self::rows($db, $new) as $r) {
            if ($r['line'] !== '' && isset($gone[(int) $r['line']]) && !isset($swapped[(int) $r['line']])) {
                $swapped[(int) $r['line']] = $r;
            } else {
                $rows[] = $r;
            }
        }
        return ['newRows' => $rows, 'swapped' => $swapped, 'newJson' => self::json($new), 'removed' => $gone, 'bad' => $bad, 'badText' => $bad === null ? null : $badText,
            'max' => $max];
    }

    /**
     * The saved lines a form removed: its remove field of a saved line set ($pattern: the page's, `%d` the line's number).
     *
     * @param array<string, mixed> $post
     * @return array<int, true>
     */
    public static function removedLines(array $post, string $pattern): array
    {
        $gone = [];
        foreach ($post as $k => $v) {
            if ($v === '1' && is_string($k) && sscanf($k, $pattern, $n) === 1 && (int) $n > 0 && sprintf($pattern, (int) $n) === $k) {
                $gone[(int) $n] = true;
            }
        }
        return $gone;
    }

    /**
     * The order the lines are saved in (KG22): the saved lines kept ($kept: their rows, 'line:N', in order) and the new lines; a new line that
     * replaces a saved line removed in the same post (`line` N, its item changed in place) takes that line's place, the other new lines follow
     * at the end, as typed.
     *
     * @param list<string> $kept
     * @param list<array{si: ?int, sku: ?int, u: ?int, line: ?int, q: string, p: string}> $new
     * @param array<int, true> $removed
     * @return list<array{0: string, 1: int}> ['kept', index in $kept] or ['new', index in $new], in order
     */
    public static function order(array $kept, array $new, array $removed): array
    {
        $slots = [];
        foreach ($kept as $i => $key) {
            $slots[] = [(int) substr($key, 5) * 2, 0, ['kept', $i]];
        }
        foreach ($new as $j => $l) {
            $slots[] = [$l['line'] !== null && isset($removed[$l['line']]) ? $l['line'] * 2 : PHP_INT_MAX, $j + 1, ['new', $j]];
        }
        usort($slots, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_map(static fn (array $s): array => $s[2], $slots);
    }
}
