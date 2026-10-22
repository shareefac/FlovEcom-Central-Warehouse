<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Admin\ApprovalRules;
use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Matching\Gtin;
use CW\OpResult;
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
 * a listing held back from the bulk confirm (M30) or in a spot check that is not complete (M28) is never bulk-actioned, and neither is
 * one whose open suggestion was found by the check on arrival while that is decided one at a time (M64: `on_arrival`) (the one
 * read of that is OneAtATime, M63: the lists of variants mark their rows from the same read, so such a row has no tick box), and a
 * confirm also skips a vetoed or flagged suggestion, a listing of a failed spot check's population, and one whose bulk link was
 * undone before. A bulk "create as new" (any list: seedCheck(), M61) makes an item only from a listing whose open suggestion says
 * "new product", and skips everything that could make a second item for one thing or an item for nothing: a listing an item was
 * already made from (`made_before`), a page heading of the website (`page_heading`), one the website does not publish
 * (`not_published`: the variant, or its product), one that changed on the website after the computer checked it
 * (`changed_since_check`), one whose barcode is elsewhere (`barcode_elsewhere`; the single page does not preselect it either), one
 * whose state on the website the warehouse does not know, or knows only from a stored state that is too old (`state_not_known`,
 * M66: the state is read by CW\Mapping\SiteState, the stored columns first; what the features and the suggestion's record say
 * against a variant counts beside a stored state too), one whose name an item already has
 * (`name_elsewhere`) or another ticked row shares (`name_twice`). On New Variants, where the rows are of every kind, also one with
 * no open suggestion (`not_checked`), one whose suggestion is a match to an item (`suggested_match`), one matched to a variant of
 * the main store that has no item yet (`match_no_item`) and one the computer could not decide (`not_new`): a person opens each
 * and decides. The hold and the spot check are read again under the decision's listing lock (a hold written meanwhile rolls the
 * row back, as in KeyBulk), and the name of a "create as new" is read again just before its decision (one()). "Skip anything
 * doubtful, finish the rest": each skipped row is recorded with why.
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
 *
 * Store Products' "Create new warehouse products for these" (the list context `store_new`, docs/decisions.md NF1-NF8; the owner, 22
 * Oct 2026: "tick a whole family, then create new products for these in one go"): a matching lead ticks website products the
 * warehouse has no product for (no open suggestion, or one that says "new product"), sees preview() first, and run() makes one item
 * per row through DecisionService `new_item`, from the row's own details exactly as the single "Create a new product" makes it, with
 * 1 sale = 1. Unlike the seed above it does not ask for the computer's "new": the person looked at the family. What it skips
 * instead (newChecks()): what no bulk action takes (held back, a spot check, found on arrival), a landing page, matched, ignored,
 * waiting for a second OK, a suggestion to match instead, an item made from it before, known not to be sold on the website, no
 * name, a barcode an item has, a name an item has (the same words in any order), a name a new product waiting for a second OK has,
 * and two ticked rows that would make one product (the same words, or a barcode). A form key makes one batch however often the
 * apply is sent (openBatch()).
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
    /**
     * Store Products' "Create new warehouse products for these" (NF1): its list context, which preview() and run() take. A batch of it is
     * recorded as one of Store Products (mapping_batch.source `store`, action `new_item`: no other action of that list makes items).
     */
    public const STORE_NEW = 'store_new';
    public const STORE_NEW_ACTIONS = ['new_item'];
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
    public const SKIPS = ['unknown_listing', 'other_store', 'changed', 'suggestion_changed', 'pending_second', 'held', 'held_meanwhile', 'in_spot_check', 'on_arrival',
        'spot_check_failed', 'bulk_undone', 'band_not_allowed', 'vetoed', 'flagged', 'no_product', 'barcode_elsewhere', 'already_rejected', 'not_ignored',
        'already_ignored', 'linked', 'not_waiting', 'no_suggestion', 'lead_only', 'not_allowed', 'product_joined', 'no_name', 'refused',
        // By Item's confirm only (M59, confirmScope()):
        'rejected_before', 'item_on_store', 'several_suggested',
        // Store Products' "Create new warehouse products for these" only (NF2, newChecks()):
        'landing_page', 'match_instead', 'barcode_on_item', 'barcode_twice', 'name_waiting', 'card_changed',
        // A bulk "create as new" only (M61, seedCheck() and nameChecks(); `suggested_match`, `not_checked`, `not_new` and `match_no_item` on
        // New Variants only):
        // (`state_not_known` since the website state is stored, M66)
        'suggested_match', 'not_checked', 'page_heading', 'not_published', 'state_not_known', 'made_before', 'not_new', 'changed_since_check', 'name_elsewhere', 'name_twice',
        'match_no_item'];
    /** The strengths that say "it is that one" (a match), as the computer check writes them: matchNoItem(). */
    private const MATCH_BANDS = ['Key', 'Check'];
    /** The website's own state of a listing that is on sale (SiteState: the stored state, the features and the exports all say it so). */
    public const PUBLISHED = SiteState::PUBLISHED;
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
    /** @var array<string, ?int> "store:product number" => the published variants of that product (SiteState::publishedSiblings), for one request */
    private array $siblings = [];
    /** How old a stored website state may be, in days (the setting, read once a request). */
    private ?int $stateMaxDays = null;
    /** The largest item id when the names of the items were read (newChecks()): one() reads the items made since, by their key. */
    private int $skuHigh = 0;
    /** Where the apply of Store Products' new items is sent (the idempotency record names it). */
    private const STORE_NEW_PATH = '/ui/review/store/new-items/apply';

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
            self::STORE_NEW => self::STORE_NEW_ACTIONS,
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
        if ($source === self::STORE_NEW) {
            // NF3: a matching lead's (the stricter of "who makes a new product" and "who acts on ticked rows": no computer check vouches
            // for these rows, as none does for the seed mint of M7).
            return $action === 'new_item' && Permissions::can($roles, 'mapping.decide') && Permissions::can($roles, 'mapping.approve');
        }
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
        if ((!in_array($source, self::SOURCES, true) && $source !== self::STORE_NEW) || !in_array($action, self::actionsOf($source), true)) {
            throw new CwException('bad_action', 'this list has no such bulk action', 400);
        }
        if ($source === self::STORE_NEW && $channelId === null) {
            throw new CwException('bad_request', 'Store Products names its store', 400);
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
        if ($source === self::STORE_NEW && !Permissions::can($roles, 'mapping.approve')) {
            throw new CwException('lead_required', 'a matching lead creates new items for ticked rows', 403);
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

        [$batchId, $fresh] = $this->openBatch($caller, $action, $source, $channelId, $band, $byListing, $reason, isset($context['form_key']) ? (string) $context['form_key'] : null);
        $batch = DecisionService::SCREEN_BATCH_PREFIX . $batchId;
        if (!$fresh) {
            // The same form sent again (NF4): its batch, as recorded; nothing is done twice.
            $n = ['done' => 0, 'pending_second' => 0, 'skipped' => 0];
            foreach ($this->db->all('SELECT outcome, COUNT(*) AS n FROM mapping_batch_row WHERE batch_id = ? GROUP BY outcome', [$batchId]) as $o) {
                $n[(string) $o['outcome']] = (int) $o['n'];
            }
            return ['batch_id' => $batchId, 'batch' => $batch, 'done' => $n['done'], 'pending' => $n['pending_second'], 'skipped' => $n['skipped'], 'replayed' => true];
        }
        $skips = $this->prechecks($action, $source, array_values($byListing), $channelId, $confirmBands);
        if ($source === 'item') {
            $skips = $this->itemChecks($action, $rows, $skips, $roles, true);
        }
        if ($source === self::STORE_NEW) {
            $skips = $this->newApplyChecks($action, $rows, $skips, $roles, true);
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
        return ['batch_id' => $batchId, 'batch' => $batch, 'done' => $count['done'], 'pending' => $count['pending_second'], 'skipped' => $count['skipped'], 'replayed' => false];
    }

    /**
     * The batch's row (mapping_batch), in its own transaction. With a form key (Store Products' new items, NF4: the preview draws one
     * and its apply sends it) through Idempotency, under the key `bulk:<person>:<form key>`: the same form sent again (a double click,
     * Back and send) gets the batch it made the first time and runs nothing ([id, false]); the same key with other rows is refused
     * (422 idempotency_key_reused), and a malformed key too (400 bad_form_key), before anything is written. The rows of a batch are
     * each their own transaction (M52), so only its first row is inside the idempotent unit: a request that stopped half-way leaves a
     * batch with fewer rows than ticked, which its page says (M47), and sending it again does not do the rest.
     *
     * @param array<int, array{listing_id: int, proposal_id: ?int, map_version: int}> $byListing
     * @return array{0: int, 1: bool} the batch id, and whether this request made it
     */
    private function openBatch(Caller $caller, string $action, string $source, ?int $channelId, ?string $band, array $byListing, ?string $reason, ?string $formKey): array
    {
        $insert = fn (Db $db): int => $db->insert(
            'INSERT INTO mapping_batch (action, source, channel_id, band, rows_asked, reason, staff_user_id, actor) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$action, $source === self::STORE_NEW ? 'store' : $source, $channelId, $source === 'review' ? $band : null, count($byListing), $reason,
                $caller->staffUserId, $caller->actor],
        );
        if ($formKey === null) {
            return [$this->db->transaction($insert), true];
        }
        if (preg_match('/^[0-9a-f]{32}$/D', $formKey) !== 1) {
            throw new CwException('bad_form_key', 'the form key is 32 hexadecimal characters', 400);
        }
        $fresh = false;
        $request = ['action' => $action, 'source' => $source, 'channel_id' => $channelId,
            'rows' => array_values(array_map(static fn (array $r): array => [$r['listing_id'], $r['proposal_id'], $r['map_version']], $byListing))];
        $r = (new Idempotency($this->db))->run($caller, 'bulk:' . $caller->staffUserId . ':' . $formKey, 'mapping.bulk_start', self::STORE_NEW_PATH, $request,
            'mapping_batch', null, static function (Db $db) use (&$fresh, $insert): OpResult {
                $fresh = true;
                return new OpResult(201, ['batch_id' => $insert($db)]);
            });
        if (($r->body['error'] ?? null) === 'idempotency_key_reused') {
            throw new CwException('idempotency_key_reused', 'this form was already sent with other rows', 422);
        }
        $id = (int) ($r->body['batch_id'] ?? 0);
        if ($id < 1) {
            throw new \RuntimeException('the stored answer of a bulk form names no batch');
        }
        return [$id, $fresh];
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
        if ($source === self::STORE_NEW) {
            $skips = $this->newApplyChecks($action, $rows, $skips, $roles, false);
        }
        $out = [];
        $count = ['done' => 0, 'pending_second' => 0, 'skipped' => 0];
        foreach ($this->predict($action, array_keys($byListing), $skips, $roles) as $lid => $p) {
            // (Store Products' new items: the new product's details as they would be made, NF2; null when there is no name)
            $out[$lid] = ['outcome' => $p['outcome'], 'code' => $p['code'], 'detail' => $p['detail'], 'needs' => $p['needs'], 'card' => $skips[$lid]['card'] ?? null];
            $count[$p['outcome']]++;
        }
        return ['rows' => $out, 'done' => $count['done'], 'pending' => $count['pending_second'], 'skipped' => $count['skipped']];
    }

    /**
     * One row: a skip found before, or the decision in its own transaction with its batch row; a refusal is recorded as a skip.
     * A "create as new" reads its name again first (M61): the pre-checks read the name guard once, before the first row, and an
     * item of that name made since is found here by one look-up on the index of 0026, and the row is skipped as `name_elsewhere`
     * with that item's CW number. "Since" is an earlier row of this run whose name differs by an accent only (the item table
     * calls the two one name; nameChecks() does not), another person's create, or an approval in Second approval. It is a read,
     * not a lock: two creates of one name decided in the same instant can still both make an item (written down in M61).
     *
     * @param array{listing_id: int, proposal_id: ?int, map_version: int} $row
     * @param array{code: string, detail: array<string, mixed>, request?: array<string, mixed>, name?: string}|null $pre
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
            $name = $action === 'new_item' && is_string($pre['name'] ?? null) ? $pre['name'] : null;
            $d = $this->db->transaction(function (Db $db) use ($caller, $req, $lid, $row, $seq, $batchId, $name): array {
                $taken = $name === null ? [] : $this->namesTaken([$name]);
                if ($taken === [] && $name !== null && is_string($pre['words'] ?? null)) {
                    // Store Products' new items (NF2): an item of the same words made since the names were read.
                    $since = $this->wordsSince((string) $pre['words']);
                    $taken = $since === null ? [] : [$since];
                }
                if ($taken !== []) {
                    throw new CwException('bulk_skip', 'an item of this name was made meanwhile', 409,
                        ['skip' => 'name_elsewhere', 'detail' => ['sku' => (string) reset($taken), 'name' => $name]]);
                }
                $d = $this->ds->decide($caller, $req);
                // Under the decision's lock on the listing: a hold (M30) or a spot check written since the checks above stops it
                // (the same read as before the row was sent, OneAtATime; a hold found only now is `held_meanwhile`).
                $now = OneAtATime::of($db, [$lid])[$lid]['code'] ?? null;
                if ($now === OneAtATime::HELD) {
                    throw new CwException('bulk_skip', 'held meanwhile', 409, ['skip' => 'held_meanwhile']);
                }
                if ($now === OneAtATime::SPOT) {
                    throw new CwException('bulk_skip', 'in a spot check', 409, ['skip' => 'in_spot_check']);
                }
                if ($now === OneAtATime::ARRIVAL || ($row['proposal_id'] !== null && OneAtATime::arrivalSuggestion($db, $row['proposal_id']))) {
                    // One of the check on arrival's that go one at a time now. Asked of the suggestion the row named, whatever the
                    // decision has just made of it: a confirm, a create, an ignore and a "send back" close it, and of() reads only
                    // an open one, so a setting switched off since the checks above would not be seen (M64).
                    throw new CwException('bulk_skip', 'found by the check on arrival', 409, ['skip' => 'on_arrival']);
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
            if ($e->errorCode === 'spot_check_other') {
                // A spot check drawn since the checks above (DecisionService refuses anyone but its owner, docs/decisions.md DG1): the
                // row is skipped as those checks would have skipped it, with the spot check's name.
                $this->record($batchId, $seq, $row, 'skipped', OneAtATime::SPOT, ['sample' => (string) ($e->detail['sample'] ?? '')], null);
                return ['skipped', OneAtATime::SPOT];
            }
            $code = $e->errorCode === 'bulk_skip' ? (string) $e->detail['skip'] : (self::REFUSAL_SKIP[$e->errorCode] ?? 'refused');
            $detail = $e->errorCode === 'bulk_skip' ? (is_array($e->detail['detail'] ?? null) ? $e->detail['detail'] : [])
                : ['code' => $e->errorCode, 'message' => mb_substr($e->getMessage(), 0, 300)];
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
        if ($source === self::STORE_NEW) {
            return $this->newChecks($rows, (int) $channelId);
        }
        $ids = array_map(static fn (array $r): int => $r['listing_id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $seed = $action === 'new_item';
        $listings = [];
        foreach ($this->db->all(
            'SELECT cl.id, cl.channel_id, cl.status, cl.map_version, p.id AS proposal_id, p.band, p.proposed_sku_id, p.proposed_new_item, p.ai_units_per_item, '
            . 'p.flags, p.evidence, ps.code AS proposed_code, pd.id AS pending_id, lp.barcodes, '
            // The website state of the listing (M66; SiteState::read): its stored state (the columns of 0028, with their date), and
            // what the website said of it when its features were stored by a matching run; each NULL when not known.
            . 'lp.site_variant_status, lp.site_product_status, lp.site_product_type, lp.site_product_id, lp.site_is_landing, lp.site_is_default, lp.site_state_at, '
            . "JSON_UNQUOTE(JSON_EXTRACT(lp.features, '$.variant_status')) AS features_status, JSON_EXTRACT(lp.features, '$.is_placeholder') AS is_placeholder, "
            // What the open suggestion was made on (its basis row, by its primary key) and what the listing is now.
            . 'lp.identity_hash, pb.proposal_id AS basis_id, pb.identity_hash AS basis_identity'
            // What a new item would be named from (DecisionService::card reads the same four), and the units sold in the month
            // (the page-heading rule reads them, with the titles and the barcodes).
            . ($seed ? ', lp.product_title, lp.variant_title, lp.brand, lp.features, lp.units_30d' : '') . ' '
            . 'FROM channel_listing cl LEFT JOIN match_proposal p ON p.open_listing_id = cl.id LEFT JOIN sku ps ON ps.id = p.proposed_sku_id '
            . 'LEFT JOIN match_decision pd ON pd.pending_listing_id = cl.id '
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id LEFT JOIN match_proposal_basis pb ON pb.proposal_id = p.id '
            . "WHERE cl.id IN ({$in})",
            $ids,
        ) as $l) {
            $listings[(int) $l['id']] = $l;
        }
        $made = $seed ? self::madeFrom($this->db, $ids) : [];
        // What no bulk action takes, whatever the action and the list (M51): the one read of it (M63), which the lists ask too.
        $never = OneAtATime::of($this->db, $ids);
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
            if (isset($never[$lid])) {
                // `held` (with the hold's reason, by, at), `in_spot_check` (with the spot check's name) or `on_arrival` (a suggestion
                // of the check on arrival that is decided on its own page, M64), as OneAtATime says it.
                $out[$lid] = $skip($never[$lid]['code'], $never[$lid]['detail']);
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
     *  3. a page heading of the website, as the stored features of a matching run recorded it (`is_placeholder`): `page_heading`
     *     (a variant with a stored state: at 4, after that state's own status; and worked out again at 9);
     *  4. it is known not to be on sale: `not_published`, with the status that says so (SiteState::notOnSale()): the variant's
     *     own status when that is known and is not Published; else its PRODUCT's (a stored state only: a published variant under
     *     a product that is not published is not on sale; the record then says `of: product`). The state is read in SiteState's
     *     order: the stored columns (0028); else the stored features' `variant_status`; else what the open suggestion recorded
     *     when the computer checked it (its evidence's `variant_status`: a listing that changed since has no features). What an
     *     old stored state knows against a variant counts here too.
     *     BESIDE A STORED STATE that does not say "not on sale", the features and the record are asked all the same what they
     *     say AGAINST the variant, as they were before the state was stored (SiteState::against(); the review of build 2a, F1):
     *     `page_heading` when the features call it one, then `not_published` with the status the features, else the record,
     *     give when that is not Published. A stored state is never a reason to make a row that was skipped without it: the
     *     features can be newer than it, and its own heading (9) hangs on a count that can be short. What they say FOR a
     *     variant is not read beside a stored state;
     *  5. NO open suggestion, whatever came before: `not_checked` on New Variants, `no_suggestion` on the other lists (as before);
     *  6. an open suggestion that does not say "new product". On New Variants: `match_no_item` when it is a match (Strong or
     *     Likely) that names no item (the import stores it so when the main-store variant the computer matched has no item:
     *     matchNoItem()), else `not_new` (with its strength: the computer could not say what it is). On the other lists
     *     `band_not_allowed` (as before);
     *  7. the listing is not what the computer checked: `changed_since_check` (changedSinceCheck()). Either its identity (titles,
     *     brand, attributes, barcodes: its profile's identity_hash) is not the one on the suggestion's match_proposal_basis row
     *     (a suggestion with no basis row, made before 0012 and never proved unchanged, skips nothing by this); or the title the
     *     suggestion's evidence holds (the one the computer judged) is not the listing's title now (evidence with no title skips
     *     nothing by this). The second is needed because the basis dates from the IMPORT of a suggestion, not from the run's
     *     export: a listing renamed between the two has a basis on its new identity;
     *  8. its barcode is on an item or on another listing: `barcode_elsewhere`;
     *  9. for a row that would now be made, with a stored state: its page heading worked out again, at this click, from the
     *     stored inputs, the titles, the barcodes, the units sold and the published variants of its product counted in the
     *     database (SiteState::heading(): the Normalizer's own answer): `page_heading` when it is one. A product that gained a
     *     second published variant since the computer's check makes its default row a heading from then on;
     * 10. the warehouse does not know that it is on sale and something that is sold: `state_not_known` (SiteState::doubt(); the
     *     record says `state`: `not_known` or `too_old`, and the date of a state that is too old). That is: no reader gives a
     *     state at all; or its stored state lacks the variant's status, its product's status or an input of the heading; or its
     *     stored state is older than the setting `mapping.arrival_state_max_days`. THIS REVERSES A RULE ON PURPOSE (M66): until
     *     the state was stored, "known from neither: skips nothing". A state known only from the features or from the
     *     suggestion's record has no date; it is relied on exactly as before (so nothing changes for the rows a matching run
     *     judged, until their store's state is loaded).
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
        $ev = json_decode((string) ($l['evidence'] ?? 'null'), true);
        $features = ['variant_status' => $l['features_status'] ?? null, 'is_placeholder' => $l['is_placeholder'] ?? null];
        $state = SiteState::read($l, $features, is_array($ev) ? $ev : null);
        if ($state['heading'] === true) {
            return $skip('page_heading');
        }
        $off = SiteState::notOnSale($state);
        if ($off !== null) {
            return $skip('not_published', ['site_status' => $off] + ($off === $state['variant_status'] ? [] : ['of' => 'product']));
        }
        // Beside a stored state: what the features and the record say against the variant, as before the state was stored.
        $against = SiteState::against($state, $features, is_array($ev) ? $ev : null);
        if ($against['heading']) {
            return $skip('page_heading');
        }
        if ($against['status'] !== null) {
            return $skip('not_published', ['site_status' => $against['status']]);
        }
        if ($l['proposal_id'] === null) {
            return $skip($mixed ? 'not_checked' : 'no_suggestion');
        }
        $band = (string) $l['band'];
        if ($mixed && self::matchNoItem($band, $l['proposed_sku_id'])) {
            return $skip('match_no_item', ['band' => $band]);
        }
        if ($band !== 'New item' || (int) $l['proposed_new_item'] !== 1) {
            return $skip($mixed ? 'not_new' : 'band_not_allowed', ['band' => $band]);
        }
        if (self::changedSinceCheck($l['basis_id'] ?? null, $l['basis_identity'] ?? null, $l['identity_hash'] ?? null, is_array($ev) ? ($ev['title'] ?? null) : null,
            self::text($l['product_title'] ?? null), self::text($l['variant_title'] ?? null))) {
            return $skip('changed_since_check');
        }
        if ($this->barcodeElsewhere((int) $l['id'], self::strings(json_decode((string) ($l['barcodes'] ?? 'null'), true)))) {
            return $skip('barcode_elsewhere');
        }
        // The row would be made. The last two questions (M66): is it a page heading NOW, and does the warehouse know its state?
        if (SiteState::headingInputs($state)) {
            $key = (int) $l['channel_id'] . ':' . $state['product_id'];
            if (!array_key_exists($key, $this->siblings)) {
                $this->siblings[$key] = SiteState::publishedSiblings($this->db, (int) $l['channel_id'], $state['product_id']);
            }
            $heading = SiteState::heading($state, $l, $this->siblings[$key]);
            if ($heading === true) {
                return $skip('page_heading');
            }
            if ($heading === null) {
                return $skip('state_not_known', ['state' => 'not_known']); // (the Normalizer could not read its titles)
            }
        }
        $doubt = SiteState::doubt($state, $this->stateMaxDays ??= SiteState::maxDays($this->db), Clock::now());
        if ($doubt !== null) {
            return $skip('state_not_known', ['state' => $doubt] + ($doubt === 'too_old' ? ['as_of' => $state['as_of']] : []));
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
     * A row with no name is left to DecisionService, which refuses it (`no_name`). A row that is still to be sent carries its
     * name (`name`): one() reads the first of the two again just before the row's decision.
     *
     * @param array<int, array{code: string, detail: array<string, mixed>, request?: array<string, mixed>}> $out the rows' pre-checks
     * @param array<int, array<string, mixed>> $listings prechecks()' rows
     * @return array<int, array{code: string, detail: array<string, mixed>, request?: array<string, mixed>, name?: string}>
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
        foreach ($names as $lid => $name) {
            if ($out[$lid]['code'] === '') {
                $out[$lid]['name'] = $name;
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

    /** The website's state of a listing as one source gives it (a text), or null when it gives none (SiteState::text()). */
    public static function siteStatus(mixed $v): ?string
    {
        return SiteState::text($v);
    }

    /**
     * The website's state of a listing that has NO stored state (the columns of 0028; SiteState::read() asks those first): what
     * its stored features say (`variant_status`), else what its open suggestion recorded when the computer checked it (its
     * evidence's `variant_status`), else null. The second and third readers of SiteState, which the list and the seed both ask,
     * so a row and its skip cannot disagree.
     */
    public static function siteState(mixed $fromFeatures, mixed $fromCheck): ?string
    {
        return SiteState::read([], ['variant_status' => $fromFeatures], ['variant_status' => $fromCheck])['variant_status'];
    }

    /**
     * Whether a listing is no longer what the computer checked when it made the open suggestion. Two signs, either is enough:
     *  - the suggestion has a basis row (match_proposal_basis, $basisId) and the identity recorded there is not the listing
     *    profile's identity now (titles, brand, attributes, barcodes; no profile then or now reads as null). No basis row: not
     *    known by this sign;
     *  - the suggestion's evidence holds the title the computer judged ($judgedTitle: a text that is not empty) and it is not the
     *    listing's title now (KeyEligibility::sameTitle: the option's title, else the title, compared without letter case and
     *    spacing: the comparison the Key bulk confirm uses for the same question, M28). No title in the evidence: not known by
     *    this sign. The basis is recorded when a suggestion is IMPORTED, on the listing as it is then; the run that judged it
     *    was exported earlier, so a listing renamed in between has a basis on its new identity and only the title shows it. A
     *    change of the brand, attributes or barcodes in that window is not seen (M61).
     * The list and the seed both ask here, so a row and its skip cannot disagree.
     */
    public static function changedSinceCheck(mixed $basisId, mixed $basisIdentity, mixed $identity, mixed $judgedTitle = null, ?string $productTitle = null,
        ?string $variantTitle = null): bool
    {
        if ($basisId !== null && (is_string($basisIdentity) ? $basisIdentity : null) !== (is_string($identity) ? $identity : null)) {
            return true;
        }
        return is_string($judgedTitle) && trim($judgedTitle) !== '' && !KeyEligibility::sameTitle($judgedTitle, $productTitle, $variantTitle);
    }

    /**
     * Whether an open suggestion is a match that names no item: its strength says "it is that one" (Strong, Likely) and no item
     * is proposed. The import of a computer check stores a suggestion so when the main-store variant it matched has no item
     * (bin/import_proposals.php, flag `target_not_minted`); the strength is what is asked here, not the flag, so one stored
     * without the flag reads the same. The list and the seed both ask here.
     */
    public static function matchNoItem(mixed $band, mixed $proposedSkuId): bool
    {
        return $proposedSkuId === null && in_array($band, self::MATCH_BANDS, true);
    }

    /** A column's text, or null. */
    private static function text(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
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
            if ($action === 'new_item') {
                // DecisionService's rules of a new product for a website product that waits for a match (no current item, NF2): 1 sale
                // is not 1 item while that rule is on, and the owner's bulk switch.
                if ((int) ($pre['request']['units_per_item'] ?? 1) !== 1 && $multiple) {
                    $needs[] = 'units_per_item';
                }
                if ($bulk) {
                    $needs[] = 'bulk';
                }
            }
            $out[$lid] = ['outcome' => $needs === [] ? 'done' : 'pending_second', 'code' => null, 'detail' => $needs === [] ? [] : ['needs_second' => $needs],
                'needs' => $needs, 'status' => $status];
        }
        return $out;
    }

    // ---- Store Products' "Create new warehouse products for these" (docs/decisions.md NF1-NF8) ---------------------------------

    /**
     * The checks of Store Products' new items (NF2), read for all rows in a few queries, the same for preview() and run(). A row is
     * skipped for the first of these that applies, in this order:
     *  - what every bulk action checks: no such variant, another store, held back / in a spot check / found on arrival (OneAtATime),
     *    changed since the page was drawn, a decision waiting for a second OK, another suggestion than the page showed;
     *  - a landing page (`landing_page`), matched or on hold (`linked`), ignored or any other state (`not_waiting`);
     *  - an item already made from it (`made_before`: a new one would be its second item);
     *  - an open suggestion that names an item (`match_instead`: match it to that one), a match to a main-store variant with no item
     *    (`match_no_item`), any other suggestion than "new product" (`not_new`). No suggestion, or "new product", is what it takes;
     *  - known not to be sold: a page heading, not published (SiteState, as the seed reads it; a state the warehouse does not know is
     *    no reason here: the person looked at the rows);
     *  - no name for the new item (`no_name`);
     *  - a barcode that an item has, or that a variant matched to an item carries (`barcode_on_item`, with that item's CW number);
     *  - a name an item that is not joined into another has: the same name, or the same words in any order, case and accents apart
     *    (`name_elsewhere`, with its CW number); a name a new product waiting for a second OK has (`name_waiting`);
     *  - of the rows left, two or more with the same words (`name_twice`) or a barcode in common (`barcode_twice`): every one of them.
     * A row that would be made carries the request (units 1, the card as the preview shows it), its name and its words (one() reads
     * both again just before its decision). Every row carries its card (null without a name), for the preview.
     *
     * @param list<array{listing_id: int, proposal_id: ?int, map_version: int}> $rows
     * @return array<int, array{code: string, detail: array<string, mixed>, card: ?array<string, mixed>, request?: array<string, mixed>, name?: string, words?: string}>
     */
    private function newChecks(array $rows, int $channelId): array
    {
        $ids = array_map(static fn (array $r): int => $r['listing_id'], $rows);
        $listings = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->db->all(
                'SELECT cl.id, cl.channel_id, cl.status, cl.map_version, p.id AS proposal_id, p.band, p.proposed_sku_id, p.proposed_new_item, p.evidence, '
                . 'ps.code AS proposed_code, pd.id AS pending_id, lp.barcodes, lp.product_title, lp.variant_title, lp.brand, lp.features, '
                . 'lp.site_variant_status, lp.site_product_status, lp.site_product_type, lp.site_product_id, lp.site_is_landing, lp.site_is_default, lp.site_state_at, '
                . "JSON_UNQUOTE(JSON_EXTRACT(lp.features, '$.variant_status')) AS features_status, JSON_EXTRACT(lp.features, '$.is_placeholder') AS is_placeholder "
                . 'FROM channel_listing cl LEFT JOIN match_proposal p ON p.open_listing_id = cl.id LEFT JOIN sku ps ON ps.id = p.proposed_sku_id '
                . 'LEFT JOIN match_decision pd ON pd.pending_listing_id = cl.id LEFT JOIN listing_profile lp ON lp.listing_id = cl.id '
                . 'WHERE cl.id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                $chunk,
            ) as $l) {
                $listings[(int) $l['id']] = $l;
            }
        }
        $made = self::madeFrom($this->db, $ids);
        $never = OneAtATime::of($this->db, $ids);
        $out = [];
        $left = [];
        foreach ($rows as $row) {
            $lid = $row['listing_id'];
            $l = $listings[$lid] ?? null;
            $card = $l === null ? null : self::newCard($l);
            $skip = self::newRowSkip($row, $l, $channelId, $made[$lid] ?? null, $never[$lid] ?? null, $card);
            $out[$lid] = ['code' => $skip[0] ?? '', 'detail' => $skip[1] ?? [], 'card' => $card];
            if ($skip === null && $card !== null && $l !== null) {
                $left[$lid] = ['name' => (string) $card['name'], 'words' => self::nameWords((string) $card['name']),
                    'barcodes' => self::strings(json_decode((string) ($l['barcodes'] ?? 'null'), true))];
            }
        }
        // What the warehouse has already: a barcode, a name (exact, or the same words), a name waiting for a second OK.
        $words = $left === [] ? [] : $this->itemWords();
        $exact = $left === [] ? [] : $this->namesTaken(array_values(array_unique(array_column($left, 'name'))));
        $waiting = $left === [] ? [] : $this->waitingNames();
        $codes = [];
        foreach ($left as $lid => $x) {
            $item = $this->barcodeItem($lid, $x['barcodes']);
            if ($item !== null) {
                $out[$lid] = ['code' => 'barcode_on_item', 'detail' => ['sku' => $item]] + $out[$lid];
                unset($left[$lid]);
                continue;
            }
            if (isset($exact[$x['name']])) {
                $out[$lid] = ['code' => 'name_elsewhere', 'detail' => ['sku' => $exact[$x['name']], 'name' => $x['name']]] + $out[$lid];
                unset($left[$lid]);
                continue;
            }
            if ($x['words'] !== '' && isset($words[$x['words']])) {
                $codes[$lid] = $words[$x['words']];
                continue;
            }
            if ($x['words'] !== '' && isset($waiting[$x['words']]) && $waiting[$x['words']] !== $lid) {
                $out[$lid] = ['code' => 'name_waiting', 'detail' => ['name' => $x['name']]] + $out[$lid];
                unset($left[$lid]);
            }
        }
        if ($codes !== []) {
            $code = [];
            $want = array_values(array_unique($codes));
            foreach ($this->db->all('SELECT id, code FROM sku WHERE id IN (' . implode(',', array_fill(0, count($want), '?')) . ')', $want) as $s) {
                $code[(int) $s['id']] = (string) $s['code'];
            }
            foreach ($codes as $lid => $skuId) {
                $out[$lid] = ['code' => 'name_elsewhere', 'detail' => ['sku' => $code[$skuId] ?? '', 'name' => $left[$lid]['name']]] + $out[$lid];
                unset($left[$lid]);
            }
        }
        // Two ticked rows that would make one product: the same words, or a barcode in common. Every row of such a group is left.
        $byWords = [];
        $byCode = [];
        foreach ($left as $lid => $x) {
            $byWords[$x['words'] !== '' ? $x['words'] : mb_strtolower($x['name'], 'UTF-8')][] = $lid;
            foreach (array_unique(array_filter(array_map(static fn (string $b): ?string => Gtin::key($b), $x['barcodes']))) as $k) {
                $byCode[$k][] = $lid;
            }
        }
        foreach ($byWords as $group) {
            foreach (count($group) > 1 ? $group : [] as $lid) {
                $out[$lid] = ['code' => 'name_twice', 'detail' => ['name' => $left[$lid]['name'], 'rows' => count($group)]] + $out[$lid];
            }
        }
        foreach ($byCode as $group) {
            foreach (count(array_unique($group)) > 1 ? array_unique($group) : [] as $lid) {
                if ($out[$lid]['code'] === '') {
                    $out[$lid] = ['code' => 'barcode_twice', 'detail' => ['rows' => count(array_unique($group))]] + $out[$lid];
                }
            }
        }
        foreach ($left as $lid => $x) {
            if ($out[$lid]['code'] === '') {
                $out[$lid] += ['request' => ['units_per_item' => 1, 'card' => $out[$lid]['card']], 'name' => $x['name'], 'words' => $x['words']];
            }
        }
        return $out;
    }

    /**
     * The first check of newChecks() that skips one row, as [code, detail], or null when none does (the warehouse's own barcodes and
     * names are asked afterwards, for all rows at once).
     *
     * @param array{listing_id: int, proposal_id: ?int, map_version: int} $row
     * @param array<string, mixed>|null $l newChecks()' row of the listing
     * @param array{code: string, detail: array<string, mixed>}|null $never OneAtATime's answer for it
     * @param array<string, mixed>|null $card
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private static function newRowSkip(array $row, ?array $l, int $channelId, ?string $madeCode, ?array $never, ?array $card): ?array
    {
        if ($l === null) {
            return ['unknown_listing', []];
        }
        if ((int) $l['channel_id'] !== $channelId) {
            return ['other_store', []];
        }
        if ($never !== null) {
            return [$never['code'], $never['detail']];
        }
        if ((int) $l['map_version'] !== $row['map_version']) {
            return ['changed', ['status' => (string) $l['status']]];
        }
        if ($l['pending_id'] !== null) {
            return ['pending_second', []];
        }
        $open = $l['proposal_id'] === null ? null : (int) $l['proposal_id'];
        if ($open !== $row['proposal_id']) {
            return ['suggestion_changed', []];
        }
        $status = (string) $l['status'];
        if ($status === 'landing') {
            return ['landing_page', []];
        }
        if (in_array($status, DecisionService::LINKED, true)) {
            return ['linked', []];
        }
        if (!in_array($status, self::OPEN, true)) {
            return ['not_waiting', ['status' => $status]];
        }
        if ($madeCode !== null) {
            return ['made_before', ['sku' => $madeCode]];
        }
        if ($open !== null) {
            $band = (string) $l['band'];
            if ($l['proposed_sku_id'] !== null) {
                return ['match_instead', ['sku' => (string) $l['proposed_code']]];
            }
            if (self::matchNoItem($band, null)) {
                return ['match_no_item', ['band' => $band]];
            }
            if ($band !== 'New item' || (int) $l['proposed_new_item'] !== 1) {
                return ['not_new', ['band' => $band]];
            }
        }
        $ev = json_decode((string) ($l['evidence'] ?? 'null'), true);
        $ev = is_array($ev) ? $ev : null;
        $features = ['variant_status' => $l['features_status'] ?? null, 'is_placeholder' => $l['is_placeholder'] ?? null];
        $state = SiteState::read($l, $features, $ev);
        if ($state['heading'] === true) {
            return ['page_heading', []];
        }
        $off = SiteState::notOnSale($state);
        if ($off !== null) {
            return ['not_published', ['site_status' => $off] + ($off === $state['variant_status'] ? [] : ['of' => 'product'])];
        }
        $against = SiteState::against($state, $features, $ev);
        if ($against['heading']) {
            return ['page_heading', []];
        }
        if ($against['status'] !== null) {
            return ['not_published', ['site_status' => $against['status']]];
        }
        if ($card === null) {
            return ['no_name', []];
        }
        return null;
    }

    /**
     * After newChecks(), what only the apply knows (NF4): a row its preview skipped is never made, whatever it looks like now (it keeps
     * the reason it has now, or is `changed` when it has none: the person said yes to "these N", and it was not one of them); a row
     * whose new product's details are not the ones the preview showed (`card_hash`: a matching run rewrote its features since, which
     * does not move its version) is skipped as `card_changed`. The preview asks none of this.
     *
     * @param list<array<string, mixed>> $rows as given to run() / preview()
     * @param array<int, array<string, mixed>> $skips newChecks()
     * @param list<string> $roles
     * @return array<int, array<string, mixed>>
     */
    private function newApplyChecks(string $action, array $rows, array $skips, array $roles, bool $applying): array
    {
        if (!$applying) {
            return $skips;
        }
        $held = [];
        foreach ($rows as $r) {
            $lid = (int) $r['listing_id'];
            if (($r['preview_skipped'] ?? false) === true) {
                $held[] = $lid;
            } elseif (($skips[$lid]['code'] ?? 'unknown_listing') === '' && array_key_exists('card_hash', $r)
                && $r['card_hash'] !== self::cardHash($skips[$lid]['card'] ?? null)) {
                $skips[$lid] = ['code' => 'card_changed', 'detail' => [], 'card' => $skips[$lid]['card'] ?? null];
            }
        }
        if ($held !== []) {
            sort($held);
            foreach ($this->predict($action, $held, $skips, $roles) as $lid => $p) {
                $skips[$lid] = ($p['outcome'] === 'skipped' ? ['code' => (string) $p['code'], 'detail' => $p['detail']]
                    : ['code' => 'changed', 'detail' => $p['status'] === null ? [] : ['status' => $p['status']]]) + ['card' => $skips[$lid]['card'] ?? null];
            }
        }
        return $skips;
    }

    /**
     * The new product a listing would get, exactly as the single "Create a new product" makes it with nothing typed over
     * (DecisionService::cardFrom over its profile and features, M9), or null when it has no name.
     *
     * @param array<string, mixed> $l
     * @return array<string, mixed>|null
     */
    private static function newCard(array $l): ?array
    {
        try {
            $features = isset($l['features']) ? json_decode((string) $l['features'], true) : null;
            return DecisionService::cardFrom(['product_title' => $l['product_title'] ?? null, 'variant_title' => $l['variant_title'] ?? null, 'brand' => $l['brand'] ?? null],
                is_array($features) ? $features : []);
        } catch (CwException) {
            return null;
        }
    }

    /** A short fingerprint of a new product's details, which the preview's form carries to its apply (NF4); '-' for none. @param array<string, mixed>|null $card */
    public static function cardHash(?array $card): string
    {
        return $card === null ? '-' : substr(hash('sha256', Idempotency::canonicalJson($card)), 0, 16);
    }

    /**
     * The words of a name as a set (NF2): lower case, accents left out, split on anything that is not a letter or a digit, each word
     * once, in order. "Hayati Pro Max 4000 Apple" and "APPLE - hayati pro max 4000" are one name; "Apple Ice" is another.
     */
    public static function nameWords(string $name): string
    {
        $s = mb_strtolower($name, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) {
                $s = (string) (preg_replace('/\p{Mn}+/u', '', $d) ?? $s);
            }
        }
        $words = preg_split('/[^\pL\pN]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || $words === []) {
            return '';
        }
        $words = array_values(array_unique(array_map('strval', $words)));
        sort($words, SORT_STRING);
        return implode(' ', $words);
    }

    /**
     * Every item that is not joined into another, by the words of its name (nameWords()): words => the lowest item id. One read of
     * the item table's ids, names and joins (about 15,000 items on staging; a preview of a family is rare), once a request.
     *
     * @return array<string, int>
     */
    private function itemWords(): array
    {
        $out = [];
        $high = 0;
        foreach ($this->db->all('SELECT id, name FROM sku WHERE merged_into_sku_id IS NULL') as $s) {
            $id = (int) $s['id'];
            $high = max($high, $id);
            $key = self::nameWords((string) $s['name']);
            if ($key !== '' && (!isset($out[$key]) || $id < $out[$key])) {
                $out[$key] = $id;
            }
        }
        $this->skuHigh = max($this->skuHigh, $high, (int) $this->db->value('SELECT COALESCE(MAX(id), 0) FROM sku'));
        return $out;
    }

    /** The CW number of an item made since itemWords() read them (by its primary key) whose name has these words, or null. */
    private function wordsSince(string $words): ?string
    {
        foreach ($this->db->all('SELECT id, code, name FROM sku WHERE id > ? AND merged_into_sku_id IS NULL ORDER BY id', [$this->skuHigh]) as $s) {
            if (self::nameWords((string) $s['name']) === $words) {
                return (string) $s['code'];
            }
        }
        return null;
    }

    /**
     * The names of the new products that wait for a second OK (not an item until a second matching lead says yes, so the item table
     * does not have them): words => the listing it waits on. The waiting decisions by their own unique index, a few rows.
     *
     * @return array<string, int>
     */
    private function waitingNames(): array
    {
        $out = [];
        foreach ($this->db->all("SELECT /*+ INDEX(d uq_match_decision_pending) */ d.pending_listing_id AS lid, JSON_UNQUOTE(JSON_EXTRACT(d.detail, '$.card.name')) AS name "
            . "FROM match_decision d WHERE d.pending_listing_id IS NOT NULL AND d.action = 'new_item'") as $d) {
            $key = is_string($d['name']) ? self::nameWords($d['name']) : '';
            if ($key !== '') {
                $out[$key] = (int) $d['lid'];
            }
        }
        return $out;
    }

    /**
     * The CW number of an item that already has one of these barcodes (sku_barcode), or that a variant matched to an item carries (that
     * item's barcode not copied to it yet: the rule of an item made by hand, PB1), or null. Every form of each barcode (Gtin::forms),
     * each an exact match on an index. A barcode on a variant nobody matched is no item's.
     *
     * @param list<string> $barcodes
     */
    private function barcodeItem(int $listingId, array $barcodes): ?string
    {
        foreach (array_chunk(Gtin::forms($barcodes), 200) as $codes) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            $code = $this->db->value("SELECT s.code FROM sku_barcode b JOIN sku s ON s.id = b.sku_id WHERE b.barcode IN ({$in}) LIMIT 1", $codes)
                ?? $this->db->value('SELECT s.code FROM listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id JOIN sku s ON s.id = cl.sku_id '
                    . "WHERE JSON_OVERLAPS(lp.barcodes, CAST(? AS JSON)) AND lp.listing_id <> ? AND cl.status IN ('mapped', 'quarantined') LIMIT 1",
                    [json_encode($codes, JSON_THROW_ON_ERROR), $listingId]);
            if ($code !== null) {
                return (string) $code;
            }
        }
        return null;
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
