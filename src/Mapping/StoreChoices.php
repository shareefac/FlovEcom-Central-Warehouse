<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Matching\Gtin;
use CW\Queues\QueueRules;

/**
 * A store's choice at Add product (the owner's drawing of 3 Nov 2026, "Add product to CW"; tracker CW-217; docs/decisions.md SC1-SC12).
 * When staff add a product or a variant on a store, they say what each new variant is for the warehouse: a new item (the default), a pack
 * of an item the warehouse has (its CW number and how many one pack holds), or not stock (a page that holds none). This class takes the
 * choices from the store (receive(), PUT /v1/store-choices), follows them once the variant has arrived (run(), bin/apply_store_choices.php
 * every minute) and lets a person answer the ones it does not follow alone (answer(), Approvals > New products).
 *
 * WHAT IT DOES, per choice (SC3-SC6), only when the variant is there (its listing and its profile) and nobody has decided it:
 *  - new   the warehouse looks first: a suggestion of the check on arrival that names an item, a barcode of the variant on an item or on
 *          another store's variant, an item with the same name or with a name that differs only in letter case, spaces and punctuation
 *          (StoreChoices::key()). Any of them: nothing is made, the row waits for a person (to_check, with what was found). None: a new
 *          item is made from the variant (DecisionService `new_item`, the item's card from the variant's own details, as the single
 *          "Create a new product" makes it).
 *  - pack  a link to the item its CW number names (followed to the item it was joined into) with that many units per sale
 *          (DecisionService `link`). The two-person rule of a link whose units are not 1 still applies: while approvals.match_multiple is
 *          on, the link waits for a matching lead on Approvals (the store's staff member chose it, a warehouse person says yes).
 *  - none  the system's landing mark (DecisionService `landing`, reason `store_choice`): the variant leaves every matching list.
 * The decisions are made as Caller::system(JOB) (actor system:store_choice): no warehouse person made them; whose choice it was, on which
 * store and when, is in each decision's detail (`store_choice`) and its reason.
 *
 * WHAT IT NEVER DOES (SC7): it never touches a variant a person (or the system's landing mark) has already decided: linked, ignored, a
 * landing page, held back, in a spot check, with a decision waiting for a second person, or with any earlier decision of a person
 * (`has_history`, to_check). It never makes a second item when one with the same name, or a barcode of it, is there. It never replaces a
 * choice: the first one the store sent for a variant is kept (receive() ignores the next). It never deletes. A decision it cannot make
 * because something moved under it (the variant's version, its suggestion) is tried again on the next run.
 *
 * The job and the person lock the choice's row first (FOR UPDATE), then DecisionService takes its own locks in its own order, in the same
 * transaction; the row's state is written last, on the row already locked (nothing new is locked after the feed clock).
 */
final class StoreChoices
{
    /** Caller::system(JOB): the job that follows the choices (DecisionService::STORE_CHOICE_ACTOR). */
    public const JOB = 'store_choice';
    public const CHOICES = ['new', 'pack', 'none'];
    public const STATES = ['waiting', 'done', 'to_check', 'refused'];
    /** The states a person answers (Approvals > New products). */
    public const OPEN = ['to_check', 'refused'];
    /** Who answers them: whoever may make a matching decision (re-read by DecisionService on each answer). */
    public const PERM = 'mapping.decide';
    public const MAX_CHOICES = 500;
    public const PER_RUN = 200;
    public const MIN_UNITS = 2;
    /**
     * What a row's `outcome` says (SC8).
     *   done      made (a new item), linked (a pack's link), waiting_second (a pack's link waits for a matching lead), not_stock (marked),
     *             already_decided (someone decided the variant first: nothing done), and a person's answers: joined, made_by_person,
     *             not_stock_by_person, decided_elsewhere (on the variant's own page).
     *   to_check  suggested_match, conflict, barcode_on_item, barcode_on_listing, same_name, close_name, said_real, held, has_history.
     *   refused   no_such_item, no_name, and any other refusal of DecisionService (its code).
     */
    public const OUTCOMES_DONE = ['made', 'linked', 'waiting_second', 'not_stock', 'already_decided', 'joined', 'made_by_person', 'not_stock_by_person',
        'decided_elsewhere'];
    public const OUTCOMES_CHECK = ['suggested_match', 'conflict', 'barcode_on_item', 'barcode_on_listing', 'same_name', 'close_name', 'said_real', 'held',
        'has_history'];
    /** A person's answers (answer()): the item found, a new item, not stock. */
    public const ANSWERS = ['same', 'new', 'none'];
    /** What key() leaves out of a name: everything but letters and digits (PCRE and MySQL's ICU read it alike). */
    private const KEY_DROP = '[^\\p{L}\\p{N}]';
    /** Refusals of DecisionService that only mean "something moved under the job": the row stays waiting for the next run. */
    private const AGAIN = ['map_version_conflict', 'proposal_changed', 'proposal_closed'];

