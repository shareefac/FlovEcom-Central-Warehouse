<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Db;

/**
 * The variants no bulk action ever takes, and why (docs/decisions.md M63): the ONE read that answers it. Two kinds, asked in this
 * order (a variant that is both is the first):
 *
 *  - `held`: a hold is in force on the listing (key_bulk_hold, KeyHold, M30: a strong match that looked doubtful, set aside for
 *    one-at-a-time review), with the hold's reason, who set it aside and when;
 *  - `in_spot_check`: the listing is one of the matches of a spot check that is not complete (key_sample_member with a position,
 *    KeySample's verdict `waiting` or `failed`, M28: only the spot check's owner answers them, one at a time; a bulk decision on
 *    one would fail the spot check), with the spot check's name.
 *
 * Who asks here: BulkDecisions, before a row is sent (prechecks(): the row is skipped with this code and detail, whatever the
 * action and the list) and again under the row's lock (one()); and the lists whose rows are variants, for the rows of their page
 * (Products › Mapping › To review, Store Products, New Variants): such a row has no tick box and says so under its name. (By
 * Item ticks items, not variants: it asks nothing here itself, and its preview, which is BulkDecisions', lists such a variant
 * as skipped, M56.) A row and its skip therefore cannot disagree. The "Show" filter of the match-strength lists and its counts (Ui\Queries) are built
 * from the SQL below, which states the same two conditions: the hold in force is KeyHold::IN_FORCE, the text KeyHold itself reads
 * with; the spot checks' matches are the answer of the same read (spotMatchesToDecide(): spotChecksOf() for the few dozen
 * variants that are a match of any spot check and still to decide), named to the SQL by their ids.
 *
 * Read only. Nothing here decides anything, and the rule itself is unchanged: a hand-made form that names such a row is skipped
 * exactly as before.
 */
final class OneAtATime
{
    public const HELD = 'held';
    public const SPOT = 'in_spot_check';
    /** The kinds, in the order they are asked (BulkDecisions::SKIPS holds both). */
    public const KINDS = [self::HELD, self::SPOT];
    /** One of a spot check's matches, as a condition on the member row `m` (the rest of its population has no position). */
    private const MEMBER = 'm.position IS NOT NULL';

    /**
     * Of these variants, the ones no bulk action takes: listing id => why. `detail` is what a skipped row records and what the
     * result page's words are filled from (held: reason, by, at; in_spot_check: sample, the spot check's name); `sample_id` and
     * `sample` name the spot check (a hold names the one whose population it was written for), for a link.
     *
     * @param list<int> $listingIds
     * @return array<int, array{code: string, detail: array<string, mixed>, sample_id: int, sample: string}>
     */
    public static function of(Db $db, array $listingIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $listingIds)));
        if ($ids === []) {
            return [];
        }
        $held = KeyHold::activeForListings($db, $ids);
        $spot = self::spotChecksOf($db, $ids);
        $out = [];
        foreach ($ids as $lid) {
            if (isset($held[$lid])) {
                $h = $held[$lid];
                $out[$lid] = ['code' => self::HELD, 'detail' => ['reason' => $h['reason'], 'by' => $h['by'], 'at' => $h['at']], 'sample_id' => $h['sample_id'],
                    'sample' => $h['sample']];
            } elseif (isset($spot[$lid])) {
                $out[$lid] = ['code' => self::SPOT, 'detail' => ['sample' => $spot[$lid]['name']], 'sample_id' => $spot[$lid]['id'], 'sample' => $spot[$lid]['name']];
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
