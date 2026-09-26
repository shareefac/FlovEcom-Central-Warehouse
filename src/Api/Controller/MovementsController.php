<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Response;
use CW\Movements;

/** POST /v1/movements {type, lines, doc_ref, ...} (plan §3, §9; D41, D42). */
final class MovementsController
{
    public function create(Context $c): Response
    {
        return Response::fromOpResult((new Movements($c->db))->record($c->caller(), $c->json(), $c->idemKey()));
    }
}
