<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Admin\ApprovalRules;
use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Settings;
use CW\Staff\StaffRoles;

/**
 * Bulk action on the website products a person ticked on the screens (the owner's "bulk action and review option each store wise",
 * 8 Oct 2026; docs/decisions.md M46-M53): the match-strength lists of Products > Mapping (confirm the suggested match, not a match,
 * create as new product, ignore) and Store Products (ignore, take off the ignored list, send back for matching).
 *
 * Every row is ONE DecisionService decision, in its own transaction, in listing id order (the order every other decision locks
 * listings in), with the map_version and the suggestion the page showed: a row someone changed meanwhile is skipped, never
 * overwritten (M5, M19), and one row that fails never undoes another. Every rule of a single decision applies to each row
 * (roles, the Conflict band, the two-person rules, the owner's approval switches); a row that needs a second person is stored
 * waiting for one (Second approval), never linked. Before a row is sent, what DecisionService does not know is checked (M51):
 * a listing held back from the bulk confirm (M30) or in a spot check that is not complete (M28) is never bulk-actioned, and a
 * confirm also skips a vetoed or flagged suggestion, a listing of a failed spot check's population, and one whose bulk link was
 * undone before; a new product skips a listing whose barcode is elsewhere (the single page does not preselect it either). The
 * hold and the spot check are read again under the decision's listing lock (a hold written meanwhile rolls the row back, as in
 * KeyBulk). "Skip anything doubtful, finish the rest": each skipped row is recorded with why.
 *
 * The existing Key bulk confirm of unseen strong matches (KeyBulk, the spot check) is a separate thing and is unchanged.
 *
 * A batch (mapping_batch, `screen:<id>` on every decision and its audit row) and one mapping_batch_row per ticked row (written in
 * the decision's transaction, so the result screen reports exactly what was saved). Audited `mapping.bulk` with the counts.
 */
final class BulkDecisions
{
    /** The bulk actions, by where they are offered (M46, M48). */
    public const ACTIONS = ['link', 'new_item', 'reject', 'ignore', 'unignore', 'send_back'];
    public const REVIEW_ACTIONS = ['link', 'new_item', 'reject', 'ignore'];
    public const STORE_ACTIONS = ['ignore', 'unignore', 'send_back'];
    /** The actions that say no (or take products out of the lists): the screens ask a second time, stating the count. */
    public const NEGATIVE = ['reject', 'ignore', 'send_back'];
    public const SOURCES = ['review', 'store'];
    /** The defaults of the settings (a schema before 0021 reads these). */
    public const DEFAULT_BANDS = ['Key', 'Check'];
    public const DEFAULT_MAX_ROWS = 100;
    /** Proposal flags a confirm never does in bulk (a person decides them one at a time: KeyEligibility::FLAG_BLOCKS). */
    public const FLAG_BLOCKS = KeyEligibility::FLAG_BLOCKS;
    /** Why a row is skipped (Ui\Words::BULK_SKIP has the words of each). */
    public const SKIPS = ['unknown_listing', 'other_store', 'changed', 'suggestion_changed', 'pending_second', 'held', 'held_meanwhile', 'in_spot_check',
        'spot_check_failed', 'bulk_undone', 'band_not_allowed', 'vetoed', 'flagged', 'no_product', 'barcode_elsewhere', 'already_rejected', 'not_ignored',
        'already_ignored', 'linked', 'not_waiting', 'no_suggestion', 'lead_only', 'not_allowed', 'product_joined', 'no_name', 'refused'];
    /** DecisionService refusals => the skip they are reported as (others: `refused`, with the code). */
    private const REFUSAL_SKIP = [
        'map_version_conflict' => 'changed',
        'proposal_changed' => 'suggestion_changed',
        'proposal_closed' => 'suggestion_changed',
        'proposal_mismatch' => 'suggestion_changed',
        'pending_second_exists' => 'pending_second',
        'already_linked' => 'linked',
        'lead_required' => 'lead_only',
        'role_not_allowed' => 'not_allowed',
        'staff_not_allowed' => 'not_allowed',
        'staff_required' => 'not_allowed',
        'sku_merged' => 'product_joined',
        'unknown_sku' => 'no_product',
        'name_required' => 'no_name',
        'not_ignored' => 'not_ignored',
        'not_waiting' => 'not_waiting',
        'no_open_proposal' => 'no_suggestion',
        'unknown_listing' => 'unknown_listing',
    ];
    private const OPEN = ['unmapped', 'suggested'];

