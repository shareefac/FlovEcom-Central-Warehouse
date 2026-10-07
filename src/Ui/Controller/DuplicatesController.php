<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Mapping\DecisionService;
use CW\OpResult;
use CW\Ui\Context;
use CW\Ui\Duplicates;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;

/**
 * The Duplicates screen (docs/decisions.md M34; the owner's decision of 6 Oct 2026): Vape and Go's own duplicate listings,
 * the same product on two pages, linked to ONE CW item so their sales and deliveries count once. Nothing changes on the
 * site: the pages keep their own price and reviews until Vape and Go runs on CW.
 *
 *   GET  /ui/review/duplicates             the open merge suggestions, by group, the biggest sellers first (linking.view)
 *   GET  /ui/review/duplicates/{id}        one group side by side, differences highlighted, a suggested keeper (?keeper=)
 *   POST /ui/review/duplicates/{id}/decide "Same product - merge into CW-x" / "Different products - keep separate", or one
 *                                          choice per listing (3 or more): one DecisionService::decideGroup() (mapping lead)
 *   POST /ui/review/duplicates/{id}/split  the undo of a wrong merge for one listing (DecisionService split; mapping lead)
 *
 * Both POSTs carry a FormOnce key (a double click decides once) and the map_version of every listing the form showed: a
 * listing that changed meanwhile refuses the whole form, and the page is drawn again with the reason in plain words.
 */
final class DuplicatesController
{
    public const NOTICES = [
        'merged' => 'Merged. Both pages now share one warehouse item. On the website they stay separate pages with their own price and reviews until Vape and Go switches to the warehouse system.',
        'separate' => 'Kept separate: these listings will not be suggested as duplicates again.',
        'mixed' => 'Saved: the listings you marked the same now share one warehouse item, the others stay separate.',
        'pending' => 'Saved. A merge that touches a counted item (or a pack of more than one) waits for a second mapping lead in Second approval.',
        'split' => 'Split off: the listing is back on its own warehouse item, with the stock that came with it.',
        'split_pending' => 'Saved. The split touches a counted item, so a second mapping lead has to approve it in Second approval.',
        'done' => 'No duplicates are left to decide.',
    ];
    public const CHOICES = ['merge', 'separate', 'later'];
    private const LIST_LIMIT = 500;
    /** Decided groups per page of "Decided recently" (M44: paged, so every decided group, and its undo, can be found). */
    public const RECENT_PER_PAGE = 25;

    public function index(Context $ctx): HtmlResponse
    {
        $dup = new Duplicates($ctx->db);
        $rows = [];
        foreach (array_slice($dup->openGroups(), 0, self::LIST_LIMIT) as $g) {
            $k = $g['keeper'];
            $rows[] = ['id' => $g['id'], 'size' => count($g['listings']), 'open' => $g['open'], 'decided' => $g['decided'], 'kind' => $g['kind'],
                'units_30d' => $g['units_30d'], 'units_365d' => $g['units_365d'], 'differs' => $g['differs'], 'against' => $g['against'], 'checked' => $g['checked'],
                'waiting' => $g['waiting'], 'title' => $k === null ? null : trim(($k['title'] ?? '') . ' ' . ($k['variant_title'] ?? '')), 'sku_code' => $k['sku']['code'] ?? null];
        }
        $page = max(1, min(10000, (int) (UiRequest::id($ctx->req->param('page')) ?? 1)));
        $recent = $dup->decidedGroups(($page - 1) * self::RECENT_PER_PAGE, self::RECENT_PER_PAGE);
        $pages = max(1, (int) ceil($recent['total'] / self::RECENT_PER_PAGE));
        return $ctx->page('duplicates', ['rows' => $rows, 'total' => count($rows), 'recent' => $recent['rows'], 'recent_total' => $recent['total'],
            'recent_page' => $page, 'recent_prev' => $page > 1 ? Html::url('/ui/review/duplicates', ['page' => (string) ($page - 1)]) : null,
            'recent_next' => $page < $pages ? Html::url('/ui/review/duplicates', ['page' => (string) ($page + 1)]) : null, 'recent_pages' => $pages], 200,
            ['title' => 'Duplicates', 'active' => 'duplicates', 'notice' => self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null]);
    }

    public function show(Context $ctx): HtmlResponse
    {
        return $this->page($ctx, $ctx->id(), null, null, 200);
    }

