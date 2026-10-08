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
use CW\Matching\Band;
use CW\Staff\StaffRoles;

/**
 * The owner's spot-check of Key proposals (docs/decisions.md M28; the owner's decision (2) of 2 Oct 2026).
 *
 * create(): a named sample of `size` (at least the spot-check size on the Approval rules page, approvals.spot_check_size: 20
 * unless the owner changes it; the size in force is stored with the sample, Y47) Key proposals drawn from the POPULATION: every open Key
 * proposal that KeyEligibility lets a bulk confirm link at that moment (the proved bases of older proposals are written
 * first, M27), minus every proposal already in another sample's population, minus every listing of a FAILED sample's
 * population (unless an audited override names that sample: then only proposals made after it, and only once a new
 * matching run or band version exists). Strata by the judge's confidence: >= 90 (b2.0's Key) and 85-89 (what M26 newly
 * admits); proportional allocation (largest remainder, at least 1 per non-empty stratum: with today's figures about 14
 * and 6); within a stratum the members with the lowest sha256("<seed>:<proposal id>"). The SEED is drawn by the server
 * (random_int) only when the sample is stored, after the population is fixed: nobody chooses it, and a dry run shows the
 * strata, not the members. Anyone can re-draw the stored sample from its stored seed and population (verify()). The
 * sample and its whole population are stored (key_sample, key_sample_member, append-only) and audited.
 *
 * status(): what became of each sample member. The sample's OWNER (the mapping lead who drew it) confirms or rejects each
 * one on the normal review screen; a member counts as CONFIRMED only when an applied `link` decision named its proposal,
 * to its item with units per item 1, not part of a bulk batch, made by the owner alone (no second person: needs_second
 * empty), while the owner held mapping_lead, and that link is still the listing's current one. Rejected, decided
 * otherwise, replaced by a later proposal, waiting for (or decided with) a second person, decided by anyone but the owner,
 * by the owner without mapping_lead, or relinked since: the sample FAILED and the bulk confirm refuses it for good.
 * status() also says whether the sample is FIT for a bulk confirm at all: at least the size in force when it was drawn, every non-empty
 * stratum holding at least its allocated share, and the members exactly what the stored seed draws.
 */
final class KeySample
{
    public const NAME_PATTERN = '/^[A-Za-z0-9._-]{1,40}$/D';
    /** The owner's spot-check was 20 proposals (decision 2): the default of approvals.spot_check_size; minSize() reads the setting. */
    public const MIN_SIZE = 20;
    public const DEFAULT_SIZE = 20;
    public const MAX_SIZE = 200;
    /** Seeds are 1..2^53-1 (exact in JSON and JavaScript). */
    public const MAX_SEED = 9_007_199_254_740_991;
    /** b2.0's Key threshold: confidences below it (85-89 under b2.1) are what M26 newly admits, so they are their own stratum. */
    public const SPLIT = 90;
    public const METHOD = 'strata by judge confidence; proportional allocation (largest remainder, at least 1 per non-empty stratum); '
        . 'in each stratum the lowest sha256("<seed>:<proposal id>"); positions in that hash order; seed drawn by the server';
    public const FAILED_STATES = ['rejected', 'waiting_second', 'needed_second', 'superseded', 'decided_otherwise', 'confirmed_by_other',
        'confirmed_not_by_lead', 'changed_since'];
    /** match_run sources that are not a new matching run (an override needs a real one, or a new band version). */
    public const NOT_A_MATCHING_RUN = [Reband::SOURCE, KeyBulk::UNDO_SOURCE];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The spot-check size now (approvals.spot_check_size on the Approval rules page, 0019, Y11; MIN_SIZE = 20 when the setting does
     * not exist): a NEW sample has at least this many members, and the size in force when it is drawn is stored with it
     * (key_sample.required_size, Y47): a sample is judged against that, so changing the setting later affects only new samples.
     */
    public static function minSize(Db $db): int
    {
        return max(1, min(self::MAX_SIZE, ApprovalRules::number($db, 'approvals.spot_check_size')));
    }

    /** @return list<array{name: string, min: int, max: int}> highest first */
    public static function strata(): array
    {
        $lo = Band::KEY_MIN_CONFIDENCE;
        if ($lo >= self::SPLIT) {
            return [['name' => "conf_{$lo}_100", 'min' => $lo, 'max' => 100]];
        }
        return [['name' => 'conf_' . self::SPLIT . '_100', 'min' => self::SPLIT, 'max' => 100],
            ['name' => "conf_{$lo}_" . (self::SPLIT - 1), 'min' => $lo, 'max' => self::SPLIT - 1]];
    }

