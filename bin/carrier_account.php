<?php

declare(strict_types=1);

/**
 * A carrier account's settings (delivery cost, phase 1; docs/decisions.md DL45, DL70): Vape Big's Royal Mail account is a row made by 0078 with
 * a placeholder number, OFF; when the owner sends its first bill: its number set here, the bill checked with bin/rm_bill.php (it must tie by
 * the day rule), then switched on here. Every change is audited (carrier_account.set). Never a key: a key is only in /etc/cw/carrier_keys.env.
 *
 *   php bin/carrier_account.php --list
 *   php bin/carrier_account.php --account=<number> --number=<new number>      (only a placeholder number, while no bill of the account is stored)
 *   php bin/carrier_account.php --account=<number> --switch-on | --switch-off
 *   php bin/carrier_account.php --account=<number> --add-channel=<code> --channel-name=<the channel's exact name in Click & Drop>
 *        (a new account's Click & Drop channels, before its first report is read: a report with another channel is refused, DL72)
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

exit(Cli::main('carrier_account', ['list', 'account:', 'number:', 'switch-on', 'switch-off', 'add-channel:', 'channel-name:'],
    'usage: php bin/carrier_account.php --list | --account=<number> (--number=<new> | --switch-on | --switch-off | --add-channel=<code> --channel-name=<exact name>)',
    static function (Cli $cli, array $opts): int {
        if (array_key_exists('list', $opts)) {
            foreach ($cli->db->all('SELECT a.carrier, a.account_no, a.name, a.state, a.key_name, p.code FROM carrier_account a JOIN delivery_party p ON p.id = a.main_party_id '
                . 'ORDER BY a.carrier, a.account_no') as $a) {
                $cli->log(sprintf('%-10s %-16s %-6s main %-12s key name %s  %s', $a['carrier'], $a['account_no'], $a['state'], $a['code'], $a['key_name'] ?? '-', $a['name']));
            }
            return Cli::OK;
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
        if (preg_match('/^[0-9]+$/D', (string) $a['account_no']) === 1) {
            // only a placeholder (0078's vapebig-pending) gets its real number: an account's real number never changes here (the Codex check r2, new
            // MEDIUM 6: 0078 seeds by number)
            $cli->error("account {$a['account_no']} has its number already: it is not changed");
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
    $cli->db->transaction(static function (Db $db) use ($a, $state, $caller): void {
        $db->exec('UPDATE carrier_account SET state = ? WHERE id = ?', [$state, (int) $a['id']]);
        Audit::write($db, $caller, 'carrier_account.set', 'carrier_account', (string) $a['id'], null, ['state' => [$a['state'], $state]]);
    });
    $cli->log("account {$a['account_no']}: {$state}");
    return Cli::OK;
}
