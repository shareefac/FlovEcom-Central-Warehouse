<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Input;
use CW\Api\Response;
use CW\Availability;

/** GET /v1/snapshot?after_listing=<id>&limit= — every listing of the channel, paged (15-min resync). */
final class SnapshotController
{
    public function index(Context $c): Response
    {
        $q = $c->query();
        $after = Input::queryInt($q, 'after_listing', 0, 0, PHP_INT_MAX);
        $limit = Input::queryInt($q, 'limit', 1000, 1, 5000);
        return Response::ok((new Availability($c->db))->snapshot($c->channel->id, $after, $limit));
    }
}
