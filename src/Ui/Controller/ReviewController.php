<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Mapping\DecisionService;
use CW\Mapping\Proposals;
use CW\Ui\Compare;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\QueueContext;
use CW\Ui\Queries;
use CW\Ui\UiRequest;

/**
 * The review screens (plan §7.1): the queues, one listing at a time next to the item CW proposes,
 * and the four decisions (link, new item, ignore, reject) plus the second person's approve /
 * withdraw. Every change goes through DecisionService: this class only reads the form, calls it
 * and shows what it answered (its refusals are shown on the same page, with the form kept).
 */
final class ReviewController
{
    /** notice key => text (%s is "listing #N" or "the listing"). Only these can be shown: a notice never comes from the URL as text. */
    public const NOTICES = [
        'decided_link' => 'Linked %s.',
        'decided_new_item' => 'A new item was created for %s and linked.',
        'decided_ignore' => 'Marked %s as ignored.',
        'decided_reject' => 'Rejected the proposal for %s. It stays in the queue for another choice.',
        'pending_second' => 'Saved for %s. A second person (mapping lead) has to approve it before it takes effect.',
        'approved' => 'Approved: the decision on %s is now in effect.',
        'withdrawn' => 'Withdrawn: the decision on %s was cancelled.',
        'queue_done' => 'Nothing left in this queue.',
    ];
    public const ACTIONS = ['link', 'new_item', 'ignore', 'reject'];
    private const CARD_LABELS = ['name' => 'Name', 'brand' => 'Brand', 'strength_mg' => 'Strength (mg)', 'nic_type' => 'Nicotine type', 'line' => 'Range / line',
        'form' => 'Form', 'flavour' => 'Flavour', 'volume_ml' => 'Volume (ml)', 'puffs' => 'Puffs', 'pack_units' => 'Pack units'];

    // ------------------------------------------------------------------------------------------
    // Queues
    // ------------------------------------------------------------------------------------------

    public function queue(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $band = $req->param('queue');
        if ($band === 'pending') {
            return $this->pendingPage($ctx, null, null, 200);
        }
        if ($band === null) {
            return HtmlResponse::redirect('/ui/');
        }
        $q = $ctx->queries();
        $channels = $q->channels();
        $qc = QueueContext::from($req->query, 'q', $channels);
        if ($qc === null) {
            return $ctx->error(404, 'unknown_queue', 'there is no such queue');
        }
        $page = UiRequest::id($req->param('page')) ?? 1;
        $result = $q->queue($qc->band, $qc->channelId, $qc->text, $qc->lane, $qc->min, $page);
        $rows = [];
        foreach ($result['rows'] as $r) {
            $rows[] = [
                'listing_id' => (int) $r['listing_id'],
                'channel' => self::s($r['channel_code']),
                'variant' => self::s($r['external_variant_id']),
                'title' => self::s($r['product_title']),
                'variant_title' => self::s($r['variant_title']),
                'brand' => self::s($r['brand']),
                'units_30d' => $r['units_30d'],
                'units_365d' => $r['units_365d'],
                'lane' => self::s($r['lane']),
                // A confidence next to "Proposal: none" reads as a contradiction: it is the AI's certainty about
                // its own answer (e.g. "cannot tell"), not a match; shown only when there is a proposal.
                'confidence' => $r['proposed_sku_id'] !== null || (bool) $r['proposed_new_item'] ? $r['ai_confidence'] : null,
                'ai_outcome' => self::s($r['ai_outcome']),
                'sku_code' => self::s($r['sku_code']),
                'sku_name' => self::s($r['sku_name']),
                'new_item' => (bool) $r['proposed_new_item'],
                'flags' => Html::strings(Html::json($r['flags'])),
                'status' => self::s($r['status']),
                'link' => Html::url('/ui/review/listing/' . (int) $r['listing_id'], $qc->query()),
            ];
        }
        $bandCounts = $q->bandCounts();
        $total = 0;
        foreach ($bandCounts[$qc->band] ?? [] as $n) {
            $total += $n;
        }
        return $ctx->page('queue', [
            'qc' => $qc,
            'channels' => $channels,
            'lanes' => Queries::LANES,
            'bands' => array_map(static fn (string $b): array => ['band' => $b, 'label' => Queries::bandLabel($b)], Proposals::BANDS),
            'band_label' => Queries::bandLabel($qc->band),
            'rows' => $rows,
            'total' => $result['total'],
            'page_no' => $result['page'],
            'pages' => $result['pages'],
            'per_page' => Queries::PER_PAGE,
            'band_total' => $total,
            'prev_link' => $result['page'] > 1 ? Html::url('/ui/review', $qc->pageQuery() + ['page' => $result['page'] - 1]) : null,
            'next_link' => $result['page'] < $result['pages'] ? Html::url('/ui/review', $qc->pageQuery() + ['page' => $result['page'] + 1]) : null,
        ], 200, ['title' => Queries::bandLabel($qc->band) . ' queue', 'active' => 'review', 'notice' => $this->notice($req, null)]);
    }

