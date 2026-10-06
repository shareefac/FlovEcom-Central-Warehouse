<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;

/**
 * LISTINGS held back from every Key bulk confirm for one-at-a-time review, and the release of a hold (docs/decisions.md M30).
 *
 * A hold is a row of key_bulk_hold (0014, append-only for the app login). It is written for a proposal of a spot-check's
 * POPULATION (the screened --report names those), but it holds that proposal's LISTING: KeyEligibility gives every
 * proposal of a held listing the reason `held_for_review`, the held one and any newer one (a new matching run or a re-band
 * replaces the open proposal), so no bulk confirm of any sample links the listing, however often it is run and whatever
 * file it is (or is not) given, and no later sample draws it into its population. Nothing else changes: the proposal stays
 * open, the listing stays in the normal Key queue, and its review screen says "Held back from the bulk confirm: <reason>".
 * A release is a row of its own naming the hold it ends (a hold is released at most once), so a listing is held while some
 * hold of it has no release; no row is ever changed.
 *
 * run(): a file of rows (proposal_id, listing_id, reason) for one sample, by an active mapping lead (hold and release).
 * Every row is checked; the ones that pass are held (or released) and the others are listed with why, never dropped
 * silently (a refused row is not held: the caller must fix it and run again):
 *   bad_proposal_id, bad_listing_id, no_reason, bad_reason, reason_too_long   the row itself
 *   duplicate_in_file                        the proposal is on an earlier row of the file
 *   not_in_population                        the proposal is not in the sample's population
 *   sample_member                            it is one of the sample's own members (its owner confirms those one at a time)
 *   listing_mismatch                         the listing is not the proposal's (a typo in one of the two)
 *   listing_mapped, listing_ignored, listing_quarantined   hold: the listing is no longer waiting (decided, or linked by a
 *                                            bulk confirm: a hold could not change it). A proposal replaced by a newer one
 *                                            on a listing still waiting IS held: the hold covers the listing's newer proposal.
 *   not_held                                 release: it was never held
 * Two outcomes change nothing and are not refusals, so a re-run of the same file is harmless: already_held (this proposal's
 * hold, or another hold of its listing, is in force), already_released. Dry run unless $apply.
 *
 * Against a bulk confirm running at the same moment (any sample's): the holds are written in one transaction that first
 * reads the listings FOR SHARE (in id order) and then checks every row again. A bulk link of one of them either commits
 * first (the row is then refused: listing_mapped) or waits for the hold to commit and then finds it, by the listing, after
 * its link and rolls back (KeyBulk: held_meanwhile). Audited once per run that writes something (mapping.key_hold,
 * mapping.key_hold_release).
 */
