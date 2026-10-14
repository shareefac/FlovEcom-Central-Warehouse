<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Response;
use CW\Relay\RelayIntake;

/**
 * The relay's two queue routes (plan §9; docs/decisions.md PF2, PF3): POST /v1/relay/reconciliations (ERPNext's corrections, to the
 * count-review queue) and POST /v1/relay/modes (ERPNext's selling-mode changes, to the policy-review queue). The three stock kinds stay
 * on POST /v1/movements.
 */
final class RelayController
{
    public function reconciliations(Context $c): Response
    {
        return Response::fromOpResult((new RelayIntake($c->db))->reconciliations($c->caller(), $c->json(), $c->idemKey()));
    }

    public function modes(Context $c): Response
    {
        return Response::fromOpResult((new RelayIntake($c->db))->modes($c->caller(), $c->json(), $c->idemKey()));
    }
}
