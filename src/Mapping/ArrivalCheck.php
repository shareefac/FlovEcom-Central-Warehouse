<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Audit;
use CW\Caller;
use CW\Catalogue\BarcodeReviews;
use CW\Catalogue\BarcodeSync;
use CW\CwException;
use CW\Db;
use CW\Matching\ArrivalRules;
use CW\Matching\Gtin;
use CW\Matching\Normalizer;
use CW\Matching\Text;
use CW\Matching\Veto;
use CW\Settings;

/**
 * The check on arrival, build 1 (the owner's "do the check on arrival next", 12 Oct 2026; docs/decisions.md M64, M65): a rules-only
 * computer check that gives a variant nobody has looked at a suggestion when its barcode, or a web address copied from the main
 * store, points at an item. It only suggests: a person confirms every match, as everywhere. The rules are CW\Matching\ArrivalRules
 * (pure); this class reads the facts for them and writes their answer through Proposals::add(), the one way a suggestion is made.
 *
 * WHEN. Only from bin/check_arrivals.php: a command-line job, on a timer for the variants the listing intake marked "to check"
 * (listing_profile.arrival_state `queued`, written by ListingIngestService), and by hand for the ones that were there before the
 * check (mark NULL: `--backfill`, never the timer). Never inside the intake's transaction and never in a web or API request.
 *
 * WHO IT LOOKS AT. Proposals::add() supersedes whatever suggestion is open, without asking, so the refusals are made here, twice:
 * before the rules (look()), and again under the variant's row lock just before add() (write()). A variant is given a suggestion
 * only when ALL of this holds (the first that does not is the outcome recorded on its profile, ArrivalRules::REFUSALS):
 *  - its store is one of `mapping.arrival_stores` (a store that is not named is not looked at at all);
 *  - it waits for a match: `unmapped`, or `suggested` by the check's own untouched suggestion (`not_open` otherwise);
 *  - no hold is in force on it (`held`) and it is in no spot check, as one of its matches or of its population (`in_spot_check`);
 *  - nobody and nothing else has touched it (`has_history`): no suggestion of any state from another run, no decision other than
 *    the `suggest` of the check itself (so none waiting for a second person, no "not a match", no ignore, no undo, no "sent back"),
 *    no "not this item" record;
 *  - its profile has a title (`no_title`);
 *  - under the lock, its identity, its web address, its stored website state and its "to check" number are still the ones that
 *    were read. If not, nothing is written and it stays to check.
 *
 * WHAT IT NEVER DOES. It never writes Strong (Key), Renamed range (Manual) or New item: ArrivalRules::BANDS is all it can say. It
 * never writes an `ai` block (so the evidence cannot be replayed as a judged match: Matching\StoredBand reads none, and a
 * suggestion of it can enter no spot check, no Key bulk confirm and no re-band), and no band version anywhere in its run. Its runs
 * are not "a new matching run" (KeySample::NOT_A_MATCHING_RUN). It never confirms, matches, ignores or creates (a system caller may
 * only suggest: DecisionService). It never names an item that was joined into another (each is followed to the item it is now). It
 * never replaces anything but its own untouched suggestion. It never locks a profile row inside its transaction (the intake locks
 * profile then listing), and the feed lock is the last lock it takes (add() takes it, through the `suggest`). It never deletes.
 * Every read is by a primary key or one of: sku_barcode's key, the multi-valued barcode index (0005), the address key and the
 * queue index (0027), the listing indexes of the suggestions, decisions, rejects, holds and spot checks, channel_listing's
 * (channel, status) and (item, channel). A dry run writes nothing at all: no mark, no audit row, no run row.
 *
 * ITS OWN SUGGESTION is looked at again when the variant is marked again. The same answer: nothing is written. Another answer: a
 * new suggestion replaces it. No clue any more (the barcode was taken off, the address changed): it is replaced by a "not sure"
 * that says so (`arrival_no_longer`); a stale one is never left open. A later judged run supersedes a rules-only suggestion like
 * any other: that is wanted.
 *
 * THE WEBSITE STATE (build 2a, 0028, docs/decisions.md M66; CW\Mapping\SiteState) is RECORDED, and decides nothing here: no outcome
 * and no rule reads it, and a match is written whatever the state says (a match makes nothing: a person confirms it, and only a
 * "New" lets an item be made, which this build never writes). A suggestion's evidence says what was known when it was made:
 * `variant_status` (a text, only when known) and `website` {known, as_of, product_status, heading}. `known` is true only for a
 * stored state (the profile's own columns) that has the variant's status, its product's status and an answer for the page
 * heading; "not known" is written as what it is. The heading of a stored state is worked out at the look (the Normalizer's own
 * answer, with the published variants of the product counted in the database: one more read, on the index of 0028, only for a
 * variant that gets a suggestion). The snapshot the write compares under the lock holds the stored state too: a state that
 * moved between the look and the write (the state-only load moves it without touching the mark) means nothing is written.
 *
 * TWO RUN ROWS. A run that writes makes its match_run rows when it first needs them, in the transaction of that first suggestion:
 * one of source `arrival` for its clean matches, one of source `arrival_warned` for everything else (a match with a warning, a
 * "not sure", a conflict), under one run id `arrival-<UTC time>`. So "found by the check on arrival" and "with a warning" are both
 * told from the run alone, on an index, without reading a suggestion's flags: what the lists' filter and counts need (OneAtATime).
 * A new pair per run: one run row holds one suggestion per variant for ever.
 */
final class ArrivalCheck
{
    /** match_run.source of a run's clean matches (a Likely match, nothing against it). */
    public const SOURCE = 'arrival';
    /** match_run.source of everything else a run writes: each carries the flag ArrivalRules::WARNED. */
    public const SOURCE_WARNED = 'arrival_warned';
    public const SOURCES = [self::SOURCE, self::SOURCE_WARNED];
    public const JOB = 'check_arrivals';
    public const AUDIT = 'mapping.arrival_check';
    /** The settings' values when a row is not there (a schema before 0027). */
    public const MAX_PER_RUN = 200;
    public const MAX_SECONDS = 45;
    public const QUEUE_ALARM = 300;
    public const DEFAULT_MAIN_STORE = 'vapeandgo';
    /** How many variants one read of a queue asks for. */
    private const CHUNK = 100;
    /** The most other variants (one barcode) and main-store variants (one address) that are read: more is a conflict or "several" anyway. */
    private const MAX_SHARED = 200;
    private const LINKED = DecisionService::LINKED;

    private readonly DecisionService $ds;
    private readonly Proposals $proposals;
    private readonly Caller $caller;
    /** @var array<int, int> item id => the item it is now (joined items followed) */
    private array $roots = [];
    /** @var array<int, array<string, mixed>> item id => what the rules need of it, for one store */
    private array $items = [];
    /** @var array<string, bool> "barcode key:item" => people ruled the barcode is not that item's (namedBy(); remembered for the run) */
    private array $ruled = [];
    /** @var array<string, int> source => match_run.id of this run (made when first needed) */
    private array $runRows = [];
    private ?string $runId = null;
    /** @var array<int, string> channel id => code */
    private array $channels = [];

    public function __construct(private readonly Db $db, ?DecisionService $ds = null)
    {
        $this->ds = $ds ?? new DecisionService($db);
        $this->proposals = new Proposals($db, $this->ds);
        $this->caller = Caller::system(self::JOB);
    }

    // ------------------------------------------------------------------------------------------------------------------
    // The settings (Settings › Matching products, 0027)

