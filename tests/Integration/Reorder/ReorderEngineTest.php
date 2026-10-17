<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Caller;
use CW\Clock;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Tests\Support\HookedStatement;
use CW\Catalogue\ItemCards;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\OpResult;
use CW\Reorder\DraftPos;
use CW\Reorder\ReorderLines;
use CW\Reorder\ReorderList;
use CW\Reorder\ReorderRefresh;
use CW\Reorder\ReorderSettings;
use CW\Tests\Support\TestDb;

/**
 * Pack G, the reorder engine (docs/decisions.md PG3-PG8) on the reorder-list scenario (ReorderFixtures::listScenario): the stored list is
 * the worked-out list (the suggested quantity, the safety stock and "below the safety stock" on worked examples), spare days from the
 * item, its brand, its main supplier or the normal setting, discontinued items left out, open orders counted, "create draft orders"
 * idempotent and skipping the doubtful lines, and the refresh in chunks that writes only what changed.
 */
final class ReorderEngineTest extends ReorderTestCase
{
    private function stored(): ReorderLines
    {
        return new ReorderLines(self::$db);
    }

    /** @return array<int, array<string, mixed>> sku id => stored line, in list order */
    private function page(array $q = []): array
    {
        return array_column($this->stored()->page(ReorderList::filters($q), 1, 200)['lines'], null, 'sku_id');
    }

    private function refresh(): ReorderRefresh
    {
        return new ReorderRefresh(self::$db, $this->pos);
    }

    private function drafts(): DraftPos
    {
        return new DraftPos(self::$db, $this->pos, $this->reorderList(), $this->refresh());
    }

    /**
     * The scenario worked by hand (12, 2, 5, 1 and 3 a day; 5 normal spare days):
     *   A  lead 3 (its supplier item) + 7 + 5 = 15 days × 12 = 180; have 60 -> need 120 -> 5 boxes of 24; safety 60, not below (60 = 60)
     *   B  2 (normal) + 7 + 5 = 14 × 2 = 28; have 0 -> 3 packs of 10 = 30; safety 10, below
     *   C  4 + 14 (its supplier) + 5 = 23 × 5 = 115 -> 20 packs of 6 = 120; safety 25, below
     *   D  14 × 1 = 14, no supplier: 14 singles; safety 5, below
     *   E  never suggested ("do not reorder"): nothing to buy, never below
     */
    public function testTheStoredListIsTheWorkedOutList(): void
    {
        $s = $this->listScenario();
        $need = $this->page();
        self::assertSame([$s['c'], $s['b'], $s['d'], $s['a']], array_keys($need), 'the same order as the list worked out on the page (urgent, days left, value)');
        $pick = static fn (array $l): array => [$l['target'], $l['available'], $l['on_order'], $l['need'], $l['packs'], $l['units'], $l['safety_stock'], $l['below_safety']];
        self::assertSame([180, 60, 0, 120, 5, 120, 60, false], $pick($need[$s['a']]));
        self::assertSame([28, 0, 0, 28, 3, 30, 10, true], $pick($need[$s['b']]));
        self::assertSame([115, 0, 0, 115, 20, 120, 25, true], $pick($need[$s['c']]));
        self::assertSame([14, 0, 0, 14, 14, 14, 5, true], $pick($need[$s['d']]));
        $all = $this->page(['show' => 'all']);
        self::assertSame([$s['c'], $s['b'], $s['d'], $s['e'], $s['a']], array_keys($all));
        self::assertSame([0, 15, false], [$all[$s['e']]['packs'], $all[$s['e']]['safety_stock'], $all[$s['e']]['below_safety']]);
        // Every stored line is the line worked out now (the whole line, through its JSON).
        $live = array_column($this->reorderList()->lines(ReorderList::filters(['show' => 'all'])), null, 'sku_id');
        self::assertEquals(array_map(static fn (array $l): array => json_decode((string) json_encode($l), true), $live), $all);
        // Below the safety stock, whatever there is to buy; the compact columns the list filters by.
        self::assertSame([$s['c'], $s['b'], $s['d']], array_keys($this->page(['below' => '1'])));
        self::assertSame([$s['b'], $s['d']], array_keys($this->page(['below' => '1', 'brand' => 'elux'])), 'the brand filter ignores case');
        self::assertSame([$s['c']], array_keys($this->page(['supplier' => (string) $s['s2']['id']])));
        self::assertSame([[1, 1, 0, (int) $s['s1']['id'], 'Elux'], [0, 0, 1, null, 'Elux']], array_map('array_values', self::$db->all(
            'SELECT need, below_safety, never_suggested, supplier_id, brand FROM reorder_line WHERE sku_id IN (?, ?) ORDER BY sku_id', [$s['b'], $s['e']])));
        self::assertSame(['Elux', 'Lost Mary'], $this->stored()->brands());
    }