    /**
     * How many members each stratum gets: proportional to its population, largest remainder (ties: stratum order),
     * at least 1 for every non-empty stratum when the size allows, never more than the stratum holds.
     *
     * @param array<string, int> $populations stratum => population, in stratum order
     * @return array<string, int>
     */
    public static function allocate(array $populations, int $size): array
    {
        $total = array_sum($populations);
        $out = array_fill_keys(array_keys($populations), 0);
        if ($total === 0 || $size <= 0) {
            return $out;
        }
        $size = min($size, $total);
        $rema = [];
        foreach ($populations as $s => $n) {
            $q = $size * $n / $total;
            $out[$s] = (int) floor($q);
            $rema[$s] = $q - floor($q);
        }
        $left = $size - array_sum($out);
        $keys = array_keys($populations);
        $order = $keys;
        // largest remainder first; equal remainders in stratum order
        usort($order, static fn (string $a, string $b): int => [$rema[$b], array_search($a, $keys, true)] <=> [$rema[$a], array_search($b, $keys, true)]);
        foreach ($order as $s) {
            if ($left <= 0) {
                break;
            }
            if ($out[$s] < $populations[$s]) {
                $out[$s]++;
                $left--;
            }
        }
        $nonEmpty = array_keys(array_filter($populations, static fn (int $n): bool => $n > 0));
        if ($size >= count($nonEmpty)) {
            foreach ($nonEmpty as $s) {
                if ($out[$s] === 0) {
                    arsort($out);
                    $donor = (string) array_key_first($out);
                    $out[$donor]--;
                    $out[$s] = 1;
                }
            }
        }
        return array_replace(array_fill_keys(array_keys($populations), 0), $out);
    }

    public static function rank(int $seed, int $proposalId): string
    {
        return hash('sha256', $seed . ':' . $proposalId);
    }

    /**
     * The members a seed draws from a population: per stratum (in order) its allocated share of the lowest ranks, then
     * all of them in rank order (position 1..n).
     *
     * @param array<string, list<int>> $byStratum stratum => proposal ids, in stratum order
     * @return list<int> proposal ids by position
     */
    public static function draw(array $byStratum, int $size, int $seed): array
    {
        $alloc = self::allocate(array_map('count', $byStratum), $size);
        $chosen = [];
        foreach ($byStratum as $stratum => $ids) {
            usort($ids, static fn (int $a, int $b): int => self::rank($seed, $a) <=> self::rank($seed, $b));
            foreach (array_slice($ids, 0, $alloc[$stratum]) as $pid) {
                $chosen[] = $pid;
            }
        }
        usort($chosen, static fn (int $a, int $b): int => self::rank($seed, $a) <=> self::rank($seed, $b));
        return $chosen;
    }

