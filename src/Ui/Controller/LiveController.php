<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Clock;
use CW\CwException;
use CW\Documents\Document;
use CW\Documents\DraftChanges;
use CW\Documents\Documents;
use CW\Live\LiveChanges;
use CW\Live\Presence;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\LiveSaveWords;
use CW\Ui\Router;
use CW\Ui\UiRequest;
use CW\Ui\Words;

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
    /** The retries of a box's save that found no network (milliseconds, then the last again; docs/decisions.md LS12), printed in the page. */
    public const RETRY_MS = [1000, 3000, 10000];
    /** The ops of POST /live/line (docs/decisions.md LS3). */
    public const OPS = ['set', 'add', 'remove', 'restore'];

    public static function routes(Router $r): void
    {
        $c = new self();
        $r->add('POST', '/ui/purchasing/orders/{id}/live', 'purchasing.view', $c->purchaseOrder(...), passive: true);
        // Each box saved by itself (the live drafts plan, step 4, 4.3; docs/decisions.md LS10): the draft's own fragment, fetched by the page
        // when its poll says the draft moved (passive: the page sends it by itself, so it marks no session seen); one line and one head box
        // saved (a person's own act: the session is seen, as any save).
        $r->add('GET', '/ui/purchasing/orders/{id}/live/doc', 'purchasing.view', $c->poDoc(...), passive: true);
        $r->add('POST', '/ui/purchasing/orders/{id}/live/line', 'doc.PO.post', $c->poLine(...));
        $r->add('POST', '/ui/purchasing/orders/{id}/live/header', 'doc.PO.post', $c->poHeader(...));
    }

    /**
     * What the paper's editor needs to save each box by itself (live.mode on; docs/decisions.md LS10, LS12), or null (not on): the line's and
     * the head's addresses, the draft's own fragment, Confirm's address, the newest line-by-line change the page shows, the retry rhythm.
     *
     * @return array{save: string, head: string, doc: string, confirm: string, e: int, retry: string}|null
     */
    public static function saveData(Context $ctx, Document $doc): ?array
    {
        if ($doc->status !== 'draft' || $doc->docType !== 'PO' || Presence::settings($ctx->db)['mode'] !== 'on') {
            return null;
        }
        $page = Presence::pagePath('po', $doc->id);
        return ['save' => $page . '/live/line', 'head' => $page . '/live/header', 'doc' => $page . '/live/doc', 'confirm' => $page . '/approve',
            'e' => LiveSaveWords::newest($ctx->db, $doc->id), 'retry' => implode(' ', self::RETRY_MS)];
    }

    /**
     * GET /ui/purchasing/orders/{id}/live/doc?since=&tab=: the draft as it is now, the fragment po_live (docs/decisions.md LS10): its head's
     * values, every line (data-uid, data-rev, data-by), the totals, the notes, the History card, and the changes after `since` (the newest
     * the page shows) not made by the page's own tab, in words. 409 with the refusal `live_off` while live.mode is not on; 409 `not_draft`
     * (who made it final) once it is not a draft; 404 for no such order.
     */
    public function poDoc(Context $ctx): HtmlResponse
    {
        $doc = $this->order($ctx);
        if ($doc === null) {
            return $ctx->error(404, 'unknown_purchase_order', Words::BUY_ERROR['unknown_purchase_order']);
        }
        if (Presence::settings($ctx->db)['mode'] !== 'on') {
            return $this->fragment($ctx, $doc, 409, ['refusal' => LiveSaveWords::refusal(new CwException('live_off', '', 409), [])]);
        }
        if ($doc->status !== 'draft') {
            $row = (array) $ctx->db->one('SELECT * FROM document WHERE id = ?', [$doc->id]);
            return $this->fragment($ctx, $doc, 409, ['refusal' => LiveSaveWords::refusal(Documents::notDraft($ctx->db, $row), [])]);
        }
        $ctx->documents()->ensureUids($doc->id);
        return $this->fragment($ctx, $doc, 200, ['since' => UiRequest::id($ctx->req->param('since')) ?? 0, 'tab' => LiveChanges::tab($ctx->req->param('tab'))]);
    }

    /**
     * POST /ui/purchasing/orders/{id}/live/line (doc.PO.post; the session's token and the same origin, as every POST): one line's change, by
     * `op`: set (uid, rev, and the boxes sent: packs, price, upp, si, or sku with u), add (si, or sku with u, or charge: the delivery; packs,
     * price, k), remove (uid, rev), restore (uid, after, k; Undo); `more` = 1 answers "Add N more". The answer is the draft as it is now
     * (po_live) with what was done (data-op-status: saved, unchanged, added, incremented, done, removed, restored; data-op-uid); a refusal
     * (409, 422, a request CW cannot take: 400) is the same fragment with the refusal in words on its line (live_conflict). A request of
     * another shape: 400.
     */
    public function poLine(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $op = $req->field('op') ?? '';
        if (!in_array($op, self::OPS, true)) {
            return $ctx->error(400, 'bad_op', Words::ERROR['bad_form']);
        }
        $uid = UiRequest::id($req->field('uid'));
        $rev = UiRequest::id($req->field('rev'));
        if (($op !== 'add' && $uid === null) || (in_array($op, ['set', 'remove'], true) && $rev === null)) {
            return $ctx->error(400, 'bad_line', Words::ERROR['bad_form']);
        }
        if (($op === 'add' || $op === 'restore') && preg_match(LiveChanges::KEY, (string) $req->field('k')) !== 1) {
            return $ctx->error(400, 'bad_key', Words::ERROR['bad_form']);
        }
        $doc = $this->order($ctx);
        if ($doc === null) {
            return $ctx->error(404, 'unknown_purchase_order', Words::BUY_ERROR['unknown_purchase_order']);
        }
        if (Presence::settings($ctx->db)['mode'] !== 'on') {
            return $this->fragment($ctx, $doc, 409, ['refusal' => LiveSaveWords::refusal(new CwException('live_off', '', 409), []), 'op' => ['op' => $op]]);
        }
        $svc = $this->pos($ctx);
        $tab = LiveChanges::tab($req->field('tab'));
        $more = $req->field('more') === '1';
        $sent = [];
        try {
            switch ($op) {
                case 'set':
                    foreach (['packs' => 'packs', 'price' => 'pack_price', 'upp' => 'units_per_pack'] as $in => $k) {
                        if ($req->field($in) !== null) {
                            $sent[$k] = $req->field($in);
                        }
                    }
                    if (UiRequest::id($req->field('si')) !== null) {
                        $sent['supplier_item_id'] = UiRequest::id($req->field('si'));
                    } elseif (UiRequest::id($req->field('sku')) !== null) {
                        $sent['sku_id'] = UiRequest::id($req->field('sku'));
                        $sent['units_per_pack'] = $req->field('u') ?? $sent['units_per_pack'] ?? '1';
                    }
                    $r = $svc->setLine($ctx->caller(), $doc->id, $uid, $rev, $sent, $tab);
                    break;
                case 'add':
                    $price = trim($req->field('price') ?? '');
                    $charge = trim($req->field('charge') ?? '');
                    $packs = trim($req->field('packs') ?? '1');
                    if ($charge !== '') {
                        $pick = ['kind' => 'charge', 'description' => Words::PO_ONE['delivery_line']];
                        $price = $charge;
                    } elseif (UiRequest::id($req->field('si')) !== null) {
                        $pick = ['supplier_item_id' => UiRequest::id($req->field('si'))];
                    } elseif (UiRequest::id($req->field('sku')) !== null) {
                        $pick = ['sku_id' => UiRequest::id($req->field('sku')), 'units_per_pack' => UiRequest::id($req->field('u')) ?? 1];
                    } else {
                        return $ctx->error(400, 'bad_line', Words::ERROR['bad_form']);
                    }
                    if (preg_match('/^[0-9]{1,7}$/D', $packs) !== 1) {
                        throw new CwException('bad_line', 'packs: a whole number', 422, ['field' => 'packs']);
                    }
                    $sent = ['packs' => $packs, 'pack_price' => $price];
                    $r = $svc->addLineLive($ctx->caller(), $doc->id, $pick, (int) $packs, $price === '' ? null : $price, (string) $req->field('k'), $more, $tab);
                    break;
                case 'remove':
                    $sent = ['remove' => true];
                    $r = $svc->removeLine($ctx->caller(), $doc->id, $uid, $rev, $tab);
                    break;
                default:
                    $r = $svc->restoreLine($ctx->caller(), $doc->id, $uid, UiRequest::id($req->field('after')), (string) $req->field('k'), $more, $tab);
            }
        } catch (CwException $e) {
            if (!in_array($e->httpStatus, [400, 409, 422], true)) {
                throw $e;
            }
            if (isset($sent['supplier_item_id']) || isset($sent['sku_id'])) {
                $sent['name'] = (string) ($ctx->db->value(isset($sent['supplier_item_id']) ? 'SELECT s.name FROM supplier_item si JOIN sku s ON s.id = si.sku_id WHERE si.id = ?'
                    : 'SELECT name FROM sku WHERE id = ?', [(int) ($sent['supplier_item_id'] ?? $sent['sku_id'])]) ?? '');
            }
            $refused = $ctx->documents()->get($doc->id);
            return $this->fragment($ctx, $refused, $e->httpStatus, ['refusal' => LiveSaveWords::refusal($e, $sent, $uid), 'op' => ['op' => $op, 'key' => $req->field('k')]]
                + self::since($ctx));
        }
        return $this->fragment($ctx, $r['document'], 200, ['op' => ['op' => $op, 'status' => $r['status'], 'uid' => $r['uid'] ?? null, 'key' => $req->field('k')]]
            + self::since($ctx));
    }

    /**
     * POST /ui/purchasing/orders/{id}/live/header (doc.PO.post): one head box (`field`: expected_date, external_ref, note) saved by itself with
     * the value the page showed (`base`) and the one typed (`value`). The answer as /live/line's; field_conflict with both values and the
     * value now (data-theirs: what "Keep theirs" puts back). Another field: 400 (the supplier and the order date are whole saves).
     */
    public function poHeader(Context $ctx): HtmlResponse
    {
        $field = $ctx->req->field('field') ?? '';
        if (!in_array($field, PurchaseOrders::LIVE_HEADER, true)) {
            return $ctx->error(400, 'bad_field', Words::ERROR['bad_form']);
        }
        $doc = $this->order($ctx);
        if ($doc === null) {
            return $ctx->error(404, 'unknown_purchase_order', Words::BUY_ERROR['unknown_purchase_order']);
        }
        if (Presence::settings($ctx->db)['mode'] !== 'on') {
            return $this->fragment($ctx, $doc, 409, ['refusal' => LiveSaveWords::refusal(new CwException('live_off', '', 409), [], null, $field), 'op' => ['op' => 'header']]);
        }
        $value = $ctx->req->field('value');
        try {
            $r = $this->pos($ctx)->setHeaderField($ctx->caller(), $doc->id, $field, $ctx->req->field('base'), $value, LiveChanges::tab($ctx->req->field('tab')));
        } catch (CwException $e) {
            if (!in_array($e->httpStatus, [400, 409, 422], true)) {
                throw $e;
            }
            return $this->fragment($ctx, $ctx->documents()->get($doc->id), $e->httpStatus, ['refusal' => LiveSaveWords::refusal($e, ['value' => $value], null, $field),
                'op' => ['op' => 'header']] + self::since($ctx));
        }
        return $this->fragment($ctx, $r['document'], 200, ['op' => ['op' => 'header', 'status' => $r['status']]] + self::since($ctx));
    }

    /**
     * The fragment po_live of a draft (or of an order no longer one, with its refusal): PurchaseOrdersController::liveView, the newest change,
     * the changes since the page's newest in words, the time (UK) for "All changes saved 14:02", what was done or refused.
     *
     * @param array<string, mixed> $extra refusal, op, since, tab
     */
    private function fragment(Context $ctx, Document $doc, int $status, array $extra = []): HtmlResponse
    {
        $view = (new PurchaseOrdersController())->liveView($ctx, $doc);
        $since = (int) ($extra['since'] ?? 0);
        $events = [];
        if (array_key_exists('since', $extra)) {
            foreach (LiveChanges::since($ctx->db, 'po', $doc->id, $since, $extra['tab'] ?? null) as $c) {
                $events[] = ['id' => $c['id'], 'by' => $c['staff_user_id'], 'name' => $c['name'], 'op' => $c['op'], 'uid' => $c['line_uid'],
                    'text' => LiveSaveWords::event($c, $view['lineNos'])];
            }
        }
        $op = $extra['op'] ?? null;
        if ($op !== null) {
            $op['key'] = LiveChanges::tab(is_string($op['key'] ?? null) ? $op['key'] : null); // the page's key echoed only as it may be (16 hex)
        }
        return $ctx->fragment('po_live', $view + ['newest' => LiveSaveWords::newest($ctx->db, $doc->id), 'at' => Html::clock(Clock::db(Clock::now())), 'liveEvents' => $events,
            'refusal' => $extra['refusal'] ?? null, 'op' => $op], $status);
    }

    /** @return array{since?: int, tab?: ?string} the newest change the page shows and its tab, when it sent them (its answer says the others' since) */
    private static function since(Context $ctx): array
    {
        $since = $ctx->req->field('since');
        return $since === null ? [] : ['since' => UiRequest::id($since) ?? 0, 'tab' => LiveChanges::tab($ctx->req->field('tab'))];
    }

    private function order(Context $ctx): ?Document
    {
        $doc = $ctx->documents()->find($ctx->id());
        return $doc === null || $doc->docType !== 'PO' || $doc->reversesId !== null ? null : $doc;
    }

    private function pos(Context $ctx): PurchaseOrders
    {
        return new PurchaseOrders($ctx->db, $ctx->documents(), $ctx->settings());
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
     * @return array{url: string, tab: string, v: int, page: string, login: string, me: int, ms: int, away_ms: int, stop_ms: int, max_ms: int, slow_ms: int, active_ms: int, jitter_ms: int, say_ms: int}|null
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
        return ['url' => $page . '/live', 'tab' => Presence::newTab(), 'v' => $version, 'page' => $page, 'login' => Html::url('/ui/login', ['back' => $page]), 'me' => $ctx->me()->id,
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
