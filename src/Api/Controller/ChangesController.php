<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Input;
use CW\Api\Response;
use CW\Availability;

/** GET /v1/changes?after=<seq>&limit= — the change feed the site worker polls (plan §3, §4, D38). */
final class ChangesController
{
    public function index(Context $c): Response
    {
        $q = $c->query();
        $after = Input::queryInt($q, 'after', 0, 0, PHP_INT_MAX);
        $limit = Input::queryInt($q, 'limit', Availability::MAX_CHANGE_ROWS, 1, Availability::MAX_CHANGE_ROWS);
        return Response::ok((new Availability($c->db))->changes($c->channel->id, $after, $limit));
    }
}