    /**
     * Works out (and with $apply stores) a sample. $by must be an active mapping lead; the sample is theirs (only their
     * confirmations count, and only they run its bulk confirm). The dry run gives the population, its strata and the
     * allocation, never members or a seed: the seed is drawn when the sample is stored.
     *
     * @param list<string> $afterFailed names of FAILED samples whose listings this sample may hold again (proposals made after
     *        that sample only); allowed only when the band version changed since, or a new matching run was imported since
     * @return array<string, mixed> name, seed (stored only), method, band_version, size, population, strata, excluded, units, members
     *         (stored only: position, proposal_id, listing_id, stratum, confidence), sample_id (stored), backfill, overrides
     */
    public function create(Caller $by, string $name, ?int $size = null, bool $apply = false, array $afterFailed = []): array
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new CwException('bad_name', 'a sample name is 1-40 of [A-Za-z0-9._-]', 400);
        }
        $min = self::minSize($this->db);
        $size ??= $min;
        if ($size < $min || $size > self::MAX_SIZE) {
            throw new CwException('bad_size', 'the sample size is ' . $min . '..' . self::MAX_SIZE, 400);
        }
        $staff = self::lead($this->db, $by);
        if ($this->db->value('SELECT id FROM key_sample WHERE name = ?', [$name]) !== null) {
            throw new CwException('sample_exists', "a sample named {$name} exists already (samples are never changed: pick another name)", 409);
        }
        $overrides = $this->overrides(array_values(array_unique($afterFailed)));
        $ids = array_map('intval', $this->db->column("SELECT id FROM match_proposal WHERE status = 'open' AND band = 'Key' ORDER BY id"));
        $bf = ProposalBasis::backfill($this->db, $ids, $apply);
        $checked = (new KeyEligibility($this->db, null, array_column($overrides, 'sample_id')))->check($ids, $apply ? [] : $bf['proved']);
        $sum = KeyEligibility::summarise($checked);
        $strata = self::strata();
        $byStratum = array_fill_keys(array_column($strata, 'name'), []);
        $excluded = $sum['excluded'];
        foreach ($sum['eligible'] as $pid) {
            $s = self::stratumOf($checked[$pid]['confidence']);
            if ($s === null) {
                $excluded['no_stratum'] = ($excluded['no_stratum'] ?? 0) + 1;
                continue;
            }
            $byStratum[$s][] = $pid;
        }
        ksort($excluded);
        $population = array_sum(array_map('count', $byStratum));
        if ($population < $size) {
            throw new CwException('population_too_small', "only {$population} Key proposals qualify; a sample of {$size} needs more", 409,
                ['population' => $population, 'excluded' => $excluded]);
        }
        $alloc = self::allocate(array_map('count', $byStratum), $size);
        $strataOut = [];
        foreach ($strata as $st) {
            $strataOut[] = ['name' => $st['name'], 'min_confidence' => $st['min'], 'max_confidence' => $st['max'],
                'population' => count($byStratum[$st['name']]), 'sample' => $alloc[$st['name']]];
        }
        $out = ['name' => $name, 'seed' => null, 'method' => self::METHOD, 'band_version' => Band::VERSION, 'size' => $size,
            'population' => $population, 'strata' => $strataOut, 'excluded' => $excluded, 'units_30d' => $sum['units_30d'],
            'units_365d' => $sum['units_365d'], 'members' => [], 'sample_id' => null, 'overrides' => $overrides,
            'backfill' => ['recorded' => $bf['recorded'], 'backfilled' => $bf['backfilled'], 'unproved' => $bf['unproved']]];
        if (!$apply) {
            return $out;
        }
        // The population is fixed: only now is the seed drawn (nobody picks it, nobody sees a draw before it is stored).
        $seed = random_int(1, self::MAX_SEED);
        $members = [];
        $stratumOf = [];
        foreach ($byStratum as $stratum => $pids) {
            foreach ($pids as $pid) {
                $stratumOf[$pid] = $stratum;
            }
        }
        foreach (self::draw($byStratum, $size, $seed) as $i => $pid) {
            $members[] = ['position' => $i + 1, 'proposal_id' => $pid, 'listing_id' => $checked[$pid]['listing_id'], 'stratum' => $stratumOf[$pid],
                'confidence' => $checked[$pid]['confidence']];
        }
        $out['seed'] = $seed;
        $out['members'] = $members;
        $out['sample_id'] = $this->db->transaction(function (Db $db) use ($by, $staff, $name, $seed, $size, $min, $population, $strataOut, $excluded, $byStratum, $members, $checked, $overrides): int {
            $sid = $db->insert(
                'INSERT INTO key_sample (name, seed, method, band_version, sample_size, required_size, population, strata, excluded, overrides, created_by, actor) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$name, $seed, self::METHOD, Band::VERSION, $size, $min, $population, Idempotency::json($strataOut),
                    $excluded === [] ? null : Idempotency::json($excluded), $overrides === [] ? null : Idempotency::json($overrides), $staff['id'], $by->actor],
            );
            $position = array_column($members, 'position', 'proposal_id');
            $rows = [];
            foreach ($byStratum as $stratum => $ids) {
                foreach ($ids as $pid) {
                    $rows[] = [$sid, $pid, $checked[$pid]['listing_id'], $stratum, $checked[$pid]['confidence'], $position[$pid] ?? null];
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                $db->exec('INSERT INTO key_sample_member (sample_id, proposal_id, listing_id, stratum, ai_confidence, position) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)')), array_merge(...$chunk));
            }
            Audit::write($db, $by, 'mapping.key_sample', 'key_sample', (string) $sid, null, [
                'name' => $name, 'seed' => $seed, 'method' => self::METHOD, 'band_version' => Band::VERSION, 'size' => $size, 'required_size' => $min,
                'population' => $population, 'strata' => $strataOut, 'excluded' => $excluded, 'overrides' => $overrides,
                'sample' => array_map(static fn (array $m): array => [$m['position'], $m['proposal_id']], $members),
            ]);
            return $sid;
        });
        return $out;
    }

    /**
     * Re-draws a stored sample from its stored seed and stored population (read-only): the members must be exactly the ones
     * stored, in the same positions.
     *
     * @return array{name: string, seed: int, matches: bool, stored: list<int>, drawn: list<int>}
     */
    public function verify(int|string $nameOrId): array
    {
        $k = $this->find($nameOrId) ?? throw new CwException('unknown_sample', 'no such sample', 404);
        [$byStratum, $stored] = $this->stored((int) $k['id'], $k);
        $drawn = self::draw($byStratum, (int) $k['sample_size'], (int) $k['seed']);
        return ['name' => (string) $k['name'], 'seed' => (int) $k['seed'], 'matches' => $drawn === $stored, 'stored' => $stored, 'drawn' => $drawn];
    }

    /** @return array<string, mixed>|null the key_sample row (+ creator's name) */
    public function find(string|int $nameOrId): ?array
    {
        return $this->db->one('SELECT k.*, u.display_name AS created_by_name, u.email AS created_by_email FROM key_sample k '
            . 'LEFT JOIN staff_user u ON u.id = k.created_by WHERE ' . (is_int($nameOrId) ? 'k.id = ?' : 'k.name = ?'), [$nameOrId]);
    }

    /** @return list<array<string, mixed>> every sample, newest first, with its progress */
    public function all(): array
    {
        $out = [];
        foreach ($this->db->column('SELECT id FROM key_sample ORDER BY id DESC LIMIT 100') as $id) {
            $s = $this->status((int) $id);
            unset($s['members']);
            $out[] = $s;
        }
        return $out;
    }

    /**
     * Each sample member's state and the verdict: `complete` (every member confirmed by the owner), `waiting` (some not
     * decided yet, nothing wrong), `failed` (any member in FAILED_STATES). `fit`: why the sample cannot unlock a bulk
     * confirm whatever its verdict ([] = it can). Also the bulk confirm's batch and its undo, as counted now.
     *
     * @return array<string, mixed>
     */
    public function status(int|string $nameOrId): array
    {
        $k = $this->find($nameOrId);
        if ($k === null) {
            throw new CwException('unknown_sample', 'no such sample', 404);
        }
        $sid = (int) $k['id'];
        [$out, $count] = $this->memberStates($k);
        $size = (int) $k['sample_size'];
        $verdict = self::verdictOf($count, count($out), $size);
        $batch = KeyBulk::batchOf((string) $k['name']);
        return [
            'id' => $sid, 'name' => (string) $k['name'], 'seed' => (int) $k['seed'], 'method' => (string) $k['method'],
            'band_version' => (string) $k['band_version'], 'size' => $size, 'population' => (int) $k['population'],
            'strata' => json_decode((string) $k['strata'], true) ?: [], 'excluded' => json_decode((string) ($k['excluded'] ?? 'null'), true) ?: [],
            'overrides' => json_decode((string) ($k['overrides'] ?? 'null'), true) ?: [],
            'created_by_id' => (int) $k['created_by'], 'created_by' => $k['created_by_name'], 'created_by_email' => $k['created_by_email'],
            'created_at' => (string) $k['created_at'],
            'members' => $out, 'decided' => $size - $count['open'], 'confirmed' => $count['confirmed'], 'failed' => $count['failed'],
            'verdict' => $verdict, 'fit' => $this->fitness($k), 'batch' => $batch,
            'bulk_linked' => (int) $this->db->value("SELECT COUNT(*) FROM match_decision WHERE bulk_batch_id = ? AND action = 'link' AND state = 'applied'", [$batch]),
            'bulk_undone' => (int) $this->db->value("SELECT COUNT(*) FROM match_decision WHERE bulk_batch_id = ? AND action = 'unlink' AND state = 'applied'",
                [KeyBulk::UNDO_PREFIX . $batch]),
        ];
    }

    /**
     * The verdict of each sample (KeyEligibility: a failed sample's listings never go into a bulk confirm).
     *
     * @param list<int> $sampleIds
     * @return array<int, array{verdict: string, created_at: string}>
     */
    public function verdicts(array $sampleIds): array
    {
        $out = [];
        foreach (array_values(array_unique($sampleIds)) as $id) {
            $k = $this->find((int) $id);
            if ($k === null) {
                continue;
            }
            [$members, $count] = $this->memberStates($k);
            $out[(int) $id] = ['verdict' => self::verdictOf($count, count($members), (int) $k['sample_size']), 'created_at' => (string) $k['created_at']];
        }
        return $out;
    }

    /** @param array{open: int, confirmed: int, failed: int} $count */
    private static function verdictOf(array $count, int $members, int $size): string
    {
        return $count['failed'] > 0 ? 'failed' : ($count['confirmed'] === $size && $members === $size ? 'complete' : 'waiting');
    }

    /**
     * Why a sample cannot unlock a bulk confirm ([] = it can): smaller than the spot-check size in force when it was drawn
     * (`sample_too_small`: key_sample.required_size, Y47; never today's setting, so raising it does not disqualify a sample in
     * progress, and lowering it does not bless a sample drawn under a bigger rule), a non-empty stratum
     * short of its allocated share or of at least one member (`stratum_short:<name>`), or members other than what the stored
     * seed draws from the stored population (`draw_not_reproducible`).
     *
     * @param array<string, mixed> $k key_sample row
     * @return list<string>
     */
    private function fitness(array $k): array
    {
        $out = [];
        $size = (int) $k['sample_size'];
        if ($size < (int) ($k['required_size'] ?? $size)) {
            $out[] = 'sample_too_small';
        }
        [$byStratum, $stored] = $this->stored((int) $k['id'], $k);
        $alloc = self::allocate(array_map('count', $byStratum), $size);
        $inSample = array_fill_keys(array_keys($byStratum), 0);
        foreach ($this->db->all('SELECT stratum, COUNT(*) AS n FROM key_sample_member WHERE sample_id = ? AND position IS NOT NULL GROUP BY stratum', [(int) $k['id']]) as $r) {
            $inSample[(string) $r['stratum']] = (int) $r['n'];
        }
        foreach ($byStratum as $stratum => $ids) {
            if ($ids !== [] && (($inSample[$stratum] ?? 0) < max(1, $alloc[$stratum]))) {
                $out[] = 'stratum_short:' . $stratum;
            }
        }
        if (self::draw($byStratum, $size, (int) $k['seed']) !== $stored) {
            $out[] = 'draw_not_reproducible';
        }
        return $out;
    }

    /**
     * The stored population by stratum (in the stored strata order) and the stored members by position.
     *
     * @param array<string, mixed> $k key_sample row
     * @return array{0: array<string, list<int>>, 1: list<int>}
     */
    private function stored(int $sampleId, array $k): array
    {
        $byStratum = [];
        foreach (json_decode((string) $k['strata'], true) ?: [] as $st) {
            if (is_array($st) && is_string($st['name'] ?? null)) {
                $byStratum[$st['name']] = [];
            }
        }
        $positioned = [];
        foreach ($this->db->all('SELECT proposal_id, stratum, position FROM key_sample_member WHERE sample_id = ? ORDER BY proposal_id', [$sampleId]) as $r) {
            $byStratum[(string) $r['stratum']][] = (int) $r['proposal_id'];
            if ($r['position'] !== null) {
                $positioned[(int) $r['position']] = (int) $r['proposal_id'];
            }
        }
        ksort($positioned);
        return [$byStratum, array_values($positioned)];
    }

    /**
     * @param array<string, mixed> $k key_sample row (+ created_by)
     * @return array{0: list<array<string, mixed>>, 1: array{open: int, confirmed: int, failed: int}}
     */
    private function memberStates(array $k): array
    {
        $sid = (int) $k['id'];
        $owner = (int) $k['created_by'];
        $members = $this->db->all(
            'SELECT m.position, m.proposal_id, m.listing_id, m.stratum, m.ai_confidence, p.status AS proposal_status, p.proposed_sku_id, '
            . 's.code AS sku_code, s.name AS sku_name, cl.status AS listing_status, cl.external_variant_id, c.code AS channel_code, '
            . 'lp.product_title, lp.variant_title, lp.units_365d FROM key_sample_member m JOIN match_proposal p ON p.id = m.proposal_id '
            . 'JOIN channel_listing cl ON cl.id = m.listing_id JOIN channel c ON c.id = cl.channel_id LEFT JOIN listing_profile lp ON lp.listing_id = m.listing_id '
            . 'LEFT JOIN sku s ON s.id = p.proposed_sku_id WHERE m.sample_id = ? AND m.position IS NOT NULL ORDER BY m.position',
            [$sid],
        );
        $pids = array_map(static fn (array $m): int => (int) $m['proposal_id'], $members);
        $lids = array_map(static fn (array $m): int => (int) $m['listing_id'], $members);
        $decisions = [];
        $rejects = [];
        $open = [];
        $leadGrants = [];
        if ($pids !== []) {
            $in = implode(',', array_fill(0, count($pids), '?'));
            $deciders = [];
            foreach ($this->db->all(
                'SELECT d.id, d.proposal_id, d.action, d.state, d.sku_id, d.units_per_item, d.bulk_batch_id, d.decided_by, d.needs_second, d.second_by, '
                . "d.created_at, u.display_name AS decider FROM match_decision d LEFT JOIN staff_user u ON u.id = d.decided_by WHERE d.proposal_id IN ({$in}) "
                . "AND d.action <> 'suggest' ORDER BY d.id",
                $pids,
            ) as $d) {
                $decisions[(int) $d['proposal_id']][] = $d;
                if ($d['decided_by'] !== null) {
                    $deciders[(int) $d['decided_by']] = true;
                }
            }
            $lin = implode(',', array_fill(0, count($lids), '?'));
            foreach ($this->db->all("SELECT listing_id, sku_id FROM match_reject WHERE listing_id IN ({$lin})", $lids) as $r) {
                $rejects[(int) $r['listing_id']][(int) $r['sku_id']] = true;
            }
            foreach ($this->db->all("SELECT listing_id, decision_id FROM listing_map_history WHERE open_listing_id IN ({$lin})", $lids) as $h) {
                $open[(int) $h['listing_id']] = (int) $h['decision_id'];
            }
            if ($deciders !== []) {
                $ids = array_keys($deciders);
                foreach ($this->db->all("SELECT staff_user_id, granted_at, revoked_at FROM staff_role WHERE role = 'mapping_lead' AND staff_user_id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $g) {
                    $leadGrants[(int) $g['staff_user_id']][] = [(string) $g['granted_at'], $g['revoked_at'] === null ? null : (string) $g['revoked_at']];
                }
            }
        }
        $heldLead = static function (int $staffId, string $at) use ($leadGrants): bool {
            foreach ($leadGrants[$staffId] ?? [] as [$from, $to]) {
                if ($from <= $at && ($to === null || $to > $at)) {
                    return true;
                }
            }
            return false;
        };
        $out = [];
        $count = ['open' => 0, 'confirmed' => 0, 'failed' => 0];
        foreach ($members as $m) {
            $pid = (int) $m['proposal_id'];
            $lid = (int) $m['listing_id'];
            $sku = $m['proposed_sku_id'] === null ? null : (int) $m['proposed_sku_id'];
            $ds = $decisions[$pid] ?? [];
            $by = null;
            $at = null;
            $decisionId = null;
            $settle = null;
            foreach ($ds as $d) {
                if ($d['state'] === 'applied' && in_array($d['action'], ['link', 'new_item', 'ignore', 'merge_skus'], true)) {
                    $settle = $d;
                }
            }
            $rejected = array_filter($ds, static fn (array $d): bool => $d['action'] === 'reject') !== [] || ($sku !== null && isset($rejects[$lid][$sku]));
            if ($rejected) {
                $state = 'rejected';
            } elseif (array_filter($ds, static fn (array $d): bool => $d['state'] === 'pending_second') !== []) {
                $state = 'waiting_second';
            } elseif ($m['proposal_status'] === 'open') {
                $state = 'open';
            } elseif ($m['proposal_status'] === 'superseded') {
                $state = 'superseded';
            } elseif ($settle === null) {
                $state = 'decided_otherwise';
            } else {
                $by = $settle['decider'];
                $at = $settle['created_at'];
                $decisionId = (int) $settle['id'];
                if ($settle['action'] !== 'link' || $settle['sku_id'] === null || (int) $settle['sku_id'] !== $sku || (int) $settle['units_per_item'] !== 1
                    || $settle['bulk_batch_id'] !== null) {
                    $state = 'decided_otherwise';
                } elseif ($settle['needs_second'] !== null || $settle['second_by'] !== null) {
                    $state = 'needed_second';
                } elseif ((int) $settle['decided_by'] !== $owner) {
                    $state = 'confirmed_by_other';
                } elseif (!$heldLead((int) $settle['decided_by'], (string) $settle['created_at'])) {
                    $state = 'confirmed_not_by_lead';
                } elseif (($open[$lid] ?? null) !== (int) $settle['id']) {
                    $state = 'changed_since';
                } else {
                    $state = 'confirmed';
                }
            }
            $count[$state === 'open' ? 'open' : ($state === 'confirmed' ? 'confirmed' : 'failed')]++;
            $out[] = ['position' => (int) $m['position'], 'proposal_id' => $pid, 'listing_id' => $lid, 'stratum' => (string) $m['stratum'],
                'confidence' => $m['ai_confidence'] === null ? null : (int) $m['ai_confidence'], 'state' => $state, 'by' => $by, 'at' => $at,
                'decision_id' => $decisionId, 'action' => $settle['action'] ?? null, 'channel' => (string) $m['channel_code'],
                'variant' => (string) $m['external_variant_id'], 'title' => $m['product_title'], 'variant_title' => $m['variant_title'],
                'units_365d' => $m['units_365d'] === null ? null : (int) $m['units_365d'], 'sku_code' => $m['sku_code'], 'sku_name' => $m['sku_name'],
                'listing_status' => (string) $m['listing_status']];
        }
        return [$out, $count];
    }

    /**
     * The overrides asked for (names of failed samples), checked: each must exist and have FAILED, and since it was drawn
     * either the band version changed or a new matching run was imported (a re-band or an undo is not one).
     *
     * @param list<string> $names
     * @return list<array{sample_id: int, name: string, created_at: string, why: string}>
     */
    private function overrides(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $k = $this->find((string) $n) ?? throw new CwException('unknown_sample', "no sample named {$n}", 404);
            $v = $this->verdicts([(int) $k['id']])[(int) $k['id']]['verdict'] ?? null;
            if ($v !== 'failed') {
                throw new CwException('override_not_allowed', "sample {$n} has not failed ({$v}): there is nothing to override", 409);
            }
            $why = null;
            if ((string) $k['band_version'] !== Band::VERSION) {
                $why = "band {$k['band_version']} -> " . Band::VERSION;
            } else {
                $skip = self::NOT_A_MATCHING_RUN;
                $run = $this->db->one('SELECT run_id, created_at FROM match_run WHERE created_at > ? AND source NOT IN ('
                    . implode(',', array_fill(0, count($skip), '?')) . ') ORDER BY id LIMIT 1', [(string) $k['created_at'], ...$skip]);
                $why = $run === null ? null : "matching run {$run['run_id']} of {$run['created_at']}";
            }
            if ($why === null) {
                throw new CwException('override_not_allowed', "sample {$n} failed and nothing changed since (no new matching run, same band "
                    . Band::VERSION . '): its listings stay one at a time', 409);
            }
            $out[] = ['sample_id' => (int) $k['id'], 'name' => (string) $k['name'], 'created_at' => (string) $k['created_at'], 'why' => $why];
        }
        return $out;
    }

    private static function stratumOf(?int $confidence): ?string
    {
        foreach (self::strata() as $st) {
            if ($confidence !== null && $confidence >= $st['min'] && $confidence <= $st['max']) {
                return $st['name'];
            }
        }
        return null;
    }

    /**
     * The acting person: staff, active, holding mapping_lead (Permissions::can, fail-closed for an admin set, I12).
     *
     * @return array{id: int, roles: list<string>}
     */
    public static function lead(Db $db, Caller $who): array
    {
        if ($who->staffUserId === null) {
            throw new CwException('staff_required', 'a mapping lead does this, not a system or site caller', 403);
        }
        $roles = StaffRoles::active($db, $who->staffUserId);
        if (!Permissions::can($roles, 'mapping.approve')) {
            throw new CwException('lead_required', 'only an active mapping lead can do this (roles: ' . (implode(', ', $roles) ?: 'none') . ')', 403);
        }
        return ['id' => $who->staffUserId, 'roles' => $roles];
    }
}
