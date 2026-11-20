<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Response;
use CW\CwException;
use CW\Profit\OrderSnapshots;
use CW\Profit\SnapshotBody;

/**
 * PUT /v1/orders/{ref}/snapshot (profit step 1; the agreed contract, docs/decisions.md PR1-PR3): a store's whole picture of one order's
 * money and parcels. Not a stock write: accepted in every store mode (off, shadow, live), like PUT /v1/listings. Idempotent without a key:
 * the same content again answers `same`, an older version `older_ignored`, else `stored` (CW\Profit\OrderSnapshots). A body over 256 KB
 * is 413 body_too_large; a bad field 400 bad_snapshot with `field`.
 */
final class OrderSnapshotController
{
    public function put(Context $c): Response
    {
        if (strlen($c->request->body()) > SnapshotBody::MAX_BYTES) {
            throw new CwException('body_too_large', 'a snapshot may be at most ' . intdiv(SnapshotBody::MAX_BYTES, 1024) . ' KB', 413);
        }
        $ref = $c->param('ref');
        return Response::ok((new OrderSnapshots($c->db))->store($c->channel->id, $ref, $c->json(), 'api'));
    }
}