    public function __construct(private readonly Db $db, private readonly DecisionService $ds)
    {
    }

    /** The match strengths whose ticked rows may be confirmed together (setting mapping.bulk_confirm_bands, read now). @return list<string> */
    public static function confirmBands(Db $db): array
    {
        return Settings::listOf($db, 'mapping.bulk_confirm_bands', self::DEFAULT_BANDS);
    }

    /** The most rows one bulk action takes (setting mapping.bulk_max_rows, read now). */
    public static function maxRows(Db $db): int
    {
        return max(1, Settings::number($db, 'mapping.bulk_max_rows', self::DEFAULT_MAX_ROWS));
    }

    /** Whether confirms and new products of a ticked list wait for a second matching lead now (approvals.mapping_bulk_second_ok). */
    public static function secondOk(Db $db): bool
    {
        return ApprovalRules::on($db, 'approvals.mapping_bulk_second_ok');
    }

    /**
     * Whether a list offers $action to a person with $roles (the screens draw only these; run() refuses the rest anyway): a person
     * who may decide one website product may do it for many (M49), a confirm only on the strengths of the setting, a new product
     * only on New product, Clues disagree only for a matching lead (as one at a time).
     *
     * @param list<string> $roles
     * @param list<string> $confirmBands
     */
    public static function offered(string $action, string $source, ?string $band, array $roles, array $confirmBands): bool
    {
        if (!Permissions::can($roles, 'mapping.decide') || !in_array($action, $source === 'store' ? self::STORE_ACTIONS : self::REVIEW_ACTIONS, true)) {
            return false;
        }
        if ($source === 'store') {
            return true;
        }
        if ($band === null || !in_array($band, Proposals::BANDS, true)) {
            return false;
        }
        if ($band === 'Conflict' && !Permissions::can($roles, 'mapping.approve')) {
            return false;
        }
        return match ($action) {
            'link' => in_array($band, $confirmBands, true),
            'new_item' => $band === 'New item',
            'reject' => $band !== 'New item',
            default => true,
        };
    }

