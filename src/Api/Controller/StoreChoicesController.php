<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Response;
use CW\Mapping\StoreChoices;

/**
 * PUT /v1/store-choices {choices: [...]} — what a store's staff said each new variant is, at Add product (docs/decisions.md SC1, SC2):
 * kept, waiting for bin/apply_store_choices.php. The first choice of a variant is kept; a later one is answered `already_received`.
 */
final class StoreChoicesController
{
    public function put(Context $c): Response
    {
        return Response::ok((new StoreChoices($c->db))->receive($c->caller(), $c->json()['choices'] ?? null));
    }
}
