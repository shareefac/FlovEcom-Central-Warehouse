<?php

declare(strict_types=1);

/**
 * Re-hashes every stored file through the file store (CW\Files\FileStore::verify, docs/decisions.md I23): a row whose
 * bytes are missing or no longer match its sha256 is a problem; a stored file no row names (an orphan, left by a
 * store whose commit failed after the bytes were written) is counted and kept.
 *
 *   php bin/verify_files.php [--limit=N] [--db=<schema>] [--admin]
 *
 * Problems go to stderr, one line each (`missing id=<n> sha256=<hex>`, `mismatch id=<n> sha256=<hex>`); the summary
 * goes to stdout (`checked=<n> ok=<n> missing=<n> mismatch=<n> orphans=<n> ms=<n>`). Read-only. To be scheduled
 * nightly once the store holds real files (docs/ops.md).
 * Exit codes: 0 every file checked is intact (orphans allowed) · 1 a file is missing or changed · 2 usage · 3 cannot run.
 */

use CW\Config;
use CW\CwException;
use CW\Files\FileStore;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('verify_files', ['limit:'], 'usage: php bin/verify_files.php [--limit=N]',
    static function (Cli $cli, array $opts): int {
        $limit = isset($opts['limit']) ? Cli::intOpt($opts, 'limit', 0, 1, 100_000_000) : null;
        $started = hrtime(true);
        try {
            $r = FileStore::fromConfig(Config::load(), $cli->db)->verify($limit);
        } catch (CwException $e) {
            $cli->error("{$e->errorCode}: {$e->getMessage()}");
            return Cli::CANNOT_RUN;
        }
        foreach (['missing', 'mismatch'] as $kind) {
            foreach ($r[$kind] as $f) {
                $cli->error("{$kind} id={$f['id']} sha256={$f['sha256']}");
            }
        }
        $summary = sprintf('checked=%d ok=%d missing=%d mismatch=%d orphans=%d ms=%d', $r['checked'], $r['ok'], count($r['missing']), count($r['mismatch']),
            $r['orphans'], intdiv(hrtime(true) - $started, 1_000_000));
        if ($r['missing'] === [] && $r['mismatch'] === []) {
            $cli->log("ok: {$summary}");
            return Cli::OK;
        }
        $cli->log("PROBLEM: {$summary}; details on stderr");
        return Cli::PROBLEM;
    }, false));