    private readonly DecisionService $ds;

    public function __construct(private readonly Db $db, ?DecisionService $ds = null)
    {
        $this->ds = $ds ?? new DecisionService($db);
    }

    // ------------------------------------------------------------------------------------------
    // The store sends its choices (PUT /v1/store-choices)
    // ------------------------------------------------------------------------------------------

    /**
     * Takes a store's choices (SC2): each new variant's first choice is kept, waiting for the job; a variant the warehouse already has a
     * choice for is answered `already_received` and nothing changes (a store sending again after a timeout is harmless). The whole call is
     * refused (400) when any choice is malformed, so a store never half-sends a product.
     *
     * @return array{accepted: int, ignored: list<array{variant_id: string, why: string}>}
     */
    public function receive(Caller $caller, mixed $choices): array
    {
        if (!$caller->isChannel()) {
            throw new CwException('channel_required', 'choices are sent by a store', 403);
        }
        if (!is_array($choices) || $choices === [] || !array_is_list($choices)) {
            throw new CwException('bad_request', 'choices must be a non-empty list', 400, ['field' => 'choices']);
        }
        if (count($choices) > self::MAX_CHOICES) {
            throw new CwException('bad_request', 'send at most ' . self::MAX_CHOICES . ' choices per call', 413, ['field' => 'choices']);
        }
        $rows = [];
        foreach ($choices as $i => $c) {
            $row = self::parse($c, $i);
            if (isset($rows[$row['variant_id']])) {
                throw new CwException('bad_request', "variant {$row['variant_id']} appears twice", 400, ['field' => "choices[{$i}].variant_id"]);
            }
            $rows[$row['variant_id']] = $row;
        }
        $channelId = (int) $caller->channelId;
        return $this->db->transaction(function (Db $db) use ($caller, $channelId, $rows): array {
            $variants = array_map('strval', array_keys($rows));
            sort($variants, SORT_STRING);
            $have = [];
            foreach (array_chunk($variants, 500) as $chunk) {
                foreach ($db->all('SELECT external_variant_id FROM store_choice WHERE channel_id = ? AND external_variant_id IN ('
                    . implode(',', array_fill(0, count($chunk), '?')) . ') FOR UPDATE', [$channelId, ...$chunk]) as $r) {
                    $have[(string) $r['external_variant_id']] = true;
                }
            }
            $ignored = [];
            $accepted = 0;
            foreach ($variants as $v) {
                if (isset($have[$v])) {
                    $ignored[] = ['variant_id' => $v, 'why' => 'already_received'];
                    continue;
                }
                $r = $rows[$v];
                try {
                    $db->exec('INSERT INTO store_choice (channel_id, external_variant_id, choice, pack_of_code, units, staff_ref, staff_name, chosen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [$channelId, $v, $r['choice'], $r['pack_of'], $r['units'], $r['staff_ref'], $r['staff_name'], $r['chosen_at']]);
                } catch (\PDOException $e) {
                    if (Db::driverCode($e) !== 1062) {
                        throw $e;
                    }
                    // Another send of the same variant got there first (the FOR UPDATE above locks no row that was not there): its choice is kept.
                    $ignored[] = ['variant_id' => $v, 'why' => 'already_received'];
                    continue;
                }
                $accepted++;
            }
            Audit::write($db, $caller, 'store_choice.receive', 'channel', (string) $channelId, null, ['accepted' => $accepted, 'ignored' => count($ignored)]);
            return ['accepted' => $accepted, 'ignored' => $ignored];
        });
    }

    /**
     * One choice of a call, checked (SC2): {variant_id, choice, pack_of, units, staff: {id, name}, chosen_at}.
     *
     * @return array{variant_id: string, choice: string, pack_of: ?string, units: ?int, staff_ref: string, staff_name: string, chosen_at: string}
     */
    private static function parse(mixed $c, int $i): array
    {
        $bad = static fn (string $field, string $what): CwException => new CwException('bad_request', "choices[{$i}].{$field} {$what}", 400,
            ['field' => "choices[{$i}].{$field}"]);
        if (!is_array($c) || array_is_list($c)) {
            throw $bad('', 'must be an object');
        }
        $v = $c['variant_id'] ?? null;
        if (is_int($v) && $v > 0) {
            $v = (string) $v;
        }
        if (!is_string($v) || preg_match('/^[0-9]{1,64}$/D', $v) !== 1) {
            throw $bad('variant_id', 'must be the store\'s variant number (digits)');
        }
        $choice = $c['choice'] ?? null;
        if (!is_string($choice) || !in_array($choice, self::CHOICES, true)) {
            throw $bad('choice', 'must be one of ' . implode(', ', self::CHOICES));
        }
        $packOf = $c['pack_of'] ?? null;
        $units = $c['units'] ?? null;
        if ($choice === 'pack') {
            if (!is_string($packOf) || preg_match('/^CW-[0-9]{6,9}$/D', $packOf) !== 1) {
                throw $bad('pack_of', 'must be the CW number of the item one pack holds (CW-000123)');
            }
            if (!is_int($units) || $units < self::MIN_UNITS || $units > DecisionService::MAX_UNITS) {
                throw $bad('units', 'must be how many of that item one pack holds, ' . self::MIN_UNITS . ' to ' . DecisionService::MAX_UNITS);
            }
        } elseif ($packOf !== null || $units !== null) {
            throw $bad('pack_of', 'and units are for a pack only');
        }
        $staff = $c['staff'] ?? null;
        $ref = is_array($staff) ? ($staff['id'] ?? null) : null;
        $name = is_array($staff) ? ($staff['name'] ?? null) : null;
        if (is_int($ref)) {
            $ref = (string) $ref;
        }
        $printable = static fn (mixed $s, int $max): bool => is_string($s) && trim($s) !== '' && mb_strlen($s) <= $max && preg_match('/^[^\p{C}]+$/uD', $s) === 1;
        if (!$printable($ref, 64)) {
            throw $bad('staff.id', 'must say who chose it (the store\'s staff number, at most 64)');
        }
        if (!$printable($name, 128)) {
            throw $bad('staff.name', 'must be the name of who chose it (at most 128)');
        }
        $when = self::time($c['chosen_at'] ?? null);
        if ($when === null) {
            throw $bad('chosen_at', 'must be a real time with its zone, from 2020 on (2026-11-03T10:42:00Z)');
        }
        return ['variant_id' => $v, 'choice' => $choice, 'pack_of' => $choice === 'pack' ? $packOf : null, 'units' => $choice === 'pack' ? $units : null,
            'staff_ref' => trim((string) $ref), 'staff_name' => trim((string) $name), 'chosen_at' => Clock::db($when)];
    }

    /**
     * A time with its zone (RFC 3339, with or without milliseconds), as UTC, or null: a day that does not exist (31 February) is not moved
     * to another, a stray byte is not a time, and only 2020 to 2099 is believed.
     */
    private static function time(mixed $at): ?\DateTimeImmutable
    {
        if (!is_string($at) || strlen($at) > 40 || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/D', $at) !== 1) {
            return null;
        }
        foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'] as $format) {
            $t = \DateTimeImmutable::createFromFormat('!' . $format, str_replace('Z', '+00:00', $at));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($t !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                $utc = $t->setTimezone(Clock::utc());
                $year = (int) $utc->format('Y');
                return $year >= 2020 && $year <= 2099 ? $utc : null;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------------------------------
    // The job (bin/apply_store_choices.php)
    // ------------------------------------------------------------------------------------------

    /**
     * Follows the waiting choices whose variant has arrived (oldest first, at most $limit), and settles the open ones a person decided
     * on the variant's own page meanwhile (decided_elsewhere). A dry run reads and says what it would do, and writes nothing.
     *
     * @return array{looked: int, counts: array<string, int>, rows: list<array{id: int, listing_id: int, outcome: string, state: string}>}
     */
    public function run(bool $apply, int $limit = self::PER_RUN): array
    {
        $counts = [];
        $rows = [];
        $waiting = $this->db->all(
            'SELECT sc.id, cl.id AS listing_id FROM store_choice sc '
            . 'JOIN channel_listing cl ON cl.channel_id = sc.channel_id AND cl.external_variant_id = sc.external_variant_id '
            . 'JOIN listing_profile lp ON lp.listing_id = cl.id '
            . "WHERE sc.state = 'waiting' ORDER BY sc.id LIMIT " . max(1, min(5000, $limit)));
        foreach ($waiting as $w) {
            $r = $apply ? $this->follow((int) $w['id']) : $this->plan((int) $w['id']);
            if ($r === null) {
                continue;
            }
            $counts[$r['outcome']] = ($counts[$r['outcome']] ?? 0) + 1;
            $rows[] = ['id' => (int) $w['id'], 'listing_id' => (int) $w['listing_id'], 'outcome' => $r['outcome'], 'state' => $r['state']];
        }
        foreach ($this->decidedElsewhere() as $id) {
            if ($apply) {
                $this->db->exec("UPDATE store_choice SET state = 'done', outcome = 'decided_elsewhere', looked_at = ? WHERE id = ? AND state IN ('to_check', 'refused')",
                    [Clock::db(Clock::now()), $id]);
            }
            $counts['decided_elsewhere'] = ($counts['decided_elsewhere'] ?? 0) + 1;
        }
        return ['looked' => count($waiting), 'counts' => $counts, 'rows' => $rows];
    }

    /**
     * What following one waiting choice would do now, written nowhere (a dry run's line).
     *
     * @return array{state: string, outcome: string, detail: array<string, mixed>}|null null: not waiting, or its variant is not there
     */
    public function plan(int $id): ?array
    {
        $c = $this->choice($id, false);
        if ($c === null || $c['state'] !== 'waiting' || $c['listing'] === null) {
            return null;
        }
        $look = $this->look($c);
        return ['state' => $look['state'], 'outcome' => $look['outcome'], 'detail' => $look['detail']];
    }

    /**
     * Follows one waiting choice (SC3-SC6): looks, then makes the decision, all in one transaction with the row locked first; a refusal
     * of the decision is written in a transaction of its own (the decision's was rolled back), or leaves the row waiting when only
     * something moved under it.
     *
     * @return array{state: string, outcome: string, detail: array<string, mixed>}|null null: not waiting (any more), or its variant is not there
     */
    public function follow(int $id): ?array
    {
        $caller = Caller::system(self::JOB);
        try {
            return $this->db->transaction(function () use ($id, $caller): ?array {
                $c = $this->choice($id, true);
                if ($c === null || $c['state'] !== 'waiting' || $c['listing'] === null) {
                    return null;
                }
                $look = $this->look($c);
                if ($look['outcome'] === 'made') {
                    // The name's guard row (as an item made by hand takes it, HandMade): a making of the same name at this moment waits for
                    // the other, then everything is looked at again with fresh reads (READ COMMITTED), so two makings never see "no such
                    // item" at once (SC6).
                    $this->db->exec('INSERT INTO item_name_guard (name_key, last_at) VALUES (SHA2(WEIGHT_STRING(CAST(? AS CHAR CHARACTER SET utf8mb4) '
                        . 'COLLATE utf8mb4_0900_ai_ci), 256), UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE last_at = UTC_TIMESTAMP(6)', [(string) $look['detail']['name']]);
                    $look = $this->look($c);
                }
                $decision = null;
                if (isset($look['request'])) {
                    $decision = $this->ds->decide($caller, $look['request'] + ['store_choice' => self::who($c)]);
                    if ($decision['state'] === 'pending_second') {
                        $look['outcome'] = 'waiting_second';
                    }
                    if ($look['outcome'] === 'made') {
                        $look['detail']['sku'] = (string) $this->db->value('SELECT code FROM sku WHERE id = ?', [$decision['sku_id']]);
                    }
                }
                $this->settle($c, $look['state'], $look['outcome'], $look['detail'], $decision['decision_id'] ?? null, null);
                return ['state' => $look['state'], 'outcome' => $look['outcome'], 'detail' => $look['detail']];
            });
        } catch (CwException $e) {
            if (in_array($e->errorCode, self::AGAIN, true)) {
                $this->db->exec('UPDATE store_choice SET looked_at = ? WHERE id = ?', [Clock::db(Clock::now()), $id]);
                return ['state' => 'waiting', 'outcome' => 'again', 'detail' => ['code' => $e->errorCode]];
            }
            [$state, $outcome] = self::refusal($e->errorCode);
            return $this->db->transaction(function () use ($id, $state, $outcome, $e): ?array {
                $c = $this->choice($id, true);
                if ($c === null || $c['state'] !== 'waiting') {
                    return null;
                }
                $detail = ['code' => $e->errorCode, 'message' => mb_substr($e->getMessage(), 0, 300)];
                $this->settle($c, $state, $outcome, $detail, null, null);
                return ['state' => $state, 'outcome' => $outcome, 'detail' => $detail];
            });
        }
    }

    /**
     * What to do with one choice whose variant is there (no write): ['state', 'outcome', 'detail'] and, when a decision follows it,
     * 'request' (DecisionService::decide()'s, without `store_choice`).
     *
     * @param array<string, mixed> $c choice()'s row
     * @return array{state: string, outcome: string, detail: array<string, mixed>, request?: array<string, mixed>}
     */
    private function look(array $c): array
    {
        $l = $c['listing'];
        $lid = (int) $l['id'];
        $status = (string) $l['status'];
        $done = static fn (string $outcome, array $detail = []): array => ['state' => 'done', 'outcome' => $outcome, 'detail' => $detail];
        $check = static fn (string $outcome, array $detail = []): array => ['state' => 'to_check', 'outcome' => $outcome, 'detail' => $detail];
        $reason = self::reasonText($c);
        $request = ['listing_id' => $lid, 'expected_map_version' => (int) $l['map_version'], 'reason' => $reason];

        if ($status === 'landing') {
            // A landing page (the system's mark or a person's): decided. Only a matching lead says it is a real product, on its page (LP3).
            return $c['choice'] === 'none' ? $done('not_stock', ['already' => true]) : $done('already_decided', ['status' => $status]);
        }
        if (in_array($status, DecisionService::LINKED, true) || $status === 'ignored') {
            $code = $l['sku_id'] === null ? null : (string) $this->db->value('SELECT code FROM sku WHERE id = ?', [(int) $l['sku_id']]);
            return $done('already_decided', ['status' => $status] + ($code === null ? [] : ['sku' => $code]));
        }
        if ($this->db->value('SELECT id FROM match_decision WHERE pending_listing_id = ?', [$lid]) !== null) {
            return $done('already_decided', ['status' => 'pending_second']);
        }
        $seen = OneAtATime::seen($this->db, $lid);
        if ($seen !== null && !str_starts_with($seen, OneAtATime::ARRIVAL . ':')) {
            return $check('held', ['seen' => explode(':', $seen)[0]]);
        }
        if ($this->db->value('SELECT 1 FROM match_decision WHERE listing_id = ? AND decided_by IS NOT NULL LIMIT 1', [$lid]) !== null) {
            return $check('has_history');
        }
        $open = $this->db->one('SELECT id, CAST(band AS CHAR) AS band, proposed_sku_id FROM match_proposal WHERE open_listing_id = ?', [$lid]);
        $request['proposal_id'] = $open === null ? null : (int) $open['id'];
        if ($open !== null && $open['band'] === 'Conflict' && $c['choice'] !== 'none') {
            // A Conflict is a matching lead's to decide (DecisionService): never followed alone.
            return $check('conflict', ['band' => 'Conflict']);
        }

        switch ($c['choice']) {
            case 'none':
                return $done('not_stock') + ['request' => ['action' => 'landing', 'landing' => ['reason' => DecisionService::LANDING_STORE_CHOICE,
                    'source' => 'add_product', 'evidence' => ['store_choice_id' => (int) $c['id']]]] + array_diff_key($request, ['proposal_id' => true])];
            case 'pack':
                $sku = $this->db->one('SELECT id FROM sku WHERE code = ?', [$c['pack_of_code']]);
                if ($sku === null) {
                    return ['state' => 'refused', 'outcome' => 'no_such_item', 'detail' => ['sku' => $c['pack_of_code']]];
                }
                $root = $this->ds->rootOf((int) $sku['id']);
                $code = (string) $this->db->value('SELECT code FROM sku WHERE id = ?', [$root]);
                return $done('linked', ['sku' => $code, 'units' => (int) $c['units']] + ($root !== (int) $sku['id'] ? ['named' => $c['pack_of_code']] : []))
                    + ['request' => ['action' => 'link', 'sku_id' => $root, 'units_per_item' => (int) $c['units']] + $request];
            default: // new
                if ($open !== null && $open['proposed_sku_id'] !== null) {
                    $root = $this->ds->rootOf((int) $open['proposed_sku_id']);
                    return $check('suggested_match', ['sku_id' => $root, 'sku' => (string) $this->db->value('SELECT code FROM sku WHERE id = ?', [$root]),
                        'band' => (string) $open['band']]);
                }
                $found = $this->barcodes($lid, self::strings(json_decode((string) ($l['barcodes'] ?? 'null'), true)));
                if ($found !== null) {
                    return $check($found['outcome'], $found['detail']);
                }
                try {
                    $name = (string) $this->ds->card($lid)['name'];
                } catch (CwException $e) {
                    return ['state' => 'refused', 'outcome' => 'no_name', 'detail' => ['code' => $e->errorCode]];
                }
                $same = $this->sameName($name);
                if ($same !== null) {
                    return $check($same['outcome'], $same['detail'] + ['name' => $name]);
                }
                return $done('made', ['name' => $name]) + ['request' => ['action' => 'new_item'] + $request];
        }
    }

    /**
     * A barcode of the variant on an item, or on another store variant (Gtin::forms(): the forms one scan may take): the first found, as
     * to_check's outcome and what it found (the item's CW number; the other variant's store, number and item).
     *
     * @param list<string> $barcodes
     * @return array{outcome: string, detail: array<string, mixed>}|null
     */
    private function barcodes(int $listingId, array $barcodes): ?array
    {
        foreach (array_chunk(Gtin::forms($barcodes), 200) as $codes) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            $item = $this->db->one("SELECT b.barcode, b.sku_id FROM sku_barcode b WHERE b.barcode IN ({$in}) ORDER BY b.sku_id LIMIT 1", $codes);
            if ($item !== null) {
                $root = $this->ds->rootOf((int) $item['sku_id']);
                return ['outcome' => 'barcode_on_item', 'detail' => ['barcode' => (string) $item['barcode'], 'sku_id' => $root,
                    'sku' => (string) $this->db->value('SELECT code FROM sku WHERE id = ?', [$root])]];
            }
            $other = $this->db->one('SELECT cl.id, cl.external_variant_id, cl.sku_id, c.code AS store FROM listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id '
                . 'JOIN channel c ON c.id = cl.channel_id WHERE JSON_OVERLAPS(lp.barcodes, CAST(? AS JSON)) AND lp.listing_id <> ? ORDER BY cl.id LIMIT 1',
                [json_encode($codes, JSON_THROW_ON_ERROR), $listingId]);
            if ($other !== null) {
                $detail = ['listing_id' => (int) $other['id'], 'store' => (string) $other['store'], 'variant' => (string) $other['external_variant_id']];
                if ($other['sku_id'] !== null) {
                    $root = $this->ds->rootOf((int) $other['sku_id']);
                    $detail += ['sku_id' => $root, 'sku' => (string) $this->db->value('SELECT code FROM sku WHERE id = ?', [$root])];
                }
                return ['outcome' => 'barcode_on_listing', 'detail' => $detail];
            }
        }
        return null;
    }

    /**
     * An item (not joined into another) whose name is the new one's (same_name: the item table's own comparison, letter case and
     * accents aside) or differs from it only in letter case, spaces and punctuation (close_name: key()).
     *
     * @return array{outcome: string, detail: array<string, mixed>}|null
     */
    private function sameName(string $name): ?array
    {
        $exact = $this->db->one('SELECT /*+ INDEX(s ix_sku_name) */ id, code FROM sku s WHERE s.name = ? AND s.merged_into_sku_id IS NULL ORDER BY id LIMIT 1', [$name]);
        if ($exact !== null) {
            return ['outcome' => 'same_name', 'detail' => ['sku_id' => (int) $exact['id'], 'sku' => (string) $exact['code']]];
        }
        $key = self::key($name);
        if ($key === '') {
            return null;
        }
        // Read fresh each time (an item made a moment ago by anyone counts): one pass over the items' names in the database, the same key
        // as key() works it out.
        $hit = $this->db->one('SELECT id, code, name FROM sku WHERE merged_into_sku_id IS NULL AND name IS NOT NULL AND '
            . "REGEXP_REPLACE(LOWER(REPLACE(name, '&', ' and ')), ?, '') = ? ORDER BY id LIMIT 1", [self::KEY_DROP, $key]);
        return $hit === null ? null : ['outcome' => 'close_name', 'detail' => ['sku_id' => (int) $hit['id'], 'sku' => (string) $hit['code'], 'item_name' => (string) $hit['name']]];
    }

    /**
     * A name as close_name compares it (SC6): lower case, "&" read as "and", and only its letters and digits ("Mr Blue Nic-Salt 10ml - 10mg"
     * and "mr blue nic salt 10ml 10mg" are one key). Strengths, sizes and flavours stay apart (10mg is not 20mg).
     */
    public static function key(string $name): string
    {
        $s = mb_strtolower(str_replace('&', ' and ', $name), 'UTF-8');
        return (string) preg_replace('/' . self::KEY_DROP . '+/u', '', $s);
    }

    /**
     * The open rows a person decided on the variant's own page meanwhile (linked, ignored, a landing page, or with a decision waiting for
     * a second person): the job settles them (decided_elsewhere).
     *
     * @return list<int>
     */
    private function decidedElsewhere(): array
    {
        return array_map('intval', $this->db->column(
            "SELECT sc.id FROM store_choice sc JOIN channel_listing cl ON cl.id = sc.listing_id WHERE sc.state IN ('to_check', 'refused') "
            . "AND (cl.status NOT IN ('unmapped', 'suggested') OR EXISTS (SELECT 1 FROM match_decision d WHERE d.pending_listing_id = cl.id)) ORDER BY sc.id LIMIT 500"));
    }

    // ------------------------------------------------------------------------------------------
    // The people (Approvals > New products)
    // ------------------------------------------------------------------------------------------

    public function openCount(): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM store_choice sc JOIN channel_listing cl ON cl.id = sc.listing_id WHERE sc.state IN ('to_check', 'refused') "
            . "AND cl.status IN ('unmapped', 'suggested') AND NOT EXISTS (SELECT 1 FROM match_decision d WHERE d.pending_listing_id = cl.id)");
    }

