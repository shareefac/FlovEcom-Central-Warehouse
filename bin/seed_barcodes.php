<?php

declare(strict_types=1);

/**
 * Seeds sku_barcode from the listing every item was minted from (CW\Mapping\BarcodeSeeder, design S3):
 * the usable GTINs of its profile, as GTIN keys. Idempotent; bin/mint_vpg.php runs it for what it minted.
 * A barcode already on another item is not added: that item's row is marked unusable and counted.
 *
 *   php bin/seed_barcodes.php [--dry-run] [--db=<schema>] [--admin]
 *
 * Exit codes: 0 ok (clashes are reported, not errors) · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\Mapping\BarcodeSeeder;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('seed_barcodes', ['dry-run'], 'usage: php bin/seed_barcodes.php [--dry-run]',
    static function (Cli $cli, array $opts): int {
        $dry = array_key_exists('dry-run', $opts);
        $t0 = hrtime(true);
        $c = (new BarcodeSeeder($cli->db))->seed(Caller::system('seed_barcodes'), null, $dry);
        $cli->log(sprintf('%sitems=%d with_barcodes=%d %s=%d already=%d unusable_codes=%d clashes=%d rows_now=%d skipped_decided=%d ms=%d',
            $dry ? 'DRY RUN (nothing written) ' : '', $c['items'], $c['with_barcodes'], $dry ? 'would_add' : 'added', $c['added'], $c['already'],
            $c['unusable_codes'], $c['clashes'], (int) $cli->db->value('SELECT COUNT(*) FROM sku_barcode'), $c['skipped_decided'], intdiv(hrtime(true) - $t0, 1_000_000)));
        return Cli::OK;
    }));
