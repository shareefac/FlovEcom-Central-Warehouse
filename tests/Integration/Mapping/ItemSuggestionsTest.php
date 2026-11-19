<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Mapping\ItemSuggestions;
use CW\Tests\Support\MappingTestCase;

/**
 * Which items a store's new variant may be (docs/decisions.md SX2, SX3, SX5, SX6; tracker CW-1138): a barcode first (on an item, or on a
 * store variant linked to one), then the same details (strict: brand family, flavour, strength, size, kind, range numbers and
 * modifiers), then a similar name; items as they are now (a joined item counts as the item it was joined into), at most five, each with
 * its free to sell for the store asking; a typed CW number in every form people type it, and what it is now.
 */
final class ItemSuggestionsTest extends MappingTestCase
{
    /** @param array<string, mixed> $card */
    private function carded(string $name, array $card, int $onHand = 0): int
    {
        $id = $this->item('legacy', $onHand, $name);
        $set = [];
        foreach (array_keys($card) as $k) {
            $set[] = "{$k} = ?";
        }
        self::$db->exec('UPDATE sku SET ' . implode(', ', $set) . ' WHERE id = ?', [...array_values($card), $id]);
        return $id;
    }

    /** @return array<string, mixed> */
    private static function legend(array $over = []): array
    {
        return $over + ['brand' => 'Elux Nic Salt (Legend Salts) E-Liquids', 'flavour' => 'lemon lime', 'strength_mg' => '5.00', 'volume_ml' => '10.00',
            'form' => 'nic_salt', 'nic_type' => 'salt', 'line' => 'elux legend'];
    }

