<?php

declare(strict_types=1);

namespace CW\SiteWriter;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Receiving\SellingModes;
use CW\Settings;
use CW\Stock;

/**
 * The selling mode CW writes on each site for a legacy (not yet counted) item, with its low-stock threshold (IM10 "selling-mode
 * switch"; docs/decisions.md I148-I166). One item_channel_mode row per (item, site) CW has set, every write logged
 * (item_channel_mode_log, append-only; W1). Counted (protected) items follow their policy on every site instead (plan §7.4), so
 * the switch refuses them.
 *
 *  current()      the rows of some items (optionally some sites), keyed "sku:channel".
 *  set()          the switch (Items > an item > "Selling mode on the websites"): one mode for the ticked sites (or every site),
 *                 optionally a new low-stock threshold, with a reason; the rows FOR UPDATE, a stamp of what the person saw (409
 *                 selling_mode_changed when someone changed them meanwhile), audit selling_mode.set, then LAST one feed row for
 *                 the item (Stock::skuChanged 'mode': the feed clock), so every linked listing is re-sent.
 *  fromReceipt()  a posted receipt's modes (GoodsReceiptHandler, I136): for the sites in site_writer.receipt_mode_sites (Vape and Go
 *                 until the owner says otherwise), the item's mode on the receipt becomes its mode there (source receipt, the
 *                 threshold kept); only for the items it accepts something of into MAIN (I169). Called inside the posting before its stock locks; returns the items whose site mode changed (the
 *                 posting writes their feed rows last).
 *
 * Out-Of-Stock keeps the mode the item had before on that site (previous_mode), as item_selling_mode does, so a later receipt
 * brings it back (SellingModes::resolve reads the newest of the item's row and these rows, I157).
 * Lock order: item_selling_mode rows (a posting's plan) -> item_channel_mode rows (sku, channel order) -> stock balances -> feed clock.
 */
final class SiteModes
{
    public const THRESHOLD_MAX = 100000;

    public function __construct(private readonly Db $db)
    {
    }

    public static function key(int $skuId, int $channelId): string
    {
        return $skuId . ':' . $channelId;
    }

