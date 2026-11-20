<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;

/**
 * ERPNext's cost at each sale, for the orders the shop sent (bin/import_erp_sales_cost.php; docs/decisions.md PR18-PR21). The files are
 * ERPNext's Sales Invoice lines, one file a day (si_<YYYY-MM-DD>.tsv: a header row, tab-separated: name, posting_date, creation,
 * custom_order_id, is_return, return_against, docstatus, net_total, total_taxes_and_charges, grand_total, discount_amount, idx,
 * item_code, qty, rate, net_rate, net_amount, incoming_rate, warehouse; item_code "Shipping Charge" is the delivery), written by a
 * read-only export (a file appears whole: it is written aside and moved in), as written or gzip-compressed (si_<day>.tsv.gz): read line
 * by line, one day in memory at a time.
 *
 * For an invoice of an order CW holds (custom_order_id = the order ref), paid before CW's own price starts (profit.cost_start), each line
 * of a variant ("P" + its id) gets ERPNext's incoming_rate as its cost (per CW unit: / units per item), cost_source erp_at_sale, through
 * the order's own service (OrderSnapshots::recompute: its totals and the day summaries follow). Several rows of one variant: their rates
 * weighted by quantity; a rate of 0 is not taken (the line keeps what it had). Returns (is_return = 1) are reported, not applied.
 *
 * Every day prints one reconciliation line: ERPNext's orders and goods net against CW's for the same orders.
 */
