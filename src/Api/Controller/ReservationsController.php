<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Input;
use CW\Api\Response;
use CW\CwException;
use CW\Reservations;

/**
 * POST /v1/reservations and /v1/reservations/{ref}/commit|release|cancel|uncancel|ship|unship|return
 * (plan §3, §4). The state machine is CW\Reservations; this only maps JSON to its calls.
 */
final class ReservationsController
{
    /** {order_ref, lines: [{variant_id, qty, unit_ids[]}]} */
    public function reserve(Context $c): Response
    {
        $b = $c->json();
        return Response::fromOpResult($this->res($c)->reserve(
            $c->caller(),
            Input::ref($b['order_ref'] ?? null),
            Input::list($b['lines'] ?? null, 'lines', 'bad_lines'),
            $c->idemKey(),
        ));
    }

    /** {lines, origin = reserved|unreserved|opening (default reserved)} */
    public function commit(Context $c): Response
    {
        $b = $c->json();
        return Response::fromOpResult($this->res($c)->commit(
            $c->caller(),
            $c->param('ref'),
            Input::list($b['lines'] ?? null, 'lines', 'bad_lines'),
            Input::optString($b['origin'] ?? null, 'origin') ?? 'reserved',
            $c->idemKey(),
        ));
    }

    /** {attempt?} (an empty body means attempt 1, R9: it can never drop a later attempt's hold) */
    public function release(Context $c): Response
    {
        $b = $c->json(true);
        return Response::fromOpResult($this->res($c)->release(
            $c->caller(),
            $c->param('ref'),
            Input::optInt($b['attempt'] ?? null, 'attempt', 1, Reservations::MAX_ATTEMPT),
            $c->idemKey(),
        ));
    }

    /** {unit_ids, restockable} — restockable is required: the site sends its tick as it is. */
    public function cancel(Context $c): Response
    {
        $b = $c->json();
        if (!array_key_exists('restockable', $b)) {
            throw new CwException('bad_request', 'restockable (true/false) is required', 400, ['field' => 'restockable']);
        }
        return Response::fromOpResult($this->res($c)->cancel(
            $c->caller(),
            $c->param('ref'),
            Input::list($b['unit_ids'] ?? null, 'unit_ids', 'bad_unit_ids'),
            Input::bool($b['restockable'], 'restockable'),
            $c->idemKey(),
        ));
    }

    /** {unit_ids} — cancelled units back to allocated (D46): the site took a line cancel back. */
    public function uncancel(Context $c): Response
    {
        $b = $c->json();
        return Response::fromOpResult($this->res($c)->uncancel(
            $c->caller(),
            $c->param('ref'),
            Input::list($b['unit_ids'] ?? null, 'unit_ids', 'bad_unit_ids'),
            $c->idemKey(),
        ));
    }

    /** {unit_ids, dispatched_at} */
    public function ship(Context $c): Response
    {
        $b = $c->json();
        return Response::fromOpResult($this->res($c)->ship(
            $c->caller(),
            $c->param('ref'),
            Input::list($b['unit_ids'] ?? null, 'unit_ids', 'bad_unit_ids'),
            $b['dispatched_at'] ?? null,
            $c->idemKey(),
        ));
    }

    /**
     * {unit_ids, at} — `at` is when the dispatch was reset (D35), required (R10). `dispatched_at`
     * is refused here: on ship it is the dispatch time, and a connector that reverses a ship with
     * the ship's own payload would book a reset after a count as one before it.
     */
    public function unship(Context $c): Response
    {
        $b = $c->json();
        if (array_key_exists('dispatched_at', $b)) {
            throw new CwException('bad_request', 'unship takes `at`, the time of the dispatch reset; dispatched_at belongs to ship', 400,
                ['field' => 'dispatched_at']);
        }
        if (($b['at'] ?? null) === null) {
            throw new CwException('at_required', 'unship needs `at`, the time the dispatch was reset', 400, ['field' => 'at']);
        }
        return Response::fromOpResult($this->res($c)->unship(
            $c->caller(),
            $c->param('ref'),
            Input::list($b['unit_ids'] ?? null, 'unit_ids', 'bad_unit_ids'),
            $b['at'],
            $c->idemKey(),
        ));
    }

    /** {unit_ids} */
    public function returnUnits(Context $c): Response
    {
        $b = $c->json();
        return Response::fromOpResult($this->res($c)->returnUnits(
            $c->caller(),
            $c->param('ref'),
            Input::list($b['unit_ids'] ?? null, 'unit_ids', 'bad_unit_ids'),
            $c->idemKey(),
        ));
    }

    private function res(Context $c): Reservations
    {
        return new Reservations($c->db);
    }
}
