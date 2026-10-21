<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\Mapping\ListingIngestService;
use CW\Tests\Support\MappingTestCase;
use CW\Ui\Queries;

/**
 * The website products' search by the stored search text of 0049 (docs/decisions.md SI1-SI6), on the real schema: the listing intake
 * keeps it in step (a push that renames a variant is found by its new words at once, and no longer by its old ones) on every search
 * (Find, the lists, Store Products, New Variants, the quick match); a text longer than the column is still found by a word past the
 * cut; the quick match still finds a word of the option number, which is not in the stored text; and a search reads the narrow index,
 * not the profiles. ForgivingSearchTest holds the searches to everything the forgiving search finds.
 */
final class SearchIndexTest extends MappingTestCase
{
    private Queries $q;
    private Caller $alt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->q = new Queries(self::$db);
        $this->alt = $this->site('alt', 'shadow');
    }

    /** @param array<string, mixed> $p */
    private function push(string $variant, array $p): int
    {
        return (int) (new ListingIngestService(self::$db))->ingest(Caller::system('test'), (int) $this->alt->channelId, [['variant_id' => $variant] + $p])['listings'][0]['listing_id'];
    }

    /** @return array<string, list<int>> what each search finds for $text */
    private function finds(string $text): array
    {
        $store = (int) $this->alt->channelId;
        return [
            'store' => array_map(static fn (array $r): int => (int) $r['listing_id'], $this->q->storeProducts($store, null, $text, 'sold_30', 1)['rows']),
            'new' => $this->q->newVariants($store, null, null, $text, 1, $this->q->newVariantCounts($store, null))['ids'],
            'find' => array_map(static fn (array $r): int => (int) $r['id'], $this->q->searchListings($text)),
            'list' => array_map(static fn (array $r): int => (int) $r['listing_id'], $this->q->queue('Check', $store, $text, null, 0, 1)['rows']),
            'quick' => $this->q->waitingListings($store, $text, 1)['ids'],
        ];
    }

    public function testTheIntakeKeepsTheSearchTextInStepOnEverySearch(): void
    {
        $id = $this->push('9001', ['product_title' => 'Hayati Pro Max Plus 6000', 'variant_title' => 'Hayati Pro Max Plus 6000 - Strawberry Kiwi', 'brand' => 'Hayati',
            'barcodes' => ['5056168800073']]);
        $this->propose($id, 'Check', self::makeSku('Hayati Pro Max Plus Strawberry Kiwi'));
        $all = ['store' => [$id], 'new' => [$id], 'find' => [$id], 'list' => [$id], 'quick' => [$id]];
        $none = array_map(static fn (): array => [], $all);
        self::assertSame($all, $this->finds('kiwi hayati promax'));
        self::assertSame($all, $this->finds('5056168800073'), 'the barcode');
        // the website renames it: the intake writes the profile, MySQL its search text, in the same statement
        $this->push('9001', ['product_title' => 'Elux Legend 3500', 'variant_title' => 'Elux Legend 3500 - Blue Razz', 'brand' => 'Elux', 'barcodes' => ['5056168800073']]);
        self::assertSame('EluxLegend3500EluxLegend3500BlueRazzElux', (string) self::$db->value('SELECT search_names FROM listing_profile WHERE listing_id = ?', [$id]));
        // (the list is left out from here: a rename is an identity change, and what happens to the suggestion is not this test's)
        $without = static fn (array $found): array => array_diff_key($found, ['list' => true]);
        self::assertSame($without($none), $without($this->finds('kiwi hayati promax')), 'the old words find it no more');
        self::assertSame($without($all), $without($this->finds('legend blue razz')), 'the new words find it at once');
        self::assertSame($without($all), $without($this->finds('bluerazz')), 'and joined');
    }

    public function testATextLongerThanTheColumnIsFoundByAWordPastTheCut(): void
    {
        $id = $this->push('9002', ['product_title' => str_repeat('Long name ', 50), 'variant_title' => str_repeat('Longer still ', 38) . 'Zebrafruit', 'brand' => 'Acme']);
        $this->propose($id, 'Check', self::makeSku('Zebrafruit item'));
        self::assertSame([1, 760], array_map('intval', array_values((array) self::$db->one('SELECT search_on_row, CHAR_LENGTH(search_names) FROM listing_profile WHERE listing_id = ?', [$id]))));
        self::assertStringNotContainsString('Zebrafruit', (string) self::$db->value('SELECT search_names FROM listing_profile WHERE listing_id = ?', [$id]), 'the word is past the cut');
        self::assertSame(['store' => [$id], 'new' => [$id], 'find' => [$id], 'list' => [$id], 'quick' => [$id]], $this->finds('zebrafruit acme'));
    }

    public function testTheQuickMatchFindsAWordOfTheOptionNumberWhichTheStoredTextHasNot(): void
    {
        $id = $this->push('AB-9917', ['product_title' => 'Plain pods', 'variant_title' => 'Plain pods - 0.6', 'brand' => 'Acme']);
        self::assertSame([$id], $this->q->waitingListings((int) $this->alt->channelId, '9917', 1)['ids'], 'a part of the option number');
        self::assertSame([$id], $this->q->waitingListings((int) $this->alt->channelId, 'ab9917 pods', 1)['ids'], 'without its hyphen, with a word of the names');
        self::assertSame([$id], $this->q->waitingListings((int) $this->alt->channelId, 'AB-9917', 1)['ids'], 'the whole option number');
        self::assertSame([], $this->q->waitingListings((int) $this->alt->channelId, '9917 zebra', 1)['ids'], 'every word must be found');
    }

    public function testASearchReadsTheNarrowIndexNotTheProfiles(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->push('v' . $i, ['product_title' => 'Product ' . $i, 'variant_title' => 'Option ' . $i, 'brand' => 'Acme']);
        }
        self::$db->exec('ANALYZE TABLE listing_profile');
        $plan = self::$db->one('EXPLAIN SELECT s.listing_id FROM listing_profile s FORCE INDEX (ix_listing_profile_search_names) WHERE s.search_names LIKE ?', ['%option%']);
        self::assertSame(['ix_listing_profile_search_names', 'index'], [(string) $plan['key'], (string) $plan['type']]);
        self::assertStringContainsString('Using index', (string) $plan['Extra']);
        $plan = self::$db->one('EXPLAIN SELECT s.listing_id FROM listing_profile s FORCE INDEX (ix_listing_profile_search_all) WHERE s.search_all LIKE ?', ['%option%']);
        self::assertStringContainsString('Using index', (string) $plan['Extra']);
        self::assertCount(30, $this->finds('acme option')['store']);
    }

    /**
     * Codex's review of 7307ab1 (runs/searchix_codex_review.txt): what the quick match found before is found, also where the stored
     * text is not the text: an attribute whose value has a backslash or a tab (its JSON text writes them escaped), a variant with no
     * profile found by a part of its option number, an option number with "&", and a word that runs from the option number into the
     * product's title.
     */
    public function testCodexR1EscapedJsonValuesProfilelessOptionsAmpersandOptionsAndWordsAcrossTheOptionNumber(): void
    {
        $store = (int) $this->alt->channelId;
        $quick = fn (string $text): array => $this->q->waitingListings($store, $text, 1)['ids'];
        $slash = $this->push('9101', ['product_title' => 'Coil set', 'brand' => 'Acme', 'attributes' => ['Note' => 'prefix abc\\def suffix', 'Size' => "20\tmg"]]);
        $found = [];
        foreach (['abc\\def', "20\tmg"] as $t) {
            $found[] = $quick($t);
        }
        $bare = $this->listing($this->alt, 'NOPROFILE7', null);
        $found[] = $quick('PROFILE7');
        $amp = $this->push('R&M-9917', ['product_title' => 'Plain pods', 'brand' => 'Acme']);
        $found[] = $quick('randm');
        $found[] = $quick('r&m pods');
        $across = $this->push('27', ['product_title' => 'Electrofag pods', 'brand' => 'Acme']);
        $found[] = $quick('27electrofag');
        // every one as the old search found it (all of them at once, so a red run shows each miss)
        self::assertSame([[$slash], [$slash], [$bare], [$amp], [$amp], [$across]], $found,
            'escaped JSON values; a part of the option number of a variant with no profile; "&" in an option number; a word from the option number into the title');
        self::assertSame(1, (int) self::$db->value('SELECT search_on_row FROM listing_profile WHERE listing_id = ?', [$slash]), 'its JSON text is not its values: searched on its row');
    }
}