    /** Spare days: the item's, else its brand's, else its main supplier's (pack G), else the normal setting; each change reaches the stored line. */
    public function testSpareDaysFromTheItemTheBrandTheSupplierOrTheNormalSetting(): void
    {
        $s = $this->listScenario();
        $set = new ReorderSettings(self::$db);
        $c = fn (): array => $this->page(['show' => 'all'])[$s['c']];
        $sup = $set->saveSupplier($s['buyer'], (int) $s['s2']['id'], 0, ['safety_days' => '2', 'note' => 'they deliver on time']);
        $this->refresh()->items([$s['c']]);
        self::assertSame([2, 'supplier', 20, 100, 17, 102, 10], [$c()['safety'], $c()['safety_source'], $c()['cover_days'], $c()['target'], $c()['packs'], $c()['units'],
            $c()['safety_stock']], '4 + 14 + 2 = 20 days × 5 = 100 -> 17 packs of 6; safety 2 × 5');
        self::assertSame(5, $this->page(['show' => 'all'])[$s['a']]['safety'], 'another supplier\'s items keep the normal days');
        $set->saveBrand($s['buyer'], 'Lost Mary', 0, ['safety_days' => '3']);
        $this->refresh()->items([$s['c']]);
        self::assertSame([3, 'brand', 105, 18, 15], [$c()['safety'], $c()['safety_source'], $c()['target'], $c()['packs'], $c()['safety_stock']], 'the brand wins');
        $set->saveItem($s['buyer'], $s['c'], 0, ['safety_days' => '1']);
        $this->refresh()->items([$s['c']]);
        self::assertSame([1, 'item', 95, 16, 5], [$c()['safety'], $c()['safety_source'], $c()['target'], $c()['packs'], $c()['safety_stock']], 'the item wins');
        // The supplier's row: versioned, audited, refused to a reviewer, ranges checked.
        self::assertSame([2, 'they deliver on time', 1], [(int) $sup['safety_days'], $sup['note'], (int) $sup['version']]);
        self::refused(409, 'version_conflict', fn () => $set->saveSupplier($s['buyer'], (int) $s['s2']['id'], 0, ['safety_days' => '4']));
        self::refused(422, 'bad_field', fn () => $set->saveSupplier($s['buyer'], (int) $s['s2']['id'], 1, ['safety_days' => '91']));
        self::refused(403, 'role_not_allowed', fn () => $set->saveSupplier($this->staffUser('reviewer'), (int) $s['s2']['id'], 1, ['safety_days' => '4']));
        self::refused(404, 'unknown_supplier', fn () => $set->saveSupplier($s['buyer'], 999_999, 0, ['safety_days' => '4']));
        self::assertSame($sup, $set->saveSupplier($s['buyer'], (int) $s['s2']['id'], 1, ['safety_days' => '2', 'note' => 'they deliver on time']), 'unchanged: nothing written');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'reorder.supplier_settings'"));
    }

