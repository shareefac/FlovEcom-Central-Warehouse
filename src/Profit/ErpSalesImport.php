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
 * by line.
 *
 * For an invoice of an order CW holds (custom_order_id = the order ref), paid before CW's own price starts (profit.cost_start), each line
 * of a variant ("P" + its id) gets ERPNext's incoming_rate as its cost (per CW unit: / units per item), cost_source erp_at_sale, through
 * the order's own service (OrderSnapshots::recompute, under the order's locks: its totals and the day summaries follow). An order's rows
 * are taken TOGETHER over every day file it is on (two passes: the first finds each order's last day, the second applies an order on it;
 * the Codex review of 92efa82, a9/b17). Several rows of one variant: their rates weighted by quantity; a variant with a row at a rate of 0
 * keeps what it had (its other rows do not price that unit: a10). A day file with a row that is not whole (its columns, its numbers) is
 * refused whole and named (b16). Returns (is_return = 1) are reported, not applied.
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
     * @return array{days: int, orders: int, lines: int, refused: int}
     */
    public function run(string $dir, string $channelCode, ?string $from, ?string $to, bool $apply, \Closure $log): array
    {
        $channel = $this->db->value('SELECT id FROM channel WHERE code = ?', [$channelCode]);
        if ($channel === null) {
            throw new \InvalidArgumentException("no store {$channelCode}");
        }
        $files = self::files($dir, $from, $to);
        $settings = new ProfitSettings($this->db);
        $until = $settings->costStartAt();
        $service = new OrderSnapshots($this->db, $this->clock, $settings);
        // Pass 1: each day read whole (a broken day is refused and left out of both passes); each order's last day.
        $last = [];
        $ok = [];
        $refused = 0;
        foreach ($files as $day => $f) {
            $d = $this->read($f);
            if (isset($d['error'])) {
                $refused++;
                $log("{$day} refused: {$d['error']} (nothing of this day is applied; the file is left as it is)");
                continue;
            }
            $ok[$day] = $f;
            foreach (array_keys($d['orders']) as $ref) {
                $last[(string) $ref] = $day;
            }
        }
        // Pass 2: the orders, each on its last day, with its rows of every day before it held until then.
        $tot = ['days' => 0, 'orders' => 0, 'lines' => 0, 'refused' => $refused];
        $held = [];
        foreach ($ok as $day => $f) {
            $d = $this->read($f);
            $r = $d['counts'] + ['cw_orders' => 0, 'cw_goods_e2' => 0, 'costed' => 0, 'no_line' => 0, 'not_in_cw' => 0, 'after_start' => 0, 'unpaid' => 0, 'rate_zero' => 0,
                'held' => 0];
            $due = [];
            foreach ($d['orders'] as $ref => $variants) {
                $ref = (string) $ref;
                foreach ($variants as $v => $x) {
                    $held[$ref][$v] ??= ['qty' => 0, 'value' => 0, 'rated_qty' => 0, 'zero' => 0];
                    foreach ($x as $k => $n) {
                        $held[$ref][$v][$k] += $n;
                    }
                }
                if (($last[$ref] ?? $day) === $day) {
                    $due[$ref] = $held[$ref];
                    unset($held[$ref]);
                } else {
                    $r['held']++;
                }
            }
            $this->apply((int) $channel, $due, $until, $apply, $service, $r);
            $tot['days']++;
            $tot['orders'] += $r['cw_orders'];
            $tot['lines'] += $r['costed'];
            $log(sprintf('%s erp orders %d goods %s cost %s | cw orders %d goods %s | lines costed %d, erp lines with no cw line %d, orders not in cw %d, '
                . 'paid on or after the cost start %d, not paid in cw %d, returns %d (%s), no order id %d, rate 0 %d, held for a later day %d%s',
                $day, $r['erp_orders'], self::gbp($r['erp_goods_e2']), self::gbp($r['erp_cost_e2']), $r['cw_orders'], self::gbp($r['cw_goods_e2']), $r['costed'],
                $r['no_line'], $r['not_in_cw'], $r['after_start'], $r['unpaid'], $r['returns'], self::gbp($r['returns_e2']), $r['no_order_id'], $r['rate_zero'],
                $r['held'], $apply ? '' : ' (dry run: nothing written)'));
        }
        return $tot;
    }

    /**
     * The day files of $dir (si_<day>.tsv or .tsv.gz, one a day; the folder read as it is named, never as a pattern: b19).
     *
     * @return array<string, string> day => path, in day order
     */
    public static function files(string $dir, ?string $from, ?string $to): array
    {
        $out = [];
        $names = @scandir($dir);
        if ($names === false) {
            throw new \InvalidArgumentException("cannot read the folder {$dir}");
        }
        sort($names, SORT_STRING);
        foreach ($names as $n) {
            if (preg_match('/^si_(\d{4}-\d{2}-\d{2})\.tsv(\.gz)?$/D', $n, $m) !== 1 || ($from !== null && $m[1] < $from) || ($to !== null && $m[1] > $to)) {
                continue;
            }
            $out[$m[1]] ??= rtrim($dir, '/') . '/' . $n;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * One day file read whole and checked: every row has every column, its numbers are numbers (a whole quantity), else the day is refused.
     *
     * @return array{orders: array<string, array<string, array{qty: int, value: int, rated_qty: int, zero: int}>>, counts: array<string, int>}|array{error: string}
     */
    private function read(string $file): array
    {
        $fh = @gzopen($file, 'rb');
        if ($fh === false) {
            return ['error' => 'the file cannot be read'];
        }
        try {
            $head = array_map('trim', explode("\t", rtrim((string) gzgets($fh), "\r\n")));
            $col = array_flip($head);
            foreach (self::COLUMNS as $c) {
                if (!isset($col[$c])) {
                    return ['error' => "no column {$c}"];
                }
            }
            $counts = ['erp_orders' => 0, 'erp_goods_e2' => 0, 'erp_cost_e2' => 0, 'returns' => 0, 'returns_e2' => 0, 'no_order_id' => 0];
            $orders = [];
            $returns = [];
            $noId = [];
            $row = 0;
            $num = static fn (string $v): ?int => ProfitMath::e4(trim($v));
            while (($line = gzgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                $row++;
                $f = explode("\t", $line);
                if (count($f) !== count($head)) {
                    return ['error' => "row {$row} has " . count($f) . ' columns, not ' . count($head)];
                }
                if (trim($f[$col['docstatus']]) !== '1') {
                    continue;
                }
                $qty = $num($f[$col['qty']]);
                $net = $num($f[$col['net_amount']]);
                $rate = $num($f[$col['incoming_rate']]);
                if ($qty === null || $qty % 10000 !== 0 || $net === null || $rate === null || $rate < 0) {
                    return ['error' => "row {$row}: its quantity, net amount or incoming rate is not a number (or not a whole quantity)"];
                }
                $qty = intdiv($qty, 10000);
                $name = (string) $f[$col['name']];
                $item = trim((string) $f[$col['item_code']]);
                $netE2 = ProfitMath::divRound($net, 100);
                if (trim((string) $f[$col['is_return']]) === '1') {
                    $returns[$name] = true;
                    $counts['returns_e2'] += $item === self::SHIPPING ? 0 : $netE2;
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
                $x = &$orders[$oid][$mm[1]];
                $x ??= ['qty' => 0, 'value' => 0, 'rated_qty' => 0, 'zero' => 0];
                $x['qty'] += $qty;
                if ($rate > 0 && $qty > 0) {
                    $x['value'] += $rate * $qty;
                    $x['rated_qty'] += $qty;
                } elseif ($qty > 0) {
                    $x['zero'] += $qty;
                }
                unset($x);
                $counts['erp_goods_e2'] += $netE2;
                $counts['erp_cost_e2'] += ProfitMath::divRound($rate * $qty, 100);
            }
            if (!gzeof($fh)) {
                return ['error' => 'the file could not be read to its end'];
            }
            $counts['erp_orders'] = count($orders);
            $counts['returns'] = count($returns);
            $counts['no_order_id'] = count($noId);
            return ['orders' => $orders, 'counts' => $counts];
        } finally {
            gzclose($fh);
        }
    }

    /**
     * The orders due on this day: their costs, each worked out under the order's locks from what is stored then.
     *
     * @param array<string, array<string, array{qty: int, value: int, rated_qty: int, zero: int}>> $due
     * @param array<string, int> $r the day's counts
     */
    private function apply(int $channel, array $due, string $until, bool $apply, OrderSnapshots $service, array &$r): void
    {
        $refs = array_map('strval', array_keys($due));
        $known = [];
        foreach (array_chunk($refs, 500) as $chunk) {
            foreach ($this->db->all('SELECT order_ref, paid_at, sale_ex FROM sale_order WHERE channel_id = ? AND order_ref IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ')', [$channel, ...$chunk]) as $o) {
                $known[(string) $o['order_ref']] = $o;
            }
        }
        foreach ($due as $ref => $variants) {
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
            $plan = self::override($this->lines($this->db, $channel, $ref), $variants);
            $r['costed'] += count($plan['override']);
            $r['no_line'] += $plan['no_line'];
            $r['rate_zero'] += $plan['rate_zero'];
            if ($apply && $plan['override'] !== []) {
                $service->recompute($channel, $ref, function (Db $db, array $body) use ($channel, $ref, $variants, $until): ?array {
                    // Again under the order's locks: still paid before the cost start, and its lines as they are now.
                    $paid = $db->value('SELECT paid_at FROM sale_order WHERE channel_id = ? AND order_ref = ?', [$channel, $ref]);
                    if ($paid === null || (string) $paid >= $until) {
                        return null;
                    }
                    $o = self::override($this->lines($db, $channel, $ref), $variants)['override'];
                    return $o === [] ? null : $o;
                });
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function lines(Db $db, int $channel, string $ref): array
    {
        return $db->all('SELECT line_id, variant_id, units_per_item FROM sale_line WHERE channel_id = ? AND order_ref = ? AND removed_at IS NULL', [$channel, $ref]);
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, array{qty: int, value: int, rated_qty: int, zero: int}> $variants
     * @return array{override: array<string, array{e4: int, source: string, by: string}>, no_line: int, rate_zero: int}
     */
    private static function override(array $lines, array $variants): array
    {
        $byVariant = [];
        foreach ($lines as $l) {
            $byVariant[(string) $l['variant_id']][] = $l;
        }
        $out = ['override' => [], 'no_line' => 0, 'rate_zero' => 0];
        foreach ($variants as $variant => $x) {
            $mine = $byVariant[(string) $variant] ?? [];
            if ($mine === []) {
                $out['no_line']++;
                continue;
            }
            if ($x['rated_qty'] <= 0 || $x['zero'] > 0) {
                $out['rate_zero']++;
                continue;
            }
            $rate = ProfitMath::divRound($x['value'], $x['rated_qty']);
            foreach ($mine as $l) {
                $out['override'][(string) $l['line_id']] = ['e4' => ProfitMath::divRound($rate, max(1, (int) $l['units_per_item'])), 'source' => 'erp_at_sale',
                    'by' => 'erp_import'];
            }
        }
        return $out;
    }

    private static function gbp(int $e2): string
    {
        return ($e2 < 0 ? '-' : '') . 'GBP ' . number_format(intdiv(abs($e2), 100)) . '.' . str_pad((string) (abs($e2) % 100), 2, '0', STR_PAD_LEFT);
    }
}