final class KeyHold
{
    public const MAX_REASON = 500;
    public const MAX_ROWS = 10_000;
    public const MAX_BYTES = 4_000_000;
    /** The first row names these columns, in any order; other columns (e.g. those of a bulk --report file) are ignored. */
    public const COLUMNS = ['proposal_id', 'listing_id', 'reason'];
    public const AUDIT_HOLD = 'mapping.key_hold';
    public const AUDIT_RELEASE = 'mapping.key_hold_release';
    /** Outcomes that write a row (with $apply), and those that change nothing. */
    public const WRITES = ['hold', 'release'];
    public const ALREADY = ['already_held', 'already_released'];
    /** The holds without a release (+ a condition on h). */
    private const ACTIVE_SQL = 'SELECT h.id, h.sample_id, k.name AS sample, h.proposal_id, h.listing_id, h.reason, h.staff_user_id, h.created_at, '
        . 'u.display_name, u.email FROM key_bulk_hold h JOIN key_sample k ON k.id = h.sample_id LEFT JOIN staff_user u ON u.id = h.staff_user_id '
        . "WHERE h.kind = 'hold' AND NOT EXISTS (SELECT 1 FROM key_bulk_hold r WHERE r.released_hold_id = h.id) AND ";

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The rows of a hold file: a header row naming proposal_id, listing_id and reason, then one row per proposal (blank rows
     * are skipped; a UTF-8 byte order mark is dropped). A row that cannot be read keeps its row number and says why.
     *
     * @return list<array{row: int, proposal_id: ?int, listing_id: ?int, reason: string, problem: ?string}>
     * @throws CwException bad_file (400): no such header, too large, too many rows
     */
    public static function parse(string $text): array
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw new CwException('bad_file', 'the file is larger than ' . self::MAX_BYTES . ' bytes', 400);
        }
        if (str_starts_with($text, "\u{FEFF}")) {
            $text = substr($text, 3);
        }
        $fh = fopen('php://memory', 'w+b');
        if ($fh === false) {
            throw new \RuntimeException('cannot open a memory stream');
        }
        $cols = null;
        $rows = [];
        $record = 0;
        try {
            fwrite($fh, $text);
            rewind($fh);
            while (($cells = fgetcsv($fh, null, ',', '"', '')) !== false) {
                $record++;
                if (implode('', array_map(static fn (mixed $c): string => trim((string) $c), $cells)) === '') {
                    continue;
                }
                if ($cols === null) {
                    $names = array_map(static fn (mixed $c): string => strtolower(trim((string) $c)), $cells);
                    $cols = [];
                    foreach (self::COLUMNS as $c) {
                        $at = array_keys($names, $c, true);
                        if (count($at) !== 1) {
                            throw new CwException('bad_file', 'the first row must name the columns ' . implode(', ', self::COLUMNS)
                                . ' (each once, in any order)', 400);
                        }
                        $cols[$c] = $at[0];
                    }
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    throw new CwException('bad_file', 'the file has more than ' . self::MAX_ROWS . ' rows', 400);
                }
                $pid = self::id($cells[$cols['proposal_id']] ?? null);
                $lid = self::id($cells[$cols['listing_id']] ?? null);
                [$reason, $why] = self::reason($cells[$cols['reason']] ?? null);
                $rows[] = ['row' => $record, 'proposal_id' => $pid, 'listing_id' => $lid, 'reason' => $reason,
                    'problem' => $pid === null ? 'bad_proposal_id' : ($lid === null ? 'bad_listing_id' : $why)];
            }
        } finally {
            fclose($fh);
        }
        if ($cols === null) {
            throw new CwException('bad_file', 'the file is empty: its first row must name the columns ' . implode(', ', self::COLUMNS), 400);
        }
        return $rows;
    }

    /**
     * The holds in force on these LISTINGS, whatever the sample and whatever proposal of the listing the hold was written
     * for: listing id => its oldest hold that has no release. This is what keeps a listing out of every bulk confirm.
     *
     * @param list<int> $listingIds
     * @return array<int, array{hold_id: int, sample_id: int, sample: string, proposal_id: int, listing_id: int, reason: string,
     *         by_id: int, by: ?string, by_email: ?string, at: string}>
     */
    public static function activeForListings(Db $db, array $listingIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $listingIds))), 500) as $chunk) {
            foreach ($db->all(self::ACTIVE_SQL . 'h.listing_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY h.id', $chunk) as $h) {
                $out[(int) $h['listing_id']] ??= self::view($h);
            }
        }
        return $out;
    }

    /**
     * Every hold of a sample's population in force now, oldest first, with what became of its listing since (the sample
     * screen, and the counts of run()): `open` (the listing is still waiting: unmapped or suggested), the listing's open
     * proposal now (`open_proposal_id`: another one than the held proposal after a new run or a re-band; the hold covers it),
     * and for a listing linked since, the batch of the decision that linked it (`linked_by_batch`: a bulk confirm must never
     * have done that).
     *
     * @return list<array<string, mixed>> activeForListings() rows + proposal_status, listing_status, open, open_proposal_id,
     *         linked_by_batch, channel, variant, title, variant_title
     */
    public static function ofSample(Db $db, int $sampleId): array
    {
        $out = [];
        foreach ($db->all(
            'SELECT h.id, h.sample_id, k.name AS sample, h.proposal_id, h.listing_id, h.reason, h.staff_user_id, h.created_at, u.display_name, u.email, '
            . 'p.status AS proposal_status, cl.status AS listing_status, cl.external_variant_id, c.code AS channel_code, lp.product_title, lp.variant_title, '
            . 'op.id AS open_proposal_id, d.bulk_batch_id AS linked_by_batch '
            . 'FROM key_bulk_hold h JOIN key_sample k ON k.id = h.sample_id LEFT JOIN staff_user u ON u.id = h.staff_user_id '
            . 'JOIN match_proposal p ON p.id = h.proposal_id JOIN channel_listing cl ON cl.id = h.listing_id JOIN channel c ON c.id = cl.channel_id '
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = h.listing_id LEFT JOIN match_proposal op ON op.open_listing_id = h.listing_id '
            . 'LEFT JOIN listing_map_history mh ON mh.open_listing_id = h.listing_id LEFT JOIN match_decision d ON d.id = mh.decision_id '
            . "WHERE h.sample_id = ? AND h.kind = 'hold' AND NOT EXISTS (SELECT 1 FROM key_bulk_hold r WHERE r.released_hold_id = h.id) ORDER BY h.id",
            [$sampleId],
        ) as $h) {
            $pid = (int) $h['proposal_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = self::view($h) + ['proposal_status' => (string) $h['proposal_status'], 'listing_status' => (string) $h['listing_status'],
                    'open' => in_array($h['listing_status'], KeyEligibility::OPEN_LISTING, true),
                    'open_proposal_id' => $h['open_proposal_id'] === null ? null : (int) $h['open_proposal_id'],
                    'linked_by_batch' => $h['listing_status'] === 'mapped' && $h['linked_by_batch'] !== null ? (string) $h['linked_by_batch'] : null,
                    'channel' => (string) $h['channel_code'], 'variant' => (string) $h['external_variant_id'], 'title' => $h['product_title'],
                    'variant_title' => $h['variant_title']];
            }
        }
        return array_values($out);
    }

    /** How many holds of a sample are in force on a listing that is still waiting (what the bulk confirm reports as `held`). */
    public static function heldOpen(Db $db, int $sampleId): int
    {
        return count(array_filter(self::ofSample($db, $sampleId), static fn (array $h): bool => $h['open']));
    }

    /**
     * Holds (or with $release releases) the rows of a file for one sample. $by must be an active mapping lead.
     *
     * @param list<array{row: int, proposal_id: ?int, listing_id: ?int, reason: string, problem: ?string}> $rows parse()
     * @param array{name?: string, sha256?: string} $file where the rows came from (audited)
     * @return array{sample: array{id: int, name: string, owner: ?string, owner_email: ?string, verdict: string}, mode: string,
     *         applied: bool, rows: int, lines: list<array{row: int, proposal_id: ?int, listing_id: ?int, reason: string, outcome: string,
     *         code: ?string, note: ?string, hold_ids: list<int>, listing_status: ?string}>, todo: int, already: int, refused: array<string, int>,
     *         written: int, held_before: int, held_after: int} held_before / held_after: the sample's holds in force on a listing still
     *         waiting (heldOpen(), what the bulk confirm then reports as `held`), before and after this run (a dry run: as it would be)
     */
    public function run(Caller $by, string $sampleName, array $rows, bool $release, bool $apply, array $file = []): array
    {
        $staff = KeySample::lead($this->db, $by);
        $samples = new KeySample($this->db);
        $k = $samples->find($sampleName) ?? throw new CwException('unknown_sample', "no sample named {$sampleName}", 404);
        $sid = (int) $k['id'];
        $mode = $release ? 'release' : 'hold';
        $before = self::heldOpen($this->db, $sid);
        $written = 0;
        if (!$apply) {
            $lines = $this->classify($sid, $rows, $release);
        } else {
            [$lines, $written] = $this->db->transaction(function (Db $db) use ($by, $staff, $k, $sid, $rows, $release, $mode, $file): array {
                if (!$release) {
                    // The listings first, FOR SHARE and in id order: a bulk link of one of them by any sample's bulk confirm
                    // (DecisionService locks the listing FOR UPDATE) either commits before the check below reads the listing's
                    // status (the row is then refused), or waits for this hold and then finds it by the listing.
                    $lids = array_values(array_unique(array_filter(array_map(static fn (array $r): ?int => $r['listing_id'], $rows), static fn (?int $v): bool => $v !== null)));
                    sort($lids);
                    foreach (array_chunk($lids, 500) as $chunk) {
                        $db->all('SELECT id FROM channel_listing WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY id FOR SHARE', $chunk);
                    }
                }
                $lines = $this->classify($sid, $rows, $release);
                $done = [];
                foreach ($lines as $l) {
                    if ($l['outcome'] === 'hold') {
                        $id = $db->insert("INSERT INTO key_bulk_hold (sample_id, proposal_id, listing_id, kind, reason, staff_user_id, actor) VALUES (?, ?, ?, 'hold', ?, ?, ?)",
                            [$sid, $l['proposal_id'], $l['listing_id'], $l['reason'], $staff['id'], $by->actor]);
                        $done[] = [$id, $l['proposal_id'], $l['listing_id'], $l['reason']];
                    } elseif ($l['outcome'] === 'release') {
                        foreach ($l['hold_ids'] as $hid) {
                            $id = $db->insert('INSERT INTO key_bulk_hold (sample_id, proposal_id, listing_id, kind, released_hold_id, reason, staff_user_id, actor) '
                                . "VALUES (?, ?, ?, 'release', ?, ?, ?, ?)", [$sid, $l['proposal_id'], $l['listing_id'], $hid, $l['reason'], $staff['id'], $by->actor]);
                            $done[] = [$id, $hid, $l['proposal_id'], $l['listing_id'], $l['reason']];
                        }
                    }
                }
                if ($done !== []) {
                    $refused = [];
                    foreach ($lines as $l) {
                        if ($l['outcome'] === 'refused') {
                            $refused[] = [$l['row'], $l['proposal_id'], $l['code']];
                        }
                    }
                    Audit::write($db, $by, $release ? self::AUDIT_RELEASE : self::AUDIT_HOLD, 'key_sample', (string) $sid, null, [
                        'sample' => (string) $k['name'], 'mode' => $mode, 'file' => $file['name'] ?? null, 'sha256' => $file['sha256'] ?? null,
                        'rows' => count($rows), $release ? 'released' : 'held' => $done,
                        'already' => count(array_filter($lines, static fn (array $l): bool => in_array($l['outcome'], self::ALREADY, true))),
                        'refused' => $refused, 'decision' => 'M30',
                    ]);
                }
                return [$lines, count(array_filter($lines, static fn (array $l): bool => in_array($l['outcome'], self::WRITES, true)))];
            });
        }
        $todo = 0;
        $already = 0;
        $refused = [];
        foreach ($lines as $l) {
            if (in_array($l['outcome'], self::WRITES, true)) {
                $todo++;
            } elseif (in_array($l['outcome'], self::ALREADY, true)) {
                $already++;
            } else {
                $refused[(string) $l['code']] = ($refused[(string) $l['code']] ?? 0) + 1;
            }
        }
        ksort($refused);
        $after = $apply ? self::heldOpen($this->db, $sid) : $before + count(array_filter($lines, static fn (array $l): bool => $l['outcome'] === 'hold'))
            - count(array_filter($lines, static fn (array $l): bool => $l['outcome'] === 'release' && in_array($l['listing_status'], KeyEligibility::OPEN_LISTING, true)));
        return [
            'sample' => ['id' => $sid, 'name' => (string) $k['name'], 'owner' => $k['created_by_name'], 'owner_email' => $k['created_by_email'],
                'verdict' => $samples->verdicts([$sid])[$sid]['verdict'] ?? 'unknown'],
            'mode' => $mode, 'applied' => $apply, 'rows' => count($rows), 'lines' => $lines, 'todo' => $todo, 'already' => $already,
            'refused' => $refused, 'written' => $written, 'held_before' => $before, 'held_after' => $after,
        ];
    }

    /**
     * What each row of the file would do now (see the class comment for the outcomes and refusals). A hold is decided by the
     * LISTING: one in force on it (this proposal's, or another hold of the listing) makes the row `already_held`; a listing
     * no longer waiting refuses it (`listing_<status>`), whatever became of the proposal.
     *
     * @param list<array{row: int, proposal_id: ?int, listing_id: ?int, reason: string, problem: ?string}> $rows
     * @return list<array{row: int, proposal_id: ?int, listing_id: ?int, reason: string, outcome: string, code: ?string, note: ?string,
     *         hold_ids: list<int>, listing_status: ?string}>
     */
    private function classify(int $sampleId, array $rows, bool $release): array
    {
        $pids = array_values(array_unique(array_filter(array_map(static fn (array $r): ?int => $r['proposal_id'], $rows), static fn (?int $v): bool => $v !== null)));
        $lids = array_values(array_unique(array_filter(array_map(static fn (array $r): ?int => $r['listing_id'], $rows), static fn (?int $v): bool => $v !== null)));
        $members = [];
        $holds = [];
        $released = [];
        foreach (array_chunk($pids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($this->db->all('SELECT m.proposal_id, m.listing_id, m.position FROM key_sample_member m '
                . "WHERE m.sample_id = ? AND m.proposal_id IN ({$in})", [$sampleId, ...$chunk]) as $m) {
                $members[(int) $m['proposal_id']] = ['listing_id' => (int) $m['listing_id'], 'member' => $m['position'] !== null];
            }
            foreach ($this->db->all('SELECT h.id, h.proposal_id, h.reason, EXISTS (SELECT 1 FROM key_bulk_hold r WHERE r.released_hold_id = h.id) AS released '
                . "FROM key_bulk_hold h WHERE h.sample_id = ? AND h.kind = 'hold' AND h.proposal_id IN ({$in}) ORDER BY h.id", [$sampleId, ...$chunk]) as $h) {
                if ((int) $h['released'] === 1) {
                    $released[(int) $h['proposal_id']] = true;
                } else {
                    $holds[(int) $h['proposal_id']][] = ['id' => (int) $h['id'], 'reason' => (string) $h['reason']];
                }
            }
        }
        $status = [];
        foreach (array_chunk($lids, 500) as $chunk) {
            foreach ($this->db->all('SELECT id, status FROM channel_listing WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $l) {
                $status[(int) $l['id']] = (string) $l['status'];
            }
        }
        $listingHolds = self::activeForListings($this->db, $lids);
        $elsewhere = static fn (array $h): string => "{$h['reason']} (the listing is held under proposal {$h['proposal_id']} of the sample {$h['sample']})";
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $pid = $r['proposal_id'];
            $lid = $r['listing_id'];
            $m = $pid !== null ? ($members[$pid] ?? null) : null;
            $lh = $lid !== null ? ($listingHolds[$lid] ?? null) : null;
            $outcome = 'refused';
            $code = null;
            $note = null;
            $holdIds = [];
            if ($r['problem'] !== null) {
                $code = $r['problem'];
            } elseif (isset($seen[$pid])) {
                $code = 'duplicate_in_file';
            } elseif ($m === null) {
                $code = 'not_in_population';
                // e.g. the listing's newer proposal: the hold to name is the one the listing is held under
                $note = $lh !== null ? $elsewhere($lh) : null;
            } elseif ($m['member']) {
                $code = 'sample_member';
            } elseif ($m['listing_id'] !== $lid) {
                $code = 'listing_mismatch';
            } elseif (!$release) {
                if (isset($holds[$pid])) {
                    $outcome = 'already_held';
                    $note = $holds[$pid][0]['reason'];
                } elseif ($lh !== null) {
                    $outcome = 'already_held';
                    $note = $elsewhere($lh);
                } elseif (!in_array($status[$lid] ?? null, KeyEligibility::OPEN_LISTING, true)) {
                    $code = 'listing_' . ($status[$lid] ?? 'gone');
                } else {
                    $outcome = 'hold';
                }
            } elseif (isset($holds[$pid])) {
                $outcome = 'release';
                $note = $holds[$pid][0]['reason'];
                $holdIds = array_column($holds[$pid], 'id');
            } elseif (isset($released[$pid])) {
                $outcome = 'already_released';
            } else {
                $code = 'not_held';
                $note = $lh !== null ? $elsewhere($lh) : null;
            }
            if ($pid !== null && $r['problem'] === null) {
                $seen[$pid] = true;
            }
            $out[] = ['row' => $r['row'], 'proposal_id' => $pid, 'listing_id' => $lid, 'reason' => $r['reason'], 'outcome' => $outcome,
                'code' => $code, 'note' => $note, 'hold_ids' => $holdIds, 'listing_status' => $lid !== null ? ($status[$lid] ?? null) : null];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $h a key_bulk_hold row joined with its sample's name and the staff user
     * @return array{hold_id: int, sample_id: int, sample: string, proposal_id: int, listing_id: int, reason: string, by_id: int, by: ?string,
     *         by_email: ?string, at: string}
     */
    private static function view(array $h): array
    {
        return ['hold_id' => (int) $h['id'], 'sample_id' => (int) $h['sample_id'], 'sample' => (string) $h['sample'], 'proposal_id' => (int) $h['proposal_id'],
            'listing_id' => (int) $h['listing_id'], 'reason' => (string) $h['reason'], 'by_id' => (int) $h['staff_user_id'],
            'by' => $h['display_name'] === null ? null : (string) $h['display_name'], 'by_email' => $h['email'] === null ? null : (string) $h['email'],
            'at' => (string) $h['created_at']];
    }

    /** A positive id as the file gives it (digits only), else null. */
    private static function id(mixed $v): ?int
    {
        $s = is_string($v) ? trim($v) : '';
        return preg_match('/^[1-9][0-9]{0,17}$/D', $s) === 1 ? (int) $s : null;
    }

    /**
     * The reason as stored: spaces and line breaks collapsed to one space, trimmed; at most MAX_REASON characters, valid UTF-8,
     * no other control character.
     *
     * @return array{0: string, 1: ?string} the reason ('' when refused) and the refusal
     */
    private static function reason(mixed $v): array
    {
        $s = is_string($v) ? $v : '';
        if (!mb_check_encoding($s, 'UTF-8')) {
            return ['', 'bad_reason'];
        }
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        if ($s === '') {
            return ['', 'no_reason'];
        }
        if (preg_match('/\p{Cc}/u', $s) === 1) {
            return ['', 'bad_reason'];
        }
        if (mb_strlen($s) > self::MAX_REASON) {
            return ['', 'reason_too_long'];
        }
        return [$s, null];
    }
}
