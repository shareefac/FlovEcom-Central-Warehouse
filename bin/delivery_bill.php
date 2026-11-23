<?php

declare(strict_types=1);

/**
 * A carrier bill's file read on the server (delivery cost in profit, step 2 part 1; docs/decisions.md DL21): what the screen's upload would
 * store, checked first. Prints the bill's top (account, number, days, net / VAT / gross, lines), the shop the account bills and, per state,
 * its lines and pounds; never a line's content (the file holds customer addresses: it is read, never copied or kept).
 *
 *   php bin/delivery_bill.php --file=<path> [--carrier=dpd] [--shop=<store code>] [--upload | --replace=<bill id>]
 *
 *   (none)     check only: nothing written
 *   --upload   store it as Profit > Delivery bills > Upload does (as system:delivery_cli)
 *   --replace  store it as the corrected file of that live bill
 *   --shop     the shop the account bills, when CW does not know the account yet and none of the file's parcels are CW's
 *
 * Exit codes: 0 done · 1 refused (the reason printed; nothing written) · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\CwException;
use CW\Ops\Cli;
use CW\Profit\DeliveryBills;
use CW\Profit\DpdInvoiceFile;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('delivery_bill', ['file:', 'carrier:', 'shop:', 'upload', 'replace:'],
    'usage: php bin/delivery_bill.php --file=<path> [--carrier=dpd] [--shop=<store code>] [--upload | --replace=<bill id>]',
    static function (Cli $cli, array $opts): int {
        $file = $opts['file'] ?? null;
        if (!is_string($file) || $file === '' || !is_file($file)) {
            throw new InvalidArgumentException('--file must name a file');
        }
        $carrier = is_string($opts['carrier'] ?? null) ? $opts['carrier'] : 'dpd';
        $shop = null;
        if (is_string($opts['shop'] ?? null)) {
            $shop = $cli->db->value('SELECT id FROM channel WHERE code = ?', [$opts['shop']]);
            if ($shop === null) {
                throw new InvalidArgumentException('no such store');
            }
            $shop = (int) $shop;
        }
        $replace = $opts['replace'] ?? null;
        if ($replace !== null && (!is_string($replace) || preg_match('/^[1-9][0-9]{0,17}$/D', $replace) !== 1)) {
            throw new InvalidArgumentException('--replace must be a bill id');
        }
        if ($replace !== null && array_key_exists('upload', $opts)) {
            throw new InvalidArgumentException('--upload stores a new bill and --replace a corrected file of one: give one of them');
        }
        $bytes = (string) file_get_contents($file);
        $bills = new DeliveryBills($cli->db);
        try {
            $p = $bills->preview($carrier, $bytes, $shop);
        } catch (CwException $e) {
            $cli->error($e->getMessage());
            return Cli::PROBLEM;
        }
        $shopName = (string) $cli->db->value('SELECT name FROM channel WHERE id = ?', [$p['shop']]);
        $cli->log(sprintf('%s account %s bill %s: %s to %s, %d lines, net %s, VAT %s, gross %s (the lines add up to them); bills %s; file sha256 %s',
            strtoupper($p['carrier']), $p['account'], $p['invoice_no'], $p['period_from'], $p['period_to'], $p['lines'], DpdInvoiceFile::gbp($p['net']),
            DpdInvoiceFile::gbp($p['vat']), DpdInvoiceFile::gbp($p['gross']), $shopName, $p['sha256']));
        foreach ($p['states'] as $state => $f) {
            $cli->log(sprintf('  %-12s %6d lines  %12s', $state, $f['lines'], DpdInvoiceFile::gbp($f['pence'])));
        }
        if ($p['live_id'] !== null) {
            $cli->log($p['same_file'] ? "this very file is live already as bill id {$p['live_id']}" : "a bill of this number is live already (id {$p['live_id']}): --replace={$p['live_id']} loads this file in its place");
        }
        if (!array_key_exists('upload', $opts) && $replace === null) {
            $cli->log('checked only: nothing written (--upload stores it)');
            return Cli::OK;
        }
        try {
            $r = $replace === null ? $bills->upload(Caller::system('delivery_cli'), $carrier, $bytes, $shop)
                : $bills->replace(Caller::system('delivery_cli'), (int) $replace, $bytes);
        } catch (CwException $e) {
            $cli->error($e->getMessage());
            return Cli::PROBLEM;
        }
        if ($r['result'] === 'same') {
            $cli->log("the same file: nothing changed (bill id {$r['invoice_id']})");
            return Cli::OK;
        }
        if ($r['rematch_error'] !== null) {
            $cli->log("stored as bill id {$r['invoice_id']}; the other bills' lines not on an order were not tried again: {$r['rematch_error']}");
            return Cli::OK;
        }
        $cli->log("stored as bill id {$r['invoice_id']}; {$r['rematched']} lines of other bills put on a parcel or an order since");
        return Cli::OK;
    }, false, true));
