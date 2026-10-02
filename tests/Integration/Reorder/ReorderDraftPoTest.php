<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Caller;
use CW\Db;
use CW\Idempotency;
use CW\OpResult;
use CW\Reorder\DraftPos;
use CW\Reorder\ReorderList;
use CW\Suppliers\Suppliers;

/**
 * "Create draft PO" from the reorder list (spec §7.4, docs/decisions.md I67): one draft per preferred supplier, the packs the
 * buyer kept or typed, the suggestion in suggested_units, the last price and the supplier's VAT code, items without a usable
 * supplier skipped and listed, the minimum-order warning, one effect per form.
 */
final class ReorderDraftPoTest extends ReorderTestCase
{
    private function drafts(): DraftPos
    {
        return new DraftPos(self::$db, $this->pos, new ReorderList(self::$db, $this->pos));
    }

    public function testOneDraftPerPreferredSupplier(): void
    {
        $s = $this->listScenario();
        $r = $this->drafts()->create($s['buyer'], [['sku_id' => $s['a'], 'packs' => 4], ['sku_id' => $s['b'], 'packs' => 3], ['sku_id' => $s['c'], 'packs' => 20],
            ['sku_id' => $s['d'], 'packs' => 14], ['sku_id' => $s['e'], 'packs' => 1], ['sku_id' => $s['a'] + 1000, 'packs' => 1], ['sku_id' => $s['b'] + 1000, 'packs' => 0]],
            'cw', str_repeat('a', 32));
        self::assertSame([[(int) $s['s1']['id'], 2, 4 * 24 + 3 * 10, '204.00', false], [(int) $s['s2']['id'], 1, 120, '240.00', true]],
            array_map(static fn (array $d): array => [$d['supplier_id'], $d['lines'], $d['units'], $d['net'], $d['below_minimum']], $r['drafts']));
        self::assertSame([[$s['d'], 'no preferred supplier: mark one of its supplier items as preferred'], [$s['e'], 'no preferred supplier: mark one of its supplier items as preferred'],
            [$s['a'] + 1000, 'not on the reorder list']], array_map(static fn (array $x): array => [$x['sku_id'], $x['reason']], $r['skipped']));
        self::assertSame(['The draft for ' . $s['s2']['code'] . ' is £240.00 net, below its minimum order of £1,000.00.'], $r['warnings']);
        $first = $r['drafts'][0]['document_id'];
        self::assertSame(['draft', 'PO', $s['buyer']->staffUserId, 'reorder', (int) $s['s1']['id']], array_values((array) self::$db->one(
            'SELECT d.status, d.doc_type, d.created_by, po.source, po.supplier_id FROM document d JOIN purchase_order po ON po.document_id = d.id WHERE d.id = ?', [$first])));
        self::assertSame([
            [1, $s['a'], (int) $s['si']['a']['id'], 'ELX-A', 'box', 24, 4, '45.0000', 'S', 120, 96],
            [2, $s['b'], (int) $s['si']['b']['id'], 'ELX-B', 'each', 10, 3, '8.0000', 'S', 30, 30],
        ], array_map('array_values', self::$db->all('SELECT pl.line_no, dl.sku_id, pl.supplier_item_id, pl.supplier_code, pl.purchase_unit, pl.units_per_pack, pl.packs, '
            . 'pl.pack_price, pl.vat_code, pl.suggested_units, dl.qty FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no '
            . 'WHERE pl.document_id = ? ORDER BY pl.line_no', [$first])), 'the packs the buyer typed (4 boxes); the suggestion kept (120 = 5 boxes); the last price');
        self::assertSame([120], array_map('intval', self::$db->column('SELECT suggested_units FROM po_line WHERE document_id = ?', [$r['drafts'][1]['document_id']])));
        // The new drafts count as "in drafts" on the list, not as on order.
        $a = array_column((new ReorderList(self::$db, $this->pos))->lines(ReorderList::filters(['show' => 'all'])), null, 'sku_id')[$s['a']];
        self::assertSame([96, 0], [$a['in_drafts'], $a['on_order']]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'reorder.draft_pos'"));
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'po.create'"));
    }

    public function testUnusableSuppliersAndMergedItemsAreSkipped(): void
    {
        $s = $this->listScenario();
        $sup = new Suppliers(self::$db);
        $s2 = (array) self::$db->one('SELECT * FROM supplier WHERE id = ?', [(int) $s['s2']['id']]);
        $sup->deactivate($s['buyer'], (int) $s2['id'], (int) $s2['version'], 'stopped trading');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$s['a'], $s['b']]);
        $r = $this->drafts()->create($s['buyer'], [['sku_id' => $s['a'], 'packs' => 3], ['sku_id' => $s['b'], 'packs' => 3], ['sku_id' => $s['c'], 'packs' => 20]]);
        self::assertSame([(int) $s['s1']['id']], array_column($r['drafts'], 'supplier_id'));
        self::assertSame([[$s['b'], 'merged into ' . sprintf('CW-%06d', $s['a']) . ': order that item instead'], [$s['c'], "its preferred supplier {$s2['code']} is inactive"]],
            array_map(static fn (array $x): array => [$x['sku_id'], $x['reason']], $r['skipped']));
        // Nothing usable: refused, nothing written.
        $before = (int) self::$db->value('SELECT COUNT(*) FROM document');
        $none = $this->drafts()->create($s['buyer'], [['sku_id' => $s['c'], 'packs' => 2]]);
        self::assertSame([], $none['drafts']);
        self::assertSame($before, (int) self::$db->value('SELECT COUNT(*) FROM document'));
        self::refused(422, 'nothing_picked', fn () => $this->drafts()->create($s['buyer'], [['sku_id' => $s['a'], 'packs' => 0]]));
        self::refused(400, 'bad_pick', fn () => $this->drafts()->create($s['buyer'], [['sku_id' => $s['a'], 'packs' => 1], ['sku_id' => $s['a'], 'packs' => 2]]));
        self::refused(400, 'bad_pick', fn () => $this->drafts()->create($s['buyer'], [['sku_id' => $s['a'], 'packs' => -1]]));
        self::refused(403, 'role_not_allowed', fn () => $this->drafts()->create($this->staffUser('reviewer'), [['sku_id' => $s['a'], 'packs' => 1]]));
        self::refused(403, 'admin_cannot_post', fn () => $this->drafts()->create($this->staffUser('admin'), [['sku_id' => $s['a'], 'packs' => 1]]));
    }

    public function testTheSameFormTwiceMakesTheDraftsOnce(): void
    {
        $s = $this->listScenario();
        $picks = [['sku_id' => $s['a'], 'packs' => 3], ['sku_id' => $s['c'], 'packs' => 20]];
        $run = fn (array $p): OpResult => (new Idempotency(self::$db))->run(Caller::staff((int) $s['buyer']->staffUserId), "ui:{$s['buyer']->staffUserId}:" . str_repeat('b', 32),
            'ui.reorder.draft', '/ui/purchasing/reorder/draft', ['picks' => $p, 'stock' => 'cw'], null, null,
            fn (Db $db): OpResult => OpResult::of(303, ['drafts' => array_column($this->drafts()->create($s['buyer'], $p)['drafts'], 'document_id')]));
        $one = $run($picks);
        $two = $run($picks);
        self::assertSame($one->body['drafts'], $two->body['drafts']);
        self::assertCount(2, $one->body['drafts']);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM purchase_order WHERE source = 'reorder'"));
        self::assertSame(422, $run([['sku_id' => $s['a'], 'packs' => 4]])->status, 'the same key with other values');
    }

    public function testSiteStockSuggestionsAreKept(): void
    {
        $s = $this->listScenario();
        $batch = (int) self::$db->value('SELECT id FROM sales_import_batch');
        self::$db->exec("INSERT INTO listing_stock_latest (channel_id, external_variant_id, snapshot_date, stock, stock_mode, sellable, batch_id) VALUES "
            . "(?, '601', '2026-10-01', 100, 'In-Stock', 1, ?)", [$s['vpg'], $batch]);
        $r = $this->drafts()->create($s['buyer'], [['sku_id' => $s['a'], 'packs' => 5]], 'site');
        self::assertSame([96], array_map('intval', self::$db->column('SELECT suggested_units FROM po_line WHERE document_id = ?', [$r['drafts'][0]['document_id']])),
            'the suggestion the buyer saw with the site stock (4 boxes), not the CW one');
    }
}
