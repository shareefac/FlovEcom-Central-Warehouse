<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Clock;
use CW\Db;
use CW\Matching\Normalizer;
use CW\Settings;

/**
 * The website state of a variant (docs/decisions.md M66; migration 0028): whether it is on sale on its website (its own status,
 * its product's status) and whether it is a page heading there, not something that is sold. "Create as new items" must not make
 * an item from a draft, a binned variant or a heading, and until 0028 the warehouse knew this only for the variants a one-off
 * matching run had looked at.
 *
 * WHERE IT LIVES. The raw inputs, as the website gives them, in eight columns of listing_profile (COLUMNS and DATE): the two
 * statuses, the product's type and number, "landing row", "default variant", the product's address, and ONE date for all of them
 * (as of when they were true). Not in `features` (an identity change wipes those), not in the identity, in neither hash.
 *
 * WHO WRITES IT. Only the listing intake (ListingIngestService), with what its caller supplies: a push may carry the optional
 * object `website` (KEY; parse() reads it leniently: a wrong type is ignored and counted, never refused), an import of an export
 * file carries what the file holds (fromExport()), and the state-only load writes nothing else. A supplied state is WHOLE: what
 * it leaves out is not known from then on, never kept from an older state under the new date.
 *
 * WHO READS IT, IN WHICH ORDER (read()). (1) The stored columns: a profile with a date has a stored state, and that is THE state,
 * with what it does not know. (2) Else the stored features of a matching run (`variant_status`, `is_placeholder`). (3) Else what
 * the open suggestion recorded when the computer checked the variant (its evidence's `variant_status`). A state from (2) or (3)
 * has no date; it is relied on exactly as it was before the columns were there. (4) None of the three: NOT KNOWN.
 *
 * ONE THING IS ASKED OF (2) AND (3) BESIDE A STORED STATE (against(); the review of build 2a, F1): what they say AGAINST the
 * variant. A bulk "create as new" skipped a variant its features called a page heading, or whose features or record gave a
 * status that is not Published, before the columns were there, and a stored state must never make it less strict than it was.
 * Nothing they say FOR a variant is read beside a stored state.
 *
 * NOT KNOWN IS A STATE. A missing value is never read as "on sale" and never as "not a page heading": notOnSale() is null for
 * it, heading() is null for it, and doubt() names it. A stored state older than the setting `mapping.arrival_state_max_days`
 * reads as not known too (tooOld()).
 *
 * THE PAGE HEADING of a stored state is the Normalizer's own answer (its placeholder rule), asked of the Normalizer with the
 * stored raw inputs, the profile's titles, barcodes and units sold, and the number of published variants of the same product on
 * the same store, counted from the database (publishedSiblings()). This class knows no part of that rule: when one of the inputs
 * it hands over is missing, the answer is "not known". Known limit: the count sees the variants whose state is stored; a variant
 * of the product that arrived without a state is not counted.
 *
 * Pure but for the two database reads at the end (publishedSiblings(), maxDays()).
 */
final class SiteState
{
    /** The status of a variant, and of a product, that is on sale, as the websites say it. */
    public const PUBLISHED = 'Published';
    /** The key of a pushed listing that carries its state. Not `site`: an export line uses that word for its store's code. */
    public const KEY = 'website';
    /** The values of a state, by the name a push uses => the listing_profile column each is stored in. */
    public const COLUMNS = ['variant_status' => 'site_variant_status', 'product_status' => 'site_product_status', 'product_type' => 'site_product_type',
        'product_id' => 'site_product_id', 'is_landing' => 'site_is_landing', 'is_default' => 'site_is_default', 'product_link' => 'site_product_link'];
    /** As of when a stored state was true; NULL: no state was ever stored for the profile. */
    public const DATE = 'site_state_at';
    /** The values the readers use: a change of one of them is "the state changed" (the product's address is read by nothing yet). */
    public const READ = ['variant_status', 'product_status', 'product_type', 'product_id', 'is_landing', 'is_default'];
    /** Where a state was read from (read()). */
    public const STORED = 'stored';
    public const FEATURES = 'features';
    public const EVIDENCE = 'evidence';
    /** How old a stored state may be, in days: the setting, and its value when the row is not there (a schema before 0028). */
    public const SETTING = 'mapping.arrival_state_max_days';
    public const MAX_DAYS = 30;
    /** The longest texts the columns hold: a status or a type; a product's number; an address. */
    private const TEXT_MAX = 32;
    private const ID_MAX = 64;
    private const LINK_MAX = 512;

