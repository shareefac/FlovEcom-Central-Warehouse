<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Input;
use CW\Api\Response;
use CW\CwException;
use CW\Reservations;

/**
 * POST /v1/opening_orders {orders: [{order_ref, lines}], final, t0?: {at, last_order_id,
 * last_stock_log_id}} (plan §8.1, D40, R12). The final batch is sent only after every earlier
 * batch answered 200 (R1), and needs t0 unless an earlier batch carried it.
 */
final class OpeningOrdersController
{
    public function create(Context $c): Response
    {
        $b = $c->json();
        if (!array_key_exists('final', $b)) {
            throw new CwException('bad_request', 'final (true for the last batch) is required', 400, ['field' => 'final']);
        }
        $t0 = $b['t0'] ?? null;
        if ($t0 !== null && (!is_array($t0) || (array_is_list($t0) && $t0 !== []))) {
            throw new CwException('bad_t0', 't0 must be an object {at, last_order_id, last_stock_log_id}', 400, ['field' => 't0']);
        }
        return Response::fromOpResult((new Reservations($c->db))->openingOrders(
            $c->caller(),
            Input::list($b['orders'] ?? null, 'orders', 'bad_orders'),
            Input::bool($b['final'], 'final'),
            $c->idemKey(),
            $t0,
        ));
    }
}
