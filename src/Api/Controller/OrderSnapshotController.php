<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Response;
use CW\Profit\OrderSnapshots;
use CW\Profit\SnapshotBody;

/**
 * PUT /v1/orders/{ref}/snapshot (profit step 1; the agreed contract, docs/decisions.md PR1-PR3): a store's whole picture of one order's
 * money and parcels. Not a stock write: accepted in every store mode (off, shadow, live), like PUT /v1/listings. Idempotent without a key:
 * the same content again answers `same`, an older version `older_ignored`, else `stored` (CW\Profit\OrderSnapshots::accept). A body over
 * 256 KB is 413 body_too_large, refused before more than that is read; a bad field 400 bad_snapshot with `field`. It only STORES the snapshot
 * (one short transaction, no day summary, no cost read): bin/apply_order_snapshots.php works the money out, so a checkout's stock call in the
 * same PHP workers never waits behind it (the final review of 6d59eda, a-HIGH2, PR95). An order placed before its store's first
 * reservation in CW is answered 200 `out_of_range` and never stored or queued (the backfill's boundary, PR148, on the live feed: PR154);
 * the connector marks it sent.
 */
final class OrderSnapshotController
{
    public function put(Context $c): Response
    {
        $raw = $c->request->body(SnapshotBody::MAX_BYTES);
        $ref = $c->param('ref');
        $json = $c->json();
        SnapshotBody::checkJsonShapes($raw);
        // An order placed before the store's first reservation in CW: 200 out_of_range, nothing stored (the backfill's boundary on the live
        // feed: the third final review, c-HIGH1 / a-M1, PR154).
        $r = (new OrderSnapshots($c->db))->accept($c->channel->id, $ref, $json, 'api', true);
        unset($r['queued']);
        return Response::ok($r);
    }
}
