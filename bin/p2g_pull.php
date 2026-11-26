<?php

declare(strict_types=1);

/**
 * Parcel2Go's labels in CW's delivery cost (CW-1160, 0085; docs/decisions.md PGL1-PGL12; CW\Profit\Parcel2Go). Runs as root (the key files are
 * root's alone); every write under the Parcel2Go lock (one at a time).
 *
 *   php bin/p2g_pull.php --account=p2g_vapeandgo                  the 10-minute job: a key pasted on the key page taken first; nothing while
 *                                                                 the account is switched off; else the recent labels, tracking numbers,
 *                                                                 invoices, and the money placed again
 *   php bin/p2g_pull.php --account=p2g_vapeandgo --import=<csv>   Parcel2Go's "My Orders" export read (the backfill; after a gap alert)
 *   php bin/p2g_pull.php --account=p2g_vapeandgo --switch-on --from=YYYY-MM-DD   (the go-live kit's switch step only)
 *   php bin/p2g_pull.php --account=p2g_vapeandgo --switch-off     the quick stop: nothing more is read or counted, nothing counted is removed
 *   php bin/p2g_pull.php --account=p2g_vapeandgo --undo-all       every Parcel2Go bill of the account undone (switched off first; the kit's undo)
 *   php bin/p2g_pull.php --account=p2g_vapeandgo --retry-refused  refused labels due again (after the reader was corrected)
 *   php bin/p2g_pull.php --account=p2g_vapeandgo --refresh-invoice=<transaction>   a basket's invoice read again (a corrected invoice: the
 *                                                                 bill there stays live until the new one is read and stored)
 *   php bin/p2g_pull.php --account=p2g_vapeandgo --status [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]   read only
 *
 * Options are read strictly (a misspelt one is a usage error, never the pull). Exit codes: 0 done (also: switched off; the 10-minute job when
 * another run holds the lock) · 1 a problem (Parcel2Go not read, an invoice refused: the run is recorded as failed, what was read stays, its
 * labels due) · 2 usage · 3 cannot run (no key, a refused key, no schema) · 4 busy: a CONTROL mode (import, switch, undo, retry, refresh) found
 * another run holding the Parcel2Go lock (the quick stop waits up to 300 s first): NOTHING was done, run it again.
 */

use CW\Caller;
use CW\Config;
use CW\CwException;
use CW\Ops\Cli;
use CW\Profit\CurlP2gHttp;
use CW\Profit\P2gApi;
use CW\Profit\P2gKey;
use CW\Profit\P2gKeyDrop;
use CW\Profit\Parcel2Go;
use CW\Profit\PdftotextText;

require dirname(__DIR__) . '/vendor/autoload.php';

const P2G_DEADLINE_SEC = 240;

$usage = 'usage: php bin/p2g_pull.php --account=<p2g_vapeandgo|p2g_vapebig> [--import=<csv> | --switch-on --from=YYYY-MM-DD | --switch-off | --undo-all '
    . '| --retry-refused | --refresh-invoice=<transaction> | --status [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]]';
