<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Input;
use CW\Api\Response;
use CW\Purchasing;

/** GET /v1/purchasing?after_sku=<id>&limit= — per item stock + 90-day units by site (plan §3, §9). */
final class PurchasingController
{
    public function index(Context $c): Response
    {
        $q = $c->query();
        return Response::ok((new Purchasing($c->db))->report(
            Input::queryInt($q, 'after_sku', 0, 0, PHP_INT_MAX),
            Input::queryInt($q, 'limit', Purchasing::DEFAULT_LIMIT, 1, Purchasing::MAX_LIMIT),
        ));
    }
}
