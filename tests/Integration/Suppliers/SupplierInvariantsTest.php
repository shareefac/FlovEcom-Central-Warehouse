<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Suppliers;

use CW\Invariants;
use CW\Suppliers\SupplierInvariants;

/**
 * S1-S5 (I42): each check catches a violation planted with admin SQL, and is quiet on what the services write. Every
 * test removes what it planted, so the post-condition (Invariants::check, which now includes S1-S5) holds again.
 */
final class SupplierInvariantsTest extends SupplierTestCase
{
    /** @return list<string> */
    private static function found(string $needle): array
    {
        return array_values(array_filter(SupplierInvariants::check(self::$db), static fn (string $v): bool => str_contains($v, $needle)));
    }

    public function testQuietOnWhatTheServicesWrite(): void
    {
        $buyer = $this->staffUser('buyer');
        $a = $this->activeSupplier($buyer, ['is_overseas' => '1', 'import_route' => 'Stamped in Kent']);
        $this->sup->update($buyer, (int) $a['id'], (int) $a['version'], ['import_route' => 'Stamped in Essex', 'email' => 'x@a.example']);
        $d = $this->draft($buyer, ['name' => 'Pending one']);
        $this->sup->requestActivation($buyer, (int) $d['id'], (int) $d['version']);
        $i = $this->items->create($buyer, (int) $a['id'], self::makeSku('Item'), ['is_preferred' => '1']);
        $this->items->recordPrice($buyer, (int) $i['id'], '2.50', self::day('-3 days'), null);
        $this->items->recordPrice($buyer, (int) $i['id'], '2.40', self::day('-9 days'), null);
        self::assertSame([], SupplierInvariants::check(self::$db));
        self::assertSame([], Invariants::check(self::$db));
    }

    public function testS1PendingIffOneOpenActivationAndOpenTasksOnExistingSuppliers(): void
    {
        $buyer = $this->staffUser('buyer');
        $d = $this->draft($buyer);
        self::$db->exec("UPDATE supplier SET status = 'pending_approval' WHERE id = ?", [(int) $d['id']]);
        self::assertCount(1, self::found("supplier {$d['id']} ({$d['code']}) is pending_approval but has 0 open activation tasks"));
        self::$db->exec("UPDATE supplier SET status = 'draft' WHERE id = ?", [(int) $d['id']]);
        $t = self::$db->insert("INSERT INTO review_task (subject_type, subject_id, kind, reason, opened_by, opened_actor, due_at) VALUES ('supplier', ?, 'approval', "
            . "'new_supplier', ?, 'staff:x', NOW(6))", [(int) $d['id'], $buyer->staffUserId]);
        self::assertCount(1, self::found("supplier {$d['id']} ({$d['code']}) is draft but has 1 open activation tasks"));
        self::$db->exec("UPDATE review_task SET reason = 'import_route' WHERE id = ?", [$t]);
        self::assertCount(1, self::found("open supplier task {$t} (approval, import_route) on supplier {$d['id']}, which is draft"));
        self::$db->exec("UPDATE review_task SET kind = 'review', reason = 'supplier_changed' WHERE id = ?", [$t]);
        self::assertCount(1, self::found("open supplier task {$t} (review, supplier_changed) on supplier {$d['id']}, which is draft"));
        self::$db->exec('UPDATE review_task SET subject_id = 999999 WHERE id = ?', [$t]);
        self::assertCount(1, self::found("open supplier task {$t} names supplier 999999, which does not exist"));
        self::$db->exec('DELETE FROM review_task WHERE id = ?', [$t]);
        self::assertSame([], SupplierInvariants::check(self::$db));
    }

    public function testS2AnActiveSupplierWasApprovedByASecondPerson(): void
    {
        $buyer = $this->staffUser('buyer');
        $a = $this->activeSupplier($buyer);
        $id = (int) $a['id'];
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ?", [$id]);
        self::$db->exec('UPDATE supplier SET approved_by = NULL WHERE id = ?', [$id]);
        self::assertCount(1, self::found("active supplier {$id} ({$a['code']}) has no approved_by/approved_at"));
        self::$db->exec('UPDATE supplier SET approved_by = ? WHERE id = ?', [$buyer->staffUserId, $id]);
        self::assertCount(1, self::found('not a second person or not the approver on the row'), 'approved_by is not the task\'s decider');
        self::$db->exec('UPDATE supplier SET approved_by = ? WHERE id = ?', [(int) $a['approved_by'], $id]);
        self::$db->exec("UPDATE review_task SET state = 'rejected' WHERE id = ?", [$task]);
        self::assertCount(1, self::found("active supplier {$id} ({$a['code']}) was last rejected at activation (task {$task})"));
        self::$db->exec("UPDATE review_task SET state = 'withdrawn', decided_by = NULL WHERE id = ?", [$task]);
        self::assertCount(1, self::found("active supplier {$id} ({$a['code']}) has no decided activation task"));
        self::$db->exec("UPDATE review_task SET state = 'approved', decided_by = ? WHERE id = ?", [(int) $a['approved_by'], $task]);
        self::assertSame([], SupplierInvariants::check(self::$db));
    }