    /**
     * The rows a person answers, oldest first (a page and one more), each with its store, the variant's titles, barcodes and version, the
     * open suggestion it would be decided against, and the item it found (detail's sku_id, followed to the item it is now); or, with
     * $state 'done', the latest answered and followed ones, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $state = 'open', int $limit = QueueRules::PAGE + 1, ?int $id = null): array
    {
        $open = $state === 'open';
        $rows = $this->db->all(
            'SELECT sc.id, sc.channel_id, sc.external_variant_id, CAST(sc.choice AS CHAR) AS choice, sc.pack_of_code, sc.units, sc.staff_name, sc.chosen_at, '
            . 'CAST(sc.state AS CHAR) AS state, sc.outcome, CAST(sc.detail AS CHAR) AS detail, sc.listing_id, sc.decision_id, sc.settled_by, sc.settled_at, sc.received_at, '
            . 'CAST(cl.status AS CHAR) AS listing_status, cl.map_version, cl.sku_id, lp.product_title, lp.variant_title, CAST(lp.barcodes AS CHAR) AS barcodes, '
            . '(SELECT p.id FROM match_proposal p WHERE p.open_listing_id = cl.id) AS proposal_id '
            . 'FROM store_choice sc JOIN channel_listing cl ON cl.id = sc.listing_id LEFT JOIN listing_profile lp ON lp.listing_id = sc.listing_id '
            . ($open ? "WHERE sc.state IN ('to_check', 'refused') AND cl.status IN ('unmapped', 'suggested') "
                . 'AND NOT EXISTS (SELECT 1 FROM match_decision d WHERE d.pending_listing_id = cl.id)'
                : "WHERE sc.state = 'done'")
            . ($id !== null ? ' AND sc.id = ' . $id : '')
            . ($open ? ' ORDER BY sc.id' : ' ORDER BY COALESCE(sc.settled_at, sc.looked_at) DESC, sc.id DESC') . ' LIMIT ' . max(1, min(500, $limit)));
        $stores = QueueRules::stores($this->db);
        $names = QueueRules::names($this->db, array_column($rows, 'settled_by'));
        foreach ($rows as &$r) {
            $r['detail'] = $r['detail'] === null ? [] : (array) json_decode((string) $r['detail'], true);
            $r['barcodes'] = self::strings(json_decode((string) ($r['barcodes'] ?? 'null'), true));
            $r['store'] = $stores[(int) $r['channel_id']] ?? null;
            $r['settled_by_name'] = $r['settled_by'] === null ? null : ($names[(int) $r['settled_by']] ?? null);
            $r['found'] = null;
            if (is_int($r['detail']['sku_id'] ?? null)) {
                $root = $this->ds->rootOf((int) $r['detail']['sku_id']);
                $r['found'] = $this->db->one('SELECT s.id, s.code, s.name, COALESCE((SELECT SUM(b.on_hand) FROM stock_balance b WHERE b.sku_id = s.id), 0) AS on_hand '
                    . 'FROM sku s WHERE s.id = ?', [$root]);
            }
        }
        unset($r);
        return $rows;
    }

    /**
     * A person's answer to an open row (SC9): `same` links the variant to the item the row found (one sale, one item), `new` makes a new
     * item of it, `none` marks it not stock. Made through DecisionService as that person (their permission, the two-person rules, the
     * version and the suggestion they saw: 409 when the variant changed), in one transaction with the row, which is then done.
     *
     * @return array{outcome: string, decision: array<string, mixed>}
     */
    public function answer(Caller $person, int $id, string $answer, int $seenVersion, ?int $seenProposal, ?string $note = null): array
    {
        if (!in_array($answer, self::ANSWERS, true)) {
            throw new CwException('bad_request', 'answer must be one of ' . implode(', ', self::ANSWERS), 400, ['field' => 'answer']);
        }
        if ($person->staffUserId === null) {
            throw new CwException('staff_required', 'a person answers', 403);
        }
        return $this->db->transaction(function () use ($person, $id, $answer, $seenVersion, $seenProposal, $note): array {
            $c = $this->choice($id, true);
            if ($c === null) {
                throw new CwException('not_found', 'no such choice', 404);
            }
            if (!in_array($c['state'], self::OPEN, true) || $c['listing'] === null) {
                throw new CwException('already_settled', 'this choice is not waiting for an answer any more', 409, ['state' => $c['state']]);
            }
            // A variant set aside or in a spot check is that person's, on its own page (DG2): never answered here. What this page showed of
            // it otherwise (nothing, or the check on arrival's suggestion) goes with the decision, which checks it again under its lock.
            $seen = OneAtATime::seen($this->db, (int) $c['listing']['id']);
            if ($seen !== null && !str_starts_with($seen, OneAtATime::ARRIVAL . ':')) {
                throw new CwException('one_at_a_time', 'this variant is set aside or in a spot check: decide it on its own page', 409);
            }
            $base = ['listing_id' => (int) $c['listing']['id'], 'expected_map_version' => $seenVersion, 'proposal_id' => $seenProposal,
                'reason' => self::reasonText($c, $note), 'seen' => $seen ?? ''];
            switch ($answer) {
                case 'same':
                    $sku = $c['detail']['sku_id'] ?? null;
                    if (!is_int($sku)) {
                        throw new CwException('nothing_found', 'this choice found no item to join it to', 409);
                    }
                    $req = ['action' => 'link', 'sku_id' => $this->ds->rootOf($sku), 'units_per_item' => 1] + $base;
                    $outcome = 'joined';
                    break;
                case 'new':
                    $req = ['action' => 'new_item'] + $base;
                    $outcome = 'made_by_person';
                    break;
                default:
                    $req = ['action' => 'landing'] + $base;
                    $outcome = 'not_stock_by_person';
            }
            $decision = $this->ds->decide($person, $req);
            $detail = $c['detail'] + ['answer' => $answer] + ($note !== null && $note !== '' ? ['note' => mb_substr($note, 0, QueueRules::NOTE_MAX)] : []);
            $this->settle($c, 'done', $outcome, $detail, (int) $decision['decision_id'], $person);
            return ['outcome' => $outcome, 'decision' => $decision];
        });
    }

