<?php

declare(strict_types=1);

namespace CW\Reorder;

use CW\Clock;
use CW\Db;
use CW\PurchaseOrders\PoMath;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Purchasing;
use CW\Settings;

/**
 * The IM9 basic reorder list (spec §7.4; docs/decisions.md I64, I65): one line per item with a demand row (reorder_demand)
 * or a minimum stock, computed now from the stored demand, the item / brand / supplier settings, CW's stock (or the site's,
 * while the sites are not live), the purchase orders on order and in drafts. Reads only.
 *
 * For item s with preferred supplier item p (none: packs of 1, MOQ 1, flag no_supplier):
 *   L = item.lead_days_override ?? p.lead_days ?? supplier.default_lead_days ?? reorder.default_lead_days
 *   R = supplier.review_days ?? reorder.default_review_days
 *   S = item.safety_days ?? brand.safety_days ?? reorder.default_safety_days
 *   φ = item.demand_factor ?? brand.demand_factor ?? 1.00;  d = rate(s) × φ
 * then ReorderMath. Available A: `cw` = Σ sellable warehouses (on_hand - allocated - held); `site` = Σ over the item's
 * vapeandgo listings of u × its usable site stock (sellable: max(0, stock); unsellable: 0; In-Stock mode or negative
 * flagged site_stock_unreliable, I78), labelled with its snapshot date. On order O = PurchaseOrders::onOrder;
 * in drafts D = PurchaseOrders::inDrafts (shown, never counted). Never suggested (k = 0, shown only with show=all):
 * do_not_reorder, merged items.
 */
final class ReorderList
{
    public const PAGE = 200;
    public const SITE_CHANNEL = 'vapeandgo';
    public const SHOW = ['need', 'all'];
    public const STOCK = ['cw', 'site'];

