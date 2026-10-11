<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Mapping\BulkDecisions;
use CW\Settings;
use CW\Ui\ByProductContext;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Bulk action on Products › By Item (docs/decisions.md M55-M59, U123-U130; the owner, 10 Oct 2026: "by item here bulk preview and
 * update option ... want to handle based on selection also all here possible options need"). A person who may decide ticks items,
 * chooses a store (or all of them) and presses an action of the bar: Confirm suggested matches, Not a match…, Send back for
 * matching…. Each acts on the SUGGESTED variants of the ticked items on the chosen store(s): one decision per variant, made by
 * BulkDecisions through DecisionService with every rule of the lists. A matched variant is never touched here (M53).
 *
 * Two POST routes, both for people who may decide (mapping.decide) and both with the session's CSRF token:
 *  - preview(): what would happen to every variant (BulkDecisions::preview(): the same checks, read only). Nothing is saved, not
 *    even a batch. It ends with one button ("Yes, do these N") and the way back to the list as it was, its ticks included.
 *  - apply(): the rows of that preview again (for each: the item it was shown under, the suggestion and version it showed, and
 *    whether the preview skipped it), through BulkDecisions::run(), which checks everything again; then the batch page, which is
 *    the result page of every bulk action. A row the preview skipped is never acted on, and one that changed since is skipped.
 * For "Not a match…" the preview is the owner's second step (U110): nothing is marked before its red button.
 *
 * A form gives only numbers and values checked here: an item's number, a variant's, a suggestion's, a version (each within what its
 * column can hold), an action of this list, a store's code that names a store, and the list's own filter values. Every word shown
 * comes from the database or Words. A ticked row sends EVERY suggested variant its row showed (never a part of them): what bounds
 * a request is the whole-request limit, mapping.bulk_max_rows, counted after the store is chosen.
 */
final class ByItemBulkController
{
    /**
     * The most suggested variants one row can send: the most rows mapping.bulk_max_rows can ever be set to (Settings::RULES). An item
     * with more could never be acted on in one go, so its row has no tick box and says so (ByProductController); it is never cut.
     */
    public const MAX_PER_ITEM = Settings::RULES['mapping.bulk_max_rows']['max'];
    /** One suggested variant as the list sends it: variant . suggestion . version. */
    private const CELL = '[1-9][0-9]{0,9}\.[1-9][0-9]{0,17}\.[0-9]{1,10}';
    /** The largest number a variant's id or version can be (INT UNSIGNED): a larger one is no form of ours (BulkDecisions refuses it too). */
    private const MAX_UINT = 0xFFFFFFFF;

