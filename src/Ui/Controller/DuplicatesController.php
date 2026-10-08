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
use CW\Ui\Words;

/**
 * Possible duplicates (docs/decisions.md M34; the owner's decision of 6 Oct 2026; in plain words since 7 Oct 2026, plan §6.11,
 * 6.12): Vape and Go's own duplicate listings, the same product on two pages, linked to ONE CW item so their sales and
 * deliveries count once. Nothing changes on the site: the pages keep their own price and reviews until Vape and Go runs on CW.
 * The screens say "join" for a merge and "keep apart" for a reject (Words::DUPS); "keep apart" is the safer answer.
 *
 *   GET  /ui/review/duplicates             the open merge suggestions, by group, the biggest sellers first (linking.view)
 *   GET  /ui/review/duplicates/{id}        one group: what the rules say first, the answers (design B), the pages side by side
 *   POST /ui/review/duplicates/{id}/decide "Same product – join them" / "Different products – keep apart", or one choice per
 *                                          listing (3 or more): one DecisionService::decideGroup() (mapping lead)
 *   POST /ui/review/duplicates/{id}/split  "Undo the join" for one listing (DecisionService split; mapping lead)
 *
 * Both POSTs carry a FormOnce key (a double click decides once) and the map_version of every listing the form showed: a
 * listing that changed meanwhile refuses the whole form, and the page is drawn again with the reason in plain words.
 */
