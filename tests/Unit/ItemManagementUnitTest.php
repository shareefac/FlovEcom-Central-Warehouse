<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Admin\ApprovalRules;
use CW\Admin\DocumentRules;
use CW\Auth\Permissions;
use CW\Items\Families;
use CW\Items\Packs;
use CW\Items\RepackHandler;
use CW\Settings;
use CW\Ui\Sections;
use CW\Ui\View;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * Pack B without a database (docs/decisions.md PB1-PB16): its pages' templates follow the stock-record pages' rules (cards on a phone,
 * every word from Words); every code it shows has its words; who may do what (PB2, PB4, PB9: never admin); its tabs sit in the two
 * sections (Products, Stock), with no new sidebar item; its settings and its approval switch are on their pages, the switch off by
 * default; the repack's lines; the bulk list's parsing.
 */
final class ItemManagementUnitTest extends TestCase
{
    private const TEMPLATES = ['item_new', 'items_by_hand', 'families', 'family', 'family_bar', 'packs', 'pack_links', 'repacks', 'item_groups'];

    public function testThePagesTurnIntoCardsAndTakeEveryWordFromWords(): void
    {
        foreach (self::TEMPLATES as $name) {
            $src = (string) file_get_contents(View::defaultDir() . '/' . $name . '.php');
            preg_match_all('/<table\b([^>]*)>/i', $src, $tables, PREG_SET_ORDER);
            foreach ($tables as $t) {
                self::assertMatchesRegularExpression('/class="[^"]*\bstack\b/', $t[1], "{$name}: {$t[0]} is cards on a phone");
            }
            preg_match_all('/<td\b[^>]*>/i', $src, $cells);
            foreach ($cells[0] as $td) {
                self::assertMatchesRegularExpression('/data-label=|class="[^"]*\bc-(status|next|head)\b/', $td, "{$name}: {$td} says what it is on a phone");
            }
            foreach (['(UTC)', '$dt(', '(GBP)', '<svg', '<img', 'data:'] as $old) {
                self::assertStringNotContainsString($old, $src, "{$name}: {$old}");
            }
            $text = (string) preg_replace(['/<\?.*?\?>/s', '/<[^>]*>/', '/&[a-z]+;/'], ' ', $src);
            self::assertSame([], preg_match_all('/[A-Za-z]{2,}/', $text, $m) > 0 ? $m[0] : [], "{$name}: a word typed in the template instead of taken from Words");
            preg_match_all('#<form\b([^>]*)>(.*?)</form>#is', $src, $forms, PREG_SET_ORDER);
            foreach ($forms as $f) {
                if (preg_match('/method="post"/i', $f[1]) === 1) {
                    self::assertStringContainsString('<input type="hidden" name="csrf" value="<?= $e($csrf) ?>">', $f[2], "{$name}: a POST form without the csrf field");
                }
            }
        }
    }

    public function testEveryCodeOfPackBHasItsWords(): void
    {
        foreach ([
            'FAMILY_SKIP' => Families::SKIPS,
            'PACK_FLAG' => Packs::FLAGS,
            'PACK_LINK_FLAG' => Packs::LINK_FLAGS,
            'REPACK_DIRECTION' => RepackHandler::DIRECTIONS,
            'BY_HAND_STATE' => \CW\Items\HandMade::STATES,
            'DOC_TYPE' => ['RPK'], 'DOC_TYPES' => ['RPK'],
            'MENU' => ['families', 'multipacks', 'packs', 'pack_links', 'repack'],
            'SEGMENT' => ['packs', 'pack_links', 'by_hand'], // RD2: the items made by hand are a Show choice of Items
            'NEW' => ['add_item', 'families', 'packs', 'repack'],
            'MENU_HELP' => ['cards', 'families', 'multipacks', 'repack'],
            'PERMISSION' => ['items.create', 'families.manage', 'doc.RPK.post'],
            'AUDIT_ACTION' => ['item.create', 'item.check', 'family.create', 'family.rename', 'family.switch', 'family.assign', 'family.unassign', 'family.bulk_assign',
                'family.bulk_remove', 'pack.create', 'pack.switch', 'repack.book'],
            'AUDIT_RECORD' => ['item_family', 'item_pack'],
            'APPROVALS' => ['items'],
        ] as $group => $codes) {
            foreach ($codes as $code) {
                self::assertTrue(Words::has($group, $code), "{$group}: no word for `{$code}`");
            }
        }
        foreach (['item_new', 'families', 'family', 'packs', 'pack_links', 'repack'] as $page) {
            self::assertNotSame('', Words::intro($page), $page);
        }
        foreach ([Words::ITEMS_NOTICE, Words::ITEMS_ERROR, Words::FAMILY_SKIP, Words::PACK_FLAG] as $texts) {
            foreach ($texts as $code => $text) {
                self::assertMatchesRegularExpression('/^[A-Z"].*[.:]$/s', $text, "{$code}: a full sentence (writing rule 7)");
            }
        }
        self::assertSame(['title', 'on', 'off'], array_keys(Words::RULE['approvals.item_by_hand_check']));
        self::assertSame('Items', Words::settingTopic('items.repack_carry_cost'));
    }

    public function testWhoMayDoWhatNeverAdmin(): void
    {
        self::assertSame(['mapping_lead', 'stock_controller', 'purchasing_manager'], Permissions::MAP['items.create'], 'the catalogue team (catalogue.edit\'s holders)');
        self::assertSame(Permissions::MAP['catalogue.edit'], Permissions::MAP['items.create']);
        self::assertSame(['mapping_lead', 'stock_controller', 'purchasing_manager', 'buyer'], Permissions::MAP['families.manage']);
        self::assertSame(['stock_controller', 'warehouse'], Permissions::MAP['doc.RPK.post'], 'the people who move stock, as a transfer');
        foreach (['items.create', 'families.manage', 'doc.RPK.post'] as $perm) {
            self::assertFalse(Permissions::can(['admin'], $perm), $perm);
            self::assertFalse(Permissions::can(['admin', 'viewer', 'auditor'], $perm), $perm);
        }
        self::assertFalse(\CW\Documents\Documents::mayPost(['admin'], 'RPK'));
        self::assertTrue(\CW\Documents\Documents::mayPost(['warehouse'], 'RPK'));
    }

