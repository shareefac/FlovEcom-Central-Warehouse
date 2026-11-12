<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Catalogue;

use CW\Caller;
use CW\Catalogue\ItemBarcodes;
use CW\Catalogue\ItemNames;
use CW\Tests\Support\StatementHook;
use CW\Tests\Support\TestDb;
use CW\Ui\Queries;

/**
 * Renaming an item (docs/decisions.md IR1-IR6; the owner, 12 Nov 2026: "giving me an option to change the name"): CW's own name only, in ONE
 * transaction with its audit row; the person checked again inside it (catalogue.edit, never admin); an item joined into another refused; a
 * name changed by someone else meanwhile refused (409, with the name it has now); the same name writes nothing; nothing else of the item moves
 * (code, stock, links, barcodes, card, the stock feed); the history newest first; Find finds an item by a name it had before.
 */
final class ItemNamesTest extends CatalogueTestCase
{
    private ItemNames $names;

    protected function setUp(): void
    {
        parent::setUp();
        $this->names = new ItemNames(self::$db);
    }

    private function nameOf(int $sku): string
    {
        return (string) self::$db->value('SELECT name FROM sku WHERE id = ?', [$sku]);
    }

    private function renames(int $sku): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'sku.rename' AND entity_type = 'sku' AND entity_id = ?", [(string) $sku]);
    }

    public function testARenameWritesTheNameAndOneAuditRowInOneTransaction(): void
    {
        $who = $this->editor();
        $sku = $this->item('legacy', 5, 'Elux Legend 3500 Blue Razz Ice 20mg Nic Salt');
        $r = $this->names->rename($who, $sku, 'Elux Legend 3500 Blue Razz Ice 20mg Nic Salt', '  Elux Legend  Blue Razz Ice 20mg ');
        self::assertSame(['result' => 'renamed', 'name' => 'Elux Legend Blue Razz Ice 20mg', 'before' => 'Elux Legend 3500 Blue Razz Ice 20mg Nic Salt'], $r);
        self::assertSame('Elux Legend Blue Razz Ice 20mg', $this->nameOf($sku));
        $a = self::$db->one("SELECT actor, staff_user_id, entity_type, entity_id, detail FROM audit_log WHERE action = 'sku.rename'");
        self::assertNotNull($a);
        self::assertSame(['staff:' . $who->staffUserId, (string) $who->staffUserId, 'sku', (string) $sku], [(string) $a['actor'], (string) $a['staff_user_id'],
            (string) $a['entity_type'], (string) $a['entity_id']]);
        $detail = (array) json_decode((string) $a['detail'], true);
        ksort($detail);
        self::assertSame(['after' => 'Elux Legend Blue Razz Ice 20mg', 'before' => 'Elux Legend 3500 Blue Razz Ice 20mg Nic Salt', 'code' => sprintf('CW-%06d', $sku)], $detail);

        // One transaction: the item locked first (FOR UPDATE), then the name, then the audit row; when the audit row fails, the name stays.
        $seen = [];
        $fail = false;
        self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [StatementHook::class, [static function (string $sql) use (&$seen, &$fail): void {
            $seen[] = [preg_replace('/\s+/', ' ', $sql), self::$db->pdo()->inTransaction()];
            if ($fail && str_starts_with($sql, 'INSERT INTO audit_log')) {
                throw new \RuntimeException('the audit row could not be written');
            }
        }]]);
        try {
            $this->names->rename($who, $sku, 'Elux Legend Blue Razz Ice 20mg', 'Elux Legend Blue Razz 20mg');
            $fail = true;
            try {
                $this->names->rename($who, $sku, 'Elux Legend Blue Razz 20mg', 'Never saved');
                self::fail('the audit row failed: the rename must fail');
            } catch (\RuntimeException $e) {
                self::assertSame('the audit row could not be written', $e->getMessage());
            }
        } finally {
            self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
        self::assertSame('Elux Legend Blue Razz 20mg', $this->nameOf($sku), 'the name of the failed rename was rolled back with its audit row');
        self::assertSame(2, $this->renames($sku));
        $writes = array_values(array_filter($seen, static fn (array $s): bool => preg_match('/FROM sku WHERE id = \? FOR UPDATE|^UPDATE sku SET name|^INSERT INTO audit_log/', (string) $s[0]) === 1));
        self::assertSame(['SELECT id, code, name, merged_into_sku_id FROM sku WHERE id = ? FOR UPDATE', 'UPDATE sku SET name = ? WHERE id = ?'],
            [$writes[0][0], $writes[1][0]]);
        self::assertStringStartsWith('INSERT INTO audit_log', (string) $writes[2][0]);
        self::assertSame([true, true, true], array_column(array_slice($writes, 0, 3), 1), 'all inside a transaction');
    }

    public function testAnItemJoinedIntoAnotherIsRefused(): void
    {
        $who = $this->editor('mapping_lead');
        $keep = $this->item('legacy', 0, 'Kept item');
        $gone = $this->item('legacy', 0, 'Joined item');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$keep, $gone]);
        $e = self::refused(409, 'merged_item', fn () => $this->names->rename($who, $gone, 'Joined item', 'New name'));
        self::assertSame(['merged_into' => $keep, 'code' => sprintf('CW-%06d', $keep)], $e->detail);
        self::assertStringContainsString(sprintf('CW-%06d', $keep), $e->getMessage(), 'it names the item to rename instead');
        self::assertSame(['Joined item', 0], [$this->nameOf($gone), $this->renames($gone)]);
        self::refused(404, 'unknown_item', fn () => $this->names->rename($who, 999_999, 'x', 'New name'));
    }

    public function testOnlyThePeopleWhoMayChangeItemCardsRename(): void
    {
        $sku = $this->item('legacy', 0, 'Old name');
        self::refused(403, 'role_not_allowed', fn () => $this->names->rename($this->staffUser('buyer'), $sku, 'Old name', 'New name'));
        self::refused(403, 'role_not_allowed', fn () => $this->names->rename($this->staffUser('auditor'), $sku, 'Old name', 'New name'));
        self::refused(403, 'admin_cannot_edit', fn () => $this->names->rename($this->staffUser('admin'), $sku, 'Old name', 'New name'));
        self::refused(403, 'staff_required', fn () => $this->names->rename(Caller::system('test'), $sku, 'Old name', 'New name'));
        self::refused(403, 'staff_not_allowed', fn () => $this->names->rename($this->staffUser('stock_controller', false), $sku, 'Old name', 'New name'));
        self::assertSame(['Old name', 0], [$this->nameOf($sku), $this->renames($sku)]);
        foreach (['mapping_lead', 'stock_controller', 'purchasing_manager'] as $i => $role) {
            $this->names->rename($this->staffUser($role), $sku, $i === 0 ? 'Old name' : "By {$i}", 'By ' . ($i + 1));
        }
        self::assertSame(['By 3', 3], [$this->nameOf($sku), $this->renames($sku)]);
    }

    public function testANameChangedMeanwhileIsRefusedWithTheNameItHasNow(): void
    {
        $a = $this->editor();
        $b = $this->editor('mapping_lead');
        $sku = $this->item('legacy', 0, 'Vampire Vape Heisenberg 10ml');
        $this->names->rename($a, $sku, 'Vampire Vape Heisenberg 10ml', 'Vampire Vape Heisenberg 10ml 3mg');
        $e = self::refused(409, 'name_changed', fn () => $this->names->rename($b, $sku, 'Vampire Vape Heisenberg 10ml', 'Heisenberg by Vampire Vape'));
        self::assertSame(['name' => 'Vampire Vape Heisenberg 10ml 3mg'], $e->detail);
        self::assertSame(['Vampire Vape Heisenberg 10ml 3mg', 1], [$this->nameOf($sku), $this->renames($sku)]);
        // The page sends the name it showed: a case change by someone else is a change too; only the spaces of the stored name are forgiven.
        $this->names->rename($a, $sku, 'Vampire Vape Heisenberg 10ml 3mg', 'vampire vape heisenberg 10ml 3mg');
        self::refused(409, 'name_changed', fn () => $this->names->rename($b, $sku, 'Vampire Vape Heisenberg 10ml 3mg', 'Something else'));
        self::$db->exec('UPDATE sku SET name = ? WHERE id = ?', ['Legacy  name ', $sku]);
        self::assertSame('renamed', $this->names->rename($b, $sku, 'Legacy name', 'Clean name')['result']);
        self::refused(409, 'name_changed', fn () => $this->names->rename($b, $sku, '', 'Other name'));
    }

    public function testTheSameNameWritesNothing(): void
    {
        $who = $this->editor();
        $sku = $this->item('legacy', 0, 'Same name');
        self::$db->exec("UPDATE sku SET updated_at = '2026-01-01 00:00:00.000000' WHERE id = ?", [$sku]);
        self::assertSame(['result' => 'unchanged', 'name' => 'Same name', 'before' => 'Same name'], $this->names->rename($who, $sku, 'Same name', '  Same   name '));
        self::assertSame([0, '2026-01-01 00:00:00.000000'], [$this->renames($sku), (string) self::$db->value('SELECT updated_at FROM sku WHERE id = ?', [$sku])]);
        // Someone already gave it the very name typed: nothing to write, nothing refused.
        $this->names->rename($who, $sku, 'Same name', 'Newer name');
        self::assertSame('unchanged', $this->names->rename($this->editor('mapping_lead'), $sku, 'Same name', 'Newer name')['result']);
        self::assertSame(1, $this->renames($sku));
        // A refused name writes nothing either.
        foreach (['', str_repeat('x', 256), "a\x07b"] as $bad) {
            self::refused(422, match (true) { $bad === '' => 'name_empty', strlen($bad) > 255 => 'name_too_long', default => 'name_not_plain' },
                fn () => $this->names->rename($who, $sku, 'Newer name', $bad));
        }
        self::assertSame(['Newer name', 1], [$this->nameOf($sku), $this->renames($sku)]);
    }

    public function testNothingElseOfTheItemChanges(): void
    {
        $who = $this->editor();
        $vpg = $this->site('vpg');
        $sku = $this->item('legacy', 7, 'Linked item');
        $this->linked($vpg, 'V100', $sku, ['5012345678900']);
        (new ItemBarcodes(self::$db))->add($who, $sku, '96385074');
        $this->cards->save($who, $sku, 0, ['brand' => 'Elux', 'flavour' => 'Cola']);
        $state = fn (): array => [
            self::$db->one('SELECT id, code, brand, sell_policy, merged_into_sku_id, origin, origin_listing_id FROM sku WHERE id = ?', [$sku]),
            self::$db->all('SELECT * FROM stock_balance WHERE sku_id = ? ORDER BY warehouse_id', [$sku]),
            (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger'), (int) self::$db->value('SELECT COUNT(*) FROM stock_change'),
            self::$db->all('SELECT id, sku_id, status, units_per_item, map_version FROM channel_listing ORDER BY id'),
            self::$db->all('SELECT * FROM sku_barcode ORDER BY barcode'), self::$db->one('SELECT * FROM item_card WHERE sku_id = ?', [$sku]),
            (int) self::$db->value('SELECT COUNT(*) FROM item_card_change'), (int) self::$db->value('SELECT COUNT(*) FROM idempotency'),
        ];
        $before = $state();
        $this->names->rename($who, $sku, 'Linked item', 'Renamed item');
        self::assertSame($before, $state(), 'code, stock, ledger, the stock feed, links, barcodes, the card: as they were');
        self::assertSame('Renamed item', $this->nameOf($sku));
    }

    public function testTheHistoryNewestFirstAndFindByAFormerName(): void
    {
        $who = $this->editor();
        $sku = $this->item('legacy', 0, 'Pod Salt Origin Tobacco 20mg');
        $this->names->rename($who, $sku, 'Pod Salt Origin Tobacco 20mg', 'Pod Salt Tobacco 20mg');
        $this->names->rename($who, $sku, 'Pod Salt Tobacco 20mg', 'Pod Salt Classic Tobacco 20mg');
        $h = $this->names->history($sku);
        self::assertSame([['Pod Salt Tobacco 20mg', 'Pod Salt Classic Tobacco 20mg'], ['Pod Salt Origin Tobacco 20mg', 'Pod Salt Tobacco 20mg']],
            array_map(static fn (array $r): array => [$r['before'], $r['after']], $h));
        $name = (string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$who->staffUserId]);
        self::assertSame([$name, $name], array_column($h, 'who'));
        self::assertSame(1, count($this->names->history($sku, 1)));
        self::assertSame([], $this->names->history($this->item('legacy', 0, 'Never renamed')));

        // Find: by its name now (every caller), by a name it had (only where asked: the Find page), never an item joined into another.
        $q = new Queries(self::$db);
        $other = $this->item('legacy', 0, 'Origin tobacco shortfill');
        self::assertSame([$sku], array_map('intval', array_column($q->searchSkus('classic tobacco', 20), 'id')));
        self::assertSame([$other], array_map('intval', array_column($q->searchSkus('origin tobacco', 20), 'id')), 'a former name only where asked');
        $found = $q->searchSkus('ORIGIN tobacco', 20, formerNames: true);
        self::assertSame([$other, $sku], array_map('intval', array_column($found, 'id')), 'its name now first, then the names it had');
        self::assertSame([null, 'Pod Salt Origin Tobacco 20mg'], array_map(static fn (array $r): ?string => $r['former_name'] ?? null, $found));
        self::assertSame([$sku], array_map('intval', array_column($q->searchSkus('pod salt tobacco', 20, formerNames: true), 'id')), 'found once');
        self::assertNull($q->searchSkus('pod salt tobacco', 20, formerNames: true)[0]['former_name'] ?? null, 'found by its name now: no "was called"');
        $keep = $this->item('legacy', 0, 'Keeper');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$keep, $sku]);
        self::assertSame([$other], array_map('intval', array_column($q->searchSkus('origin tobacco', 20, formerNames: true), 'id')));
        self::assertSame([], $q->searchSkus('o', 20, formerNames: true), 'two characters at least, as before');
    }

    public function testTheLimitIsTheColumnsOwn(): void
    {
        self::assertSame(ItemNames::NAME_MAX, (int) self::$db->value(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'sku' AND COLUMN_NAME = 'name'", [TestDb::name()]));
        $who = $this->editor();
        $sku = $this->item('legacy', 0, 'Short');
        $long = str_repeat('é', 254) . '🍓';
        $this->names->rename($who, $sku, 'Short', $long);
        self::assertSame($long, $this->nameOf($sku), '255 characters, 4-byte ones too, fit the column');
    }

    // ------------------------------------------------------------------------------------------
    // The Codex review of 578fc44 (round 1)
    // ------------------------------------------------------------------------------------------

    /**
     * Round 1, MEDIUM 1: a rename takes the NEW name's guard row (item_name_guard, as an item made by hand or a store's choice takes it), after
     * the item and before the name, so a making of that name at this moment waits for the rename and then sees it ("the same item exists").
     */
    public function testARenameHoldsTheNewNamesGuardSoAMakingOfThatNameWaits(): void
    {
        $who = $this->editor();
        $sku = $this->item('legacy', 0, 'Old guard name');
        $key = static fn (string $n): string => (string) self::$db->value('SELECT SHA2(WEIGHT_STRING(CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_ai_ci), 256)', [$n]);
        $other = TestDb::connect();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $seen = [];
        $waited = false;
        self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [StatementHook::class, [static function (string $sql) use (&$seen, &$waited, $other): void {
            $seen[] = (string) preg_replace('/\s+/', ' ', $sql);
            if (str_starts_with($sql, 'UPDATE sku SET name')) {
                // A making of the same name (as HandMade::create takes its guard first) while the rename is not committed: it waits.
                try {
                    $other->exec('INSERT INTO item_name_guard (name_key, last_at) VALUES (SHA2(WEIGHT_STRING(CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_ai_ci), 256), '
                        . 'UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE last_at = UTC_TIMESTAMP(6)', ['NEW guard name']);
                } catch (\PDOException $e) {
                    self::assertSame(1205, \CW\Db::driverCode($e), 'the making waits for the rename');
                    $waited = true;
                }
            }
        }]]);
        try {
            $this->names->rename($who, $sku, 'Old guard name', 'New guard name');
        } finally {
            self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
        self::assertTrue($waited, 'a making of the new name (any case) is serialised with the rename');
        $order = array_values(array_filter(array_map(static fn (string $s): ?string => match (true) {
            str_contains($s, 'FROM sku WHERE id = ? FOR UPDATE') => 'item', str_starts_with($s, 'INSERT INTO item_name_guard') => 'guard',
            str_starts_with($s, 'UPDATE sku SET name') => 'name', str_starts_with($s, 'INSERT INTO audit_log') => 'audit', default => null,
        }, $seen)));
        self::assertSame(['item', 'guard', 'name', 'audit'], $order, 'lock order: the item, then the name\'s guard (HandMade and StoreChoices take the guard and then no existing item)');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM item_name_guard WHERE name_key = ?', [$key('New guard name')]));
        // Nothing written, no guard taken: the same name, a refused one.
        $this->names->rename($who, $sku, 'New guard name', 'New guard name');
        self::refused(409, 'name_changed', fn () => $this->names->rename($who, $sku, 'Old guard name', 'Third name'));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_name_guard WHERE name_key = ?', [$key('Third name')]));
    }

    /** Round 1, MEDIUM 2: the names an item had are counted per item that Find can show (one a item, joined ones and ones already found left out). */
    public function testAFormerNameFindsEveryItemItCanShow(): void
    {
        $who = $this->editor();
        $q = new Queries(self::$db);
        // Oldest: renamed once from a matching name.
        $oldest = $this->item('legacy', 0, 'Mango Ice Oldest');
        $this->names->rename($who, $oldest, 'Mango Ice Oldest', 'Fruit zero');
        // Then renamed many times, each time from a matching name: one item.
        $busy = $this->item('legacy', 0, 'Mango Ice 0');
        for ($i = 0; $i < 12; $i++) {
            $this->names->rename($who, $busy, "Mango Ice {$i}", 'Mango Ice ' . ($i + 1));
        }
        $this->names->rename($who, $busy, 'Mango Ice 12', 'Busy item');
        $old = $this->item('legacy', 0, 'Mango Ice Classic');
        $this->names->rename($who, $old, 'Mango Ice Classic', 'Fruit one');
        // Newest: renamed from a matching name, then joined into another: never counted.
        $keep = $this->item('legacy', 0, 'Keeper');
        foreach ([1, 2] as $i) {
            $joined = $this->item('legacy', 0, "Mango Ice Joined {$i}");
            $this->names->rename($who, $joined, "Mango Ice Joined {$i}", "Joined {$i}");
            self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$keep, $joined]);
        }
        self::assertSame([$old], array_map('intval', array_column($q->searchSkus('mango ice', 1, formerNames: true), 'id')), 'the joined ones are not counted');
        $three = $q->searchSkus('mango ice', 3, formerNames: true);
        self::assertSame([$old, $busy, $oldest], array_map('intval', array_column($three, 'id')), 'the newest rename first; one row an item');
        self::assertSame(['Mango Ice Classic', 'Mango Ice 12', 'Mango Ice Oldest'], array_column($three, 'former_name'), 'the newest name of each that matched');
    }

    /**
     * Round 1, MEDIUM 3: the names an item had are read only when the names now leave room on the page, in one statement capped in time
     * (MAX_EXECUTION_TIME); past the cap Find shows what the names now found.
     */
    public function testFormerNamesAreReadOnlyWhenThereIsRoomAndWithinATimeCap(): void
    {
        $who = $this->editor();
        $q = new Queries(self::$db);
        $now = $this->item('legacy', 0, 'Grape Now');
        $was = $this->item('legacy', 0, 'Grape Before');
        $this->names->rename($who, $was, 'Grape Before', 'Plum');
        $audit = [];
        $timeout = false;
        self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [StatementHook::class, [static function (string $sql) use (&$audit, &$timeout): void {
            if (str_contains($sql, 'audit_log')) {
                $audit[] = (string) preg_replace('/\s+/', ' ', $sql);
                if ($timeout) {
                    throw new \PDOException('SQLSTATE[HY000]: General error: 3024 Query execution was interrupted, maximum statement execution time exceeded');
                }
            }
        }]]);
        try {
            self::assertSame([$now], array_map('intval', array_column($q->searchSkus('grape', 1, formerNames: true), 'id')));
            self::assertSame([], $audit, 'the page is full with the names now: no history read');
            self::assertSame([$now, $was], array_map('intval', array_column($q->searchSkus('grape', 2, formerNames: true), 'id')));
            self::assertNotSame([], $audit);
            self::assertStringStartsWith('SELECT /*+ MAX_EXECUTION_TIME(' . ItemNames::FORMER_MS . ') */', $audit[0]);
            $timeout = true;
            self::assertSame([$now], array_map('intval', array_column($q->searchSkus('grape', 2, formerNames: true), 'id')), 'past the cap: the names now');
        } finally {
            self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
    }

    /** Round 1, MEDIUM 5 (OLD helper): a job taken away while the rename waited for the item is seen: the person is read again once it holds it. */
    public function testAJobTakenAwayWhileTheRenameWaitedIsSeen(): void
    {
        $who = $this->editor();
        $sku = $this->item('legacy', 0, 'Waiting name');
        $other = TestDb::connect();
        self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [StatementHook::class, [static function (string $sql) use ($other, $who): void {
            if (str_contains($sql, 'FROM sku WHERE id = ? FOR UPDATE')) {
                $other->exec('UPDATE staff_role SET revoked_at = UTC_TIMESTAMP(6) WHERE staff_user_id = ?', [$who->staffUserId]);
            }
        }]]);
        try {
            self::refused(403, 'role_not_allowed', fn () => $this->names->rename($who, $sku, 'Waiting name', 'Never'));
        } finally {
            self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
        self::assertSame(['Waiting name', 0], [$this->nameOf($sku), $this->renames($sku)]);
    }

    /** Round 1, LOW 6: a stored name with a NUL reaches the page's hidden field as U+FFFD (the HTML parser's rule): it is still that name. */
    public function testAStoredNulIsTheNameThePageShowed(): void
    {
        $who = $this->editor();
        $sku = $this->item('legacy', 0, 'tmp');
        self::$db->exec('UPDATE sku SET name = ? WHERE id = ?', ["El\0ux", $sku]);
        self::assertSame('renamed', $this->names->rename($who, $sku, "El\u{FFFD}ux", 'Elux')['result']);
        self::assertSame('Elux', $this->nameOf($sku));
    }
}
