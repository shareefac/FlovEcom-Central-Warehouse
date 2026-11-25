<?php

declare(strict_types=1);

namespace CW\Ai;

use CW\Clock;
use CW\Db;

/**
 * What Claude's reading costs (docs/decisions.md AI8): each call's tokens at the model's prices (settings, pounds per million tokens), kept in
 * thousandths of a penny on its log row (ai_call.cost_millipence; a call of a one-page invoice is about 3p, so pennies alone would round most
 * of it away), rounded UP (never under-counted); the month is the UK month (Europe/London), its sum in pence rounded up. The limit (setting
 * ai.monthly_limit_gbp) is reached when the month's sum is at it or above: no new reading starts that month (uploading as before still works).
 *
 * £ example: a 5-page Royal Mail bill of 160 lines read with 15,000 tokens in and 13,000 out at £3.20 / £16.00 per million costs
 * 15,000 x 3.20 / 10 + 13,000 x 16.00 / 10 = 4,800 + 20,800 = 25,600 thousandths of a penny = 25.6p; 100 such bills a month = £25.60, under £30.
 */
final class AiUsage
{
    public const UK = 'Europe/London';

    /** One call's cost in thousandths of a penny: (in x price_in + out x price_out) / 10, rounded up (bcmath: no float). */
    public static function millipence(int $in, int $out, string $priceIn, string $priceOut): int
    {
        $p = static fn (string $v): string => preg_match('/^\d{1,8}(\.\d{1,6})?$/D', $v) === 1 ? $v : '0';
        $sum = bcadd(bcmul((string) max(0, $in), $p($priceIn), 6), bcmul((string) max(0, $out), $p($priceOut), 6), 6);
        $tenth = bcdiv($sum, '10', 6);
        $whole = bcadd($tenth, '0', 0);
        return (int) (bccomp($tenth, $whole, 6) > 0 ? bcadd($whole, '1', 0) : $whole);
    }

    /** Thousandths of a penny as pence, rounded up. */
    public static function pence(int $millipence): int
    {
        return intdiv(max(0, $millipence) + 999, 1000);
    }

    /**
     * This UK month's reading: the calls made (documents), the ones that read a document (ok), and what they cost in pence (rounded up).
     *
     * @return array{calls: int, documents: int, pence: int, from: string}
     */
    public static function month(Db $db, ?\DateTimeImmutable $now = null): array
    {
        $uk = ($now ?? Clock::now())->setTimezone(new \DateTimeZone(self::UK));
        $first = new \DateTimeImmutable($uk->format('Y-m-01') . ' 00:00:00', new \DateTimeZone(self::UK));
        $from = Clock::db($first->setTimezone(Clock::utc()));
        $r = $db->one("SELECT COUNT(*) AS calls, COALESCE(SUM(result = 'ok'), 0) AS ok, COALESCE(SUM(cost_millipence), 0) AS mp FROM ai_call WHERE created_at >= ?",
            [$from]) ?? ['calls' => 0, 'ok' => 0, 'mp' => 0];
        return ['calls' => (int) $r['calls'], 'documents' => (int) $r['ok'], 'pence' => self::pence((int) $r['mp']), 'from' => $from];
    }
}
