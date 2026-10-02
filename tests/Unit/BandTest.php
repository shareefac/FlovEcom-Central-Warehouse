<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Mapping\KeySample;
use CW\Matching\Band;
use CW\Matching\StoredBand;
use PHPUnit\Framework\TestCase;

/**
 * M26 (the owner, 2 Oct 2026): Key from judge confidence 85 when the barcode/transfer key and the blind judge agree; every
 * other Key condition unchanged. StoredBand replays a stored proposal's evidence (Reband, KeyEligibility), and KeySample's
 * allocation and draw are pure (M28).
 */
final class BandTest extends TestCase
{
    /** @param array<string, mixed> $over @return array<string, mixed> */
    private static function keyEv(array $over = []): array
    {
        return $over + ['lane' => 'barcode', 'target' => 7, 'target_vetoes' => [], 'target_flags' => [],
            'candidates' => [['id' => 7, 'prescore' => 90, 'vetoes' => []], ['id' => 8, 'prescore' => 70, 'vetoes' => []]]];
    }

    /** @return array{outcome: string, chosen_id: ?int, confidence: int, units_per_item: ?int} */
    private static function judge(int $conf, ?int $chosen = 7, int $units = 1, string $outcome = 'match'): array
    {
        return ['outcome' => $outcome, 'chosen_id' => $chosen, 'confidence' => $conf, 'units_per_item' => $units];
    }

    public function testKeyFrom85WhenTheKeyAndTheJudgeAgree(): void
    {
        self::assertSame(['b2.1', 85], [Band::VERSION, Band::KEY_MIN_CONFIDENCE]);
        self::assertSame(['b2.0' => 90, 'b2.1' => 85], Band::KEY_MIN_BY_VERSION);
        foreach ([85, 86, 87, 88, 89, 90, 99] as $conf) {
            self::assertSame(Band::KEY, Band::final(self::keyEv(), self::judge($conf))['band'], "barcode, {$conf}");
            self::assertSame(Band::KEY, Band::final(self::keyEv(['lane' => 'transfer']), self::judge($conf))['band'], "transfer, {$conf}");
        }
        self::assertSame(['barcode_key+ai_87'], Band::final(self::keyEv(), self::judge(87))['reasons']);
        self::assertSame(Band::CHECK, Band::final(self::keyEv(), self::judge(84))['band']);
        self::assertSame(Band::CHECK, Band::final(self::keyEv(), self::judge(70))['band']);
        // b2.0's rule, replayed on stored evidence: 85-89 was Check
        self::assertSame(Band::CHECK, Band::final(self::keyEv(), self::judge(89), Band::KEY_MIN_BY_VERSION['b2.0'])['band']);
        self::assertSame(Band::KEY, Band::final(self::keyEv(), self::judge(90), Band::KEY_MIN_BY_VERSION['b2.0'])['band']);
    }

    public function testEveryOtherKeyConditionIsUnchanged(): void
    {
        $cands = [['id' => 7, 'prescore' => 95, 'vetoes' => []]];
        // 85-89 without a key: the candidates lane is at most Check, whatever the confidence
        foreach ([85, 88, 89, 99] as $conf) {
            self::assertSame(Band::CHECK, Band::final(['lane' => 'candidates', 'candidates' => $cands], self::judge($conf))['band'], "candidates, {$conf}");
        }
        self::assertSame(Band::CHECK, Band::final(self::keyEv(['target' => null, 'lane' => 'barcode']), self::judge(88))['band'], 'a key lane without a single target');
        // vetoed: never Key, at any confidence (a vetoed key target is a Conflict; a judge match on a vetoed pair too)
        foreach ([85, 88, 99, 100] as $conf) {
            self::assertSame(Band::CONFLICT, Band::final(self::keyEv(['target_vetoes' => [['code' => 'strength']]]), self::judge($conf))['band']);
            self::assertSame(Band::CONFLICT, Band::final(['lane' => 'candidates', 'candidates' => [['id' => 7, 'prescore' => 99, 'vetoes' => [['code' => 'nic_type']]]]],
                self::judge($conf))['band']);
        }
        self::assertSame(Band::CHECK, Band::final(self::keyEv(['target_flags' => ['price_outlier']]), self::judge(88))['band'], 'a soft flag');
        self::assertSame(Band::CANT_TELL, Band::final(self::keyEv(['target_flags' => ['line_alias_pending']]), self::judge(88))['band'], 'a pending alias');
        self::assertSame(Band::CANT_TELL, Band::final(self::keyEv(['pending_alias' => true]), self::judge(95))['band']);
        self::assertSame(Band::CHECK, Band::final(self::keyEv(), self::judge(88, 7, 2))['band'], 'units per item 2');
        self::assertSame(Band::CONFLICT, Band::final(self::keyEv(), self::judge(88, 8))['band'], 'the judge picks another item');
        self::assertSame(Band::CONFLICT, Band::final(self::keyEv(['flags' => ['multi_sku_gtin']]), self::judge(99))['band']);
        self::assertSame(Band::CONFLICT, Band::final(self::keyEv(), self::judge(85, null, 1, 'no_match_in_list'))['band']);
    }

