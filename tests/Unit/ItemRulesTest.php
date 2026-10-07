<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Catalogue\ItemRules;
use PHPUnit\Framework\TestCase;

/**
 * The item card's legal rules (IM3; docs/decisions.md I102, I103, I113, I115): TRPR reg 36 (nicotine refills over 10 ml, tanks and
 * pods over 2 ml, nicotine over 20 mg/ml) and the single-use ban, at their exact edges; warnings until a person confirms the card,
 * then blocks that follow the LAST confirmation (no edit lifts one, no edit imposes one); what a confirmation needs (a kit's tank
 * capacity, 0 = none); the advice that never blocks; a value that is not a stored decimal is refused, never skipped.
 */
final class ItemRulesTest extends TestCase
{
    private const AT = '2026-10-07 10:00:00.000000';

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private static function card(array $over = []): array
    {
        return $over + ['product_type' => null, 'liquid_ml' => null, 'nicotine_mg' => null, 'duty_liable' => null, 'single_use' => null, 'ecid' => null,
            'confirmed_at' => null, 'confirmed_breaches' => null, 'first_confirmed_at' => null];
    }

    /** A card a person confirmed (acknowledging $rules), then edited to $now (null: as confirmed). @return array<string, mixed> */
    private static function confirmed(array $values, array $rules, ?array $now = null): array
    {
        return self::card(($now ?? $values) + ['confirmed_at' => $now === null ? self::AT : null, 'confirmed_breaches' => $rules === [] ? null : $rules,
            'first_confirmed_at' => self::AT]);
    }

    public function testTheRefillLimitIsTenMlOfNicotineLiquid(): void
    {
        foreach (['e_liquid', 'shortfill', 'nic_shot'] as $type) {
            self::assertSame([], ItemRules::breaches(self::card(['product_type' => $type, 'liquid_ml' => '10.0', 'nicotine_mg' => '20.00'])), "{$type}: 10 ml is allowed");
            self::assertSame(['trpr_refill_ml'], ItemRules::breaches(self::card(['product_type' => $type, 'liquid_ml' => '10.1', 'nicotine_mg' => '3.00'])), "{$type}: 10.1 ml");
            self::assertSame(['trpr_refill_ml'], ItemRules::breaches(self::card(['product_type' => $type, 'liquid_ml' => '50.0', 'nicotine_mg' => '0.01'])));
            self::assertSame([], ItemRules::breaches(self::card(['product_type' => $type, 'liquid_ml' => '100.0', 'nicotine_mg' => '0.00'])), "{$type}: nicotine-free 100 ml");
            self::assertSame([], ItemRules::breaches(self::card(['product_type' => $type, 'liquid_ml' => '50.0'])), 'an unknown strength breaks nothing');
        }
        self::assertSame([], ItemRules::breaches(self::card(['liquid_ml' => '50.0', 'nicotine_mg' => '18.00'])), 'the refill rule needs the product type');
        self::assertSame([], ItemRules::breaches(self::card(['product_type' => 'coil', 'liquid_ml' => '50.0', 'nicotine_mg' => '18.00'])));
    }

    public function testTheTankLimitIsTwoMl(): void
    {
        self::assertSame([], ItemRules::breaches(self::card(['product_type' => 'device_kit', 'liquid_ml' => '0.0'])), 'a kit without a tank');
        foreach (['tank', 'prefilled_pod', 'device_kit', 'single_use'] as $type) {
            $single = $type === 'single_use' ? ['single_use' => 1] : [];
            self::assertNotContains('trpr_tank_ml', ItemRules::breaches(self::card(['product_type' => $type, 'liquid_ml' => '2.0'] + $single)), "{$type}: 2 ml is allowed");
            self::assertContains('trpr_tank_ml', ItemRules::breaches(self::card(['product_type' => $type, 'liquid_ml' => '2.1'] + $single)), "{$type}: 2.1 ml");
        }
        self::assertSame(['trpr_tank_ml'], ItemRules::breaches(self::card(['product_type' => 'tank', 'liquid_ml' => '5.0', 'nicotine_mg' => '0.00'])),
            'a tank needs no nicotine to break it');
        self::assertSame([], ItemRules::breaches(self::card(['product_type' => 'e_liquid', 'liquid_ml' => '5.0', 'nicotine_mg' => '20.00'])), 'a bottle is not a tank');
    }