    public function preview(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $q = $ctx->queries();
        $stores = $q->everyStore();
        $picked = [];
        foreach ($req->fieldsMatching('/^pick_[1-9][0-9]{0,9}$/D') as $name => $v) {
            if ($v === '1') {
                $picked[] = (int) substr($name, 5);
            }
        }
        sort($picked);
        $state = self::state($ctx, $stores, $picked);
        $back = [$state['back'], Words::MENU['by_product']];
        $action = (string) ($req->field('action') ?? '');
        if (!in_array($action, BulkDecisions::ITEM_ACTIONS, true)) {
            return $ctx->error(400, 'bad_form', Words::ERROR['bad_form'], $back);
        }
        if ($state['on_unknown']) {
            return $ctx->error(400, 'unknown_store', Words::ERROR['unknown_store'], $back);
        }
        if ($picked === []) {
            return $ctx->error(422, 'nothing_ticked', Words::BY_ITEM_BULK['nothing_ticked'], $back);
        }
        if (count($picked) > ByProductContext::MAX_TICKED) {
            // More ticks than a page has rows (its largest size and the one pinned item): no page of ours.
            return $ctx->error(400, 'bad_form', Words::ERROR['bad_form'], $back);
        }
        // What each ticked row showed: its suggested variants, each with its suggestion and version. A variant is suggested for one
        // item only, so one named twice is no page of ours.
        $rows = [];
        foreach ($picked as $sku) {
            $cells = $req->field('cells_' . $sku);
            if ($cells === null || $cells === '') {
                continue;
            }
            // One cell at a time (a single pattern for a whole row of them would be too large to compile); one more than a row can
            // send is enough to know there are too many.
            $parts = explode(' ', $cells, self::MAX_PER_ITEM + 2);
            if (count($parts) > self::MAX_PER_ITEM) {
                return $ctx->error(400, 'bad_form', Words::ERROR['bad_form'], $back);
            }
            foreach ($parts as $cell) {
                if (preg_match('/^' . self::CELL . '$/D', $cell) !== 1) {
                    return $ctx->error(400, 'bad_form', Words::ERROR['bad_form'], $back);
                }
                [$listing, $proposal, $version] = array_map('intval', explode('.', $cell));
                if (isset($rows[$listing]) || $listing > self::MAX_UINT || $version > self::MAX_UINT) {
                    return $ctx->error(400, 'bad_form', Words::ERROR['bad_form'], $back);
                }
                $rows[$listing] = ['listing_id' => $listing, 'proposal_id' => $proposal, 'map_version' => $version, 'sku_id' => $sku];
            }
        }
        // The chosen store: only its variants. A variant's store is read here (its number only), never taken from the form.
        $on = $state['on'];
        if ($on !== null) {
            $storeOf = $q->variantStores(array_keys($rows));
            $rows = array_filter($rows, static fn (array $r): bool => ($storeOf[$r['listing_id']] ?? 0) === (int) $on['id']);
        }
        ksort($rows);
        $where = $on === null ? Words::BY_ITEM_BULK['all_stores_lower'] : (string) $on['name'];
        if ($rows === []) {
            return $ctx->error(422, 'nothing_to_do', Words::say('BY_ITEM_BULK', 'nothing_on', $where), $back);
        }
        // The limit counts decisions, that is variants, not items (M55): refused whole, in words.
        $max = BulkDecisions::maxRows($ctx->db);
        if (count($rows) > $max) {
            return $ctx->error(422, 'too_many_rows', Words::say('BY_ITEM_BULK', 'too_many_rows', count($rows), $where, $max), $back);
        }
        try {
            $p = (new BulkDecisions($ctx->db, $ctx->decisions()))->preview($ctx->caller(), $action, array_values($rows),
                ['source' => 'item', 'channel_id' => $on === null ? null : (int) $on['id']]);
        } catch (CwException $e) {
            return $ctx->error($e->httpStatus, $e->errorCode, self::refusal($e, count($rows), $where, $max), $back);
        }

        // The names, for the rows of the request only (at most the limit's).
        $variants = $q->variantsOf(array_keys($rows));
        $items = [];
        foreach ($q->productRows(array_values(array_unique(array_column($rows, 'sku_id')))) as $s) {
            $items[(int) $s['id']] = $s;
        }
        $storeNames = array_column($stores, 'name', 'id');
        $lines = [];
        foreach ($rows as $lid => $r) {
            $v = $variants[$lid] ?? null;
            $outcome = $p['rows'][$lid] ?? ['outcome' => 'skipped', 'code' => 'unknown_listing', 'detail' => [], 'needs' => []];
            $item = $items[$r['sku_id']] ?? null;
            $lines[] = [
                'listing_id' => $lid,
                'sku_id' => $r['sku_id'],
                'item' => self::s($item['name'] ?? null) ?? self::s($item['code'] ?? null) ?? '#' . $r['sku_id'],
                'item_code' => self::s($item['code'] ?? null),
                'store' => $v === null ? '' : (string) ($storeNames[(int) $v['channel_id']] ?? ''),
                'title' => self::title($v),
                'variant' => $v === null ? null : self::s($v['external_variant_id']),
                'outcome' => $outcome['outcome'],
                'tone' => Words::tone('BULK_RESULT', $outcome['outcome']),
                'label' => match ($outcome['outcome']) {
                    'done' => Words::BY_ITEM_BULK['will_' . $action],
                    'pending_second' => Words::BY_ITEM_BULK['will_wait'],
                    default => Words::BY_ITEM_BULK['will_skip'],
                },
                'why' => $outcome['outcome'] === 'skipped' ? BulkController::why((string) $outcome['code'], $outcome['detail'], 'BY_ITEM_SKIP') : null,
                'needs' => array_map(static fn (string $n): string => Words::of('NEEDS_SECOND', $n), $outcome['needs']),
                // What the apply sends back: the item, the suggestion and the version shown, and whether this line is one of "these N".
                'field' => $r['sku_id'] . '.' . $r['proposal_id'] . '.' . $r['map_version'] . '.' . ($outcome['outcome'] === 'skipped' ? '0' : '1'),
            ];
        }
        // By item (CW number), then store, then variant: the order of the list the person came from.
        usort($lines, static fn (array $a, array $b): int => [$a['sku_id'], $a['store'], $a['listing_id']] <=> [$b['sku_id'], $b['store'], $b['listing_id']]);
        $go = $p['done'] + $p['pending'];
        $parts = [Words::say('BY_ITEM_BULK', 'summary_' . $action, $p['done'])];
        if ($action === 'link') {
            $parts[] = Words::say('BY_ITEM_BULK', 'summary_pending', $p['pending']);
        }
        $parts[] = Words::say('BY_ITEM_BULK', 'summary_skipped', $p['skipped']);
        $idle = count($picked) - count(array_unique(array_column($rows, 'sku_id')));
        $title = Words::say('BY_ITEM_BULK', 'title', Words::BY_ITEM_BULK['title_' . $action]);
        return $ctx->page('by_item_preview', [
            'title' => $title,
            'back' => $state['back'],
            'action' => $action,
            'danger' => in_array($action, BulkDecisions::NEGATIVE, true),
            'summary' => implode(' · ', $parts),
            'scope' => Words::say('BY_ITEM_BULK', 'scope', $where,
                count($picked) === 1 ? Words::BY_ITEM_BULK['selected_one'] : Words::say('BY_ITEM_BULK', 'selected_many', count($picked)),
                count($rows) === 1 ? Words::BY_ITEM_BULK['variants_one'] : Words::say('BY_ITEM_BULK', 'variants_many', count($rows))),
            'idle' => $idle <= 0 ? null : ($idle === 1 ? Words::say('BY_ITEM_BULK', 'nothing_here_one', $where) : Words::say('BY_ITEM_BULK', 'nothing_here_many', $idle, $where)),
            'text' => Words::BY_ITEM_BULK['text_' . $action],
            'go' => $go,
            'button' => Words::say('BY_ITEM_BULK', 'button_' . $action, $go),
            'lines' => $lines,
            'keep' => $state['keep'],
        ], 200, ['title' => $title, 'active' => 'by_product', 'notice' => null]);
    }