    /** E22: discontinued on the item card is left out of the suggestions, the safety-stock list, the drafts and the low-stock figures. */
    public function testDiscontinuedItemsAreLeftOut(): void
    {
        $s = $this->listScenario();
        (new ItemCards(self::$db))->save($this->staffUser('stock_controller'), $s['b'], 0, ['discontinued' => 'yes']);
        $this->refreshLines();
        self::assertArrayNotHasKey($s['b'], $this->page(), 'not on "Products to buy"');
        self::assertArrayNotHasKey($s['b'], $this->page(['below' => '1']), 'not "below the safety stock" either');
        $b = $this->page(['show' => 'all'])[$s['b']];
        self::assertSame([0, 'discontinued (item card)', false], [$b['packs'], $b['never'], $b['below_safety']]);
        self::assertSame([0, 1, 0], array_map('intval', array_values((array) self::$db->one('SELECT need, never_suggested, below_safety FROM reorder_line WHERE sku_id = ?',
            [$s['b']]))));
        $r = $this->drafts()->create($s['buyer'], [['sku_id' => $s['a'], 'packs' => 5], ['sku_id' => $s['b'], 'packs' => 3]], 'cw', str_repeat('d', 32));
        self::assertSame([$s['a']], array_map('intval', self::$db->column('SELECT sku_id FROM document_line WHERE document_id = ?', [$r['drafts'][0]['document_id']])));
        self::assertSame([[$s['b'], 'never suggested (discontinued (item card)): add it to an order in the order editor if you mean it']],
            array_map(static fn (array $x): array => [$x['sku_id'], $x['reason']], $r['skipped']));
    }

    /** Open orders (approved, sent, part received) count as on order; drafts are shown, never counted; a cancelled order is gone. */
    public function testOpenPurchaseOrdersAreCounted(): void
    {
        $s = $this->listScenario();
        $si = (int) $s['si']['a']['id'];
        $sent = $this->draftPo($s['buyer'], (int) $s['s1']['id'], [['supplier_item_id' => $si, 'packs' => 2]]);
        $sent = $this->pos->approve($s['buyer'], $sent->id, $sent->version);
        $this->pos->markSent($s['buyer'], $sent->id, $sent->version, 'email', 'orders@example.test', true);
        $part = $this->draftPo($s['buyer'], (int) $s['s1']['id'], [['supplier_item_id' => $si, 'packs' => 1]]);
        $part = $this->pos->approve($s['buyer'], $part->id, $part->version);
        self::$db->transaction(fn () => $this->pos->applyReceipt($part->id, [1 => 10], 'GRN-TEST'));
        $gone = $this->draftPo($s['buyer'], (int) $s['s1']['id'], [['supplier_item_id' => $si, 'packs' => 1]]);
        $gone = $this->pos->approve($s['buyer'], $gone->id, $gone->version);
        $this->pos->cancel($s['buyer'], $gone->id, $gone->version, 'not_needed', null);
        $this->draftPo($s['buyer'], (int) $s['s1']['id'], [['supplier_item_id' => $si, 'packs' => 3]]);
        self::assertSame([0, 120], [$this->page()[$s['a']]['on_order'], $this->page()[$s['a']]['units']], 'the stored list is as it was worked out');
        $r = $this->refreshLines();
        self::assertSame(1, $r['changed'], 'only A changed');
        $a = $this->page()[$s['a']];
        self::assertSame([48 + 14, 72, 122, 58, 3, 72, 60, false], [$a['on_order'], $a['in_drafts'], $a['position'], $a['need'], $a['packs'], $a['units'], $a['safety_stock'],
            $a['below_safety']], 'sent 48 + the part-received box 24 - 10 received; the draft (72) and the cancelled order do not count');
    }

