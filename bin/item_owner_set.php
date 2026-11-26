<?php

declare(strict_types=1);

/**
 * CW-1153 (docs/decisions.md VB4, VB7): the go-live kit's marking of a shop's own items. Marks every item of --items (a file of CW codes,
 * read strictly) as the setting's shop's own (Settings > Own items) from --from (a UTC moment in the past: the one explicit exception to
 * "from now"), with --reason, ALL IN ONE TRANSACTION: every item or none. An item already that shop's own is left as it is (a run again
 * changes nothing). With --bill=<number> also marks that final supplier invoice as the shop's own bill (ItemOwners::markBill: every goods line's
 * item that shop's own at its posting time, else refused; its credit notes made final go with it). With --bill-lines=<n> (the go-live kit; the
 * Codex review of bd9104d, M16) the bill must have exactly n goods lines, each an item of --items, checked under the bill's row (else refused,
 * bill_not_pinned). Without --apply: everything is worked out and ROLLED BACK.
 *
 *   php bin/item_owner_set.php --items=<file> --from='YYYY-MM-DD HH:MM:SS' --reason='...' [--bill=SINV-000627 [--bill-lines=8]] [--apply]
 *
 * Exit codes: 0 done · 2 usage or refused (nothing written) · 3 cannot run · 4 busy.
 */

use CW\Caller;
use CW\CwException;
use CW\Items\ItemCodes;
use CW\Items\ItemOwners;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

final class ItemOwnerSetDryRun extends \RuntimeException
{
}

exit(Cli::main('item_owner_set', ['items:', 'from:', 'reason:', 'bill:', 'bill-lines:', 'apply'],
    "usage: php bin/item_owner_set.php --items=<file> --from='YYYY-MM-DD HH:MM:SS' --reason='...' [--bill=<number> [--bill-lines=<n>]] [--apply]",
    static function (Cli $cli, array $opts): int {
        if (!$cli->tryLock()) {
            $cli->error('busy: another run of this job holds its lock: nothing done');
            return Cli::BUSY;
        }
        foreach (['items', 'from', 'reason'] as $k) {
            if (!is_string($opts[$k] ?? null) || trim((string) $opts[$k]) === '') {
                throw new InvalidArgumentException("give --{$k}");
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', (string) $opts['from']) !== 1) {
            throw new InvalidArgumentException("--from is a UTC moment like '2026-11-16 09:00:00'");
        }
        $billLines = null;
        if (array_key_exists('bill-lines', $opts)) {
            if (!is_string($opts['bill'] ?? null) || !is_string($opts['bill-lines']) || preg_match('/^[1-9]\d{0,3}$/D', $opts['bill-lines']) !== 1) {
                throw new InvalidArgumentException('--bill-lines=<n> goes with --bill and is a count of goods lines');
            }
            $billLines = (int) $opts['bill-lines'];
        }
        $db = $cli->db;
        $skus = ItemCodes::fromFile($db, (string) $opts['items']);
        $apply = array_key_exists('apply', $opts);
        $caller = Caller::system('own_items_kit');
        $owners = new ItemOwners($db);
        $shop = ItemOwners::shop($db) ?? throw new InvalidArgumentException('the setting ' . ItemOwners::SETTING . ' names no shop');
        $out = ['added' => 0, 'already' => 0];
        $bill = null;
        try {
            $db->transaction(function () use ($db, $owners, $caller, $skus, $opts, $shop, $apply, $billLines, &$out, &$bill): void {
                $codes = [];
                foreach (array_chunk($skus, 1000) as $chunk) {
                    foreach ($db->all('SELECT id, code FROM sku WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                        $codes[(int) $r['id']] = (string) $r['code'];
                    }
                }
                foreach ($skus as $sku) {
                    $open = $db->one('SELECT channel_id, from_at FROM item_owner WHERE sku_id = ? AND to_at IS NULL', [$sku]); // add() checks again under the item's row
                    if ($open !== null && (int) $open['channel_id'] === $shop['id']) {
                        $out['already']++;
                        continue;
                    }
                    $owners->add($caller, $codes[$sku], (string) $opts['reason'], (string) $opts['from']);
                    $out['added']++;
                }
                if (is_string($opts['bill'] ?? null)) {
                    $bill = $owners->markBill($caller, (string) $opts['bill'], true, $billLines === null ? null : $skus, $billLines);
                }
                if (!$apply) {
                    throw new ItemOwnerSetDryRun('dry run');
                }
            });
        } catch (ItemOwnerSetDryRun) {
            $cli->log(sprintf('DRY RUN (everything rolled back): %d items would be marked as %s\'s own from %s, %d are already', $out['added'], $shop['name'],
                $opts['from'], $out['already']) . ($bill === null ? '' : "; bill {$bill['number']} ({$bill['lines']} goods lines) would be the shop's own bill, with {$bill['credits']} credit notes"));
            $cli->log('ok');
            return Cli::OK;
        } catch (CwException $e) {
            $cli->error("refused, nothing written: {$e->errorCode}: {$e->getMessage()}");
            return Cli::USAGE;
        }
        ItemOwners::refreshReorder($db, $skus);
        $cli->log(sprintf('APPLIED: %d items marked as %s\'s own from %s, %d were already', $out['added'], $shop['name'], $opts['from'], $out['already'])
            . ($bill === null ? '' : "; bill {$bill['number']} ({$bill['lines']} goods lines) is the shop's own bill (" . ($bill['changed'] ? 'marked now' : 'it was already')
                . "; {$bill['credits']} credit notes with it)"));
        $cli->log('ok');
        return Cli::OK;
    }, true, true));