    public function apply(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $stores = $ctx->queries()->everyStore();
        $state = self::state($ctx, $stores, ByProductContext::ticked($req->field('ticked')));
        $back = [$state['back'], Words::MENU['by_product']];
        $action = (string) ($req->field('action') ?? '');
        if (!in_array($action, BulkDecisions::ITEM_ACTIONS, true)) {
            return $ctx->error(400, 'bad_form', Words::ERROR['bad_form'], $back);
        }
        if ($state['on_unknown']) {
            return $ctx->error(400, 'unknown_store', Words::ERROR['unknown_store'], $back);
        }
        $rows = [];
        foreach ($req->fieldsMatching('/^row_[1-9][0-9]{0,9}$/D') as $name => $v) {
            if (preg_match('/^([1-9][0-9]{0,9})\.([1-9][0-9]{0,17})\.([0-9]{1,10})\.([01])$/D', $v, $m) !== 1
                || (int) substr($name, 4) > self::MAX_UINT || (int) $m[3] > self::MAX_UINT) {
                return $ctx->error(400, 'bad_form', Words::ERROR['bad_form'], $back);
            }
            $rows[] = ['listing_id' => (int) substr($name, 4), 'sku_id' => (int) $m[1], 'proposal_id' => (int) $m[2], 'map_version' => (int) $m[3],
                'preview_skipped' => $m[4] === '0'];
        }
        $on = $state['on'];
        $where = $on === null ? Words::BY_ITEM_BULK['all_stores_lower'] : (string) $on['name'];
        if ($rows === []) {
            return $ctx->error(422, 'nothing_ticked', Words::BY_ITEM_BULK['nothing_ticked'], $back);
        }
        try {
            // Everything is checked again there (the limit too): a variant of another store than the chosen one is skipped.
            $r = (new BulkDecisions($ctx->db, $ctx->decisions()))->run($ctx->caller(), $action, $rows,
                ['source' => 'item', 'channel_id' => $on === null ? null : (int) $on['id']]);
        } catch (CwException $e) {
            return $ctx->error($e->httpStatus, $e->errorCode, self::refusal($e, count($rows), $where, BulkDecisions::maxRows($ctx->db)), $back);
        }
        // The result page's way back is the list as the person left it (BulkController::show builds it from these values).
        return HtmlResponse::redirect(Html::url('/ui/review/batches/' . $r['batch_id'], ['from' => 'item'] + $state['list']));
    }

