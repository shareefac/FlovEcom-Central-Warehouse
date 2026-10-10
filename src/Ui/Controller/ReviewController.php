<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Mapping\BulkDecisions;
use CW\Mapping\DecisionService;
use CW\Mapping\KeyEligibility;
use CW\Mapping\KeyHold;
use CW\Mapping\KeySample;
use CW\Mapping\Proposals;
use CW\Matching\Form;
use CW\Ui\Compare;
use CW\Ui\Context;
use CW\Ui\Duplicates;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\QueueContext;
use CW\Ui\Queries;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * The matching screens (plan §7.1; in plain words since 7 Oct 2026, plan §6.14-6.16): the lists, one website product at a
 * time next to the warehouse product the computer suggests, and the four answers (match, new product, ignore, wrong product)
 * plus the second person's approve / cancel. Every change goes through DecisionService: this class only reads the form,
 * calls it and shows what it answered (its refusals are shown on the same page in words, by error code, with the form kept).
 *
 * A website product that is one of the 20 of a spot check (M28) is decided by the spot check's owner only: everyone else sees
 * why and no answer forms (behaviour item 5 of the plan, a provisional default of 7 Oct 2026). For the owner "Not a match" has a
 * second step that says it stops the bulk link for good before anything is saved (compare.md §2.1). An already matched website
 * product has no answer form; a matching lead changes a wrong match behind "Change this match" (behaviour item 13, provisional).
 */
final class ReviewController
{
    /** notice key => its words (Words::MATCH_NOTICE). Only these can be shown: a notice never comes from the URL as text. */
    public const NOTICES = [
        'decided_link' => Words::MATCH_NOTICE['decided_link'],
        'decided_new_item' => Words::MATCH_NOTICE['decided_new_item'],
        'decided_ignore' => Words::MATCH_NOTICE['decided_ignore'],
        'decided_reject' => Words::MATCH_NOTICE['decided_reject'],
        'pending_second' => Words::MATCH_NOTICE['pending_second'],
        'approved' => Words::MATCH_NOTICE['approved'],
        'withdrawn' => Words::MATCH_NOTICE['withdrawn'],
        'queue_done' => Words::MATCH_NOTICE['queue_done'],
        'decided_unlink' => Words::MATCH_NOTICE['decided_unlink'],
    ];
    public const ACTIONS = ['link', 'new_item', 'ignore', 'reject', 'unlink'];
    /** The nicotine types the new-product form offers (Matching\Normalizer's values). */
    private const NIC_TYPES = ['salt', 'freebase', 'zero', 'shortfill', 'nic_shot'];
    /** Example numbers for a refused number field of the new-product details. */
    private const NUMBER_EXAMPLE = ['strength_mg' => '20', 'volume_ml' => '10', 'puffs' => '600', 'pack_units' => '10'];

    // ------------------------------------------------------------------------------------------
    // Lists
    // ------------------------------------------------------------------------------------------

    public function queue(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $band = $req->param('queue');
        if ($band === 'pending') {
            return $this->pendingPage($ctx, null, null, 200);
        }
        if ($band === null) {
            return $this->overview($ctx);
        }
        $q = $ctx->queries();
        $channels = $q->channels();
        $names = self::channelNames($channels);
        $qc = QueueContext::from($req->query, 'q', $channels);
        if ($qc === null) {
            return $ctx->error(404, 'unknown_queue', 'there is no such queue', ['/ui/review?queue=Key', Words::MENU['review']]);
        }
        $me = $ctx->me();
        $page = UiRequest::id($req->param('page')) ?? 1;
        $result = $q->queue($qc->band, $qc->channelId, $qc->text, $qc->lane, $qc->min, $page);
        $leadOnly = $qc->band === 'Conflict' && !$me->isLead();
        // Bulk action (M46-M53, U108): the actions this list offers this person; "Select all on this page" without app.js (`all=1`).
        $bulkActions = self::bulkActions('review', $qc->band, $me->roles, BulkDecisions::confirmBands($ctx->db));
        $all = $req->param('all') === '1';
        $rows = [];
        foreach ($result['rows'] as $r) {
            $hasTarget = $r['proposed_sku_id'] !== null || (bool) $r['proposed_new_item'];
            $outcome = self::s($r['ai_outcome']);
            $rows[] = [
                'listing_id' => (int) $r['listing_id'],
                'channel' => $names[(string) $r['channel_code']] ?? self::s($r['channel_code']),
                'variant' => self::s($r['external_variant_id']),
                'title' => self::s($r['product_title']),
                'variant_title' => self::s($r['variant_title']),
                'brand' => self::s($r['brand']),
                'units_30d' => $r['units_30d'],
                'units_365d' => $r['units_365d'],
                'same_barcode' => $r['lane'] === 'barcode',
                // A confidence next to "no product" reads as a contradiction: it is the AI's certainty about its own answer (e.g.
                // "not sure"), not a match; shown only with a suggestion.
                'ai' => $outcome === null ? null : ($hasTarget && $r['ai_confidence'] !== null && !in_array($outcome, ['cannot_tell', 'multiple_plausible'], true)
                    ? Words::say('QUEUE', 'sure', Words::of('AI', $outcome), (int) $r['ai_confidence']) : Words::of('AI', $outcome)),
                'sku_code' => self::s($r['sku_code']),
                'sku_name' => self::s($r['sku_name']),
                'new_item' => (bool) $r['proposed_new_item'],
                'watch' => self::flagWords(Html::strings(Html::json($r['flags']))),
                'link' => Html::url('/ui/review/listing/' . (int) $r['listing_id'], $qc->query()),
                'map_version' => (int) $r['map_version'],
                'proposal_id' => (int) $r['proposal_id'],
                'picked' => $all,
            ];
        }
        $counts = [];
        $bandCounts = $q->bandCounts();
        foreach ($bandCounts as $b => $byChannel) {
            // The strength legend counts the store chosen (U106): the selector's choice applies to every list.
            $counts[$b] = $qc->channelId !== null ? (int) ($byChannel[$qc->channelId] ?? 0) : array_sum(array_map('intval', $byChannel));
        }
        $bands = array_map(static fn (string $b): array => ['band' => $b, 'label' => Words::of('BAND', $b), 'count' => $counts[$b] ?? 0], Proposals::BANDS);
        // The next list with work, for an empty list (F161): after this one in the tab order, else from the first.
        $next = null;
        $at = array_search($qc->band, Proposals::BANDS, true);
        $order = array_merge(array_slice(Proposals::BANDS, (int) $at + 1), array_slice(Proposals::BANDS, 0, (int) $at));
        foreach ($order as $b) {
            if (($counts[$b] ?? 0) > 0) {
                $next = ['href' => Html::url('/ui/review', ['queue' => $b, 'channel' => $qc->channel]), 'label' => Words::of('BAND_TITLE', $b), 'count' => $counts[$b]];
                break;
            }
        }
        $perStore = $bandCounts[$qc->band] ?? [];
        return $ctx->page('queue', [
            'qc' => $qc,
            'channels' => $channels,
            'lanes' => Queries::LANES,
            'bands' => $bands,
            'title' => Words::of('BAND_TITLE', $qc->band),
            'lookOnly' => $me->canDecide() ? null : Words::whoCan('mapping.decide'),
            'leadOnly' => $leadOnly && $me->canDecide(),
            'button' => $me->canDecide() && !$leadOnly ? Words::QUEUE['open'] : Words::QUEUE['look'],
            // A filter that hides what the list still has (F161); an empty list is "empty" even when it was filtered.
            'filtered' => ($qc->lane !== null || $qc->min > 0 || $qc->text !== '') && ($counts[$qc->band] ?? 0) > 0,
            'clear_link' => Html::url('/ui/review', ['queue' => $qc->band, 'channel' => $qc->channel]),
            'stores' => self::storeItems($q->stores(), '/ui/review', ['queue' => $qc->band], $qc->channel, $perStore, true),
            'bulkActions' => $bulkActions,
            'bulkMax' => BulkDecisions::maxRows($ctx->db),
            'bulkKeep' => array_filter($qc->pageQuery() + ['page' => $result['page'] > 1 ? $result['page'] : null], static fn (mixed $v): bool => $v !== null && $v !== ''),
            'allLink' => Html::url('/ui/review', $qc->pageQuery() + ['page' => $result['page'] > 1 ? $result['page'] : null, 'all' => 1]),
            'next_list' => $next,
            'rows' => $rows,
            'total' => $result['total'],
            'page_no' => $result['page'],
            'pages' => $result['pages'],
            'prev_link' => $result['page'] > 1 ? Html::url('/ui/review', $qc->pageQuery() + ['page' => $result['page'] - 1]) : null,
            'next_link' => $result['page'] < $result['pages'] ? Html::url('/ui/review', $qc->pageQuery() + ['page' => $result['page'] + 1]) : null,
        ], 200, ['title' => Words::of('BAND_TITLE', $qc->band), 'active' => 'review', 'notice' => $this->notice($ctx, null)]);
    }