    // ------------------------------------------------------------------------------------------------------------------
    // What a caller of the listing intake supplies

    /**
     * The state one line of a first-match export carries (tools/first_match/export.php writes these fields on every line), under
     * the names a push uses: what bin/import_listings.php adds to the line's listing as `website`. Values as the file has them
     * (parse() cleans them, as it does a push's); a field the line does not have is null.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function fromExport(array $row): array
    {
        return [
            'variant_status' => $row['variant_status'] ?? null,
            'product_status' => $row['product_status'] ?? null,
            'product_type' => $row['product_type'] ?? null,
            'product_id' => $row['product_id'] ?? null,
            'is_landing' => $row['is_landing'] ?? null,
            'is_default' => $row['is_default'] ?? null,
            'product_link' => $row['product_permalink'] ?? null,
        ];
    }

    /**
     * A listing's `website` value, read leniently: this is a data feed, and a state that cannot be read must not cost the listing
     * its push. Never a refusal. A value of the wrong type, or one that cannot be what it says (a status longer than a status, a
     * product number that is not printable, a yes-or-no that is neither), is IGNORED AND COUNTED, and is then not known; null and
     * an empty text are "not known" and are not counted; a key that is not one of COLUMNS is left alone. Something that is no
     * object at all is ignored whole and counted once.
     *
     * `state` is null when nothing usable was supplied (the stored state then stays as it is); otherwise it has all seven values,
     * each null when not known: a supplied state replaces the stored one whole.
     *
     * @return array{state: array<string, mixed>|null, ignored: int}
     */
    public static function parse(mixed $website): array
    {
        if ($website === null || $website === []) {
            return ['state' => null, 'ignored' => 0];
        }
        if (!is_array($website) || array_is_list($website)) {
            return ['state' => null, 'ignored' => 1];
        }
        $state = array_fill_keys(array_keys(self::COLUMNS), null);
        $ignored = 0;
        foreach (['variant_status', 'product_status', 'product_type'] as $k) {
            $v = $website[$k] ?? null;
            if ($v === null) {
                continue;
            }
            if (!is_string($v) || mb_strlen(trim($v)) > self::TEXT_MAX) {
                $ignored++;
                continue;
            }
            $state[$k] = trim($v) === '' ? null : trim($v);
        }
        $id = $website['product_id'] ?? null;
        if ($id !== null) {
            $id = is_int($id) && $id >= 0 ? (string) $id : (is_string($id) ? trim($id) : false);
            if ($id === false || strlen($id) > self::ID_MAX || preg_match('/^[\x21-\x7e]*$/D', $id) !== 1) {
                $ignored++;
            } else {
                $state['product_id'] = $id === '' ? null : $id;
            }
        }
        foreach (['is_landing', 'is_default'] as $k) {
            $v = $website[$k] ?? null;
            if ($v === null) {
                continue;
            }
            if (!in_array($v, [true, false, 1, 0, '1', '0'], true)) {
                $ignored++;
                continue;
            }
            $state[$k] = $v === true || $v === 1 || $v === '1';
        }
        $link = $website['product_link'] ?? null;
        if ($link !== null) {
            if (!is_string($link)) {
                $ignored++;
            } else {
                $state['product_link'] = trim($link) === '' ? null : mb_substr(trim($link), 0, self::LINK_MAX);
            }
        }
        return ['state' => array_filter($state, static fn (mixed $v): bool => $v !== null) === [] ? null : $state, 'ignored' => $ignored];
    }