    /** M29, run3 follow-up (e): b2.0 fell through to `unrecognised_outcome` here (70-79) and assemble.php relabelled it. */
    public function testKeyLaneNoMatchBelow80HasItsOwnReason(): void
    {
        foreach ([79, 72, 70, 69, 0] as $conf) {
            $r = Band::final(self::keyEv(), self::judge($conf, null, 1, 'no_match_in_list'));
            self::assertSame(['band' => Band::CANT_TELL, 'reasons' => ['ai_no_match_on_key_below_80_' . $conf]], $r, "barcode {$conf}");
            self::assertSame(['ai_no_match_on_key_below_80_' . $conf],
                Band::final(self::keyEv(['lane' => 'transfer']), self::judge($conf, null, 1, 'no_match_in_list'))['reasons'], "transfer {$conf}");
        }
        self::assertSame(['ai_no_match_on_key'], Band::final(self::keyEv(), self::judge(80, null, 1, 'no_match_in_list'))['reasons']);
        // unrecognised_outcome now means an outcome outside Band::OUTCOMES only, at any confidence
        foreach ([95, 75, 30] as $conf) {
            self::assertSame(['band' => Band::CANT_TELL, 'reasons' => ['unrecognised_outcome']], Band::final(self::keyEv(), self::judge($conf, null, 1, 'probably')));
        }
        self::assertSame(['match', 'no_match_in_list', 'cannot_tell', 'multiple_plausible', 'not_a_product'], Band::OUTCOMES);
        // a stored run3 proposal (assemble.php's label) replays to the same reasons
        $ev = self::evidence(72, ['band' => "Can't tell", 'band_reasons' => ['ai_no_match_on_key_below_80_72']],
            ['outcome' => 'no_match_in_list', 'chosen' => null, 'units_per_item' => null]);
        self::assertSame(['band' => "Can't tell", 'reasons' => ['ai_no_match_on_key_below_80_72'], 'error' => null], StoredBand::evaluate($ev, 90));
        self::assertSame(['band' => "Can't tell", 'reasons' => ['ai_no_match_on_key_below_80_72'], 'error' => null], StoredBand::evaluate($ev, 85));
    }

    public function testVersionOf(): void
    {
        self::assertSame('b2.0', Band::versionOf('n2.0/c1.0/v2.0/b2.0'));
        self::assertSame('b2.1', Band::versionOf('reband/b2.1'));
        self::assertSame('b2.0', Band::versionOf('b2.0'));
        self::assertNull(Band::versionOf('n2.0/c1.0/v2.0/b1.0'));
        self::assertNull(Band::versionOf(null));
    }

    /** @param array<string, mixed> $over @param array<string, mixed> $ai @return array<string, mixed> stored first-match evidence (import_proposals shape) */
    public static function evidence(int $conf = 87, array $over = [], array $ai = []): array
    {
        $item = ['cw_id' => 'CWP-12723', 'vpg_variant_id' => 12723, 'title' => 'Mr Blue 10mg', 'sku_id' => 3];
        return $over + ['run_id' => 'run3t-sold', 'band' => 'Check', 'band_reasons' => ['key_with_flags_or_low_conf_' . $conf], 'lane' => 'barcode',
            'lane_target' => $item, 'lane_flags' => [], 'target_vetoes' => [], 'target_soft_flags' => [], 'key_possible' => true, 'key_blocked_by' => [],
            'relabel_pending' => null, 'relabel_partners' => [],
            'ai' => $ai + ['outcome' => 'match', 'confidence' => $conf, 'units_per_item' => 1, 'reason' => 'same', 'chosen' => $item, 'closest' => null,
                'fields_not_agree' => [], 'vetoes_on_chosen' => [], 'soft_flags_on_chosen' => [], 'warnings' => [], 'model' => 'claude-sonnet-5-5'],
            'candidates' => [['ref' => 'C1', 'cw_id' => 'CWP-12723', 'sku_id' => 3, 'role' => 'lane_target', 'prescore' => 92, 'vetoes' => [], 'soft_flags' => []],
                ['ref' => 'C2', 'cw_id' => 'CWP-26627', 'sku_id' => 4, 'role' => 'search', 'prescore' => 60, 'vetoes' => ['flavour_diff'], 'soft_flags' => []]]];
    }