    /**
     * The stores the check looks at (`mapping.arrival_stores`: channel codes; empty or not there: none, the check is off): channel
     * id => code, in code order. A code that names no store is left out.
     *
     * @return array<int, string>
     */
    public static function stores(Db $db): array
    {
        $codes = array_values(array_filter(explode(',', self::text($db, 'mapping.arrival_stores', '')), static fn (string $c): bool => $c !== ''));
        if ($codes === []) {
            return [];
        }
        $out = [];
        foreach ($db->all('SELECT id, code FROM channel WHERE code IN (' . implode(',', array_fill(0, count($codes), '?')) . ') ORDER BY code', $codes) as $c) {
            $out[(int) $c['id']] = (string) $c['code'];
        }
        return $out;
    }

    /** The main store's channel id (`mapping.arrival_main_store`), or null when the setting is empty or names no store. */
    public static function mainStore(Db $db): ?int
    {
        $code = self::text($db, 'mapping.arrival_main_store', self::DEFAULT_MAIN_STORE);
        $id = $code === '' ? null : $db->value('SELECT id FROM channel WHERE code = ?', [$code]);
        return $id === null ? null : (int) $id;
    }

    /** Whether a clean match of the check may be confirmed from a ticked list (`mapping.arrival_bulk_confirm`; off unless set). */
    public static function bulkConfirm(Db $db): bool
    {
        return Settings::flag($db, 'mapping.arrival_bulk_confirm', false);
    }

    /** How many variants of a store wait for the check (the queue index alone). */
    public static function waiting(Db $db, int $channelId): int
    {
        return (int) $db->value("SELECT COUNT(*) FROM listing_profile lp WHERE lp.arrival_state = 'queued' AND lp.arrival_channel_id = ?", [$channelId]);
    }

    /**
     * The last real run that looked at anything (its audit row, by the index on the action): when, its run id (null: it wrote no
     * suggestion) and its counts per store code; null when the check has never run.
     *
     * @return array{at: string, run_id: ?string, per_store: array<string, array<string, mixed>>}|null
     */
    public static function lastRun(Db $db): ?array
    {
        $r = $db->one('SELECT created_at, detail FROM audit_log WHERE action = ? ORDER BY created_at DESC, id DESC LIMIT 1', [self::AUDIT]);
        $d = $r === null ? null : json_decode((string) $r['detail'], true);
        if (!is_array($d)) {
            return null;
        }
        return ['at' => (string) $r['created_at'], 'run_id' => is_string($d['run_id'] ?? null) ? $d['run_id'] : null,
            'per_store' => is_array($d['per_store'] ?? null) ? $d['per_store'] : []];
    }

    // ------------------------------------------------------------------------------------------------------------------
    // One run

    /**
     * One run of the check: the variants marked "to check" (or, with `backfill`, the ones of one store that were there before the
     * check), store by store in listing order, until the run's number or its time is used up.
     *
     *  - `apply` false (the default): a DRY RUN. It reads and counts and writes nothing: no suggestion, no mark, no run row, no
     *    audit row. With `channel` it may look at any store; without, at the stores of the setting.
     *  - `apply` true: writes. Only the stores of the setting (`channel` naming another one is refused: 400 store_not_switched_on).
     *    With no store in the setting it does nothing and says `switched_off`.
     *  - `channel` not given (the timer's form): a store whose queue is above `mapping.arrival_queue_alarm` is left for a person
     *    (`above_alarm`): a whole store that was just loaded is never drained by the timer. Naming the store takes its queue.
     *  - `backfill` (needs `channel`): the unmatched variants of that store that have no mark, instead of the queue.
     *  - `limit`, `max_seconds`: instead of the settings, for a hand run. `after_id`: only listings above this id (to go on where a
     *    run stopped: `last_id`). `each`: called with every variant's line (bin/check_arrivals.php --list).
     *
     * @param array{apply?: bool, channel?: ?string, backfill?: bool, limit?: ?int, max_seconds?: ?int, after_id?: int, each?: ?callable} $opts
     * @return array{mode: string, switched_off: bool, run_id: ?string, stopped: ?string, looked: int, per_store: array<string, array<string, mixed>>, ms: int}
     */
    public function run(array $opts = []): array
    {
        $t0 = hrtime(true);
        $apply = (bool) ($opts['apply'] ?? false);
        $backfill = (bool) ($opts['backfill'] ?? false);
        $code = $opts['channel'] ?? null;
        $on = self::stores($this->db);
        if ($backfill && $code === null) {
            throw new CwException('bad_request', 'a backfill names its store', 400);
        }
        if ($code !== null) {
            $cid = $this->db->value('SELECT id FROM channel WHERE code = ?', [$code]);
            if ($cid === null) {
                throw new CwException('unknown_store', "there is no store {$code}", 404);
            }
            if ($apply && !isset($on[(int) $cid])) {
                throw new CwException('store_not_switched_on', "the check on arrival is not switched on for {$code}: name it in the setting first", 400);
            }
            $targets = [(int) $cid => (string) $code];
        } else {
            $targets = $on;
        }
        $report = ['mode' => $apply ? 'apply' : 'dry_run', 'switched_off' => $on === [], 'run_id' => null, 'stopped' => null, 'looked' => 0, 'per_store' => [], 'ms' => 0];
        if ($targets === []) {
            return $report; // no store in the setting and none named: switched off
        }
        $limit = max(1, (int) ($opts['limit'] ?? Settings::number($this->db, 'mapping.arrival_max_per_run', self::MAX_PER_RUN)));
        $seconds = max(1, (int) ($opts['max_seconds'] ?? Settings::number($this->db, 'mapping.arrival_max_seconds', self::MAX_SECONDS)));
        $alarm = Settings::number($this->db, 'mapping.arrival_queue_alarm', self::QUEUE_ALARM);
        $main = self::mainStore($this->db);
        $deadline = $t0 + $seconds * 1_000_000_000;
        $this->channels += $targets;
        $this->runRows = [];
        $this->runId = null;
        $each = $opts['each'] ?? null;

        foreach ($targets as $channelId => $store) {
            $s = ['waiting' => self::waiting($this->db, $channelId), 'looked' => 0, 'match' => 0, 'conflict' => 0, 'not_sure' => 0, 'kept' => 0, 'moved' => 0,
                'failed' => 0, 'left' => [], 'last_id' => null];
            if ($code === null && $s['waiting'] > $alarm) {
                // The timer never drains a whole store: a person runs it by hand, naming the store.
                $report['per_store'][$store] = ['skipped' => 'above_alarm'] + $s;
                continue;
            }
            $this->items = [];
            $after = (int) ($opts['after_id'] ?? 0);
            while ($report['stopped'] === null) {
                $ids = $this->next($channelId, $backfill, $after, min(self::CHUNK, $limit - $report['looked']));
                if ($ids === []) {
                    break;
                }
                foreach ($ids as $id) {
                    if (hrtime(true) >= $deadline) {
                        $report['stopped'] = 'time';
                        break;
                    }
                    $after = $id;
                    $look = $this->look($id, $main);
                    $done = $apply ? $this->write($look) : ['result' => self::planned($look), 'outcome' => $look['outcome'], 'proposal_id' => null];
                    $report['looked']++;
                    $s['looked']++;
                    $s['last_id'] = $id;
                    self::count($s, $done, $look);
                    if ($each !== null) {
                        $each(self::line($look, $done));
                    }
                    if ($report['looked'] >= $limit) {
                        $report['stopped'] = 'limit';
                        break;
                    }
                }
            }
            ksort($s['left']);
            $report['per_store'][$store] = $s;
            if ($report['stopped'] !== null) {
                break;
            }
        }
        $report['run_id'] = $this->runRows === [] ? null : $this->runId;
        $report['ms'] = intdiv(hrtime(true) - $t0, 1_000_000);
        if ($apply && $report['looked'] > 0) {
            Audit::write($this->db, $this->caller, self::AUDIT, 'match_run', $report['run_id'], null, ['mode' => $backfill ? 'backfill' : ($code === null ? 'timer' : 'by_hand'),
                'run_id' => $report['run_id'], 'rules' => ArrivalRules::VERSION, 'looked' => $report['looked'], 'stopped' => $report['stopped'],
                'per_store' => $report['per_store'], 'ms' => $report['ms']]);
        }
        return $report;
    }

