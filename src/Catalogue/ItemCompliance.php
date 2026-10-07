<?php

declare(strict_types=1);

namespace CW\Catalogue;

use CW\CwException;
use CW\Db;

/**
 * What the item cards mean for the other modules (IM3; docs/decisions.md I103, I113, I119, I122): the one place they ask "may
 * this item be ordered / received / sold?". Read-only.
 *
 *  - BLOCKED (ItemRules::status): the rules a person confirmed the card breaks, until a person confirms it again. Refused TODAY
 *    by: the reorder list (never suggested, ReorderList), "create draft PO" (skipped, DraftPos), the approval of a purchase order
 *    (PurchaseOrderHandler::validate: 422 item_blocked). NOT YET by receiving (IM6, I-3, will call receiving() /
 *    assertAllowed('receive')) nor by the website stock (IM10, I-6, will call assertAllowed('sell')): until then a blocked item
 *    is taken off sale on the website by hand (the screens say so, I121).
 *  - WARNED: a rule the values break that no confirmation stands behind: shown everywhere (item page, item cards list, reorder
 *    flags, PO warnings), refused nowhere.
 *  - DISCONTINUED: never suggested on the reorder list; a buyer may still order it on purpose (a PO warning says so).
 *  - DUTY (for IM6, decision 8 "refuse all unstamped deliveries from 1 Jan 2027"; I-Day is after 1 Apr 2027): receiving()
 *    says stamp_required for every duty-liable item. An item whose card does not answer is flagged `duty_unknown`: treated as
 *    duty-liable (fail closed) unless its card names a product that holds no liquid (coil, tank, accessory: I119).
 *  - A write path (the PO approval) reads the cards FOR SHARE ($lock), so a confirmation committing meanwhile is either seen or
 *    waits for it (I122).
 */
final class ItemCompliance
{
    public const ACTIONS = ['order' => 'ordered', 'receive' => 'received', 'sell' => 'sold'];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Per item: card (has one), confirmed (now), enforced (confirmed at least once), level (null | warn | block), blocked (the
     * rule codes that block it), warnings (the rule codes that only warn), breaches (both), product_type, discontinued,
     * duty_liable (?bool), single_use (?bool). Items without a card are answered too (nothing broken). $lock: read the cards FOR
     * SHARE (call it inside the caller's transaction).
     *
     * @param list<int> $skuIds
     * @return array<int, array{card: bool, confirmed: bool, enforced: bool, level: ?string, blocked: list<string>, warnings: list<string>, breaches: list<string>,
     *   product_type: ?string, discontinued: bool, duty_liable: ?bool, single_use: ?bool}>
     */
    public function statusOf(array $skuIds, bool $lock = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $skuIds)));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['card' => false, 'confirmed' => false, 'enforced' => false, 'level' => null, 'blocked' => [], 'warnings' => [], 'breaches' => [],
                'product_type' => null, 'discontinued' => false, 'duty_liable' => null, 'single_use' => null];
        }
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->db->all('SELECT sku_id, product_type, liquid_ml, nicotine_mg, duty_liable, single_use, discontinued, confirmed_at, confirmed_breaches, '
                . 'first_confirmed_at FROM item_card WHERE sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') ORDER BY sku_id'
                . ($lock ? ' FOR SHARE' : ''), $chunk) as $c) {
                $st = ItemRules::status($c);
                $out[(int) $c['sku_id']] = ['card' => true, 'confirmed' => $c['confirmed_at'] !== null, 'enforced' => ItemRules::enforced($c),
                    'level' => $st['level'], 'blocked' => $st['blocked'], 'warnings' => $st['warnings'],
                    'breaches' => array_values(array_intersect(array_keys(ItemRules::RULES), [...$st['blocked'], ...$st['warnings']])),
                    'product_type' => $c['product_type'] === null ? null : (string) $c['product_type'], 'discontinued' => (int) $c['discontinued'] === 1,
                    'duty_liable' => $c['duty_liable'] === null ? null : (int) $c['duty_liable'] === 1,
                    'single_use' => $c['single_use'] === null ? null : (int) $c['single_use'] === 1];
            }
        }
        return $out;
    }

    /**
     * The blocked items among $skuIds: item id => the rules that block it.
     *
     * @param list<int> $skuIds
     * @return array<int, list<string>>
     */
    public function blocked(array $skuIds, bool $lock = false): array
    {
        $out = [];
        foreach ($this->statusOf($skuIds, $lock) as $id => $s) {
            if ($s['blocked'] !== []) {
                $out[$id] = $s['blocked'];
            }
        }
        return $out;
    }

    /**
     * Refuses (422 item_blocked, detail.items: CW code => rule codes) when any of the items is blocked; $action is what the
     * caller is about to do (order | receive | sell), for the message. $lock: as statusOf() (a write path passes true).
     *
     * @param list<int> $skuIds
     */
    public function assertAllowed(array $skuIds, string $action, bool $lock = false): void
    {
        if (!isset(self::ACTIONS[$action])) {
            throw new \InvalidArgumentException("unknown action {$action}");
        }
        $blocked = $this->blocked($skuIds, $lock);
        if ($blocked === []) {
            return;
        }
        $codes = [];
        foreach ($this->db->all('SELECT id, code FROM sku WHERE id IN (' . implode(', ', array_fill(0, count($blocked), '?')) . ') ORDER BY id', array_keys($blocked)) as $r) {
            $codes[(string) $r['code']] = $blocked[(int) $r['id']];
        }
        $parts = [];
        foreach ($codes as $code => $rules) {
            $parts[] = "{$code} (" . ItemRules::labels($rules) . ')';
        }
        throw new CwException('item_blocked', 'Blocked by the item card, so it cannot be ' . self::ACTIONS[$action] . ': ' . implode('; ', $parts)
            . '. A person confirmed these fields; if they are wrong, correct the item card and confirm it again.', 422, ['items' => $codes]);
    }

    /**
     * What IM6 (receiving, I-3) needs per item: blocked (rule codes; refuse the line), warnings (rule codes no confirmation stands
     * behind; show them), stamp_required (decision 8 from 1 Jan 2027: duty-liable, or not answered unless the card names a coil,
     * tank or accessory, I119), duty_unknown (the card does not answer: no card, or duty-liable empty), discontinued. $lock: as
     * statusOf() (receiving is a write path: pass true).
     *
     * @param list<int> $skuIds
     * @return array<int, array{blocked: list<string>, warnings: list<string>, stamp_required: bool, duty_unknown: bool, discontinued: bool}>
     */
    public function receiving(array $skuIds, bool $lock = false): array
    {
        $out = [];
        foreach ($this->statusOf($skuIds, $lock) as $id => $s) {
            $out[$id] = [
                'blocked' => $s['blocked'],
                'warnings' => $s['warnings'],
                'stamp_required' => $s['duty_liable'] ?? !in_array($s['product_type'], ItemRules::DRY_TYPES, true),
                'duty_unknown' => $s['duty_liable'] === null,
                'discontinued' => $s['discontinued'],
            ];
        }
        return $out;
    }
}
