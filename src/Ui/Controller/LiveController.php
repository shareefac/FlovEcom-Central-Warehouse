<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Documents\Document;
use CW\Documents\DraftChanges;
use CW\Documents\Documents;
use CW\Live\Presence;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Router;
use CW\Ui\UiRequest;

/**
 * Who is here on a draft (the live drafts plan, step 3; docs/decisions.md LV3, LV4): the poll an open draft page sends by itself, answered in
 * JSON (Context::json: names and numbers, no words) by CW\Live\Presence. A POST like every other (sign-in, the route's permission, same origin,
 * the session's token; no form-once key: a poll changes nothing but its own row, so it may come any number of times). Passive: it marks the
 * session seen only when it says active=1 (Ui\Route::$passive, Ui\Kernel), so a page left open is still signed out after the idle limit.
 *
 * Step 3 has one page: purchase orders, for whoever may open an order (purchasing.view). Step 3b (docs/decisions.md LV18-LV25) draws it on the
 * page: pageData() gives a draft's page what its script needs (the poll's address, the page's own tab, the version it shows, the rhythm of
 * Presence), and a poll that says the version its page shows (`v`) and finds the draft saved since is told who saved it and when (`changed`:
 * "Fazil saved a change at 14:02. [Show it]"; one read, only then).
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

    /**
     * What a draft's page needs to show who is here (step 3b), or null when nothing is shown: live.mode off, or the document no longer a draft
     * (a confirmed order shows nobody). $version: the version the page shows (the editor's form version: a page drawn again after a refusal
     * shows the version it was typed on).
     *
     * @return array{url: string, tab: string, v: int, page: string, login: string, ms: int, away_ms: int, stop_ms: int, max_ms: int, slow_ms: int, active_ms: int, jitter_ms: int, say_ms: int}|null
     */
    public static function pageData(Context $ctx, string $kind, Document $doc, int $version): ?array
    {
        if ($doc->status !== 'draft' || $doc->docType !== (Presence::KINDS[$kind]['type'] ?? null)) {
            return null;
        }
        $s = Presence::settings($ctx->db);
        if ($s['mode'] === 'off') {
            return null;
        }
        $page = Presence::pagePath($kind, $doc->id);
        return ['url' => $page . '/live', 'tab' => Presence::newTab(), 'v' => $version, 'page' => $page, 'login' => Html::url('/ui/login', ['back' => $page]),
            'ms' => $s['alone'] * 1000, 'away_ms' => Presence::IDLE_AWAY_SECONDS * 1000, 'stop_ms' => Presence::AWAY_SECONDS * 1000,
            'max_ms' => Presence::BACKOFF_SECONDS * 1000, 'slow_ms' => Presence::SLOW_MS, 'active_ms' => Presence::ACTIVE_SECONDS * 1000, 'jitter_ms' => Presence::JITTER_MS,
            'say_ms' => Presence::SAY_SECONDS * 1000];
    }

    private function beat(Context $ctx, string $kind): HtmlResponse
    {
        $me = $ctx->me();
        $in = Presence::input($ctx->req->post);
        $edits = Documents::mayPost($me->roles, Presence::KINDS[$kind]['type']);
        $answer = (new Presence($ctx->db, $ctx->sessions()))->beat($kind, $ctx->id(), $me->id, $me->sessionId, $edits, $in);
        // The page shows an older version of the draft: who saved it last, and when (UK time), so the page can say so (one read, only then).
        $seen = UiRequest::id($ctx->req->field('v'));
        if (isset($answer['here']) && $seen !== null && $seen !== $answer['v'] && !$in['bye']) {
            $last = DraftChanges::last($ctx->db, $ctx->id());
            if ($last !== null) {
                $answer['changed'] = ['by' => $last['name'], 'at' => Html::clock($last['at']), 'you' => $last['staff_user_id'] === $me->id];
            }
        }
        return $ctx->json($answer);
    }
}
