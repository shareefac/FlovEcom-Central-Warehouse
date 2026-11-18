<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Documents\Documents;
use CW\Live\Presence;
use CW\Ui\Context;
use CW\Ui\HtmlResponse;
use CW\Ui\Router;

/**
 * Who is here on a draft (the live drafts plan, step 3; docs/decisions.md LV3, LV4): the poll an open draft page sends by itself, answered in
 * JSON (Context::json: names and numbers, no words) by CW\Live\Presence. A POST like every other (sign-in, the route's permission, same origin,
 * the session's token; no form-once key: a poll changes nothing but its own row, so it may come any number of times). Passive: it marks the
 * session seen only when it says active=1 (Ui\Route::$passive, Ui\Kernel), so a page left open is still signed out after the idle limit.
 *
 * Step 3 has one page: purchase orders, for whoever may open an order (purchasing.view). The page's look and its script (the poll loop, the
 * chips and the marks on the lines) come with step 3b.
 */
final class LiveController
{
    public static function routes(Router $r): void
    {
        $c = new self();
        $r->add('POST', '/ui/purchasing/orders/{id}/live', 'purchasing.view', $c->purchaseOrder(...), passive: true);
    }

    public function purchaseOrder(Context $ctx): HtmlResponse
    {
        return $this->beat($ctx, 'po');
    }

    private function beat(Context $ctx, string $kind): HtmlResponse
    {
        $me = $ctx->me();
        $in = Presence::input($ctx->req->post);
        $edits = Documents::mayPost($me->roles, Presence::KINDS[$kind]['type']);
        return $ctx->json((new Presence($ctx->db, $ctx->sessions()))->beat($kind, $ctx->id(), $me->id, $me->sessionId, $edits, $in));
    }
}