    /**
     * @param string|null $error a refusal of approve / withdraw to show above the list
     */
    private function pendingPage(Context $ctx, ?string $error, ?int $errorDecision, int $status): HtmlResponse
    {
        $me = $ctx->me();
        $rows = [];
        foreach ($ctx->queries()->pendingDecisions() as $d) {
            $rows[] = [
                'listing_id' => (int) $d['listing_id'],
                'channel' => self::s($d['channel_code']),
                'variant' => self::s($d['external_variant_id']),
                'title' => self::s($d['product_title']),
                'variant_title' => self::s($d['variant_title']),
            ] + $this->pendingView($d, $me->id, $me->isLead());
        }
        return $ctx->page('pending', [
            'rows' => $rows,
            'error' => $error,
            'error_decision' => $errorDecision,
        ], $status, ['title' => 'Waiting for a second person', 'active' => 'pending', 'notice' => $this->notice($ctx->req, null)]);
    }

    // ------------------------------------------------------------------------------------------
    // One listing
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
            return $ctx->error(404, 'not_found', 'no such listing');
        }
        $me = $ctx->me();
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

        // The item on the right. While a decision waits for a second person it is the item THAT decision
        // links to (the approver approves what the page compares; ?pick is ignored); otherwise a picked
        // one, else the one the refused form was sent for, else the proposal's.
        $pendingTarget = $pending !== null && $pending['sku_id'] !== null && in_array($pending['action'], ['link', 'reject', 'merge_skus'], true)
            ? (int) $pending['sku_id'] : null;
        $pick = $pending !== null ? null : (UiRequest::id($req->param('pick')) ?? ($form !== null ? UiRequest::id($form['sku_id'] ?? null) : null));
        $targetId = $pending !== null ? $pendingTarget : ($pick ?? $proposedId);
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
        $wanted = [$targetId, $proposedId, $proposal['closest_sku_id'] ?? null, is_array($ev['lane_target'] ?? null) ? ($ev['lane_target']['sku_id'] ?? null) : null, $chosenId];
        foreach ([...$candidates, ...$partners] as $c) {
            $wanted[] = $c['sku_id'] ?? null;
        }
        $skus = $q->skus(array_values(array_filter(array_map(static fn (mixed $v): int => is_int($v) ? $v : 0, $wanted))));

        $target = $targetId !== null ? ($skus[$targetId] ?? null) : null;
        $pickNote = null;
        if ($pick !== null && ($target === null || $target['merged_into_sku_id'] !== null)) {
            $pickNote = $target === null ? 'That item does not exist.' : 'That item was merged into another one; search for the item it was merged into.';
            $targetId = $proposedId;
            $target = $targetId !== null ? ($skus[$targetId] ?? null) : null;
        }
        $aliases = $q->brandAliases();
        $targetBarcodes = $target !== null ? ($q->barcodesOfMany([(int) $target['id']])[(int) $target['id']] ?? []) : [];
        $compare = $target !== null ? Compare::rows($card, $target, $listingBarcodes, $targetBarcodes, $aliases) : [];

        // Where else this listing's barcodes are (another listing, e.g. a binned Vape and Go variant never
        // minted, or an item): what a "new item" could duplicate. The target and its listings are left out.
        $elsewhere = ['items' => [], 'listings' => []];
        if ($listingBarcodes !== []) {
            $where = $q->barcodeElsewhere($id, $listingBarcodes);
            foreach ($where['items'] as $r) {
                if ($target === null || (int) $r['id'] !== (int) $target['id']) {
                    $elsewhere['items'][] = ['id' => (int) $r['id'], 'code' => self::s($r['code']), 'name' => self::s($r['name']), 'barcode' => self::s($r['barcode'])];
                }
            }
            foreach ($where['listings'] as $r) {
                if ($target === null || $r['sku_code'] === null || $r['sku_code'] !== $target['code']) {
                    $elsewhere['listings'][] = ['id' => (int) $r['id'], 'channel' => self::s($r['channel_code']), 'variant' => self::s($r['external_variant_id']),
                        'status' => self::s($r['status']), 'site_status' => self::s($r['variant_status']), 'sku_code' => self::s($r['sku_code']),
                        'title' => self::s(trim(((string) $r['product_title']) . ' ' . ((string) $r['variant_title']))), 'units_365d' => $r['units_365d']];
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
        // Preselection (plan §7.1, U8): Key -> confirm the proposed item; New item -> create one, unless its
        // barcode is already on another listing or item (then a person looks first).
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
        $qq = $qc !== null ? $qc->query() : [];
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
                'role' => self::s($c['role'] ?? null),
                'prescore' => self::s($c['prescore'] ?? null),
                'vetoes' => Html::strings($c['vetoes'] ?? null),
                'soft' => Html::strings($c['soft_flags'] ?? null),
                'ai_picked' => $sid !== null && $sid === $chosenId,
                'proposed' => $sid !== null && $sid === $proposedId,
                'pick' => $usable($sid) ? $pickUrl((int) $sid) : null,
            ];
        }
        if ($chosenId !== null && !$chosenListed) {
            // The judge's pick is always a row, even when the candidate list does not carry it.
            array_unshift($candView, ['ref' => null, 'sku' => isset($skus[$chosenId]) ? $this->skuView($skus[$chosenId]) : null,
                'cw_id' => self::s($chosen['cw_id'] ?? null), 'role' => null, 'prescore' => null,
                'vetoes' => Html::strings($ai['vetoes_on_chosen'] ?? null), 'soft' => Html::strings($ai['soft_flags_on_chosen'] ?? null),
                'ai_picked' => true, 'proposed' => $chosenId === $proposedId, 'pick' => $usable($chosenId) ? $pickUrl($chosenId) : null]);
        }
        $closestId = $proposal !== null && $proposal['closest_sku_id'] !== null ? (int) $proposal['closest_sku_id'] : null;
        $lead = $me->isLead();
        $noForm = null;
        if (!$me->canDecide()) {
            $noForm = $me->rolesPhrase() . ' can look at listings but not decide them.';
        } elseif ($band === 'Conflict' && !$lead) {
            $noForm = 'A Conflict proposal can only be decided by a mapping lead.';
        } elseif ($pending !== null) {
            $noForm = 'A decision on this listing is waiting for a second person. Approve or withdraw it first.';
        }

        // What the run says, shaped for reading: which fields disagree and how (conflicts first), and the
        // flags grouped by weight (a veto blocks a link; a soft flag asks for a check).
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
        $blocks = [];
        $checks = [];
        foreach ($targetVetoes as $f) {
            $blocks[] = ['flag' => $f, 'on' => 'proposed item'];
        }
        foreach (Html::strings($ai['vetoes_on_chosen'] ?? null) as $f) {
            $blocks[] = ['flag' => $f, 'on' => "AI's pick"];
        }
        foreach ($targetSoft as $f) {
            $checks[] = ['flag' => $f, 'on' => 'proposed item'];
        }
        foreach (Html::strings($ai['soft_flags_on_chosen'] ?? null) as $f) {
            $checks[] = ['flag' => $f, 'on' => "AI's pick"];
        }
        $otherFlags = $proposal === null ? [] : array_values(array_diff(Html::strings(Html::json($proposal['flags'])), $targetVetoes, $targetSoft));

        return $ctx->page('listing', [
            'l' => [
                'id' => (int) $l['id'], 'channel' => self::s($l['channel_code']), 'channel_name' => self::s($l['channel_name']),
                'variant' => self::s($l['external_variant_id']), 'status' => self::s($l['status']), 'title' => self::s($l['product_title']),
                'variant_title' => self::s($l['variant_title']), 'brand' => self::s($l['brand']), 'price' => self::s($l['price']),
                'url' => Html::safeUrl(is_string($l['perma_link']) ? $l['perma_link'] : null), 'units_30d' => $l['units_30d'],
                'units_365d' => $l['units_365d'], 'units_per_item' => (int) $l['units_per_item'], 'map_version' => (int) $l['map_version'],
                'sku_id' => $l['sku_id'] === null ? null : (int) $l['sku_id'],
            ],
            'attributes' => $attributes,
            'listing_barcodes' => $listingBarcodes,
            'card_rows' => Compare::rows($card, $target ?? [], $listingBarcodes, [], $aliases),
            'compare' => $compare,
            'labels' => self::CARD_LABELS,
            'target' => $target !== null ? $this->skuView($target) : null,
            'target_barcodes' => $targetBarcodes,
            'target_heading' => $pending !== null ? 'Item this decision links to' : ($pick !== null && $pickNote === null && $target !== null ? 'Item you picked' : 'Proposed item'),
            'pick_note' => $pickNote,
            'elsewhere' => $elsewhere,
            'linked_sku' => $l['sku_id'] !== null ? ($q->skus([(int) $l['sku_id']])[(int) $l['sku_id']] ?? null) : null,
            'proposal' => $proposal === null ? null : [
                'id' => (int) $proposal['id'], 'band' => $band, 'band_label' => Queries::bandLabel((string) $band),
                'run_band' => is_string($ev['band'] ?? null) && $ev['band'] !== $band ? mb_substr($ev['band'], 0, 60) : null,
                'lane' => self::s($proposal['lane']), 'run' => self::s($proposal['run_id']),
                'new_item' => (bool) $proposal['proposed_new_item'], 'created_at' => self::s($proposal['created_at']),
                'blocks' => $blocks, 'checks' => $checks, 'other_flags' => $otherFlags,
                'band_reasons' => Html::strings($ev['band_reasons'] ?? null),
                'lane_flags' => Html::strings($ev['lane_flags'] ?? null),
                'relabel' => self::s($ev['relabel_pending'] ?? null),
                'ai' => [
                    'outcome' => self::s($proposal['ai_outcome']), 'confidence' => $proposal['ai_confidence'], 'units' => $proposal['ai_units_per_item'],
                    'model' => self::s($proposal['ai_model']), 'reason' => self::s($ai['reason'] ?? null, 1500),
                    'fields' => $fields, 'warnings' => Html::strings($ai['warnings'] ?? null),
                    'chosen' => $chosen === null ? null : [
                        'sku' => $chosenId !== null && isset($skus[$chosenId]) ? $this->skuView($skus[$chosenId]) : null,
                        'cw_id' => self::s($chosen['cw_id'] ?? null), 'title' => self::s($chosen['title'] ?? null),
                        'pick' => $usable($chosenId) ? $pickUrl((int) $chosenId) : null, 'is_target' => $chosenId !== null && $chosenId === $targetId,
                    ],
                ],
                'proposed_sku_id' => $proposedId,
            ],
            'partners' => array_map(fn (array $p): array => [
                'sku' => is_int($p['sku_id'] ?? null) && isset($skus[$p['sku_id']]) ? $this->skuView($skus[$p['sku_id']]) : null,
                'cw_id' => self::s($p['cw_id'] ?? null), 'title' => self::s($p['title'] ?? null), 'note' => self::s($p['note'] ?? null),
                'pick' => is_int($p['sku_id'] ?? null) && $usable($p['sku_id']) ? $pickUrl($p['sku_id']) : null,
            ], $partners),
            'closest' => $closestId !== null && isset($skus[$closestId]) ? $this->skuView($skus[$closestId]) : null,
            'closest_pick' => $usable($closestId) ? $pickUrl((int) $closestId) : null,
            'candidates' => $candView,
            'search_text' => $searchText,
            'found' => $found,
            'qc' => $qc,
            'qq' => $qq,
            'queue_link' => $qc !== null ? Html::url('/ui/review', $qc->pageQuery()) : null,
            'queue_label' => $qc !== null ? Queries::bandLabel($qc->band) : null,
            'next_link' => $next !== null ? Html::url('/ui/review/listing/' . $next, $qq) : null,
            'pending' => $pending === null ? null : $this->pendingView($pending, $me->id, $lead),
            'decisions' => array_map(static fn (array $d): array => [
                'id' => (int) $d['id'], 'action' => self::s($d['action']), 'state' => self::s($d['state']), 'sku_code' => self::s($d['sku_code']),
                'units' => $d['units_per_item'], 'reason' => self::s($d['reason']), 'decider' => self::s($d['decider']), 'created_at' => self::s($d['created_at']),
            ], $q->decisionsOf($id)),
            'form' => $values,
            'no_form' => $noForm,
            'preselect' => $preselect,
            'quick_link' => $preselect === 'link' && $noForm === null && $form === null,
            'protected' => $protected,
            'previously_rejected' => $target !== null && in_array((int) $target['id'], $rejectedIds, true),
            'error' => $error,
            'error_field' => $errorField,
            'card_fields' => DecisionService::CARD_FIELDS,
            'max_units' => DecisionService::MAX_UNITS,
            'max_reason' => DecisionService::MAX_REASON,
            'can_link' => $target !== null,
            'is_lead' => $lead,
        ], $status, ['title' => 'Listing #' . $id, 'active' => 'review', 'notice' => $this->notice($req, $id)]);
    }

    /**
     * A waiting decision as the approver must see it: what it does (for new_item the identity card it will
     * mint, field by field), why it waits, who decided it, and whether it went stale (the listing changed,
     * its identity included, or another proposal opened since: approving is then refused).
     *
     * @param array<string, mixed> $d a Queries::pendingOf / pendingDecisions row
     * @return array<string, mixed>
     */
    private function pendingView(array $d, int $me, bool $lead): array
    {
        $own = (int) $d['decided_by'] === $me;
        $card = [];
        if ($d['action'] === 'new_item') {
            $c = Html::json($d['detail'])['card'] ?? [];
            foreach (DecisionService::CARD_FIELDS as $f) {
                $v = is_array($c) ? ($c[$f] ?? null) : null;
                if (is_string($v) || is_int($v) || is_float($v)) {
                    $card[] = ['label' => self::CARD_LABELS[$f], 'value' => Html::dec($v)];
                }
            }
        }
        $stale = [];
        if ((int) $d['map_version'] !== (int) $d['expected_map_version']) {
            $stale[] = 'The listing changed since this was decided (its link, or the site changed its titles, brand or barcodes).';
        }
        if (($d['open_proposal_id'] === null ? null : (int) $d['open_proposal_id']) !== ($d['proposal_id'] === null ? null : (int) $d['proposal_id'])) {
            $stale[] = 'The listing has another proposal since this was decided.';
        }
        return [
            'id' => (int) $d['id'], 'action' => self::s($d['action']),
            'sku_id' => $d['sku_id'] === null ? null : (int) $d['sku_id'], 'sku_code' => self::s($d['sku_code']), 'sku_name' => self::s($d['sku_name']),
            'merge_from_id' => $d['merge_from_sku_id'] === null ? null : (int) $d['merge_from_sku_id'],
            'merge_from_code' => self::s($d['merge_from_code']), 'merge_from_name' => self::s($d['merge_from_name']),
            'units' => $d['units_per_item'], 'card' => $card,
            'needs' => Html::strings(Html::json($d['needs_second'])), 'reason' => self::s($d['reason']), 'decider' => self::s($d['decider']),
            'created_at' => self::s($d['created_at']), 'own' => $own, 'stale' => $stale,
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
            return $refuse('Choose what to do with this listing.', 'action');
        }
        $version = UiRequest::id($req->field('expected_map_version')) ?? (($req->field('expected_map_version') ?? '') === '0' ? 0 : null);
        if ($version === null) {
            return $refuse('The form is incomplete; reload the page.', null, 400);
        }
        $request = ['action' => $action, 'listing_id' => $id, 'expected_map_version' => $version];
        $proposalId = UiRequest::id($req->field('proposal_id'));
        if ($proposalId !== null) {
            $request['proposal_id'] = $proposalId;
        }
        if ($form['reason'] !== '') {
            if (mb_strlen($form['reason']) > DecisionService::MAX_REASON) {
                return $refuse('The reason is at most ' . DecisionService::MAX_REASON . ' characters.', 'reason');
            }
            $request['reason'] = $form['reason'];
        }
        if (in_array($action, ['link', 'reject'], true)) {
            $sku = UiRequest::id($form['sku_id']);
            if ($sku === null) {
                return $refuse($action === 'link' ? 'Pick the item to link to first (search below, or use one of the candidates).' : 'There is no proposed item to reject.', 'sku_id');
            }
            $request['sku_id'] = $sku;
        }
        if ($action === 'ignore' && !isset($request['reason'])) {
            return $refuse('Say why this listing is ignored (a few words are enough).', 'reason');
        }
        if (in_array($action, ['link', 'new_item'], true)) {
            if (preg_match('/^[0-9]{1,5}$/D', $form['units']) !== 1 || (int) $form['units'] < 1 || (int) $form['units'] > DecisionService::MAX_UNITS) {
                return $refuse('Units per item is a whole number from 1 to ' . DecisionService::MAX_UNITS . '.', 'units_per_item');
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
            return $refuse($e->getMessage(), $field, $e->httpStatus);
        }
        $notice = $result['state'] === 'pending_second' ? 'pending_second' : 'decided_' . $action;
        $to = $qc !== null ? $q->nextInQueue($qc->band, $qc->channelId, $qc->text, $qc->lane, $qc->min, $id) : null;
        if ($qc === null) {
            return HtmlResponse::redirect(Html::url('/ui/review/listing/' . $id, ['notice' => $notice]));
        }
        if ($to === null && $action === 'reject') {
            // A rejected proposal stays open (the listing still needs an item): stay on it, do not say the queue is empty.
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
            return $this->pendingPage($ctx, $e->getMessage(), $id, $e->httpStatus);
        }
        $notice = $approve ? 'approved' : 'withdrawn';
        if ($from === 'listing') {
            return HtmlResponse::redirect(Html::url('/ui/review/listing/' . (int) $r['listing_id'], ['notice' => $notice]));
        }
        return HtmlResponse::redirect(Html::url('/ui/review', ['queue' => 'pending', 'notice' => $notice, 'prev' => (int) $r['listing_id']]));
    }

    // ------------------------------------------------------------------------------------------

    /** The whitelisted notice named in the URL, worded for the listing it is about. */
    private function notice(UiRequest $req, ?int $current): ?string
    {
        $text = self::NOTICES[$req->param('notice') ?? ''] ?? null;
        if ($text === null) {
            return null;
        }
        $prev = UiRequest::id($req->param('prev')) ?? $current;
        return sprintf($text, $prev !== null ? 'listing #' . $prev : 'the listing');
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
