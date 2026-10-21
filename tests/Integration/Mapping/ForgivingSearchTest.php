<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\Tests\Support\MappingTestCase;
use CW\Ui\Queries;

/**
 * Every product search of the matching pages reads the text the same way (docs/decisions.md SX1-SX3; CW\Ui\SearchText), on the
 * real schema: every word in any order, "R&M" / "R and M" / "RandM", "Elfbar" / "Elf Bar", "20mg" / "20 mg", a CW number however
 * it is written (on the lists also the website products suggested for or matched to it), a barcode with or without its leading
 * zero; and what the old searches found (the phrase, the option number, the barcode as typed, case and accents) is still found.
 */
final class ForgivingSearchTest extends MappingTestCase
{
    private Queries $q;
    private Caller $alt;
    /** @var array<string, int> */
    private array $sku = [];
    /** @var array<string, int> */
    private array $l = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->q = new Queries(self::$db);
        $this->site('vpg', 'shadow');
        $this->alt = $this->site('alt', 'shadow');
        $this->sku['tornado'] = $this->sku('Cool Mint Nic Salt E-Liquid R and M Tornado Salts', 'R and M', 'Tornado', 'Cool Mint');
        $this->sku['hayati'] = $this->sku('Hayati Pro Max Plus 6000 - Strawberry Kiwi', 'Hayati', 'Pro Max Plus', 'Strawberry Kiwi');
        $this->sku['elf'] = $this->sku('Blue Razz Lemonade Elf Bar 600 - 20 mg', 'Elf Bar', '600', 'Blue Razz Lemonade');
        $this->sku['creme'] = $this->sku('Crème Brûlée Nic Salt 10ml', 'Doozy Vape Co', null, 'Crème Brûlée');
        self::$db->exec('INSERT INTO sku_barcode (barcode, sku_id) VALUES (?, ?)', ['5056168800073', $this->sku['hayati']]);
        // Store "alt": a suggested Likely match (its barcode stored WITH a leading zero), a suggested one written "20 mg", two Tornado
        // variants (one waiting, one matched to the Tornado item), and an "Elfbar" one.
        $this->l['hayati'] = $this->variant('6444', 'Hayati Pro Max Plus 6000 Prefilled Pod Kit', 'Hayati Pro Max Plus 6000 Prefilled Pod Kit - 20mg | Strawberry Kiwi',
            'Hayati', ['05056168800073'], 30);
        $this->propose($this->l['hayati'], 'Check', $this->sku['hayati']);
        $this->l['tahiti'] = $this->variant('8077', 'TAHITI 10ml Nicotine Salt E-Liquid by Doozy Vape Co', 'TAHITI 10ml Nicotine Salt E-Liquid by Doozy Vape Co - 20 mg',
            'Doozy Vape Co', [], 20);
        $this->propose($this->l['tahiti'], 'Check', $this->sku['creme']);
        $this->l['randm'] = $this->variant('7001', 'RandM Tornado 7000 Disposable', 'RandM Tornado 7000 - Cool Mint', 'RandM', [], 10);
        $this->l['rm'] = $this->variant('7002', 'R&M Tornado 7000 Disposable', 'R&M Tornado 7000 - Cola', 'R&M', [], 5, $this->sku['tornado']);
        $this->l['elfbar'] = $this->variant('9001', 'Elfbar 600 Disposable', 'Elfbar 600 - Blue Razz Lemonade', 'Elfbar', [], 1);
    }

    private function sku(string $name, string $brand, ?string $line, ?string $flavour): int
    {
        $id = self::makeSku($name);
        self::$db->exec('UPDATE sku SET brand = ?, line = ?, flavour = ? WHERE id = ?', [$brand, $line, $flavour, $id]);
        return $id;
    }

    /** @param list<string> $barcodes */
    private function variant(string $option, string $title, string $optionTitle, string $brand, array $barcodes, int $u30, ?int $sku = null): int
    {
        $id = $this->listing($this->alt, $option, $sku);
        self::$db->exec('INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, barcodes, units_30d, units_365d) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $title, $optionTitle, $brand, json_encode($barcodes, JSON_THROW_ON_ERROR), $u30, $u30 * 10]);
        return $id;
    }

    private function store(): int
    {
        return (int) $this->alt->channelId;
    }

    /** @return list<int> the website products Store Products finds for $text (every page) */
    private function storeFinds(string $text): array
    {
        $r = $this->q->storeProducts($this->store(), null, $text, 'sold_30', 1);
        return array_map(static fn (array $row): int => (int) $row['listing_id'], $r['rows']);
    }

    /** @return list<int> */
    private function listFinds(string $text): array
    {
        return array_map(static fn (array $row): int => (int) $row['listing_id'], $this->q->queue('Check', $this->store(), $text, null, 0, 1)['rows']);
    }

    /** @return list<int> */
    private function newFinds(string $text): array
    {
        $counts = $this->q->newVariantCounts($this->store(), null);
        return $this->q->newVariants($this->store(), null, null, $text, 1, $counts)['ids'];
    }

    /** @return list<int> */
    private function itemFinds(string $text): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->q->searchSkus($text));
    }

    /** @return list<int> */
    private function findListings(string $text): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->q->searchListings($text));
    }

    /** @return list<int> By Item's list for $text */
    private function byItem(string $text): array
    {
        return $this->q->productIds(null, null, [$this->store()], $text);
    }

    /** @return list<int> the quick match's / picker's waiting variants */
    private function waiting(string $text): array
    {
        return $this->q->waitingListings($this->store(), $text, 1)['ids'];
    }

    public function testEveryWordInAnyOrderOnTheListsStoreProductsAndNewVariants(): void
    {
        foreach (['hayati strawberry kiwi', 'kiwi strawberry hayati', 'HAYATI kiwi'] as $text) {
            self::assertSame([$this->l['hayati']], $this->listFinds($text), "list: {$text}");
            self::assertSame([$this->l['hayati']], $this->storeFinds($text), "Store Products: {$text}");
            self::assertSame([$this->l['hayati']], $this->newFinds($text), "New Variants: {$text}");
            self::assertSame([$this->l['hayati']], $this->findListings($text), "Find: {$text}");
        }
        self::assertSame([$this->l['tahiti']], $this->listFinds('tahiti 20mg'), 'the option title says "20 mg"');
        self::assertSame([$this->l['hayati']], $this->listFinds('hayati 20 mg'), 'the option title says "20mg"');
        self::assertSame([], $this->listFinds('hayati cola'), 'every word must be found');
    }

    public function testABrandWrittenThreeWaysAndWordsJoinedOrSplit(): void
    {
        foreach (['R&M tornado', 'R and M tornado', 'randm tornado', 'r & m TORNADO'] as $text) {
            self::assertEqualsCanonicalizing([$this->l['randm'], $this->l['rm']], $this->storeFinds($text), "Store Products: {$text}");
            self::assertSame([$this->sku['tornado']], $this->itemFinds($text), "Find, items: {$text}");
            self::assertSame([$this->sku['tornado']], $this->byItem($text), "By Item: {$text}");
            self::assertSame([$this->l['randm']], $this->waiting($text), "quick match (waiting only): {$text}");
        }
        self::assertSame([$this->l['elfbar']], $this->storeFinds('elf bar 600'), '"Elfbar" on the website, "elf bar" typed');
        self::assertSame([$this->sku['elf']], $this->itemFinds('elfbar 20mg'), '"Elf Bar" and "20 mg" on the item, "elfbar 20mg" typed');
        self::assertSame([$this->sku['elf']], $this->itemFinds('20mg blue-razz'), 'a hyphen typed between two words');
        self::assertSame([$this->l['elfbar']], $this->waiting('elf bar'));
    }

    public function testACwNumberHoweverItIsWrittenAndOnTheListsTheWebsiteProductsOfThatItem(): void
    {
        $h = $this->sku['hayati'];
        foreach ([sprintf('CW-%06d', $h), "cw {$h}", "CW{$h}", "#{$h}"] as $text) {
            self::assertContains($h, $this->itemFinds($text), "Find, items: {$text}");
            self::assertSame([$this->l['hayati']], $this->listFinds($text), "list (the suggested item): {$text}");
        }
        // a bare number is also a CW number (and still its words: "20mg" holds a "2")
        self::assertContains($h, $this->itemFinds((string) $h));
        self::assertContains($this->l['hayati'], $this->listFinds((string) $h));
        self::assertSame([$h], $this->byItem("cw {$h}"));
        $t = sprintf('CW-%06d', $this->sku['tornado']);
        self::assertSame([$this->l['rm']], $this->storeFinds($t), 'Store Products: the website product matched to it');
        self::assertContains($this->l['rm'], $this->findListings("cw {$this->sku['tornado']}"), 'Find, website products: the one matched to it');
    }

    public function testABarcodeWithOrWithoutItsLeadingZero(): void
    {
        foreach (['5056168800073', '05056168800073'] as $code) {
            self::assertSame([$this->l['hayati']], $this->storeFinds($code), "Store Products: {$code}");
            self::assertSame([$this->l['hayati']], $this->listFinds($code), "list: {$code}");
            self::assertSame([$this->l['hayati']], $this->newFinds($code), "New Variants: {$code}");
            self::assertSame([$this->l['hayati']], $this->findListings($code), "Find, website products: {$code}");
            self::assertSame([$this->l['hayati']], $this->waiting($code), "quick match: {$code}");
            self::assertSame([$this->sku['hayati']], $this->itemFinds($code), "Find, items: {$code}");
            self::assertSame([$this->sku['hayati']], $this->byItem($code), "By Item: {$code}");
        }
    }

    /** What the old searches found is still found: the phrase as it is on the page, the option number, case and accents. */
    public function testWhatTheOldSearchesFoundIsStillFound(): void
    {
        foreach (['Hayati Pro Max Plus 6000 Prefilled Pod Kit', 'Prefilled Pod Kit - 20mg', '6444', '05056168800073', 'pod kit'] as $text) {
            self::assertContains($this->l['hayati'], $this->storeFinds($text), "Store Products: {$text}");
            self::assertContains($this->l['hayati'], $this->listFinds($text), "list: {$text}");
            self::assertContains($this->l['hayati'], $this->newFinds($text), "New Variants: {$text}");
        }
        self::assertSame([$this->l['rm']], $this->storeFinds('R&M Tornado 7000 - Cola'));
        self::assertSame([$this->l['elfbar']], $this->storeFinds('9001'), 'the option number');
        self::assertSame([$this->sku['creme']], $this->itemFinds('creme brulee'), 'accents');
        self::assertSame([$this->sku['creme']], $this->itemFinds('CRÈME'), 'case');
        self::assertSame([$this->sku['hayati']], $this->itemFinds('Strawberry Kiwi'));
        self::assertSame([$this->l['tahiti']], $this->waiting('8077'), 'the quick match: the option number');
        self::assertSame([$this->l['hayati']], $this->waiting('50561688'), 'the quick match: any part of a barcode');
    }

    public function testATextWithNothingToLookForFindsNothingAndBreaksNothing(): void
    {
        foreach (['&', '- , |', "\u{00A0}"] as $text) {
            self::assertSame([], $this->storeFinds($text), "Store Products: {$text}");
            self::assertSame([], $this->byItem($text), "By Item: {$text}");
            self::assertSame([], $this->waiting($text), "quick match: {$text}");
            self::assertSame([], $this->listFinds($text), "list: {$text}");
            self::assertSame([], $this->newFinds($text), "New Variants: {$text}");
        }
        // Store Products' one pass: the count and the page agree with what is found, and a page past the end is the last one
        $r = $this->q->storeProducts($this->store(), null, 'tornado', 'sold_30', 9);
        self::assertSame([2, 1, 1], [$r['total'], $r['page'], $r['pages']]);
        self::assertSame([$this->l['randm'], $this->l['rm']], array_map(static fn (array $row): int => (int) $row['listing_id'], $r['rows']), 'best sellers first');
    }
}