    /**
     * The next variants to look at, in listing order. The queue: one range of the index (arrival_state, arrival_channel_id), the
     * listing id from the index itself, no profile read. A backfill: the store's unmatched variants in id order (channel_listing's
     * (channel, status) index), each with its profile by the primary key, the ones without a mark.
     *
     * @return list<int>
     */
    private function next(int $channelId, bool $backfill, int $after, int $n): array
    {
        if ($n < 1) {
            return [];
        }
        $sql = $backfill
            ? 'SELECT /*+ JOIN_ORDER(cl, lp) INDEX(cl ix_channel_listing_status) */ cl.id FROM channel_listing cl JOIN listing_profile lp ON lp.listing_id = cl.id '
                . "WHERE cl.channel_id = ? AND cl.status = 'unmapped' AND cl.id > ? AND lp.arrival_state IS NULL ORDER BY cl.id LIMIT {$n}"
            : 'SELECT /*+ INDEX(lp ix_listing_profile_arrival) */ lp.listing_id FROM listing_profile lp '
                . "WHERE lp.arrival_state = 'queued' AND lp.arrival_channel_id = ? AND lp.listing_id > ? ORDER BY lp.listing_id LIMIT {$n}";
        return array_map('intval', $this->db->column($sql, [$channelId, $after]));
    }

    // ------------------------------------------------------------------------------------------------------------------
    // One variant

    /**
     * What the check says of one variant now. Read only and without a lock: the refusals (unless $ignoreHistory: the scoring asks
     * what the rules alone say), then the facts and the rules' answer, and what would be done with it.
     *
     * @return array{listing_id: int, channel_id: ?int, store: ?string, variant: ?string, title: ?string, snapshot: array{identity_hash: ?string, perma_link: ?string, seq: int, own: ?int, site: ?array<string, mixed>},
     *     outcome: string, action: string, answer: array<string, mixed>, facts: ?array<string, mixed>, target: ?array<string, mixed>, evidence: ?array<string, mixed>}
     *     action: `write` (a suggestion to write), `keep` (its own suggestion says the same already), `leave` (nothing to suggest)
     */
    public function look(int $listingId, ?int $mainStoreId = null, bool $ignoreHistory = false): array
    {
        $mainStoreId ??= self::mainStore($this->db);
        $l = $this->db->one('SELECT id, channel_id, external_variant_id, status, map_version FROM channel_listing WHERE id = ?', [$listingId]);
        $p = $l === null ? null : $this->profile($listingId);
        $out = ['listing_id' => $listingId, 'channel_id' => $l === null ? null : (int) $l['channel_id'], 'store' => null,
            'variant' => $l === null ? null : (string) $l['external_variant_id'], 'title' => $p === null ? null : self::title($p),
            'snapshot' => ['identity_hash' => $p['identity_hash'] ?? null, 'perma_link' => $p['perma_link'] ?? null, 'seq' => (int) ($p['arrival_seq'] ?? 0), 'own' => null,
                'site' => self::storedState($p)],
            'outcome' => 'not_open', 'action' => 'leave', 'answer' => ArrivalRules::none('not_open'), 'facts' => null, 'target' => null, 'evidence' => null];
        if ($l === null) {
            return $out;
        }
        $out['store'] = $this->channels[(int) $l['channel_id']] ??= (string) $this->db->value('SELECT code FROM channel WHERE id = ?', [(int) $l['channel_id']]);
        $state = $this->state($l, $p);
        $own = $state['own'];
        $out['snapshot']['own'] = $own['id'] ?? null;
        if ($state['refusal'] !== null && !($ignoreHistory && $p !== null && self::title($p) !== '')) {
            $out['outcome'] = $state['refusal'];
            $out['answer'] = ArrivalRules::none($state['refusal']);
            return $out;
        }
        if ($ignoreHistory) {
            $own = null;
        }
        /** @var array<string, mixed> $p */
        $facts = $this->facts($l, $p, $mainStoreId);
        $answer = ArrivalRules::decide($facts);
        if (!$answer['suggest'] && $own !== null) {
            $answer = ArrivalRules::noLonger($own['lane']); // its own suggestion has no clue behind it any more: never left open
        }
        $out['facts'] = self::brief($facts);
        $out['answer'] = $answer;
        $out['outcome'] = (string) $answer['outcome'];
        if (!$answer['suggest']) {
            return $out;
        }
        // (the item the answer names is one of the items the facts were read for: its record is there, with nothing read twice)
        $target = $answer['target'] === null ? null : ($facts['items'][(int) $answer['target']]['record'] ?? null);
        $out['target'] = $target === null ? null : array_diff_key($target, ['features' => true]);
        $out['evidence'] = self::evidence($p, $answer, $facts, $target, $this->website($p, (int) $l['channel_id']));
        $out['action'] = $own !== null && ArrivalRules::same($own, $answer) ? 'keep' : 'write';
        return $out;
    }

