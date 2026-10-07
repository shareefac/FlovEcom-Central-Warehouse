<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Auth\Permissions;

/**
 * The phone tab bar (design A, owner decision 7 Oct 2026): Home ("To do"), then up to three of the person's own menu items in
 * a fixed order of daily work, then "More" (the whole menu). Built from the person's menu, so it never shows a page they
 * cannot open: the owner gets To do / Matches / Duplicates / Orders / More, a buyer To do / Orders / To buy / Suppliers / More,
 * a stock controller To do / Products / Barcodes / Suppliers / More. The goods-in bench (IM6) comes before the orders: the
 * delivery check is done on the tablet, so goods in and the purchasing desk get To do / Bench / Orders / Suppliers / More; the
 * receipts and the incidents come after the suppliers, so the three bars above stay as the owner approved them.
 */
final class Tabs
{
    /** Menu keys in the order the tab bar takes them (after Home). */
    public const PRIORITY = ['review', 'duplicates', 'bench', 'orders', 'reviews', 'reorder', 'suppliers', 'receiving', 'incidents', 'cards', 'barcodes',
        'people', 'documents', 'company'];
    public const MAX = 3;

    /**
     * @param list<array{section: string, items: list<array<string, mixed>>}> $menu Permissions::menu()
     * @return list<array<string, mixed>> each: key, label (Words::TAB), path, query?, badge?; the last is "More" (#menu). [] without a menu.
     */
    public static function of(array $menu): array
    {
        $items = [];
        foreach ($menu as $section) {
            foreach ($section['items'] as $item) {
                $items[(string) $item['key']] = $item;
            }
        }
        if ($items === []) {
            return [];
        }
        // A section the menu lifted to second place (Permissions::LIFT: Staff for an admin, Products for a stock controller) is
        // that person's main work: its items come first.
        $lifted = isset($menu[1]['key']) && isset(Permissions::LIFT[$menu[1]['key']]) ? array_column($menu[1]['items'], 'key') : [];
        $tabs = [];
        foreach (array_unique(['home', ...$lifted, ...self::PRIORITY]) as $key) {
            if (isset($items[$key]) && count($tabs) <= self::MAX) {
                $tabs[] = ['key' => $key, 'label' => Words::TAB[$key]] + $items[$key];
            }
        }
        $tabs[] = ['key' => 'more', 'label' => Words::TAB['more'], 'path' => '#menu'];
        return $tabs;
    }
}