    /**
     * A parsed state as the seven columns hold it, in the order of COLUMNS (yes and no as 1 and 0).
     *
     * @param array<string, mixed> $state parse()'s
     * @return list<mixed>
     */
    public static function values(array $state): array
    {
        $out = [];
        foreach (array_keys(self::COLUMNS) as $k) {
            $v = $state[$k] ?? null;
            $out[] = is_bool($v) ? (int) $v : $v;
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------------------------------
    // The stored state, and the three readers

    /**
     * The state a profile row holds in its columns, in parse()'s shape with its date (`as_of`); null when no state was ever
     * stored for it (no date).
     *
     * @param array<string, mixed> $row a listing_profile row, or a part of one: the columns of COLUMNS and DATE
     * @return array<string, mixed>|null
     */
    public static function stored(array $row): ?array
    {
        $at = $row[self::DATE] ?? null;
        if (!is_string($at) || $at === '') {
            return null;
        }
        $out = [];
        foreach (self::COLUMNS as $k => $column) {
            $v = $row[$column] ?? null;
            $out[$k] = match (true) {
                $v === null => null,
                $k === 'is_landing' || $k === 'is_default' => (int) $v === 1,
                default => (string) $v,
            };
        }
        return $out + ['as_of' => $at];
    }

    /**
     * Whether a newly supplied state says something else than the stored one, of what the readers use (READ). Known before and
     * not known now, or the other way round, is a change.
     *
     * @param array<string, mixed> $old stored()'s
     * @param array<string, mixed> $new parse()'s
     */
    public static function changed(array $old, array $new): bool
    {
        foreach (self::READ as $k) {
            if (($old[$k] ?? null) !== ($new[$k] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The state of a variant as the readers give it, in their order (the class's docblock): the stored columns; else the stored
     * features; else the open suggestion's record; else not known.
     *
     * `source` says which one answered (null: none: not known). `as_of` is the stored state's date (the other two have none).
     * `variant_status` and `product_status` are texts as the website says them, or null when not known (only a stored state knows
     * the product's). The raw inputs of the page heading (`product_type`, `product_id`, `is_landing`, `is_default`) are a stored
     * state's only. `heading` is what the FEATURES recorded (true, false, or null when they say nothing): the heading of a stored
     * state is not in this answer, it is worked out by heading().
     *
     * @param array<string, mixed> $row the profile's columns of COLUMNS and DATE (missing ones read as NULL)
     * @param array<string, mixed>|null $features the stored features, or the two values read out of them: `variant_status` (a
     *        text; the text "null" is JSON's null) and `is_placeholder` (true, 1, or their texts)
     * @param array<string, mixed>|null $evidence the open suggestion's evidence, or its `variant_status` read out of it
     * @return array{source: ?string, as_of: ?string, variant_status: ?string, product_status: ?string, product_type: ?string, product_id: ?string,
     *     is_landing: ?bool, is_default: ?bool, heading: ?bool}
     */
    public static function read(array $row, ?array $features = null, ?array $evidence = null): array
    {
        $none = ['source' => null, 'as_of' => null, 'variant_status' => null, 'product_status' => null, 'product_type' => null, 'product_id' => null,
            'is_landing' => null, 'is_default' => null, 'heading' => null];
        $stored = self::stored($row);
        if ($stored !== null) {
            return ['source' => self::STORED, 'as_of' => (string) $stored['as_of']] + array_intersect_key($stored, $none) + $none;
        }
        $status = self::text($features['variant_status'] ?? null);
        $flag = $features['is_placeholder'] ?? null;
        $heading = match (true) {
            $flag === true || $flag === 1 || $flag === 'true' || $flag === '1' => true,
            $flag === false || $flag === 0 || $flag === 'false' || $flag === '0' => false,
            default => null,
        };
        if ($status !== null || $heading === true) {
            return ['source' => self::FEATURES, 'variant_status' => $status, 'heading' => $heading] + $none;
        }
        $status = self::text($evidence['variant_status'] ?? null);
        if ($status !== null) {
            return ['source' => self::EVIDENCE, 'variant_status' => $status] + $none;
        }
        return $none;
    }

    /** A status as one source gives it (a text), or null when it gives none. */
    public static function text(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' && $v !== 'null' ? mb_substr(trim($v), 0, 64) : null;
    }

    /**
     * The status that says a variant is NOT on sale, or null: its own status when that is known and is not Published; else its
     * product's, when that is known and is not Published (a published variant under an unpublished product is not on sale; the
     * exports count it the same way). Null also when nothing is known: not known is not "not on sale".
     *
     * @param array<string, mixed> $state read()'s
     */
    public static function notOnSale(array $state): ?string
    {
        foreach (['variant_status', 'product_status'] as $k) {
            if (is_string($state[$k] ?? null) && $state[$k] !== self::PUBLISHED) {
                return $state[$k];
            }
        }
        return null;
    }

    /**
     * What the two readers that have no date say AGAINST a variant beside its STORED state: `heading`, whether its features call
     * it a page heading (`is_placeholder`); and `status`, the status its features give, else its open suggestion's record, when
     * that is not Published (null when they give none, or give Published). For a state that is not a stored one the answer is
     * "nothing" (false, null): read() has asked the features and the record of that one already, and nothing is said twice.
     *
     * Why they are asked at all, when a stored state is THE state (the review of build 2a, F1). Before the columns were there a
     * bulk "create as new" skipped a variant for these two answers. The stored state alone can know less than they do: the
     * features of a matching run can be NEWER than it (a run imported after the state's export, from an export whose own state
     * was not stored: a file with no time in its name, a line the state-only load left, or the run's import without the
     * listings'), and the page heading of a stored state hangs on a count that can be short (a variant of the product that has
     * no stored state is not counted). So BulkDecisions::seedCheck() and New Variants ask here, after the stored state's own
     * status and where the features were asked before, and are never less strict than they were. The cost, accepted: a variant
     * whose OLD features say Draft or page heading stays one at a time although a newer stored state says it is on sale, exactly
     * as before the columns were there, until a matching run rewrites its features. What they say FOR a variant ("Published",
     * "no heading") is not read beside a stored state, here or anywhere.
     *
     * @param array<string, mixed> $state read()'s
     * @param array<string, mixed>|null $features as read()'s
     * @param array<string, mixed>|null $evidence as read()'s
     * @return array{heading: bool, status: ?string}
     */
    public static function against(array $state, ?array $features, ?array $evidence): array
    {
        if (($state['source'] ?? null) !== self::STORED) {
            return ['heading' => false, 'status' => null];
        }
        $undated = self::read([], $features, $evidence);
        return ['heading' => $undated['heading'] === true, 'status' => self::notOnSale($undated)];
    }

    // ------------------------------------------------------------------------------------------------------------------
    // The age, and what cannot be relied on

    /**
     * Whether a stored state's date is more than $maxDays days before $now. No date, or one that cannot be read, is never fresh.
     * A date a little ahead of this clock (another machine's) is not old.
     */
    public static function tooOld(?string $asOf, int $maxDays, \DateTimeImmutable $now): bool
    {
        if ($asOf === null) {
            return true;
        }
        try {
            $at = Clock::fromDb($asOf);
        } catch (\UnexpectedValueException) {
            return true;
        }
        return Clock::diff($at, $now) > $maxDays * 86400;
    }

    /**
     * Whether a stored state holds everything the page-heading rule is handed: the product's type and number (its published
     * variants are counted by the number), "landing row" and "default variant".
     *
     * @param array<string, mixed> $state read()'s
     */
    public static function headingInputs(array $state): bool
    {
        return ($state['source'] ?? null) === self::STORED && ($state['is_landing'] ?? null) !== null && ($state['product_type'] ?? null) !== null
            && ($state['is_default'] ?? null) !== null && ($state['product_id'] ?? null) !== null;
    }

    /**
     * Why a state cannot be relied on to say "this variant is on sale and is something that is sold", or null when it can. The
     * last question of a bulk "create as new" (BulkDecisions::seedCheck()) and the line under a row marked New (New Variants)
     * both ask here, so a row and its skip cannot disagree.
     *  - `not_known`: no reader gives a state; or the stored state lacks the variant's status, its product's status, or an input
     *    of the page heading;
     *  - `too_old`: the stored state is older than the setting;
     *  - null for a stored state that is whole and fresh, and for a state taken from the features or from a suggestion's record:
     *    that one has no date and is relied on exactly as it was before the columns were there (M66).
     * It does not say whether the variant IS on sale (notOnSale()) or IS a heading (heading()).
     *
     * @param array<string, mixed> $state read()'s
     */
    public static function doubt(array $state, int $maxDays, \DateTimeImmutable $now): ?string
    {
        $source = $state['source'] ?? null;
        if ($source === null) {
            return 'not_known';
        }
        if ($source !== self::STORED) {
            return null;
        }
        if (($state['variant_status'] ?? null) === null || ($state['product_status'] ?? null) === null || !self::headingInputs($state)) {
            return 'not_known';
        }
        return self::tooOld($state['as_of'] ?? null, $maxDays, $now) ? 'too_old' : null;
    }

    // ------------------------------------------------------------------------------------------------------------------
    // The page heading

    /**
     * Whether the variant is a page heading of its website (true, false), or null when that is not known: the Normalizer's own
     * answer (`is_placeholder`), asked with a stored state's raw inputs, the profile's titles, barcodes and units sold in the
     * month, and the number of published variants of the same product. Null when the state is not a stored one, when one of the
     * inputs handed over is missing (headingInputs(); $publishedSiblings null: they could not be counted), or when the Normalizer
     * cannot read the titles. Never "not a heading" because something was missing.
     *
     * @param array<string, mixed> $state read()'s
     * @param array<string, mixed> $profile the profile's product_title, variant_title, barcodes (JSON text, a list or null) and units_30d
     * @param ?int $publishedSiblings publishedSiblings()'s count
     */
    public static function heading(array $state, array $profile, ?int $publishedSiblings): ?bool
    {
        if (!self::headingInputs($state) || $publishedSiblings === null) {
            return null;
        }
        $codes = $profile['barcodes'] ?? null;
        $codes = is_string($codes) ? json_decode($codes, true) : $codes;
        try {
            // (the brand and the attributes are left out: the heading is a matter of the titles, the barcodes, the sales and the four
            // values of the website, and a variant the Normalizer cannot place in a range has an answer all the same)
            $f = Normalizer::normalize(['site' => '', 'variant_id' => 0, 'product_title' => (string) ($profile['product_title'] ?? ''),
                'variant_title' => (string) ($profile['variant_title'] ?? ''), 'brand' => '', 'attributes' => [], 'barcodes' => is_array($codes) ? array_values($codes) : [],
                'price' => null, 'sale_price' => null, 'units_30d' => (int) ($profile['units_30d'] ?? 0), 'is_landing' => $state['is_landing'] ? 1 : 0,
                'product_type' => (string) $state['product_type'], 'is_default' => $state['is_default'] ? 1 : 0], ['published_siblings' => $publishedSiblings]);
        } catch (\Throwable) {
            return null;
        }
        return ($f['is_placeholder'] ?? null) === true;
    }

    /**
     * How many variants of one product of one store are Published, by their stored states: what the Normalizer's rule calls the
     * published siblings (the variant itself is one of them when it is published). Null when the product's number is not known.
     * One range of the index on the product's number (0028) and, for each of its rows, the listing by its primary key: the number
     * is the website's own, so another store's product of the same number is left out by the listing's store.
     */
    public static function publishedSiblings(Db $db, int $channelId, ?string $productId): ?int
    {
        if ($productId === null || $productId === '') {
            return null;
        }
        return (int) $db->value('SELECT /*+ JOIN_ORDER(lp, cl) INDEX(lp ix_listing_profile_site_product) */ COUNT(*) FROM listing_profile lp '
            . 'JOIN channel_listing cl ON cl.id = lp.listing_id WHERE lp.site_product_id = ? AND cl.channel_id = ? AND lp.site_variant_status = ?',
            [$productId, $channelId, self::PUBLISHED]);
    }

    /** How many days old a stored state may be (the setting, read now; a day at the least, a year at the most). */
    public static function maxDays(Db $db): int
    {
        return min(365, max(1, Settings::number($db, self::SETTING, self::MAX_DAYS)));
    }
}