    /**
     * Writes what look() said, in ONE transaction: the variant's row lock, every refusal again, the variant still as it was read
     * (its identity, its web address, its stored website state, its "to check" number, its own suggestion), then Proposals::add() (which moves an unmatched variant to `suggested` through DecisionService and takes the feed
     * lock last), then the suggestion's basis read back: the identity it recorded must be the one the rules ran on, or everything
     * is rolled back. The profile row is never locked here. After the commit the profile's mark is set, only while its number is
     * still the one that was read: a variant marked again meanwhile stays to check.
     *
     * @param array<string, mixed> $look look()'s answer
     * @return array{result: string, outcome: string, proposal_id: ?int}
     *     result: `written`, `kept` (its own suggestion says the same), `left` (nothing to suggest: the outcome says why),
     *     `refused` (touched since it was looked at: the outcome is the refusal), `moved` (changed since: nothing written, still to
     *     check), `failed` (the database refused it: nothing written, still to check)
     */
    public function write(array $look): array
    {
        $id = (int) $look['listing_id'];
        $snap = $look['snapshot'];
        if ($look['action'] !== 'write') {
            // Nothing to write: the mark only. (Its own suggestion saying the same already is `kept`.)
            $this->mark($id, (int) $snap['seq'], (string) $look['outcome'], $look['channel_id']);
            return ['result' => $look['action'] === 'keep' ? 'kept' : 'left', 'outcome' => (string) $look['outcome'], 'proposal_id' => null];
        }
        $answer = $look['answer'];
        $source = $answer['warned'] ? self::SOURCE_WARNED : self::SOURCE;
        $fresh = null; // a run row made inside the transaction: kept only when it commits
        try {
            $r = $this->db->transaction(function (Db $db) use ($id, $snap, $answer, $look, $source, &$fresh): array {
                $fresh = null;
                $l = $db->one('SELECT id, channel_id, external_variant_id, status, map_version FROM channel_listing WHERE id = ? FOR UPDATE', [$id]);
                if ($l === null) {
                    return ['result' => 'refused', 'outcome' => 'not_open', 'proposal_id' => null];
                }
                // Every refusal again, under the lock: no decision on this variant commits before this transaction ends.
                $p = $this->profile($id);
                $state = $this->state($l, $p);
                if ($state['refusal'] !== null) {
                    return ['result' => 'refused', 'outcome' => $state['refusal'], 'proposal_id' => null];
                }
                if ($p === null || ($p['identity_hash'] ?? null) !== $snap['identity_hash'] || ($p['perma_link'] ?? null) !== $snap['perma_link']
                    || (int) $p['arrival_seq'] !== (int) $snap['seq'] || ($state['own']['id'] ?? null) !== $snap['own'] || self::storedState($p) !== ($snap['site'] ?? null)) {
                    return ['result' => 'moved', 'outcome' => (string) $look['outcome'], 'proposal_id' => null];
                }
                $runRow = $this->runRows[$source] ?? null;
                if ($runRow === null) {
                    $runRow = $fresh = $this->runRow($source);
                }
                $r = $this->proposals->add($this->caller, $id, $runRow, ['proposed_sku_id' => $answer['sku_id'], 'proposed_new_item' => false,
                    'band' => $answer['band'], 'lane' => $answer['lane'], 'closest_sku_id' => $answer['closest_sku_id'],
                    'evidence' => ['run_id' => $this->runId] + $look['evidence'], 'flags' => $answer['flags']], true);
                $basis = $r['result'] === 'created' ? ProposalBasis::of($db, (int) $r['proposal_id']) : null;
                if ($basis === null || ($basis['identity_hash'] ?? null) !== $snap['identity_hash'] || ($l['status'] === 'unmapped') !== $r['suggested']) {
                    // The profile changed while this ran (the basis is read without a lock), or the suggestion did not take: nothing of
                    // this transaction stays (the suggestion, its `suggest`, a run row made in it).
                    throw new CwException('arrival_moved', 'the variant changed while it was checked', 409);
                }
                return ['result' => 'written', 'outcome' => (string) $look['outcome'], 'proposal_id' => (int) $r['proposal_id']];
            });
        } catch (CwException $e) {
            if ($e->errorCode !== 'arrival_moved') {
                throw $e;
            }
            return ['result' => 'moved', 'outcome' => (string) $look['outcome'], 'proposal_id' => null];
        } catch (\PDOException $e) {
            // The database refused this one (a lock wait that ran out, say): rolled back whole, it stays to check, the run goes on.
            return ['result' => 'failed', 'outcome' => (string) $look['outcome'], 'proposal_id' => null];
        }
        if ($fresh !== null) {
            $this->runRows[$source] = $fresh;
        }
        if ($r['result'] !== 'moved') {
            $this->mark($id, (int) $snap['seq'], $r['outcome'], $look['channel_id']);
        }
        return $r;
    }

    /**
     * The mark on the profile after a look: `done`, when, and what was found. A compare-and-set on the "to check" number, in its
     * own statement after the suggestion's transaction: a variant the intake marked again meanwhile keeps its `queued`.
     */
    private function mark(int $listingId, int $seq, string $outcome, ?int $channelId): void
    {
        $this->db->exec("UPDATE listing_profile SET arrival_state = 'done', arrival_checked_at = UTC_TIMESTAMP(6), arrival_outcome = ?, "
            . 'arrival_channel_id = COALESCE(arrival_channel_id, ?) WHERE listing_id = ? AND arrival_seq = ?', [mb_substr($outcome, 0, 32), $channelId, $listingId, $seq]);
    }

    /** The match_run row of this run for $source, made on first use (inside the caller's transaction). */
    private function runRow(string $source): int
    {
        if ($this->runId === null) {
            $base = 'arrival-' . gmdate('Ymd\THis\Z');
            $id = $base;
            for ($n = 2; $this->db->value('SELECT 1 FROM match_run WHERE run_id = ? LIMIT 1', [$id]) !== null; $n++) {
                $id = "{$base}-{$n}"; // two runs within one second (a test; never the timer)
            }
            $this->runId = $id;
        }
        return $this->proposals->run($this->runId, $source, null, self::engine(), null, ['rules' => ArrivalRules::VERSION, 'check' => 'arrival',
            'warned' => $source === self::SOURCE_WARNED, 'main_store' => self::text($this->db, 'mapping.arrival_main_store', self::DEFAULT_MAIN_STORE),
            'stores' => array_values(self::stores($this->db)), 'bulk_confirm' => self::bulkConfirm($this->db)]);
    }

    /** What made the answer: the rules, the Normalizer and the Veto, by their versions. Never a band version (the check has no band rules). */
    public static function engine(): string
    {
        return ArrivalRules::VERSION . '/' . Normalizer::VERSION . '/' . Veto::VERSION;
    }

    // ------------------------------------------------------------------------------------------------------------------
    // The refusals

    /**
     * Why this variant is not given a suggestion (null: it may be), and the check's own open suggestion when it has one. Asked
     * before the rules and again under the variant's lock; the reads are the same both times. In this order, so that the outcome
     * names the check that stopped it: its state, a hold, a spot check, then any other history, then the title.
     *
     * @param array<string, mixed> $l the channel_listing row
     * @param array<string, mixed>|null $p its profile
     * @return array{refusal: ?string, own: ?array<string, mixed>}
     */
    private function state(array $l, ?array $p): array
    {
        $id = (int) $l['id'];
        $refuse = static fn (string $why): array => ['refusal' => $why, 'own' => null];
        if (!in_array($l['status'], KeyEligibility::OPEN_LISTING, true)) {
            return $refuse('not_open');
        }
        if (KeyHold::activeForListings($this->db, [$id]) !== []) {
            return $refuse('held');
        }
        if ($this->db->value('SELECT 1 FROM key_sample_member WHERE listing_id = ? LIMIT 1', [$id]) !== null) {
            return $refuse('in_spot_check');
        }
        // Every suggestion this variant ever had, with its run's source: one of another run, in any state, is history.
        $own = null;
        foreach ($this->db->all('SELECT p.id, p.status, r.source FROM match_proposal p JOIN match_run r ON r.id = p.match_run_id WHERE p.listing_id = ?', [$id]) as $x) {
            if (!in_array($x['source'], self::SOURCES, true)) {
                return $refuse('has_history');
            }
            if ($x['status'] === 'open') {
                $own = (int) $x['id'];
            }
        }
        // A decision other than a `suggest` (a person's, an undo, a "sent back"), or one waiting for a second person.
        if ($this->db->value("SELECT 1 FROM match_decision WHERE listing_id = ? AND (action <> 'suggest' OR state <> 'applied') LIMIT 1", [$id]) !== null) {
            return $refuse('has_history');
        }
        if ($this->db->value('SELECT 1 FROM match_reject WHERE listing_id = ? LIMIT 1', [$id]) !== null) {
            return $refuse('has_history');
        }
        // `suggested` is only ever the check's own open suggestion here; `unmapped` has none.
        if (($l['status'] === 'suggested') !== ($own !== null)) {
            return $refuse('not_open');
        }
        if ($p === null || self::title($p) === '') {
            return $refuse('no_title');
        }
        if ($own === null) {
            return ['refusal' => null, 'own' => null];
        }
        $o = $this->db->one('SELECT id, band, lane, proposed_sku_id, closest_sku_id, flags, evidence FROM match_proposal WHERE id = ?', [$own]);
        $ev = json_decode((string) ($o['evidence'] ?? 'null'), true);
        return ['refusal' => null, 'own' => ['id' => $own, 'band' => (string) $o['band'], 'lane' => $o['lane'] === null ? null : (string) $o['lane'],
            'sku_id' => $o['proposed_sku_id'] === null ? null : (int) $o['proposed_sku_id'],
            'closest_sku_id' => $o['closest_sku_id'] === null ? null : (int) $o['closest_sku_id'],
            'flags' => self::strings(json_decode((string) ($o['flags'] ?? 'null'), true)),
            'reasons' => self::strings(is_array($ev) ? ($ev['band_reasons'] ?? null) : null)]];
    }