    /**
     * @param array<string, mixed>|null $form the refused form's values (keeper, choices)
     */
    private function page(Context $ctx, int $id, ?array $form, ?string $error, int $status): HtmlResponse
    {
        $dup = new Duplicates($ctx->db);
        $g = $dup->group($id);
        if ($g === null) {
            return $ctx->error(404, 'not_found', 'no such duplicate group');
        }
        if ($g['id'] !== $id) {
            return HtmlResponse::redirect('/ui/review/duplicates/' . $g['id']);
        }
        $ds = $ctx->decisions();
        $ls = $dup->listings($g['listings']);
        $ordered = array_values(array_filter(array_map(static fn (int $lid): ?array => $ls[$lid] ?? null, $g['listings'])));
        $merges = [];
        foreach ($ordered as $l) {
            $merges[$l['id']] = $l['sku'] !== null ? $ds->mergeOpening($l['id']) : null;
        }
        $own = array_map(static fn (array $p): int => $p['id'], $g['proposals']);
        // The keeper suggested: among the listings that were not moved onto their item by a merge (after a merge, the kept one).
        $suggested = Duplicates::suggestKeeper(array_values(array_filter($ordered, static fn (array $l): bool => $merges[$l['id']] === null)))
            ?? Duplicates::suggestKeeper($ordered);
        $asked = $form !== null ? UiRequest::id((string) ($form['keeper'] ?? '')) : UiRequest::id($ctx->req->param('keeper'));
        $usable = static fn (?int $lid): bool => $lid !== null && isset($ls[$lid]) && $ls[$lid]['sku'] !== null && $ls[$lid]['sku']['merged_into'] === null;
        $keeper = $usable($asked) ? $ls[$asked] : $suggested;
        $state = function (array $l, ?array $keeper) use ($ds, $own): string {
            $keepSku = $keeper['sku']['id'] ?? null;
            return match (true) {
                $keeper !== null && $l['id'] === $keeper['id'] => 'keeper',
                $l['sku'] === null => 'not_linked',
                $keepSku !== null && $ds->rootOf($l['sku']['id']) === $keepSku => 'same',
                $l['pending'] !== null => 'waiting',
                // M41: a page whose open suggestion belongs to another group (or another queue) is decided there, never here.
                ($l['proposal'] !== null && !in_array($l['proposal']['id'], $own, true)) || $l['other_proposal'] !== null => 'elsewhere',
                $keepSku === null => 'not_linked',
                $ds->duplicateBlocked($l['id'], $keepSku) === 'rejected_before' => 'separate',
                default => 'open',
            };
        };
        $openFor = static fn (?array $k): array => array_values(array_filter($ordered, static fn (array $l): bool => $state($l, $k) === 'open'));
        $waiting = array_filter($ordered, static fn (array $l): bool => $l['pending'] !== null) !== [];
        if ($asked === null && $g['open'] > 0 && !$waiting && $openFor($keeper) === []) {
            // M41: nothing is decidable against the suggested keeper while a suggestion is still open (and nothing waits for a
            // second person): keep the item an open suggestion proposes instead (the person can still pick another).
            foreach ($g['proposals'] as $p) {
                if ($p['status'] !== 'open' || $p['proposed_sku_id'] === null) {
                    continue;
                }
                $root = $ds->rootOf($p['proposed_sku_id']);
                foreach ($ordered as $l) {
                    if ($usable($l['id']) && $l['sku']['id'] === $root && $openFor($l) !== []) {
                        $keeper = $l;
                        break 2;
                    }
                }
            }
        }
        $sweep = $dup->sweepFeatures($ordered);
        $verdicts = $keeper === null ? [] : $dup->verdicts($ordered, $keeper['id']);
        $me = $ctx->me();
        $rows = [];
        foreach ($ordered as $l) {
            $merge = $merges[$l['id']];
            $former = $merge === null ? null : $ctx->queries()->sku($merge['from']);
            $preview = $merge === null ? null : $ds->splitPreview($l['id']);
            $rows[] = $l + [
                'state' => $state($l, $keeper),
                'is_suggested' => $suggested !== null && $l['id'] === $suggested['id'],
                'keeper_link' => $l['sku'] !== null && $l['sku']['merged_into'] === null && ($keeper === null || $l['id'] !== $keeper['id'])
                    ? Html::url('/ui/review/duplicates/' . $g['id'], ['keeper' => $l['id']]) : null,
                'choice' => $form !== null ? (string) ($form['c'][$l['id']] ?? '') : '',
                'verdict' => $verdicts[$l['id']] ?? null,
                'short' => self::short($l),
                // Moved onto its item by a merge: the undo (split) back to the item it had (the merge undone, M40), or to a new item;
                // where it goes, with which pages and how many units is shown before the button.
                'undo' => $merge === null || $former === null || $preview === null ? null : ['merge_id' => $merge['id'], 'from_code' => (string) $former['code'],
                    'former_ok' => $preview['former_ok'], 'chain' => $preview['chain'], 'with' => $preview['with'], 'former_units' => $preview['former_units'],
                    'new_units' => $preview['new_units'], 'new_exact' => $preview['new_exact'], 'by_warehouse' => $preview['by_warehouse']],
                'undo_key' => FormOnce::newKey(),
            ];
        }
        $open = array_values(array_filter($rows, static fn (array $r): bool => $r['state'] === 'open'));
        // What the rules say against merging the open pages into the kept one, and the stock a merge of all of them would move.
        $against = array_values(array_filter($open, static fn (array $r): bool => $r['verdict'] !== null && (!$r['verdict']['ok'] || !$r['verdict']['checked'])));
        $moves = [];
        foreach ($open as $r) {
            $moves[$r['sku']['id']] = $r['sku']['available'];
        }
        $noForm = null;
        if (!$me->isLead()) {
            $noForm = $me->rolesPhrase() . ' can look at duplicates but not decide them: merging and keeping separate is for a mapping lead.';
        }
        $merged = array_values(array_filter($rows, static fn (array $r): bool => $r['undo'] !== null));
        return $ctx->page('duplicate_group', [
            'g' => $g,
            'rows' => $rows,
            'compare' => Duplicates::compare($ordered, $sweep),
            'keeper' => $keeper,
            'suggested' => $suggested,
            'open_rows' => $open,
            'against' => $against,
            'merge_units' => array_sum($moves),
            'merge_items' => count($moves),
            'merged_rows' => $merged,
            'no_form' => $noForm,
            'can_decide' => $noForm === null && $keeper !== null && $open !== [],
            'can_split' => $me->isLead(),
            'form_key' => $form !== null && is_string($form['form_key'] ?? null) ? $form['form_key'] : FormOnce::newKey(),
            'confirmed' => $form !== null && ($form['confirm'] ?? '') === '1',
            'error' => $error,
            'note' => $merged !== [] || array_filter($rows, static fn (array $r): bool => $r['state'] === 'same') !== [],
            'next_link' => $this->next($dup, $g['id']),
        ], $status, ['title' => 'Duplicates: group ' . ($g['group'] ?? $g['id']), 'active' => 'duplicates',
            'notice' => self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null]);
    }

