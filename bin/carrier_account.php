<?php

declare(strict_types=1);

/**
 * A carrier account's settings (delivery cost, phase 1; docs/decisions.md DL45, DL70): Vape Big's Royal Mail account is a row made by 0078 with
 * a placeholder number, OFF; when the owner sends its first bill: its number set here, the bill checked with bin/rm_bill.php (it must tie by
 * the day rule), then switched on here. Every change is audited (carrier_account.set). Phase 1 is report-only (DL84): no account has a
 * Click & Drop key; a day is covered only by a report import (bin/rm_labels.php --report, or the screen), never by a person saying so here.
 *
 *   php bin/carrier_account.php --list
 *   php bin/carrier_account.php --account=<number> --number=<new number>      (only a placeholder number, while no bill of the account is stored)
 *   php bin/carrier_account.php --account=<number> --switch-on | --switch-off
 *   php bin/carrier_account.php --account=<number> --add-channel=<code> --channel-name=<the channel's exact name in Click & Drop>
 *        (a new account's Click & Drop channels, before its first report is read: a report with another channel is refused, DL72)
 *   php bin/carrier_account.php --party=<code> --first-order=<number>
 *        (a shop's first order number in CW, as its labels' references carry it: a label below it that a before-start rule fits is the shop's
 *        before CW start, DL78; 0078 sets Electrofag's, 5457. The nightly re-plan, or bin/match_delivery_bills.php, then plans the last
 *        120 days again; an older bill takes the new number when it is replaced)
 *
 * Exit codes: 0 done · 1 refused (the reason printed) · 2 usage · 3 cannot run.
 */

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Ops\Cli;
use CW\Profit\DeliveryBills;

require dirname(__DIR__) . '/vendor/autoload.php';

/** The accounts 0078 seeds by their number (DPD's, the shared Royal Mail account): their number never changes here. */
const CARRIER_ACCOUNT_SEEDED = ['3012002', '0706703000'];

exit(Cli::main('carrier_account', ['list', 'account:', 'number:', 'switch-on', 'switch-off', 'add-channel:', 'channel-name:', 'party:', 'first-order:'],
    'usage: php bin/carrier_account.php --list | --account=<number> (--number=<new> | --switch-on | --switch-off | --add-channel=<code> --channel-name=<exact name>'
    . ') | --party=<code> --first-order=<number>',
    static function (Cli $cli, array $opts): int {
        if (array_key_exists('list', $opts)) {
            foreach ($cli->db->all('SELECT a.carrier, a.account_no, a.name, a.state, p.code FROM carrier_account a JOIN delivery_party p ON p.id = a.main_party_id '
                . 'ORDER BY a.carrier, a.account_no') as $a) {
                $cli->log(sprintf('%-10s %-16s %-6s main %-12s %s', $a['carrier'], $a['account_no'], $a['state'], $a['code'], $a['name']));
            }
            foreach ($cli->db->all('SELECT code, first_order_no FROM delivery_party WHERE first_order_no IS NOT NULL ORDER BY code') as $p) {
                $cli->log(sprintf('party %-12s first CW order number %s', $p['code'], $p['first_order_no']));
            }
            return Cli::OK;
        }
        if (is_string($opts['party'] ?? null)) {
            $code = $opts['party'];
            $first = is_string($opts['first-order'] ?? null) ? $opts['first-order'] : '';
            if (preg_match('/^[1-9][0-9]{0,8}$/D', $first) !== 1) {
                throw new InvalidArgumentException('--first-order=<the shop\'s first order number in CW: digits, not 0>');
            }
            try {
                return (new DeliveryBills($cli->db))->inLock(static fn (): int => carrier_party_first_order($cli, $code, (int) $first));
            } catch (CwException $e) {
                $cli->error(($e->errorCode === 'busy' ? 'busy: ' : '') . $e->getMessage());
                return Cli::PROBLEM;
            }
        }
        $no = is_string($opts['account'] ?? null) ? $opts['account'] : throw new InvalidArgumentException('--account=<number> is needed');
        // Under the delivery bills' lock, the account read again inside it: a bill of the account being stored meanwhile waits, or this does (the
        // Codex check r1, HIGH 4: a number changed while a first bill was being stored would strand that bill).
        try {
            return (new DeliveryBills($cli->db))->inLock(static fn (): int => carrier_account_change($cli, $opts, $no));
        } catch (CwException $e) {
            $cli->error(($e->errorCode === 'busy' ? 'busy: ' : '') . $e->getMessage());
            return Cli::PROBLEM;
        }
    }, true, true));

