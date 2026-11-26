<?php

declare(strict_types=1);

namespace CW\Items;

use CW\Db;

/**
 * A file of CW item codes, one a line (CW-1153's tools: the 81 items of the go-live kit; docs/decisions.md VB12), read STRICTLY: the whole
 * file is refused when any line is not a CW code, a code is there twice, or a code names no item (or one merged into another); the refusal
 * names the line numbers only. Blank lines are none. An empty file is refused.
 */
final class ItemCodes
{
    public const MAX_LINES = 10_000;

    /** @return list<int> the items' ids, in the file's order */
    public static function fromFile(Db $db, string $path): array
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) > 1_000_000) {
            throw new \InvalidArgumentException('the items file cannot be read (or is over 1 MB)');
        }
        return self::fromText($db, (string) file_get_contents($path));
    }

    /** @return list<int> */
    public static function fromText(Db $db, string $text): array
    {
        $codes = [];
        $bad = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^CW-[0-9]{6,}$/D', $line) !== 1 || isset($codes[$line])) {
                $bad[] = $i + 1;
                continue;
            }
            $codes[$line] = $i + 1;
        }
        if (count($codes) > self::MAX_LINES) {
            throw new \InvalidArgumentException('the items file has more than ' . self::MAX_LINES . ' codes');
        }
        $ids = [];
        foreach (array_chunk(array_keys($codes), 1000, true) as $chunk) {
            foreach ($db->all('SELECT id, code, merged_into_sku_id FROM sku WHERE code IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', array_values($chunk)) as $r) {
                if ($r['merged_into_sku_id'] === null) {
                    $ids[(string) $r['code']] = (int) $r['id'];
                }
            }
        }
        foreach ($codes as $code => $n) {
            if (!isset($ids[$code])) {
                $bad[] = $n;
            }
        }
        if ($bad !== [] || $codes === []) {
            sort($bad);
            throw new \InvalidArgumentException($codes === [] && $bad === [] ? 'the items file has no code'
                : 'the items file is refused whole: line(s) ' . implode(', ', array_slice($bad, 0, 20)) . ' are not codes of items CW has (or appear twice)');
        }
        return array_values(array_map(static fn (string $c): int => $ids[$c], array_keys($codes)));
    }
}
