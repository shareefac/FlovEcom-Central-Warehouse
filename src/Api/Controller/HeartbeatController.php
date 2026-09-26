<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Response;
use CW\Heartbeat;

/** POST /v1/heartbeat — the site worker's 60-second health report (plan §3, §14). */
final class HeartbeatController
{
    public function create(Context $c): Response
    {
        return Response::fromOpResult((new Heartbeat($c->db))->record($c->caller(), $c->json(), $c->idemKey()));
    }
}