    /**
     * Does $action for the ticked rows, row by row (the class comment). Refuses the whole request, writing nothing, when the
     * action is not one of the list's, the person may not make it, a confirm is asked on a strength the setting leaves out, the
     * rows are none or more than the setting allows, or an ignore has no note.
     *
     * @param list<array{listing_id: int, proposal_id: ?int, map_version: int}> $rows what the page showed for each ticked row
     * @param array{source: string, channel_id?: ?int, band?: ?string, reason?: ?string} $context
     * @return array{batch_id: int, batch: string, done: int, pending: int, skipped: int}
     */
    public function run(Caller $caller, string $action, array $rows, array $context): array
    {
        $source = $context['source'];
        $channelId = $context['channel_id'] ?? null;
        $band = $context['band'] ?? null;
        $reason = isset($context['reason']) ? trim((string) $context['reason']) : '';
        $reason = $reason === '' ? null : $reason;
        if (!in_array($source, self::SOURCES, true) || !in_array($action, $source === 'store' ? self::STORE_ACTIONS : self::REVIEW_ACTIONS, true)) {
            throw new CwException('bad_action', 'this list has no such bulk action', 400);
        }
        if ($source === 'review' && ($band === null || !in_array($band, Proposals::BANDS, true))) {
            throw new CwException('bad_request', 'a match-strength list names its strength', 400);
        }
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'a person makes bulk decisions', 403);
        }
        $roles = StaffRoles::active($this->db, $caller->staffUserId);
        if (!Permissions::can($roles, 'mapping.decide')) {
            throw new CwException('role_not_allowed', 'these roles cannot make mapping decisions', 403);
        }
        if ($source === 'review' && $band === 'Conflict' && !Permissions::can($roles, 'mapping.approve')) {
            throw new CwException('lead_required', 'a listing with a Conflict proposal is decided by a mapping_lead', 403);
        }
        $confirmBands = self::confirmBands($this->db);
        if (!self::offered($action, $source, $band, $roles, $confirmBands)) {
            // A confirm on a strength the setting leaves out (or a new product off its list): refused even when posted (M46).
            throw new CwException('band_not_allowed', 'this bulk action is not allowed on this list', 403, ['band' => $band, 'allowed' => $confirmBands]);
        }
        if ($reason !== null && mb_strlen($reason) > DecisionService::MAX_REASON) {
            throw new CwException('bad_reason', 'reason is at most ' . DecisionService::MAX_REASON . ' characters', 400);
        }
        if ($action === 'ignore' && $reason === null) {
            throw new CwException('reason_required', 'ignoring needs a note', 422);
        }
        $byListing = [];
        foreach ($rows as $r) {
            $byListing[(int) $r['listing_id']] = ['listing_id' => (int) $r['listing_id'], 'proposal_id' => $r['proposal_id'] === null ? null : (int) $r['proposal_id'],
                'map_version' => (int) $r['map_version']];
        }
        ksort($byListing);
        $max = self::maxRows($this->db);
        if ($byListing === []) {
            throw new CwException('nothing_ticked', 'no row was ticked', 422);
        }
        if (count($byListing) > $max) {
            throw new CwException('too_many_rows', 'at most ' . $max . ' rows at a time', 422, ['max' => $max, 'asked' => count($byListing)]);
        }

        $batchId = $this->db->transaction(fn (Db $db): int => $db->insert(
            'INSERT INTO mapping_batch (action, source, channel_id, band, rows_asked, reason, staff_user_id, actor) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$action, $source, $channelId, $source === 'review' ? $band : null, count($byListing), $reason, $caller->staffUserId, $caller->actor],
        ));
        $batch = DecisionService::SCREEN_BATCH_PREFIX . $batchId;
        $skips = $this->prechecks($action, array_values($byListing), $channelId, $confirmBands);
        $count = ['done' => 0, 'pending_second' => 0, 'skipped' => 0];
        $why = [];
        $seq = 0;
        foreach ($byListing as $lid => $row) {
            $seq++;
            [$outcome, $code] = $this->one($caller, $action, $row, $seq, $batchId, $batch, $reason, $skips[$lid] ?? null);
            $count[$outcome]++;
            if ($code !== null) {
                $why[$code] = ($why[$code] ?? 0) + 1;
            }
        }
        $this->db->transaction(function (Db $db) use ($caller, $batchId, $batch, $action, $source, $channelId, $band, $count, $why, $byListing): void {
            Audit::write($db, $caller, 'mapping.bulk', 'mapping_batch', (string) $batchId, null, ['batch' => $batch, 'action' => $action, 'source' => $source,
                'channel_id' => $channelId, 'band' => $band, 'asked' => count($byListing), 'done' => $count['done'], 'pending_second' => $count['pending_second'],
                'skipped' => $count['skipped'], 'skipped_why' => $why === [] ? new \stdClass() : $why]);
        });
        return ['batch_id' => $batchId, 'batch' => $batch, 'done' => $count['done'], 'pending' => $count['pending_second'], 'skipped' => $count['skipped']];
    }

    /**
     * One row: a skip found before, or the decision in its own transaction with its batch row; a refusal is recorded as a skip.
     *
     * @param array{listing_id: int, proposal_id: ?int, map_version: int} $row
     * @param array{code: string, detail: array<string, mixed>, request?: array<string, mixed>}|null $pre
     * @return array{0: string, 1: ?string} outcome, skip code
     */
    private function one(Caller $caller, string $action, array $row, int $seq, int $batchId, string $batch, ?string $reason, ?array $pre): array
    {
        $lid = $row['listing_id'];
        if ($pre !== null && $pre['code'] !== '') {
            $this->record($batchId, $seq, $row, 'skipped', $pre['code'], $pre['detail'], null);
            return ['skipped', $pre['code']];
        }
        $req = ($pre['request'] ?? []) + ['action' => $action, 'listing_id' => $lid, 'expected_map_version' => $row['map_version'], 'bulk_batch_id' => $batch];
        if ($row['proposal_id'] !== null) {
            $req['proposal_id'] = $row['proposal_id'];
        }
        if ($reason !== null) {
            $req['reason'] = $reason;
        }
        try {
            $d = $this->db->transaction(function (Db $db) use ($caller, $req, $lid, $row, $seq, $batchId): array {
                $d = $this->ds->decide($caller, $req);
                // Under the decision's lock on the listing: a hold (M30) or a spot check written since the checks above stops it.
                if (KeyHold::activeForListings($db, [$lid]) !== []) {
                    throw new CwException('bulk_skip', 'held meanwhile', 409, ['skip' => 'held_meanwhile']);
                }
                if ($this->inSpotCheck([$lid]) !== []) {
                    throw new CwException('bulk_skip', 'in a spot check', 409, ['skip' => 'in_spot_check']);
                }
                $outcome = $d['state'] === 'pending_second' ? 'pending_second' : 'done';
                $this->record($batchId, $seq, $row, $outcome, null, $outcome === 'pending_second' ? ['needs_second' => $d['needs_second']] : null,
                    (int) $d['decision_id']);
                return $d;
            });
        } catch (\PDOException $e) {
            // A database refusal of this row (a lock wait that ran out, say): reported, and the next row goes on (M52).
            $this->record($batchId, $seq, $row, 'skipped', 'refused', ['code' => 'database', 'message' => 'the database refused this row: try it again'], null);
            return ['skipped', 'refused'];
        } catch (CwException $e) {
            $code = $e->errorCode === 'bulk_skip' ? (string) $e->detail['skip'] : (self::REFUSAL_SKIP[$e->errorCode] ?? 'refused');
            $detail = $e->errorCode === 'bulk_skip' ? [] : ['code' => $e->errorCode, 'message' => mb_substr($e->getMessage(), 0, 300)];
            if ($e->errorCode === 'map_version_conflict' && is_string($e->detail['status'] ?? null)) {
                $detail['status'] = $e->detail['status'];
            }
            $this->record($batchId, $seq, $row, 'skipped', $code, $detail, null);
            return ['skipped', $code];
        }
        return [$d['state'] === 'pending_second' ? 'pending_second' : 'done', null];
    }

    /**
     * What is known before any row is sent, read in a few queries: a skip (code + detail) for the rows that must not be sent, and
     * for the others what DecisionService needs (the item and units of the suggestion).
     *
     * @param list<array{listing_id: int, proposal_id: ?int, map_version: int}> $rows
     * @param list<string> $confirmBands
     * @return array<int, array{code: string, detail: array<string, mixed>, request?: array<string, mixed>}> listing id => pre-check
     */
    private function prechecks(string $action, array $rows, ?int $channelId, array $confirmBands): array
    {
        $ids = array_map(static fn (array $r): int => $r['listing_id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $listings = [];
        foreach ($this->db->all(
            'SELECT cl.id, cl.channel_id, cl.status, cl.map_version, p.id AS proposal_id, p.band, p.proposed_sku_id, p.proposed_new_item, p.ai_units_per_item, '
            . 'p.flags, p.evidence, pd.id AS pending_id, lp.barcodes '
            . 'FROM channel_listing cl LEFT JOIN match_proposal p ON p.open_listing_id = cl.id LEFT JOIN match_decision pd ON pd.pending_listing_id = cl.id '
            . "LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.id IN ({$in})",
            $ids,
        ) as $l) {
            $listings[(int) $l['id']] = $l;
        }
        $held = KeyHold::activeForListings($this->db, $ids);
        $spot = $this->inSpotCheck($ids);
        $failed = $action === 'link' ? $this->failedPopulation($ids) : [];
        $undone = $action === 'link' ? array_fill_keys(array_map('intval', $this->db->column(
            "SELECT DISTINCT listing_id FROM match_decision WHERE listing_id IN ({$in}) AND bulk_batch_id LIKE 'undo:%'", $ids)), true) : [];
        $rejected = [];
        if ($action === 'reject') {
            foreach ($this->db->all("SELECT listing_id, sku_id FROM match_reject WHERE listing_id IN ({$in})", $ids) as $x) {
                $rejected[(int) $x['listing_id']][(int) $x['sku_id']] = true;
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $lid = $row['listing_id'];
            $l = $listings[$lid] ?? null;
            $skip = static fn (string $code, array $detail = []): array => ['code' => $code, 'detail' => $detail];
            if ($l === null) {
                $out[$lid] = $skip('unknown_listing');
                continue;
            }
            if ($channelId !== null && (int) $l['channel_id'] !== $channelId) {
                $out[$lid] = $skip('other_store');
                continue;
            }
            if (isset($held[$lid])) {
                $h = $held[$lid];
                $out[$lid] = $skip('held', ['reason' => $h['reason'], 'by' => $h['by'], 'at' => $h['at']]);
                continue;
            }
            if (isset($spot[$lid])) {
                $out[$lid] = $skip('in_spot_check', ['sample' => $spot[$lid]]);
                continue;
            }
            if ((int) $l['map_version'] !== $row['map_version']) {
                $out[$lid] = $skip('changed', ['status' => (string) $l['status']]);
                continue;
            }
            if ($l['pending_id'] !== null) {
                $out[$lid] = $skip('pending_second');
                continue;
            }
            $open = $l['proposal_id'] === null ? null : (int) $l['proposal_id'];
            if ($open !== $row['proposal_id']) {
                $out[$lid] = $skip('suggestion_changed');
                continue;
            }
            $status = (string) $l['status'];
            $linked = in_array($status, DecisionService::LINKED, true);
            switch ($action) {
                case 'link':
                case 'new_item':
                case 'reject':
                    if ($linked) {
                        $out[$lid] = $skip('linked');
                        break;
                    }
                    if (!in_array($status, self::OPEN, true)) {
                        $out[$lid] = $skip('not_waiting', ['status' => $status]);
                        break;
                    }
                    if ($open === null) {
                        $out[$lid] = $skip('no_suggestion');
                        break;
                    }
                    $out[$lid] = $this->suggestionCheck($action, $l, $confirmBands, $failed[$lid] ?? null, isset($undone[$lid]), $rejected[$lid] ?? []);
                    break;
                case 'ignore':
                    $out[$lid] = match (true) {
                        $linked => $skip('linked'),
                        $status === 'ignored' => $skip('already_ignored'),
                        !in_array($status, self::OPEN, true) => $skip('not_waiting', ['status' => $status]),
                        default => ['code' => '', 'detail' => []],
                    };
                    break;
                case 'unignore':
                    $out[$lid] = $status === 'ignored' ? ['code' => '', 'detail' => []] : $skip('not_ignored', ['status' => $status]);
                    break;
                case 'send_back':
                    $out[$lid] = match (true) {
                        $linked => $skip('linked'),
                        $status === 'ignored' => $skip('not_waiting', ['status' => $status]),
                        $open === null => $skip('no_suggestion'),
                        default => ['code' => '', 'detail' => []],
                    };
                    break;
            }
        }
        return $out;
    }

    /**
     * The checks of a row's open suggestion for a confirm, a new product or a "not a match", and what DecisionService needs.
     *
     * @param array<string, mixed> $l the listing with its open proposal (prechecks())
     * @param list<string> $confirmBands
     * @param array<int, true> $rejected the items this listing was marked "not this product" for
     * @return array{code: string, detail: array<string, mixed>, request?: array<string, mixed>}
     */
    private function suggestionCheck(string $action, array $l, array $confirmBands, ?string $failedSample, bool $undone, array $rejected): array
    {
        $band = (string) $l['band'];
        $sku = $l['proposed_sku_id'] === null ? null : (int) $l['proposed_sku_id'];
        $units = $l['ai_units_per_item'] === null ? 1 : max(1, (int) $l['ai_units_per_item']);
        $skip = static fn (string $code, array $detail = []): array => ['code' => $code, 'detail' => $detail];
        if ($action === 'reject') {
            if ($sku === null) {
                return $skip('no_product');
            }
            if (isset($rejected[$sku])) {
                return $skip('already_rejected');
            }
            return ['code' => '', 'detail' => [], 'request' => ['sku_id' => $sku]];
        }
        if ($action === 'new_item') {
            if ($band !== 'New item' || (int) $l['proposed_new_item'] !== 1) {
                return $skip('band_not_allowed', ['band' => $band]);
            }
            if ($this->barcodeElsewhere((int) $l['id'], self::strings(json_decode((string) ($l['barcodes'] ?? 'null'), true)))) {
                return $skip('barcode_elsewhere');
            }
            return ['code' => '', 'detail' => [], 'request' => ['units_per_item' => $units]];
        }
        if (!in_array($band, $confirmBands, true)) {
            return $skip('band_not_allowed', ['band' => $band]);
        }
        if ($sku === null) {
            return $skip('no_product');
        }
        $ev = json_decode((string) ($l['evidence'] ?? 'null'), true);
        $ev = is_array($ev) ? $ev : [];
        $vetoes = array_values(array_unique([...self::strings($ev['target_vetoes'] ?? null), ...self::strings(is_array($ev['ai'] ?? null) ? ($ev['ai']['vetoes_on_chosen'] ?? null) : null)]));
        if ($vetoes !== []) {
            return $skip('vetoed', ['vetoes' => $vetoes]);
        }
        $flags = array_values(array_intersect(self::strings(json_decode((string) ($l['flags'] ?? 'null'), true)), self::FLAG_BLOCKS));
        if ($flags !== []) {
            return $skip('flagged', ['flags' => $flags]);
        }
        if ($failedSample !== null) {
            return $skip('spot_check_failed', ['sample' => $failedSample]);
        }
        if ($undone) {
            return $skip('bulk_undone');
        }
        return ['code' => '', 'detail' => [], 'request' => ['sku_id' => $sku, 'units_per_item' => $units]];
    }

    /**
     * The listings that are one of the matches of a spot check that is not complete (M28: only its owner answers them, one at a time;
     * a bulk decision on one would fail it), with the spot check's name.
     *
     * @param list<int> $listingIds
     * @return array<int, string> listing id => spot check name
     */
    private function inSpotCheck(array $listingIds): array
    {
        $members = [];
        foreach (array_chunk($listingIds, 500) as $chunk) {
            foreach ($this->db->all('SELECT m.listing_id, m.sample_id, k.name FROM key_sample_member m JOIN key_sample k ON k.id = m.sample_id '
                . 'WHERE m.position IS NOT NULL AND m.listing_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $m) {
                $members[(int) $m['listing_id']][(int) $m['sample_id']] = (string) $m['name'];
            }
        }
        if ($members === []) {
            return [];
        }
        $verdicts = (new KeySample($this->db))->verdicts(array_values(array_unique(array_merge(...array_map('array_keys', array_values($members))))));
        $out = [];
        foreach ($members as $lid => $samples) {
            foreach ($samples as $sid => $name) {
                if (($verdicts[$sid]['verdict'] ?? 'waiting') !== 'complete') {
                    $out[$lid] = $name;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * The listings in the population of a spot check that failed (M28: "if even one is wrong, the rest are checked one by one").
     *
     * @param list<int> $listingIds
     * @return array<int, string> listing id => spot check name
     */
    private function failedPopulation(array $listingIds): array
    {
        $of = [];
        foreach (array_chunk($listingIds, 500) as $chunk) {
            foreach ($this->db->all('SELECT m.listing_id, m.sample_id, k.name FROM key_sample_member m JOIN key_sample k ON k.id = m.sample_id WHERE m.listing_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $m) {
                $of[(int) $m['listing_id']][(int) $m['sample_id']] = (string) $m['name'];
            }
        }
        if ($of === []) {
            return [];
        }
        $verdicts = (new KeySample($this->db))->verdicts(array_values(array_unique(array_merge(...array_map('array_keys', array_values($of))))));
        $out = [];
        foreach ($of as $lid => $samples) {
            foreach ($samples as $sid => $name) {
                if (($verdicts[$sid]['verdict'] ?? null) === 'failed') {
                    $out[$lid] = $name;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Whether one of the listing's barcodes is on an item or on another listing (any site, linked or not): what a new product could
     * duplicate. The review page shows these and then preselects nothing (ReviewController); a bulk new product skips them. The same
     * reads as Ui\Queries::barcodeElsewhere (the GTIN key too; the multi-valued index of 0005).
     *
     * @param list<string> $barcodes
     */
    private function barcodeElsewhere(int $listingId, array $barcodes): bool
    {
        $codes = [];
        foreach (array_slice($barcodes, 0, 20) as $b) {
            $codes[$b] = true;
            $key = \CW\Matching\Gtin::key($b);
            if ($key !== null) {
                $codes[$key] = true;
            }
        }
        $codes = array_map('strval', array_keys($codes));
        if ($codes === []) {
            return false;
        }
        $in = implode(',', array_fill(0, count($codes), '?'));
        return $this->db->value("SELECT 1 FROM sku_barcode WHERE barcode IN ({$in}) LIMIT 1", $codes) !== null
            || $this->db->value('SELECT 1 FROM listing_profile lp WHERE JSON_OVERLAPS(lp.barcodes, CAST(? AS JSON)) AND lp.listing_id <> ? LIMIT 1',
                [json_encode($codes, JSON_THROW_ON_ERROR), $listingId]) !== null;
    }

    /** @param array<string, mixed>|null $detail */
    private function record(int $batchId, int $seq, array $row, string $outcome, ?string $code, ?array $detail, ?int $decisionId): void
    {
        $this->db->transaction(fn (Db $db): int => $db->insert(
            'INSERT INTO mapping_batch_row (batch_id, seq, listing_id, proposal_id, seen_map_version, outcome, skip_code, detail, decision_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$batchId, $seq, $row['listing_id'], $row['proposal_id'], $row['map_version'], $outcome, $code,
                $detail === null || $detail === [] ? null : Idempotency::json($detail), $decisionId],
        ));
    }

    /** @return list<string> */
    private static function strings(mixed $v): array
    {
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }
}
