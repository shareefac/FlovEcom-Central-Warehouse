<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Db;

/**
 * The variants no bulk action ever takes, and why (docs/decisions.md M63, M64): the ONE read that answers it. Three kinds, asked in
 * this order (a variant that is more than one is the first):
 *
 *  - `held`: a hold is in force on the listing (key_bulk_hold, KeyHold, M30: a strong match that looked doubtful, set aside for
 *    one-at-a-time review), with the hold's reason, who set it aside and when;
 *  - `in_spot_check`: the listing is one of the matches of a spot check that is not complete (key_sample_member with a position,
 *    KeySample's verdict `waiting` or `failed`, M28: only the spot check's owner answers them, one at a time; a bulk decision on
 *    one would fail the spot check), with the spot check's name;
 *  - `on_arrival`: the variant's open suggestion was found by the check on arrival (Mapping\ArrivalCheck, M64: a barcode or a
 *    copied web address and no AI). While the setting `mapping.arrival_bulk_confirm` is off, every such suggestion; with it on,
 *    only the ones the check wrote with a warning (a clean match is then an ordinary row). They are told BY THEIR RUN: the check
 *    writes a run's clean matches under one match_run row (source `arrival`) and everything else under another (source
 *    `arrival_warned`), so arrivalRuns() names the kind by a handful of run ids and neither this read nor the lists' filter ever
 *    opens a suggestion's flags. With no such suggestion in the data arrivalRuns() is empty and nothing here reads or changes.
 *
 * Who asks here: BulkDecisions, before a row is sent (prechecks(): the row is skipped with this code and detail, whatever the
 * action and the list) and again under the row's lock (one()); and the lists whose rows are variants, for the rows of their page
 * (Products › Mapping › To review, Store Products, New Variants): such a row has no tick box and says so under its name. (By
 * Item ticks items, not variants: it asks nothing here itself, and its preview, which is BulkDecisions', lists such a variant
 * as skipped, M56.) A row and its skip therefore cannot disagree. The "Show" filter of the match-strength lists and its counts (Ui\Queries) are built
 * from the SQL below, which states the same conditions: the hold in force is KeyHold::IN_FORCE, the text KeyHold itself reads
 * with; the spot checks' matches are the answer of the same read (spotMatchesToDecide(): spotChecksOf() for the few dozen
 * variants that are a match of any spot check and still to decide), named to the SQL by their ids; the check on arrival's
 * suggestions are the open ones of arrivalRuns(), named to the SQL by those run ids (arrivalSql()), which of() reads too.
 *
 * The variant's own page is where such a variant is decided, one at a time (docs/decisions.md DG2): its decision forms carry what the
 * page showed of it (seen()), and DecisionService asks seen() again under the listing's lock, so a decision sent without the page
 * as it is now (a hand-made form, another page's button, a page drawn before the hold) is refused, 409 one_at_a_time.
 *
 * Read only. Nothing here decides anything, and the rule itself is unchanged: a hand-made form that names such a row is skipped
 * exactly as before.
 */
final class OneAtATime
{
    public const HELD = 'held';
    public const SPOT = 'in_spot_check';
    public const ARRIVAL = 'on_arrival';
    /** The kinds, in the order they are asked (BulkDecisions::SKIPS holds each). */
    public const KINDS = [self::HELD, self::SPOT, self::ARRIVAL];
    /** One of a spot check's matches, as a condition on the member row `m` (the rest of its population has no position). */
    private const MEMBER = 'm.position IS NOT NULL';