    public function testTheNicotineLimitIsTwentyMgPerMlForAnyType(): void
    {
        self::assertSame([], ItemRules::breaches(self::card(['nicotine_mg' => '20.00'])));
        self::assertSame(['trpr_nicotine'], ItemRules::breaches(self::card(['nicotine_mg' => '20.01'])));
        self::assertSame(['trpr_nicotine'], ItemRules::breaches(self::card(['product_type' => 'prefilled_pod', 'liquid_ml' => '2.0', 'nicotine_mg' => '50.00'])));
        self::assertSame(['trpr_refill_ml', 'trpr_nicotine'], ItemRules::breaches(self::card(['product_type' => 'e_liquid', 'liquid_ml' => '30.0', 'nicotine_mg' => '25.00'])),
            'every rule broken, in RULES order');
    }

    public function testSingleUseIsAPersonsAnswerAndBreaksTheBan(): void
    {
        self::assertSame(['single_use'], ItemRules::breaches(self::card(['single_use' => 1])));
        self::assertSame(['single_use'], ItemRules::breaches(self::card(['single_use' => '1'])));
        self::assertSame([], ItemRules::breaches(self::card(['single_use' => 0])));
        self::assertSame([], ItemRules::breaches(self::card(['single_use' => null, 'product_type' => 'device_kit'])), 'not inferred from anything');
        self::assertSame(['trpr_tank_ml', 'single_use'], ItemRules::breaches(self::card(['product_type' => 'single_use', 'single_use' => 1, 'liquid_ml' => '10.0'])));
    }

    public function testWarningsUntilConfirmedThenBlocksThatFollowTheLastConfirmation(): void
    {
        $tank = ['product_type' => 'tank', 'liquid_ml' => '5.0'];
        self::assertSame(['level' => 'warn', 'blocked' => [], 'warnings' => ['trpr_tank_ml']], ItemRules::status(self::card($tank)), 'nobody confirmed it');
        self::assertFalse(ItemRules::enforced(self::card($tank)));
        self::assertSame(['level' => 'block', 'blocked' => ['trpr_tank_ml'], 'warnings' => []], ItemRules::status(self::confirmed($tank, ['trpr_tank_ml'])));
        self::assertSame('block', ItemRules::level(self::confirmed($tank, [])), 'confirmed values that break a rule block (a rule made stricter later)');
        self::assertTrue(ItemRules::enforced(self::confirmed($tank, ['trpr_tank_ml'])));

        // Changed since the confirmation: correcting, emptying or retyping a field lifts nothing; only the next confirmation does.
        foreach ([['product_type' => 'tank', 'liquid_ml' => '2.0'], ['product_type' => 'tank'], ['product_type' => 'accessory', 'liquid_ml' => '5.0'], []] as $edit) {
            self::assertSame(['level' => 'block', 'blocked' => ['trpr_tank_ml'], 'warnings' => []], ItemRules::status(self::confirmed($tank, ['trpr_tank_ml'], $edit)),
                json_encode($edit));
        }
        $refill = ['product_type' => 'e_liquid', 'liquid_ml' => '12.0', 'nicotine_mg' => '20.00'];
        self::assertSame('block', ItemRules::level(self::confirmed($refill, ['trpr_refill_ml'], ['product_type' => 'e_liquid', 'liquid_ml' => '12.0'])), 'nicotine emptied');
        $kit = ['product_type' => 'device_kit', 'liquid_ml' => '2.0', 'single_use' => 1];
        self::assertSame('block', ItemRules::level(self::confirmed($kit, ['single_use'], ['single_use' => null] + $kit)), 'single-use answered "not known" again');

        // An edit that breaks a rule on a card confirmed compliant: a warning until a person confirms it (no block without an acknowledgement).
        self::assertSame(['level' => 'warn', 'blocked' => [], 'warnings' => ['trpr_tank_ml']], ItemRules::status(self::confirmed(['product_type' => 'tank',
            'liquid_ml' => '2.0'], [], $tank)));
        self::assertSame(['level' => 'block', 'blocked' => ['trpr_tank_ml'], 'warnings' => ['trpr_nicotine']], ItemRules::status(self::confirmed($tank, ['trpr_tank_ml'],
            $tank + ['nicotine_mg' => '25.00'])), 'held and new rules side by side');
        self::assertNull(ItemRules::level(self::confirmed(['product_type' => 'tank', 'liquid_ml' => '2.0'], [])), 'no breach, no level');
        self::assertSame(['trpr_tank_ml'], ItemRules::confirmedBreaches('["trpr_tank_ml"]'), 'as stored');
        self::assertSame([], ItemRules::confirmedBreaches(null));
    }