    /**
     * "Create draft orders" (E4): the same form twice makes the drafts once; the doubtful lines are skipped and listed (no main supplier,
     * never suggested, a main supplier not approved yet, no price), the rest is drafted; the stored list shows the new drafts at once.
     */
    public function testCreatingDraftsIsIdempotentAndSkipsTheDoubtfulLines(): void
    {
        $s = $this->listScenario();
        $buyer = $s['buyer'];
        $f = $this->brandItem('Elux new flavour waiting supplier', 'Elux');
        $g = $this->brandItem('Elux no price yet', 'Elux');
        $s3 = $this->draft($buyer, ['name' => 'Waiting Wholesale']);
        $s3 = $this->sup->requestActivation($buyer, (int) $s3['id'], (int) $s3['version']);
        self::assertSame('pending_approval', $s3['status']);
        $this->reorderSupplierItem($buyer, (int) $s3['id'], $f, 10, '9.00', ['is_preferred' => '1']);
        $this->reorderSupplierItem($buyer, (int) $s['s1']['id'], $g, 10, null, ['is_preferred' => '1']);
        foreach ([$f, $g] as $sku) {
            self::$db->exec("INSERT INTO item_reorder (sku_id, min_stock, updated_actor) VALUES (?, 10, 'system:test')", [$sku]);
        }
        $this->refreshLines();
        $picks = [['sku_id' => $s['a'], 'packs' => 5], ['sku_id' => $s['b'], 'packs' => 3], ['sku_id' => $s['d'], 'packs' => 14], ['sku_id' => $s['e'], 'packs' => 1],
            ['sku_id' => $f, 'packs' => 1], ['sku_id' => $g, 'packs' => 1]];
        $run = fn (array $p, string $key): OpResult => (new Idempotency(self::$db))->run(Caller::staff((int) $buyer->staffUserId), "ui:{$buyer->staffUserId}:{$key}",
            'ui.reorder.draft', '/ui/purchasing/reorder/draft', ['picks' => $p, 'stock' => 'cw'], null, null,
            fn (Db $db): OpResult => OpResult::of(303, $this->drafts()->create($buyer, $p, 'cw', $key)));
        $one = $run($picks, str_repeat('e', 32));
        $two = $run($picks, str_repeat('e', 32));
        self::assertEquals($one->body, $two->body, 'the same form twice: one effect (the stored answer, as it was)');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM purchase_order WHERE source = 'reorder'"));
        self::assertSame([[(int) $s['s1']['id'], 2, 5 * 24 + 3 * 10]], array_map(static fn (array $d): array => [$d['supplier_id'], $d['lines'], $d['units']], $one->body['drafts']));
        self::assertSame([
            [$s['d'], 'no preferred supplier: mark one of its supplier items as preferred'],
            [$s['e'], 'never suggested (marked "do not reorder"): add it to an order in the order editor if you mean it'],
            [$f, 'its preferred supplier ' . $s3['code'] . ' is not approved yet'],
            [$g, 'no price yet for its supplier item at ' . $s['s1']['code'] . ': record the price first'],
        ], array_map(static fn (array $x): array => [$x['sku_id'], $x['reason']], $one->body['skipped']));
        // The stored lines of the drafted items show their drafts at once (not ticked again: I79).
        $page = $this->page(['show' => 'all']);
        self::assertSame([120, 30, 0], [$page[$s['a']]['in_drafts'], $page[$s['b']]['in_drafts'], $page[$s['d']]['in_drafts']]);
        self::assertContains('in_draft', $page[$s['a']]['flags']);
        // With the price rule switched off the line without a price is drafted (at £0, which the order editor warns about).
        $this->setting('reorder.draft_skip_no_price', 'false');
        $three = $run([['sku_id' => $g, 'packs' => 1]], str_repeat('f', 32));
        self::assertSame([[], 1], [$three->body['skipped'], count($three->body['drafts'])]);
        self::refused(422, 'nothing_picked', fn () => $this->drafts()->create($buyer, [['sku_id' => $s['a'], 'packs' => 0]]));
    }

