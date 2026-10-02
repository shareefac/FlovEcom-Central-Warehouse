<?php

declare(strict_types=1);

/**
 * Stores one file in the document store (CW\Files\FileStore, docs/decisions.md I23) and prints what was kept:
 *
 *   php bin/store_file.php --file=<path> --kind=<kind> [--note="..."] [--db=<schema>] [--admin]
 *
 * kind: supplier_invoice, delivery_note, packing_list, photo, duty_evidence, generated_pdf, other. The type is sniffed
 * from the content (PDF, JPEG, PNG, CSV, text, XLSX only), at most 25 MiB; the same content stored again returns the
 * row it already has (deduped=1). The file is kept under its sha256 at least 7 years (retain_until on its row), in the
 * directory app.env `file_store_dir` names (CW_FILE_STORE_DIR overrides; deploy/staging/install_file_store.sh sets it).
 * Until the I-2/I-3 screens bring browser uploads, this tool is how files reach CW.
 *
 * STDOUT: `id=<n> sha256=<hex> size=<bytes> mime=<type> deduped=<0|1>`. Audited `file.store` as system:store_file.
 * Exit codes: 0 stored · 1 refused (type, size, kind) · 2 usage · 3 cannot run (no file store, database, schema).
 */

use CW\Caller;
use CW\Config;
use CW\CwException;
use CW\Files\FileStore;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('store_file', ['file:', 'kind:', 'note:'],
    'usage: php bin/store_file.php --file=<path> --kind=<' . implode('|', FileStore::KINDS) . '> [--note="..."]',
    static function (Cli $cli, array $opts): int {
        $file = $opts['file'] ?? null;
        $kind = $opts['kind'] ?? null;
        $note = $opts['note'] ?? null;
        if (!is_string($file) || $file === '' || !is_string($kind) || ($note !== null && !is_string($note))) {
            throw new InvalidArgumentException('give --file=<path> and --kind=<kind> once each (and --note at most once)');
        }
        try {
            $store = FileStore::fromConfig(Config::load(), $cli->db);
            $r = $store->store(Caller::system('store_file'), $file, basename($file), $kind, $note);
        } catch (CwException $e) {
            $cli->error("{$e->errorCode}: {$e->getMessage()}");
            return $e->httpStatus >= 500 ? Cli::CANNOT_RUN : Cli::PROBLEM;
        }
        fwrite(STDOUT, sprintf("id=%d sha256=%s size=%d mime=%s deduped=%d\n", $r['id'], $r['sha256'], $r['size'], $r['mime'], $r['deduped'] ? 1 : 0));
        return Cli::OK;
    }, false));