    // ------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------

    /**
     * One choice with its variant (null when it has not arrived: no listing, or no profile yet), the row locked when $lock.
     *
     * @return array<string, mixed>|null
     */
    private function choice(int $id, bool $lock): ?array
    {
        $c = $this->db->one('SELECT sc.id, sc.channel_id, sc.external_variant_id, CAST(sc.choice AS CHAR) AS choice, sc.pack_of_code, sc.units, sc.staff_ref, sc.staff_name, '
            . 'sc.chosen_at, CAST(sc.state AS CHAR) AS state, sc.outcome, CAST(sc.detail AS CHAR) AS detail, c.code AS store FROM store_choice sc '
            . 'JOIN channel c ON c.id = sc.channel_id WHERE sc.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if ($c === null) {
            return null;
        }
        $c['detail'] = $c['detail'] === null ? [] : (array) json_decode((string) $c['detail'], true);
        $c['listing'] = $this->db->one('SELECT cl.id, CAST(cl.status AS CHAR) AS status, cl.sku_id, cl.map_version, CAST(lp.barcodes AS CHAR) AS barcodes '
            . 'FROM channel_listing cl JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.channel_id = ? AND cl.external_variant_id = ?',
            [(int) $c['channel_id'], (string) $c['external_variant_id']]);
        return $c;
    }