    /**
     * What a form says of the list it came from, each value checked: the filter, the search, the page, the number of rows, the store
     * the action is for (`on`: none = all stores; a code that names no store is refused by the caller), and the way back to that
     * list with these items ticked.
     *
     * @param list<array{id: int, code: string, name: string}> $stores
     * @param list<int> $picked the ticked items
     * @return array{on: array{id: int, code: string, name: string}|null, on_unknown: bool, list: array<string, scalar|null>, keep: array<string, scalar>, back: string}
     */
    private static function state(Context $ctx, array $stores, array $picked): array
    {
        $req = $ctx->req;
        $code = $req->field('on') ?? '';
        $on = $code === '' ? null : ByProductContext::store($stores, $code);
        $text = ByProductContext::text($req->field('q') ?? '');
        $page = UiRequest::id($req->field('page'));
        $list = ['cov' => ByProductContext::coverage($req->field('cov'), $stores)['value'], 'q' => $text === '' ? null : $text,
            'n' => ByProductContext::pageSize($req->field('n')), 'page' => $page !== null && $page > 1 ? $page : null];
        $ticked = implode('.', array_slice($picked, 0, ByProductContext::MAX_TICKED));
        $more = ['on' => $on === null ? null : (string) $on['code'], 'ticked' => $ticked === '' ? null : $ticked];
        return [
            'on' => $on,
            'on_unknown' => $code !== '' && $on === null,
            'list' => $list,
            'keep' => array_filter($list + $more, static fn (mixed $v): bool => $v !== null && $v !== ''),
            'back' => Html::url(ByProductContext::LIST_PATH, $list + $more),
        ];
    }

    /** A refusal of the whole request by BulkDecisions, in this page's words. */
    private static function refusal(CwException $e, int $rows, string $where, int $max): string
    {
        return match ($e->errorCode) {
            'too_many_rows' => Words::say('BY_ITEM_BULK', 'too_many_rows', (int) ($e->detail['asked'] ?? $rows), $where, (int) ($e->detail['max'] ?? $max)),
            'nothing_ticked' => Words::BY_ITEM_BULK['nothing_ticked'],
            default => Words::BULK_ERROR[$e->errorCode] ?? Words::say('MATCH_ERROR', 'other', ucfirst(rtrim($e->getMessage(), '.')) . '.'),
        };
    }

    /** A variant's name: its product's name and its own option name, else its option number, else "(no name)". @param array<string, mixed>|null $v */
    private static function title(?array $v): string
    {
        $name = $v === null ? '' : trim((string) ($v['product_title'] ?? '') . ' ' . (string) ($v['variant_title'] ?? ''));
        if ($name !== '') {
            return mb_substr($name, 0, 200);
        }
        $variant = $v === null ? null : self::s($v['external_variant_id'] ?? null);
        return $variant === null ? Words::LISTING['no_title'] : Words::say('QUEUE', 'option', $variant);
    }

    private static function s(mixed $v): ?string
    {
        return $v === null || $v === '' || !is_scalar($v) ? null : mb_substr((string) $v, 0, 500);
    }
}
