<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Items;

use CW\Items\Families;
use CW\Tests\Support\MappingTestCase;

/**
 * Product families (pack B; owner answer Q4; docs/decisions.md PB4-PB6) through the service: a family is created, renamed, switched off
 * and on (never deleted), its name unique among the families switched on; one item is put in, moved and taken out (the row stays), never
 * moved unseen; a pasted list is put in a family in one go with the doubtful lines skipped and listed (the owner's rule), at most the
 * setting's number; ticked items are taken out; who may change families. The stock invariants (B1-B3 included) hold after every test.
 */
final class FamiliesTest extends MappingTestCase
{
    private Families $fam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fam = new Families(self::$db);
    }

    public function testAFamilyIsCreatedRenamedSwitchedOffAndOnAndNeverDeleted(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $f = $this->fam->create($lead, '  Elux   Legend 3500  ', 'every flavour of the Legend');
        self::assertSame('Elux Legend 3500', $f['name'], 'spaces made single');
        $row = $this->fam->get($f['id']);
        self::assertSame([1, 1, 0, 'every flavour of the Legend'], [(int) $row['is_active'], (int) $row['version'], $row['items'], $row['note']]);

        $r = $this->fam->rename($lead, $f['id'], 1, 'Elux Legend');
        self::assertSame([2, true], [$r['version'], $r['changed']]);
        self::assertSame(['version' => 2, 'changed' => false], array_intersect_key($this->fam->rename($lead, $f['id'], 2, 'Elux Legend'), ['version' => 1, 'changed' => 1]));
        self::refusedWith(409, 'family_changed', fn () => $this->fam->rename($lead, $f['id'], 1, 'Old form'));

        $off = $this->fam->setActive($lead, $f['id'], 2, false);
        self::assertSame([3, true], [$off['version'], $off['changed']]);
        self::assertSame(0, (int) self::$db->value('SELECT is_active FROM item_family WHERE id = ?', [$f['id']]));
        self::assertSame([], $this->fam->list('', false, 1)['rows'], 'a switched-off family is left out of the list');
        self::assertSame(['Elux Legend'], array_column($this->fam->list('legend', true, 1)['rows'], 'name'), '...unless asked for');
        self::assertSame(true, $this->fam->setActive($lead, $f['id'], 3, true)['changed']);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM item_family'), 'never deleted');
        self::assertSame(['family.create', 'family.rename', 'family.switch', 'family.switch'],
            self::$db->column("SELECT action FROM audit_log WHERE entity_type = 'item_family' ORDER BY id"));
        self::refusedWith(422, 'bad_name', fn () => $this->fam->create($lead, 'x'));
        self::refusedWith(404, 'unknown_family', fn () => $this->fam->rename($lead, 999999, 1, 'Nothing'));
    }

    public function testANameIsUniqueAmongTheFamiliesSwitchedOnIgnoringCase(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $a = $this->fam->create($lead, 'Nasty Juice');
        $e = self::refusedWith(409, 'family_exists', fn () => $this->fam->create($lead, 'nasty juice'));
        self::assertSame($a['id'], $e->detail['family_id']);
        $b = $this->fam->create($lead, 'Nasty Salt');
        self::refusedWith(409, 'family_exists', fn () => $this->fam->rename($lead, $b['id'], 1, 'NASTY JUICE'));
        // A switched-off family frees its name; switching it on again needs the name free.
        $this->fam->setActive($lead, $a['id'], 1, false);
        $c = $this->fam->create($lead, 'Nasty Juice');
        self::refusedWith(409, 'family_exists', fn () => $this->fam->setActive($lead, $a['id'], 2, true));
        self::assertSame($c['id'], $this->fam->activeByName('NASTY JUICE')['id'] ?? null);
    }

    public function testOneItemIsPutInMovedAndTakenOutNeverUnseenAndItsRowStays(): void
    {
        $buyer = $this->staffUser('buyer');
        $a = $this->fam->create($buyer, 'Family A')['id'];
        $b = $this->fam->create($buyer, 'Family B')['id'];
        $sku = $this->item('legacy', 0, 'Item one');

        self::assertSame(['sku_id' => $sku, 'from' => null, 'to' => $a, 'changed' => true], $this->fam->assign($buyer, $sku, $a, null));
        self::assertSame(['id' => $a, 'name' => 'Family A', 'active' => true], Families::ofItems(self::$db, [$sku])[$sku]);
        self::refusedWith(409, 'family_moved', fn () => $this->fam->assign($buyer, $sku, $b, null), 'the form showed no family: it is in A now');
        self::assertSame(['sku_id' => $sku, 'from' => $a, 'to' => $b, 'changed' => true], $this->fam->assign($buyer, $sku, $b, $a));
        self::assertFalse($this->fam->assign($buyer, $sku, $b, $b)['changed'], 'the same family again writes nothing');
        self::assertSame(['sku_id' => $sku, 'from' => $b, 'to' => null, 'changed' => true], $this->fam->assign($buyer, $sku, null, $b));
        self::assertSame([], Families::ofItems(self::$db, [$sku]));
        self::assertSame([[$sku, null, 3]], array_map(static fn (array $r): array => [(int) $r['sku_id'], $r['family_id'], (int) $r['version']],
            self::$db->all('SELECT sku_id, family_id, version FROM item_family_member')), 'taken out: the row stays');

        // A switched-off family takes no new item; a joined item goes nowhere; unknown items and families are named.
        $this->fam->setActive($buyer, $a, 1, false);
        self::refusedWith(422, 'family_off', fn () => $this->fam->assign($buyer, $sku, $a, null));
        $merged = $this->item('legacy', 0, 'Joined away');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$sku, $merged]);
        self::refusedWith(409, 'merged_item', fn () => $this->fam->assign($buyer, $merged, $b, null));
        self::refusedWith(404, 'unknown_item', fn () => $this->fam->assign($buyer, 999999, $b, null));
        self::refusedWith(404, 'unknown_family', fn () => $this->fam->assign($buyer, $sku, 999999, null));
        self::assertSame(['family.assign', 'family.assign', 'family.unassign'],
            self::$db->column("SELECT action FROM audit_log WHERE entity_type = 'sku' AND entity_id = ? ORDER BY id", [(string) $sku]));
    }

    public function testABulkListIsPutInAFamilyWithTheDoubtfulLinesSkippedAndListed(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $fam = $this->fam->create($lead, 'Pod Salts')['id'];
        $other = $this->fam->create($lead, 'Other Salts')['id'];
        [$one, $two, $three, $here, $there, $joined] = [$this->item('legacy', 0, 'One'), $this->item('legacy', 0, 'Two'), $this->item('legacy', 0, 'Three'),
            $this->item('legacy', 0, 'Here'), $this->item('legacy', 0, 'There'), $this->item('legacy', 0, 'Joined')];
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, source) VALUES ('5012345678900', ?, 'manual')", [$three]);
        $this->fam->assign($lead, $here, $fam, null);
        $this->fam->assign($lead, $there, $other, null);
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$one, $joined]);
        $code = static fn (int $id): string => sprintf('CW-%06d', $id);

        $r = $this->fam->bulkAssign($lead, $fam, implode("\n", [$code($one), (string) $two, '5012345678900', $code($one), $code($here), $code($there), $code($joined),
            'CW-999999', 'nonsense']));
        self::assertSame([$one, $two, $three], array_column($r['added'], 'sku_id'), 'the sure ones are done');
        self::assertSame([[$code($one), 'repeated'], [$code($here), 'already_here'], [$code($there), 'in_other_family'], [$code($joined), 'merged'],
            ['CW-999999', 'not_found'], ['nonsense', 'not_found']],
            array_map(static fn (array $s): array => [$s['text'], $s['reason']], $r['skipped']), 'the doubtful ones are listed with their reason');
        self::assertSame('Other Salts', $r['skipped'][2]['other'], 'the other family is named');
        $of = Families::ofItems(self::$db, [$one, $two, $three, $here, $there]);
        self::assertSame([$fam, $fam, $fam, $fam, $other], [$of[$one]['id'], $of[$two]['id'], $of[$three]['id'], $of[$here]['id'], $of[$there]['id']]);
        self::assertSame(4, $this->fam->get($fam)['items']);
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'family.bulk_assign'"), true);
        self::assertSame([$one, $two, $three], $audit['added']);
        self::assertCount(6, $audit['skipped']);

        // A switched-off family takes none; an empty list is refused; nothing is saved by a refusal.
        $this->fam->setActive($lead, $other, 1, false);
        self::refusedWith(422, 'family_off', fn () => $this->fam->bulkAssign($lead, $other, $code($one)));
        self::refusedWith(422, 'empty_list', fn () => $this->fam->bulkAssign($lead, $fam, " \n , "));
    }

    public function testTheBulkLimitIsTheSettingAndNothingIsSavedAboveIt(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $fam = $this->fam->create($lead, 'Limited')['id'];
        $ids = [$this->item('legacy'), $this->item('legacy'), $this->item('legacy')];
        $before = self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'items.family_bulk_max_rows'");
        self::assertSame('200', $before, 'the baseline of 0031');
        self::$db->exec("UPDATE app_setting SET value_json = '2' WHERE setting_key = 'items.family_bulk_max_rows'");
        try {
            $e = self::refusedWith(422, 'too_many_rows', fn () => $this->fam->bulkAssign($lead, $fam, implode(' ', array_map('strval', $ids))));
            self::assertSame(['max' => 2, 'given' => 3], $e->detail);
            self::assertSame(0, $this->fam->get($fam)['items'], 'nothing saved');
            self::assertCount(2, $this->fam->bulkAssign($lead, $fam, $ids[0] . ',' . $ids[1])['added']);
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = ? WHERE setting_key = 'items.family_bulk_max_rows'", [$before]);
        }
    }

    public function testTickedItemsAreTakenOutWithTheOnesNoLongerThereSkipped(): void
    {
        $sc = $this->staffUser('stock_controller');
        $fam = $this->fam->create($sc, 'Coils')['id'];
        $other = $this->fam->create($sc, 'Tanks')['id'];
        [$a, $b, $c] = [$this->item('legacy'), $this->item('legacy'), $this->item('legacy')];
        $this->fam->bulkAssign($sc, $fam, "{$a} {$b} {$c}");
        $this->fam->assign($sc, $c, $other, $fam); // moved meanwhile
        $r = $this->fam->bulkRemove($sc, $fam, [$a, $b, $c]);
        self::assertSame([$a, $b], array_column($r['removed'], 'sku_id'));
        self::assertSame([[$c, 'not_in_family']], array_map(static fn (array $s): array => [$s['sku_id'], $s['reason']], $r['skipped']));
        self::assertSame(0, $this->fam->get($fam)['items']);
        self::assertSame($other, Families::ofItems(self::$db, [$c])[$c]['id']);
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM item_family_member'), 'the rows stay');
    }

    public function testWhoMayChangeFamilies(): void
    {
        foreach (['mapping_lead', 'stock_controller', 'purchasing_manager', 'buyer'] as $role) {
            self::assertIsInt($this->fam->create($this->staffUser($role), "By {$role}")['id'], $role);
        }
        foreach (['viewer', 'mapper', 'goods_in', 'reviewer', 'auditor'] as $role) {
            self::refusedWith(403, 'role_not_allowed', fn () => $this->fam->create($this->staffUser($role), "By {$role}"), $role);
        }
        self::refusedWith(403, 'admin_cannot_edit', fn () => $this->fam->create($this->staffUser('admin'), 'By admin'));
        self::refusedWith(403, 'staff_required', fn () => $this->fam->create(\CW\Caller::system('test'), 'By the system'));
    }

    private static function refusedWith(int $status, string $code, callable $fn, string $why = ''): \CW\CwException
    {
        try {
            $fn();
        } catch (\CW\CwException $e) {
            self::assertSame([$status, $code], [$e->httpStatus, $e->errorCode], $why . ' ' . $e->getMessage());
            return $e;
        }
        self::fail("expected {$status} {$code} {$why}");
    }
}