    /** Writes a row's state (the row is locked by the caller's transaction). @param array<string, mixed> $detail */
    private function settle(array $c, string $state, string $outcome, array $detail, ?int $decisionId, ?Caller $person): void
    {
        $now = Clock::db(Clock::now());
        $this->db->exec('UPDATE store_choice SET state = ?, outcome = ?, detail = ?, listing_id = ?, decision_id = COALESCE(?, decision_id), looked_at = ?, '
            . 'settled_by = ?, settled_at = ? WHERE id = ?',
            [$state, $outcome, $detail === [] ? null : Idempotency::json($detail), $c['listing']['id'] ?? null, $decisionId, $now,
                $person?->staffUserId, $person === null ? null : $now, (int) $c['id']]);
        if ($person !== null || $state !== 'done' || $decisionId === null) {
            Audit::write($this->db, $person ?? Caller::system(self::JOB), 'store_choice.' . ($person === null ? 'follow' : 'answer'), 'store_choice', (string) $c['id'], null,
                ['state' => $state, 'outcome' => $outcome, 'decision_id' => $decisionId, 'listing_id' => $c['listing']['id'] ?? null]);
        }
    }

    /** Whose choice a decision follows (DecisionService's `store_choice`). @param array<string, mixed> $c @return array<string, mixed> */
    private static function who(array $c): array
    {
        return ['id' => (int) $c['id'], 'store' => (string) $c['store'], 'staff_ref' => (string) $c['staff_ref'], 'staff_name' => (string) $c['staff_name'],
            'chosen_at' => (string) $c['chosen_at']];
    }