    public function testThePagesSitInItemsAndInventory(): void
    {
        // The redesign (docs/decisions.md RD1, RD2): Items' entries are Items (with the items made by hand as a Show choice and "+ New item"
        // opening the one-page form), Item groups, Multipacks, Barcodes; Repack is an Inventory entry.
        self::assertSame(['cards', 'families', 'multipacks', 'barcodes'], array_keys(Sections::MAP['items']['tabs']));
        self::assertSame(['cards', 'by_hand'], array_column(Sections::MAP['items']['tabs']['cards']['pages'], 'key'));
        self::assertSame('repack', array_keys(Sections::MAP['inventory']['tabs'])[4]);
        self::assertSame(['section' => 'items', 'tab' => 'cards', 'page' => 'cards'], Sections::locate('/ui/items/new'));
        self::assertSame(['section' => 'items', 'tab' => 'cards', 'page' => 'cards'], Sections::locate('/ui/items/new/12/check'));
        self::assertSame(['section' => 'items', 'tab' => 'cards', 'page' => 'by_hand'], Sections::locate('/ui/items/new', ['show' => 'all']));
        self::assertSame(['section' => 'items', 'tab' => 'families', 'page' => 'families'], Sections::locate('/ui/items/families/12'));
        self::assertSame(['section' => 'items', 'tab' => 'multipacks', 'page' => 'packs'], Sections::locate('/ui/items/packs/12/switch'));
        self::assertSame(['section' => 'items', 'tab' => 'multipacks', 'page' => 'pack_links'], Sections::locate('/ui/items/packs/links'));
        self::assertSame(['section' => 'inventory', 'tab' => 'repack', 'page' => 'repack'], Sections::locate('/ui/stock/repack/12/post'));
        self::assertSame(['section' => 'items', 'tab' => 'cards', 'page' => 'cards'], Sections::locate('/ui/items/12/family'), 'the item page\'s box stays on the item');
        $tabs = static function (array $roles, string $section): array {
            foreach (Sections::menu($roles) as $s) {
                if ($s['key'] === $section) {
                    return array_column($s['items'], 'label');
                }
            }
            return [];
        };
        self::assertSame(['Items', 'Item groups', 'Multipacks'], $tabs(['buyer'], 'items'));
        self::assertSame(['Items', 'Item groups', 'Multipacks', 'Barcodes'], $tabs(['stock_controller'], 'items'));
        self::assertContains('Repack', $tabs(['warehouse'], 'inventory'));
        self::assertNotContains('Repack', $tabs(['viewer'], 'inventory'));
        self::assertSame([['label' => Words::NEW['add_item'], 'href' => '/ui/items/new']], Sections::frame(['stock_controller'], [], '/ui/items/cards')['actions'], '"+ New item"');
        self::assertSame([['label' => Words::NEW['add_item'], 'href' => '/ui/items/new']], Sections::frame(['stock_controller'], [], '/ui/items/new', ['show' => 'all'])['actions']);
        self::assertSame([], Sections::frame(['stock_controller'], [], '/ui/items/new')['actions'], 'the form it opens has none of its own');
    }

    public function testTheSettingsAndTheSwitchAreOnTheirPagesTheSwitchOffByDefault(): void
    {
        self::assertSame(['min' => 1, 'max' => 1000], Settings::RULES['items.family_bulk_max_rows']);
        self::assertFalse(ApprovalRules::SWITCHES['approvals.item_by_hand_check'], 'extra approvals start off');
        self::assertSame([['switch', 'approvals.item_by_hand_check']], ApprovalRules::PAGE['items']);
        self::assertContains(['document', 'RPK'], ApprovalRules::PAGE['records']);
        self::assertContains('RPK', DocumentRules::SIZE_TYPES);
        self::assertArrayNotHasKey('RPK', DocumentRules::APPROVAL_KIND, 'no blocking approval of its own');
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0031_items_families_repack.sql');
        self::assertStringContainsString(" ('items.pack_suggest_single_sold','bool','true',", $sql, 'the owner\'s Q3 rule: on');
        self::assertStringContainsString(" ('items.repack_carry_cost','bool','true',", $sql);
        self::assertStringContainsString(" ('approvals.item_by_hand_check','bool','false',", $sql, 'off by default');
        self::assertMatchesRegularExpression("/\\('RPK',\\s*'RPK',\\s*'Repack',\\s*'B',\\s*'none', NULL, 'none', NULL, 3, 'reverse', 0,/", $sql, 'no check, no OK first');
    }

    public function testARepacksLinesAndABulkListsNames(): void
    {
        self::assertSame([['sku_id' => 7, 'qty' => -2], ['sku_id' => 9, 'qty' => 20]], RepackHandler::lines('break', 7, 9, 2, 10));
        self::assertSame([['sku_id' => 9, 'qty' => -30], ['sku_id' => 7, 'qty' => 3]], RepackHandler::lines('make', 7, 9, 3, 10));
        self::assertSame(['CW-000001', '2', '5012345678900', 'x'], Families::tokens(" CW-000001\n2 ,5012345678900;\tx\n\n"));
        self::assertSame('Elux Legend', Families::name("  Elux \t Legend "));
        $this->expectException(\CW\CwException::class);
        Families::name('x');
    }
}
