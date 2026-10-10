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
 * create as new product, ignore), Store Products (ignore, take off the ignored list, send back for matching) and, since 10 Oct 2026,
 * New Variants (M60-M62: create as new items, confirm the suggested match, ignore), the owner's "seed" on every store.
 *
 * Every row is ONE DecisionService decision, in its own transaction, in listing id order (the order every other decision locks
 * listings in), with the map_version and the suggestion the page showed: a row someone changed meanwhile is skipped, never
 * overwritten (M5, M19), and one row that fails never undoes another. Every rule of a single decision applies to each row
 * (roles, the Conflict band, the two-person rules, the owner's approval switches); a row that needs a second person is stored
 * waiting for one (Second approval), never linked. Before a row is sent, what DecisionService does not know is checked (M51):
 * a listing held back from the bulk confirm (M30) or in a spot check that is not complete (M28) is never bulk-actioned, and a
 * confirm also skips a vetoed or flagged suggestion, a listing of a failed spot check's population, and one whose bulk link was
 * undone before. A bulk "create as new" (any list: seedCheck(), M61) makes an item only from a listing whose open suggestion says
 * "new product", and skips everything that could make a second item for one thing or an item for nothing: a listing an item was
 * already made from (`made_before`), a page heading of the website (`page_heading`), one the website does not publish
 * (`not_published`), one that changed on the website after the computer checked it (`changed_since_check`), one whose barcode is
 * elsewhere (`barcode_elsewhere`; the single page does not preselect it either), one whose name an item already has
 * (`name_elsewhere`) or another ticked row shares (`name_twice`). On New Variants, where the rows are of every kind, also one with
 * no open suggestion (`not_checked`), one whose suggestion is a match to an item (`suggested_match`) and one the computer could not
 * decide (`not_new`): a person opens each and decides. The hold and the spot check are read again under the decision's listing
 * lock (a hold written meanwhile rolls the row back, as in KeyBulk). "Skip anything doubtful, finish the rest": each skipped row
 * is recorded with why.
 *
 * The existing Key bulk confirm of unseen strong matches (KeyBulk, the spot check) is a separate thing and is unchanged.
 *
 * A batch (mapping_batch, `screen:<id>` on every decision and its audit row) and one mapping_batch_row per ticked row (written in
 * the decision's transaction, so the result screen reports exactly what was saved). Audited `mapping.bulk` with the counts.
 *
 * Products › By Item (the list context `item`, docs/decisions.md M55-M59): the rows are the suggested variants of the items a
 * person ticked, of every strength at once, so each row's own strength is checked as it is done (prechecks(): a confirm skips a
 * strength the setting leaves out; DecisionService refuses Clues disagree to a matcher). The screen shows preview() of the same
 * rows before anything is saved: the same request checks (checked()), the same pre-checks, and what DecisionService would answer,
 * read only. Its apply carries the item each row was shown under (`sku_id`: a suggestion that proposes another item now is
 * skipped) and the rows the preview skipped (`preview_skipped`: never acted on, recorded as skipped with why). A confirm there
 * leaves to a person, one at a time (M59): a pair marked "not a match" before, an item that already has a variant (or a decision
 * waiting) on that store, and an item with more than one suggested variant on a store.
 */
final class BulkDecisions
{
    /** The bulk actions, by where they are offered (M46, M48). */
    public const ACTIONS = ['link', 'new_item', 'reject', 'ignore', 'unignore', 'send_back'];
    public const REVIEW_ACTIONS = ['link', 'new_item', 'reject', 'ignore'];
    public const STORE_ACTIONS = ['ignore', 'unignore', 'send_back'];
    /** By Item (M55): what the suggested variants of ticked items may have done to them; a match is undone one at a time (M53). */
    public const ITEM_ACTIONS = ['link', 'reject', 'send_back'];
    /** New Variants (M60): the seed first ("Create as new items"), then the existing confirm and ignore, row by row as everywhere. */
    public const NEW_ACTIONS = ['new_item', 'link', 'ignore'];
    /** The actions that say no (or take products out of the lists): the screens ask a second time, stating the count. */
    public const NEGATIVE = ['reject', 'ignore', 'send_back'];
    /** The lists a bulk action comes from, as mapping_batch.source holds them (0021, 0025, 0026). */
    public const SOURCES = ['review', 'store', 'item', 'new'];
    /** The defaults of the settings (a schema before 0021 reads these). */
    public const DEFAULT_BANDS = ['Key', 'Check'];
    public const DEFAULT_MAX_ROWS = 100;
    /** Proposal flags a confirm never does in bulk (a person decides them one at a time: KeyEligibility::FLAG_BLOCKS). */
    public const FLAG_BLOCKS = KeyEligibility::FLAG_BLOCKS;
    /** Why a row is skipped (Ui\Words::BULK_SKIP has the words of each). */
    public const SKIPS = ['unknown_listing', 'other_store', 'changed', 'suggestion_changed', 'pending_second', 'held', 'held_meanwhile', 'in_spot_check',
        'spot_check_failed', 'bulk_undone', 'band_not_allowed', 'vetoed', 'flagged', 'no_product', 'barcode_elsewhere', 'already_rejected', 'not_ignored',
        'already_ignored', 'linked', 'not_waiting', 'no_suggestion', 'lead_only', 'not_allowed', 'product_joined', 'no_name', 'refused',
        // By Item's confirm only (M59, confirmScope()):
        'rejected_before', 'item_on_store', 'several_suggested',
        // A bulk "create as new" only (M61, seedCheck() and nameChecks(); `suggested_match`, `not_checked` and `not_new` on New Variants only):
        'suggested_match', 'not_checked', 'page_heading', 'not_published', 'made_before', 'not_new', 'changed_since_check', 'name_elsewhere', 'name_twice'];
    /** The website's own state of a listing that is on sale (listing_profile.features `variant_status`, as the load tools read it). */
    public const PUBLISHED = 'Published';
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
    /** The largest number a variant's id or version can be (INT UNSIGNED): a row that names a larger one names nothing (checked()). */
    private const MAX_UINT = 0xFFFFFFFF;
    /** How far rejectedBefore() follows an item's joins (a chain of joins is one or two long). */
    private const MAX_JOINS = 32;
    /** How many names one read of the name guard asks about (one index look-up each, in one statement). */
    private const NAMES_A_READ = 50;

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

    /** The actions of a list (an unknown source has none). @return list<string> */
    public static function actionsOf(string $source): array
    {
        return match ($source) {
            'review' => self::REVIEW_ACTIONS,
            'store' => self::STORE_ACTIONS,
            'item' => self::ITEM_ACTIONS,
            'new' => self::NEW_ACTIONS,
            default => [],
        };
    }

    /**
     * Whether a list offers $action to a person with $roles (the screens draw only these; run() refuses the rest anyway): a person
     * who may decide one website product may do it for many (M49), a confirm only on the strengths of the setting, a new product
     * only on New product, Clues disagree only for a matching lead (as one at a time). New Variants mixes strengths in one list:
     * it offers its three actions, a confirm while the setting allows any strength, and each row is checked on its own (M61, M62).
     *
     * @param list<string> $roles
     * @param list<string> $confirmBands
     */
    public static function offered(string $action, string $source, ?string $band, array $roles, array $confirmBands): bool
    {
        if ($source === 'item') {
            // By Item (M55): rows of every strength; each row's own strength and its Conflict rule are checked as it is done.
            return Permissions::can($roles, 'mapping.decide') && in_array($action, self::ITEM_ACTIONS, true);
        }
        if (!Permissions::can($roles, 'mapping.decide') || !in_array($action, self::actionsOf($source), true)) {
            return false;
        }
        if ($source === 'store') {
            return true;
        }
        if ($source === 'new') {
            return $action !== 'link' || $confirmBands !== [];
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
     * The checks of the whole request, for run() and preview() alike (they were the first lines of run(), M56): the list and its
     * action, a person who may decide, a confirm only on the strengths of the setting, the note, at least one and at most the
     * setting's rows. A refusal is a CwException and nothing is written.
     *
     * @param list<array{listing_id: int, proposal_id: ?int, map_version: int}> $rows
     * @param array{source: string, channel_id?: ?int, band?: ?string, reason?: ?string} $context
     * @return array{source: string, channel_id: ?int, band: ?string, reason: ?string, rows: array<int, array{listing_id: int, proposal_id: ?int, map_version: int}>,
     *               confirm_bands: list<string>, roles: list<string>} rows: by listing id, in listing id order
     */
    private function checked(Caller $caller, string $action, array $rows, array $context): array
    {
        $source = $context['source'];
        $channelId = $context['channel_id'] ?? null;
        $band = $context['band'] ?? null;
        $reason = isset($context['reason']) ? trim((string) $context['reason']) : '';
        $reason = $reason === '' ? null : $reason;
        if (!in_array($source, self::SOURCES, true) || !in_array($action, self::actionsOf($source), true)) {
            throw new CwException('bad_action', 'this list has no such bulk action', 400);
        }
        if ($source === 'review' && ($band === null || !in_array($band, Proposals::BANDS, true))) {
            throw new CwException('bad_request', 'a match-strength list names its strength', 400);
        }
        if ($source === 'new' && $channelId === null) {
            // New Variants is one store's list: a request that names none did not come from it.
            throw new CwException('bad_request', 'New Variants names its store', 400);
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
        if ($reason !== null && !mb_check_encoding($reason, 'UTF-8')) {
            // Refused here: the database would refuse the batch's row (1366) and the person would get an error page.
            throw new CwException('bad_reason', 'reason is not valid UTF-8 text', 400, ['why' => 'encoding']);
        }
        if ($reason !== null && mb_strlen($reason) > DecisionService::MAX_REASON) {
            throw new CwException('bad_reason', 'reason is at most ' . DecisionService::MAX_REASON . ' characters', 400);
        }
        if ($action === 'ignore' && $reason === null) {
            throw new CwException('reason_required', 'ignoring needs a note', 422);
        }
        $byListing = [];
        foreach ($rows as $r) {
            // A number no variant or version can be (beyond its column) would only fail when its row is recorded, in the middle of
            // the batch: the whole request is refused here, before anything is written (every list; review finding of 10 Oct 2026).
            if ((int) $r['listing_id'] < 1 || (int) $r['listing_id'] > self::MAX_UINT || (int) $r['map_version'] < 0 || (int) $r['map_version'] > self::MAX_UINT
                || ($r['proposal_id'] !== null && (int) $r['proposal_id'] < 1)) {
                throw new CwException('bad_request', 'a row names a number that is no variant, suggestion or version', 400);
            }
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
        return ['source' => $source, 'channel_id' => $channelId, 'band' => $band, 'reason' => $reason, 'rows' => $byListing, 'confirm_bands' => $confirmBands,
            'roles' => $roles];
    }

    /**
     * Does $action for the ticked rows, row by row (the class comment). Refuses the whole request, writing nothing, when the
     * action is not one of the list's, the person may not make it, a confirm is asked on a strength the setting leaves out, the
     * rows are none or more than the setting allows, or an ignore has no note.
     *
     * @param list<array{listing_id: int, proposal_id: ?int, map_version: int, sku_id?: int, preview_skipped?: bool}> $rows what the page showed for
     *        each ticked row (By Item: also the item it was shown under, and whether its preview skipped it)
     * @param array{source: string, channel_id?: ?int, band?: ?string, reason?: ?string} $context
     * @return array{batch_id: int, batch: string, done: int, pending: int, skipped: int}
     */
    public function run(Caller $caller, string $action, array $rows, array $context): array
    {
        ['source' => $source, 'channel_id' => $channelId, 'band' => $band, 'reason' => $reason, 'rows' => $byListing, 'confirm_bands' => $confirmBands,
            'roles' => $roles] = $this->checked($caller, $action, $rows, $context);

        $batchId = $this->db->transaction(fn (Db $db): int => $db->insert(
            'INSERT INTO mapping_batch (action, source, channel_id, band, rows_asked, reason, staff_user_id, actor) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$action, $source, $channelId, $source === 'review' ? $band : null, count($byListing), $reason, $caller->staffUserId, $caller->actor],
        ));
        $batch = DecisionService::SCREEN_BATCH_PREFIX . $batchId;
        $skips = $this->prechecks($action, $source, array_values($byListing), $channelId, $confirmBands);
        if ($source === 'item') {
            $skips = $this->itemChecks($action, $rows, $skips, $roles, true);
        }
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
     * What run() would do with these rows, read only (By Item's preview, M56): the same checks of the whole request (checked():
     * refused whole the same way, with the same codes), the same pre-checks of each row (prechecks(), and itemChecks() for By
     * Item), then for each row they let through what DecisionService would answer (predict(): Clues disagree for a matcher, an
     * item joined into another, and the reasons it would wait for a second OK). Nothing is written (no batch, no batch row, no
     * audit row) and nothing is locked. run() checks everything again: a row that changed in between is skipped, never applied
     * to something else.
     *
     * @param list<array{listing_id: int, proposal_id: ?int, map_version: int, sku_id?: int}> $rows
     * @param array{source: string, channel_id?: ?int, band?: ?string, reason?: ?string} $context
     * @return array{rows: array<int, array{outcome: string, code: ?string, detail: array<string, mixed>, needs: list<string>}>, done: int, pending: int, skipped: int}
     *         rows: listing id => what will happen to it, in listing id order (the order run() does them in)
     */
    public function preview(Caller $caller, string $action, array $rows, array $context): array
    {
        ['source' => $source, 'channel_id' => $channelId, 'rows' => $byListing, 'confirm_bands' => $confirmBands, 'roles' => $roles]
            = $this->checked($caller, $action, $rows, $context);
        $skips = $this->prechecks($action, $source, array_values($byListing), $channelId, $confirmBands);
        if ($source === 'item') {
            $skips = $this->itemChecks($action, $rows, $skips, $roles, false);
        }
        $out = [];
        $count = ['done' => 0, 'pending_second' => 0, 'skipped' => 0];
        foreach ($this->predict($action, array_keys($byListing), $skips, $roles) as $lid => $p) {
            $out[$lid] = ['outcome' => $p['outcome'], 'code' => $p['code'], 'detail' => $p['detail'], 'needs' => $p['needs']];
            $count[$p['outcome']]++;
        }
        return ['rows' => $out, 'done' => $count['done'], 'pending' => $count['pending_second'], 'skipped' => $count['skipped']];
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
    private function prechecks(string $action, string $source, array $rows, ?int $channelId, array $confirmBands): array
    {
        $ids = array_map(static fn (array $r): int => $r['listing_id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $seed = $action === 'new_item';
        $listings = [];
        foreach ($this->db->all(
            'SELECT cl.id, cl.channel_id, cl.status, cl.map_version, p.id AS proposal_id, p.band, p.proposed_sku_id, p.proposed_new_item, p.ai_units_per_item, '
            . 'p.flags, p.evidence, ps.code AS proposed_code, pd.id AS pending_id, lp.barcodes, '
            // What the website said of the listing when its features were stored (a load of the store's list); NULL when not known.
            . "JSON_UNQUOTE(JSON_EXTRACT(lp.features, '$.variant_status')) AS site_status, JSON_EXTRACT(lp.features, '$.is_placeholder') AS is_placeholder, "
            // What the open suggestion was made on (its basis row, by its primary key) and what the listing is now.
            . 'lp.identity_hash, pb.proposal_id AS basis_id, pb.identity_hash AS basis_identity'
            // What a new item would be named from (DecisionService::card reads the same four).
            . ($seed ? ', lp.product_title, lp.variant_title, lp.brand, lp.features' : '') . ' '
            . 'FROM channel_listing cl LEFT JOIN match_proposal p ON p.open_listing_id = cl.id LEFT JOIN sku ps ON ps.id = p.proposed_sku_id '
            . 'LEFT JOIN match_decision pd ON pd.pending_listing_id = cl.id '
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id LEFT JOIN match_proposal_basis pb ON pb.proposal_id = p.id '
            . "WHERE cl.id IN ({$in})",
            $ids,
        ) as $l) {
            $listings[(int) $l['id']] = $l;
        }
        $made = $seed ? self::madeFrom($this->db, $ids) : [];
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
                    if ($seed) {
                        $out[$lid] = $this->seedCheck($l, $source, $made[$lid] ?? null);
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
        return $seed ? $this->nameChecks($out, $listings) : $out;
    }

    /**
     * The checks of a row's open suggestion for a confirm or a "not a match", and what DecisionService needs (a new product: seedCheck()).
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
     * A bulk "create as new" of one row, on every list that offers it (M61). A duplicate item splits one thing's stock over two
     * records and is never deleted, and an item nobody sells is never deleted either, so a bulk seed makes an item only from an
     * open suggestion that says "new product" and skips everything doubtful, in this order (the first that applies is the reason
     * recorded):
     *  1. an item was already made from this listing (sku.origin_listing_id, whatever became of the match since: undone, joined,
     *     and whatever suggestion is open now): `made_before`, with that item's CW number. A later computer check compares the
     *     listing with the main store's list only, so it says "new" again of a listing whose own item it cannot see;
     *  2. New Variants only: the open suggestion is a match to an item: `suggested_match`, with that item's CW number;
     *  3. a page heading of the website (features `is_placeholder`): `page_heading`;
     *  4. its state on the website is known and is not Published: `not_published`, with that state. The state is the stored
     *     features' `variant_status`; when they give none (a listing that changed since has no features), what the open
     *     suggestion recorded when the computer checked it (its evidence's `variant_status`). Known from neither: skips nothing;
     *  5. NO open suggestion, whatever came before: `not_checked` on New Variants, `no_suggestion` on the other lists (as before);
     *  6. an open suggestion that does not say "new product": `not_new` on New Variants (with its strength: the computer could
     *     not say what it is), `band_not_allowed` on the other lists (as before);
     *  7. the listing's identity (titles, brand, attributes, barcodes: its profile's identity_hash) is not the one the suggestion
     *     was made on (its match_proposal_basis row): `changed_since_check`. A suggestion with no basis row (made before 0012 and
     *     never proved unchanged) skips nothing here;
     *  8. its barcode is on an item or on another listing: `barcode_elsewhere`.
     * What is left is sent with the suggestion's units, unless its name is taken (nameChecks()). The single page is not touched by
     * any of this: there a person sees the listing's details and may still make its item.
     *
     * @param array<string, mixed> $l the listing with its open proposal (prechecks())
     * @param ?string $madeCode the CW number of the item already made from it (madeFrom())
     * @return array{code: string, detail: array<string, mixed>, request?: array<string, mixed>}
     */
    private function seedCheck(array $l, string $source, ?string $madeCode): array
    {
        $skip = static fn (string $code, array $detail = []): array => ['code' => $code, 'detail' => $detail];
        $mixed = $source === 'new'; // New Variants lists rows of every kind; a match-strength list holds one strength
        if ($madeCode !== null) {
            return $skip('made_before', ['sku' => $madeCode]);
        }
        if ($mixed && $l['proposal_id'] !== null && $l['proposed_sku_id'] !== null) {
            return $skip('suggested_match', ['sku' => (string) $l['proposed_code']]);
        }
        if (self::isPlaceholder($l['is_placeholder'] ?? null)) {
            return $skip('page_heading');
        }
        $ev = json_decode((string) ($l['evidence'] ?? 'null'), true);
        $site = self::siteState($l['site_status'] ?? null, is_array($ev) ? ($ev['variant_status'] ?? null) : null);
        if ($site !== null && $site !== self::PUBLISHED) {
            return $skip('not_published', ['site_status' => $site]);
        }
        if ($l['proposal_id'] === null) {
            return $skip($mixed ? 'not_checked' : 'no_suggestion');
        }
        $band = (string) $l['band'];
        if ($band !== 'New item' || (int) $l['proposed_new_item'] !== 1) {
            return $skip($mixed ? 'not_new' : 'band_not_allowed', ['band' => $band]);
        }
        if (self::changedSinceCheck($l['basis_id'] ?? null, $l['basis_identity'] ?? null, $l['identity_hash'] ?? null)) {
            return $skip('changed_since_check');
        }
        if ($this->barcodeElsewhere((int) $l['id'], self::strings(json_decode((string) ($l['barcodes'] ?? 'null'), true)))) {
            return $skip('barcode_elsewhere');
        }
        return ['code' => '', 'detail' => [], 'request' => ['units_per_item' => $l['ai_units_per_item'] === null ? 1 : max(1, (int) $l['ai_units_per_item'])]];
    }

    /**
     * The name guard of a bulk "create as new" (M61), over the rows seedCheck() would send. The name is the one the item would
     * get (itemName()):
     *  - an item that is not joined into another already has it (the item table's own comparison of names: letter case and
     *    accents do not tell two names apart): `name_elsewhere`, with that item's CW number. This is what stops the second of two
     *    stores' listings of one thing, and a listing renamed to an item's name;
     *  - else two or more of the request's rows have it (compared without letter case): `name_twice`, every row of the group.
     * A row with no name is left to DecisionService, which refuses it (`no_name`).
     *
     * @param array<int, array{code: string, detail: array<string, mixed>, request?: array<string, mixed>}> $out the rows' pre-checks
     * @param array<int, array<string, mixed>> $listings prechecks()' rows
     * @return array<int, array{code: string, detail: array<string, mixed>, request?: array<string, mixed>}>
     */
    private function nameChecks(array $out, array $listings): array
    {
        $names = [];
        foreach ($out as $lid => $pre) {
            $name = $pre['code'] === '' && isset($listings[$lid]) ? self::itemName($listings[$lid]) : null;
            if ($name !== null) {
                $names[$lid] = $name;
            }
        }
        if ($names === []) {
            return $out;
        }
        $taken = $this->namesTaken(array_values(array_unique(array_values($names))));
        $groups = [];
        foreach ($names as $lid => $name) {
            if (isset($taken[$name])) {
                $out[$lid] = ['code' => 'name_elsewhere', 'detail' => ['sku' => $taken[$name], 'name' => $name]];
                continue;
            }
            $groups[mb_strtolower($name, 'UTF-8')][] = $lid;
        }
        foreach ($groups as $lids) {
            foreach (count($lids) > 1 ? $lids : [] as $lid) {
                $out[$lid] = ['code' => 'name_twice', 'detail' => ['name' => $names[$lid], 'rows' => count($lids)]];
            }
        }
        return $out;
    }

    /**
     * The name the item made from a listing would get: DecisionService::cardFrom over the same four columns card() reads (the
     * option's title, else the title, else the features' title; white space collapsed and trimmed, at most 255 characters: what
     * mint() stores). Null when it has none.
     *
     * @param array<string, mixed> $l prechecks()' row of a "create as new"
     */
    private static function itemName(array $l): ?string
    {
        try {
            return (string) DecisionService::cardFrom($l, isset($l['features']) ? (array) json_decode((string) $l['features'], true) : [])['name'];
        } catch (CwException) {
            return null;
        }
    }

    /**
     * Of these names, the ones an item that is not joined into another already has, each with the CW number of one such item.
     * One look-up per name on the index of 0026 (sku.name), NAMES_A_READ of them in one statement; the hint names that index (a
     * hint naming an index that is not there yet is ignored: the code may run a moment before the migration).
     *
     * @param list<string> $names
     * @return array<string, string> name => CW number
     */
    private function namesTaken(array $names): array
    {
        $out = [];
        foreach (array_chunk($names, self::NAMES_A_READ) as $chunk) {
            $parts = [];
            foreach (array_keys($chunk) as $i) {
                $parts[] = '(SELECT /*+ INDEX(s ix_sku_name) */ ' . $i . ' AS i, MIN(s.code) AS code FROM sku s WHERE s.name = ? AND s.merged_into_sku_id IS NULL)';
            }
            foreach ($this->db->all(implode(' UNION ALL ', $parts), $chunk) as $r) {
                if ($r['code'] !== null) {
                    $out[$chunk[(int) $r['i']]] = (string) $r['code'];
                }
            }
        }
        return $out;
    }

    /**
     * The items already made from these listings (sku.origin_listing_id, by its index of 0004, named in a hint so that a small
     * table is read the same way): for each, the CW number of the first one. An item stays the listing's own whatever became of
     * the match (undone, the item joined into another).
     *
     * @param list<int> $listingIds
     * @return array<int, string> listing id => CW number
     */
    public static function madeFrom(Db $db, array $listingIds): array
    {
        $first = [];
        foreach (array_chunk($listingIds, 500) as $chunk) {
            foreach ($db->all('SELECT /*+ INDEX(s ix_sku_origin_listing) */ s.origin_listing_id, s.id, s.code FROM sku s WHERE s.origin_listing_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $s) {
                $lid = (int) $s['origin_listing_id'];
                if (!isset($first[$lid]) || (int) $s['id'] < $first[$lid][0]) {
                    $first[$lid] = [(int) $s['id'], (string) $s['code']];
                }
            }
        }
        return array_map(static fn (array $f): string => $f[1], $first);
    }

    /** Whether the stored features call a listing a page heading (`is_placeholder`, as JSON_EXTRACT gives it: true, or 1). */
    public static function isPlaceholder(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === 'true' || $v === '1';
    }

    /** The website's state of a listing as one source gives it (a text), or null when it gives none. */
    public static function siteStatus(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' && $v !== 'null' ? mb_substr(trim($v), 0, 64) : null;
    }

    /**
     * The website's state of a listing: what its stored features say (`variant_status`), else what its open suggestion recorded
     * when the computer checked it (its evidence's `variant_status`), else null. The list and the seed both ask here, so a row
     * and its skip cannot disagree.
     */
    public static function siteState(mixed $fromFeatures, mixed $fromCheck): ?string
    {
        return self::siteStatus($fromFeatures) ?? self::siteStatus($fromCheck);
    }

    /**
     * Whether a listing is no longer what its open suggestion was made on: the suggestion has a basis row (match_proposal_basis,
     * $basisId) and the identity recorded there is not the listing profile's identity now (titles, brand, attributes, barcodes;
     * no profile then or now reads as null). No basis row: not known, so false. The list and the seed both ask here.
     */
    public static function changedSinceCheck(mixed $basisId, mixed $basisIdentity, mixed $identity): bool
    {
        return $basisId !== null && (is_string($basisIdentity) ? $basisIdentity : null) !== (is_string($identity) ? $identity : null);
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
     * forms as Ui\Queries::barcodeElsewhere (Gtin::forms: the code as stored, its key, and the key padded with zeros up to 14
     * digits, so "5056168800073" also finds a record that holds "05056168800073"), every barcode of the listing, each look-up an
     * exact match on an index (sku_barcode's primary key; the multi-valued index of 0005).
     *
     * @param list<string> $barcodes
     */
    private function barcodeElsewhere(int $listingId, array $barcodes): bool
    {
        foreach (array_chunk(\CW\Matching\Gtin::forms($barcodes), 200) as $codes) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            if ($this->db->value("SELECT 1 FROM sku_barcode WHERE barcode IN ({$in}) LIMIT 1", $codes) !== null
                || $this->db->value('SELECT 1 FROM listing_profile lp WHERE JSON_OVERLAPS(lp.barcodes, CAST(? AS JSON)) AND lp.listing_id <> ? LIMIT 1',
                    [json_encode($codes, JSON_THROW_ON_ERROR), $listingId]) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * By Item's own checks of its rows (M56), after prechecks(): the suggestion a row names must still propose the item the row was
     * shown under (`sku_id`; another item now: `suggestion_changed`, so a row is never applied to something else); a confirm then
     * leaves the doubtful ones to a person (confirmScope(), M59). On the apply ($applying), a row its preview skipped
     * (`preview_skipped`) is never acted on, whatever it looks like now: it keeps the reason it has now (predict()), or is skipped as
     * changed when it has none any more (the person did not say yes to it).
     *
     * @param list<array<string, mixed>> $rows as given to run() / preview()
     * @param array<int, array{code: string, detail: array<string, mixed>, request?: array<string, mixed>}> $skips prechecks()
     * @param list<string> $roles
     * @return array<int, array{code: string, detail: array<string, mixed>, request?: array<string, mixed>}>
     */
    private function itemChecks(string $action, array $rows, array $skips, array $roles, bool $applying): array
    {
        $expect = [];
        $held = [];
        foreach ($rows as $r) {
            $lid = (int) $r['listing_id'];
            if (isset($r['sku_id'])) {
                $expect[$lid] = (int) $r['sku_id'];
            }
            if ($applying && ($r['preview_skipped'] ?? false) === true) {
                $held[$lid] = true;
            }
        }
        $check = array_values(array_filter(array_keys($expect), static fn (int $lid): bool => ($skips[$lid]['code'] ?? 'unknown_listing') === ''));
        if ($check !== []) {
            $now = [];
            $store = [];
            foreach ($this->db->all('SELECT p.open_listing_id, p.proposed_sku_id, cl.channel_id FROM match_proposal p JOIN channel_listing cl ON cl.id = p.open_listing_id '
                . 'WHERE p.open_listing_id IN (' . implode(',', array_fill(0, count($check), '?')) . ')', $check) as $p) {
                $now[(int) $p['open_listing_id']] = $p['proposed_sku_id'] === null ? null : (int) $p['proposed_sku_id'];
                $store[(int) $p['open_listing_id']] = (int) $p['channel_id'];
            }
            $confirm = [];
            foreach ($check as $lid) {
                if (($now[$lid] ?? null) !== $expect[$lid]) {
                    $skips[$lid] = ['code' => 'suggestion_changed', 'detail' => []];
                } elseif ($action === 'link') {
                    $confirm[$lid] = ['sku' => $expect[$lid], 'store' => $store[$lid]];
                }
            }
            foreach ($this->confirmScope($confirm) as $lid => $skip) {
                $skips[$lid] = $skip;
            }
        }
        if ($held !== []) {
            $ids = array_keys($held);
            sort($ids);
            foreach ($this->predict($action, $ids, $skips, $roles) as $lid => $p) {
                $skips[$lid] = $p['outcome'] === 'skipped' ? ['code' => (string) $p['code'], 'detail' => $p['detail']]
                    : ['code' => 'changed', 'detail' => $p['status'] === null ? [] : ['status' => $p['status']]];
            }
        }
        return $skips;
    }

    /**
     * What a bulk confirm of By Item leaves to a person, one at a time (M59; the owner's "skip anything doubtful, finish the rest, show
     * the skipped at the end"), read for all rows in a few reads, the same for preview() and run():
     *  - `rejected_before`: the pair was marked "not a match" before (rejectedBefore()). One at a time such a match waits for a
     *    second person; in bulk it is not sent there unread.
     *  - `item_on_store`: the item already has a variant matched on that store, or a match waiting there for a second OK. A second
     *    variant of one store for one item is decided on its page.
     *  - `several_suggested`: more than one variant of that store is suggested for the item. Confirming them together would make the
     *    second variant the rule above keeps for a person, and which one is right is exactly the doubt: all of them are left.
     * "Not a match" and "Send back for matching" take every open suggestion, as before.
     *
     * @param array<int, array{sku: int, store: int}> $rows listing id => the item it would be matched to and the store it is on
     * @return array<int, array{code: string, detail: array<string, mixed>}> the rows that are skipped, by listing id
     */
    private function confirmScope(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $rejected = self::rejectedBefore($this->db, array_map(static fn (array $r): int => $r['sku'], $rows));
        $skus = array_values(array_unique(array_map(static fn (array $r): int => $r['sku'], $rows)));
        $in = implode(',', array_fill(0, count($skus), '?'));
        $has = [];
        // (the two indexes By Item's cells read: channel_listing (sku_id, channel_id) alone, and the open suggestions by item)
        foreach ($this->db->all("SELECT DISTINCT cl.sku_id, cl.channel_id FROM channel_listing cl WHERE cl.sku_id IN ({$in})", $skus) as $r) {
            $has[(int) $r['sku_id'] . ':' . (int) $r['channel_id']] = true;
        }
        // (the decisions that wait, by their own unique index: a few rows, whatever the size of match_decision)
        foreach ($this->db->all('SELECT /*+ INDEX(d uq_match_decision_pending) */ d.sku_id, cl.channel_id FROM match_decision d '
            . "JOIN channel_listing cl ON cl.id = d.pending_listing_id WHERE d.pending_listing_id IS NOT NULL AND d.action = 'link' AND d.sku_id IN ({$in})", $skus) as $r) {
            $has[(int) $r['sku_id'] . ':' . (int) $r['channel_id']] = true;
        }
        $suggested = [];
        foreach ($this->db->all('SELECT /*+ INDEX(p ix_match_proposal_open_sku) */ p.proposed_sku_id AS sku_id, cl.channel_id, COUNT(*) AS n FROM match_proposal p '
            . "JOIN channel_listing cl ON cl.id = p.listing_id WHERE p.status = 'open' AND p.proposed_sku_id IN ({$in}) AND cl.status IN ('unmapped', 'suggested') "
            . 'AND NOT EXISTS (SELECT 1 FROM match_decision pw WHERE pw.pending_listing_id = cl.id) GROUP BY p.proposed_sku_id, cl.channel_id', $skus) as $r) {
            $suggested[(int) $r['sku_id'] . ':' . (int) $r['channel_id']] = (int) $r['n'];
        }
        $out = [];
        foreach ($rows as $lid => $r) {
            $key = $r['sku'] . ':' . $r['store'];
            if (isset($rejected[$lid])) {
                $out[$lid] = ['code' => 'rejected_before', 'detail' => []];
            } elseif (isset($has[$key])) {
                $out[$lid] = ['code' => 'item_on_store', 'detail' => ['sku_id' => $r['sku'], 'channel_id' => $r['store']]];
            } elseif (($suggested[$key] ?? 0) > 1) {
                $out[$lid] = ['code' => 'several_suggested', 'detail' => ['sku_id' => $r['sku'], 'channel_id' => $r['store'], 'variants' => $suggested[$key]]];
            }
        }
        // The words of a skip name the item and the store: read once, for the rows that are skipped for them.
        $itemIds = [];
        $storeIds = [];
        foreach ($out as $skip) {
            if (isset($skip['detail']['sku_id'])) {
                $itemIds[(int) $skip['detail']['sku_id']] = true;
                $storeIds[(int) $skip['detail']['channel_id']] = true;
            }
        }
        if ($itemIds !== []) {
            $items = [];
            foreach ($this->db->all('SELECT id, code, name FROM sku WHERE id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')', array_keys($itemIds)) as $s) {
                $items[(int) $s['id']] = trim((string) $s['name']) !== '' ? mb_substr(trim((string) $s['name']), 0, 200) : (string) $s['code'];
            }
            $stores = [];
            foreach ($this->db->all('SELECT id, name FROM channel WHERE id IN (' . implode(',', array_fill(0, count($storeIds), '?')) . ')', array_keys($storeIds)) as $c) {
                $stores[(int) $c['id']] = (string) $c['name'];
            }
            foreach ($out as $lid => $skip) {
                if (isset($skip['detail']['sku_id'])) {
                    $out[$lid]['detail'] += ['item' => $items[(int) $skip['detail']['sku_id']] ?? '', 'store' => $stores[(int) $skip['detail']['channel_id']] ?? ''];
                }
            }
        }
        return $out;
    }

    /**
     * Which of these variant / item pairs were marked "not a match" before (M59): the variant has a match_reject for the item itself
     * or for an item that was joined into it since. The same answer as DecisionService::rejectedAs() (which reads the item's family
     * downwards for one pair); here the rejected items are followed up their joins, so a whole page of suggestions is answered in
     * two or three reads: match_reject by its unique index (listing_id, sku_id), then the few rejected items by their primary key.
     * By Item's cells ("Marked not a match"), its confirm (confirmScope()) and predict() all ask here.
     *
     * @param array<int, int> $pairs listing id => item id
     * @return array<int, true> the listing ids of the pairs that were marked wrong before
     */
    public static function rejectedBefore(Db $db, array $pairs): array
    {
        $marked = [];
        foreach (array_chunk(array_keys($pairs), 500) as $chunk) {
            foreach ($db->all('SELECT listing_id, sku_id FROM match_reject WHERE listing_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $marked[(int) $r['listing_id']][] = (int) $r['sku_id'];
            }
        }
        if ($marked === []) {
            return [];
        }
        // What each rejected item was joined into, step by step (only those that are not the pair's own item need asking).
        $into = [];
        $ask = [];
        foreach ($marked as $lid => $skus) {
            foreach ($skus as $sku) {
                if ($sku !== $pairs[$lid]) {
                    $ask[$sku] = true;
                }
            }
        }
        for ($step = 0; $ask !== [] && $step < self::MAX_JOINS; $step++) {
            $next = [];
            foreach (array_chunk(array_keys($ask), 500) as $chunk) {
                foreach ($db->all('SELECT id, merged_into_sku_id FROM sku WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $s) {
                    $to = $s['merged_into_sku_id'] === null ? null : (int) $s['merged_into_sku_id'];
                    $into[(int) $s['id']] = $to;
                    if ($to !== null && !array_key_exists($to, $into)) {
                        $next[$to] = true;
                    }
                }
            }
            $ask = $next;
        }
        $out = [];
        foreach ($marked as $lid => $skus) {
            foreach ($skus as $sku) {
                for ($at = $sku, $step = 0; $at !== null && $step <= self::MAX_JOINS; $at = $into[$at] ?? null, $step++) {
                    if ($at === $pairs[$lid]) {
                        $out[$lid] = true;
                        break 2;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * What DecisionService would answer for each row the pre-checks let through (M57), read only, from a few reads of all rows: the
     * rules its decide() applies to a website product that waits for a match, in its order. Clues disagree is a matching lead's
     * (`lead_only`); a confirm or a "not a match" of an item that is not there is refused (`no_product`), a confirm of an item joined
     * into another too (`product_joined`), and units per sale above DecisionService::MAX_UNITS (`refused`, bad_units); a confirm
     * waits for a second matching lead when the item is protected (`protected_sku`), when 1 sale is not 1 item while that approval
     * rule is on (`units_per_item`), when the pair was marked wrong before (`previously_rejected`: rejectedBefore(); By Item's
     * confirm has skipped such a row by then, M59) and while the bulk switch is on (`bulk`). Every other row is done. A row with a
     * skip keeps it. The tests hold this to DecisionService's own answers (BulkDecisionsTest).
     *
     * @param list<int> $ids listing ids, in the order to answer them
     * @param array<int, array{code: string, detail: array<string, mixed>, request?: array<string, mixed>}> $skips prechecks()
     * @param list<string> $roles
     * @return array<int, array{outcome: string, code: ?string, detail: array<string, mixed>, needs: list<string>, status: ?string}>
     */
    private function predict(string $action, array $ids, array $skips, array $roles): array
    {
        $open = array_values(array_filter($ids, static fn (int $lid): bool => ($skips[$lid]['code'] ?? 'unknown_listing') === ''));
        $facts = [];
        $skus = [];
        $rejected = [];
        if ($open !== []) {
            $in = implode(',', array_fill(0, count($open), '?'));
            foreach ($this->db->all("SELECT cl.id, cl.status, p.band FROM channel_listing cl LEFT JOIN match_proposal p ON p.open_listing_id = cl.id WHERE cl.id IN ({$in})",
                $open) as $f) {
                $facts[(int) $f['id']] = ['status' => (string) $f['status'], 'band' => $f['band'] === null ? null : (string) $f['band']];
            }
            $skuIds = [];
            foreach ($open as $lid) {
                if (isset($skips[$lid]['request']['sku_id'])) {
                    $skuIds[(int) $skips[$lid]['request']['sku_id']] = true;
                }
            }
            if ($skuIds !== []) {
                foreach ($this->db->all('SELECT id, sell_policy, merged_into_sku_id FROM sku WHERE id IN (' . implode(',', array_fill(0, count($skuIds), '?')) . ')',
                    array_keys($skuIds)) as $s) {
                    $skus[(int) $s['id']] = ['policy' => (string) $s['sell_policy'], 'merged' => $s['merged_into_sku_id'] !== null];
                }
            }
            if ($action === 'link') {
                $pairs = [];
                foreach ($open as $lid) {
                    if (isset($skips[$lid]['request']['sku_id'])) {
                        $pairs[$lid] = (int) $skips[$lid]['request']['sku_id'];
                    }
                }
                $rejected = self::rejectedBefore($this->db, $pairs);
            }
        }
        $lead = Permissions::can($roles, 'mapping.approve');
        $multiple = ApprovalRules::on($this->db, 'approvals.match_multiple');
        $bulk = self::secondOk($this->db);
        $out = [];
        foreach ($ids as $lid) {
            $pre = $skips[$lid] ?? ['code' => 'unknown_listing', 'detail' => []];
            $f = $facts[$lid] ?? null;
            $status = $f['status'] ?? null;
            $skip = static fn (string $code, array $detail = []): array => ['outcome' => 'skipped', 'code' => $code, 'detail' => $detail, 'needs' => [], 'status' => $status];
            if ($pre['code'] !== '' || $f === null) {
                $out[$lid] = $pre['code'] !== '' ? $skip($pre['code'], $pre['detail']) : $skip('unknown_listing');
                continue;
            }
            if ($f['band'] === 'Conflict' && !$lead) {
                $out[$lid] = $skip('lead_only');
                continue;
            }
            $needs = [];
            if ($action === 'link' || $action === 'reject') {
                $s = $skus[(int) ($pre['request']['sku_id'] ?? 0)] ?? null;
                $units = (int) ($pre['request']['units_per_item'] ?? 1);
                if ($s === null) {
                    $out[$lid] = $skip('no_product');
                    continue;
                }
                if ($action === 'link' && $s['merged']) {
                    $out[$lid] = $skip('product_joined');
                    continue;
                }
                if ($action === 'link') {
                    if ($s['policy'] !== 'legacy') {
                        $needs[] = 'protected_sku';
                    }
                    if ($units !== 1 && $multiple) {
                        $needs[] = 'units_per_item';
                    }
                    if (isset($rejected[$lid])) {
                        $needs[] = 'previously_rejected';
                    }
                    if ($bulk) {
                        $needs[] = 'bulk';
                    }
                    if ($units > DecisionService::MAX_UNITS) {
                        $out[$lid] = $skip('refused', ['code' => 'bad_units', 'message' => 'units_per_item must be 1..' . DecisionService::MAX_UNITS]);
                        continue;
                    }
                }
            }
            $out[$lid] = ['outcome' => $needs === [] ? 'done' : 'pending_second', 'code' => null, 'detail' => $needs === [] ? [] : ['needs_second' => $needs],
                'needs' => $needs, 'status' => $status];
        }
        return $out;
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