    /** The decision's reason: whose choice it followed, or a person's answer with their note. @param array<string, mixed> $c */
    private static function reasonText(array $c, ?string $note = null): string
    {
        $what = match ($c['choice']) {
            'pack' => 'a pack of ' . $c['units'] . ' x ' . $c['pack_of_code'],
            'none' => 'not stock',
            default => 'a new item',
        };
        $s = "Chosen at Add product on {$c['store']} by {$c['staff_name']}: {$what}.";
        if ($note !== null && trim($note) !== '') {
            $s .= ' ' . trim($note);
        }
        return mb_substr($s, 0, DecisionService::MAX_REASON);
    }

    /** A refusal of DecisionService as the row's state and outcome. @return array{0: string, 1: string} */
    private static function refusal(string $code): array
    {
        return match ($code) {
            'pending_second_exists', 'not_open', 'already_linked', 'linked' => ['done', 'already_decided'],
            'one_at_a_time' => ['to_check', 'held'],
            'has_history' => ['to_check', 'has_history'],
            'lead_required' => ['to_check', 'conflict'],
            'said_real' => ['to_check', 'said_real'],
            'landing_page' => ['done', 'already_decided'],
            'unknown_sku', 'sku_merged' => ['refused', 'no_such_item'],
            'name_required' => ['refused', 'no_name'],
            default => ['refused', substr($code, 0, 32)],
        };
    }

    /** @return list<string> */
    private static function strings(mixed $v): array
    {
        return is_array($v) ? array_values(array_filter(array_map(static fn ($x): ?string => is_string($x) || is_int($x) ? (string) $x : null, $v), static fn ($x): bool => $x !== null && $x !== '')) : [];
    }
}
