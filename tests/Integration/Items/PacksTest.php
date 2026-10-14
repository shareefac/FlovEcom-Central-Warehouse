<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Items;

use CW\Items\Packs;
use CW\Tests\Support\MappingTestCase;

/**
 * Multipacks (pack B; owner answer Q3; docs/decisions.md PB6-PB8) through the service: a pack item is defined as N x a single item,
 * switched off and on (never changed or deleted) under its rules; what the screens compare it with (the pack links: variants matched
 * with more than 1 unit a sale); and the owner's rule that a pack is suggested as N singles only while the single is sold separately,
 * a setting. Nothing here relinks a variant or moves stock.
 */
final class PacksTest extends MappingTestCase
{
    private Packs $packs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packs = new Packs(self::$db);
    }

    public function testAPackItemIsDefinedSwitchedOffAndOnUnderItsRules(): void
    {
        $lead = $this->staffUser('mapping_lead');
        [$box, $shot, $other] = [$this->item('legacy', 0, 'Nic shot 10 x 10ml'), $this->item('legacy', 0, 'Nic shot 10ml'), $this->item('legacy', 0, 'Other')];
        $id = $this->packs->create($lead, $box, $shot, 10, 'sealed box of ten')['id'];
        $p = $this->packs->get($id);
        self::assertSame([$box, $shot, 10, 1], [(int) $p['pack_sku_id'], (int) $p['single_sku_id'], (int) $p['units'], (int) $p['is_active']]);
        self::assertSame($id, (int) $this->packs->activeOf($box)['id']);

        self::refused(422, 'same_item', fn () => $this->packs->create($lead, $box, $box, 10));
        self::refused(422, 'bad_units', fn () => $this->packs->create($lead, $other, $shot, 1));
        self::refused(422, 'bad_units', fn () => $this->packs->create($lead, $other, $shot, 1001));
        self::refused(409, 'pack_exists', fn () => $this->packs->create($lead, $box, $other, 5), 'one definition switched on per pack item');
        self::refused(409, 'pack_nested', fn () => $this->packs->create($lead, $other, $box, 2), 'a pack of packs');
        self::refused(409, 'pack_nested', fn () => $this->packs->create($lead, $shot, $other, 2), 'the single of a pack is not itself a pack');
        self::refused(404, 'unknown_item', fn () => $this->packs->create($lead, 999999, $shot, 2));
        $joined = $this->item('legacy', 0, 'Joined');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$other, $joined]);
        self::refused(409, 'merged_item', fn () => $this->packs->create($lead, $joined, $shot, 2));

        // Switched off: kept, and a new definition of the same pack item may be made; the old one cannot come back on beside it.
        self::assertTrue($this->packs->setActive($lead, $id, false)['changed']);
        self::assertFalse($this->packs->setActive($lead, $id, false)['changed']);
        self::assertNull($this->packs->activeOf($box));
        $five = $this->packs->create($lead, $box, $shot, 5)['id'];
        self::refused(409, 'pack_exists', fn () => $this->packs->setActive($lead, $id, true));
        $this->packs->setActive($lead, $five, false);
        self::assertTrue($this->packs->setActive($lead, $id, true)['changed']);
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM item_pack'), 'never deleted');
        self::assertSame(['pack.create', 'pack.switch', 'pack.create', 'pack.switch', 'pack.switch'],
            self::$db->column("SELECT action FROM audit_log WHERE entity_type = 'item_pack' ORDER BY id"));

        // Who: the catalogue team (catalogue.edit), never admin.
        self::refused(403, 'role_not_allowed', fn () => $this->packs->create($this->staffUser('buyer'), $other, $shot, 3));
        self::refused(403, 'admin_cannot_edit', fn () => $this->packs->create($this->staffUser('admin'), $other, $shot, 3));
        self::assertIsInt($this->packs->create($this->staffUser('stock_controller'), $other, $shot, 3)['id']);
    }

    public function testTheFlagsComparePackItemsWithThePackLinks(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $site = $this->site('vapeandgo', 'off');
        [$box, $shot] = [$this->item('legacy', 4, 'Box 10 x 10ml'), $this->item('legacy', 30, 'Shot 10ml')];
        self::$db->exec('UPDATE sku SET pack_units = 20 WHERE id = ?', [$box]);
        self::$db->exec('UPDATE sku SET pack_units = 1 WHERE id = ?', [$shot]);
        $id = $this->packs->create($lead, $box, $shot, 10)['id'];
        $row = $this->packs->list(false, '', 1)['rows'][0];
        self::assertSame(['count_differs'], $row['flags'], 'the box\'s card says 20, not 10 x 1');
        self::assertSame(['pack' => 4, 'single' => 30], $row['on_hand']);

        $this->listing($site, 'S1', $shot);          // the single is sold separately
        $this->listing($site, 'S10', $shot, 10);     // ... and as a pack link of 10: the same pack sold two ways
        $this->listing($site, 'B2', $box, 2);        // a pack of packs
        $row = $this->packs->list(false, '', 1)['rows'][0];
        self::assertSame(['single_sold', 'sold_both_ways', 'pack_of_packs', 'count_differs'], $row['flags']);
        self::assertSame(['pack_u1' => 0, 'pack_multi' => 1, 'single_u1' => 1, 'single_un' => 1], $row['links']);
        self::assertSame($id, (int) $this->packs->ofItem($shot)['packs'][0]['id']);
        self::assertSame($id, (int) $this->packs->ofItem($box)['pack']['id']);
        self::assertSame([], $this->packs->list(false, 'nothing like it', 1)['rows']);
        self::assertSame($id, (int) $this->packs->list(false, sprintf('CW-%06d', $shot), 1)['rows'][0]['id'], 'found by either item\'s CW number');

        // The pack links: every variant with more than 1 unit a sale, and what the pack items say of it.
        $links = $this->packs->links(1);
        self::assertSame(2, $links['total']);
        self::assertSame([['B2', 2, ['item_is_pack']], ['S10', 10, ['pack_item_exists']]],
            array_map(static fn (array $r): array => [$r['external_variant_id'], (int) $r['units_per_item'], $r['flags']], $links['rows']), 'by units, then oldest first');
        self::assertSame([null, $box], [$links['rows'][0]['pack_item'], $links['rows'][1]['pack_item']['id']]);
    }

    public function testAPackIsSuggestedAsSinglesOnlyWhileTheSingleIsSoldSeparatelyTheSettingDecides(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $site = $this->site('vapeandgo', 'off');
        [$box, $shot, $coils, $coil] = [$this->item('legacy', 0, 'Box'), $this->item('legacy', 0, 'Shot'), $this->item('legacy', 0, 'Coils 5 pack'),
            $this->item('legacy', 0, 'Coil')];
        $boxPack = $this->packs->create($lead, $box, $shot, 10)['id'];
        $coilPack = $this->packs->create($lead, $coils, $coil, 5)['id'];
        $boxVariant = $this->listing($site, 'BOX', $box);
        $this->listing($site, 'COILS', $coils);
        $this->listing($site, 'SHOT', $shot); // the shot is sold on its own; the single coil is not (Q3: the maker's pack, its own item)
        $rows = array_column($this->packs->list(false, '', 1)['rows'], null, 'id');
        self::assertContains('suggest', $rows[$boxPack]['flags']);
        self::assertSame([$boxVariant], array_map(static fn (array $l): int => (int) $l['id'], $rows[$boxPack]['suggest_listings']));
        self::assertNotContains('suggest', $rows[$coilPack]['flags'], 'its own product, 1 unit: nothing suggested');
        self::assertSame([], $rows[$coilPack]['suggest_listings']);

        $before = self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'items.pack_suggest_single_sold'");
        self::assertSame('true', $before, 'on by default (the owner\'s answer)');
        self::$db->exec("UPDATE app_setting SET value_json = 'false' WHERE setting_key = 'items.pack_suggest_single_sold'");
        try {
            $rows = array_column($this->packs->list(false, '', 1)['rows'], null, 'id');
            self::assertContains('suggest', $rows[$coilPack]['flags'], 'the rule switched off: every pack with variants is suggested');
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = ? WHERE setting_key = 'items.pack_suggest_single_sold'", [$before]);
        }
        // Nothing was relinked: a suggestion is a link to the variant's page.
        self::assertSame(['sku_id' => $box, 'units_per_item' => 1, 'status' => 'mapped', 'map_version' => 0], $this->link($boxVariant));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM match_decision'));
    }
}
