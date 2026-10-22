<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Mapping\BulkDecisions;
use CW\Mapping\DecisionService;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Queries;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Store Products' "Create new warehouse products for these" (docs/decisions.md NF1-NF8; the owner, 22 Oct 2026: "tick a whole
 * family, then create new products for these in one go; better for future Vape Big-only products too"). A matching lead ticks website
 * products of one store that the warehouse has no product for (the "Not matched" filter and the search find a family) and presses the
 * bar's button, which posts the list's form here (`formaction`: the bar's other actions keep the list's own bulk route).
 *
 * Two POST routes, both a matching lead's (mapping.approve) and both with the session's CSRF token:
 *  - preview(): for each ticked row, the new warehouse product it would get, exactly as the single "Create a new product" makes it
 *    (the card the variant's page fills in, 1 sale = 1), and "Will create", "Will wait for a second OK" or "Will skip" with why
 *    (BulkDecisions::preview, list context `store_new`: the same checks as the apply, read only). Nothing is saved, not even a batch.
 *  - apply(): the rows of that preview again (for each: the suggestion and version shown, whether the preview skipped it, and the
 *    fingerprint of the details it showed) and the form's key, through BulkDecisions::run(), which checks everything again and goes
 *    row by row through DecisionService `new_item`; then the batch page. The same form sent again lands on the same batch page and
 *    does nothing more.
 * A form gives only numbers and values checked here: a variant's number, a suggestion's, a version (each within what its column can
 * hold), a store's code that names a store, the list's own filter values and the form key. Every word comes from Words or the database.
 */
final class StoreNewItemsController
{
    public const PREVIEW = '/ui/review/store/new-items';
    public const APPLY = '/ui/review/store/new-items/apply';
    /** The largest number a variant's id or version can be (INT UNSIGNED). */
    private const MAX_UINT = 0xFFFFFFFF;

    public function preview(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $store = self::store($ctx);
        $back = [self::back($ctx, $store), Words::MENU['store_products']];
        if ($store === null) {
            return $ctx->error(400, 'unknown_store', Words::ERROR['unknown_store'], $back);
        }
        if (($req->field('action') ?? '') !== 'new_item') {
            return $ctx->error(400, 'bad_form', Words::ERROR['bad_form'], $back);
        }
        $rows = [];
        foreach ($req->fieldsMatching('/^pick_[1-9][0-9]{0,9}$/D') as $name => $v) {
            if ($v !== '1') {
                continue;
            }
            $id = (int) substr($name, 5);
            $ver = $req->field('ver_' . $id);
            $prop = $req->field('prop_' . $id) ?? '';
            if ($ver === null || preg_match('/^[0-9]{1,10}$/D', $ver) !== 1 || ($prop !== '' && UiRequest::id($prop) === null) || $id > self::MAX_UINT
                || (int) $ver > self::MAX_UINT) {
                return $ctx->error(400, 'bad_form', Words::ERROR['bad_form'], $back);
            }
            $rows[] = ['listing_id' => $id, 'proposal_id' => UiRequest::id($prop), 'map_version' => (int) $ver];
        }
        if ($rows === []) {
            return $ctx->error(422, 'nothing_ticked', Words::BULK_ERROR['nothing_ticked'], $back);
        }
        $max = BulkDecisions::maxRows($ctx->db);
        if (count($rows) > $max) {
            return $ctx->error(422, 'too_many_rows', Words::say('BULK_ERROR', 'too_many_rows', count($rows), $max), $back);
        }
        try {
            $p = (new BulkDecisions($ctx->db, $ctx->decisions()))->preview($ctx->caller(), 'new_item', $rows,
                ['source' => BulkDecisions::STORE_NEW, 'channel_id' => (int) $store['id']]);
        } catch (CwException $e) {
            return $ctx->error($e->httpStatus, $e->errorCode, self::refusal($e, count($rows), $max), $back);
        }
        $ids = array_map(static fn (array $r): int => $r['listing_id'], $rows);
        $names = [];
        foreach ($ctx->db->all('SELECT cl.id, cl.external_variant_id, lp.product_title, lp.variant_title, lp.brand FROM channel_listing cl '
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $l) {
            $names[(int) $l['id']] = $l;
        }
        $byId = array_column($rows, null, 'listing_id');
        $lines = [];
        foreach ($p['rows'] as $lid => $o) {
            $r = $byId[$lid];
            $l = $names[$lid] ?? null;
            $lines[] = [
                'listing_id' => $lid,
                'title' => self::s($l['product_title'] ?? null),
                'variant_title' => self::s($l['variant_title'] ?? null),
                'brand' => self::s($l['brand'] ?? null),
                'variant' => self::s($l['external_variant_id'] ?? null),
                'outcome' => $o['outcome'],
                'tone' => Words::tone('BULK_RESULT', $o['outcome']),
                'label' => match ($o['outcome']) {
                    'done' => Words::STORE_NEW['will_create'],
                    'pending_second' => Words::STORE_NEW['will_wait'],
                    default => Words::STORE_NEW['will_skip'],
                },
                'why' => $o['outcome'] === 'skipped' ? BulkController::why((string) $o['code'], $o['detail']) : null,
                'needs' => array_map(static fn (string $n): string => Words::of('NEEDS_SECOND', $n), $o['needs']),
                'card' => $o['card'] === null ? null : self::facts($o['card']),
                // What the apply sends back: the suggestion and the version shown, whether this line is one of "these N", and the
                // fingerprint of the details shown.
                'field' => ($r['proposal_id'] ?? 0) . '.' . $r['map_version'] . '.' . ($o['outcome'] === 'skipped' ? '0' : '1') . '.' . BulkDecisions::cardHash($o['card']),
            ];
        }
        $go = $p['done'] + $p['pending'];
        return $ctx->page('store_new_preview', [
            'store' => $store,
            'back' => $back[0],
            'summary' => Words::say('STORE_NEW', 'summary', $p['done'], $p['pending'], $p['skipped']),
            'go' => $go,
            'button' => Words::say('STORE_NEW', 'go', $go),
            'lines' => $lines,
            'keep' => self::keep($ctx, $store),
            'form_key' => FormOnce::newKey(),
        ], 200, ['title' => Words::STORE_NEW['title'], 'active' => 'store_products']);
    }

    public function apply(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $store = self::store($ctx);
        $back = [self::back($ctx, $store), Words::MENU['store_products']];
        if ($store === null) {
            return $ctx->error(400, 'unknown_store', Words::ERROR['unknown_store'], $back);
        }
        $key = $req->field(FormOnce::FIELD) ?? '';
        if (preg_match('/^[0-9a-f]{32}$/D', $key) !== 1) {
            return $ctx->error(400, 'bad_form', Words::ERROR['bad_form'], $back);
        }
        $rows = [];
        foreach ($req->fieldsMatching('/^row_[1-9][0-9]{0,9}$/D') as $name => $v) {
            if (preg_match('/^(0|[1-9][0-9]{0,17})\.([0-9]{1,10})\.([01])\.([0-9a-f]{16}|-)$/D', $v, $m) !== 1
                || (int) substr($name, 4) > self::MAX_UINT || (int) $m[2] > self::MAX_UINT) {
                return $ctx->error(400, 'bad_form', Words::ERROR['bad_form'], $back);
            }
            $rows[] = ['listing_id' => (int) substr($name, 4), 'proposal_id' => $m[1] === '0' ? null : (int) $m[1], 'map_version' => (int) $m[2],
                'preview_skipped' => $m[3] === '0', 'card_hash' => $m[4]];
        }
        if ($rows === []) {
            return $ctx->error(422, 'nothing_ticked', Words::BULK_ERROR['nothing_ticked'], $back);
        }
        try {
            // Everything is checked again there (the limit, the person, every row); the same form again gets the batch it made.
            $r = (new BulkDecisions($ctx->db, $ctx->decisions()))->run($ctx->caller(), 'new_item', $rows,
                ['source' => BulkDecisions::STORE_NEW, 'channel_id' => (int) $store['id'], 'form_key' => $key]);
        } catch (CwException $e) {
            return $ctx->error($e->httpStatus, $e->errorCode, self::refusal($e, count($rows), BulkDecisions::maxRows($ctx->db)), $back);
        }
        return HtmlResponse::redirect('/ui/review/batches/' . $r['batch_id']);
    }

    /**
     * The new product's details as the preview shows them: each field of the card by its name on the variant's page (Words::FIELD), a
     * value it has not as "not stated", the kind of product and the nicotine type in words, numbers as the page writes them; then 1 sale = 1.
     *
     * @param array<string, mixed> $card
     * @return list<array{0: string, 1: string}>
     */
    private static function facts(array $card): array
    {
        $out = [];
        foreach (DecisionService::CARD_FIELDS as $f) {
            $v = $card[$f] ?? null;
            $text = match (true) {
                $v === null || $v === '' => Words::LISTING['not_stated'],
                $f === 'nic_type' => Words::of('NIC_TYPE', (string) $v),
                $f === 'form' => Words::of('FORM_VALUE', (string) $v),
                is_float($v) || is_int($v) => Html::dec($v),
                default => mb_substr((string) $v, 0, 300),
            };
            $out[] = [Words::FIELD[$f], $text];
        }
        $out[] = [Words::STORE_NEW['sale'], Words::saleUses(1)];
        return $out;
    }

    /** The store the form names (its code), or null. @return array{id: int, code: string, name: string}|null */
    private static function store(Context $ctx): ?array
    {
        $code = $ctx->req->field('channel');
        foreach ($code === null || $code === '' ? [] : $ctx->queries()->channels() as $c) {
            if ($c['code'] === $code) {
                return $c;
            }
        }
        return null;
    }

    /** Store Products as the person left it (the store, the filter, the sort, the search, the page), each value as the list reads it. */
    private static function back(Context $ctx, ?array $store): string
    {
        return Html::url('/ui/review/store', ['channel' => $store['code'] ?? null] + self::list($ctx));
    }

    /** @return array<string, scalar|null> the list's own values of the form, checked */
    private static function list(Context $ctx): array
    {
        $req = $ctx->req;
        $state = $req->field('state');
        $sort = $req->field('sort');
        $text = mb_substr(trim($req->field('q') ?? ''), 0, 100);
        $page = UiRequest::id($req->field('page'));
        return ['state' => $state !== null && in_array($state, Queries::STORE_STATES, true) ? $state : null,
            'sort' => $sort !== null && $sort !== 'sold_30' && isset(Queries::STORE_SORTS[$sort]) ? $sort : null,
            'q' => $text === '' ? null : $text, 'page' => $page !== null && $page > 1 ? $page : null];
    }

    /** The hidden fields of the preview's form: the store and the list's values (for the way back). @return array<string, scalar> */
    private static function keep(Context $ctx, array $store): array
    {
        return array_filter(['channel' => (string) $store['code']] + self::list($ctx), static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /** A refusal of the whole request by BulkDecisions, in this page's words. */
    private static function refusal(CwException $e, int $rows, int $max): string
    {
        return match ($e->errorCode) {
            'too_many_rows' => Words::say('BULK_ERROR', 'too_many_rows', (int) ($e->detail['asked'] ?? $rows), (int) ($e->detail['max'] ?? $max)),
            'lead_required' => Words::STORE_NEW['lead_only'],
            'idempotency_key_reused' => Words::error('idempotency_key_reused'),
            'bad_form_key' => Words::ERROR['bad_form'],
            default => Words::BULK_ERROR[$e->errorCode] ?? Words::say('MATCH_ERROR', 'other', ucfirst(rtrim($e->getMessage(), '.')) . '.'),
        };
    }

    private static function s(mixed $v): ?string
    {
        return $v === null || $v === '' || !is_scalar($v) ? null : mb_substr((string) $v, 0, 500);
    }
}
