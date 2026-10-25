<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Mapping\DecisionService;
use CW\Mapping\OneAtATime;
use CW\Ui\ByProductContext;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Queries;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Products › Simple matching (docs/decisions.md SM1-SM9; the owner's request of 25 Oct 2026: "Create a simple page with a table with 4
 * columns: one for CW, one for Vape and Go, one for Vape Big and one for Electrofag ... If nothing is matched there is a blank simple
 * dropdown so I can search with just what it is called on that store, or the ID it has ... click Apply and it matches, with a plus
 * button so if there is a duplicate I can match that too. One dropdown is enough.").
 *
 * A new clean page that reuses the matching code: By Item's list of warehouse products and its search (Queries::productIds /
 * productRows / productCells), the quick match's search of a store's products still waiting for a match (Queries::waitingListings,
 * suggestedFor, pickerRows), and the decide route's request (ReviewController::decisionRequest) sent to DecisionService, the only code
 * that changes a match. It only ADDS a match: a match is changed or undone on the website product's own page, as before. Every rule
 * of a match stays DecisionService's (the person's name on the decision and its audit row, a version that moved, the suggestion it
 * must name, a Conflict for a matching lead, a spot check's owner, a product decided one at a time on its own page, a second OK);
 * this page says the outcome in a short line.
 *
 *   GET  /ui/match            the list (linking.view): `q` the search, `page`; back from Apply: `notice` + `prev` (the matching pages'
 *                             notice), or `said` (a refusal, SAID) + `prev` (the website product) + `ch` (the store) + `t` (what was
 *                             typed), or `confirm` (the website product a typed ID names, to confirm) + `ch`; `at` the warehouse product
 *                             of the row it came back to
 *   GET  /ui/match/options    one cell's choices (mapping.decide), a fragment for app.js: `sku`, `channel`, `q`
 *   POST /ui/match/apply      one match (mapping.decide): `sku_id`, `channel`, `listing_id`, `expected_map_version`, `proposal_id`
 *                             (else `pick`, the store's ID typed without the script: shown to be confirmed), and the list's `q` and
 *                             `page` for the way back
 */
final class SimpleMatchController
{
    public const PATH = '/ui/match';
    public const OPTIONS = '/ui/match/options';
    public const APPLY = '/ui/match/apply';
    /**
     * The store columns, by store code, in the owner's order (25 Oct 2026); a store the channel table holds besides these is a column
     * after them, by name (Queries::everyStore's order). A store that is not set up is no column.
     */
    public const STORE_ORDER = ['vapeandgo', 'vapebig', 'electrofag'];
    /** The most choices one dropdown lists (a page of Queries::waitingListings; the suggestions too). */
    public const OPTIONS_MAX = 20;
    /** The refusals Apply says, by their code in the address (`said`): only these are read back, never a text. */
    public const SAID = ['choose', 'other_store', 'not_waiting', 'changed', 'own_page', 'not_allowed', 'gone', 'other'];
    /** The refusals that lead to the website product's own page, where its decision is made or its reason shown. */
    private const SAID_LINK = ['own_page', 'not_allowed', 'other'];
    /** A website product that still waits for a match (Queries::STATE_SQL): no match, no decision waiting, not ignored, no landing page. */
    private const WAITING = ['suggested', 'not_matched'];
    /** What the box held, put back in it after a refusal (`t`): a choice's words are longer than a search's. */
    private const KEPT_MAX = 300;

    public function index(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $q = $ctx->queries();
        $decide = $ctx->me()->canDecide();
        $stores = self::stores($q->everyStore());
        $text = ByProductContext::text($req->param('q') ?? '');
        $layout = ['title' => Words::MENU['simple_match'], 'active' => 'simple_match', 'notice' => (new ReviewController())->notice($ctx, null)];
        $lookOnly = $decide ? null : Words::whoCan('mapping.decide');
        // By Item's list as it opens (the warehouse products not joined into another one, by CW number) and its search: a search's ids
        // in one pass (which also counts them), else the count of the products and only this page of them.
        $per = Queries::PER_PAGE;
        $all = $text === '' ? null : $q->productIds(null, null, [], $text);
        $total = $all === null ? $q->productTotal() : count($all);
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, UiRequest::id($req->param('page')) ?? 1), $pages);
        $offset = ($page - 1) * $per;
        $ids = $all === null ? $q->productIds(null, null, [], '', $per, $offset) : array_slice($all, $offset, $per);
        $at = UiRequest::id($req->param('at'));
        $said = self::said($ctx, $stores, $at);
        // A typed ID to confirm (`confirm`, from Apply without the script): the product as this page shows it now, in its cell's box,
        // or, when it no longer waits for a match, "Choose one from the list".
        $chosen = null;
        $confirm = $decide && $at !== null && $said === null ? UiRequest::id($req->param('confirm')) : null;
        $store = $confirm === null ? null : ReviewController::store($stores, $req->param('ch'));
        if ($confirm !== null && $store !== null) {
            $chosen = self::choices($ctx, [$confirm], (int) $store['id'])[0] ?? null;
            $chosen = $chosen === null ? null : $chosen + ['sku' => $at, 'channel' => (string) $store['code']];
            $said = $chosen !== null ? null : ['code' => 'choose', 'text' => Words::SIMPLE_MATCH['said_choose'], 'link' => null, 'sku' => $at,
                'channel' => (string) $store['code'], 'typed' => ''];
        }
        $keep = array_filter(['q' => $text === '' ? null : $text, 'page' => $page > 1 ? $page : null], static fn (mixed $v): bool => $v !== null);
        $rows = self::rows($q->productRows($ids), $stores, $q->productCells($ids), $decide, $at, $said, $chosen);
        $base = ['q' => $text === '' ? null : $text];
        return $ctx->page('simple_match', [
            'stores' => $stores,
            'rows' => $rows,
            'text' => $text,
            'path' => self::PATH,
            'apply' => self::APPLY,
            'keep' => $keep,
            'lookOnly' => $lookOnly,
            // a refusal about a row this page does not show (it was joined into another product meanwhile): said above the list
            'saidTop' => $said !== null && !in_array($said['sku'], $ids, true) ? $said : null,
            'page_no' => $page,
            'pages' => $pages,
            'prev_link' => $page > 1 ? Html::url(self::PATH, $base + ['page' => $page > 2 ? $page - 1 : null]) : null,
            'next_link' => $page < $pages ? Html::url(self::PATH, $base + ['page' => $page + 1]) : null,
        ], 200, $layout);
    }

    /**
     * One cell's choices (GET …/options, read only, for whoever may decide): the products of ONE store that still wait for a match,
     * for ONE warehouse product, as a fragment app.js copies into the cell's dropdown. An empty box (no `q`, or spaces): the
     * computer's suggestions of this product on that store; a text: that store's waiting products with every word in their names (and
     * what else the quick match reads) or whose ID it is, best sellers first. At most OPTIONS_MAX. Each choice carries what Apply sends
     * for it (the website product, its version, its open suggestion of any product: the decision must name it, I7) and says when it
     * is decided one at a time on its own page, or (for a Matcher) by a matching lead. An error is a page in words, which app.js does
     * not show (it shows its own line).
     */
    public function options(Context $ctx): HtmlResponse
    {
        $q = $ctx->queries();
        $stores = self::stores($q->everyStore());
        $back = [self::PATH, Words::MENU['simple_match']];
        $store = ReviewController::store($stores, $ctx->req->param('channel'));
        if ($store === null) {
            return $ctx->error(404, 'unknown_store', 'no such store', $back);
        }
        $id = UiRequest::id($ctx->req->param('sku'));
        $sku = $id === null ? null : ($q->skus([$id])[$id] ?? null);
        if ($id === null || $sku === null) {
            return $ctx->error(404, 'unknown_item', 'no such item', $back);
        }
        if ($sku['merged_into_sku_id'] !== null) {
            return $ctx->error(404, 'item_joined', 'the item was merged into another one', $back);
        }
        $raw = mb_substr(trim($ctx->req->param('q') ?? ''), 0, self::KEPT_MAX);
        $text = ByProductContext::text($raw);
        if ($text === '') {
            $ids = $q->suggestedFor($id, (int) $store['id'], self::OPTIONS_MAX);
        } else {
            // The product whose ID is exactly what was typed first (a short ID is part of many others, which may sell more), then the
            // search (Codex's review of the branch, LOW 4); for a choice's words kept in the box after a refusal, the product its ID
            // names, so it can be chosen anew as it is now (Codex's fifth review).
            $named = self::idOf($raw);
            $exact = $q->listingByOption((int) $store['id'], $text) ?? ($named === null ? null : $q->listingByOption((int) $store['id'], $named));
            $ids = array_values(array_unique([...($exact === null ? [] : [$exact]), ...$q->waitingListings((int) $store['id'], $text, 1, self::OPTIONS_MAX)['ids']]));
        }
        $choices = array_slice(self::choices($ctx, $ids, (int) $store['id']), 0, self::OPTIONS_MAX);
        $says = $choices !== [] ? '' : ($text === '' ? Words::SIMPLE_MATCH['type_more'] : Words::say('SIMPLE_MATCH', 'found_none', (string) $store['name']));
        return $ctx->fragment('simple_match_options', ['sku' => $id, 'store' => (string) $store['code'], 'choices' => $choices, 'says' => $says]);
    }

    /**
     * Apply (POST, for whoever may decide): one more match of a warehouse product on one store. The website product is the one chosen
     * from the list (`listing_id` with the version and the suggestion the list gave, and the box still naming it: its words or its ID).
     * Without a choice (no script, or the box's text changed) the box is taken as typed: the one product of that store whose ID it is
     * exactly and that still waits for a match comes back chosen in the box, to be confirmed with a second Apply (`confirm`); nothing is
     * decided from a typed ID alone. The product must be that store's and still wait for a match, at the version the form names: this
     * page never changes a match. The decision is the decide route's `link` (ReviewController::decisionRequest, then
     * DecisionService::decide, which checks every rule again under the product's lock, with addOnly() as its recheck), with the units
     * this warehouse product's own open suggestion gives (as the quick match), else 1. Then back to the list as it was, at the row
     * (#m-<number>): the matching pages' notice (matched, or saved for a second OK), or a refusal said in the cell (SAID), with nothing
     * saved and what the box held put back in it.
     */
    public function apply(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $q = $ctx->queries();
        $stores = self::stores($q->everyStore());
        $list = [self::PATH, Words::MENU['simple_match']];
        $store = ReviewController::store($stores, $req->field('channel'));
        if ($store === null) {
            return $ctx->error(404, 'unknown_store', 'no such store', $list);
        }
        $skuId = UiRequest::id($req->field('sku_id'));
        $sku = $skuId === null ? null : ($q->skus([$skuId])[$skuId] ?? null);
        if ($skuId === null || $sku === null) {
            return $ctx->error(404, 'unknown_item', 'no such item', $list);
        }
        $text = ByProductContext::text($req->field('q') ?? '');
        $page = UiRequest::id($req->field('page'));
        $back = ['q' => $text === '' ? null : $text, 'page' => $page !== null && $page > 1 ? $page : null];
        // The box: what it holds (compared whole with the chosen product's words; put back in it after a refusal, rule 7), and as typed
        // (cut to a search's length: an ID).
        $box = trim($req->field('pick') ?? '');
        $typed = ByProductContext::text($box);
        $kept = mb_substr($box, 0, self::KEPT_MAX);
        $refuse = static fn (string $said, ?int $listing = null): HtmlResponse => HtmlResponse::redirect(Html::url(self::PATH, $back
            + ['said' => $said, 'prev' => $listing, 'at' => $skuId, 'ch' => (string) $store['code'], 't' => $kept !== '' ? $kept : null]) . '#m-' . $skuId);
        // Without the script the box holds the store's ID. The one product of that store with exactly that ID that still waits for a match
        // is shown in its cell to be confirmed: one more Apply, sent from the page that shows it, with that page's version and suggestion
        // (I7: never the product as it is when the request arrives; Codex's review of the branch, HIGH 1). Else "Choose one from the
        // list", with what was typed kept.
        $asTyped = static function () use ($q, $store, $typed, $back, $skuId, $refuse): HtmlResponse {
            $found = $typed === '' ? null : $q->listingByOption((int) $store['id'], $typed);
            $row = $found === null ? null : ($q->pickerRows([$found])[$found] ?? null);
            if ($found === null || $row === null || !in_array((string) $row['state'], self::WAITING, true)) {
                return $refuse('choose');
            }
            return HtmlResponse::redirect(Html::url(self::PATH, $back + ['confirm' => $found, 'at' => $skuId, 'ch' => (string) $store['code']]) . '#m-' . $skuId);
        };
        if ($sku['merged_into_sku_id'] !== null) {
            return $refuse('gone');
        }
        $listingId = UiRequest::id($req->field('listing_id'));
        if ($listingId === null) {
            return $asTyped();
        }
        $row = $q->pickerRows([$listingId])[$listingId] ?? null;
        if ($row === null) {
            return $refuse('choose');
        }
        if ((int) $row['channel_id'] !== (int) $store['id']) {
            return $refuse('other_store', $listingId);
        }
        if (!in_array((string) $row['state'], self::WAITING, true)) {
            return $refuse('not_waiting', $listingId);
        }
        // What the person saw: the version and the suggestion the list gave. The version must be the one the waiting state above was read
        // with: a form naming another one is refused here (Codex's review, MEDIUM 2), and DecisionService compares it under the lock.
        $version = trim($req->field('expected_map_version') ?? '');
        $proposal = trim($req->field('proposal_id') ?? '');
        if ($version !== (string) $row['map_version']) {
            return $refuse('changed', $listingId);
        }
        // The box must name the product the hidden fields carry: its words as its list shows them, or its ID. A box whose text was changed
        // after the choice (without the script nothing clears the hidden fields) is not that product: what it holds now is taken as
        // typed (Codex's second review, HIGH).
        if ($box !== (string) $row['external_variant_id'] && $box !== trim(self::choices($ctx, [$listingId], (int) $store['id'])[0]['label'] ?? '')) {
            return $asTyped();
        }
        $own = $row['proposal_id'] !== null && (string) $row['proposal_id'] === $proposal && $row['proposed_sku_id'] !== null
            && (int) $row['proposed_sku_id'] === $skuId && $row['ai_units_per_item'] !== null;
        $units = $own ? max(1, min(DecisionService::MAX_UNITS, (int) $row['ai_units_per_item'])) : 1;
        [$request] = ReviewController::decisionRequest($listingId, ['action' => 'link', 'expected_map_version' => $version, 'seen' => '',
            'proposal_id' => $proposal, 'reason' => '', 'sku_id' => (string) $skuId, 'units' => (string) $units]);
        if ($request === null) {
            return $refuse('changed', $listingId); // no version the list could have given: choose it again
        }
        try {
            // Under the product's lock, after every check of what the person saw: still that store's, still waiting (addOnly).
            $result = $ctx->decisions()->decide($ctx->caller(), $request, self::addOnly($ctx->db, $listingId, (int) $store['id']));
        } catch (CwException $e) {
            return $refuse(self::saidOf($e), $listingId);
        }
        $notice = $result['state'] === 'pending_second' ? 'pending_second' : 'decided_link';
        return HtmlResponse::redirect(Html::url(self::PATH, $back + ['notice' => $notice, 'prev' => $listingId, 'at' => $skuId]) . '#m-' . $skuId);
    }

    /**
     * The stores in the owner's order (STORE_ORDER), any other after them as Queries::everyStore() gives them (by name).
     *
     * @param list<array{id: int, code: string, name: string}> $stores
     * @return list<array{id: int, code: string, name: string}>
     */
    public static function stores(array $stores): array
    {
        $rank = array_flip(self::STORE_ORDER);
        usort($stores, static fn (array $a, array $b): int => ($rank[$a['code']] ?? count($rank)) <=> ($rank[$b['code']] ?? count($rank)));
        return $stores;
    }

    /**
     * What Apply asks again under the website product's lock (DecisionService::decide's recheck, after every check of what the person
     * saw and before anything is written): the product is still that store's and still waits for a match (not matched or on hold, not
     * ignored, not a landing page; a decision waiting for a second OK is the service's own refusal). So this page only ever adds a
     * match: one made by someone else between this page's check and the lock is never changed (Codex's review of the branch, MEDIUM 2).
     * A locking read: the latest row, which the decision holds already.
     */
    public static function addOnly(Db $db, int $listingId, int $storeId): \Closure
    {
        return static function () use ($db, $listingId, $storeId): void {
            $l = $db->one('SELECT channel_id, status FROM channel_listing WHERE id = ? FOR UPDATE', [$listingId]);
            if ($l === null || (int) $l['channel_id'] !== $storeId || !in_array((string) $l['status'], ['unmapped', 'suggested'], true)) {
                throw new CwException('not_waiting', 'the listing no longer waits for a match; this page only adds a match', 409,
                    ['status' => $l['status'] ?? null]);
            }
        };
    }

    /**
     * The choices of a cell's dropdown, in the order given: the website products of the store that still wait for a match, each as the
     * box shows it ("ID <option> · <name>", choiceName(), and "decided on its own page" for one OneAtATime names, "a matching lead decides it" for a
     * Conflict when the person is a Matcher) with what Apply sends for it (its version and its open suggestion of any product).
     *
     * @param list<int> $ids website products
     * @return list<array{label: string, listing_id: int, map_version: int, proposal_id: ?int, option: string}>
     */
    private static function choices(Context $ctx, array $ids, int $storeId): array
    {
        $data = $ctx->queries()->pickerRows($ids);
        // The products no list answers (held back, a spot check's match, found on arrival): one read for them all (OneAtATime).
        $once = OneAtATime::ofWaiting($ctx->db, $ids);
        $lead = $ctx->me()->isLead();
        $out = [];
        foreach ($ids as $lid) {
            $r = $data[$lid] ?? null;
            if ($r === null || (int) $r['channel_id'] !== $storeId || !in_array((string) $r['state'], self::WAITING, true)) {
                continue;
            }
            $option = (string) $r['external_variant_id'];
            $word = isset($once[$lid]) ? 'option_own_page' : ($r['band'] === 'Conflict' && !$lead ? 'option_lead' : 'option');
            $out[] = ['label' => Words::say('SIMPLE_MATCH', $word, self::choiceName($r), $option), 'listing_id' => $lid, 'map_version' => (int) $r['map_version'],
                'proposal_id' => $r['proposal_id'] === null ? null : (int) $r['proposal_id'], 'option' => $option];
        }
        return $out;
    }

    /**
     * The ID at the start of a choice's words ("ID <option> · <name>", any mark after the ID: Words::SIMPLE_MATCH['option']), or null: what the box
     * holds when a refusal kept a choice's words in it. The words around the ID are the option word's own, never typed here.
     */
    public static function idOf(string $text): ?string
    {
        // The words before the ID ("ID "): a choice starts with them, so a name with "ID" in it never hides the choice's own ID (SM10).
        $before = explode('%2$s', Words::SIMPLE_MATCH['option'])[0];
        if ($before === '' || !str_starts_with($text, $before) || preg_match('/^(\S+)/u', mb_substr($text, mb_strlen($before)), $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    /** A DecisionService refusal as the code of its short line (SAID). */
    public static function saidOf(CwException $e): string
    {
        return match ($e->errorCode) {
            'map_version_conflict', 'proposal_changed', 'proposal_closed', 'proposal_mismatch' => 'changed',
            'one_at_a_time' => 'own_page',
            'lead_required', 'spot_check_other', 'role_not_allowed', 'staff_required' => 'not_allowed',
            'pending_second_exists', 'landing_page', 'no_change', 'already_linked', 'not_waiting' => 'not_waiting',
            'sku_merged', 'unknown_sku' => 'gone',
            default => 'other',
        };
    }

    /**
     * The refusal the address names (`said`, one of SAID, about the row `at`), in words: the store's name where a line names it
     * (`ch`), the way to the website product's page (`prev`) where SAID_LINK says, and what the box held (`t`) to put back in it after
     * any refusal, with no choice behind it (rule 7; Codex's fifth review).
     *
     * @param list<array{id: int, code: string, name: string}> $stores
     * @return array{code: string, text: string, link: ?string, sku: int, channel: ?string, typed: string}|null
     */
    private static function said(Context $ctx, array $stores, ?int $at): ?array
    {
        $code = $ctx->req->param('said');
        if ($at === null || !is_string($code) || !in_array($code, self::SAID, true)) {
            return null;
        }
        $store = ReviewController::store($stores, $ctx->req->param('ch'));
        $prev = UiRequest::id($ctx->req->param('prev'));
        $text = in_array($code, ['other_store', 'not_waiting'], true) ? Words::say('SIMPLE_MATCH', 'said_' . $code, (string) ($store['name'] ?? ''))
            : Words::SIMPLE_MATCH['said_' . $code];
        return ['code' => $code, 'text' => $text, 'link' => $prev !== null && in_array($code, self::SAID_LINK, true) ? '/ui/review/listing/' . $prev : null,
            'sku' => $at, 'channel' => $store['code'] ?? null, 'typed' => mb_substr(trim($ctx->req->param('t') ?? ''), 0, self::KEPT_MAX)];
    }

    /**
     * The page's rows: each warehouse product with one cell per store, in the stores' order. A cell lists the website products matched
     * to it on that store (the best seller first) and then the matches of it that wait for a second OK, each with its name on the store,
     * its ID, "1 sale = N" when N is not 1 and "On hold"; and, for someone who may decide, the add control: shown at once in an empty
     * cell, behind "+" in one that has a product (open again with what the box held after a refusal, and with the product
     * a typed ID names, chosen, to confirm).
     *
     * @param list<array<string, mixed>> $products Queries::productRows
     * @param list<array{id: int, code: string, name: string}> $stores
     * @param array{matched: list<array<string, mixed>>, suggested: list<array<string, mixed>>, waiting: list<array<string, mixed>>} $cells Queries::productCells
     * @param array{code: string, text: string, link: ?string, sku: int, channel: ?string, typed: string}|null $said
     * @param array{label: string, listing_id: int, map_version: int, proposal_id: ?int, option: string, sku: int, channel: string}|null $chosen
     * @return list<array<string, mixed>>
     */
    private static function rows(array $products, array $stores, array $cells, bool $decide, ?int $at, ?array $said, ?array $chosen = null): array
    {
        $by = [];
        foreach (['matched', 'waiting'] as $state) {
            foreach ($cells[$state] as $r) {
                $units = (int) ($r['units_per_item'] ?? 1);
                $notes = [];
                if ($units !== 1) {
                    $notes[] = Words::saleUses($units);
                }
                if ($state === 'matched' && ($r['status'] ?? null) === 'quarantined') {
                    $notes[] = Words::BY_PRODUCT['on_hold'];
                }
                $option = (string) ($r['external_variant_id'] ?? '');
                $by[(int) $r['sku_id']][(int) $r['channel_id']][] = ['listing_id' => (int) $r['listing_id'], 'name' => self::name($r),
                    'id' => $option === '' ? null : Words::say('SIMPLE_MATCH', 'id', $option), 'link' => '/ui/review/listing/' . (int) $r['listing_id'],
                    'waiting' => $state === 'waiting', 'notes' => $notes];
            }
        }
        $rows = [];
        foreach ($products as $p) {
            $id = (int) $p['id'];
            $out = [];
            foreach ($stores as $si => $s) {
                $items = $by[$id][(int) $s['id']] ?? [];
                $here = $said !== null && $said['sku'] === $id && $said['channel'] === (string) $s['code'] ? $said : null;
                $pick = $chosen !== null && $chosen['sku'] === $id && $chosen['channel'] === (string) $s['code'] ? $chosen : null;
                $out[] = [
                    'store' => (string) $s['name'],
                    'channel' => (string) $s['code'],
                    'items' => $items,
                    'said' => $here === null ? null : ['text' => $here['text'], 'link' => $here['link']],
                    'add' => !$decide ? null : [
                        'plus' => $items !== [],
                        'open' => $here !== null || $pick !== null,
                        'typed' => $pick['label'] ?? ($here['typed'] ?? ''),
                        'chosen' => $pick,
                        'id' => 'sm-' . $id . '-' . $si,
                        'options' => Html::url(self::OPTIONS, ['sku' => $id, 'channel' => (string) $s['code']]),
                        'label' => Words::say('SIMPLE_MATCH', 'pick', (string) $s['name']),
                        'title' => Words::say('SIMPLE_MATCH', 'apply_named', (string) $s['name'], (string) $p['code']),
                        'more' => Words::say('SIMPLE_MATCH', 'add_more_named', (string) $s['name'], (string) $p['code']),
                    ],
                ];
            }
            $rows[] = ['id' => $id, 'code' => (string) $p['code'], 'name' => self::text($p['name'] ?? null), 'cells' => $out, 'here' => $at === $id];
        }
        return $rows;
    }

    /**
     * A website product's name on its store: its product's name and its own option name on one line (a line break or a tab the intake
     * kept is a space: a box holds one line, and a choice is the box's words; Codex's sixth review), else "(no name)".
     *
     * @param array<string, mixed> $r
     */
    private static function name(array $r): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($r['product_title'] ?? '') . ' ' . (string) ($r['variant_title'] ?? '')));
        return $name === '' ? Words::LISTING['no_title'] : mb_substr($name, 0, 200);
    }

    /**
     * A choice's name in the dropdown (the owner, 26 Oct 2026: two choices looked the same, their ends cut off): what tells it apart
     * first, as a browser cuts a long choice at its end (SM10). The strength the matching reads from it ("20mg") when known; then its
     * own option name without the product's name when that repeats it ("Red Edition · IVG 2400 ...", not "IVG 2400 ... IVG 2400 ... - Red
     * Edition"); then the product's name. One line, at most 200 characters.
     *
     * @param array<string, mixed> $r a Queries::pickerRows row
     */
    private static function choiceName(array $r): string
    {
        $flat = static fn (mixed $v): string => trim((string) preg_replace('/\s+/u', ' ', (string) ($v ?? '')));
        $product = $flat($r['product_title'] ?? null);
        $variant = $flat($r['variant_title'] ?? null);
        if ($variant === '' || $product === '') {
            $name = $variant !== '' ? $variant : $product;
        } elseif (mb_stripos($variant, $product) !== false) {
            $rest = $flat(trim(str_ireplace($product, ' ', $variant), " \t-|·,:"));
            $rest = trim($rest, " -|·,:");
            $name = $rest === '' ? $variant : $rest . ' · ' . $product;
        } elseif (mb_stripos($product, $variant) !== false) {
            $name = $product;
        } else {
            $name = $variant . ' · ' . $product;
        }
        if ($name === '') {
            return Words::LISTING['no_title'];
        }
        $mg = null;
        try {
            $card = DecisionService::cardFrom(['product_title' => $r['product_title'] ?? null, 'variant_title' => $r['variant_title'] ?? null,
                'brand' => $r['brand'] ?? null], Html::json($r['features'] ?? null));
            $v = $card['strength_mg'] ?? null;
            $mg = is_int($v) || is_float($v) ? Html::dec($v) . 'mg' : null;
        } catch (CwException) {
            $mg = null; // nothing the matching read: the name alone
        }
        // Not read by the matching: the last strength the store's own words name ("... - 20mg"), its option name first.
        foreach ([$variant, $product] as $words) {
            if ($mg === null && preg_match_all('/(\d+(?:\.\d+)?)\s?mg\b/iu', $words, $m) > 0) {
                $mg = end($m[1]) . 'mg';
            }
        }
        // A name that starts with its strength already ("10mg | Black Mamba") does not say it twice.
        $first = $mg !== null && preg_match('/^' . preg_quote(substr($mg, 0, -2), '/') . '\s?mg\b/iu', $name) !== 1;
        return mb_substr(($first ? $mg . ' · ' : '') . $name, 0, 200);
    }

    private static function text(mixed $v): ?string
    {
        return $v === null || $v === '' || !is_scalar($v) ? null : mb_substr((string) $v, 0, 500);
    }
}
