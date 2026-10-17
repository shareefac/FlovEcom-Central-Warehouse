<?php

declare(strict_types=1);

namespace CW\Reorder;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Settings;
use CW\SiteWriter\SiteModes;
use CW\Stock;

/**
 * The low-stock figure CW keeps per item (pack G; docs/decisions.md PG7; plan part 3b, row E11 and "five rows" (b): the shop's low-stock
 * levels are no longer refreshed by ERPNext's monthly job; "the buyer owns them, or you accept them as they stand"). The figure is in
 * single items (item_reorder.low_stock_level); the site writer writes floor(figure / units per sale) on each linked variant
 * (SiteWriter\SiteView), unless the selling-mode switch set a figure for that website (that one wins). Empty = each website keeps its own.
 *
 *  apply()  one click on Reorder › Low-stock warnings for the ticked items (at most reorder.low_stock_bulk_max):
 *             'safety'  the figure becomes the item's safety stock now (ReorderMath: spare days × sales a day, at least its smallest
 *                       stock): ERPNext's monthly job, by a person's click;
 *             'keep'    the figure is cleared: each website keeps its own.
 *           The owner's rule (skip anything doubtful, finish the rest, show the skipped ones with the reason): skipped are an item not on
 *           the list, a merged item, an item never suggested (discontinued, "do not reorder", blocked by its card) for 'safety', a safety
 *           stock of 0 (no sales, no smallest stock) or above the figure's maximum, and an item that already has that figure. ONE
 *           transaction: the rows FOR UPDATE in item order (a row is added where the item had none), version + 1, one audit row
 *           reorder.low_stock {action, changed, skipped}, the items' stored lines worked out again (ReorderRefresh::items), then LAST
 *           one feed row per changed item (Stock::skuChanged, the feed clock).
 * Every write: reorder.manage read inside the transaction, never admin (ReorderSettings::manager()).
 */
final class LowStockLevels
{
    public const ACTIONS = ['safety', 'keep'];

    private readonly Settings $settings;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, private readonly ReorderList $list, private readonly ?ReorderRefresh $refresh = null, ?Settings $settings = null,
        ?\Closure $clock = null)
    {
        $this->settings = $settings ?? new Settings($db);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /** Why a ticked item was left out (apply()'s `why`). */
    public const SKIP = ['not_listed', 'merged', 'never', 'zero', 'too_big', 'already'];

    /**
     * @param list<int> $skus the ticked items
     * @return array{changed: list<array{sku_id: int, code: string, before: ?int, after: ?int}>,
     *   skipped: list<array{sku_id: int, code: string, why: string, reason: string}>}
     */
    public function apply(Caller $caller, string $action, array $skus): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new CwException('bad_action', 'the action is safety or keep', 400);
        }
        $ids = array_values(array_unique(array_map('intval', $skus)));
        if ($ids === [] || min($ids) < 1) {
            throw new CwException('nothing_picked', 'tick at least one product', 422);
        }
        $max = ReorderSettings::engine($this->settings)['low_stock_bulk_max'];
        if (count($ids) > $max) {
            throw new CwException('too_many_lines', "at most {$max} products at once", 422, ['max' => $max]);
        }
        sort($ids);
        return $this->db->transaction(function (Db $db) use ($caller, $action, $ids): array {
            (new ReorderSettings($db, $this->clock))->manager($caller);
            // The sku lock also serializes a settings save when item_reorder has no row yet (saveItem takes the same lock).
            $codes = [];
            foreach ($db->all('SELECT id, code FROM sku WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE', $ids) as $r) {
                $codes[(int) $r['id']] = (string) $r['code'];
            }
            $rows = [];
            foreach ($db->all('SELECT sku_id, low_stock_level FROM item_reorder WHERE sku_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY sku_id FOR UPDATE',
                $ids) as $r) {
                $rows[(int) $r['sku_id']] = $r['low_stock_level'] === null ? null : (int) $r['low_stock_level'];
            }
            $lines = [];
            foreach ($this->list->lines(ReorderList::filters(['show' => 'all', 'stock' => 'cw']), $ids) as $l) {
                $lines[(int) $l['sku_id']] = $l;
            }
            $changed = [];
            $skipped = [];
            $now = Clock::db(($this->clock)());
            foreach ($ids as $id) {
                $l = $lines[$id] ?? null;
                $code = $codes[$id] ?? "#{$id}";
                $before = $rows[$id] ?? null;
                $after = $action === 'keep' ? null : ($l === null ? null : (int) $l['safety_stock']);
                $why = match (true) {
                    $l === null => 'not_listed',
                    in_array('merged', $l['flags'], true) => 'merged',
                    $action === 'safety' && $l['never'] !== null => 'never',
                    $action === 'safety' && $after === 0 => 'zero',
                    $action === 'safety' && $after > SiteModes::THRESHOLD_MAX => 'too_big',
                    $before === $after => 'already',
                    default => null,
                };
                if ($why !== null) {
                    $skipped[] = ['sku_id' => $id, 'code' => $code, 'why' => $why, 'reason' => self::reason($why, $l, $after)];
                    continue;
                }
                if (array_key_exists($id, $rows)) {
                    $db->exec('UPDATE item_reorder SET low_stock_level = ?, version = version + 1, updated_by = ?, updated_actor = ?, updated_at = ? WHERE sku_id = ?',
                        [$after, $caller->staffUserId, $caller->actor, $now, $id]);
                } else {
                    $db->exec('INSERT INTO item_reorder (sku_id, low_stock_level, version, updated_by, updated_actor, updated_at) VALUES (?, ?, 1, ?, ?, ?)',
                        [$id, $after, $caller->staffUserId, $caller->actor, $now]);
                }
                $changed[] = ['sku_id' => $id, 'code' => $code, 'before' => $before, 'after' => $after];
            }
            if ($changed !== []) {
                Audit::write($db, $caller, 'reorder.low_stock', 'sku', (string) $changed[0]['sku_id'], null, ['action' => $action,
                    'changed' => array_map(static fn (array $c): array => [$c['code'], $c['before'], $c['after']], $changed),
                    'skipped' => array_column($skipped, 'sku_id')]);
                $this->refresh?->items(array_column($changed, 'sku_id')); // the stored lines, before the feed clock (held to the end)
                $stock = new Stock($db);
                foreach ($changed as $c) {
                    $stock->skuChanged($c['sku_id'], ReorderSettings::FEED_REASON); // LAST: the feed clock (D39)
                }
            }
            return ['changed' => $changed, 'skipped' => $skipped];
        });
    }

    /** The service's sentence for a skipped item (the API's; the screens word it by `why`). @param array<string, mixed>|null $l */
    private static function reason(string $why, ?array $l, ?int $after): string
    {
        return match ($why) {
            'not_listed' => 'not on the reorder list',
            'merged' => (string) ($l['never'] ?? 'merged') . ': change that item instead',
            'never' => 'never suggested (' . (string) ($l['never'] ?? '') . '): no low-stock warning is set for it',
            'zero' => 'its safety stock is 0 (no sales and no smallest stock): nothing to warn about',
            'too_big' => 'its safety stock is above ' . number_format(SiteModes::THRESHOLD_MAX) . ': set it by hand',
            default => $after === null ? 'each website keeps its own figure already' : "its low-stock figure is {$after} already",
        };
    }
}
