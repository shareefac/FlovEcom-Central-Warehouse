<?php

declare(strict_types=1);

/**
 * CW-1153 (docs/decisions.md VB13; CW\Items\ItemOwnerRefresh): the sale lines of the given items, paid on these UK days, marked as the periods
 * of a shop's own items say (and their orders' own figures and day summaries with them), by the money service itself. It never changes a cost:
 * an order with a line still waiting for its cost is left to the cost job (counted as waiting). Run again: nothing more to do.
 *
 *   php bin/item_owner_refresh.php --items=<file of CW codes> --from=YYYY-MM-DD --to=YYYY-MM-DD [--channel=<store code>] [--apply]
 *
 * Without --apply: what would be marked (nothing written). Exit codes: 0 done · 2 usage · 3 cannot run · 4 busy (another run holds the lock).
 */

use CW\Items\ItemCodes;
use CW\Items\ItemOwnerRefresh;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('item_owner_refresh', ['items:', 'from:', 'to:', 'channel:', 'apply'],
    'usage: php bin/item_owner_refresh.php --items=<file> --from=YYYY-MM-DD --to=YYYY-MM-DD [--channel=<code>] [--apply]',
    static function (Cli $cli, array $opts): int {
        if (!$cli->tryLock()) {
            $cli->error('busy: another run of this job holds its lock: nothing done (run it again)');
            return Cli::BUSY;
        }
        $day = static function (mixed $v, string $name): string {
            if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) !== 1) {
                throw new InvalidArgumentException("give --{$name}=YYYY-MM-DD");
            }
            return $v;
        };
        $from = $day($opts['from'] ?? null, 'from');
        $to = $day($opts['to'] ?? null, 'to');
        if ($from > $to || !is_string($opts['items'] ?? null)) {
            throw new InvalidArgumentException('give --items=<file> and --from not after --to');
        }
        $skus = ItemCodes::fromFile($cli->db, $opts['items']);
        $apply = array_key_exists('apply', $opts);
        $r = (new ItemOwnerRefresh($cli->db))->run($skus, $from, $to, is_string($opts['channel'] ?? null) ? $opts['channel'] : null, $apply);
        $cli->log(sprintf('%d orders read; %d %s (%d lines); %d waiting for their cost (the cost job marks them); %d left (a newer snapshot waits: run again)',
            $r['orders'], $r['refreshed'], $apply ? 'marked' : 'would be marked (dry run: add --apply)', $r['lines'], $r['waiting'], $r['deferred']));
        $cli->log('ok');
        return Cli::OK;
    }, true, true));