    /**
     * Waiting for a second OK (F147-F153).
     *
     * @param string|null $error a refusal of approve / cancel to show above the list
     */
    private function pendingPage(Context $ctx, ?string $error, ?int $errorDecision, int $status): HtmlResponse
    {
        $me = $ctx->me();
        $q = $ctx->queries();
        $channels = $q->channels();
        $names = self::channelNames($channels);
        $store = self::store($channels, $ctx->req->param('channel'));
        $rows = [];
        foreach ($q->pendingDecisions(200, $store['id'] ?? null) as $d) {
            $rows[] = [
                'listing_id' => (int) $d['listing_id'],
                'channel' => $names[(string) $d['channel_code']] ?? self::s($d['channel_code']),
                'variant' => self::s($d['external_variant_id']),
                'title' => self::s($d['product_title']),
                'variant_title' => self::s($d['variant_title']),
            ] + $this->pendingView($d, $me->id, $me->isLead()) + ['bulk' => DecisionService::isScreenBatch(self::s($d['bulk_batch_id']))
                ? (int) substr((string) $d['bulk_batch_id'], strlen(DecisionService::SCREEN_BATCH_PREFIX)) : null];
        }
        return $ctx->page('pending', [
            'stores' => self::storeItems($q->stores(), '/ui/review', ['queue' => 'pending'], $store['code'] ?? null, $q->pendingByStore(), true),
            'rows' => $rows,
            'error' => $error,
            'error_decision' => $errorDecision,
            'lookOnly' => $me->isLead() || $me->canDecide() ? null : Words::whoCan('mapping.approve'),
        ], $status, ['title' => Words::title('pending'), 'active' => 'pending', 'notice' => $this->notice($ctx, null)]);
    }

    /**
     * Mapping › To review (U107): the store selector and, per store, one board group with a row per match strength (and the products
     * no computer check has suggested anything for yet): what waits for a person, the share of the group's products matched, the
     * share of their 30-day units on matched products, and a button to the store's list of that strength. A total row per store.
     * From Home's matching-progress counts (Queries::bandCounts, unproposed, coverage) and Queries::storeBands.
     */
    private function overview(Context $ctx): HtmlResponse
    {
        $q = $ctx->queries();
        $me = $ctx->me();
        $stores = $q->stores();
        $store = self::store($stores, $ctx->req->param('channel'));
        $bandCounts = $q->bandCounts();
        $unproposed = $q->unproposed();
        $coverage = $q->coverage();
        $byBand = $q->storeBands();
        $zero = ['listings' => 0, 'linked' => 0, 'u30' => 0, 'l30' => 0];
        $waitingByStore = [];
        $groups = [];
        foreach ($stores as $s) {
            $sid = (int) $s['id'];
            $waitingByStore[$sid] = 0;
            foreach (Proposals::BANDS as $b) {
                $waitingByStore[$sid] += (int) ($bandCounts[$b][$sid] ?? 0);
            }
            if ($store !== null && $store['id'] !== $sid) {
                continue;
            }
            $rows = [];
            $sum = $zero;
            foreach (Proposals::BANDS as $b) {
                $f = ($byBand[$sid][$b] ?? []) + $zero;
                $waiting = (int) ($bandCounts[$b][$sid] ?? 0);
                foreach ($zero as $k => $_) {
                    $sum[$k] += $f[$k];
                }
                $rows[] = ['band' => $b, 'tone' => Words::tone('BAND', $b), 'waiting' => $waiting] + $f
                    + ['href' => $waiting > 0 ? Html::url('/ui/review', ['queue' => $b, 'channel' => $s['code']]) : null];
            }
            $cov = $coverage[$sid] ?? [];
            $all = ['listings' => (int) ($cov['listings'] ?? 0), 'linked' => (int) ($cov['linked_listings'] ?? 0), 'u30' => (int) ($cov['u30'] ?? 0),
                'l30' => (int) ($cov['l30'] ?? 0)];
            $rest = [];
            foreach ($zero as $k => $_) {
                $rest[$k] = max(0, $all[$k] - $sum[$k]);
            }
            $groups[] = ['id' => $sid, 'code' => (string) $s['code'], 'name' => (string) $s['name'], 'rows' => $rows,
                'unchecked' => ['waiting' => (int) ($unproposed[$sid] ?? 0)] + $rest,
                'total' => ['waiting' => $waitingByStore[$sid]] + $all,
                'products' => Html::url('/ui/review/store', ['channel' => $s['code']])];
        }
        return $ctx->page('mapping_overview', [
            'stores' => self::storeItems($stores, '/ui/review', [], $store['code'] ?? null, $waitingByStore, true),
            'groups' => $groups,
            'button' => $me->canDecide() ? Words::QUEUE['open'] : Words::QUEUE['look'],
            'lookOnly' => $me->canDecide() ? null : Words::whoCan('mapping.decide'),
        ], 200, ['title' => Words::SEGMENT['review'], 'active' => 'review', 'notice' => $this->notice($ctx, null)]);
    }

    /**
     * The store selector's items (U106): "All stores" first when $all, then each store; each keeps $query and adds `channel`.
     *
     * @param list<array{id: int, code: string, name: string}> $stores
     * @param array<string, scalar|null> $query
     * @param array<int, int> $counts channel id => count
     * @return list<array{label: string, href: string, query: array<string, scalar|null>, count: int, current: bool}>
     */
    public static function storeItems(array $stores, string $path, array $query, ?string $current, array $counts, bool $all): array
    {
        $known = in_array($current, array_column($stores, 'code'), true) ? $current : null;
        $out = [];
        if ($all) {
            $out[] = ['label' => Words::BULK['all_stores'], 'href' => $path, 'query' => $query, 'count' => array_sum(array_map('intval', $counts)),
                'current' => $known === null];
        }
        foreach ($stores as $s) {
            $out[] = ['label' => (string) $s['name'], 'href' => $path, 'query' => $query + ['channel' => $s['code']], 'count' => (int) ($counts[(int) $s['id']] ?? 0),
                'current' => $known === $s['code']];
        }
        return $out;
    }

    /**
     * The bulk actions a list offers this person (BulkDecisions::offered), with their button words and look: the safe ones primary,
     * the ones that say no secondary with "…" (a second step follows).
     *
     * @param list<string> $roles
     * @param list<string> $confirmBands
     * @return list<array{action: string, label: string, class: string}>
     */
    public static function bulkActions(string $source, ?string $band, array $roles, array $confirmBands): array
    {
        $out = [];
        foreach ($source === 'store' ? BulkDecisions::STORE_ACTIONS : BulkDecisions::REVIEW_ACTIONS as $a) {
            if (BulkDecisions::offered($a, $source, $band, $roles, $confirmBands)) {
                $out[] = ['action' => $a, 'label' => Words::BULK_ACTION[$a], 'class' => in_array($a, BulkDecisions::NEGATIVE, true) ? 'secondary' : 'primary'];
            }
        }
        return $out;
    }