    public function testS3AnApprovedImportRouteHasItsApproval(): void
    {
        $buyer = $this->staffUser('buyer');
        $a = $this->activeSupplier($buyer, ['is_overseas' => '1', 'import_route' => 'Stamped in Kent']);
        $id = (int) $a['id'];
        self::$db->exec('UPDATE supplier SET import_route_approved_at = DATE_SUB(approved_at, INTERVAL 1 DAY) WHERE id = ?', [$id]);
        self::assertCount(1, self::found("overseas supplier {$id} ({$a['code']}) has its import route approved at"));
        self::$db->exec('UPDATE supplier SET import_route_approved_at = approved_at WHERE id = ?', [$id]);
        self::assertSame([], SupplierInvariants::check(self::$db));
    }

    public function testS4TheLastPriceMirrorsTheNewestNonPoPrice(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->draft($buyer);
        $i = $this->items->create($buyer, (int) $s['id'], self::makeSku('Item'), []);
        $id = (int) $i['id'];
        self::$db->exec("UPDATE supplier_item SET last_pack_price = 1, last_price_on = CURRENT_DATE(), last_price_source = 'manual' WHERE id = ?", [$id]);
        self::assertCount(1, self::found("supplier item {$id}: last price 1.0000 on"), 'a last price without history');
        self::$db->exec('UPDATE supplier_item SET last_pack_price = NULL, last_price_on = NULL, last_price_source = NULL WHERE id = ?', [$id]);
        $this->items->recordPrice($buyer, $id, '3.00', self::day('-2 days'), null);
        self::$db->insert("INSERT INTO supplier_item_price (supplier_item_id, pack_price, units_per_pack, unit_price, source, effective_on, recorded_actor) "
            . "VALUES (?, 9, 1, 9, 'po', CURRENT_DATE(), 'system:test')", [$id]);
        self::assertSame([], SupplierInvariants::check(self::$db), 'a PO price never moves the last price');
        self::$db->insert("INSERT INTO supplier_item_price (supplier_item_id, pack_price, units_per_pack, unit_price, source, effective_on, recorded_actor) "
            . "VALUES (?, 4, 1, 4, 'invoice', CURRENT_DATE(), 'system:test')", [$id]);
        self::assertCount(1, self::found("supplier item {$id}: last price 3.0000"), 'a newer invoice price the row does not show');
        self::$db->exec("UPDATE supplier_item SET last_pack_price = 4, last_price_on = CURRENT_DATE(), last_price_source = 'invoice' WHERE id = ?", [$id]);
        self::assertSame([], SupplierInvariants::check(self::$db));
        self::$db->exec("UPDATE supplier_item SET last_price_source = 'manual' WHERE id = ?", [$id]);
        self::assertCount(1, self::found("supplier item {$id}"));
        self::$db->exec("UPDATE supplier_item SET last_price_source = 'invoice' WHERE id = ?", [$id]);
    }

    public function testS5SupplierItemsNameExistingItems(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->draft($buyer);
        $sku = self::makeSku('Item');
        $i = $this->items->create($buyer, (int) $s['id'], $sku, []);
        self::$db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            self::$db->exec('UPDATE supplier_item SET sku_id = 999999 WHERE id = ?', [(int) $i['id']]);
        } finally {
            self::$db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        self::assertCount(1, self::found("supplier item {$i['id']} names item 999999, which does not exist"));
        self::$db->exec('UPDATE supplier_item SET sku_id = ? WHERE id = ?', [$sku, (int) $i['id']]);
        // A merged item is not a violation (the screens tag it).
        $into = self::makeSku('Into');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$into, $sku]);
        self::assertSame([], SupplierInvariants::check(self::$db));
    }
}