    /**
     * E11: the list is worked out in chunks (reorder.refresh_chunk_items), writes only the lines that changed, unlists an item that left
     * it (never deletes), records its run, runs one at a time, and a line read earlier never overwrites one read later.
     */
    public function testTheRefreshWorksInChunksAndWritesOnlyWhatChanged(): void
    {
        $this->setting('reorder.refresh_chunk_items', '50');
        $ids = [];
        for ($i = 1; $i <= 120; $i++) {
            $ids[] = self::makeSku("Chunk item {$i}");
        }
        $params = [];
        foreach ($ids as $id) {
            array_push($params, $id, 5, 'system:test');
        }
        self::$db->exec('INSERT INTO item_reorder (sku_id, min_stock, updated_actor) VALUES ' . implode(', ', array_fill(0, 120, '(?, ?, ?)')), $params);
        $r = $this->refresh()->all();
        self::assertSame([120, 120, 0, 3], [$r['items'], $r['changed'], $r['unlisted'], $r['chunks']], '120 items in 3 chunks of at most 50');
        self::assertSame(120, $this->stored()->count());
        $r = $this->refresh()->all();
        self::assertSame([120, 0, 3], [$r['items'], $r['changed'], $r['chunks']], 'nothing changed: nothing written');
        self::$db->exec('UPDATE item_reorder SET min_stock = 9 WHERE sku_id = ?', [$ids[7]]);
        self::assertSame(1, $this->refresh()->all()['changed']);
        self::assertSame([9, 9], [$this->page()[$ids[7]]['target'], $this->page()[$ids[7]]['safety_stock']]);
        // An item that leaves the list (no demand, no minimum, no low-stock figure any more) is unlisted, never deleted.
        self::$db->exec('UPDATE item_reorder SET min_stock = NULL WHERE sku_id = ?', [$ids[8]]);
        $r = $this->refresh()->all();
        self::assertSame([119, 0, 1], [$r['items'], $r['changed'], $r['unlisted']]);
        self::assertSame([0, 120], [(int) self::$db->value('SELECT listed FROM reorder_line WHERE sku_id = ?', [$ids[8]]), (int) self::$db->value('SELECT COUNT(*) FROM reorder_line')]);
        self::assertArrayNotHasKey($ids[8], $this->page(['show' => 'all']));
        $job = (array) self::$db->one("SELECT runs, ok_at, error, CAST(result AS CHAR) AS result FROM reorder_job WHERE job = 'lines'");
        self::assertSame([4, null], [(int) $job['runs'], $job['error']]);
        self::assertNotNull($job['ok_at']);
        self::assertSame(119, json_decode((string) $job['result'], true)['items']);
        // A read made earlier never overwrites a line read later (a long run that read a chunk before a draft was made).
        self::$db->exec('UPDATE item_reorder SET min_stock = 11 WHERE sku_id = ?', [$ids[7]]);
        $early = new ReorderRefresh(self::$db, $this->pos, null, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2020-01-01 00:00:00', new \DateTimeZone('UTC')));
        $early->items([$ids[7]]);
        self::assertSame(9, $this->page()[$ids[7]]['target'], 'the stored line was read later: kept');
        $this->refresh()->items([$ids[7]]);
        self::assertSame(11, $this->page()[$ids[7]]['target']);
        // One run at a time: another holds the lock -> 409 refresh_running, nothing written.
        $other = TestDb::connect();
        $other->value('SELECT GET_LOCK(?, 0)', [ReorderRefresh::LOCK_PREFIX . TestDb::name()]);
        try {
            self::refused(409, 'refresh_running', fn () => $this->refresh()->all());
        } finally {
            $other->value('SELECT RELEASE_LOCK(?)', [ReorderRefresh::LOCK_PREFIX . TestDb::name()]);
        }
        self::assertSame(4, (int) self::$db->value("SELECT runs FROM reorder_job WHERE job = 'lines'"), 'a refused run is not a run');
    }

    /** A refresher whose clock says $utc (the time its figures are read). */
    private function refreshAt(string $utc): ReorderRefresh
    {
        return new ReorderRefresh(self::$db, $this->pos, null, static fn (): \DateTimeImmutable => new \DateTimeImmutable($utc, new \DateTimeZone('UTC')));
    }

    /**
     * Review finding 1 (Codex, 14 Oct 2026): a refresh that found a line unchanged still records that it read it, so a refresh that read
     * the figures EARLIER (and was slower to write) can never put its older line over it. The line's change time stays.
     */
    public function testALineReadEarlierNeverOverwritesOneFoundUnchangedLater(): void
    {
        $x = self::makeSku('Unchanged later');
        self::$db->exec("INSERT INTO item_reorder (sku_id, min_stock, updated_actor) VALUES (?, 5, 'system:test')", [$x]);
        $this->refreshAt('2026-10-14 10:00:00')->items([$x]);
        // B reads at 10:00:02: nothing changed (the minimum is 5), so nothing is rewritten, but its read is recorded.
        self::assertSame(0, $this->refreshAt('2026-10-14 10:00:02')->items([$x])['changed']);
        self::assertSame(['2026-10-14 10:00:02.000000', '2026-10-14 10:00:00.000000'], array_values((array) self::$db->one(
            'SELECT read_at, changed_at FROM reorder_line WHERE sku_id = ?', [$x])), 'read recorded; changed_at kept');
        // A read the minimum of 10 at 10:00:01 (a save B never saw) and writes only now: older than B's read, it loses.
        self::$db->exec('UPDATE item_reorder SET min_stock = 10 WHERE sku_id = ?', [$x]);
        $this->refreshAt('2026-10-14 10:00:01')->items([$x]);
        self::assertSame(5, $this->page(['show' => 'all'])[$x]['target'], 'the earlier read did not overwrite the later one');
        self::assertSame(5, (int) self::$db->value('SELECT safety_stock FROM reorder_line WHERE sku_id = ?', [$x]), 'both tables agree');
        // A read made after both writes it.
        $this->refreshAt('2026-10-14 10:00:03')->items([$x]);
        self::assertSame([10, 10], [$this->page(['show' => 'all'])[$x]['target'], (int) self::$db->value('SELECT safety_stock FROM reorder_line WHERE sku_id = ?', [$x])]);
    }

    /**
     * Review finding 2 (Codex, 14 Oct 2026): an item a buyer adds to the list while a full refresh runs (its own refresh writes its line
     * after the full run read the list's ids) stays listed: the clean-up only unlists lines read before the full run read the ids, and only
     * items that are still off the list.
     */
    public function testAnItemAddedDuringAFullRefreshStaysListed(): void
    {
        $this->setting('reorder.refresh_chunk_items', '50');
        $ids = [];
        for ($i = 1; $i <= 120; $i++) {
            $ids[] = self::makeSku("Full run item {$i}");
        }
        $params = [];
        foreach ($ids as $id) {
            array_push($params, $id, 5, 'system:test');
        }
        self::$db->exec('INSERT INTO item_reorder (sku_id, min_stock, updated_actor) VALUES ' . implode(', ', array_fill(0, 120, '(?, ?, ?)')), $params);
        $this->refreshAt('2026-10-14 09:00:00')->all();
        $x = self::makeSku('Added while the list is worked out');
        self::$db->exec('UPDATE item_reorder SET min_stock = 6 WHERE sku_id = ?', [$ids[0]]); // the first chunk has a line to write
        $base = new \DateTimeImmutable('2026-10-14 10:00:00', new \DateTimeZone('UTC'));
        $calls = 0;
        $done = false;
        $clock = function () use (&$calls, &$done, $base, $ids, $x): \DateTimeImmutable {
            $now = $base->modify('+' . (++$calls) . ' seconds');
            // Once the first chunk is written by this run (its first item read after 10:00:00), the buyer adds X and its line is written.
            if (!$done && (string) self::$db->value('SELECT read_at FROM reorder_line WHERE sku_id = ?', [$ids[0]]) > '2026-10-14 10:00:00') {
                $done = true;
                self::$db->exec("INSERT INTO item_reorder (sku_id, min_stock, updated_actor) VALUES (?, 7, 'system:test')", [$x]);
                $this->refreshAt($now->modify('+500 milliseconds')->format('Y-m-d H:i:s.u'))->items([$x]);
            }
            return $now;
        };
        $r = (new ReorderRefresh(self::$db, $this->pos, null, $clock))->all();
        self::assertTrue($done, 'X was added during the run');
        self::assertSame([120, 0], [$r['items'], $r['unlisted']], 'the run read 120 ids; it unlisted nothing');
        self::assertSame(1, (int) self::$db->value('SELECT listed FROM reorder_line WHERE sku_id = ?', [$x]), 'X stays on the list');
        self::assertSame(7, $this->page(['show' => 'all'])[$x]['target']);
        // The next full run counts it, and an item that really left is still unlisted.
        self::$db->exec('UPDATE item_reorder SET min_stock = NULL WHERE sku_id = ?', [$ids[5]]);
        $r = $this->refreshAt('2026-10-14 11:00:00')->all();
        self::assertSame([120, 1], [$r['items'], $r['unlisted']], '120 + X - the one that left');
        self::assertSame(0, (int) self::$db->value('SELECT listed FROM reorder_line WHERE sku_id = ?', [$ids[5]]));
    }

    /** A bad setting stops the refresh before anything is written, and the run is recorded with its error. */
    public function testABadSettingIsRecordedAsTheRunsError(): void
    {
        $this->listScenario();
        self::$db->exec("UPDATE app_setting SET value_json = CAST('9' AS JSON) WHERE setting_key = 'reorder.refresh_chunk_items'");
        try {
            self::refused(500, 'bad_setting', fn () => $this->refresh()->all());
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = CAST('500' AS JSON) WHERE setting_key = 'reorder.refresh_chunk_items'");
        }
        $job = (array) self::$db->one("SELECT error, ok_at FROM reorder_job WHERE job = 'lines'");
        self::assertStringStartsWith('bad_setting: ', (string) $job['error']);
        self::assertNotNull($job['ok_at'], 'the earlier good run (the scenario\'s) is still known');
        try {
            $this->refresh()->all();
        } catch (CwException $e) {
            self::fail($e->getMessage());
        }
        self::assertNull(self::$db->value("SELECT error FROM reorder_job WHERE job = 'lines'"), 'a good run clears it');
    }

    public function testARefreshRecomputesAfterWaitingForADraft(): void
    {
        $s = $this->listScenario();
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []]);
        $pos = new PurchaseOrders($conn, new Documents($conn, DocumentHandlers::all($conn)));
        $at = Clock::now()->modify('+2 hours');
        $ran = false;
        $draft = function () use ($s, &$ran): void {
            HookedStatement::$before = [];
            $ran = true;
            $this->drafts()->create($s['buyer'], [['sku_id' => $s['a'], 'packs' => 5]], 'cw', str_repeat('a', 32));
        };
        // Stop before the first per-item lock, whether it is an existing-row SELECT or the missing-row upsert.
        HookedStatement::$before = [
            'SELECT sku_id, line_hash, listed, read_at FROM reorder_line' => $draft,
            'INSERT INTO reorder_line (sku_id, listed, need, urgent' => $draft,
        ];
        try {
            (new ReorderRefresh($conn, $pos, null, static fn (): \DateTimeImmutable => $at))->items([$s['a']]);
        } finally {
            HookedStatement::$before = [];
        }
        self::assertTrue($ran);
        $line = $this->page(['show' => 'all'])[$s['a']];
        self::assertSame(120, $line['in_drafts'], 'the later refresh reads the draft that committed while it waited');
        self::assertContains('in_draft', $line['flags']);
    }

    public function testAbsentResultsKeepMarkersForMissingAndAlreadyUnlistedRows(): void
    {
        $x = self::makeSku('Never listed yet');
        $this->refreshAt('2026-10-14 10:00:02')->items([$x]);
        self::assertSame([0, '2026-10-14 10:00:02.000000'], array_values((array) self::$db->one(
            'SELECT listed, read_at FROM reorder_line WHERE sku_id = ?', [$x])), 'absence is recorded even without an old row');
        self::$db->exec("INSERT INTO item_reorder (sku_id, min_stock, updated_actor) VALUES (?, 10, 'system:test')", [$x]);
        $this->refreshAt('2026-10-14 10:00:01')->items([$x]);
        self::assertSame(0, (int) self::$db->value('SELECT listed FROM reorder_line WHERE sku_id = ?', [$x]), 'an older refresh cannot restore a removed suggestion');

        $this->refreshAt('2026-10-14 10:00:03')->items([$x]);
        self::$db->exec('UPDATE item_reorder SET min_stock = NULL WHERE sku_id = ?', [$x]);
        $this->refreshAt('2026-10-14 10:00:04')->items([$x]);
        $changed = self::$db->value('SELECT changed_at FROM reorder_line WHERE sku_id = ?', [$x]);
        $this->refreshAt('2026-10-14 10:00:06')->items([$x]);
        self::assertSame(['2026-10-14 10:00:06.000000', $changed], array_values((array) self::$db->one(
            'SELECT read_at, changed_at FROM reorder_line WHERE sku_id = ?', [$x])), 'already unlisted: advance the read, retain the change time');
        self::$db->exec('UPDATE item_reorder SET min_stock = 20 WHERE sku_id = ?', [$x]);
        $this->refreshAt('2026-10-14 10:00:05')->items([$x]);
        self::assertSame(0, (int) self::$db->value('SELECT listed FROM reorder_line WHERE sku_id = ?', [$x]));
        self::assertArrayNotHasKey($x, $this->page(['show' => 'all']));
    }
}
