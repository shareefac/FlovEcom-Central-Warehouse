<?php

declare(strict_types=1);

namespace CW\SiteWriter;

use CW\Db;

/**
 * The nightly checks of the per-site selling modes (0018, IM10; called at the end of CW\Invariants::check, so bin/invariants.php,
 * the hammer and every stock test run them; docs/decisions.md I151, I163). Read-only; at most MAX_PER_CHECK violations per check.
 *
 *  W1. every item_channel_mode row equals its newest log row (mode, previous mode, low-stock threshold, version, source, receipt),
 *      and each (item, site)'s log versions are 1..n with a row behind them: the app login may UPDATE the row, never the log.
 *  W2. every row and log row names an existing item and site, and a receipt's names a GRN document.
 */
final class SiteWriterInvariants
{
    private const MAX_PER_CHECK = 50;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        return [...self::rows($db), ...self::logs($db), ...self::names($db)];
    }

    /** @return list<string> W1: the row is its newest log row */
    private static function rows(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT m.sku_id, m.channel_id, m.mode, m.previous_mode, m.low_stock_threshold, m.version, m.source, m.document_id, l.id AS l_id, '
            . 'l.mode_after, l.previous_mode AS l_previous, l.threshold_after, l.version AS l_version, l.source AS l_source, l.document_id AS l_document '
            . 'FROM item_channel_mode m LEFT JOIN item_channel_mode_log l ON l.sku_id = m.sku_id AND l.channel_id = m.channel_id '
            . 'AND l.version = (SELECT MAX(x.version) FROM item_channel_mode_log x WHERE x.sku_id = m.sku_id AND x.channel_id = m.channel_id) '
            . 'WHERE l.id IS NULL OR NOT (m.mode <=> l.mode_after) OR NOT (m.previous_mode <=> l.previous_mode) '
            . 'OR NOT (m.low_stock_threshold <=> l.threshold_after) OR m.version <> l.version OR m.source <> l.source OR NOT (m.document_id <=> l.document_id) '
            . 'ORDER BY m.sku_id, m.channel_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "item_channel_mode of item {$r['sku_id']} on site {$r['channel_id']} ({$r['mode']}, previous " . ($r['previous_mode'] ?? 'none')
                . ', threshold ' . ($r['low_stock_threshold'] ?? 'none') . ", version {$r['version']}, {$r['source']}) is not its newest log row ("
                . ($r['l_id'] === null ? 'none' : ($r['mode_after'] . ', previous ' . ($r['l_previous'] ?? 'none') . ', threshold ' . ($r['threshold_after'] ?? 'none')
                    . ", version {$r['l_version']}, {$r['l_source']}")) . ')';
        }
        return $v;
    }

    /** @return list<string> W1: the log's versions */
    private static function logs(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT l.sku_id, l.channel_id, COUNT(*) AS n, MIN(l.version) AS lo, MAX(l.version) AS hi, MAX(m.sku_id IS NULL) AS orphan '
            . 'FROM item_channel_mode_log l LEFT JOIN item_channel_mode m ON m.sku_id = l.sku_id AND m.channel_id = l.channel_id '
            . 'GROUP BY l.sku_id, l.channel_id HAVING n <> hi OR lo <> 1 OR orphan = 1 ORDER BY l.sku_id, l.channel_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "item_channel_mode_log of item {$r['sku_id']} on site {$r['channel_id']}: versions {$r['lo']}..{$r['hi']} in {$r['n']} rows"
                . ((int) $r['orphan'] === 1 ? ', without its item_channel_mode row' : '');
        }
        return $v;
    }

    /** @return list<string> W2 */
    private static function names(Db $db): array
    {
        $v = [];
        foreach (['item_channel_mode', 'item_channel_mode_log'] as $t) {
            foreach ($db->all(
                "SELECT DISTINCT t.sku_id, t.channel_id, s.id IS NULL AS no_sku, c.id IS NULL AS no_channel, "
                . "(t.document_id IS NOT NULL AND (d.id IS NULL OR d.doc_type <> 'GRN')) AS bad_doc FROM {$t} t "
                . 'LEFT JOIN sku s ON s.id = t.sku_id LEFT JOIN channel c ON c.id = t.channel_id LEFT JOIN document d ON d.id = t.document_id '
                . "WHERE s.id IS NULL OR c.id IS NULL OR (t.document_id IS NOT NULL AND (d.id IS NULL OR d.doc_type <> 'GRN')) "
                . 'ORDER BY t.sku_id, t.channel_id LIMIT ' . self::MAX_PER_CHECK,
            ) as $r) {
                $v[] = "{$t} of item {$r['sku_id']} on site {$r['channel_id']}: " . implode(', ', array_keys(array_filter([
                    'there is no such item' => (int) $r['no_sku'] === 1, 'there is no such site' => (int) $r['no_channel'] === 1,
                    'its receipt is not a GRN document' => (int) $r['bad_doc'] === 1])));
            }
        }
        return $v;
    }
}