    /**
     * Of these variants, the ones no bulk action takes: listing id => why. `detail` is what a skipped row records and what the
     * result page's words are filled from (held: reason, by, at; in_spot_check: sample, the spot check's name; on_arrival: none);
     * `sample_id` and `sample` name the spot check (a hold names the one whose population it was written for), for a link (0 and
     * '' for on_arrival: it belongs to none).
     *
     * @param list<int> $listingIds
     * @param list<int>|null $arrivalRuns arrivalRuns() when the caller has read them; read here when not given
     * @return array<int, array{code: string, detail: array<string, mixed>, sample_id: int, sample: string}>
     */
    public static function of(Db $db, array $listingIds, ?array $arrivalRuns = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $listingIds)));
        if ($ids === []) {
            return [];
        }
        $held = KeyHold::activeForListings($db, $ids);
        $spot = self::spotChecksOf($db, $ids);
        $arrival = self::onArrival($db, $ids, $arrivalRuns ?? self::arrivalRuns($db));
        $out = [];
        foreach ($ids as $lid) {
            if (isset($held[$lid])) {
                $h = $held[$lid];
                $out[$lid] = ['code' => self::HELD, 'detail' => ['reason' => $h['reason'], 'by' => $h['by'], 'at' => $h['at']], 'sample_id' => $h['sample_id'],
                    'sample' => $h['sample']];
            } elseif (isset($spot[$lid])) {
                $out[$lid] = ['code' => self::SPOT, 'detail' => ['sample' => $spot[$lid]['name']], 'sample_id' => $spot[$lid]['id'], 'sample' => $spot[$lid]['name']];
            } elseif (isset($arrival[$lid])) {
                $out[$lid] = ['code' => self::ARRIVAL, 'detail' => [], 'sample_id' => 0, 'sample' => ''];
            }
        }
        return $out;
    }

    /**
     * of() for variants that still wait for a match (unmapped or suggested, no decision waiting for a second OK: what By Item's quick
     * match lists, Ui\Queries::waitingListings and suggestedFor), in the same few reads however many spot checks they are in: the
     * holds, the matches of spot checks, the check on arrival's suggestions. A spot check's verdict is not asked (of() asks KeySample
     * for it, spot check by spot check): KeySample counts a match confirmed only while its listing is linked by the owner's own link,
     * so a variant that still waits is never a confirmed match, and a spot check that holds one is not complete. The answer is of()'s
     * for such a variant (a test holds them equal); for any other variant ask of().
     *
     * @param list<int> $listingIds variants that still wait for a match
     * @param list<int>|null $arrivalRuns arrivalRuns() when the caller has read them; read here when not given
     * @return array<int, array{code: string, detail: array<string, mixed>, sample_id: int, sample: string}>
     */
    public static function ofWaiting(Db $db, array $listingIds, ?array $arrivalRuns = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $listingIds)));
        if ($ids === []) {
            return [];
        }
        $held = KeyHold::activeForListings($db, $ids);
        $spot = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($db->all('SELECT m.listing_id, m.sample_id, k.name FROM key_sample_member m JOIN key_sample k ON k.id = m.sample_id '
                . 'WHERE ' . self::MEMBER . ' AND m.listing_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY m.sample_id', $chunk) as $m) {
                // The oldest spot check first, as spotChecksOf() takes it.
                $spot[(int) $m['listing_id']] ??= ['id' => (int) $m['sample_id'], 'name' => (string) $m['name']];
            }
        }
        $arrival = self::onArrival($db, $ids, $arrivalRuns ?? self::arrivalRuns($db));
        $out = [];
        foreach ($ids as $lid) {
            if (isset($held[$lid])) {
                $h = $held[$lid];
                $out[$lid] = ['code' => self::HELD, 'detail' => ['reason' => $h['reason'], 'by' => $h['by'], 'at' => $h['at']], 'sample_id' => $h['sample_id'],
                    'sample' => $h['sample']];
            } elseif (isset($spot[$lid])) {
                $out[$lid] = ['code' => self::SPOT, 'detail' => ['sample' => $spot[$lid]['name']], 'sample_id' => $spot[$lid]['id'], 'sample' => $spot[$lid]['name']];
            } elseif (isset($arrival[$lid])) {
                $out[$lid] = ['code' => self::ARRIVAL, 'detail' => [], 'sample_id' => 0, 'sample' => ''];
            }
        }
        return $out;
    }

    /**
     * What a variant's own page shows of this, as its decision forms carry it (`seen`, docs/decisions.md DG2): `held:<hold id>`,
     * `in_spot_check:<spot check id>` or `on_arrival:<suggestion id>`, by of()'s rule and in its order; null when the variant is none
     * of them. The page draws it into its forms; DecisionService asks it again under the listing's lock and refuses a decision from a
     * page that names anything else (409 one_at_a_time). The ids make it a statement about THIS hold, spot check or suggestion: a hold
     * released and written again since the page was drawn is another one, whose note the person has not seen.
     */
    public static function seen(Db $db, int $listingId): ?string
    {
        $why = self::of($db, [$listingId])[$listingId] ?? null;
        return match ($why['code'] ?? null) {
            null => null,
            self::HELD => self::HELD . ':' . (KeyHold::activeForListings($db, [$listingId])[$listingId]['hold_id'] ?? 0),
            self::SPOT => self::SPOT . ':' . $why['sample_id'],
            default => self::ARRIVAL . ':' . (int) $db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$listingId]),
        };
    }

    /**
     * The match_run rows whose OPEN suggestions are decided one at a time now (M64): the check on arrival's runs that still have
     * an open suggestion; of both its sources while `mapping.arrival_bulk_confirm` is off, only the ones with a warning
     * (ArrivalCheck::SOURCE_WARNED) while it is on. Read from two indexes and no row: the runs by their id's first letters (the
     * unique key (run_id, source); every run of the check is named `arrival-...`), each asked on the index (run, status) of the
     * suggestions whether one is still open. A handful of numbers: of() and the SQL of the lists (arrivalSql()) both take them, so
     * a row and its filter cannot disagree. Empty while the check has written nothing that is still open: nothing reads further.
     *
     * @return list<int>
     */
    public static function arrivalRuns(Db $db): array
    {
        $sources = ArrivalCheck::bulkConfirm($db) ? [ArrivalCheck::SOURCE_WARNED] : ArrivalCheck::SOURCES;
        return array_map('intval', $db->column('SELECT r.id FROM match_run r WHERE r.run_id LIKE ? AND r.source IN (' . implode(',', array_fill(0, count($sources), '?')) . ') '
            . "AND EXISTS (SELECT /*+ INDEX(p ix_match_proposal_run_open) */ 1 FROM match_proposal p WHERE p.match_run_id = r.id AND p.status = 'open') ORDER BY r.id",
            ['arrival-%', ...$sources]));
    }

    /**
     * Is this suggestion one of the check on arrival's that are decided one at a time now, WHATEVER ITS STATE (M64)? What a bulk
     * action asks under the row's lock about the suggestion its row named, after the decision (BulkDecisions::one()). of() reads
     * a variant's OPEN suggestion, and a confirm, a "create as new", an ignore and a "send back" have just closed it: of() alone
     * would not see `mapping.arrival_bulk_confirm` switched off while a batch runs. The same sources as arrivalRuns(), by the
     * suggestion's primary key and its run's.
     */
    public static function arrivalSuggestion(Db $db, int $proposalId): bool
    {
        $sources = ArrivalCheck::bulkConfirm($db) ? [ArrivalCheck::SOURCE_WARNED] : ArrivalCheck::SOURCES;
        return $db->value('SELECT 1 FROM match_proposal p JOIN match_run r ON r.id = p.match_run_id WHERE p.id = ? AND r.source IN ('
            . implode(',', array_fill(0, count($sources), '?')) . ')', [$proposalId, ...$sources]) !== null;
    }

    /**
     * Of these variants, the ones whose open suggestion is of one of $runs: listing id => true. The suggestions by their own
     * unique key (the open one of a listing), a page of them at most.
     *
     * @param list<int> $listingIds
     * @param list<int> $runs
     * @return array<int, true>
     */
    private static function onArrival(Db $db, array $listingIds, array $runs): array
    {
        $out = [];
        if ($runs === []) {
            return $out;
        }
        foreach (array_chunk($listingIds, 500) as $chunk) {
            foreach ($db->column('SELECT p.open_listing_id FROM match_proposal p WHERE p.open_listing_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') AND '
                . self::arrivalSql('p.match_run_id', $runs), $chunk) as $lid) {
                $out[(int) $lid] = true;
            }
        }
        return $out;
    }

    /**
     * The listings that are one of the matches of a spot check that is not complete (M28), each with that spot check (the oldest
     * such one when it is a match of several). The members come from the index on the listing; a spot check's verdict is
     * KeySample's, asked only for the spot checks found.
     *
     * @param list<int> $listingIds
     * @return array<int, array{id: int, name: string}> listing id => the spot check
     */
    public static function spotChecksOf(Db $db, array $listingIds): array
    {
        $members = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $listingIds))), 500) as $chunk) {
            foreach ($db->all('SELECT m.listing_id, m.sample_id, k.name FROM key_sample_member m JOIN key_sample k ON k.id = m.sample_id '
                . 'WHERE ' . self::MEMBER . ' AND m.listing_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY m.sample_id', $chunk) as $m) {
                $members[(int) $m['listing_id']][(int) $m['sample_id']] = (string) $m['name'];
            }
        }
        if ($members === []) {
            return [];
        }
        $open = self::notComplete($db, array_values(array_unique(array_merge(...array_map('array_keys', array_values($members))))));
        $out = [];
        foreach ($members as $lid => $samples) {
            foreach ($samples as $sid => $name) {
                if (isset($open[$sid])) {
                    $out[$lid] = ['id' => $sid, 'name' => $name];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * The variants still to decide (unmapped or suggested) that are one of the matches of a spot check that is not complete, each
     * with that spot check: listing id => the spot check. What a list's "In a spot check" filter and its count name in their SQL
     * (spotSql()). It is spotChecksOf() of the candidates, so for a variant still to decide it cannot say anything else than the
     * row's own answer. The candidates: the spot checks' ids (a handful of rows), then for those ids the matches with a position
     * (one range of the index (sample, position) for each spot check: its 20, not its population) and each match's listing by its
     * primary key. Never a population, never a list.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function spotMatchesToDecide(Db $db): array
    {
        $samples = array_map('intval', $db->column('SELECT id FROM key_sample ORDER BY id'));
        if ($samples === []) {
            return [];
        }
        $candidates = array_map('intval', $db->column('SELECT /*+ JOIN_ORDER(m, cl) INDEX(m uq_key_sample_member_position) */ DISTINCT m.listing_id '
            . 'FROM key_sample_member m JOIN channel_listing cl ON cl.id = m.listing_id WHERE m.sample_id IN (' . implode(',', $samples) . ') AND ' . self::MEMBER
            . ' AND cl.status IN (' . implode(',', array_fill(0, count(KeyEligibility::OPEN_LISTING), '?')) . ')', KeyEligibility::OPEN_LISTING));
        return $candidates === [] ? [] : self::spotChecksOf($db, $candidates);
    }

    /**
     * SQL that is true of a variant held back: a hold in force on the listing `$listingId` names (a column of the outer query,
     * `cl.id`, say). The condition is KeyHold::IN_FORCE, by the index on the hold's listing.
     */
    public static function heldSql(string $listingId): string
    {
        return '(EXISTS (SELECT 1 FROM key_bulk_hold h WHERE h.listing_id = ' . self::column($listingId) . ' AND ' . KeyHold::IN_FORCE . '))';
    }

    /**
     * SQL that is true of a variant that is one of the spot checks' matches named ($matches: the listing ids spotMatchesToDecide()
     * gives, a few dozen numbers). None named: true of nothing.
     *
     * @param list<int> $matches
     */
    public static function spotSql(string $listingId, array $matches): string
    {
        return $matches === [] ? '(1 = 0)' : '(' . self::column($listingId) . ' IN (' . implode(',', array_map('intval', $matches)) . '))';
    }

    /**
     * SQL that is true of a suggestion found by the check on arrival that is decided one at a time now: its run (`$runColumn`, a
     * column of the outer query: `p.match_run_id`) is one of $runs (arrivalRuns()). None named: true of nothing.
     *
     * @param list<int> $runs
     */
    public static function arrivalSql(string $runColumn, array $runs): string
    {
        return $runs === [] ? '(1 = 0)' : '(' . self::column($runColumn) . ' IN (' . implode(',', array_map('intval', $runs)) . '))';
    }

    /**
     * Of these spot checks, the ones that are not complete: KeySample's verdict is `waiting` (some match not answered yet) or
     * `failed`; one that cannot be read counts as waiting.
     *
     * @param list<int> $sampleIds
     * @return array<int, true>
     */
    private static function notComplete(Db $db, array $sampleIds): array
    {
        $verdicts = (new KeySample($db))->verdicts($sampleIds);
        $out = [];
        foreach ($sampleIds as $sid) {
            if (($verdicts[$sid]['verdict'] ?? 'waiting') !== 'complete') {
                $out[$sid] = true;
            }
        }
        return $out;
    }

    /** A column name as the SQL above takes it (`cl.id`): written by the code that asks, never taken from a request. */
    private static function column(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException('not a column: ' . $name);
        }
        return $name;
    }
}