    public function testStoredEvidenceReplaysUnderEitherVersion(): void
    {
        self::assertSame(['Check', 'Key'], [StoredBand::evaluate(self::evidence(87), 90)['band'], StoredBand::evaluate(self::evidence(87), 85)['band']]);
        self::assertSame(['Key', 'Key'], [StoredBand::evaluate(self::evidence(95), 90)['band'], StoredBand::evaluate(self::evidence(95), 85)['band']]);
        self::assertSame('Check', StoredBand::evaluate(self::evidence(84), 85)['band']);
        // the routing on top of Band (assemble.php) and the clean-evidence guard
        self::assertSame('Check', StoredBand::evaluate(self::evidence(88, [], ['warnings' => ['quote_not_verbatim: brand']]), 85)['band']);
        self::assertSame('Manual', StoredBand::evaluate(self::evidence(88, ['relabel_pending' => 'Crystal Pro Max = Hayati Pro Max']), 85)['band']);
        self::assertSame('Manual', StoredBand::evaluate(self::evidence(88, ['relabel_partners' => [['cw_id' => 'CWP-12723', 'note' => 'x']]]), 85)['band']);
        self::assertSame('Check', StoredBand::evaluate(self::evidence(88, ['key_possible' => false]), 85)['band']);
        self::assertSame('Check', StoredBand::evaluate(self::evidence(88, ['key_blocked_by' => ['ceiling:Check']]), 85)['band']);
        self::assertSame('Check', StoredBand::evaluate(self::evidence(88, [], ['soft_flags_on_chosen' => ['price_outlier']]), 85)['band']);
        self::assertSame('Check', StoredBand::evaluate(self::evidence(88, ['target_soft_flags' => ['price_outlier']]), 85)['band']);
        self::assertSame('Conflict', StoredBand::evaluate(self::evidence(99, ['target_vetoes' => ['strength']]), 85)['band']);
        self::assertSame('Check', StoredBand::evaluate(self::evidence(88, ['lane' => 'candidates', 'lane_target' => null]), 85)['band']);
        self::assertSame('Check', StoredBand::evaluate(self::evidence(88, [], ['units_per_item' => 2]), 85)['band']);
        $other = ['cw_id' => 'CWP-26627', 'sku_id' => 4];
        self::assertSame('Conflict', StoredBand::evaluate(self::evidence(88, [], ['chosen' => $other]), 85)['band'], 'AI match on a vetoed candidate');
        self::assertSame("Can't tell", StoredBand::evaluate(self::evidence(45), 85)['band'], 'a match below 50 is cannot_tell');
        self::assertSame('Conflict', StoredBand::evaluate(self::evidence(85, [], ['outcome' => 'no_match_in_list', 'chosen' => null]), 85)['band']);
        // evidence without the first-match shape
        self::assertSame([null, 'evidence_unreadable'], array_values(array_intersect_key(StoredBand::evaluate([], 85), ['band' => 1, 'error' => 1])));
        self::assertNull(StoredBand::evaluate(['lane' => 'barcode', 'ai' => null], 85)['band']);
        self::assertNull(StoredBand::evaluate(['lane' => 'barcode', 'ai' => ['outcome' => 'match', 'confidence' => '88']], 85)['band']);
    }

    public function testSampleAllocationIsProportionalWithAtLeastOnePerStratum(): void
    {
        self::assertSame([['name' => 'conf_90_100', 'min' => 90, 'max' => 100], ['name' => 'conf_85_89', 'min' => 85, 'max' => 89]], KeySample::strata());
        self::assertSame(['hi' => 14, 'lo' => 6], KeySample::allocate(['hi' => 936, 'lo' => 435], 20), 'staging-like figures: about 14 and 6');
        self::assertSame(['hi' => 14, 'lo' => 6], KeySample::allocate(['hi' => 21, 'lo' => 9], 20));
        self::assertSame(['hi' => 19, 'lo' => 1], KeySample::allocate(['hi' => 1000, 'lo' => 3], 20), 'at least one from a small stratum');
        self::assertSame(['hi' => 20, 'lo' => 0], KeySample::allocate(['hi' => 500, 'lo' => 0], 20));
        self::assertSame(['hi' => 1, 'lo' => 4], KeySample::allocate(['hi' => 2, 'lo' => 30], 5), 'the small stratum gets its one');
        self::assertSame(['hi' => 2, 'lo' => 29], KeySample::allocate(['hi' => 2, 'lo' => 30], 31), 'never more than a stratum holds');
        self::assertSame(['hi' => 0, 'lo' => 0], KeySample::allocate(['hi' => 0, 'lo' => 0], 20));
        self::assertSame(['hi' => 3, 'lo' => 2], KeySample::allocate(['hi' => 10, 'lo' => 5], 5), 'largest remainder');
        foreach ([[37, 11, 20], [5, 5, 3], [100, 1, 1], [1, 1, 2]] as [$a, $b, $n]) {
            self::assertSame(min($n, $a + $b), array_sum(KeySample::allocate(['hi' => $a, 'lo' => $b], $n)), "{$a}/{$b}/{$n}");
        }
        self::assertSame(hash('sha256', '42:7'), KeySample::rank(42, 7), 'anyone can re-draw a sample from its seed');
    }
}