    /** @param list<array{id: int, code: string, name: string}> $channels @return array{id: int, code: string, name: string}|null the store a code names */
    public static function store(array $channels, ?string $code): ?array
    {
        foreach ($channels as $c) {
            if ($code !== null && $c['code'] === $code) {
                return $c;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------------------------------
    // One website product
    // ------------------------------------------------------------------------------------------

    public function listing(Context $ctx): HtmlResponse
    {
        $qc = QueueContext::from($ctx->req->query, 'fq', $ctx->queries()->channels());
        return $this->show($ctx, $ctx->id(), $qc, null, null, 200);
    }

    /**
     * @param array<string, string>|null $form the values of a refused form, shown again
     */
    private function show(Context $ctx, int $id, ?QueueContext $qc, ?array $form, ?string $error, int $status, ?string $errorField = null): HtmlResponse
    {
        $q = $ctx->queries();
        $req = $ctx->req;
        $l = $q->listing($id);
        if ($l === null) {
            return $ctx->error(404, 'unknown_listing', 'no such listing', ['/ui/search', Words::title('search')]);
        }
        $me = $ctx->me();
        $channels = $q->channels();
        $names = self::channelNames($channels);
        $features = Html::json($l['features']);
        $card = DecisionService::cardFrom(
            ['product_title' => $l['product_title'], 'variant_title' => $l['variant_title'], 'brand' => $l['brand']],
            $features,
        );
        $proposal = $q->openProposal($id);
        $ev = $proposal === null ? [] : Html::json($proposal['evidence']);
        $ai = is_array($ev['ai'] ?? null) ? $ev['ai'] : [];
        $listingBarcodes = Html::strings(Html::json($l['barcodes']));
        $pending = $q->pendingOf($id);
        $proposedId = $proposal !== null && $proposal['proposed_sku_id'] !== null ? (int) $proposal['proposed_sku_id'] : null;

        // The product on the right. While a decision waits for a second person it is the product THAT decision matches (the
        // approver approves what the page compares; ?pick is ignored); otherwise a picked one, else the one the refused form
        // was sent for, else the suggestion's.
        $pendingTarget = $pending !== null && $pending['sku_id'] !== null && in_array($pending['action'], ['link', 'reject', 'merge_skus', 'split'], true)
            ? (int) $pending['sku_id'] : null;
        $pick = $pending !== null ? null : (UiRequest::id($req->param('pick')) ?? ($form !== null ? UiRequest::id($form['sku_id'] ?? null) : null));
        // Already matched (behaviour item 13, F184, provisional): with nothing picked or suggested, the page compares the website
        // product with the warehouse product it is matched to.
        $matched = $pending === null && $l['sku_id'] !== null && in_array($l['status'], DecisionService::LINKED, true);
        $linkedId = $matched ? (int) $l['sku_id'] : null;
        $targetId = $pending !== null ? $pendingTarget : ($pick ?? $proposedId ?? $linkedId);
        $candidates = [];
        foreach (is_array($ev['candidates'] ?? null) ? $ev['candidates'] : [] as $c) {
            if (is_array($c)) {
                $candidates[] = $c; // every candidate the judge saw (C1..C15), in its order: the reason text cites them
            }
        }
        $chosen = is_array($ai['chosen'] ?? null) ? $ai['chosen'] : null;
        $chosenId = is_int($chosen['sku_id'] ?? null) ? $chosen['sku_id'] : null;
        $partners = [];
        foreach (is_array($ev['relabel_partners'] ?? null) ? array_slice($ev['relabel_partners'], 0, 50) : [] as $p) {
            if (is_array($p)) {
                $partners[] = $p;
            }
        }
        $wanted = [$targetId, $proposedId, $proposal['closest_sku_id'] ?? null, is_array($ev['lane_target'] ?? null) ? ($ev['lane_target']['sku_id'] ?? null) : null, $chosenId,
            $l['sku_id'] === null ? null : (int) $l['sku_id']];
        foreach ([...$candidates, ...$partners] as $c) {
            $wanted[] = $c['sku_id'] ?? null;
        }
        $skus = $q->skus(array_values(array_filter(array_map(static fn (mixed $v): int => is_int($v) ? $v : 0, $wanted))));

        $target = $targetId !== null ? ($skus[$targetId] ?? null) : null;
        $pickNote = null;
        if ($pick !== null && ($target === null || $target['merged_into_sku_id'] !== null)) {
            $pickNote = $target === null ? Words::LISTING['pick_missing'] : Words::LISTING['pick_merged'];
            $targetId = $proposedId;
            $target = $targetId !== null ? ($skus[$targetId] ?? null) : null;
        }
        $aliases = $q->brandAliases();
        $targetBarcodes = $target !== null ? ($q->barcodesOfMany([(int) $target['id']])[(int) $target['id']] ?? []) : [];
        $compareRows = $target !== null ? Compare::rows($card, $target, $listingBarcodes, $targetBarcodes, $aliases) : [];

        // Where else this listing's barcodes are (another listing, e.g. a binned Vape and Go variant never minted, or an item):
        // what a "new product" could duplicate. The target and its listings are left out.
        $elsewhere = ['items' => [], 'listings' => []];
        if ($listingBarcodes !== []) {
            $where = $q->barcodeElsewhere($id, $listingBarcodes);
            // A matched website product's own warehouse product (and its other website products) are no "may duplicate" (F184).
            $linkedCode = $linkedId !== null && isset($skus[$linkedId]) ? (string) $skus[$linkedId]['code'] : null;
            foreach ($where['items'] as $r) {
                if (($target === null || (int) $r['id'] !== (int) $target['id']) && (int) $r['id'] !== $linkedId) {
                    $elsewhere['items'][] = ['id' => (int) $r['id'], 'code' => self::s($r['code']), 'name' => self::s($r['name']), 'barcode' => self::s($r['barcode'])];
                }
            }
            foreach ($where['listings'] as $r) {
                if (($target === null || $r['sku_code'] === null || $r['sku_code'] !== $target['code']) && ($linkedCode === null || $r['sku_code'] !== $linkedCode)) {
                    $elsewhere['listings'][] = ['id' => (int) $r['id'], 'channel' => $names[(string) $r['channel_code']] ?? self::s($r['channel_code']),
                        'variant' => self::s($r['external_variant_id']), 'status' => self::s($r['status']), 'site_status' => self::s($r['variant_status']),
                        'sku_code' => self::s($r['sku_code']), 'title' => self::s(trim(((string) $r['product_title']) . ' ' . ((string) $r['variant_title']))),
                        'units_365d' => $r['units_365d']];
                }
            }
        }
        $clash = $elsewhere['items'] !== [] || $elsewhere['listings'] !== [];

        $searchText = mb_substr(trim($req->param('s') ?? ''), 0, 100);
        $found = [];
        if ($searchText !== '') {
            $rows = $q->searchSkus($searchText);
            $bc = $q->barcodesOfMany(array_map(static fn (array $r): int => (int) $r['id'], $rows));
            foreach ($rows as $r) {
                $found[] = $this->skuView($r) + ['barcodes' => $bc[(int) $r['id']] ?? []];
            }
        }

        $units = $proposal !== null && $proposal['ai_units_per_item'] !== null ? (int) $proposal['ai_units_per_item']
            : ($l['sku_id'] !== null ? (int) $l['units_per_item'] : 1);
        $band = $proposal !== null ? (string) $proposal['band'] : null;
        // Preselection (plan §7.1, U8): Key -> confirm the suggested product; New item -> create one, unless its barcode is
        // already on another listing or item (then a person looks first).
        $preselect = null;
        if ($band === 'Key' && $target !== null && $pick === null && $pending === null && $proposedId === (int) $target['id']) {
            $preselect = 'link';
        } elseif ($band === 'New item' && $proposal !== null && (bool) $proposal['proposed_new_item'] && $pick === null && $pending === null && !$clash) {
            $preselect = 'new_item';
        }
        $defaults = ['action' => $preselect ?? '', 'units' => (string) $units, 'reason' => '', 'sku_id' => $target !== null ? (string) $target['id'] : ''];
        foreach (DecisionService::CARD_FIELDS as $f) {
            $defaults['card_' . $f] = Html::dec($card[$f] ?? null);
        }
        $values = $form !== null ? $form + $defaults : $defaults;

        $protected = $target !== null && $target['sell_policy'] !== 'legacy';
        $rejectedIds = $q->rejectedOf($id);
        $next = $qc !== null ? $q->nextInQueue($qc->band, $qc->channelId, $qc->text, $qc->lane, $qc->min, $id) : null;
        // Opened from a spot check (M28): the page, its forms and its picks lead back there instead of to a list.
        $sample = null;
        $sampleId = $qc === null ? UiRequest::id($req->param('sample') ?? $req->field('sample')) : null;
        if ($sampleId !== null) {
            $k = $ctx->db->one('SELECT id, name FROM key_sample WHERE id = ?', [$sampleId]);
            $sample = $k === null ? null : ['id' => (int) $k['id'], 'name' => (string) $k['name'], 'url' => '/ui/review/samples/' . (int) $k['id']];
        }
        // The open proposal is a member of a spot check (M28): only the spot check's owner decides it. Anyone else's decision (or
        // one by the owner that is not a plain confirmation) makes the spot check fail, and the bulk confirm then refuses.
        $spot = null;
        $memberSql = 'SELECT k.id, k.name, k.created_by, k.sample_size, m.position, u.display_name FROM key_sample_member m '
            . 'JOIN key_sample k ON k.id = m.sample_id LEFT JOIN staff_user u ON u.id = k.created_by WHERE %s AND m.position IS NOT NULL ORDER BY k.id DESC LIMIT 1';
        $m = $proposal !== null ? $ctx->db->one(sprintf($memberSql, 'm.proposal_id = ?'), [(int) $proposal['id']]) : null;
        // A member answered already (its suggestion is closed, the listing matched by the owner's yes): changing that match now
        // fails the spot check as well (KeySample: changed_since, decided_otherwise), so the page still guards it, below, while
        // the spot check has not failed and this member is a confirmed one.
        $done = false;
        if ($m === null) {
            $m = $ctx->db->one(sprintf($memberSql, 'm.listing_id = ?'), [$id]);
            $done = $m !== null;
        }
        if ($m !== null) {
            $spot = ['id' => (int) $m['id'], 'name' => (string) $m['name'], 'position' => (int) $m['position'], 'size' => (int) $m['sample_size'],
                'owner' => self::s($m['display_name']) ?? Words::ROLE['mapping_lead'], 'mine' => (int) $m['created_by'] === $me->id,
                'url' => '/ui/review/samples/' . (int) $m['id'], 'done' => $done];
        }
        $strip = ($spot['id'] ?? $sample['id'] ?? null) !== null ? $this->strip($ctx, (int) ($spot['id'] ?? $sample['id']), $id) : null;
        if ($spot !== null && $spot['done'] && ($strip === null || $strip['verdict'] === 'failed' || $strip['state_here'] !== 'confirmed')) {
            // Failed already (nothing more to lose) or not a confirmed member: an ordinary page. A strip is shown only when the
            // page was opened from a spot check.
            $wasId = $spot['id'];
            $spot = null;
            $strip = $sample === null ? null : ($sample['id'] === $wasId ? $strip : $this->strip($ctx, $sample['id'], $id));
        }
        // Held back from every bulk confirm for one-at-a-time review (M30): the hold is on the LISTING, whatever its open proposal
        // is now (a newer run's or a re-band's too); the listing stays in its list, and the page says why.
        $held = null;
        $h = KeyHold::activeForListings($ctx->db, [$id])[$id] ?? null;
        if ($h !== null) {
            $held = ['reason' => $h['reason'], 'by' => $h['by'] ?? Words::ROLE['mapping_lead'], 'at' => $h['at'], 'sample' => $h['sample'],
                'url' => '/ui/review/samples/' . $h['sample_id'], 'proposal_id' => $h['proposal_id'],
                'newer' => $proposal !== null && (int) $proposal['id'] !== $h['proposal_id'],
                'waiting' => in_array($l['status'], KeyEligibility::OPEN_LISTING, true)];
        }
        $qq = $qc !== null ? $qc->query() : ($sample !== null ? ['sample' => $sample['id']] : []);
        $pickUrl = static fn (int $sid): string => Html::url('/ui/review/listing/' . $id, $qq + ['pick' => $sid]) . '#decide';
        $usable = static fn (?int $sid): bool => $sid !== null && isset($skus[$sid]) && $skus[$sid]['merged_into_sku_id'] === null;

        $attributes = [];
        foreach (is_array(Html::json($l['attributes'])['items'] ?? null) ? array_slice(Html::json($l['attributes'])['items'], 0, 30) : [] as $a) {
            if (is_array($a) && (is_string($a['name'] ?? null) || is_string($a['value'] ?? null) || is_int($a['value'] ?? null))) {
                $attributes[] = ['name' => self::s($a['name'] ?? null), 'value' => self::s($a['value'] ?? null)];
            }
        }
        $candView = [];
        $chosenListed = false;
        foreach ($candidates as $c) {
            $sid = is_int($c['sku_id'] ?? null) ? $c['sku_id'] : null;
            $chosenListed = $chosenListed || ($sid !== null && $sid === $chosenId);
            $candView[] = [
                'ref' => self::s($c['ref'] ?? null, 8),
                'sku' => $sid !== null && isset($skus[$sid]) ? $this->skuView($skus[$sid]) : null,
                'cw_id' => self::s($c['cw_id'] ?? null),
                'prescore' => self::s($c['prescore'] ?? null),
                'problems' => self::problems(Html::strings($c['vetoes'] ?? null), Html::strings($c['soft_flags'] ?? null)),
                'ai_picked' => $sid !== null && $sid === $chosenId,
                'proposed' => $sid !== null && $sid === $proposedId,
                'pick' => $usable($sid) ? $pickUrl((int) $sid) : null,
            ];
        }
        if ($chosenId !== null && !$chosenListed) {
            // The judge's pick is always a row, even when the candidate list does not carry it.
            array_unshift($candView, ['ref' => null, 'sku' => isset($skus[$chosenId]) ? $this->skuView($skus[$chosenId]) : null,
                'cw_id' => self::s($chosen['cw_id'] ?? null), 'prescore' => null,
                'problems' => self::problems(Html::strings($ai['vetoes_on_chosen'] ?? null), Html::strings($ai['soft_flags_on_chosen'] ?? null)),
                'ai_picked' => true, 'proposed' => $chosenId === $proposedId, 'pick' => $usable($chosenId) ? $pickUrl($chosenId) : null]);
        }
        $closestId = $proposal !== null && $proposal['closest_sku_id'] !== null ? (int) $proposal['closest_sku_id'] : null;
        $lead = $me->isLead();

        // Who may answer here, and if not, why (F205, F210, F229; behaviour item 5 for a spot check's match, 13 for a matched one).
        $noForm = null;
        $showSearch = true;
        $changeMatch = false;
        if (!$me->canDecide()) {
            $noForm = Words::LISTING['look_only'];
            $showSearch = false;
        } elseif ($spot !== null && !$spot['mine']) {
            $noForm = $spot['done'] ? Words::say('SPOT', 'other_done', $spot['owner']) . ' ' . Words::SPOT['other_done_text']
                : Words::say('SPOT', 'other', $spot['owner']) . ' ' . Words::SPOT['other_text'];
            $showSearch = false;
        } elseif ($matched) {
            // Behaviour item 13 (F184, provisional): nothing to decide on a matched website product, so no answer form and no quick
            // yes. A matching lead can still change a wrong match, behind "Change this match" (folded); anyone else is told to ask one.
            $matchedSku = $linkedId !== null ? ($skus[$linkedId] ?? null) : null;
            $noForm = Words::say('LISTING', 'matched_nothing', trim(($matchedSku['code'] ?? '') . ' ' . (self::s($matchedSku['name'] ?? null) ?? '')),
                Words::saleUses((int) $l['units_per_item'])) . ' '
                . Words::LISTING[$lead ? 'matched_lead' : 'matched_ask'];
            $showSearch = $lead;
            $changeMatch = $lead;
        } elseif ($band === 'Conflict' && !$lead) {
            $noForm = Words::LISTING['lead_only'];
            $showSearch = false;
        } elseif ($pending !== null) {
            $noForm = (int) $pending['decided_by'] === $me->id ? Words::LISTING['wait_own'] : ($lead ? Words::LISTING['wait_lead'] : Words::LISTING['wait_other']);
            $showSearch = false;
        }
        // The spot check's own matching lead answers it as design B asks: Yes / Not a match (a second step, nothing saved before
        // it) / Not sure (nothing saved).
        $spotMode = $spot !== null && $spot['mine'] && !$spot['done'] && $noForm === null;
        if ($spotMode && $form === null && $preselect === 'link') {
            $values['action'] = 'reject'; // the answer form sits behind "Not a match": its first answer is "No, wrong product"
        }
        // The owner's "Yes, same product" always confirms the SUGGESTED product with the suggestion's units (what the spot check
        // counts as a yes), also after a refused answer or with another product picked; matching a picked other product is an
        // answer of the "Not a match" step (KeySample: decided_otherwise, so the spot check fails), worded as such there.
        $spotYes = null;
        $spotInstead = null;
        $spotOpen = false;
        if ($spotMode) {
            $spotInstead = $target !== null && (int) $target['id'] !== $proposedId ? (string) $target['code'] : null;
            if ($proposedId !== null && $usable($proposedId)) {
                $spotYes = ['sku_id' => $proposedId, 'code' => (string) $skus[$proposedId]['code'], 'units' => (string) $units];
            }
            // A refused answer opens the second step again, unless it was the plain yes (a stale page, say): that is not "Not a match".
            $spotOpen = $error !== null && !(($values['action'] ?? '') === 'link' && UiRequest::id($values['sku_id'] ?? null) === $proposedId);
        }

        // Why the computer suggests this, in words (F173-F177, F192-F195); the codes go to Technical details.
        $why = null;
        if ($proposal !== null) {
            $outcome = self::s($proposal['ai_outcome']);
            $fields = [];
            $fna = $ai['fields_not_agree'] ?? null;
            foreach (is_array($fna) ? $fna : [] as $k => $v) {
                if (is_string($k)) {
                    $fields[] = ['field' => mb_substr($k, 0, 40), 'state' => is_string($v) ? mb_substr($v, 0, 20) : null];
                } elseif (is_string($v)) {
                    $fields[] = ['field' => mb_substr($v, 0, 40), 'state' => null];
                }
            }
            usort($fields, static fn (array $a, array $b): int => [$a['state'] !== 'conflict', $a['field']] <=> [$b['state'] !== 'conflict', $b['field']]);
            $targetVetoes = Html::strings($ev['target_vetoes'] ?? null);
            $targetSoft = Html::strings($ev['target_soft_flags'] ?? null);
            $watch = [];
            foreach ($fields as $f) {
                $watch[] = sprintf(Words::AI_FIELD_STATE[$f['state'] ?? 'none'] ?? Words::AI_FIELD_STATE['none'], Words::of('AI_FIELD', $f['field']));
            }
            foreach ([...$targetSoft, ...Html::strings($ai['soft_flags_on_chosen'] ?? null)] as $flag) {
                $watch[] = Words::of('FLAG', $flag);
            }
            $cannot = [];
            $proposedCode = $proposedId !== null && isset($skus[$proposedId]) ? (string) $skus[$proposedId]['code'] : Words::THING['proposed_item'];
            foreach ($targetVetoes as $v) {
                $cannot[] = Words::say('LISTING', 'cannot', $proposedCode, Words::of('VETO', $v));
            }
            foreach (Html::strings($ai['vetoes_on_chosen'] ?? null) as $v) {
                $cannot[] = Words::say('LISTING', 'cannot_ai', Words::of('VETO', $v));
            }
            $bc = 'unknown';
            foreach ($compareRows as $r) {
                if ($r['field'] === 'barcodes') {
                    $bc = $r['state'];
                }
            }
            $reason = self::s($ai['reason'] ?? null, 1500);
            $refs = array_filter(array_map(static fn (array $c): ?string => $c['ref'], $candView));
            $why = [
                'band' => Words::of('BAND', $band), 'band_help' => Words::of('BAND_HELP', $band),
                'barcode' => $target === null ? null : ['tone' => $bc === 'same' ? 'done' : ($bc === 'differs' ? 'blocked' : 'off'),
                    'text' => Words::LISTING['barcode_' . ($bc === 'same' || $bc === 'differs' ? $bc : 'unknown')]],
                'ai' => $outcome === null ? null : ($proposal['ai_confidence'] !== null && !in_array($outcome, ['cannot_tell', 'multiple_plausible'], true)
                    ? Words::say('LISTING', 'ai_sure', Words::of('AI', $outcome), (int) $proposal['ai_confidence']) : Words::of('AI', $outcome)),
                'here' => self::bandReasons(Html::strings($ev['band_reasons'] ?? null)),
                'watch' => array_values(array_unique($watch)),
                'cannot' => array_values(array_unique($cannot)),
                'renamed' => self::renamed(self::s($ev['relabel_pending'] ?? null)),
                // The AI's reason, with C1, C2 … as links to the rows they name (F225).
                'reason' => $reason === null ? null : array_map(static fn (string $part): array => ['text' => $part, 'ref' => in_array($part, $refs, true)],
                    preg_split('/\b(C\d{1,2})\b/', $reason, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$reason]),
            ];
        }

        // The comparison: rows with something on either side only, values in words (F190, F191, F232).
        $compare = [];
        foreach ($compareRows as $r) {
            if ($r['listing'] === '' && $r['item'] === '') {
                continue;
            }
            $compare[] = ['label' => $r['label'], 'listing' => self::fieldValue($r['field'], $r['listing']), 'item' => self::fieldValue($r['field'], $r['item']),
                'state' => $r['state']];
        }
        $newRows = [];
        if ($target === null) {
            foreach (Compare::rows($card, [], $listingBarcodes, [], $aliases) as $r) {
                if ($r['listing'] !== '') {
                    $newRows[] = ['label' => $r['label'], 'value' => self::fieldValue($r['field'], $r['listing'])];
                }
            }
        }
        $linked = $l['sku_id'] !== null ? ($skus[(int) $l['sku_id']] ?? $q->skus([(int) $l['sku_id']])[(int) $l['sku_id']] ?? null) : null;
        $title = self::s($l['product_title']);
        $dups = new Duplicates($ctx->db);
        $dupGroups = $dups->groupNumbersOfListings([$id])[$id] ?? [];
        // Behaviour item 11 (F207, provisional): the sales are the sales history's when it is loaded for the site, as on Possible
        // duplicates (one source, so the two pages agree), else the website's own figures; the page says which.
        $sold = $dups->soldFromHistory([$id => $l])[$id] ?? null;

        return $ctx->page('listing', [
            'l' => [
                'id' => (int) $l['id'], 'channel' => self::s($l['channel_code']), 'channel_name' => self::s($l['channel_name']),
                'variant' => self::s($l['external_variant_id']), 'status' => self::s($l['status']), 'title' => $title,
                'variant_title' => self::s($l['variant_title']), 'brand' => self::s($l['brand']), 'price' => self::s($l['price']),
                'url' => Html::safeUrl(is_string($l['perma_link']) ? $l['perma_link'] : null),
                'units_30d' => $sold !== null ? $sold['u30'] : $l['units_30d'], 'units_365d' => $sold !== null ? $sold['u365'] : $l['units_365d'],
                'units_to' => $sold['to'] ?? null,
                'units_per_item' => (int) $l['units_per_item'], 'map_version' => (int) $l['map_version'],
                'sku_id' => $l['sku_id'] === null ? null : (int) $l['sku_id'],
            ],
            'attributes' => $attributes,
            'listing_barcodes' => $listingBarcodes,
            'compare' => $compare,
            'new_rows' => $newRows,
            'target' => $target !== null ? $this->skuView($target) : null,
            'target_barcodes' => $targetBarcodes,
            'target_heading' => $pending !== null ? Words::LISTING['pending_target']
                : ($pick !== null && $pickNote === null && $target !== null ? Words::LISTING['picked']
                : ($linkedId !== null && $target !== null && (int) $target['id'] === $linkedId ? Words::LISTING['matched_heading'] : Words::LISTING['suggested'])),
            'pick_note' => $pickNote,
            'elsewhere' => $elsewhere,
            'linked_sku' => $linked === null ? null : ['id' => (int) $linked['id'], 'code' => (string) $linked['code'], 'name' => self::s($linked['name'])],
            'proposal' => $proposal === null ? null : [
                'id' => (int) $proposal['id'], 'band' => $band,
                'run_band' => is_string($ev['band'] ?? null) && $ev['band'] !== $band ? mb_substr($ev['band'], 0, 60) : null,
                'lane' => self::s($proposal['lane']), 'run' => self::s($proposal['run_id']),
                'new_item' => (bool) $proposal['proposed_new_item'], 'created_at' => self::s($proposal['created_at']),
                'band_reasons' => Html::strings($ev['band_reasons'] ?? null),
                'flags' => Html::strings(Html::json($proposal['flags'])),
                'lane_flags' => Html::strings($ev['lane_flags'] ?? null),
                'ai' => [
                    'outcome' => self::s($proposal['ai_outcome']), 'confidence' => $proposal['ai_confidence'],
                    'model' => self::s($proposal['ai_model']), 'warnings' => Html::strings($ai['warnings'] ?? null),
                    'fields' => array_map(static fn (array $f): string => $f['field'] . ($f['state'] !== null ? ': ' . $f['state'] : ''), $fields ?? []),
                    'vetoes' => [...Html::strings($ev['target_vetoes'] ?? null), ...Html::strings($ai['vetoes_on_chosen'] ?? null)],
                    'soft' => [...Html::strings($ev['target_soft_flags'] ?? null), ...Html::strings($ai['soft_flags_on_chosen'] ?? null)],
                    'roles' => array_values(array_filter(array_map(static fn (array $c): ?string => self::s($c['role'] ?? null), $candidates))),
                    'chosen' => $chosen === null ? null : [
                        'sku' => $chosenId !== null && isset($skus[$chosenId]) ? $this->skuView($skus[$chosenId]) : null,
                        'cw_id' => self::s($chosen['cw_id'] ?? null), 'title' => self::s($chosen['title'] ?? null),
                        'pick' => $usable($chosenId) ? $pickUrl((int) $chosenId) : null, 'is_target' => $chosenId !== null && $chosenId === $targetId,
                    ],
                ],
                'proposed_sku_id' => $proposedId,
            ],
            'why' => $why,
            'partners' => array_map(fn (array $p): array => [
                'sku' => is_int($p['sku_id'] ?? null) && isset($skus[$p['sku_id']]) ? $this->skuView($skus[$p['sku_id']]) : null,
                'cw_id' => self::s($p['cw_id'] ?? null), 'title' => self::s($p['title'] ?? null),
                'pick' => is_int($p['sku_id'] ?? null) && $usable($p['sku_id']) ? $pickUrl($p['sku_id']) : null,
            ], $partners),
            'closest' => $closestId !== null && isset($skus[$closestId]) && $closestId !== $targetId ? $this->skuView($skus[$closestId]) : null,
            'closest_pick' => $usable($closestId) ? $pickUrl((int) $closestId) : null,
            'candidates' => $candView,
            'search_text' => $searchText,
            'found' => $found,
            'show_search' => $showSearch,
            'qc' => $qc,
            'qq' => $qq,
            'back' => $qc !== null ? [Html::url('/ui/review', $qc->pageQuery()), Words::of('BAND_TITLE', $qc->band)]
                : ($sample !== null ? [$sample['url'], Words::say('SAMPLE', 'title', $sample['name'])] : self::back($req)),
            'sample' => $sample,
            'spot' => $spot,
            'spot_mode' => $spotMode,
            'strip' => $strip,
            'held' => $held,
            // The duplicate groups this listing is in (M44): their page holds the decision and the undo of a wrong join.
            'dup_groups' => $dupGroups,
            'next_link' => $next !== null ? Html::url('/ui/review/listing/' . $next, $qq) : null,
            'pending' => $pending === null ? null : $this->pendingView($pending, $me->id, $lead),
            'decisions' => array_map(static fn (array $d): array => [
                'action' => self::s($d['action']), 'state' => self::s($d['state']), 'sku_code' => self::s($d['sku_code']),
                'units' => in_array($d['action'], ['link', 'new_item'], true) && $d['units_per_item'] !== null ? Words::saleUses($d['units_per_item']) : null,
                'reason' => self::s($d['reason']), 'decider' => $d['action'] === 'suggest' || $d['decider'] === null ? Words::LISTING['h_computer'] : self::s($d['decider']),
                'created_at' => self::s($d['created_at']),
            ], $q->decisionsOf($id)),
            'form' => $values,
            'no_form' => $noForm,
            'preselect' => $preselect,
            'quick_link' => !$spotMode && $preselect === 'link' && $noForm === null && $form === null,
            'spot_yes' => $spotYes,
            'spot_instead' => $spotInstead,
            'spot_open' => $spotOpen,
            'protected' => $protected,
            'previously_rejected' => $target !== null && in_array((int) $target['id'], $rejectedIds, true),
            'error' => $error,
            'error_field' => $errorField,
            'card_fields' => self::cardFields($values),
            'max_units' => DecisionService::MAX_UNITS,
            'max_reason' => DecisionService::MAX_REASON,
            'can_link' => $target !== null,
            // A matching lead's "Change this match" (behaviour item 13): the answers about another product than the matched one.
            'change_match' => $changeMatch,
            'change_target' => $changeMatch && $target !== null && (int) $target['id'] !== $linkedId ? $this->skuView($target) : null,
            // ... on a spot check's confirmed member (its owner): the change fails the spot check, so it says so first.
            'change_spot' => $changeMatch && $spot !== null && $spot['done'],
            'is_lead' => $lead,
            'lookOnly' => $me->canDecide() ? null : Words::whoCan('mapping.decide'),
            // The intro says what this person can do here: choose, or only look (a matched one, another lead's spot check).
            'intro_page' => $spot !== null && !$spot['mine'] && $me->canDecide() ? 'listing_spot' : ($matched ? 'listing_matched' : 'listing'),
        ], $status, ['title' => $title ?? Words::LISTING['no_title'], 'active' => 'review', 'notice' => $this->notice($ctx, $id)]);
    }

    /**
     * The spot check's 20 blocks (design B) as this page shows them: each member's state, this one, and the next one still to
     * answer (by position after this one, else the first) for its owner.
     *
     * @return array{id: int, name: string, size: int, mine: bool, blocks: list<array{state: string, title: string}>, yes: int, wrong: int, todo: int,
     *               position: ?int, state_here: ?string, verdict: string, next: ?string, next_position: ?int, decided: int, url: string}
     */
    private function strip(Context $ctx, int $sampleId, int $listingId): ?array
    {
        try {
            $s = (new KeySample($ctx->db))->status($sampleId);
        } catch (CwException) {
            return null;
        }
        $blocks = [];
        $position = null;
        $stateHere = null;
        $yes = $wrong = $todo = 0;
        $next = null;
        $first = null;
        foreach ($s['members'] as $m) {
            $state = $m['state'] === 'confirmed' ? 'yes' : ($m['state'] === 'open' ? 'todo' : 'no');
            $yes += $state === 'yes' ? 1 : 0;
            $wrong += $state === 'no' ? 1 : 0;
            $todo += $state === 'todo' ? 1 : 0;
            $here = $m['listing_id'] === $listingId;
            if ($here) {
                $position = $m['position'];
                $stateHere = $m['state'];
            }
            if ($state === 'todo' && !$here) {
                $first ??= $m;
                if ($position !== null && $next === null) {
                    $next = $m;
                }
            }
            $blocks[] = ['state' => $here ? 'now' : $state, 'title' => Words::say('SPOT', $here ? 'block_now' : ['yes' => 'block_yes', 'no' => 'block_wrong', 'todo' => 'block_todo'][$state],
                (int) $m['position'])];
        }
        $next ??= $first;
        return ['id' => $s['id'], 'name' => $s['name'], 'size' => $s['size'], 'mine' => $s['created_by_id'] === $ctx->me()->id, 'blocks' => $blocks,
            'yes' => $yes, 'wrong' => $wrong, 'todo' => $todo, 'position' => $position, 'state_here' => $stateHere, 'verdict' => $s['verdict'],
            'decided' => $s['decided'],
            'next' => $next === null ? null : Html::url('/ui/review/listing/' . $next['listing_id'], ['sample' => $s['id']]),
            'next_position' => $next === null ? null : (int) $next['position'], 'url' => '/ui/review/samples/' . $s['id']];
    }

    /**
     * A waiting decision as the approver must see it: what it does (for new_item the product details it will create, field by
     * field), why it waits, who decided it, and whether it went stale (the listing changed, its identity included, or another
     * suggestion opened since: approving is then refused).
     *
     * @param array<string, mixed> $d a Queries::pendingOf / pendingDecisions row
     * @return array<string, mixed>
     */
    private function pendingView(array $d, int $me, bool $lead): array
    {
        $own = (int) $d['decided_by'] === $me;
        $card = [];
        $name = null;
        if ($d['action'] === 'new_item') {
            $c = Html::json($d['detail'])['card'] ?? [];
            foreach (DecisionService::CARD_FIELDS as $f) {
                $v = is_array($c) ? ($c[$f] ?? null) : null;
                if (is_string($v) || is_int($v) || is_float($v)) {
                    if ($f === 'name') {
                        $name = (string) $v;
                    }
                    $card[] = ['label' => Words::FIELD[$f], 'value' => self::fieldValue($f, Html::dec($v))];
                }
            }
        }
        $stale = [];
        if ((int) $d['map_version'] !== (int) $d['expected_map_version']) {
            $stale[] = Words::LISTING['stale_version'];
        }
        if (($d['open_proposal_id'] === null ? null : (int) $d['open_proposal_id']) !== ($d['proposal_id'] === null ? null : (int) $d['proposal_id'])) {
            $stale[] = Words::LISTING['stale_proposal'];
        }
        $reason = self::s($d['reason']);
        return [
            'id' => (int) $d['id'], 'action' => self::s($d['action']),
            'sku_id' => $d['sku_id'] === null ? null : (int) $d['sku_id'], 'sku_code' => self::s($d['sku_code']), 'sku_name' => self::s($d['sku_name']),
            'merge_from_id' => $d['merge_from_sku_id'] === null ? null : (int) $d['merge_from_sku_id'],
            'merge_from_code' => self::s($d['merge_from_code']), 'merge_from_name' => self::s($d['merge_from_name']),
            // A split (M33): the item it leaves, and where it goes (the item it had before the merge, or a new item minted on approval).
            'prev_id' => $d['prev_sku_id'] === null ? null : (int) $d['prev_sku_id'], 'prev_code' => self::s($d['prev_sku_code']),
            'split_to' => $d['action'] === 'split' ? self::s(Html::json($d['detail'])['split_to'] ?? null) : null,
            'units' => $d['units_per_item'], 'sale' => $d['units_per_item'] === null ? null : Words::saleUses($d['units_per_item']),
            'card' => $card, 'name' => $name,
            'needs' => Html::strings(Html::json($d['needs_second'])), 'reason' => $reason,
            'from_dups' => $reason !== null && str_starts_with($reason, 'Duplicates screen'),
            'decider' => self::s($d['decider']), 'created_at' => self::s($d['created_at']), 'own' => $own, 'stale' => $stale,
            'can_approve' => $lead && !$own, 'can_withdraw' => $own || $lead,
        ];
    }

    // ------------------------------------------------------------------------------------------
    // Decisions
    // ------------------------------------------------------------------------------------------

    public function decide(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $req = $ctx->req;
        $q = $ctx->queries();
        $qc = QueueContext::from($req->post, 'fq', $q->channels());
        $form = [
            'action' => $req->field('action') ?? '',
            'units' => trim($req->field('units_per_item') ?? ''),
            'reason' => trim($req->field('reason') ?? ''),
            'sku_id' => trim($req->field('sku_id') ?? ''),
        ];
        foreach (DecisionService::CARD_FIELDS as $f) {
            $form['card_' . $f] = mb_substr(trim($req->field('card_' . $f) ?? ''), 0, 300);
        }
        $refuse = fn (string $message, ?string $field = null, int $status = 422): HtmlResponse => $this->show($ctx, $id, $qc, $form, $message, $status, $field);

        $action = $form['action'];
        if (!in_array($action, self::ACTIONS, true)) {
            return $refuse(Words::MATCH_ERROR['choose'], 'action');
        }
        $version = UiRequest::id($req->field('expected_map_version')) ?? (($req->field('expected_map_version') ?? '') === '0' ? 0 : null);
        if ($version === null) {
            return $refuse(Words::ERROR['bad_form'], null, 400);
        }
        $request = ['action' => $action, 'listing_id' => $id, 'expected_map_version' => $version];
        $proposalId = UiRequest::id($req->field('proposal_id'));
        if ($proposalId !== null) {
            $request['proposal_id'] = $proposalId;
        }
        if ($form['reason'] !== '') {
            if (mb_strlen($form['reason']) > DecisionService::MAX_REASON) {
                return $refuse(Words::say('MATCH_ERROR', 'reason_long', DecisionService::MAX_REASON), 'reason');
            }
            $request['reason'] = $form['reason'];
        }
        if (in_array($action, ['link', 'reject'], true)) {
            $sku = UiRequest::id($form['sku_id']);
            if ($sku === null) {
                return $refuse($action === 'link' ? Words::MATCH_ERROR['pick_first'] : Words::MATCH_ERROR['nothing_to_reject'], 'sku_id');
            }
            $request['sku_id'] = $sku;
        }
        if ($action === 'ignore' && !isset($request['reason'])) {
            return $refuse(Words::MATCH_ERROR['ignore_why'], 'reason');
        }
        if ($action === 'unlink' && !isset($request['reason'])) {
            // Undoing a match changes a stock link: one at a time, from this page, with a note (M53).
            return $refuse(Words::MATCH_ERROR['unlink_why'], 'reason');
        }
        if (in_array($action, ['link', 'new_item'], true)) {
            if (preg_match('/^[0-9]{1,5}$/D', $form['units']) !== 1 || (int) $form['units'] < 1 || (int) $form['units'] > DecisionService::MAX_UNITS) {
                return $refuse(Words::say('MATCH_ERROR', 'units', DecisionService::MAX_UNITS), 'units_per_item');
            }
            $request['units_per_item'] = (int) $form['units'];
        }
        try {
            if ($action === 'new_item') {
                $request['card'] = $this->cardOverrides($ctx, $id, $form);
            }
            $result = $ctx->decisions()->decide($ctx->caller(), $request);
        } catch (CwException $e) {
            $field = is_string($e->detail['field'] ?? null) ? $e->detail['field'] : null;
            return $refuse(self::plain($e, 'decide'), $field, $e->httpStatus);
        }
        $notice = $result['state'] === 'pending_second' ? 'pending_second' : 'decided_' . $action;
        $to = $qc !== null ? $q->nextInQueue($qc->band, $qc->channelId, $qc->text, $qc->lane, $qc->min, $id) : null;
        if ($qc === null) {
            $sample = UiRequest::id($req->field('sample'));
            return HtmlResponse::redirect(Html::url('/ui/review/listing/' . $id, ['notice' => $notice] + ($sample !== null ? ['sample' => $sample] : [])));
        }
        if ($to === null && $action === 'reject') {
            // A rejected proposal stays open (the listing still needs an item): stay on it, do not say the list is empty.
            return HtmlResponse::redirect(Html::url('/ui/review/listing/' . $id, $qc->query() + ['notice' => $notice]));
        }
        if ($to === null) {
            // The last listing: a decision that waits for a second person must still be said (it is not "done").
            $end = $notice === 'pending_second' ? ['notice' => 'pending_second', 'prev' => $id] : ['notice' => 'queue_done'];
            return HtmlResponse::redirect(Html::url('/ui/review', $qc->pageQuery() + $end));
        }
        return HtmlResponse::redirect(Html::url('/ui/review/listing/' . $to, $qc->query() + ['notice' => $notice, 'prev' => $id]));
    }

    /**
     * Only what the person changed: a field left as the profile gave it is not an override; a field
     * emptied clears it (DecisionService: null clears).
     *
     * @param array<string, string> $form
     * @return array<string, ?string>
     */
    private function cardOverrides(Context $ctx, int $listingId, array $form): array
    {
        $defaults = $ctx->decisions()->card($listingId);
        $out = [];
        foreach (DecisionService::CARD_FIELDS as $f) {
            $v = trim(preg_replace('/\s+/u', ' ', $form['card_' . $f] ?? '') ?? '');
            if ($v === Html::dec($defaults[$f] ?? null)) {
                continue;
            }
            $out[$f] = $v === '' ? null : $v;
        }
        return $out;
    }

    public function approve(Context $ctx): HtmlResponse
    {
        return $this->settle($ctx, true);
    }

    public function withdraw(Context $ctx): HtmlResponse
    {
        return $this->settle($ctx, false);
    }

    private function settle(Context $ctx, bool $approve): HtmlResponse
    {
        $id = $ctx->id();
        $reason = trim($ctx->req->field('reason') ?? '');
        $from = $ctx->req->field('from') === 'listing' ? 'listing' : 'pending';
        try {
            $r = $approve
                ? $ctx->decisions()->approve($ctx->caller(), $id, $reason === '' ? null : $reason)
                : $ctx->decisions()->withdraw($ctx->caller(), $id, $reason === '' ? null : $reason);
        } catch (CwException $e) {
            return $this->pendingPage($ctx, self::plain($e, $approve ? 'approve' : 'withdraw'), $id, $e->httpStatus);
        }
        $notice = $approve ? 'approved' : 'withdrawn';
        if ($from === 'listing') {
            return HtmlResponse::redirect(Html::url('/ui/review/listing/' . (int) $r['listing_id'], ['notice' => $notice]));
        }
        return HtmlResponse::redirect(Html::url('/ui/review', ['queue' => 'pending', 'notice' => $notice, 'prev' => (int) $r['listing_id']]));
    }

    // ------------------------------------------------------------------------------------------

    /**
     * A DecisionService refusal in the page's words, by its error code (F186, F214; DuplicatesController::plain is the model).
     * The service's own message is the API's and stays as it is; a code without words here shows it, with "Nothing was saved".
     *
     * @param 'decide'|'approve'|'withdraw' $where
     */
    public static function plain(CwException $e, string $where): string
    {
        $settle = $where !== 'decide';
        $code = $e->errorCode;
        return match (true) {
            $code === 'lead_required' => Words::MATCH_ERROR[$where === 'decide' ? 'lead_required' : 'lead_required_' . $where],
            $code === 'map_version_conflict', $code === 'proposal_changed' => Words::MATCH_ERROR[$code . ($settle ? '_settle' : '')],
            $code === 'bad_card' => self::badCard(is_string($e->detail['field'] ?? null) ? substr($e->detail['field'], 5) : ''),
            $code === 'bad_units' => Words::say('MATCH_ERROR', 'bad_units', DecisionService::MAX_UNITS),
            in_array($code, ['role_not_allowed', 'staff_required'], true) => Words::MATCH_ERROR['not_allowed'],
            isset(Words::MATCH_ERROR[$code]) && $code !== 'other' => Words::MATCH_ERROR[$code],
            default => Words::say('MATCH_ERROR', 'other', ucfirst(rtrim($e->getMessage(), '.')) . '.'),
        };
    }

    /** A refused field of the new-product details, by its name ("Strength (mg)" must be a number, like 20). */
    private static function badCard(string $field): string
    {
        $label = Words::FIELD[$field] ?? Words::LISTING['new_details'];
        return isset(self::NUMBER_EXAMPLE[$field]) ? Words::say('MATCH_ERROR', 'bad_card_number', $label, self::NUMBER_EXAMPLE[$field])
            : Words::say('MATCH_ERROR', 'bad_card', $label);
    }

    /** The whitelisted notice named in the URL, worded for the website product it is about (F201, F202). */
    private function notice(Context $ctx, ?int $current): ?string
    {
        $key = $ctx->req->param('notice') ?? '';
        if (!isset(self::NOTICES[$key])) {
            return null;
        }
        if ($key === 'queue_done') {
            return Words::MATCH_NOTICE['queue_done'];
        }
        $prev = UiRequest::id($ctx->req->param('prev'));
        $about = $prev ?? $current;
        $name = Words::MATCH_NOTICE['the_product'];
        $code = null;
        if ($about !== null) {
            $l = $ctx->queries()->listing($about);
            if ($l !== null) {
                $name = Words::quoted(trim(((string) $l['product_title']) . ' ' . ((string) $l['variant_title'])), $name);
                if ($l['sku_id'] !== null && in_array($l['status'], DecisionService::LINKED, true)) {
                    $code = $ctx->queries()->skus([(int) $l['sku_id']])[(int) $l['sku_id']]['code'] ?? null;
                }
            }
        }
        $text = $key === 'decided_link'
            ? ($code !== null ? Words::say('MATCH_NOTICE', 'decided_link', $name, (string) $code) : Words::say('MATCH_NOTICE', 'decided_link_plain', $name))
            : Words::say('MATCH_NOTICE', $key, $name);
        if ($prev !== null && $current !== null && $prev !== $current) {
            $text .= ' ' . Words::MATCH_NOTICE['next'];
        }
        return $text;
    }

    /**
     * The new-product details as form fields: the kind of product and the nicotine type as drop-downs of known values (F199;
     * a value the website product gave that is not one of them stays offered), the rest as text.
     *
     * @param array<string, string> $values
     * @return list<array{name: string, label: string, value: string, options: ?array<string, string>}>
     */
    private static function cardFields(array $values): array
    {
        $out = [];
        foreach (DecisionService::CARD_FIELDS as $f) {
            $value = $values['card_' . $f] ?? '';
            $options = null;
            if ($f === 'form' || $f === 'nic_type') {
                $codes = $f === 'form' ? Form::ALL : self::NIC_TYPES;
                $options = ['' => Words::LISTING['not_stated']];
                foreach ($codes as $c) {
                    $options[$c] = Words::of($f === 'form' ? 'FORM_VALUE' : 'NIC_TYPE', $c);
                }
                if ($value !== '' && !isset($options[$value])) {
                    // A website's own value (a kind the matching knows only as a group, such as pod_refill): kept, in words.
                    $options[$value] = Words::of($f === 'form' ? 'FORM_VALUE' : 'NIC_TYPE', mb_substr($value, 0, 60));
                }
            }
            $out[] = ['name' => $f, 'label' => Words::FIELD[$f], 'value' => $value, 'options' => $options];
        }
        return $out;
    }

    /** A compared value as people read it: the kind of product and the nicotine type in words. */
    private static function fieldValue(string $field, string $value): string
    {
        return match (true) {
            $value === '' => '',
            $field === 'form' => Words::has('FORM_VALUE', $value) ? Words::of('FORM_VALUE', $value) : $value,
            $field === 'nic_type' => Words::has('NIC_TYPE', $value) ? Words::of('NIC_TYPE', $value) : $value,
            default => $value,
        };
    }

    /**
     * "Watch out" words of a list's flags (F157): the warnings people act on; codes only the matching uses (ceiling:Check …)
     * are left out (they are in the website product's Technical details).
     *
     * @param list<string> $flags
     * @return list<string>
     */
    private static function flagWords(array $flags): array
    {
        $out = [];
        foreach ($flags as $f) {
            if (Words::has('FLAG', $f)) {
                $out[Words::of('FLAG', $f)] = true;
            } elseif (Words::has('VETO', $f)) {
                $out[ucfirst(Words::of('VETO', $f))] = true;
            }
        }
        return array_map('strval', array_keys($out));
    }

    /**
     * What is wrong with a product the AI looked at (F196): "Cannot be this: different strength", "Price very different".
     *
     * @param list<string> $vetoes
     * @param list<string> $soft
     * @return list<array{text: string, bad: bool}>
     */
    private static function problems(array $vetoes, array $soft): array
    {
        $out = [];
        foreach ($vetoes as $v) {
            $out[] = ['text' => Words::say('LISTING', 'cannot_be', Words::of('VETO', $v)), 'bad' => true];
        }
        foreach ($soft as $f) {
            $out[] = ['text' => Words::of('FLAG', $f), 'bad' => false];
        }
        return $out;
    }

    /**
     * "Why it is here" in words (F174): each reason's parts ("barcode_key+ai_96") by their name (Words::BAND_REASON, a
     * number at the end dropped); parts without a name are left to Technical details.
     *
     * @param list<string> $reasons
     */
    private static function bandReasons(array $reasons): ?string
    {
        $out = [];
        foreach ($reasons as $r) {
            foreach (preg_split('/[+,]\s*/', $r) ?: [] as $part) {
                $part = trim($part);
                $base = (string) preg_replace('/_\d+$/', '', $part);
                if (Words::has('BAND_REASON', $part)) {
                    $out[Words::of('BAND_REASON', $part)] = true;
                } elseif (Words::has('BAND_REASON', $base)) {
                    $out[Words::of('BAND_REASON', $base)] = true;
                }
            }
        }
        return $out === [] ? null : ucfirst(implode('; ', array_keys($out)));
    }

    /** A renamed range in words (F195): 'Electrofag calls it "Crystal Pro Max", Vape and Go calls it "Hayati Pro Max".' */
    private static function renamed(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }
        if (preg_match('/^(.+?) "(.+?)" = (.+?) "(.+?)"$/u', trim($note), $m) === 1) {
            return "{$m[1]} calls it \"{$m[2]}\", {$m[3]} calls it \"{$m[4]}\".";
        }
        return $note;
    }

    /**
     * Where "Back" goes when the page was not opened from a list or a spot check (F217): the page the person came from when it
     * is one of these screens (the Referer, same origin, a known path only), else Home.
     *
     * @return array{0: string, 1: string}
     */
    private static function back(UiRequest $req): array
    {
        $ref = $req->header('referer');
        if ($ref !== null) {
            $path = parse_url($ref, PHP_URL_PATH);
            $host = parse_url($ref, PHP_URL_HOST);
            $here = $req->header('host');
            $query = [];
            parse_str((string) parse_url($ref, PHP_URL_QUERY), $query);
            if (is_string($path) && ($host === null || $here === null || strcasecmp((string) $host, (string) preg_replace('/:\d+$/', '', $here)) === 0)) {
                if ($path === '/ui/search') {
                    return [Html::url('/ui/search', ['q' => is_string($query['q'] ?? null) ? mb_substr($query['q'], 0, 100) : null]), Words::title('search')];
                }
                if (preg_match('#^/ui/items/(\d{1,10})$#D', $path, $m) === 1) {
                    return ['/ui/items/' . $m[1], Words::LISTING['back']];
                }
                if (preg_match('#^/ui/review/duplicates(/\d{1,10})?$#D', $path, $m) === 1) {
                    return [$path, Words::LISTING['back']];
                }
            }
        }
        return ['/ui/', Words::MENU['home']];
    }

    /** @param list<array{id: int, code: string, name: string}> $channels @return array<string, string> code => name */
    private static function channelNames(array $channels): array
    {
        $out = [];
        foreach ($channels as $c) {
            $out[(string) $c['code']] = (string) $c['name'];
        }
        return $out;
    }

    /** @param array<string, mixed> $r a sku row @return array<string, mixed> */
    private function skuView(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'code' => self::s($r['code']), 'name' => self::s($r['name']), 'brand' => self::s($r['brand']),
            'policy' => self::s($r['sell_policy']), 'strength_mg' => self::s($r['strength_mg']), 'nic_type' => self::s($r['nic_type']),
            'line' => self::s($r['line']), 'form' => self::s($r['form']), 'flavour' => self::s($r['flavour']), 'volume_ml' => self::s($r['volume_ml']),
            'puffs' => self::s($r['puffs']), 'pack_units' => self::s($r['pack_units']),
            'merged' => ($r['merged_into_sku_id'] ?? null) !== null,
            // The first-match run (proposals.csv) names an item minted from a Vape and Go listing CWP-<its variant id>.
            'cwp' => isset($r['vpg_variant_id']) && $r['vpg_variant_id'] !== null ? 'CWP-' . $r['vpg_variant_id'] : null,
            'details' => implode(' · ', array_filter([self::s($r['brand']), $r['strength_mg'] === null ? null : Html::dec($r['strength_mg']) . 'mg', self::s($r['line']),
                self::s($r['flavour']), $r['volume_ml'] === null ? null : Html::dec($r['volume_ml']) . 'ml'], static fn (?string $v): bool => $v !== null && $v !== '')),
        ];
    }

    /** A value from a database row, a JSON document or a site, as a short plain string (never an array). */
    private static function s(mixed $v, int $max = 500): ?string
    {
        if ($v === null || $v === '' || !(is_string($v) || is_int($v) || is_float($v))) {
            return null;
        }
        return mb_substr((string) $v, 0, $max);
    }
}