/** @param array<string, mixed> $opts */
function carrier_account_change(Cli $cli, array $opts, string $no): int
{
    $a = $cli->db->one('SELECT * FROM carrier_account WHERE account_no = ?', [$no]) ?? throw new InvalidArgumentException('no carrier account of that number');
    $caller = Caller::system('carrier_account');
    if (is_string($opts['add-channel'] ?? null)) {
        $code = $opts['add-channel'];
        $name = is_string($opts['channel-name'] ?? null) ? trim($opts['channel-name']) : '';
        if (preg_match('/^[a-z][a-z0-9_]{1,15}$/D', $code) !== 1 || $code === 'other' || $name === '' || mb_strlen($name) > 128) {
            throw new InvalidArgumentException('--add-channel=<code: lower-case letters, digits, _> --channel-name=<the channel\'s exact name, at most 128 characters>');
        }
        try {
            $cli->db->transaction(static function (Db $db) use ($a, $code, $name, $caller): void {
                $db->exec('INSERT INTO carrier_channel (account_id, code, name) VALUES (?, ?, ?)', [(int) $a['id'], $code, $name]);
                Audit::write($db, $caller, 'carrier_account.set', 'carrier_account', (string) $a['id'], null, ['channel_added' => $code]);
            });
        } catch (\PDOException $e) {
            if (Db::driverCode($e) !== 1062) {
                throw $e;
            }
            $cli->error("account {$a['account_no']}: a channel of that code or name is there already: nothing changed");
            return Cli::PROBLEM;
        }
        $cli->log("account {$a['account_no']}: channel {$code} added");
        return Cli::OK;
    }
    if (is_string($opts['number'] ?? null)) {
        $new = $opts['number'];
        if (preg_match('/^[0-9]{6,16}$/D', $new) !== 1) {
            throw new InvalidArgumentException('--number must be the account number as the carrier prints it (digits)');
        }
        if (in_array($new, CARRIER_ACCOUNT_SEEDED, true) || $cli->db->value('SELECT 1 FROM carrier_account WHERE account_no = ? LIMIT 1', [$new]) !== null) {
            // never another account's number (an account is found here by its number alone: the Codex check r5, LOW 11)
            $cli->error("{$new} is another account's number: nothing changed");
            return Cli::PROBLEM;
        }
        if (in_array((string) $a['account_no'], CARRIER_ACCOUNT_SEEDED, true)) {
            // 0078 seeds these accounts' parties, channels and rules BY THEIR NUMBER: theirs never changes, off or not (the Codex check r4, r3
            // second table 6 PARTLY; DL80)
            $cli->error("account {$a['account_no']} is one 0078 sets up by its number: its number never changes");
            return Cli::PROBLEM;
        }
        if (preg_match('/^[0-9]+$/D', (string) $a['account_no']) === 1 && $a['state'] !== 'off') {
            // a placeholder (0078's vapebig-pending) gets its real number, and a number typed wrong is corrected while the account is off with no bill;
            // an account in use never changes its number here (the Codex checks r2 new MEDIUM 6, r3 new MEDIUM 4: 0078 seeds by number)
            $cli->error("account {$a['account_no']} is in use: its number is not changed");
            return Cli::PROBLEM;
        }
        if ($cli->db->value('SELECT 1 FROM carrier_invoice WHERE carrier = ? AND account = ? LIMIT 1', [$a['carrier'], $a['account_no']]) !== null) {
            $cli->error('a bill of this account is stored already: its number stays');
            return Cli::PROBLEM;
        }
        $cli->db->transaction(static function (Db $db) use ($a, $new, $caller): void {
            $db->exec('UPDATE carrier_account SET account_no = ? WHERE id = ?', [$new, (int) $a['id']]);
            Audit::write($db, $caller, 'carrier_account.set', 'carrier_account', (string) $a['id'], null, ['account_no' => [$a['account_no'], $new]]);
        });
        $cli->log("account {$a['account_no']} is now {$new} (still {$a['state']})");
        return Cli::OK;
    }
    $state = array_key_exists('switch-on', $opts) ? 'active' : (array_key_exists('switch-off', $opts) ? 'off' : throw new InvalidArgumentException('nothing to do'));
    if ($state === 'off' && $a['state'] === 'active') {
        // an account whose bills count is never switched off by itself: its money would stay in the profit with nothing planning it (the Codex
        // check r9 H14). Its bills undone first ("Undo this upload", or the release undo), then switched off.
        $live = (int) $cli->db->value("SELECT COUNT(*) FROM carrier_invoice WHERE carrier = ? AND account = ? AND status IN ('live', 'loading')", [$a['carrier'], $a['account_no']]);
        if ($live > 0) {
            $cli->error("account {$a['account_no']} has {$live} bill(s) in the profit: undo them first (each bill's page, 'Undo this upload'), then switch it off. Nothing changed.");
            return Cli::PROBLEM;
        }
    }
    $cli->db->transaction(static function (Db $db) use ($a, $state, $caller): void {
        $db->exec('UPDATE carrier_account SET state = ? WHERE id = ?', [$state, (int) $a['id']]);
        Audit::write($db, $caller, 'carrier_account.set', 'carrier_account', (string) $a['id'], null, ['state' => [$a['state'], $state]]);
    });
    $cli->log("account {$a['account_no']}: {$state}");
    return Cli::OK;
}

/**
 * A shop's first order number in CW (DL78), under the delivery bills' lock and audited (delivery_party.set): never worked out from data, so a
 * later backfill of the shop's older orders never moves it.
 */
function carrier_party_first_order(Cli $cli, string $code, int $first): int
{
    $p = $cli->db->one('SELECT id, code, channel_id, first_order_no FROM delivery_party WHERE code = ?', [$code])
        ?? throw new InvalidArgumentException('no delivery party of that code (bin/carrier_account.php --list)');
    if ($p['channel_id'] === null) {
        $cli->error("party {$p['code']} has no shop in CW: a first CW order number is a shop's");
        return Cli::PROBLEM;
    }
    $was = $p['first_order_no'] === null ? null : (int) $p['first_order_no'];
    if ($was === $first) {
        $cli->log("{$p['code']}: first CW order number {$first} already: nothing changed");
        return Cli::OK;
    }
    $cli->db->transaction(static function (Db $db) use ($p, $was, $first): void {
        $db->exec('UPDATE delivery_party SET first_order_no = ? WHERE id = ?', [$first, (int) $p['id']]);
        Audit::write($db, Caller::system('carrier_account'), 'delivery_party.set', 'delivery_party', (string) $p['id'], null, ['first_order_no' => [$was, $first]]);
    });
    $cli->log("{$p['code']}: first CW order number {$first} (was " . ($was ?? 'none') . '). The nightly re-plan puts its labels where they now belong (or run '
        . 'bin/match_delivery_bills.php).');
    return Cli::OK;
}
