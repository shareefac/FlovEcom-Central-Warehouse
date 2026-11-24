<?php

declare(strict_types=1);

/**
 * A Royal Mail bill read on the server (delivery cost, phase 1; docs/decisions.md DL52-DL55, DL70; CW\Profit\RoyalMailBills): what the screen's
 * upload would store, checked first. Prints the bill's top (account, number, days, net / VAT / gross, rows) and where its money would go
 * (per destination and shop, party or reason); never a row's content. Also the way to prove a new account's first bill ties by the day rule
 * before it is switched on (bin/carrier_account.php).
 *
 *   php bin/rm_bill.php --file=<saved page or pasted text> --account=<account number> [--upload | --replace=<bill id>]
 *
 * Exit codes: 0 done · 1 refused (the reason printed; nothing written) · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\CwException;
use CW\Ops\Cli;
use CW\Profit\DpdInvoiceFile;
use CW\Profit\RoyalMailBills;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('rm_bill', ['file:', 'account:', 'upload', 'replace:'],
    'usage: php bin/rm_bill.php --file=<path> --account=<account number> [--upload | --replace=<bill id>]',
    static function (Cli $cli, array $opts): int {
        $file = $opts['file'] ?? null;
        if (!is_string($file) || $file === '' || !is_file($file)) {
            throw new InvalidArgumentException('--file must name a file');
        }
        $no = is_string($opts['account'] ?? null) ? $opts['account'] : null;
        $account = $no === null ? null : $cli->db->value("SELECT a.id FROM carrier_account a JOIN carrier c ON c.code = a.carrier AND c.family = 'pooled' WHERE a.account_no = ?",
            [$no]);
        if ($account === null) {
            throw new InvalidArgumentException('--account must be the number of a Royal Mail account CW has');
        }
        $replace = $opts['replace'] ?? null;
        if ($replace !== null && (!is_string($replace) || preg_match('/^[1-9][0-9]{0,17}$/D', $replace) !== 1)) {
            throw new InvalidArgumentException('--replace must be a bill id');
        }
        if ($replace !== null && array_key_exists('upload', $opts)) {
            throw new InvalidArgumentException('--upload stores a new bill and --replace a corrected file of one: give one of them');
        }
        $bytes = (string) file_get_contents($file);
        $rm = new RoyalMailBills($cli->db);
        try {
            $p = $rm->preview((int) $account, $bytes);
        } catch (CwException $e) {
            $cli->error($e->getMessage());
            return Cli::PROBLEM;
        }
        $cli->log(sprintf('Royal Mail account %s (%s) bill %s of %s: %s to %s, %d rows, net %s, VAT %s, gross %s (the rows add up to them); fingerprint %s',
            $p['account'], $p['state'], $p['invoice_no'], $p['invoice_date'], $p['period_from'], $p['period_to'], $p['rows'], DpdInvoiceFile::gbp($p['net']),
            DpdInvoiceFile::gbp($p['vat']), DpdInvoiceFile::gbp($p['gross']), $p['sha256']));
        foreach ($p['where'] as $k => $pence) {
            [$dest, $ch, $party, $reason, $line] = explode('|', $k);
            $who = $ch !== '' ? (string) $cli->db->value('SELECT name FROM channel WHERE id = ?', [(int) $ch])
                : ($party !== '' ? (string) $cli->db->value('SELECT name FROM delivery_party WHERE id = ?', [(int) $party]) : $reason);
            $cli->log(sprintf('  %-13s %-28s %12s', $dest . ($line !== '' ? ' (day line)' : ''), $who, DpdInvoiceFile::gbp($pence)));
        }
        // the "needs a look" of its days (DL86 7): counts only, never a parcel or a label named here
        $red = count(array_filter($p['look'] ?? [], static fn (array $i): bool => $i['red']));
        $cli->log(sprintf('needs a look: %d (%d red: billed more than labels by more than the setting)', count($p['look'] ?? []), $red));
        if ($p['live_id'] !== null) {
            $cli->log($p['same_file'] ? "this very bill is live already as bill id {$p['live_id']}" : "a bill of this number is live already (id {$p['live_id']}): --replace={$p['live_id']} loads this one in its place");
        }
        if (!array_key_exists('upload', $opts) && $replace === null) {
            $cli->log('checked only: nothing written (--upload stores it)');
            return Cli::OK;
        }
        try {
            $r = $replace === null ? $rm->upload(Caller::system('delivery_cli'), (int) $account, $bytes) : $rm->replace(Caller::system('delivery_cli'), (int) $replace, $bytes);
        } catch (CwException $e) {
            $cli->error($e->getMessage());
            return Cli::PROBLEM;
        }
        $cli->log($r['result'] === 'same' ? "this very bill is loaded already (bill id {$r['invoice_id']}): nothing changed" : "stored as bill id {$r['invoice_id']}");
        return Cli::OK;
    }, true, true));