final class ErpSalesImport
{
    public const SHIPPING = 'Shipping Charge';
    private const COLUMNS = ['name', 'custom_order_id', 'is_return', 'docstatus', 'item_code', 'qty', 'net_amount', 'incoming_rate'];

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, private readonly ?\Closure $clock = null)
    {
    }

    /**
     * @param \Closure(string): void $log
     * @return array{days: int, orders: int, lines: int}
     */
    public function run(string $dir, string $channelCode, ?string $from, ?string $to, bool $apply, \Closure $log): array
    {
        $channel = $this->db->value('SELECT id FROM channel WHERE code = ?', [$channelCode]);
        if ($channel === null) {
            throw new \InvalidArgumentException("no store {$channelCode}");
        }
        // A day's file as written (si_<day>.tsv) or compressed (si_<day>.tsv.gz): one file a day, read line by line (gzopen reads both).
        $files = [];
        foreach ([...(glob(rtrim($dir, '/') . '/si_*.tsv') ?: []), ...(glob(rtrim($dir, '/') . '/si_*.tsv.gz') ?: [])] as $f) {
            if (preg_match('/si_(\d{4}-\d{2}-\d{2})\.tsv(\.gz)?$/D', $f, $m) === 1) {
                $files[$m[1]] ??= $f;
            }
        }
        ksort($files, SORT_STRING);
        $settings = new ProfitSettings($this->db);
        $until = $settings->costStartAt();
        $service = new OrderSnapshots($this->db, $this->clock, $settings);
        $tot = ['days' => 0, 'orders' => 0, 'lines' => 0];
        foreach ($files as $dayOf => $f) {
            $m = [1 => (string) $dayOf];
            if (($from !== null && $m[1] < $from) || ($to !== null && $m[1] > $to)) {
                continue;
            }
            $r = $this->day((int) $channel, $f, $until, $apply, $service);
            $tot['days']++;
            $tot['orders'] += $r['cw_orders'];
            $tot['lines'] += $r['costed'];
            $log(sprintf('%s erp orders %d goods %s cost %s | cw orders %d goods %s | lines costed %d, erp lines with no cw line %d, orders not in cw %d, '
                . 'paid on or after the cost start %d, not paid in cw %d, returns %d (%s), no order id %d, rate 0 %d%s',
                $m[1], $r['erp_orders'], self::gbp($r['erp_goods_e2']), self::gbp($r['erp_cost_e2']), $r['cw_orders'], self::gbp($r['cw_goods_e2']), $r['costed'],
                $r['no_line'], $r['not_in_cw'], $r['after_start'], $r['unpaid'], $r['returns'], self::gbp($r['returns_e2']), $r['no_order_id'], $r['rate_zero'],
                $apply ? '' : ' (dry run: nothing written)'));
        }
        return $tot;
    }

    /** @return array<string, int> */
    private function day(int $channel, string $file, string $until, bool $apply, OrderSnapshots $service): array
    {
        $fh = gzopen($file, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("cannot read {$file}");
        }
        $head = array_map('trim', explode("\t", rtrim((string) gzgets($fh), "\r\n")));
        $col = array_flip($head);
        foreach (self::COLUMNS as $c) {
            if (!isset($col[$c])) {
                gzclose($fh);
                throw new \RuntimeException(basename($file) . " has no column {$c}");
            }
        }
        $r = ['erp_orders' => 0, 'erp_goods_e2' => 0, 'erp_cost_e2' => 0, 'cw_orders' => 0, 'cw_goods_e2' => 0, 'costed' => 0, 'no_line' => 0, 'not_in_cw' => 0,
            'after_start' => 0, 'unpaid' => 0, 'returns' => 0, 'returns_e2' => 0, 'no_order_id' => 0, 'rate_zero' => 0];
        $orders = [];
        $returns = [];
        $noId = [];
        while (($line = gzgets($fh)) !== false) {
            $f = explode("\t", rtrim($line, "\r\n"));
            if (count($f) < count($head) || trim($f[$col['docstatus']]) !== '1') {
                continue;
            }
            $name = (string) $f[$col['name']];
            $item = trim((string) $f[$col['item_code']]);
            $net = ProfitMath::divRound(ProfitMath::e4(trim((string) $f[$col['net_amount']])) ?? 0, 100);
            if (trim((string) $f[$col['is_return']]) === '1') {
                $returns[$name] = true;
                $r['returns_e2'] += $item === self::SHIPPING ? 0 : $net;
                continue;
            }
            $oid = trim((string) $f[$col['custom_order_id']]);
            if ($oid === '' || strtoupper($oid) === 'NULL' || preg_match('/^[\x21-\x7e]{1,64}$/D', $oid) !== 1) {
                $noId[$name] = true;
                continue;
            }
            if ($item === self::SHIPPING || preg_match('/^P([0-9]{1,18})$/D', $item, $mm) !== 1) {
                continue;
            }
            $qty = (int) round((float) trim((string) $f[$col['qty']]));
            $rate = ProfitMath::e4(trim((string) $f[$col['incoming_rate']])) ?? 0;
            $orders[$oid] ??= [];
            $orders[$oid][$mm[1]] ??= ['qty' => 0, 'value' => 0, 'rated_qty' => 0];
            $orders[$oid][$mm[1]]['qty'] += $qty;
            if ($rate > 0 && $qty > 0) {
                $orders[$oid][$mm[1]]['value'] += $rate * $qty;
                $orders[$oid][$mm[1]]['rated_qty'] += $qty;
            }
            $r['erp_goods_e2'] += $net;
            $r['erp_cost_e2'] += ProfitMath::divRound($rate * $qty, 100);
        }
        gzclose($fh);
        $r['erp_orders'] = count($orders);
        $r['returns'] = count($returns);
        $r['no_order_id'] = count($noId);
        $refs = array_map('strval', array_keys($orders));
        $known = [];
        foreach (array_chunk($refs, 500) as $chunk) {
            foreach ($this->db->all('SELECT order_ref, paid_at, sale_ex FROM sale_order WHERE channel_id = ? AND order_ref IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ')', [$channel, ...$chunk]) as $o) {
                $known[(string) $o['order_ref']] = $o;
            }
        }
        foreach ($orders as $ref => $variants) {
            $ref = (string) $ref;
            $o = $known[$ref] ?? null;
            if ($o === null) {
                $r['not_in_cw']++;
                continue;
            }
            if ($o['paid_at'] === null) {
                $r['unpaid']++;
                continue;
            }
            if ((string) $o['paid_at'] >= $until) {
                $r['after_start']++;
                continue;
            }
            $r['cw_orders']++;
            $r['cw_goods_e2'] += (int) $o['sale_ex'];
            $lines = $this->db->all('SELECT line_id, variant_id, units_per_item FROM sale_line WHERE channel_id = ? AND order_ref = ? AND removed_at IS NULL',
                [$channel, $ref]);
            $byVariant = [];
            foreach ($lines as $l) {
                $byVariant[(string) $l['variant_id']][] = $l;
            }
            $override = [];
            foreach ($variants as $variant => $x) {
                $mine = $byVariant[(string) $variant] ?? [];
                if ($mine === []) {
                    $r['no_line']++;
                    continue;
                }
                if ($x['rated_qty'] <= 0) {
                    $r['rate_zero']++;
                    continue;
                }
                $rate = ProfitMath::divRound($x['value'], $x['rated_qty']);
                foreach ($mine as $l) {
                    $override[(string) $l['line_id']] = ['e4' => ProfitMath::divRound($rate, max(1, (int) $l['units_per_item'])), 'source' => 'erp_at_sale',
                        'by' => 'erp_import'];
                }
            }
            $r['costed'] += count($override);
            if ($apply && $override !== []) {
                $service->recompute($channel, $ref, $override);
            }
        }
        return $r;
    }

    private static function gbp(int $e2): string
    {
        return ($e2 < 0 ? '-' : '') . 'GBP ' . number_format(intdiv(abs($e2), 100)) . '.' . str_pad((string) (abs($e2) % 100), 2, '0', STR_PAD_LEFT);
    }
}