    // ------------------------------------------------------------------------------------------------------------------
    // The facts

    /** The profile as the check reads it (never locked). @return array<string, mixed>|null */
    private function profile(int $listingId): ?array
    {
        return $this->db->one('SELECT lp.product_title, lp.variant_title, lp.brand, lp.attributes, lp.barcodes, lp.price, lp.perma_link, lp.units_30d, lp.units_365d, '
            . 'lp.identity_hash, lp.arrival_state, lp.arrival_seq, '
            // The website state (M66): the stored one (the columns of 0028), and what a matching run's features recorded.
            . 'lp.site_variant_status, lp.site_product_status, lp.site_product_type, lp.site_product_id, lp.site_is_landing, lp.site_is_default, lp.site_state_at, '
            . "JSON_UNQUOTE(JSON_EXTRACT(lp.features, '$.variant_status')) AS features_status, JSON_EXTRACT(lp.features, '$.is_placeholder') AS is_placeholder "
            . 'FROM listing_profile lp WHERE lp.listing_id = ?', [$listingId]);
    }

    /**
     * The stored website state as the snapshot holds it: the values the readers use (SiteState::READ), or null when no state is
     * stored. Not its date: the same state seen again under a newer date is still what was read.
     *
     * @param array<string, mixed>|null $p the profile
     * @return array<string, mixed>|null
     */
    private static function storedState(?array $p): ?array
    {
        $stored = $p === null ? null : SiteState::stored($p);
        return $stored === null ? null : array_intersect_key($stored, array_flip(SiteState::READ));
    }

    /**
     * The website state of a variant as a suggestion's evidence records it (M66): its status when a reader gives one (the
     * stored state, else the features), and `website`: whether the warehouse KNOWS the state (a stored state with the variant's
     * status, its product's status and an answer for the page heading), as of when, the product's status, and the heading (true,
     * false, or null when not known). The heading of a stored state is worked out here (SiteState::heading(), with the published
     * variants of its product counted now); without a stored state it is what the features recorded. Nothing here decides
     * anything: the rules have answered before this is read.
     *
     * @param array<string, mixed> $p the profile
     * @return array{variant_status: ?string, website: array{known: bool, as_of: ?string, product_status: ?string, heading: ?bool}}
     */
    private function website(array $p, int $channelId): array
    {
        $state = SiteState::read($p, ['variant_status' => $p['features_status'] ?? null, 'is_placeholder' => $p['is_placeholder'] ?? null]);
        $heading = SiteState::headingInputs($state) ? SiteState::heading($state, $p, SiteState::publishedSiblings($this->db, $channelId, $state['product_id'])) : $state['heading'];
        return ['variant_status' => $state['variant_status'], 'website' => [
            'known' => $state['source'] === SiteState::STORED && $state['variant_status'] !== null && $state['product_status'] !== null && $heading !== null,
            'as_of' => $state['as_of'], 'product_status' => $state['product_status'], 'heading' => $heading]];
    }