    /** A short name for a page's column: its product title, cut. @param array<string, mixed> $l */
    private static function short(array $l): string
    {
        $t = trim((string) ($l['title'] ?? ''));
        return $t === '' ? '#' . $l['id'] : (mb_strlen($t) > 40 ? mb_substr($t, 0, 38) . '...' : $t);
    }

    /** POST /ui/review/duplicates/{id}/decide (mapping lead): the group's decisions in one DecisionService::decideGroup(). */
    public function decide(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $req = $ctx->req;
        $dup = new Duplicates($ctx->db);
        $g = $dup->group($id);
        if ($g === null) {
            return $ctx->error(404, 'not_found', 'no such duplicate group');
        }
        $keeperId = UiRequest::id($req->field('keeper'));
        $do = $req->field('do') ?? '';
        $choices = [];
        $versions = [];
        $props = [];
        foreach ($g['listings'] as $lid) {
            $c = $req->field('c_' . $lid);
            if ($c !== null && in_array($c, self::CHOICES, true)) {
                $choices[$lid] = $c;
            }
            $v = $req->field('v_' . $lid);
            if ($v !== null && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $v) === 1) {
                $versions[$lid] = (int) $v;
            }
            $p = UiRequest::id($req->field('p_' . $lid));
            if ($p !== null) {
                $props[$lid] = $p;
            }
        }
        $confirm = $req->field('confirm') === '1';
        $form = ['keeper' => (string) $keeperId, 'c' => $choices, 'form_key' => $req->field(FormOnce::FIELD), 'confirm' => $confirm ? '1' : ''];
        $refuse = fn (string $message, int $status = 422): HtmlResponse => $this->page($ctx, $g['id'], $form, $message, $status);
        if (!in_array($do, ['merge_all', 'separate_all', 'save'], true)) {
            return $refuse('Choose what to do with these listings.');
        }
        if ($keeperId === null || !in_array($keeperId, $g['listings'], true)) {
            return $refuse('The form is incomplete: reload the page.', 400);
        }
        $keepSku = UiRequest::id($req->field('keep_sku'));
        try {
            // Everything is worked out inside the form's one effect, from the state under its transaction: the same form sent
            // again (a double click, the back button) replays the first answer instead of finding nothing left to decide.
            $r = FormOnce::run($ctx, 'ui.duplicates.decide', ['group' => $g['id'], 'keeper' => $keeperId, 'keep_sku' => $keepSku, 'do' => $do,
                'choices' => $choices, 'versions' => $versions, 'proposals' => $props, 'confirm' => $confirm],
                function (Db $db) use ($ctx, $g, $keeperId, $keepSku, $do, $choices, $versions, $props, $confirm): OpResult {
                    [$requests, $expect] = $this->requests($ctx, $g, $keeperId, $keepSku, $do, $choices, $versions, $props, $confirm);
                    $results = $ctx->decisions()->decideGroup($ctx->caller(), $g['listings'], $requests, 'duplicates:' . $g['key'], $expect,
                        array_map(static fn (array $p): int => $p['id'], $g['proposals']));
                    $pending = array_filter($results, static fn (array $x): bool => $x['state'] === 'pending_second') !== [];
                    $merged = array_filter($results, static fn (array $x): bool => $x['action'] === 'merge_skus' && $x['state'] === 'applied') !== [];
                    $separate = array_filter($results, static fn (array $x): bool => $x['action'] === 'reject') !== [];
                    $notice = $pending ? 'pending' : ($merged && $separate ? 'mixed' : ($merged ? 'merged' : 'separate'));
                    return OpResult::of(200, ['result' => 'decided', 'redirect' => $this->after($ctx, $g['id'], $notice)]);
                });
        } catch (CwException $e) {
            return $refuse(self::plain($e), $e->httpStatus);
        }
        return FormOnce::redirect($r);
    }

    /**
     * The group decision a form asks for, against the group as it is now: per listing other than the keeper, its choice (merge,
     * separate, later; the buttons for all), skipped when there is nothing to decide for it (not linked, the same item as the
     * keeper already, waiting for a second person, kept separate before). Merges go one per item (a listing of an item another
     * listing merges moves with it); "different products" is a reject of the kept item by the listing, or, when the keeper's own
     * suggestion of THIS group proposes the listing's item, by the keeper's listing (that suggestion is the one answered).
     * M41: a decision names only a suggestion of this group; a listing whose open suggestion belongs to another group (or another
     * queue) is left to it, and refuses the form when the form offered it (it was open here when the page was drawn).
     * M44: a merge of a page the rules find reasons against (DuplicateSweep::judge against the kept page) needs the person's
     * "I checked the live pages" ($confirm), else nothing is saved.
     *
     * @param array<string, mixed> $g
     * @param array<int, string> $choices
     * @param array<int, int> $versions
     * @param array<int, int> $props
     * @return array{0: list<array<string, mixed>>, 1: array<int, int>} the requests and the map_versions every listing must still have
     */
    private function requests(Context $ctx, array $g, int $keeperId, ?int $keepSku, string $do, array $choices, array $versions, array $props, bool $confirm): array
    {
        $dup = new Duplicates($ctx->db);
        $ls = $dup->listings($g['listings']);
        $own = array_map(static fn (array $p): int => $p['id'], $g['proposals']);
        $ownProposal = static fn (array $l): ?int => $l['proposal'] !== null && in_array($l['proposal']['id'], $own, true) ? $l['proposal']['id'] : null;
        foreach ($props as $lid => $pid) {
            if (!in_array($pid, $own, true)) {
                throw new CwException('proposal_changed', 'The suggestions for this group changed since the page was drawn. Nothing was saved: check the page and decide again.', 409);
            }
        }
        $keeper = $ls[$keeperId] ?? null;
        if ($keeper === null || $keeper['sku'] === null || $keeper['sku']['merged_into'] !== null || $keepSku !== $keeper['sku']['id']
            || ($versions[$keeperId] ?? null) !== $keeper['map_version']) {
            throw new CwException('keeper_changed', 'The listing you chose to keep changed since the page was drawn (someone relinked or merged it). '
                . 'Nothing was saved: the page shows it as it is now, check it and decide again.', 409);
        }
        $ds = $ctx->decisions();
        $requests = [];
        $mergedItems = [];
        $toMerge = [];
        $expect = [$keeperId => $keeper['map_version']];
        foreach ($g['listings'] as $lid) {
            if ($lid === $keeperId) {
                continue;
            }
            $l = $ls[$lid];
            $choice = match ($do) {
                'merge_all' => 'merge',
                'separate_all' => 'separate',
                default => $choices[$lid] ?? 'later',
            };
            if ($choice === 'later' || $l['sku'] === null || $ds->rootOf($l['sku']['id']) === $keepSku || $l['pending'] !== null
                || $ds->duplicateBlocked($lid, $keepSku) === 'rejected_before') {
                continue;
            }
            if (($l['proposal'] !== null && $ownProposal($l) === null) || $l['other_proposal'] !== null) {
                if (isset($versions[$lid])) {
                    throw new CwException('elsewhere', "Listing #{$lid} has an open suggestion in another group (or another queue) since the page was drawn: "
                        . 'decide that one first. Nothing was saved.', 409);
                }
                continue;
            }
            if (!isset($versions[$lid])) {
                throw new CwException('form_incomplete', 'The form is incomplete: reload the page.', 400);
            }
            $expect[$lid] = $versions[$lid];
            if ($choice === 'merge') {
                if (isset($mergedItems[$l['sku']['id']])) {
                    continue;
                }
                $mergedItems[$l['sku']['id']] = true;
                $toMerge[] = $l;
                $requests[] = ['action' => 'merge_skus', 'listing_id' => $lid, 'expected_map_version' => $versions[$lid], 'sku_id' => $keepSku,
                    'merge_from_sku_id' => $l['sku']['id'], 'proposal_id' => $props[$lid] ?? null, 'reason' => 'Duplicates screen: same product'];
                continue;
            }
            $kp = $ownProposal($keeper) === null ? null : $keeper['proposal'];
            if ($l['proposal'] === null && $kp !== null && $kp['proposed_sku_id'] !== null && $ds->rootOf($kp['proposed_sku_id']) === $ds->rootOf($l['sku']['id'])) {
                $requests[] = ['action' => 'reject', 'listing_id' => $keeperId, 'expected_map_version' => $keeper['map_version'], 'sku_id' => $l['sku']['id'],
                    'proposal_id' => $props[$keeperId] ?? null, 'reason' => 'Duplicates screen: different products'];
            } else {
                $requests[] = ['action' => 'reject', 'listing_id' => $lid, 'expected_map_version' => $versions[$lid], 'sku_id' => $keepSku,
                    'proposal_id' => $props[$lid] ?? null, 'reason' => 'Duplicates screen: different products'];
            }
        }
        if ($requests === []) {
            throw new CwException('nothing_chosen', $do === 'save' ? 'Choose "same product" or "different products" for at least one listing (or leave the page as it is).'
                : 'Nothing is left to decide in this group.', 422);
        }
        if ($toMerge !== [] && !$confirm) {
            $verdicts = $dup->verdicts([$keeper, ...$toMerge], $keeperId);
            $doubt = array_values(array_filter($toMerge, static fn (array $l): bool => !($verdicts[$l['id']]['ok'] ?? false)));
            if ($doubt !== []) {
                throw new CwException('confirm_needed', 'The rules found reasons that ' . (count($doubt) === 1 ? 'listing #' . $doubt[0]['id'] . ' is' : 'listings #'
                    . implode(', #', array_column($doubt, 'id')) . ' are') . ' a different product (see "What the rules say"). Nothing was saved: open the live '
                    . 'pages, and if they are the same product tick "I checked the live pages" and merge again.', 422);
            }
        }
        return [$requests, $expect];
    }

    /** POST /ui/review/duplicates/{id}/split (mapping lead): one listing back off a merge (DecisionService split, M33). */
    public function split(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $req = $ctx->req;
        $g = (new Duplicates($ctx->db))->group($id);
        if ($g === null) {
            return $ctx->error(404, 'not_found', 'no such duplicate group');
        }
        $lid = UiRequest::id($req->field('listing'));
        $v = $req->field('v');
        $to = $req->field('to') ?? 'former';
        if ($lid === null || !in_array($lid, $g['listings'], true) || $v === null || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $v) !== 1
            || !in_array($to, DecisionService::SPLIT_TO, true)) {
            return $this->page($ctx, $g['id'], null, 'The form is incomplete: reload the page.', 400);
        }
        $reason = trim($req->field('reason') ?? '');
        if (mb_strlen($reason) > DecisionService::MAX_REASON) {
            return $this->page($ctx, $g['id'], null, 'The reason is at most ' . DecisionService::MAX_REASON . ' characters.', 422);
        }
        $request = ['action' => 'split', 'listing_id' => $lid, 'expected_map_version' => (int) $v, 'split_to' => $to,
            'proposal_id' => UiRequest::id($req->field('proposal')), 'reason' => $reason === '' ? 'Duplicates screen: not the same product after all' : $reason];
        try {
            $r = FormOnce::run($ctx, 'ui.duplicates.split', $request + ['group' => $g['id']], function (Db $db) use ($ctx, $g, $request): OpResult {
                $res = $ctx->decisions()->decide($ctx->caller(), $request);
                return OpResult::of(200, ['result' => $res['state'], 'redirect' => Html::url('/ui/review/duplicates/' . $g['id'],
                    ['notice' => $res['state'] === 'pending_second' ? 'split_pending' : 'split'])]);
            });
        } catch (CwException $e) {
            return $this->page($ctx, $g['id'], null, self::plain($e), $e->httpStatus);
        }
        return FormOnce::redirect($r);
    }

    /**
     * Where a decision leads: the next open group in list order (after this one, else the first other); with none, this group
     * again while something in it is still open (a listing left for later, a merge waiting for a second person), else the list.
     */
    private function after(Context $ctx, int $current, string $notice): string
    {
        $dup = new Duplicates($ctx->db);
        $next = $this->nextId($dup, $current);
        if ($next !== null) {
            return Html::url('/ui/review/duplicates/' . $next, ['notice' => $notice]);
        }
        if (($dup->group($current)['open'] ?? 0) > 0) {
            return Html::url('/ui/review/duplicates/' . $current, ['notice' => $notice]);
        }
        return Html::url('/ui/review/duplicates', ['notice' => 'done']);
    }

    private function next(Duplicates $dup, int $current): ?string
    {
        $n = $this->nextId($dup, $current);
        return $n === null ? null : '/ui/review/duplicates/' . $n;
    }

    private function nextId(Duplicates $dup, int $current): ?int
    {
        $ids = array_map(static fn (array $g): int => $g['id'], $dup->openGroups(false));
        $at = array_search($current, $ids, true);
        if ($at !== false && isset($ids[$at + 1])) {
            return $ids[$at + 1];
        }
        foreach ($ids as $gid) {
            if ($gid !== $current) {
                return $gid;
            }
        }
        return null;
    }

    /** A DecisionService refusal in plain words for the person at the screen. */
    private static function plain(CwException $e): string
    {
        return match ($e->errorCode) {
            'map_version_conflict' => 'One of these listings changed since the page was drawn (someone linked, merged or the site renamed it). '
                . 'Nothing was saved: the page shows them as they are now, check and decide again.',
            'proposal_changed', 'proposal_closed' => 'The suggestions for this group changed since the page was drawn. Nothing was saved: check the page and decide again.',
            'pending_second_exists' => 'A decision on one of these listings is waiting for a second person. Nothing was saved: approve or withdraw it first (Second approval).',
            'rejected_pair' => 'These listings cannot be merged: one of them was marked as a different product before. Nothing was saved.',
            'protected_merge', 'protected_split' => 'One of the items is protected (counted and set to sell from the warehouse): it cannot be merged or split here. Nothing was saved.',
            'counted_meanwhile' => 'One of the items was counted a moment ago, so this needs a second mapping lead now. Nothing was saved: decide again.',
            'not_merged' => 'This listing was not moved here by a merge (or was relinked since): change it on its review page instead. Nothing was saved.',
            'former_merged_elsewhere' => 'The item this listing had before was merged into another item since: split it to a new item instead. Nothing was saved.',
            'lead_required' => 'Only a mapping lead can do this. Nothing was saved.',
            'idempotency_key_reused' => 'This form was already sent with other choices: reload the page and decide again.',
            'keeper_changed', 'form_incomplete', 'nothing_chosen', 'elsewhere', 'confirm_needed' => $e->getMessage(),
            'split_chain' => 'This page came onto its item through two merges, so CW cannot tell which merge was wrong: split it to a new item instead (no '
                . 'stock moves; the next count settles it). Nothing was saved.',
            default => $e->getMessage() . ' Nothing was saved.',
        };
    }
}