exit(Cli::main('p2g_pull', ['account:', 'import:', 'switch-on', 'switch-off', 'undo-all', 'retry-refused', 'refresh-invoice:', 'status', 'from:', 'to:'], $usage,
    static function (Cli $cli, array $opts) use ($usage): int {
        $account = is_string($opts['account'] ?? null) ? $opts['account'] : '';
        if (!in_array($account, P2gKey::ACCOUNTS, true)) {
            throw new InvalidArgumentException("--account=<p2g_vapeandgo|p2g_vapebig> is needed\n{$usage}");
        }
        $modes = array_values(array_filter(['import', 'switch-on', 'switch-off', 'undo-all', 'retry-refused', 'refresh-invoice', 'status'],
            static fn (string $m): bool => isset($opts[$m])));
        if (count($modes) > 1 || (isset($opts['from']) && !in_array($modes[0] ?? '', ['switch-on', 'status'], true)) || (isset($opts['to']) && ($modes[0] ?? '') !== 'status')
            || (($modes[0] ?? '') === 'switch-on' && !isset($opts['from']))
            || (isset($opts['refresh-invoice']) && (!is_string($opts['refresh-invoice']) || preg_match('/^[1-9][0-9]{0,11}$/D', $opts['refresh-invoice']) !== 1))) {
            throw new InvalidArgumentException($usage);
        }
        $p2g = new Parcel2Go($cli->db);
        $caller = Caller::system('p2g_pull');
        $mode = $modes[0] ?? 'pull';
        $say = static fn (string $s) => fwrite(STDOUT, $s . "\n");
        try {
            switch ($mode) {
                case 'status':
                    $s = Parcel2Go::status($cli->db, $account, is_string($opts['from'] ?? null) ? $opts['from'] : null, is_string($opts['to'] ?? null) ? $opts['to'] : null);
                    $say(json_encode($s, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                    return Cli::OK;
                case 'switch-on':
                    $p2g->switchOn($caller, $account, (string) $opts['from']);
                    $say("{$account}: switched on from {$opts['from']}");
                    return Cli::OK;
                case 'switch-off':
                    $p2g->switchOff($caller, $account);
                    $say("{$account}: switched off (nothing counted was removed)");
                    return Cli::OK;
                case 'undo-all':
                    $r = $p2g->undoAll($caller, $account);
                    $say("{$account}: {$r['bills']} Parcel2Go bills undone ({$r['pence']}p net)");
                    return Cli::OK;
                case 'retry-refused':
                    $say("{$account}: " . $p2g->retryRefused($caller, $account) . ' refused labels due again');
                    return Cli::OK;
                case 'import':
                    $r = $p2g->import($caller, $account, (string) $opts['import']);
                    $say("{$account}: export read: {$r['rows']} rows, {$r['lines']} labels ({$r['lines_new']} new), {$r['tracking_filled']} tracking numbers filled");
                    return Cli::OK;
                case 'refresh-invoice':
                    $keyDir = P2gKeyDrop::keyDir(Config::load());
                    $deadline = microtime(true) + P2G_DEADLINE_SEC;
                    $api = new P2gApi(P2gKey::load(P2gKey::path($keyDir, $account), $account), new CurlP2gHttp(), null, $deadline);
                    $r = $p2g->refreshInvoice($caller, $account, $api, new PdftotextText(), (int) $opts['refresh-invoice']);
                    $say("{$account}: invoice TX-{$opts['refresh-invoice']} read again: {$r}");
                    return Cli::OK;
            }
            // the 10-minute job: a pasted key taken first (root; set only once Parcel2Go gives a token for it), then the run (nothing while off)
            $config = Config::load();
            $keyDir = P2gKeyDrop::keyDir($config);
            $prove = static function (P2gKey $k): void {
                (new P2gApi($k, new CurlP2gHttp(), null, microtime(true) + 60))->proveToken();
            };
            if (P2gKeyDrop::take($cli->db, P2gKeyDrop::dir($config), $keyDir, P2gKeyDrop::secretPath($config), $account, $prove)) {
                $say("{$account}: the key pasted on the key page was taken (Parcel2Go gave a token for it)");
            }
            if (!Parcel2Go::account($cli->db, $account)['on']) {
                $say("{$account}: switched off: nothing read");
                return Cli::OK;
            }
            $deadline = microtime(true) + P2G_DEADLINE_SEC;
            $api = new P2gApi(P2gKey::load(P2gKey::path($keyDir, $account), $account), new CurlP2gHttp(), null, $deadline);
            $r = $p2g->run($caller, $account, $api, new PdftotextText(), $deadline);
            $say(sprintf('%s: %s; %d listed, %d new, %d tracking numbers, %d invoices stored, %d refused, %d placed, %d calls%s', $account, (string) $r['result'],
                (int) ($r['listed'] ?? 0), (int) ($r['lines_new'] ?? 0), (int) ($r['tracking_found'] ?? 0), (int) ($r['priced'] ?? 0), (int) ($r['refused'] ?? 0),
                (int) ($r['placed'] ?? 0), (int) ($r['calls'] ?? 0), ((int) ($r['gap_seen'] ?? 0)) === 1 ? '; LINES MAY BE MISSING: import the export' : ''));
            return Cli::OK;
        } catch (CwException $e) {
            fwrite(STDERR, "p2g_pull: {$account}: " . Parcel2Go::why($e) . "\n");
            if ($e->errorCode === 'busy') {
                // the 10-minute job: the next run goes on; a control mode did NOTHING: never a quiet success (H4 / F6)
                return $mode === 'pull' ? Cli::OK : Cli::BUSY;
            }
            return in_array($e->errorCode, ['p2g_key_refused', 'not_found'], true) ? Cli::CANNOT_RUN : Cli::PROBLEM;
        }
    }, false, true));