    private readonly Settings $settings;
    /** @var array<string, mixed>|null */
    private ?array $params = null;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, private readonly PurchaseOrders $pos, ?Settings $settings = null, ?\Closure $clock = null)
    {
        $this->settings = $settings ?? new Settings($db);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /** @return array<string, mixed> ReorderSettings::params() */
    public function params(): array
    {
        return $this->params ??= ReorderSettings::params($this->settings);
    }

    /**
     * The filters of the screen and the CSV from a query string (unknown values fall back to the defaults).
     *
     * @param array<string, ?string> $q brand, supplier, q, urgent, show, stock
     * @return array{brand: ?string, supplier: ?int, q: string, urgent: bool, show: string, stock: string}
     */
    public static function filters(array $q): array
    {
        $brand = trim((string) ($q['brand'] ?? ''));
        $supplier = $q['supplier'] ?? null;
        return [
            'brand' => $brand === '' ? null : mb_substr($brand, 0, 128),
            'supplier' => is_string($supplier) && preg_match('/^[1-9][0-9]{0,9}$/D', $supplier) === 1 ? (int) $supplier : null,
            'q' => mb_substr(trim((string) ($q['q'] ?? '')), 0, 100),
            'urgent' => ($q['urgent'] ?? null) === '1',
            'show' => in_array($q['show'] ?? null, self::SHOW, true) ? (string) $q['show'] : 'need',
            'stock' => in_array($q['stock'] ?? null, self::STOCK, true) ? (string) $q['stock'] : 'cw',
        ];
    }

    /**
     * Every line matching the filters, in list order (urgent, cover now, value). $skus limits the list to those items (the
     * draft-PO form); $withExplain adds `explain` (the "Why" text) to each line.
     *
     * @param array{brand: ?string, supplier: ?int, q: string, urgent: bool, show: string, stock: string} $f
     * @param list<int>|null $skus
     * @return list<array<string, mixed>>
     */
    public function lines(array $f, ?array $skus = null, bool $withExplain = false): array
    {
        $p = $this->params();
        $where = [];
        $params = [];
        if ($f['brand'] !== null) {
            $where[] = 's.brand = ?';
            $params[] = $f['brand'];
        }
        if ($f['supplier'] !== null) {
            $where[] = 'si.supplier_id = ?';
            $params[] = $f['supplier'];
        }
        if ($f['q'] !== '') {
            if (preg_match('/^(?:cw-?)?0*([0-9]{1,10})$/iD', $f['q'], $m) === 1) {
                $where[] = 's.id = ?';
                $params[] = (int) $m[1];
            } else {
                foreach (array_slice(preg_split('/\s+/u', $f['q'], -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $w) {
                    $where[] = "CONCAT_WS(' ', s.name, s.brand, s.code) LIKE ?";
                    $params[] = '%' . addcslashes($w, '\\%_') . '%';
                }
            }
        }
        if ($skus !== null) {
            if ($skus === []) {
                return [];
            }
            $where[] = 's.id IN (' . implode(', ', array_fill(0, count($skus), '?')) . ')';
            array_push($params, ...array_map('intval', $skus));
        }
        $rows = $this->db->all(
            'SELECT s.id, s.code, s.name, s.brand, s.merged_into_sku_id, m.code AS merged_code, d.rate, d.rate_raw_30, d.computed_at, '
            . 'r.safety_days, r.lead_days_override, r.min_stock, r.max_stock, r.demand_factor, r.pack_rounding, r.do_not_reorder, '
            . 'rb.demand_factor AS brand_factor, rb.safety_days AS brand_safety, si.id AS si_id, si.supplier_id, si.supplier_code, si.purchase_unit, si.units_per_pack, '
            . 'si.moq_packs, si.order_multiple_packs, si.lead_days AS si_lead, si.last_pack_price, sup.code AS supplier_code_cw, sup.name AS supplier_name, '
            . 'sup.status AS supplier_status, sup.default_lead_days, sup.review_days '
            . 'FROM (SELECT sku_id FROM reorder_demand UNION SELECT sku_id FROM item_reorder WHERE min_stock IS NOT NULL) base '
            . 'JOIN sku s ON s.id = base.sku_id LEFT JOIN sku m ON m.id = s.merged_into_sku_id LEFT JOIN reorder_demand d ON d.sku_id = s.id '
            . 'LEFT JOIN item_reorder r ON r.sku_id = s.id LEFT JOIN reorder_brand rb ON rb.brand = s.brand '
            . 'LEFT JOIN supplier_item si ON si.preferred_sku_id = s.id LEFT JOIN supplier sup ON sup.id = si.supplier_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)),
            $params,
        );
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $stock = $f['stock'] === 'site' ? $this->siteStock($ids) : array_map(static fn (array $s): array => ['available' => $s['available'], 'date' => null,
            'unreliable' => false], (new Purchasing($this->db))->stockOf($ids));
        $onOrder = $this->pos->onOrder($ids);
        $inDrafts = $this->pos->inDrafts($ids);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $hasSupplier = $r['si_id'] !== null;
            $factorSource = $r['demand_factor'] !== null ? 'item' : ($r['brand_factor'] !== null ? 'brand' : 'default');
            $factorE2 = intdiv(DemandMath::toE4((string) ($r['demand_factor'] ?? $r['brand_factor'] ?? '1.00')), 100);
            $lead = $r['lead_days_override'] ?? $r['si_lead'] ?? $r['default_lead_days'] ?? $p['lead'];
            $review = $r['review_days'] ?? $p['review'];
            $safety = $r['safety_days'] ?? $r['brand_safety'] ?? $p['safety'];
            $never = match (true) {
                $r['merged_into_sku_id'] !== null => 'merged into ' . ($r['merged_code'] ?? 'another item'),
                (int) ($r['do_not_reorder'] ?? 0) === 1 => 'marked "do not reorder"',
                default => null,
            };
            $price = $r['last_pack_price'] === null ? null : PoMath::e4((string) $r['last_pack_price']);
            $rate = $r['rate'] === null ? 0 : DemandMath::toE4((string) $r['rate']);
            $a = $stock[$id]['available'] ?? 0;
            $line = ReorderMath::line([
                'rate_e4' => $rate, 'factor_e2' => $factorE2, 'lead' => (int) $lead, 'review' => (int) $review, 'safety' => (int) $safety,
                'min_stock' => $r['min_stock'] === null ? null : (int) $r['min_stock'], 'max_stock' => $r['max_stock'] === null ? null : (int) $r['max_stock'],
                'available' => $a, 'on_order' => $onOrder[$id] ?? 0, 'in_drafts' => $inDrafts[$id] ?? 0,
                'upp' => $hasSupplier ? (int) $r['units_per_pack'] : 1, 'moq' => $hasSupplier ? (int) $r['moq_packs'] : 1,
                'mult' => $hasSupplier ? (int) $r['order_multiple_packs'] : 1, 'rounding' => (string) ($r['pack_rounding'] ?? 'up'), 'never' => $never !== null,
                'pack_price_e4' => $price,
            ]);
            $flags = [];
            if ($line['urgent']) {
                $flags[] = 'urgent';
            }
            if (!$hasSupplier) {
                $flags[] = 'no_supplier';
            } elseif ($r['supplier_status'] !== 'active') {
                $flags[] = 'supplier_' . $r['supplier_status'];
            }
            if ($hasSupplier && $price === null) {
                $flags[] = 'no_price';
            }
            if ($r['merged_into_sku_id'] !== null) {
                $flags[] = 'merged';
            }
            if ((int) ($r['do_not_reorder'] ?? 0) === 1) {
                $flags[] = 'do_not_reorder';
            }
            if ($r['rate'] === null) {
                $flags[] = 'no_history';
            }
            if ($stock[$id]['unreliable'] ?? false) {
                $flags[] = 'site_stock_unreliable';
            }
            if (($inDrafts[$id] ?? 0) > 0) {
                $flags[] = 'in_draft'; // not pre-ticked on the screen: a second "create drafts" would order it twice (I79)
            }
            $row = $line + [
                'sku_id' => $id, 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'brand' => $r['brand'],
                'supplier_item_id' => $hasSupplier ? (int) $r['si_id'] : null, 'supplier_id' => $hasSupplier ? (int) $r['supplier_id'] : null,
                'supplier' => $hasSupplier ? (string) $r['supplier_code_cw'] : null, 'supplier_name' => $r['supplier_name'], 'supplier_status' => $r['supplier_status'],
                'supplier_code' => $r['supplier_code'], 'purchase_unit' => $hasSupplier ? (string) $r['purchase_unit'] : 'each',
                'upp' => $hasSupplier ? (int) $r['units_per_pack'] : 1, 'moq' => $hasSupplier ? (int) $r['moq_packs'] : 1,
                'mult' => $hasSupplier ? (int) $r['order_multiple_packs'] : 1, 'rounding' => (string) ($r['pack_rounding'] ?? 'up'),
                'pack_price' => $r['last_pack_price'] === null ? null : (string) $r['last_pack_price'],
                'rate_e4' => $rate, 'rate_raw_30_e4' => $r['rate_raw_30'] === null ? 0 : DemandMath::toE4((string) $r['rate_raw_30']),
                'factor_e2' => $factorE2, 'factor_source' => $factorSource, 'lead' => (int) $lead, 'review' => (int) $review, 'safety' => (int) $safety,
                'min_stock' => $r['min_stock'] === null ? null : (int) $r['min_stock'], 'max_stock' => $r['max_stock'] === null ? null : (int) $r['max_stock'],
                'available' => $a, 'on_order' => $onOrder[$id] ?? 0, 'in_drafts' => $inDrafts[$id] ?? 0, 'stock_source' => $f['stock'],
                'site_date' => $stock[$id]['date'] ?? null, 'site_unreliable' => $stock[$id]['unreliable'] ?? false, 'site_channel' => self::SITE_CHANNEL, 'never' => $never, 'no_supplier' => !$hasSupplier, 'flags' => $flags,
                'computed_at' => $r['computed_at'],
            ];
            if ($f['urgent'] && !$row['urgent']) {
                continue;
            }
            if ($f['show'] === 'need' && $row['packs'] === 0) {
                continue;
            }
            $out[] = $row;
        }
        usort($out, ReorderMath::compare(...));
        if ($withExplain) {
            $out = $this->explain($out);
        }
        return $out;
    }

    /**
     * Adds `explain` (the "Why" text) to each line: reads the demand detail of those items only.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    public function explain(array $lines): array
    {
        $p = $this->params();
        $ids = array_map(static fn (array $l): int => (int) $l['sku_id'], $lines);
        $demand = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->db->all('SELECT sku_id, rate_short, rate_long, CAST(detail AS CHAR) AS detail FROM reorder_demand WHERE sku_id IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $d) {
                $demand[(int) $d['sku_id']] = $d;
            }
        }
        foreach ($lines as &$l) {
            $d = $demand[(int) $l['sku_id']] ?? null;
            $detail = $d === null ? [] : (array) json_decode((string) $d['detail'], true);
            $listings = [];
            foreach ((array) ($detail['listings'] ?? []) as $x) {
                $u = (int) $x['u'];
                $listings[] = ['channel' => (string) $x['channel'], 'rate_e4' => $u * DemandMath::toE4((string) $x['rate']), 'method' => (string) $x['method'],
                    'valid_short' => (int) $x['valid_short'], 'valid_long' => (int) $x['valid_long'], 'excluded' => (array) $x['excluded'],
                    'excluded_short' => (array) $x['excluded_short'], 'anomalies' => (array) $x['anomalies'], 'cap' => $x['cap'], 'capped' => (int) $x['capped'],
                    'r_short_e4' => $x['r_short'] === null ? null : $u * DemandMath::toE4((string) $x['r_short']),
                    'r_long_e4' => $x['r_long'] === null ? null : $u * DemandMath::toE4((string) $x['r_long'])];
            }
            $l['explain'] = Explain::text($l + [
                'rate_short_e4' => $d === null || $d['rate_short'] === null ? null : DemandMath::toE4((string) $d['rate_short']),
                'rate_long_e4' => $d === null || $d['rate_long'] === null ? null : DemandMath::toE4((string) $d['rate_long']),
                'weight_e6' => $p['weight_e6'], 'short_window' => $p['short_window'], 'long_window' => $p['long_window'], 'min_long' => $p['min_long'],
                'listings' => $listings,
            ]);
        }
        unset($l);
        return $lines;
    }

    /**
     * Each channel's loaded history and the demand's age, for the list's header: channels (code, from, to, stale), computed_at,
     * demand_stale (an import finished after the demand was computed).
     *
     * @return array{channels: list<array{code: string, from: string, to: string, stale: bool, days_ago: int}>, computed_at: ?string, demand_stale: bool, stale_days: int}
     */
    public function header(): array
    {
        $p = $this->params();
        $today = DemandMath::day(($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d'));
        $channels = [];
        $lastImport = null;
        foreach ($this->db->all("SELECT c.code, MIN(b.date_from) AS s, MAX(b.date_to) AS e, MAX(b.finished_at) AS f FROM sales_import_batch b JOIN channel c ON c.id = b.channel_id "
            . "WHERE b.status = 'loaded' GROUP BY c.code ORDER BY c.code") as $r) {
            $ago = $today - DemandMath::day((string) $r['e']);
            $channels[] = ['code' => (string) $r['code'], 'from' => (string) $r['s'], 'to' => (string) $r['e'], 'stale' => $ago > $p['stale_days'], 'days_ago' => $ago];
            $lastImport = $lastImport === null || (string) $r['f'] > $lastImport ? (string) $r['f'] : $lastImport;
        }
        $computed = $this->db->value('SELECT MAX(computed_at) FROM reorder_demand');
        $computed = $computed === null ? null : (string) $computed;
        return ['channels' => $channels, 'computed_at' => $computed, 'demand_stale' => $lastImport !== null && ($computed === null || $computed < $lastImport),
            'stale_days' => $p['stale_days']];
    }

    /** @return list<string> the brands of the items on the list (the brand filter) */
    public function brands(): array
    {
        return array_map('strval', $this->db->column('SELECT DISTINCT s.brand FROM reorder_demand d JOIN sku s ON s.id = d.sku_id WHERE s.brand IS NOT NULL ORDER BY s.brand'));
    }

    /** @return list<array{id: int, code: string, name: string, status: string}> the suppliers preferred for at least one item (the supplier filter) */
    public function suppliers(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'status' => (string) $r['status']],
            $this->db->all('SELECT DISTINCT sup.id, sup.code, sup.name, sup.status FROM supplier_item si JOIN supplier sup ON sup.id = si.supplier_id '
                . 'WHERE si.preferred_sku_id IS NOT NULL ORDER BY sup.name, sup.id'));
    }

    /**
     * The site's own stock of items (stock=site): Σ over their vapeandgo mapped listings of u × the listing's usable stock on
     * the latest snapshot, and the snapshot date. Usable (review finding, I78): an unsellable listing counts 0 (its figure is
     * not stock the site can sell), a sellable one max(0, stock). `unreliable` when a sellable listing is in the site's
     * In-Stock mode (sold whatever its quantity: the figure is not kept, best sellers go far below 0) or below 0: the line is
     * flagged and the Why says so. Items without a snapshot row: 0, no date.
     *
     * @param list<int> $ids
     * @return array<int, array{available: int, date: ?string, unreliable: bool}>
     */
    public function siteStock(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['available' => 0, 'date' => null, 'unreliable' => false];
        }
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->db->all('SELECT l.sku_id, SUM(l.units_per_item * IF(x.sellable = 1, GREATEST(x.stock, 0), 0)) AS stock, MAX(x.snapshot_date) AS d, '
                . "MAX(x.sellable = 1 AND (x.stock_mode = 'In-Stock' OR x.stock < 0)) AS unreliable FROM channel_listing l "
                . 'JOIN channel c ON c.id = l.channel_id JOIN listing_stock_latest x ON x.channel_id = l.channel_id AND x.external_variant_id = l.external_variant_id '
                . "WHERE c.code = ? AND l.status = 'mapped' AND l.sku_id IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ') GROUP BY l.sku_id',
                [self::SITE_CHANNEL, ...$chunk]) as $r) {
                $out[(int) $r['sku_id']] = ['available' => (int) $r['stock'], 'date' => $r['d'] === null ? null : (string) $r['d'],
                    'unreliable' => (int) $r['unreliable'] === 1];
            }
        }
        return $out;
    }

    /** A rate in central units a day (e4) times φ (e6 overall: d) as the list prints it. */
    public static function perDay(int $dE6): string
    {
        return Explain::rate(DemandMath::halfUpDiv($dE6, 100));
    }

    /** Cover now in days (e1) as the list prints it: '12.5', '∞' when there is no demand. */
    public static function cover(?int $e1): string
    {
        if ($e1 === null) {
            return '∞';
        }
        return ($e1 < 0 ? '-' : '') . intdiv(abs($e1), 10) . '.' . (abs($e1) % 10);
    }

    /** A value (e4 GBP) in pounds and pence, half up ('1,234.56'). */
    public static function money(?int $e4): string
    {
        if ($e4 === null) {
            return '';
        }
        $e2 = DemandMath::halfUpDiv($e4, 100);
        return ($e2 < 0 ? '-' : '') . number_format(intdiv(abs($e2), 100)) . '.' . str_pad((string) (abs($e2) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** A value (e4 GBP) as a CSV number with 2 places, half up ('1234.56'). */
    public static function amount(?int $e4): ?string
    {
        return $e4 === null ? null : PoMath::fromE2(DemandMath::halfUpDiv($e4, 100));
    }
}