    /**
     * @param list<int> $skuIds
     * @param list<int>|null $channelIds null = every site
     * @return array<string, array{sku_id: int, channel_id: int, mode: string, previous: ?string, threshold: ?int, version: int, source: string,
     *     document_id: ?int, updated_by: ?int, updated_actor: string, updated_at: string}>
     */
    public function current(array $skuIds, ?array $channelIds = null, bool $lock = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $skuIds)));
        sort($ids);
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $params = $chunk;
            $sql = 'SELECT sku_id, channel_id, mode, previous_mode, low_stock_threshold, version, source, document_id, updated_by, updated_actor, updated_at FROM item_channel_mode '
                . 'WHERE sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')';
            if ($channelIds !== null) {
                if ($channelIds === []) {
                    return [];
                }
                $sql .= ' AND channel_id IN (' . implode(', ', array_fill(0, count($channelIds), '?')) . ')';
                array_push($params, ...array_map('intval', $channelIds));
            }
            foreach ($this->db->all($sql . ' ORDER BY sku_id, channel_id' . ($lock ? ' FOR UPDATE' : ''), $params) as $r) {
                $out[self::key((int) $r['sku_id'], (int) $r['channel_id'])] = [
                    'sku_id' => (int) $r['sku_id'], 'channel_id' => (int) $r['channel_id'], 'mode' => (string) $r['mode'],
                    'previous' => $r['previous_mode'] === null ? null : (string) $r['previous_mode'],
                    'threshold' => $r['low_stock_threshold'] === null ? null : (int) $r['low_stock_threshold'], 'version' => (int) $r['version'],
                    'source' => (string) $r['source'], 'document_id' => $r['document_id'] === null ? null : (int) $r['document_id'],
                    'updated_by' => $r['updated_by'] === null ? null : (int) $r['updated_by'], 'updated_actor' => (string) $r['updated_actor'],
                    'updated_at' => (string) $r['updated_at'],
                ];
            }
        }
        return $out;
    }

    /** The channels a receipt's mode lands on (site_writer.receipt_mode_sites): id => code, unknown codes left out. @return array<int, string> */
    public function receiptChannels(?Settings $settings = null): array
    {
        $settings ??= new Settings($this->db);
        $raw = $settings->has('site_writer.receipt_mode_sites') ? (string) $settings->get('site_writer.receipt_mode_sites') : '';
        $codes = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $c): bool => $c !== ''));
        if ($codes === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all('SELECT id, code FROM channel WHERE code IN (' . implode(', ', array_fill(0, count($codes), '?')) . ') ORDER BY id', $codes) as $r) {
            $out[(int) $r['id']] = (string) $r['code'];
        }
        return $out;
    }

    /**
     * A stamp of an item's rows as a screen showed them (the version of each site's row), for set()'s 409.
     *
     * @param array<string, array{channel_id: int, version: int}> $rows current() of one item
     */
    public static function stamp(array $rows): string
    {
        $parts = [];
        foreach ($rows as $r) {
            $parts[(int) $r['channel_id']] = (int) $r['channel_id'] . '.' . (int) $r['version'];
        }
        ksort($parts);
        return substr(sha1('ics:' . implode(',', $parts)), 0, 16);
    }

    /**
     * The switch (class docblock). $sites: channel codes, or ['*'] for every site. $threshold: '' = keep, else a whole number
     * 0..THRESHOLD_MAX. $stamp: stamp() of the rows the person saw (null: not checked, the CLI). 400 bad_mode / bad_reason /
     * bad_threshold · 404 unknown_item · 409 merged_item / protected_item / selling_mode_changed · 422 no_sites / unknown_site.
     *
     * @param list<string> $sites
     * @return array{changed: list<string>, unchanged: list<string>, mode: string, threshold: ?int}
     */
    public function set(Caller $caller, int $skuId, string $mode, array $sites, string $threshold, string $reason, ?string $stamp = null): array
    {
        if (!in_array($mode, SellingModes::MODES, true)) {
            throw new CwException('bad_mode', 'Choose In-Stock, From-Warehouse or Out-Of-Stock.', 400);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500 || !mb_check_encoding($reason, 'UTF-8')) {
            throw new CwException('bad_reason', 'Say in 3 to 500 characters why the selling mode changes.', 400);
        }
        $newThreshold = null;
        $threshold = trim($threshold);
        if ($threshold !== '') {
            if (preg_match('/^\d{1,6}$/D', $threshold) !== 1 || (int) $threshold > self::THRESHOLD_MAX) {
                throw new CwException('bad_threshold', 'The low-stock threshold is a whole number from 0 to ' . self::THRESHOLD_MAX . ' (leave it empty to keep it).', 400);
            }
            $newThreshold = (int) $threshold;
        }
        $sites = array_values(array_unique(array_filter(array_map('trim', $sites), static fn (string $s): bool => $s !== '')));
        if ($sites === []) {
            throw new CwException('no_sites', 'Tick the websites the selling mode is for (or "all websites").', 422);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $skuId, $mode, $sites, $newThreshold, $reason, $stamp): array {
            // No lock on the sku row (a posting locks item_channel_mode, then balances; setPolicy balances, then sku): a policy committing
            // meanwhile only makes this row unused (a protected item follows its policy).
            $sku = $db->one('SELECT id, code, sell_policy, merged_into_sku_id FROM sku WHERE id = ?', [$skuId])
                ?? throw new CwException('unknown_item', 'no such item', 404);
            if ($sku['merged_into_sku_id'] !== null) {
                throw new CwException('merged_item', "{$sku['code']} was merged into another item: change the selling mode there.", 409);
            }
            if ((string) $sku['sell_policy'] !== 'legacy') {
                throw new CwException('protected_item', "{$sku['code']} is counted and protected: its selling mode on every website follows its policy ("
                    . $sku['sell_policy'] . '). Change the policy instead (plan §7.4).', 409, ['policy' => (string) $sku['sell_policy']]);
            }
            $all = [];
            foreach ($db->all('SELECT id, code FROM channel ORDER BY id') as $c) {
                $all[(string) $c['code']] = (int) $c['id'];
            }
            if (in_array('*', $sites, true)) {
                $targets = $all;
            } else {
                $targets = [];
                foreach ($sites as $code) {
                    if (!isset($all[$code])) {
                        throw new CwException('unknown_site', 'There is no website ' . mb_substr($code, 0, 32) . '.', 422);
                    }
                    $targets[$code] = $all[$code];
                }
                asort($targets);
            }
            if ($targets === []) {
                throw new CwException('no_sites', 'There is no website to set the selling mode on.', 422);
            }
            $rows = $this->current([$skuId], null, true);
            if ($stamp !== null && !hash_equals(self::stamp($rows), $stamp)) {
                throw new CwException('selling_mode_changed', 'Someone changed this item\'s selling mode a moment ago: look at it again.', 409);
            }
            $now = Clock::db(Clock::now());
            $changed = [];
            $unchanged = [];
            foreach ($targets as $code => $cid) {
                $cur = $rows[self::key($skuId, $cid)] ?? null;
                $prev = SellingModes::previousFor(['mode' => $cur['mode'] ?? null, 'previous' => $cur['previous'] ?? null], $mode);
                $th = $newThreshold ?? ($cur['threshold'] ?? null);
                if ($cur !== null && $cur['mode'] === $mode && $cur['previous'] === $prev && $cur['threshold'] === $th) {
                    $unchanged[] = $code;
                    continue;
                }
                $this->write($db, $caller, $skuId, $cid, $cur, $mode, $prev, $th, 'switch', null, null, $reason, $now);
                $changed[] = $code;
            }
            if ($changed !== []) {
                Audit::write($db, $caller, 'selling_mode.set', 'sku', (string) $skuId, null, ['code' => $sku['code'], 'mode' => $mode, 'sites' => $changed,
                    'unchanged' => $unchanged, 'threshold' => $newThreshold, 'reason' => $reason]);
                (new Stock($db))->skuChanged($skuId, 'mode'); // LAST: the feed clock (D39)
            }
            return ['changed' => $changed, 'unchanged' => $unchanged, 'mode' => $mode, 'threshold' => $newThreshold];
        });
    }

    /**
     * A posted receipt's modes for the receipt sites (class docblock). $writes: sku_id => ['mode', 'line_no'] (one per item the
     * receipt accepts something of, GoodsReceiptHandler step 4, I169). Inside the posting's transaction, before its stock locks;
     * $now: the posting's time. Returns the items whose mode changed on some site.
     *
     * @param array<int, array{mode: string, line_no: int}> $writes
     * @return list<int>
     */
    public function fromReceipt(Caller $caller, int $documentId, array $writes, ?Settings $settings = null, ?string $now = null): array
    {
        $channels = $this->receiptChannels($settings);
        if ($channels === [] || $writes === []) {
            return [];
        }
        ksort($writes);
        $rows = $this->current(array_keys($writes), array_keys($channels), true);
        $now ??= Clock::db(Clock::now()); // the posting's clock (SellingModes::current compares these times)
        $changed = [];
        foreach ($writes as $sku => $w) {
            foreach (array_keys($channels) as $cid) {
                $cur = $rows[self::key((int) $sku, $cid)] ?? null;
                $prev = SellingModes::previousFor(['mode' => $cur['mode'] ?? null, 'previous' => $cur['previous'] ?? null], $w['mode']);
                $this->write($this->db, $caller, (int) $sku, $cid, $cur, $w['mode'], $prev, $cur['threshold'] ?? null, 'receipt', $documentId, $w['line_no'], null, $now);
                if ($cur === null || $cur['mode'] !== $w['mode']) {
                    $changed[(int) $sku] = true;
                }
            }
        }
        return array_map('intval', array_keys($changed));
    }

    /** @param array<string, mixed>|null $cur */
    private function write(Db $db, Caller $caller, int $skuId, int $channelId, ?array $cur, string $mode, ?string $prev, ?int $threshold, string $source,
        ?int $documentId, ?int $lineNo, ?string $reason, string $now): void
    {
        $db->exec(
            'INSERT INTO item_channel_mode (sku_id, channel_id, mode, previous_mode, low_stock_threshold, version, source, document_id, updated_by, updated_actor, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?) AS new ON DUPLICATE KEY UPDATE mode = new.mode, previous_mode = new.previous_mode, '
            . 'low_stock_threshold = new.low_stock_threshold, version = item_channel_mode.version + 1, source = new.source, document_id = new.document_id, '
            . 'updated_by = new.updated_by, updated_actor = new.updated_actor, updated_at = new.updated_at',
            [$skuId, $channelId, $mode, $prev, $threshold, $source, $documentId, $caller->staffUserId, $caller->actor, $now],
        );
        $version = (int) $db->value('SELECT version FROM item_channel_mode WHERE sku_id = ? AND channel_id = ?', [$skuId, $channelId]);
        $db->exec(
            'INSERT INTO item_channel_mode_log (sku_id, channel_id, version, mode_before, mode_after, previous_mode, threshold_before, threshold_after, source, reason, '
            . 'document_id, line_no, actor, staff_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$skuId, $channelId, $version, $cur['mode'] ?? null, $mode, $prev, $cur['threshold'] ?? null, $threshold, $source, $reason, $documentId, $lineNo,
                $caller->actor, $caller->staffUserId, $now],
        );
    }
}
