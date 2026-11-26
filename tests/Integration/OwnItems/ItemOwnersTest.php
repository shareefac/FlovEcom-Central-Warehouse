<?php

declare(strict_types=1);

namespace CW\Tests\Integration\OwnItems;

use CW\Caller;
use CW\CwException;
use CW\Items\ItemOwnerInvariants;
use CW\Items\ItemOwners;
use CW\Settings;
use CW\Tests\Support\MappingTestCase;

/**
 * CW-1153 B1 (docs/decisions.md VB1-VB6): a shop's own items. The list has a history by PERIOD (add, remove, add again: three moments,
 * two periods, nothing rewritten); only an admin or a reviewer changes it (a system tool may start a period in the past, a person never);
 * an item another shop sells cannot be added; the setting names the shop and moves only while no other shop's item is marked; Vape and
 * Go / Electrofag can never link a marked item, and a marked item is never merged or split; the nightly check reports a breach.
 */
final class ItemOwnersTest extends MappingTestCase
{
    private const AT = '2026-11-26 10:00:00.000000';
    private Caller $vb;
    private Caller $vpg;
    private \DateTimeImmutable $clockAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vb = $this->site('vapebig');
        $this->vpg = $this->site('vapeandgo');
        $this->clockAt = new \DateTimeImmutable(self::AT, new \DateTimeZone('UTC'));
    }

    private function owners(): ItemOwners
    {
        return new ItemOwners(self::$db, fn (): \DateTimeImmutable => $this->clockAt);
    }

    private function later(string $modify): void
    {
        $this->clockAt = $this->clockAt->modify($modify);
    }

    private static function code(int $sku): string
    {
        return (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
    }

    private static function refusedWith(string $code, callable $fn): CwException
    {
        try {
            $fn();
        } catch (CwException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
            return $e;
        }
        self::fail("expected {$code}, nothing was thrown");
    }

    public function testAddRemoveAndAddAgainKeepEveryPeriodAndAreAudited(): void
    {
        $admin = $this->staffUser('admin');
        $sku = $this->item('legacy', 5, 'Hayati 4000 Blue Razz');
        $this->listing($this->vb, 'VB-1', $sku);
        $a = $this->owners()->add($admin, self::code($sku), 'bought with Vape Big\'s own money');
        self::assertSame(self::AT, $a['from_at']);
        self::assertSame([$sku => (int) $this->vb->channelId], ItemOwners::open(self::$db, [$sku]));
        $this->later('+1 hour');
        $r = $this->owners()->remove($admin, self::code($sku), 'ours again from today');
        self::assertSame('2026-11-26 11:00:00.000000', $r['to_at']);
        self::assertSame([], ItemOwners::open(self::$db, [$sku]));
        $this->later('+1 hour');
        $this->owners()->add($admin, self::code($sku), 'Vape Big bought it back');
        $periods = ItemOwners::periods(self::$db, [$sku])[$sku];
        self::assertCount(2, $periods);
        self::assertSame([(int) $this->vb->channelId, self::AT, '2026-11-26 11:00:00.000000'], $periods[0]);
        self::assertNull($periods[1][2]);
        // the moment decides: a sale paid at 10:30 was Vape Big's own, one at 11:30 ours, one at 12:30 Vape Big's own again
        self::assertSame((int) $this->vb->channelId, ItemOwners::ownerAt([$sku => $periods], $sku, '2026-11-26 10:30:00.000000'));
        self::assertNull(ItemOwners::ownerAt([$sku => $periods], $sku, '2026-11-26 11:30:00.000000'));
        self::assertSame((int) $this->vb->channelId, ItemOwners::ownerAt([$sku => $periods], $sku, '2026-11-26 12:30:00.000000'));
        self::assertSame(['item_owner.add', 'item_owner.remove', 'item_owner.add'],
            self::$db->column("SELECT action FROM audit_log WHERE action LIKE 'item_owner.%' ORDER BY id"));
        self::assertCount(2, $this->owners()->history());
        self::assertSame([], ItemOwnerInvariants::check(self::$db));
    }

    public function testOnlyAnAdminOrAReviewerChangesTheListAndOnlyTheToolStartsAPeriodInThePast(): void
    {
        $sku = $this->item('legacy', 0, 'Elux Legend 4000 Mango');
        self::refusedWith('role_not_allowed', fn () => $this->owners()->add($this->staffUser('mapper'), self::code($sku), 'trying'));
        self::refusedWith('backdate_system_only', fn () => $this->owners()->add($this->staffUser('reviewer'), self::code($sku), 'the count', '2026-11-16 09:00:00'));
        $tool = Caller::system('own_items_kit');
        self::refusedWith('bad_from', fn () => $this->owners()->add($tool, self::code($sku), 'the count', '2026-11-27 09:00:00'));
        $a = $this->owners()->add($tool, self::code($sku), 'the count that brought it in', '2026-11-16 09:00:00');
        self::assertSame('2026-11-16 09:00:00.000000', $a['from_at']);
        self::refusedWith('own_item_already', fn () => $this->owners()->add($tool, self::code($sku), 'again'));
        $this->owners()->remove($this->staffUser('reviewer'), self::code($sku), 'ours again');
        self::refusedWith('own_item_overlap', fn () => $this->owners()->add($tool, self::code($sku), 'before its end', '2026-11-20 09:00:00'));
        self::refusedWith('unknown_item', fn () => $this->owners()->add($tool, 'CW-999999', 'no such item'));
        self::refusedWith('bad_reason', fn () => $this->owners()->add($tool, self::code($sku), 'x'));
        self::refusedWith('not_own_item', fn () => $this->owners()->remove($tool, self::code($sku), 'not on the list'));
    }

    public function testAnItemAnotherShopSellsCannotBeAddedAndTheSettingMovesOnlyWhileNoItemOfTheShopIsMarked(): void
    {
        $tool = Caller::system('own_items_kit');
        $shared = $this->item('legacy', 0, 'Shared liquid');
        $this->listing($this->vb, 'VB-S', $shared);
        $this->listing($this->vpg, 'VPG-S', $shared);
        $e = self::refusedWith('own_item_other_shop', fn () => $this->owners()->add($tool, self::code($shared), 'not ours'));
        self::assertSame(['VAPEANDGO test site'], $e->detail['shops']);
        $only = $this->item('legacy', 0, 'Hayati 4000 Lime');
        $this->owners()->add($tool, self::code($only), 'Vape Big\'s own');
        $settings = new Settings(self::$db);
        $e = self::refusedWith('bad_value', fn () => $settings->set($this->staffUser('admin'), ItemOwners::SETTING, 'nosuchshop', 'a typo'));
        self::assertStringContainsString('the code of a shop', $e->getMessage());
        self::makeChannel('electrofag');
        $e = self::refusedWith('bad_value', fn () => $settings->set($this->staffUser('admin'), ItemOwners::SETTING, 'electrofag', 'another shop'));
        self::assertStringContainsString('remove them', $e->getMessage());
        $this->later('+1 minute');
        $this->owners()->remove($tool, self::code($only), 'ours again');
        $settings->set($this->staffUser('admin'), ItemOwners::SETTING, 'electrofag', 'another shop');
        self::assertSame('electrofag', ItemOwners::shop(self::$db)['code'] ?? null);
        $settings->set($this->staffUser('admin'), ItemOwners::SETTING, 'vapebig', 'back');
    }

    public function testVapeAndGoCannotLinkAMarkedItemAndAMarkedItemIsNeverJoinedOrSplit(): void
    {
        $hayati = $this->item('legacy', 5, 'Hayati 4000 Melon');
        $dup = $this->item('legacy', 5, 'Melon duplicate');
        $this->listing($this->vb, 'VB-H', $hayati);
        $this->owners()->add(Caller::system('own_items_kit'), self::code($hayati), 'Vape Big\'s own');
        $lead = $this->staffUser('mapping_lead');
        $vpgNew = $this->listing($this->vpg, 'VPG-NEW', null);
        $e = self::refused(422, 'own_item_other_shop', fn () => $this->decide($lead, 'link', $vpgNew, ['sku_id' => $hayati, 'reason' => 'same barcode']));
        self::assertStringContainsString('only VAPEBIG test site sells it', $e->getMessage());
        $vbNew = $this->listing($this->vb, 'VB-NEW', null);
        $this->decide($lead, 'link', $vbNew, ['sku_id' => $hayati, 'reason' => 'same product, other size']);
        self::assertSame($hayati, (int) self::$db->value('SELECT sku_id FROM channel_listing WHERE id = ?', [$vbNew]));
        $o = $this->listing($this->vb, 'VB-DUP', $dup);
        self::refused(409, 'own_item_merge', fn () => $this->decide($lead, 'merge_skus', $o, ['sku_id' => $hayati, 'merge_from_sku_id' => $dup]));
        self::refused(409, 'own_item_merge', fn () => $this->decide($lead, 'merge_skus', $o, ['sku_id' => $dup, 'merge_from_sku_id' => $hayati]));
    }

    public function testTheNightlyCheckReportsAMarkedItemLinkedOnAnotherShop(): void
    {
        $hayati = $this->item('legacy', 0, 'Hayati 4000 Peach');
        $this->owners()->add(Caller::system('own_items_kit'), self::code($hayati), 'Vape Big\'s own');
        self::assertSame([], ItemOwnerInvariants::check(self::$db));
        // behind the link guard's back (a direct write)
        $this->listing($this->vpg, 'VPG-H', $hayati);
        $v = ItemOwnerInvariants::check(self::$db);
        self::assertCount(1, $v);
        self::assertStringStartsWith('O1: ' . self::code($hayati), $v[0]);
        self::$db->exec("UPDATE channel_listing SET status = 'unmapped', sku_id = NULL WHERE channel_id = ?", [$this->vpg->channelId]);
    }
}
