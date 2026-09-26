<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Response;
use CW\ListingProfiles;

/** PUT /v1/listings {listings: [...]} — writes listing_profile only (plan §3, D6). */
final class ListingsController
{
    public function put(Context $c): Response
    {
        return Response::ok((new ListingProfiles($c->db))->push($c->caller(), $c->json()['listings'] ?? null));
    }
}
