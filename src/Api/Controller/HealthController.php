<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Response;
use CW\Clock;

/** GET /v1/health — what the site's circuit breaker probes: the channel's mode (plan §3, §5, §12). */
final class HealthController
{
    public function show(Context $c): Response
    {
        return Response::ok([
            'channel' => $c->channel->code,
            'mode' => $c->channel->mode,
            'time' => Clock::iso(Clock::db(Clock::now())),
        ]);
    }
}
