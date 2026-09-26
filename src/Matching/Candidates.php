<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * Candidate generation (plan §7.3 step 4, design S6), ported from product_mapping blocking (:983-1073)
 * and score_pair (:1218), used as a PRESCORE only (it never links anything).
 *
 * Blocks (union):
 *  - brand family tokens of the listing's brand (generic words stripped, weak words dropped);
 *  - GTIN crosswalk families (VPG families that the listing's brand maps to through barcode pairs);
 *  - family words found in the listing's title (distributor brands: ALT "Vapes Bars" selling "PIXL 8000");
 *  - rare title tokens (document frequency <= TOKEN_DF_MAX over the seed).
 * Output: top K by prescore, plus the same-product siblings of the top 5 (cap MAX_TOTAL).
 *
 * Prescore changes vs score_pair: brand compared by family (crosswalk family = 15 points);
 * unknown pack is not equal to 1 (both unknown 2 pts, one unknown 2 pts, equal 4 pts);
 * form-class and line-number conflicts cap the score at 40 like strength/puff/pack conflicts.
 */
final class Candidates
{
    public const VERSION = 'c1.0';
    public const TOKEN_DF_MAX = 150;
    public const TOP_K = 15;
    public const SIBLINGS_OF_TOP = 5;
    public const MAX_TOTAL = 25;
    public const MAX_FAMILY_BLOCK = 3000;

    /** @var array<int, array<string,mixed>> */
    private array $seed;
    /** @var array<string, list<int>> */
    private array $byFamily = [];
    /** @var array<string, list<int>> */
    private array $byToken = [];
    /** @var array<int, list<int>> */
    private array $byProduct = [];
    /** @var array<string, list<string>> listing brand_key => seed family tokens */
    private array $crosswalk;
    /** @var array<int, array<string,true>> flipped name tokens */
    private array $tokSet = [];

    /**
     * @param array<int, array<string,mixed>> $seed central items keyed by id (Normalizer features)
     * @param array<string, list<string>> $crosswalk listing brand_key => seed family tokens
     */
    public function __construct(array $seed, array $crosswalk = [])
    {
        $this->seed = $seed;
        $this->crosswalk = $crosswalk;
        $df = [];
        foreach ($seed as $id => $f) {
            foreach ($f['brand_family'] as $t) {
                $this->byFamily[$t][] = $id;
            }
            $this->byProduct[(int) $f['product_id']][] = $id;
            $set = [];
            foreach ($f['name_tokens'] as $t) {
                $set[(string) $t] = true;
                $df[(string) $t][] = $id;
            }
            $this->tokSet[$id] = $set;
        }
        foreach ($df as $t => $ids) {
            if (count($ids) <= self::TOKEN_DF_MAX) {
                $this->byToken[(string) $t] = $ids;
            }
        }
    }

    /** @return list<int> */
    public function siblings(int $id): array
    {
        $p = (int) ($this->seed[$id]['product_id'] ?? 0);
        return array_values(array_filter($this->byProduct[$p] ?? [], fn ($x) => $x !== $id));
    }

    /** Family tokens a listing is blocked on. @return list<string> */
    public function blockFamilies(array $f): array
    {
        $fam = [];
        foreach ($f['brand_family'] as $t) {
            if (isset($this->byFamily[$t])) {
                $fam[$t] = true;
            }
        }
        foreach ($this->crosswalk[$f['brand_key']] ?? [] as $t) {
            if (isset($this->byFamily[$t])) {
                $fam[$t] = true;
            }
        }
        foreach ($f['full_tokens'] as $t) {
            if (strlen($t) >= 3 && isset($this->byFamily[$t]) && !in_array($t, Normalizer::BRAND_WEAK, true)
                && !in_array($t, Normalizer::STOPWORDS, true)) {
                $fam[$t] = true;
            }
        }
        return array_keys($fam);
    }

    /**
     * @param array<string,mixed> $f listing features
     * @param array<int,true> $exclude central ids never to return (rejects, leave-one-out)
     * @return list<array{id:int,prescore:int,breakdown:string,flags:list<string>,via:string}>
     */
    public function generate(array $f, int $k = self::TOP_K, array $exclude = []): array
    {
        $pool = [];
        foreach ($this->blockFamilies($f) as $t) {
            $ids = $this->byFamily[$t];
            if (count($ids) > self::MAX_FAMILY_BLOCK) {
                continue;
            }
            foreach ($ids as $id) {
                $pool[$id] = true;
            }
        }
        foreach ($f['name_tokens'] as $t) {
            foreach ($this->byToken[(string) $t] ?? [] as $id) {
                $pool[$id] = true;
            }
        }
        $scored = [];
        foreach (array_keys($pool) as $id) {
            if (isset($exclude[$id])) {
                continue;
            }
            [$score, $bd, $flags] = $this->prescore($f, $this->seed[$id], $id);
            $scored[] = ['id' => $id, 'prescore' => $score, 'breakdown' => $bd, 'flags' => $flags, 'via' => 'block'];
        }
        usort($scored, fn ($a, $b) => [$b['prescore'], $a['id']] <=> [$a['prescore'], $b['id']]);
        $top = array_slice($scored, 0, $k);
        $have = [];
        foreach ($top as $c) {
            $have[$c['id']] = true;
        }
        foreach (array_slice($top, 0, self::SIBLINGS_OF_TOP) as $c) {
            foreach ($this->siblings($c['id']) as $sid) {
                if (count($top) >= self::MAX_TOTAL) {
                    break 2;
                }
                if (isset($have[$sid]) || isset($exclude[$sid])) {
                    continue;
                }
                [$score, $bd, $flags] = $this->prescore($f, $this->seed[$sid], $sid);
                $top[] = ['id' => $sid, 'prescore' => $score, 'breakdown' => $bd, 'flags' => $flags, 'via' => 'sibling'];
                $have[$sid] = true;
            }
        }
        return $top;
    }

    /**
     * Deterministic prescore 0-100 with conflict caps (port of score_pair).
     *
     * @return array{0:int,1:string,2:list<string>}
     */
    public function prescore(array $a, array $r, ?int $rid = null): array
    {
        $flags = [];
        $caps = [];

        // name: 45 pts = 0.6 x token Jaccard + 0.4 x similar_text%
        $ta = $a['name_tokens'];
        $trSet = $rid !== null ? $this->tokSet[$rid] : array_flip($r['name_tokens']);
        $inter = 0;
        foreach ($ta as $t) {
            if (isset($trSet[(string) $t])) {
                $inter++;
            }
        }
        $union = count($ta) + count($trSet) - $inter;
        $jac = $union > 0 ? $inter / $union : 0.0;
        $pct = 0.0;
        if ($a['name_norm'] !== '' && $r['name_norm'] !== '' && $inter > 0) {
            similar_text($a['name_norm'], $r['name_norm'], $pct);
        }
        $namePts = (int) round(45 * (0.6 * $jac + 0.4 * $pct / 100));

        // brand family: 20 pts
        $af = $a['brand_family'];
        $rf = $r['brand_family'];
        if ($af === [] || $rf === []) {
            $brandPts = 5;
        } elseif (array_intersect($af, $rf) !== []) {
            $brandPts = 20;
        } elseif (array_intersect($this->crosswalk[$a['brand_key']] ?? [], $rf) !== []) {
            $brandPts = 15;
        } else {
            $cross = false;
            foreach ($rf as $t) {
                if (in_array($t, $a['full_tokens'], true)) {
                    $cross = true;
                    break;
                }
            }
            if ($cross) {
                $brandPts = 12;
            } else {
                $brandPts = 0;
                $flags[] = 'brand_mismatch';
                $caps[] = 60;
            }
        }

        // strength: 15 pts
        $as = $a['strength_mg'];
        $rs = $r['strength_mg'];
        if ($as === null && $rs === null) {
            $strPts = 7;
        } elseif ($as === null || $rs === null) {
            $strPts = 4;
        } elseif (abs($as - $rs) <= 0.1) {
            $strPts = 15;
        } else {
            $strPts = 0;
            $flags[] = 'strength_conflict';
            $caps[] = 40;
        }
        if ($a['puffs'] !== null && $r['puffs'] !== null && $a['puffs'] !== $r['puffs']) {
            $flags[] = 'puff_conflict';
            $caps[] = 40;
        }
        if ($a['form_class'] !== null && $r['form_class'] !== null && $a['form_class'] !== $r['form_class']) {
            $flags[] = 'form_conflict';
            $caps[] = 40;
        }
        if ($a['line_numbers'] !== [] && $r['line_numbers'] !== []
            && array_diff($a['line_numbers'], $r['line_numbers']) !== [] && array_diff($r['line_numbers'], $a['line_numbers']) !== []) {
            $flags[] = 'line_number_conflict';
            $caps[] = 40;
        }

        // volume + pack: 10 pts (unknown pack is NOT 1)
        $av = $a['volume_ml'];
        $rv = $r['volume_ml'];
        if ($av === null && $rv === null) {
            $volPts = 3;
        } elseif ($av === null || $rv === null) {
            $volPts = 2;
        } elseif (abs($av - $rv) <= 0.1) {
            $volPts = 6;
        } else {
            $volPts = 0;
            $flags[] = 'volume_diff';
        }
        $ap = $a['pack_units'];
        $rp = $r['pack_units'];
        if ($ap !== null && $rp !== null) {
            if ($ap === $rp) {
                $volPts += 4;
            } else {
                $flags[] = 'pack_conflict';
                $caps[] = 40;
            }
        } else {
            $volPts += 2;
        }

        // unit-price sanity: 10 pts (flags only)
        $pa = $a['unit_price'] ?? 0.0;
        $pr = $r['unit_price'] ?? 0.0;
        if ($pa > 0 && $pr > 0) {
            $ratio = min($pa, $pr) / max($pa, $pr);
            if ($ratio >= 0.6) {
                $pricePts = 10;
            } elseif ($ratio >= 0.4) {
                $pricePts = 5;
            } else {
                $pricePts = 0;
                $flags[] = 'price_outlier';
            }
        } else {
            $pricePts = 5;
        }

        $score = $namePts + $brandPts + $strPts + $volPts + $pricePts;
        if (array_key_exists('product_live', $r) && !$r['product_live']) {
            $score -= 5;
            $flags[] = 'target_not_published';
        }
        foreach ($caps as $cap) {
            $score = min($score, $cap);
        }
        $score = max(0, min(100, $score));
        return [$score, "name:$namePts brand:$brandPts str:$strPts vol:$volPts price:$pricePts", $flags];
    }
}