    private function code(int $id): string
    {
        return (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$id]);
    }

    /** The CW-1138 variant as the store's Add variant knows it. @return array<string, mixed> */
    private static function cw1138(array $over = []): array
    {
        return $over + ['title' => 'Elux Legend Lemon & Lime Nic Salt E-liquid 10ml', 'option' => '5mg', 'brand' => 'Elux Legend',
            'attrs' => [['name' => 'Nicotine Strength', 'value' => '5mg']], 'barcodes' => []];
    }

    public function testTheCw1138VariantFindsItsItemByItsDetailsFirstWithItsFreeToSell(): void
    {
        $site = $this->site('vapebig');
        $item = $this->carded('Elux Legend Nic Salt - Lemon & Lime - 10ml - 5mg', self::legend(), 12);
        $ten = $this->carded('Elux Legend Nic Salt - Lemon & Lime - 10ml - 10mg', self::legend(['strength_mg' => '10.00']), 3);
        $other = $this->carded('Elux Legend Nic Salt - Menthol - 10ml - 5mg', self::legend(['flavour' => 'menthol']));
        $this->item('legacy', 0, 'Mr Blue Shortfill 100ml');

        $r = (new ItemSuggestions(self::$db))->suggest($site->channelId, self::cw1138());
        self::assertTrue($r['strong']);
        self::assertArrayNotHasKey('item', $r, 'no CW number asked: no item block');
        self::assertSame(['code' => $this->code($item), 'name' => 'Elux Legend Nic Salt - Lemon & Lime - 10ml - 5mg', 'free' => 12, 'why' => 'same_details'], $r['suggestions'][0]);
        $codes = array_column($r['suggestions'], 'code');
        self::assertContains($this->code($ten), $codes, 'the 10mg is a similar name');
        self::assertContains($this->code($other), $codes, 'the menthol 5mg is a similar name');
        foreach (array_slice($r['suggestions'], 1) as $s) {
            self::assertSame('similar_name', $s['why'], $s['code']);
        }
        self::assertNotContains('Mr Blue Shortfill 100ml', array_column($r['suggestions'], 'name'), 'an unrelated name is not suggested');
        // The name as one text (no option text, the brand "Elux" alone): its brand word read as flavour still finds it.
        $plain = (new ItemSuggestions(self::$db))->suggest($site->channelId, ['title' => 'Elux Legend Lemon & Lime Nic Salt E-liquid 10ml - 5mg', 'brand' => 'Elux']);
        self::assertSame([$this->code($item), 'same_details'], [$plain['suggestions'][0]['code'], $plain['suggestions'][0]['why']]);
        // Free to sell is the store's: a store selling from another warehouse sees 0.
        self::$db->exec("INSERT INTO warehouse (code, name, is_sellable) VALUES ('SX2', 'Another shop warehouse', 1)");
        $elsewhere = $this->site('otherwh', 'live', 'SX2');
        self::assertSame(0, (new ItemSuggestions(self::$db))->suggest($elsewhere->channelId, self::cw1138())['suggestions'][0]['free']);
    }

    public function testABarcodeComesFirstOnAnItemOrOnALinkedVariantAndAJoinedItemCountsAsTheItemItIsNow(): void
    {
        $site = $this->site('vapebig');
        $details = $this->carded('Elux Legend Nic Salt - Lemon & Lime - 10ml - 5mg', self::legend());
        $byCode = $this->item('legacy', 0, 'Old name of a 5mg');
        self::$db->exec("INSERT INTO sku_barcode (sku_id, barcode, units_per_scan) VALUES (?, '5061026755072', 1)", [$byCode]);
        $kept = $this->item('legacy', 0, 'Kept item');
        $gone = $this->item('legacy', 0, 'Gone item');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$kept, $gone]);
        $lid = $this->listing($this->site('electrofag'), '5183', $gone);
        self::$db->exec("INSERT INTO listing_profile (listing_id, product_title, barcodes) VALUES (?, 'An EF page', '[\"05061026755270\"]')", [$lid]);

        // one barcode as the store typed it (leading zero dropped), one on the item: both first, then the details
        $r = (new ItemSuggestions(self::$db))->suggest($site->channelId, self::cw1138(['barcodes' => ['5061026755270', '5061026755072']]));
        self::assertSame([[$this->code($byCode), 'barcode', '5061026755072'], [$this->code($kept), 'barcode', '05061026755270']],
            array_map(static fn (array $s): array => [$s['code'], $s['why'], $s['barcode']], array_slice($r['suggestions'], 0, 2)));
        self::assertSame([$this->code($details), 'same_details'], [$r['suggestions'][2]['code'], $r['suggestions'][2]['why']]);
        self::assertTrue($r['strong']);
        self::assertNotContains($this->code($gone), array_column($r['suggestions'], 'code'), 'never an item joined into another');
        self::assertSame(2, count((new ItemSuggestions(self::$db))->suggest($site->channelId, self::cw1138(['barcodes' => ['5061026755072']]), null, 2)['suggestions']),
            'the limit');
        // a barcode alone (no name) is a question too
        self::assertSame([$this->code($byCode)], array_column((new ItemSuggestions(self::$db))->suggest($site->channelId, ['barcodes' => ['5061026755072']])['suggestions'], 'code'));
    }

    public function testATypedCwNumberInEveryFormAndWhatItIsNow(): void
    {
        $site = $this->site('vapebig');
        $item = $this->item('legacy', 7, 'Elux Legend Nic Salt - Lemon & Lime - 10ml - 5mg');
        $code = $this->code($item);
        $digits = (string) (int) substr($code, 3);
        foreach ([$code, strtolower($code), 'cw-' . $digits, 'CW ' . $digits, $digits, ' ' . $code . ' ', 'cw' . $digits] as $typed) {
            self::assertSame($code, ItemSuggestions::code($typed), $typed);
        }
        foreach (['', 'CW-', 'CW-000000', '0', 'CW-1234567890', 'AB-011468', '11468x', "11468\n1", 'CW--11468', '1 1468'] as $bad) {
            self::assertNull(ItemSuggestions::code($bad), $bad);
        }
        self::assertSame('CW-1234567', ItemSuggestions::code('1234567'));
        self::assertNull(ItemSuggestions::code(11468), 'only text');

        $s = new ItemSuggestions(self::$db);
        self::assertSame(['code' => $code, 'name' => 'Elux Legend Nic Salt - Lemon & Lime - 10ml - 5mg', 'free' => 7, 'why' => 'code'], $s->lookup($site->channelId, $code));
        self::assertNull($s->lookup($site->channelId, 'CW-999999'));
        $gone = $this->item('legacy', 0, 'Joined away');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$item, $gone]);
        self::assertSame([$code, $this->code($gone)], [$s->lookup($site->channelId, $this->code($gone))['code'], $s->lookup($site->channelId, $this->code($gone))['named']]);
        $r = $s->suggest($site->channelId, ['title' => 'Elux Legend Lemon & Lime'], $this->code($gone));
        self::assertSame($code, $r['item']['code']);
        self::assertNull($s->suggest($site->channelId, [], 'CW-999999')['item'], 'a number alone, unknown');
    }

    public function testTheSameDetailsAreStrict(): void
    {
        $card = ItemSuggestions::card(ItemSuggestions::profile(self::cw1138()));
        self::assertSame(['lemon lime', 5.0, 10.0, 'nic_salt', ['elux', 'legend']], [$card['flavour'], $card['strength_mg'], $card['volume_ml'], $card['form'], $card['family']]);
        $same = static fn (array $over): bool => ItemSuggestions::sameDetails($card, self::legend($over));
        self::assertTrue($same([]));
        self::assertTrue($same(['flavour' => 'lime lemon']), 'the flavour words in any order');
        self::assertTrue($same(['volume_ml' => null, 'nic_type' => null]), 'an unknown size or type does not stop it');
        self::assertTrue($same(['brand' => 'Elux', 'line' => 'elux legend']), 'a brand family word in common');
        self::assertTrue($same(['form' => 'e_liquid']), 'the same kind of product (a liquid)');
        self::assertFalse($same(['strength_mg' => '10.00']), 'another strength');
        self::assertFalse($same(['strength_mg' => null]), 'a strength on one side only');
        self::assertFalse($same(['flavour' => 'lemon lime ice']), 'another flavour');
        self::assertFalse($same(['flavour' => null]), 'no flavour');
        self::assertFalse($same(['volume_ml' => '100.00']), 'another size');
        self::assertFalse($same(['form' => 'prefilled_pod']), 'another kind');
        self::assertFalse($same(['form' => null]), 'an unknown kind');
        self::assertFalse($same(['nic_type' => 'freebase']), 'another nicotine');
        self::assertFalse($same(['brand' => 'Hayati Pro Max', 'line' => 'hayati pro max']), 'another brand');
        self::assertFalse($same(['pack_units' => 10]), 'a pack of ten');
        self::assertFalse($same(['line' => 'elux legend 3500']), 'a range number on one side');
        self::assertFalse($same(['line' => 'elux legend pro']), 'a modifier on one side');

        $pods = ItemSuggestions::card(ItemSuggestions::profile(['title' => 'Elux Legend 3500 Prefilled Pods', 'option' => 'Lemon Lime', 'brand' => 'Elux',
            'attrs' => [['name' => 'Flavour', 'value' => 'Lemon Lime']]]));
        $podItem = ['brand' => 'Elux Vape', 'flavour' => 'lemon lime', 'strength_mg' => $pods['strength_mg'], 'volume_ml' => null, 'form' => $pods['form'],
            'nic_type' => null, 'line' => 'elux legend 3500', 'puffs' => 3500];
        self::assertTrue(ItemSuggestions::sameDetails($pods, $podItem), 'the same range number');
        self::assertFalse(ItemSuggestions::sameDetails($pods, ['line' => 'elux legend 8000', 'puffs' => 8000] + $podItem), 'another range number');
    }

    public function testAPacksBarcodeIsNeverSuggestedAsTheItemAndAnRandMBrandIsFoundAsTheMatchingReadsIt(): void
    {
        // Codex review r1 (a2): Existing item links one for one, so an outer case's barcode (10 units a scan), a barcode on two items
        // (not usable) or a store variant linked as a pack is never a suggestion of the item itself.
        $site = $this->site('vapebig');
        $single = $this->item('legacy', 0, 'Single bottle');
        self::$db->exec("INSERT INTO sku_barcode (sku_id, barcode, units_per_scan) VALUES (?, '5000000000017', 10)", [$single]);
        $doubt = $this->item('legacy', 0, 'Barcode in doubt');
        self::$db->exec("INSERT INTO sku_barcode (sku_id, barcode, units_per_scan, is_usable) VALUES (?, '5000000000024', 1, 0)", [$doubt]);
        $pack = $this->listing($this->site('electrofag'), '6001', $single, 5);
        self::$db->exec("INSERT INTO listing_profile (listing_id, product_title, barcodes) VALUES (?, 'A 5 pack', '[\"5000000000031\"]')", [$pack]);
        // ...nor through a store variant linked one for one that carries such a barcode (Codex re-check)
        $other = $this->item('legacy', 0, 'Another bottle');
        $one = $this->listing($this->site('vapeandgo'), '6002', $other);
        self::$db->exec("INSERT INTO listing_profile (listing_id, product_title, barcodes) VALUES (?, 'One bottle', '[\"5000000000024\", \"5000000000017\"]')", [$one]);
        $s = new ItemSuggestions(self::$db);
        foreach (['5000000000017', '5000000000024', '5000000000031'] as $code) {
            self::assertSame([], $s->suggest($site->channelId, ['barcodes' => [$code]])['suggestions'], $code);
        }
        // Codex review r1 (a3): the database narrows the brand as Normalizer::brandTokens() reads it ("R&M" is "randm"; the item has no range
        // to match instead).
        $rm = $this->carded('Blue Razz Ice R&M Disposable', ['brand' => 'R&M', 'flavour' => 'blue razz ice', 'strength_mg' => '20.00', 'volume_ml' => null,
            'form' => 'disposable', 'nic_type' => null, 'line' => null]);
        $in = ['title' => 'R and M Disposable Vape', 'option' => 'Blue Razz Ice', 'brand' => 'R and M',
            'attrs' => [['name' => 'Flavour', 'value' => 'Blue Razz Ice'], ['name' => 'Nicotine Strength', 'value' => '20mg']]];
        $card = ItemSuggestions::card(ItemSuggestions::profile($in));
        self::assertSame(['randm'], $card['family']);
        self::assertTrue(ItemSuggestions::sameDetails($card, self::$db->one('SELECT * FROM sku WHERE id = ?', [$rm])), 'the same details');
        self::assertSame([$rm], $s->sameDetailsItems($card, 5), 'and the database finds it');
        self::assertSame([$this->code($rm), 'same_details'], [$s->suggest($site->channelId, $in)['suggestions'][0]['code'], $s->suggest($site->channelId, $in)['suggestions'][0]['why']]);
    }

    public function testNothingToGoOnFindsNothing(): void
    {
        $site = $this->site('vapebig');
        $this->carded('Elux Legend Nic Salt - Lemon & Lime - 10ml - 5mg', self::legend());
        $s = new ItemSuggestions(self::$db);
        self::assertSame(['suggestions' => [], 'strong' => false], $s->suggest($site->channelId, ['title' => 'Something Unheard Of 30ml', 'brand' => 'Nobody']));
        self::assertSame(['suggestions' => [], 'strong' => false], $s->suggest($site->channelId, []));
        self::assertSame(['suggestions' => [], 'strong' => false], $s->suggest($site->channelId, ['title' => '%', 'option' => '_']), 'LIKE signs are words, not patterns');
    }
}
