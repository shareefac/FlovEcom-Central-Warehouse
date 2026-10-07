<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Catalogue\ItemCards;
use CW\Reorder\DraftPos;
use CW\Reorder\ReorderList;

/**
 * The reorder list and purchase orders honour the item card (IM3; IM9 "discontinued, single-use and over-limit items are never
 * suggested"; docs/decisions.md I103, I105): a discontinued item and an item its confirmed card blocks are never suggested; a card
 * that breaks a rule but is not confirmed yet is flagged and still suggested; "create draft PO" skips a blocked item; a purchase
 * order with a blocked item is not approved, and its warnings say why; a corrected card lifts the block once a person confirms it
 * again (I113).
 */
final class ReorderItemCardTest extends ReorderTestCase
{
    /** @return array<int, array<string, mixed>> sku id => line */
    private function lines(array $q = []): array
    {
        return array_column($this->reorderList()->lines(ReorderList::filters($q)), null, 'sku_id');
    }

    public function testDiscontinuedAndBlockedItemsAreNeverSuggested(): void
    {
        $s = $this->listScenario();
        $cards = new ItemCards(self::$db);
        $editor = $this->staffUser('stock_controller');
        $before = $this->lines();
        self::assertGreaterThan(0, $before[$s['b']]['packs']);
        self::assertGreaterThan(0, $before[$s['c']]['packs']);
        self::assertGreaterThan(0, $before[$s['a']]['packs']);

        $cards->save($editor, $s['b'], 0, ['discontinued' => 'yes']);
        $cards->save($editor, $s['c'], 0, ['product_type' => 'prefilled_pod', 'liquid_ml' => '3', 'nicotine_mg' => '20', 'duty_liable' => 'yes']);
        $cards->confirm($editor, $s['c'], 1, true);
        $cards->save($editor, $s['a'], 0, ['nicotine_mg' => '25']); // breaks a rule, not confirmed: a warning

        $need = $this->lines();
        self::assertArrayNotHasKey($s['b'], $need, 'discontinued: never suggested');
        self::assertArrayNotHasKey($s['e'], $need, 'the buyer\'s "do not reorder" (I66) still holds beside the card\'s flag');
        self::assertArrayNotHasKey($s['c'], $need, 'blocked: never suggested');
        self::assertGreaterThan(0, $need[$s['a']]['packs'], 'a warning does not stop the suggestion');
        self::assertContains('card_warning', $need[$s['a']]['flags']);
        self::assertSame([[], ['trpr_nicotine']], [$need[$s['a']]['card_blocked'], $need[$s['a']]['card_warnings']]);
        $all = $this->lines(['show' => 'all']);
        self::assertSame([0, 'discontinued (item card)'], [$all[$s['b']]['packs'], $all[$s['b']]['never']]);
        self::assertSame([0, 'marked "do not reorder"'], [$all[$s['e']]['packs'], $all[$s['e']]['never']]);
        self::assertContains('discontinued', $all[$s['b']]['flags']);
        self::assertSame([0, 'blocked by its item card: tank or pod over 2 ml'], [$all[$s['c']]['packs'], $all[$s['c']]['never']]);
        self::assertContains('card_blocked', $all[$s['c']]['flags']);

        // "Create draft PO": the buyer may still order a discontinued item on purpose; a blocked one is skipped.
        $r = (new DraftPos(self::$db, $this->pos, $this->reorderList()))->create($s['buyer'], [['sku_id' => $s['b'], 'packs' => 1], ['sku_id' => $s['c'], 'packs' => 2]],
            'cw', str_repeat('c', 32));
        self::assertSame([(int) $s['s1']['id']], array_column($r['drafts'], 'supplier_id'));
        self::assertSame([[$s['c'], 'blocked by its item card: tank or pod over 2 ml: it cannot be ordered (correct the item card and confirm it again if it is wrong)']],
            array_map(static fn (array $x): array => [$x['sku_id'], $x['reason']], $r['skipped']));
        self::assertStringContainsString(sprintf('%s is marked discontinued on its item card.', self::$db->value('SELECT code FROM sku WHERE id = ?', [$s['b']])),
            implode("\n", $this->pos->warnings($r['drafts'][0]['document_id'])));

        // A purchase order with the blocked item is not approved; its warnings say why. Corrected, the block is lifted.
        $po = $this->draftPo($s['buyer'], (int) $s['s2']['id'], [['supplier_item_id' => (int) $s['si']['c']['id'], 'packs' => 2]]);
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$s['c']]);
        self::assertStringContainsString("Line 1: {$code} is blocked by its item card (tank or pod over 2 ml): the order cannot be approved with it.",
            implode("\n", $this->pos->warnings($po->id)));
        $e = self::refused(422, 'item_blocked', fn () => $this->pos->approve($s['buyer'], $po->id, $po->version));
        self::assertSame([$code => ['trpr_tank_ml']], $e->detail['items']);
        self::assertSame('draft', self::$db->value('SELECT status FROM document WHERE id = ?', [$po->id]));
        $cards->save($editor, $s['c'], 2, ['liquid_ml' => '2']);
        self::assertArrayNotHasKey($s['c'], $this->lines(), 'corrected but not confirmed again: still blocked');
        self::refused(422, 'item_blocked', fn () => $this->pos->approve($s['buyer'], $po->id, $po->version));
        self::assertSame(['trpr_tank_ml'], $cards->confirm($editor, $s['c'], 3)['lifted']);
        self::assertGreaterThan(0, $this->lines()[$s['c']]['packs'], 'corrected and confirmed: suggested again');
        $po = $this->pos->approve($s['buyer'], $po->id, $po->version);
        self::assertSame('posted', $po->status);
        $warned = $this->draftPo($s['buyer'], (int) $s['s1']['id'], [['supplier_item_id' => (int) $s['si']['a']['id'], 'packs' => 1]]);
        self::assertStringContainsString("item card, not confirmed yet, says nicotine over 20 mg/ml: check it before ordering", implode("\n", $this->pos->warnings($warned->id)));
        self::assertSame('posted', $this->pos->approve($s['buyer'], $warned->id, $warned->version)->status, 'a warning does not stop the approval');
    }
}