    /**
     * What the rules read (ArrivalRules::decide): where the variant's usable barcodes are, which main-store variants have its
     * address, and for every item those name, the item's side of the comparison (and its whole record, `record`, which the rules
     * do not read: look() takes the named item's from it).
     *
     * @param array<string, mixed> $l
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function facts(array $l, array $p, ?int $mainStoreId): array
    {
        $id = (int) $l['id'];
        $channelId = (int) $l['channel_id'];
        $codes = json_decode((string) ($p['barcodes'] ?? 'null'), true);
        $keys = BarcodeSync::keys(is_array($codes) ? array_values($codes) : [])['usable'];
        $barcode = ['keys' => $keys, 'items' => [], 'not_usable' => false, 'same_store' => 0, 'main_no_item' => 0, 'other_no_item' => 0, 'variants' => []];
        if ($keys !== []) {
            // Every text the same barcode may be stored as (with and without its leading zeros): exact look-ups on two indexes.
            $forms = Gtin::forms($keys);
            $recorded = []; // barcode key => the items the barcode records give it to (the item's own record is the authority)
            foreach ($this->db->all('SELECT barcode, sku_id, is_usable, units_per_scan FROM sku_barcode WHERE barcode IN (' . implode(',', array_fill(0, count($forms), '?')) . ')',
                $forms) as $b) {
                $item = $this->root((int) $b['sku_id']);
                $barcode['items'][$item]['units_per_scan'] = max((int) ($barcode['items'][$item]['units_per_scan'] ?? 1), (int) $b['units_per_scan']);
                $barcode['not_usable'] = $barcode['not_usable'] || (int) $b['is_usable'] !== 1;
                $recorded[Gtin::key((string) $b['barcode']) ?? (string) $b['barcode']][(int) $b['sku_id']] = true;
            }
            $others = $this->db->all('SELECT /*+ JOIN_ORDER(lp, cl) */ lp.listing_id, lp.barcodes, cl.channel_id, cl.status, cl.sku_id, cl.units_per_item FROM listing_profile lp '
                . 'JOIN channel_listing cl ON cl.id = lp.listing_id WHERE JSON_OVERLAPS(lp.barcodes, CAST(? AS JSON)) AND lp.listing_id <> ? LIMIT ' . (self::MAX_SHARED + 1),
                [json_encode($forms, JSON_THROW_ON_ERROR), $id]);
            // More variants share it than are read: no clue to trust.
            $barcode['not_usable'] = $barcode['not_usable'] || count($others) > self::MAX_SHARED;
            foreach ($others as $o) {
                $onMain = $mainStoreId !== null && (int) $o['channel_id'] === $mainStoreId;
                if ((int) $o['channel_id'] === $channelId) {
                    $barcode['same_store']++;
                    continue;
                }
                if ($onMain) {
                    $barcode['variants'][] = (int) $o['listing_id'];
                }
                $names = $o['sku_id'] !== null && in_array($o['status'], self::LINKED, true) ? $this->namedBy($keys, $o, $recorded) : null;
                if ($names !== null) {
                    // A matched variant that carries the barcode names its item. Matched as a pack (units per item other than 1)
                    // and with no barcode record of the item's to say otherwise, the barcode may be the pack's: the rules' "pack"
                    // line then answers (the barcode sync asks a person the same question, BarcodeReviews `multipack_listing`).
                    $item = $this->root((int) $o['sku_id']);
                    $barcode['items'][$item]['units_per_scan'] = max((int) ($barcode['items'][$item]['units_per_scan'] ?? 1), $names['recorded'] ? 1 : (int) $o['units_per_item']);
                } else {
                    // (no item, or people ruled that this barcode is not its item's: it carries the code and names no item)
                    $barcode[$onMain ? 'main_no_item' : 'other_no_item']++;
                }
            }
        }
        $key = ArrivalRules::permaKey(is_string($p['perma_link'] ?? null) ? $p['perma_link'] : null);
        $address = ['key' => $key, 'variants' => []];
        $onMainStore = $mainStoreId !== null && $channelId === $mainStoreId;
        if ($key !== '' && $mainStoreId !== null && !$onMainStore) {
            // The index compares without letter case and accents (a superset): permaKey() in PHP decides, as the batch run does.
            foreach ($this->db->all('SELECT /*+ JOIN_ORDER(lp, cl) INDEX(lp ix_listing_profile_perma_key) */ lp.listing_id, lp.perma_link, cl.status, cl.sku_id, cl.units_per_item '
                . 'FROM listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id WHERE lp.perma_key = ? AND cl.channel_id = ? AND lp.listing_id <> ? '
                . 'ORDER BY lp.listing_id LIMIT ' . self::MAX_SHARED, [$key, $mainStoreId, $id]) as $v) {
                if (ArrivalRules::permaKey((string) $v['perma_link']) === $key) {
                    $linked = $v['sku_id'] !== null && in_array($v['status'], self::LINKED, true);
                    $address['variants'][] = ['listing_id' => (int) $v['listing_id'], 'sku_id' => $linked ? $this->root((int) $v['sku_id']) : null,
                        'units' => $linked ? (int) $v['units_per_item'] : 1];
                }
            }
        }
        $items = [];
        foreach (array_keys($barcode['items']) as $item) {
            $items[(int) $item] = true;
        }
        foreach ($address['variants'] as $v) {
            if ($v['sku_id'] !== null) {
                $items[$v['sku_id']] = true;
            }
        }
        foreach (array_keys($items) as $item) {
            $x = $this->item((int) $item, $channelId, $id, $mainStoreId);
            $items[$item] = ['features' => $x['features'], 'taken' => $x['taken'], 'record' => $x];
        }
        // The variant's own features are read only when there is an item to compare it with (normalising is the costly part of a look).
        return ['main_store' => $onMainStore, 'variant' => $items === [] ? null : self::features((string) ($this->channels[$channelId] ?? ''), (string) $l['external_variant_id'], $p),
            'barcode' => $barcode, 'address' => $address, 'items' => $items];
    }

    /**
     * Does this matched variant, which carries one of the barcodes, name its item through it? Null when people ruled it does not
     * (M65): for every barcode the two share, the item has no barcode record of its own for it AND the latest decided barcode
     * review of (barcode, the variant's item) keeps the barcode away from that item (BarcodeReviews::KEEPS_AWAY: removed from it
     * by a person, ruled another item's, ruled shared, not to be added). The barcode sync and the seeder obey the same rows
     * (BarcodeSync::plan(), the same read); a barcode record of the item's own wins over an older ruling, as there. The item is
     * the variant's own as stored (what a review names its claimant by), not the item it was joined into: a ruling made for an
     * item that was joined into another since is not found (the sync's own limit). One indexed read per (barcode, item) of a run.
     *
     * @param list<string> $keys the checked variant's usable barcodes
     * @param array<string, mixed> $o the other variant: barcodes, sku_id
     * @param array<string, array<int, true>> $recorded barcode key => the items a barcode record gives it to
     * @return array{recorded: bool}|null recorded: the item's own barcode record holds one of the shared barcodes
     */
    private function namedBy(array $keys, array $o, array $recorded): ?array
    {
        $sku = (int) $o['sku_id'];
        $codes = json_decode((string) ($o['barcodes'] ?? 'null'), true);
        $shared = array_values(array_intersect($keys, BarcodeSync::keys(is_array($codes) ? array_values($codes) : [])['usable']));
        $own = false;
        $away = $shared !== [];
        foreach ($shared as $key) {
            if (isset($recorded[$key][$sku])) {
                $own = true;
                $away = false;
                continue;
            }
            $this->ruled["{$key}:{$sku}"] ??= in_array($this->db->value("SELECT decision FROM barcode_review WHERE barcode = ? AND claimant_sku_id = ? AND status = 'decided' "
                . 'ORDER BY id DESC LIMIT 1', [$key, $sku]), BarcodeReviews::KEEPS_AWAY, true);
            $away = $away && $this->ruled["{$key}:{$sku}"];
        }
        return $away ? null : ['recorded' => $own];
    }

    /**
     * An item's side: its code and name, its representative variant (its origin listing while that is still matched to it, else
     * its lowest matched main-store variant, else its lowest matched variant: a few reads by primary key and by the (item, channel)
     * index, then one profile), that variant's features normalised afresh, and whether another variant of the asking variant's
     * store is matched to it, waits for a second OK to be, or has it suggested.
     *
     * @return array{sku_id: int, code: string, name: string, listing_id: ?int, variant: ?string, title: ?string, features: ?array<string, mixed>, taken: bool}
     */
    private function item(int $skuId, int $channelId, int $listingId, ?int $mainStoreId): array
    {
        if (!isset($this->items[$skuId])) {
            if (count($this->items) >= 2000) {
                $this->items = []; // a long scoring run: the features of two thousand items are enough to remember
            }
            $s = $this->db->one('SELECT s.id, s.code, s.name, s.origin_listing_id FROM sku s WHERE s.id = ?', [$skuId]) ?? ['code' => '', 'name' => '', 'origin_listing_id' => null];
            $linked = "cl.sku_id = ? AND cl.status IN ('mapped', 'quarantined')";
            $rep = $s['origin_listing_id'] === null ? null : $this->db->value("SELECT cl.id FROM channel_listing cl WHERE cl.id = ? AND {$linked}", [(int) $s['origin_listing_id'], $skuId]);
            if ($rep === null && $mainStoreId !== null) {
                $rep = $this->db->value("SELECT cl.id FROM channel_listing cl WHERE {$linked} AND cl.channel_id = ? ORDER BY cl.id LIMIT 1", [$skuId, $mainStoreId]);
            }
            $rep ??= $this->db->value("SELECT cl.id FROM channel_listing cl WHERE {$linked} ORDER BY cl.id LIMIT 1", [$skuId]);
            $r = $rep === null ? null : $this->db->one('SELECT cl.external_variant_id, ch.code AS store, lp.product_title, lp.variant_title, lp.brand, lp.attributes, lp.barcodes, '
                . 'lp.price, lp.units_30d FROM channel_listing cl JOIN channel ch ON ch.id = cl.channel_id JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.id = ?', [(int) $rep]);
            $this->items[$skuId] = ['sku_id' => $skuId, 'code' => (string) $s['code'], 'name' => (string) $s['name'], 'listing_id' => $r === null ? null : (int) $rep,
                'variant' => $r === null ? null : (string) $r['external_variant_id'], 'title' => $r === null ? null : self::title($r),
                'features' => $r === null ? null : self::features((string) $r['store'], (string) $r['external_variant_id'], $r), 'taken' => []];
        }
        $x = $this->items[$skuId];
        // (asked per variant: the answer depends on which variant asks; the three reads are on narrow indexes)
        $taken = $this->db->value('SELECT 1 FROM channel_listing cl WHERE cl.sku_id = ? AND cl.channel_id = ? AND cl.id <> ? LIMIT 1', [$skuId, $channelId, $listingId]) !== null
            || $this->db->value('SELECT /*+ INDEX(p ix_match_proposal_open_sku) */ 1 FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id '
                . "WHERE p.status = 'open' AND p.proposed_sku_id = ? AND p.listing_id <> ? AND cl.channel_id = ? LIMIT 1", [$skuId, $listingId, $channelId]) !== null
            || $this->db->value('SELECT 1 FROM match_decision d JOIN channel_listing cl ON cl.id = d.pending_listing_id '
                . "WHERE d.sku_id = ? AND d.pending_listing_id IS NOT NULL AND d.pending_listing_id <> ? AND d.action = 'link' AND cl.channel_id = ? LIMIT 1",
                [$skuId, $listingId, $channelId]) !== null;
        return ['taken' => $taken] + $x;
    }

    /** The item an item is now: itself, or the one it was joined into (DecisionService::rootOf), remembered for the run. */
    private function root(int $skuId): int
    {
        return $this->roots[$skuId] ??= $this->ds->rootOf($skuId);
    }

    /**
     * A profile's features, normalised afresh (stored features lack the words the comparison reads); null when it cannot be read.
     *
     * @param array<string, mixed> $p a profile row
     * @return array<string, mixed>|null
     */
    private static function features(string $store, string $variant, array $p): ?array
    {
        $attrs = is_string($p['attributes'] ?? null) ? json_decode((string) $p['attributes'], true) : null;
        $attrs = is_array($attrs) ? (is_array($attrs['items'] ?? null) ? $attrs['items'] : (array_is_list($attrs) ? $attrs : [])) : [];
        $codes = is_string($p['barcodes'] ?? null) ? json_decode((string) $p['barcodes'], true) : null;
        try {
            return Normalizer::normalize(['site' => $store, 'variant_id' => ctype_digit($variant) ? (int) $variant : 0, 'product_title' => (string) ($p['product_title'] ?? ''),
                'variant_title' => (string) ($p['variant_title'] ?? ''), 'brand' => (string) ($p['brand'] ?? ''), 'attributes' => $attrs,
                'barcodes' => is_array($codes) ? array_values($codes) : [], 'price' => $p['price'] ?? null, 'sale_price' => null, 'units_30d' => (int) ($p['units_30d'] ?? 0)]);
        } catch (\Throwable) {
            return null;
        }
    }

    /** A variant's title as the matching shows it: the option's title, else the product's (what KeyEligibility::sameTitle compares). @param array<string, mixed> $p */
    private static function title(array $p): string
    {
        $vt = Text::clean(is_string($p['variant_title'] ?? null) ? $p['variant_title'] : null);
        return $vt !== '' ? $vt : Text::clean(is_string($p['product_title'] ?? null) ? $p['product_title'] : null);
    }

    /**
     * The suggestion's evidence: what the check read and why it says what it says. No `ai` block: no AI looked, and without one
     * the evidence can never be replayed as a judged match (StoredBand). The run id is added when it is written.
     *
     * @param array<string, mixed> $p the profile
     * @param array<string, mixed> $answer
     * @param array<string, mixed> $facts
     * @param array<string, mixed>|null $target item()
     * @param array{variant_status: ?string, website: array<string, mixed>} $site website()
     * @return array<string, mixed>
     */
    private static function evidence(array $p, array $answer, array $facts, ?array $target, array $site): array
    {
        $ev = ['check' => 'arrival', 'rules' => ArrivalRules::VERSION, 'title' => self::title($p)];
        if ($site['variant_status'] !== null) {
            $ev['variant_status'] = $site['variant_status']; // a text, written only when the status is known
        }
        $ev['website'] = $site['website'];
        $lane = null;
        if ($target !== null) {
            $lane = ['sku_id' => $target['sku_id'], 'code' => $target['code'], 'title' => $target['title'] ?? $target['name'], 'via' => $answer['via']];
            if ($target['listing_id'] !== null) {
                $lane['listing_id'] = $target['listing_id'];
                if (preg_match('/^\d{1,20}$/D', (string) $target['variant']) === 1) {
                    $lane['vpg_variant_id'] = (string) $target['variant']; // lets the basis record this variant's link (ProposalBasis::findTarget)
                }
            }
        }
        return $ev + ['units_365d' => $p['units_365d'] === null ? null : (int) $p['units_365d'], 'lane' => $answer['lane'], 'lane_target' => $lane,
            'lane_flags' => $answer['lane_flags'], 'target_vetoes' => $answer['target_vetoes'], 'target_soft_flags' => $answer['target_soft_flags'],
            'band_reasons' => $answer['reasons'], 'arrival' => ['reason' => $answer['outcome']] + self::brief($facts)];
    }

    /** The facts without the features (what the evidence and a listing of the run keep). @param array<string, mixed> $facts @return array<string, mixed> */
    private static function brief(array $facts): array
    {
        $b = $facts['barcode'];
        return ['barcode' => ['keys' => $b['keys'], 'items' => array_map('intval', array_keys($b['items'])), 'not_usable' => $b['not_usable'], 'same_store' => $b['same_store'],
            'main_no_item' => $b['main_no_item'], 'other_no_item' => $b['other_no_item']],
            'address' => ['key' => $facts['address']['key'], 'variants' => array_slice($facts['address']['variants'], 0, 10)]];
    }

    // ------------------------------------------------------------------------------------------------------------------
    // Counting and printing

    /** What a dry run says would be done. @param array<string, mixed> $look */
    private static function planned(array $look): string
    {
        return match ($look['action']) {
            'write' => 'written',
            'keep' => 'kept',
            default => 'left',
        };
    }

    /**
     * @param array<string, mixed> $s a store's counts
     * @param array{result: string, outcome: string, proposal_id: ?int} $done
     * @param array<string, mixed> $look
     */
    private static function count(array &$s, array $done, array $look): void
    {
        switch ($done['result']) {
            case 'written':
                $s[match ($look['answer']['band']) {
                    ArrivalRules::CHECK => 'match',
                    ArrivalRules::CONFLICT => 'conflict',
                    default => 'not_sure',
                }]++;
                break;
            case 'kept':
                $s['kept']++;
                break;
            case 'moved':
                $s['moved']++;
                break;
            case 'failed':
                $s['failed']++;
                break;
            default:
                $s['left'][$done['outcome']] = ($s['left'][$done['outcome']] ?? 0) + 1;
        }
    }

    /**
     * One variant of a run in one line.
     *
     * @param array<string, mixed> $look
     * @param array{result: string, outcome: string, proposal_id: ?int} $done
     */
    public static function line(array $look, array $done): string
    {
        $a = $look['answer'];
        return sprintf('listing=%d store=%s variant=%s %s outcome=%s%s%s%s title=%s', $look['listing_id'], $look['store'] ?? '-', $look['variant'] ?? '-', $done['result'],
            $done['outcome'], $a['band'] === null ? '' : ' strength="' . $a['band'] . '" lane=' . $a['lane'],
            $look['target'] === null ? '' : ($a['sku_id'] !== null ? ' item=' : ' closest=') . $look['target']['code'],
            ($a['flags'] ?? []) === [] ? '' : ' flags=' . implode(',', $a['flags']), json_encode(mb_substr((string) ($look['title'] ?? ''), 0, 80), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ------------------------------------------------------------------------------------------------------------------
    // The scoring (read only)

    /**
     * How the rules compare with a judged run (design section 12, T1 and T2), reading only: for every variant of the store that
     * has a suggestion of that run, what the rules say now with its history ignored, against
     *  - T1: the run's own deterministic clue: its lane (`barcode`, `transfer`, or none: the run then searched by name) and the
     *    item its lane target is now. Every difference is listed with what each side says;
     *  - T2: where the rules say "match", the item the run proposed (its judge's pick), and, for the variants a person has matched
     *    since, the item the variant is matched to.
     * Limits to read the numbers with: the run's truth is its AI plus a key, except the matches a person made; the run looked
     * only at variants that sold; it was made by an older Normalizer.
     *
     * @return array{run_id: string, store: string, variants: int, t1: array<string, int>, t1_misses: list<string>, t2_run: array<string, int>, t2_run_misses: list<string>,
     *     t2_person: array<string, int>, t2_person_misses: list<string>, outcomes: array<string, int>, ms: int}
     */
    public function score(string $store, ?string $runId = null): array
    {
        $t0 = hrtime(true);
        $channelId = $this->db->value('SELECT id FROM channel WHERE code = ?', [$store]);
        if ($channelId === null) {
            throw new CwException('unknown_store', "there is no store {$store}", 404);
        }
        $channelId = (int) $channelId;
        $this->channels[$channelId] = $store;
        $skip = [...self::SOURCES, ...KeySample::NOT_A_MATCHING_RUN];
        $run = $runId !== null
            ? $this->db->one('SELECT id, run_id FROM match_run WHERE run_id = ? AND source NOT IN (' . implode(',', array_fill(0, count($skip), '?')) . ') ORDER BY id LIMIT 1', [$runId, ...$skip])
            // The newest judged run that suggested for this store (match_run is a few rows; each is asked for one suggestion).
            : $this->db->one('SELECT r.id, r.run_id FROM match_run r WHERE r.source NOT IN (' . implode(',', array_fill(0, count($skip), '?')) . ') AND EXISTS (SELECT 1 FROM match_proposal p '
                . 'JOIN channel_listing cl ON cl.id = p.listing_id WHERE p.match_run_id = r.id AND cl.channel_id = ?) ORDER BY r.id DESC LIMIT 1', [...$skip, $channelId]);
        if ($run === null) {
            throw new CwException('unknown_run', $runId === null ? "no judged run has a suggestion for {$store}" : "there is no judged run {$runId}", 404);
        }
        $main = self::mainStore($this->db);
        $this->items = [];
        $out = ['run_id' => (string) $run['run_id'], 'store' => $store, 'variants' => 0,
            't1' => ['same_clue_same_item' => 0, 'both_no_clue' => 0, 'same_item_other_lane' => 0, 'rule_has_no_item' => 0, 'run_has_no_item' => 0, 'other_item' => 0,
                'rule_clue_run_none' => 0, 'run_clue_rule_none' => 0],
            't1_misses' => [],
            't2_run' => ['rule_says_match' => 0, 'same_item' => 0, 'other_item' => 0, 'run_proposed_none' => 0], 't2_run_misses' => [],
            't2_person' => ['matched_by_a_person' => 0, 'rule_same_item' => 0, 'rule_other_item' => 0, 'rule_no_item' => 0], 't2_person_misses' => [],
            'outcomes' => [], 'ms' => 0];
        $after = 0;
        do {
            // The run's suggestions in listing order, by its own index (run, listing); a page at a time: the evidence is wide.
            $rows = $this->db->all('SELECT p.listing_id, p.band, p.lane, p.proposed_sku_id, p.evidence, cl.status, cl.sku_id, cl.external_variant_id FROM match_proposal p '
                . 'JOIN channel_listing cl ON cl.id = p.listing_id WHERE p.match_run_id = ? AND p.listing_id > ? AND cl.channel_id = ? ORDER BY p.listing_id LIMIT ' . self::CHUNK,
                [(int) $run['id'], $after, $channelId]);
            foreach ($rows as $r) {
                $after = (int) $r['listing_id'];
                $out['variants']++;
                $look = $this->look($after, $main, true);
                $a = $look['answer'];
                $out['outcomes'][$look['outcome']] = ($out['outcomes'][$look['outcome']] ?? 0) + 1;
                $ev = json_decode((string) ($r['evidence'] ?? 'null'), true);
                $ev = is_array($ev) ? $ev : [];
                $theirLane = in_array($r['lane'], [ArrivalRules::LANE_BARCODE, ArrivalRules::LANE_COPY], true) ? (string) $r['lane'] : null;
                $t = is_array($ev['lane_target'] ?? null) && is_int($ev['lane_target']['sku_id'] ?? null) ? $this->root($ev['lane_target']['sku_id']) : null;
                $theirItem = $theirLane === null ? null : $t;
                $myLane = $a['suggest'] ? (string) $a['lane'] : null;
                $myItem = $a['target'];
                $who = "listing {$after} (variant {$r['external_variant_id']}) " . json_encode(mb_substr((string) ($look['title'] ?? ''), 0, 60), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $mine = 'rule: ' . ($myLane ?? 'no clue') . ' ' . $look['outcome'] . ($myItem === null ? '' : ' ' . $this->code($myItem));
                $theirs = 'run: ' . ($theirLane ?? (string) $r['lane']) . ' ' . $r['band'] . ($theirItem === null ? '' : ' ' . $this->code($theirItem))
                    . (self::strings($ev['lane_flags'] ?? null) === [] ? '' : ' [' . implode(',', self::strings($ev['lane_flags'])) . ']');
                $t1 = match (true) {
                    $myLane === null && $theirLane === null => 'both_no_clue',
                    $myLane === null => 'run_clue_rule_none',
                    $theirLane === null => 'rule_clue_run_none',
                    $myItem !== null && $theirItem !== null && $myItem === $theirItem => $myLane === $theirLane ? 'same_clue_same_item' : 'same_item_other_lane',
                    $myItem !== null && $theirItem !== null => 'other_item',
                    $myItem === null && $theirItem === null => $myLane === $theirLane ? 'same_clue_same_item' : 'same_item_other_lane',
                    $myItem === null => 'rule_has_no_item',
                    default => 'run_has_no_item',
                };
                $out['t1'][$t1]++;
                if (!in_array($t1, ['same_clue_same_item', 'both_no_clue'], true)) {
                    $out['t1_misses'][] = "{$t1}: {$who}: {$mine}; {$theirs}";
                }
                if ($a['sku_id'] !== null) {
                    $out['t2_run']['rule_says_match']++;
                    $picked = $r['proposed_sku_id'] === null ? null : $this->root((int) $r['proposed_sku_id']);
                    $k = $picked === null ? 'run_proposed_none' : ($picked === (int) $a['sku_id'] ? 'same_item' : 'other_item');
                    $out['t2_run'][$k]++;
                    if ($k !== 'same_item') {
                        $out['t2_run_misses'][] = "{$k}: {$who}: {$mine}; run proposed " . ($picked === null ? "none ({$r['band']})" : $this->code($picked) . " ({$r['band']})");
                    }
                }
                if ($r['sku_id'] !== null && in_array($r['status'], self::LINKED, true) && $this->matchedByAPerson($after)) {
                    $out['t2_person']['matched_by_a_person']++;
                    $is = $this->root((int) $r['sku_id']);
                    $k = $myItem === null ? 'rule_no_item' : ($myItem === $is ? 'rule_same_item' : 'rule_other_item');
                    $out['t2_person'][$k]++;
                    if ($k !== 'rule_same_item') {
                        $out['t2_person_misses'][] = "{$k}: {$who}: {$mine}; a person matched it to " . $this->code($is);
                    }
                }
            }
        } while (count($rows) === self::CHUNK);
        ksort($out['outcomes']);
        $out['ms'] = intdiv(hrtime(true) - $t0, 1_000_000);
        return $out;
    }

    /** Whether the variant's match was made by a person (its latest applied link or new item names who decided). */
    private function matchedByAPerson(int $listingId): bool
    {
        return $this->db->value("SELECT decided_by FROM match_decision WHERE listing_id = ? AND action IN ('link', 'new_item') AND state = 'applied' ORDER BY id DESC LIMIT 1",
            [$listingId]) !== null;
    }

    private function code(int $skuId): string
    {
        return (string) ($this->db->value('SELECT code FROM sku WHERE id = ?', [$skuId]) ?? "item {$skuId}");
    }

    /** A string setting read straight from the database: $default when its row is not there (a schema before 0027). */
    private static function text(Db $db, string $key, string $default): string
    {
        $v = $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ? AND value_type = 'string'", [$key]);
        if ($v === null) {
            return $default;
        }
        $text = json_decode((string) $v, false, 4);
        return is_string($text) ? trim($text) : '';
    }

    /** @return list<string> */
    private static function strings(mixed $v): array
    {
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }
}