final class DuplicatesController
{
    /** notice key => its words (Words::DUP_NOTICE; %s is the group's name). Only these can be shown. */
    public const NOTICES = [
        'merged' => Words::DUP_NOTICE['merged'],
        'separate' => Words::DUP_NOTICE['separate'],
        'mixed' => Words::DUP_NOTICE['mixed'],
        'pending' => Words::DUP_NOTICE['pending'],
        'split' => Words::DUP_NOTICE['split'],
        'split_pending' => Words::DUP_NOTICE['split_pending'],
        'done' => Words::DUP_NOTICE['done'],
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
        $lead = $ctx->me()->isLead();
        return $ctx->page('duplicates', ['rows' => $rows, 'total' => count($rows), 'recent' => $recent['rows'], 'recent_total' => $recent['total'],
            'recent_page' => $page, 'recent_prev' => $page > 1 ? Html::url('/ui/review/duplicates', ['page' => (string) ($page - 1)]) : null,
            'recent_next' => $page < $pages ? Html::url('/ui/review/duplicates', ['page' => (string) ($page + 1)]) : null, 'recent_pages' => $pages,
            'lookOnly' => $lead ? null : Words::whoCan('mapping.approve'), 'look' => $lead ? null : Words::DUPS['look']], 200,
            ['title' => Words::title('duplicates'), 'active' => 'duplicates', 'notice' => $this->notice($ctx, null)]);
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
            return $ctx->error(404, 'unknown_group', 'no such duplicate group', ['/ui/review/duplicates', Words::title('duplicates')]);
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
            $noForm = Words::DUPS['look'];
        }
        $merged = array_values(array_filter($rows, static fn (array $r): bool => $r['undo'] !== null));
        $names = [];
        foreach ($ctx->queries()->channels() as $c) {
            $names[(string) $c['code']] = (string) $c['name'];
        }
        // The heading says the answer once the group is decided (F127).
        $same = array_filter($rows, static fn (array $r): bool => $r['state'] === 'same') !== [];
        $apart = array_filter($rows, static fn (array $r): bool => $r['state'] === 'separate') !== [];
        $heading = $g['open'] > 0 || $keeper === null || (!$same && !$apart) ? Words::say('DUPS', 'title', count($rows))
            : ($same && !$apart ? Words::say('DUPS', 'decided_same', (string) $keeper['sku']['code']) : ($apart && !$same ? Words::DUPS['decided_apart'] : Words::DUPS['decided_mixed']));
        return $ctx->page('duplicate_group', [
            'heading' => $heading,
            'kind' => match ($g['kind']) {
                'shared_gtin' => Words::DUPS['kind_shared_gtin'],
                'sweep' => Words::DUPS['kind_sweep'],
                null => null,
                default => Words::DUPS['kind_names'],
            },
            'names' => $names,
            'lookOnly' => $me->isLead() ? null : Words::whoCan('mapping.approve'),
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
        ], $status, ['title' => Words::say('DUPS', 'group', (string) ($g['group'] ?? $g['id'])), 'active' => 'duplicates',
            'notice' => $this->notice($ctx, $g['id'])]);
    }

    /**
     * The whitelisted notice named in the URL, worded for the group it is about (F133: the notice is shown on the NEXT group, so
     * it names the one decided and says this is the next one).
     */
    private function notice(Context $ctx, ?int $current): ?string
    {
        $key = $ctx->req->param('notice') ?? '';
        if (!isset(self::NOTICES[$key])) {
            return null;
        }
        if (!str_contains(self::NOTICES[$key], '%s')) {
            return self::NOTICES[$key];
        }
        $prev = UiRequest::id($ctx->req->param('prev'));
        $name = Words::DUP_NOTICE['the_group'];
        $about = $prev ?? $current;
        if ($about !== null) {
            $dup = new Duplicates($ctx->db);
            $g = $dup->group($about);
            if ($g !== null) {
                $first = $dup->listings(array_slice($g['listings'], 0, 1))[$g['listings'][0] ?? 0] ?? null;
                $name = Words::quoted($first === null ? null : trim(($first['title'] ?? '') . ' ' . ($first['variant_title'] ?? '')), $name);
            }
        }
        $text = Words::say('DUP_NOTICE', $key, $name);
        if ($prev !== null && $current !== null && $prev !== $current) {
            $text .= ' ' . Words::DUP_NOTICE['next'];
        }
        return $text;
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
            return $ctx->error(404, 'unknown_group', 'no such duplicate group', ['/ui/review/duplicates', Words::title('duplicates')]);
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
            return $refuse(Words::DUP_ERROR['choose']);
        }
        if ($keeperId === null || !in_array($keeperId, $g['listings'], true)) {
            return $refuse(Words::DUP_ERROR['form_incomplete'], 400);
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
                throw new CwException('proposal_changed', Words::DUP_ERROR['proposal_changed'], 409);
            }
        }
        $keeper = $ls[$keeperId] ?? null;
        if ($keeper === null || $keeper['sku'] === null || $keeper['sku']['merged_into'] !== null || $keepSku !== $keeper['sku']['id']
            || ($versions[$keeperId] ?? null) !== $keeper['map_version']) {
            throw new CwException('keeper_changed', Words::DUP_ERROR['keeper_changed'], 409);
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
                    throw new CwException('elsewhere', Words::say('DUP_ERROR', 'elsewhere', self::named($l)), 409);
                }
                continue;
            }
            if (!isset($versions[$lid])) {
                throw new CwException('form_incomplete', Words::DUP_ERROR['form_incomplete'], 400);
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
            throw new CwException('nothing_chosen', $do === 'save' ? Words::DUP_ERROR['nothing_chosen'] : Words::DUP_ERROR['nothing_left'], 422);
        }
        if ($toMerge !== [] && !$confirm) {
            $verdicts = $dup->verdicts([$keeper, ...$toMerge], $keeperId);
            $doubt = array_values(array_filter($toMerge, static fn (array $l): bool => !($verdicts[$l['id']]['ok'] ?? false)));
            if ($doubt !== []) {
                throw new CwException('confirm_needed', Words::say('DUP_ERROR', 'confirm_needed', Words::andList(array_map(static fn (array $l): string => self::named($l), $doubt))), 422);
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
            return $ctx->error(404, 'unknown_group', 'no such duplicate group', ['/ui/review/duplicates', Words::title('duplicates')]);
        }
        $lid = UiRequest::id($req->field('listing'));
        $v = $req->field('v');
        $to = $req->field('to') ?? 'former';
        if ($lid === null || !in_array($lid, $g['listings'], true) || $v === null || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $v) !== 1
            || !in_array($to, DecisionService::SPLIT_TO, true)) {
            return $this->page($ctx, $g['id'], null, Words::DUP_ERROR['form_incomplete'], 400);
        }
        $reason = trim($req->field('reason') ?? '');
        if (mb_strlen($reason) > DecisionService::MAX_REASON) {
            return $this->page($ctx, $g['id'], null, Words::say('DUP_ERROR', 'reason_long', DecisionService::MAX_REASON), 422);
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
            return Html::url('/ui/review/duplicates/' . $next, ['notice' => $notice, 'prev' => $current]);
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
        $ids = $dup->openGroupIds(); // the order of openGroups(), from the units alone
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

    /**
     * A refusal in the page's words, by its error code (Words::DUP_ERROR; DecisionService's own messages are the API's). The
     * page's own refusals (keeper_changed, elsewhere, confirm_needed …) are made in those words already.
     */
    private static function plain(CwException $e): string
    {
        return match ($e->errorCode) {
            'map_version_conflict', 'pending_second_exists', 'rejected_pair', 'counted_meanwhile', 'not_merged', 'former_merged_elsewhere', 'lead_required',
            'idempotency_key_reused', 'split_chain' => Words::DUP_ERROR[$e->errorCode],
            'proposal_changed', 'proposal_closed' => Words::DUP_ERROR['proposal_changed'],
            'protected_merge', 'protected_split' => Words::DUP_ERROR['protected'],
            'keeper_changed', 'form_incomplete', 'nothing_chosen', 'elsewhere', 'confirm_needed' => $e->getMessage(),
            default => Words::say('DUP_ERROR', 'other', ucfirst(rtrim($e->getMessage(), '.')) . '.'),
        };
    }

    /** A page of a group by its name, in quotes, for a sentence. @param array<string, mixed> $l a Duplicates::listings() row */
    private static function named(array $l): string
    {
        return Words::quoted(trim(((string) ($l['title'] ?? '')) . ' ' . ((string) ($l['variant_title'] ?? ''))), '#' . (int) $l['id']);
    }
}
