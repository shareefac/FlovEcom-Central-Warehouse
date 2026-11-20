<?php

declare(strict_types=1);

/**
 * The one deliberate re-cost of stored sales (profit step 1; docs/decisions.md PR16; CW\Profit\RecostSales): the lines of the orders paid
 * on these UK days whose cost came from these sources get the buying price at the time they were paid again (costed_by recost).
 *
 *   php bin/recost_sales.php --from=YYYY-MM-DD --to=YYYY-MM-DD [--source=none,estimate,...] [--channel=<store code>] [--apply]
 *
 *   --source  one or more of none, estimate, cw_cost, erp_at_sale, or all (default: none: the lines with no buying price)
 *   --apply   write; without it a dry run that prints what would move
 *
 * Exit codes: 0 done · 2 usage · 3 cannot run.
 */

use CW\Ops\Cli;
use CW\Profit\RecostSales;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('recost_sales', ['from:', 'to:', 'source:', 'channel:', 'apply'],
    'usage: php bin/recost_sales.php --from=YYYY-MM-DD --to=YYYY-MM-DD [--source=none,estimate,cw_cost,erp_at_sale|all] [--channel=<code>] [--apply]',
    static function (Cli $cli, array $opts): int {
        $day = static function (mixed $v, string $name): string {
            if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) !== 1) {
                throw new InvalidArgumentException("give --{$name}=YYYY-MM-DD");
            }
            return $v;
        };
        $from = $day($opts['from'] ?? null, 'from');
        $to = $day($opts['to'] ?? null, 'to');
        if ($from > $to) {
            throw new InvalidArgumentException('--from must not be after --to');
        }
        $src = is_string($opts['source'] ?? null) ? $opts['source'] : 'none';
        $sources = $src === 'all' ? RecostSales::SOURCES : array_values(array_unique(array_map('trim', explode(',', $src))));
        $apply = array_key_exists('apply', $opts);
        $r = (new RecostSales($cli->db))->run($from, $to, $sources, is_string($opts['channel'] ?? null) ? $opts['channel'] : null, $apply);
        foreach ($r['moves'] as $move => $n) {
            $cli->log("{$move}: {$n} lines");
        }
        $cli->log(sprintf('%d orders, %d lines %s', $r['orders'], $r['lines'], $apply ? 're-costed' : 'would be re-costed (dry run: add --apply)'));
        return Cli::OK;
    }, true, true));