    public function testWhatAConfirmationNeeds(): void
    {
        self::assertSame(['product_type', 'duty_liable'], ItemRules::missing(self::card()));
        self::assertSame(['duty_liable', 'liquid_ml', 'nicotine_mg'], ItemRules::missing(self::card(['product_type' => 'e_liquid'])));
        self::assertSame(['liquid_ml', 'nicotine_mg'], ItemRules::missing(self::card(['product_type' => 'prefilled_pod', 'duty_liable' => 1])));
        self::assertSame(['liquid_ml'], ItemRules::missing(self::card(['product_type' => 'tank', 'duty_liable' => 0])));
        self::assertSame(['liquid_ml', 'single_use'], ItemRules::missing(self::card(['product_type' => 'device_kit', 'duty_liable' => 0])),
            'a kit: what its tank holds (the 2 ml rule), and single-use');
        self::assertSame(['liquid_ml'], ItemRules::missing(self::card(['product_type' => 'device_kit', 'duty_liable' => 0, 'single_use' => 0])));
        self::assertSame([], ItemRules::missing(self::card(['product_type' => 'device_kit', 'duty_liable' => 0, 'single_use' => 0, 'liquid_ml' => '0.0'])),
            '0 ml: it comes without a tank');
        self::assertSame([], ItemRules::missing(self::card(['product_type' => 'coil', 'duty_liable' => 0])));
        self::assertSame([], ItemRules::missing(self::card(['product_type' => 'shortfill', 'duty_liable' => 1, 'liquid_ml' => '50.0', 'nicotine_mg' => '0.00'])),
            '0 mg is an answer, not a missing value');
        foreach (ItemRules::missing(self::card()) as $f) {
            self::assertArrayHasKey($f, ItemRules::NEEDED);
        }
    }

    public function testAdviceNeverBlocks(): void
    {
        self::assertStringContainsString('Vaping Products Duty applies to all vaping liquids', ItemRules::advice(self::card(['product_type' => 'shortfill', 'duty_liable' => 0]))[0]);
        self::assertStringContainsString('holds no vaping liquid', ItemRules::advice(self::card(['product_type' => 'coil', 'duty_liable' => 1]))[0]);
        self::assertSame([], ItemRules::advice(self::card(['product_type' => 'e_liquid', 'duty_liable' => 1, 'ecid' => '12345-16-12345'])));
        self::assertStringContainsString('usual shape', ItemRules::advice(self::card(['ecid' => 'GB12345']))[0]);
        self::assertSame([], ItemRules::breaches(self::card(['product_type' => 'shortfill', 'duty_liable' => 0])));
    }

    public function testDecimalsAreComparedAsIntegers(): void
    {
        self::assertSame(100, ItemRules::scaled('10.0', 1));
        self::assertSame(101, ItemRules::scaled('10.1', 1));
        self::assertSame(2000, ItemRules::scaled('20.00', 2));
        self::assertSame(2000, ItemRules::scaled('20', 2));
        self::assertSame(2001, ItemRules::scaled('20.01', 2));
        self::assertSame(5, ItemRules::scaled('0.05', 2));
        self::assertNull(ItemRules::scaled('10.05', 1), 'more precision than the column holds');
        self::assertNull(ItemRules::scaled('-1', 1));
        self::assertNull(ItemRules::scaled(null, 1));
        self::assertNull(ItemRules::scaled('', 1));
        foreach ([10.0, true, ['10']] as $bad) {
            try {
                ItemRules::scaled($bad, 1);
                self::fail('a ' . get_debug_type($bad) . ' must be refused, not read as "unknown"');
            } catch (\InvalidArgumentException) {
            }
        }
        try {
            ItemRules::breaches(self::card(['product_type' => 'tank', 'liquid_ml' => 5.0]));
            self::fail('a computed float must not skip the tank rule');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('decimal string', $e->getMessage());
        }
        self::assertSame('tank or pod over 2 ml, nicotine over 20 mg/ml', ItemRules::labels(['trpr_tank_ml', 'trpr_nicotine']));
        self::assertSame(array_keys(ItemRules::RULES), array_keys(ItemRules::WHY));
    }

    public function testTheSqlFormNamesTheSameLimits(): void
    {
        $sql = ItemRules::sqlBreach('c');
        foreach (["c.nicotine_mg > 20", "c.liquid_ml > 10", "c.liquid_ml > 2", "c.single_use = 1", "'e_liquid','shortfill','nic_shot'", "'tank','prefilled_pod','device_kit','single_use'"] as $part) {
            self::assertStringContainsString($part, $sql);
        }
        self::assertStringContainsString('JSON_LENGTH(c.confirmed_breaches)', ItemRules::sqlBlocked('c'));
        self::assertStringContainsString(ItemRules::sqlBlocked('c'), ItemRules::sqlFlagged('c'));
        $this->expectException(\InvalidArgumentException::class);
        ItemRules::sqlBreach('c; DROP TABLE x');
    }
}
