<?php

declare(strict_types=1);

/**
 * The barcode sync (IM3; CW\Catalogue\BarcodeSync, docs/decisions.md I106-I108): the barcodes the sites hold for LINKED listings
 * (listing_profile.barcodes) into the items' sku_barcode rows. A barcode no item has is added (units per scan 1); one already on
 * another item, or a new one on a multipack listing (units per item <> 1), goes to the barcode review (Items > Barcode review)
 * and is never moved; a barcode already on another item becomes unusable there until a person decides.
 *
 *   php bin/sync_barcodes.php [--apply] [--channel=<code>] [--limit=<listings>] [--db=<schema>] [--admin]
 *
 * DRY RUN by default: reads only and prints what a real run would do. --apply writes (one transaction per 1,000 listings, one
 * audit row sku_barcode.sync). Idempotent: run it again and it finds everything already added, in review or decided. It also
 * prints (never changes) the barcodes added earlier from a listing that is no longer linked to their item (source_unlinked).
 *
 * Exit codes: 0 done (reviews opened are work for people, not errors) · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\Catalogue\BarcodeSync;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('sync_barcodes', ['apply', 'channel:', 'limit:'], 'usage: php bin/sync_barcodes.php [--apply] [--channel=<code>] [--limit=<listings>]',
    static function (Cli $cli, array $opts): int {
        $apply = array_key_exists('apply', $opts);
        $limit = isset($opts['limit']) ? Cli::intOpt($opts, 'limit', 0, 1, 10_000_000) : null;
        $channelId = null;
        if (isset($opts['channel'])) {
            $code = $opts['channel'];
            if (!is_string($code) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $code) !== 1) {
                throw new InvalidArgumentException('--channel must be a channel code (e.g. vapeandgo)');
            }
            $channelId = $cli->db->value('SELECT id FROM channel WHERE code = ?', [$code]);
            if ($channelId === null) {
                throw new InvalidArgumentException("there is no channel {$code}");
            }
            $channelId = (int) $channelId;
        }
        $sync = new BarcodeSync($cli->db);
        $c = $sync->run(Caller::system('sync_barcodes'), $apply, $channelId, $limit);
        $cli->log(sprintf('%slistings=%d codes=%d unusable_codes=%d (junk=%d restricted=%d) already=%d skipped_decided=%d in_review=%d %s=%d '
            . 'review_on_another_item=%d (holder_merged=%d) review_multipack_listing=%d made_unusable=%d raced=%d open_reviews_now=%d source_unlinked=%d ms=%d',
            $apply ? '' : 'DRY RUN (nothing written) ', $c['listings'], $c['codes'], $c['unusable_codes'], $c['junk_codes'], $c['restricted_codes'], $c['already'],
            $c['skipped_decided'], $c['in_review'], $apply ? 'added' : 'would_add', $c['added'], $c['review_on_another_item'], $c['review_holder_merged'],
            $c['review_multipack_listing'], $c['made_unusable'], $c['raced'], (int) $cli->db->value("SELECT COUNT(*) FROM barcode_review WHERE status = 'open'"),
            $c['source_unlinked'], $c['ms']));
        if ($c['source_unlinked'] > 0) {
            // Never changed by the sync (I118): a person looks at each on the item page and removes what is wrong.
            $u = $sync->unlinkedSources(20);
            $cli->log("barcodes from a listing no longer linked to their item ({$u['count']}; the first " . count($u['rows']) . '):');
            foreach ($u['rows'] as $r) {
                $cli->log("  {$r['barcode']} on {$r['code']} from listing {$r['listing_id']} (" . ($r['listing_status'] === null ? 'listing gone'
                    : "now {$r['listing_status']}" . ($r['listing_code'] !== null ? " to {$r['listing_code']}" : '')) . ')');
            }
        }
        return Cli::OK;
    }));
