<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Csrf;
use CW\Queues\Corrections;
use CW\Settings;
use CW\Ui\Controller\StoreQueuesController;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * "Confirm all ready" (docs/decisions.md CA2): Corrections::why(), pure. Each rule of Needs a look alone, its edges (later, not equal; over
 * the limit, not at it; below zero, not at zero; two website products, not one), and the order: with every rule applying, taking the
 * first away each time gives the next one, a to f. Each reason has its words (a row's line, the board's list); the setting has its rule.
 */
final class ConfirmAllUnitTest extends TestCase
{
    private const AT = '2026-10-31 07:00:00.000000';
    private const LATER = '2026-10-31 07:26:00.000000';

    /** @return array{on_hand: int, counted_at: ?string, links: int, fix_at: ?string} */
    private static function facts(array $f = []): array
    {
        return $f + ['on_hand' => 10, 'counted_at' => null, 'links' => 1, 'fix_at' => null];
    }

    private static function code(int $delta, array $facts = [], bool $newer = false, int $limit = 100): ?string
    {
        return Corrections::why(self::AT, ['delta' => $delta, 'newer' => $newer], self::facts($facts), $limit)['code'] ?? null;
    }

    public function testEachRuleAloneAndItsEdges(): void
    {
        self::assertNull(self::code(0), 'no difference is ready');
        self::assertNull(self::code(5));
        self::assertNull(self::code(-10), 'down to zero is not below it');
        self::assertSame('counted', self::code(5, ['counted_at' => self::LATER]));
        self::assertNull(self::code(5, ['counted_at' => self::AT]), 'counted at the same moment: not after');
        self::assertNull(self::code(5, ['counted_at' => '2026-10-30 07:00:00.000000']), 'counted before: ERPNext set it later');
        self::assertSame('newer', self::code(5, [], true));
        self::assertNull(self::code(100), 'exactly the limit is not over it');
        self::assertNull(self::code(-10, ['on_hand' => 100]), 'a fall of 10 within the limit');
        self::assertSame(['code' => 'big', 'delta' => 101, 'limit' => 100], Corrections::why(self::AT, ['delta' => 101, 'newer' => false], self::facts(), 100));
        self::assertSame(['code' => 'big', 'delta' => -101, 'limit' => 100], Corrections::why(self::AT, ['delta' => -101, 'newer' => false], self::facts(['on_hand' => 500]), 100));
        self::assertSame('big', self::code(1, [], false, 0), 'a limit of 0: every difference is big');
        self::assertNull(self::code(0, [], false, 0), '... and no difference is still ready');
        self::assertSame(['code' => 'below_zero', 'after' => -1], Corrections::why(self::AT, ['delta' => -11, 'newer' => false], self::facts(), 100));
        self::assertSame('below_zero', self::code(0, ['on_hand' => -2]), 'on hand below zero and nothing booked: still below zero');
        self::assertSame('below_zero', self::code(1, ['on_hand' => -2]), 'a rise that leaves it below zero');
        self::assertSame(['code' => 'variants', 'links' => 2], Corrections::why(self::AT, ['delta' => 1, 'newer' => false], self::facts(['links' => 2]), 100));
        self::assertNull(self::code(1, ['links' => 0]), 'not linked any more: nothing for this rule');
        self::assertSame(['code' => 'fixed', 'fix_at' => self::LATER], Corrections::why(self::AT, ['delta' => 1, 'newer' => false], self::facts(['fix_at' => self::LATER]), 100));
        self::assertNull(self::code(1, ['fix_at' => '2026-10-31 06:59:59.999999']), 'a fix before the correction: ERPNext set it after the fix');
        self::assertNull(self::code(1, ['fix_at' => self::AT]), 'at the same moment: not after');
    }

    public function testTheFirstRuleThatAppliesIsTheReasonInTheBoardsOrder(): void
    {
        self::assertSame(['counted', 'newer', 'big', 'below_zero', 'variants', 'fixed'], Corrections::NOT_READY);
        // Every rule applies: a fall of 150 below zero, counted, newer, two website products, a fix after it. Each step takes the first away.
        $facts = ['on_hand' => 20, 'counted_at' => self::LATER, 'links' => 3, 'fix_at' => self::LATER];
        $delta = -150;
        $newer = true;
        $limit = 100;
        $seen = [];
        foreach (Corrections::NOT_READY as $expected) {
            $why = Corrections::why(self::AT, ['delta' => $delta, 'newer' => $newer], self::facts($facts), $limit);
            $seen[] = $why['code'] ?? null;
            match ($expected) {
                'counted' => $facts['counted_at'] = null,
                'newer' => $newer = false,
                'big' => $limit = 1000,
                'below_zero' => $facts['on_hand'] = 500,
                'variants' => $facts['links'] = 1,
                'fixed' => $facts['fix_at'] = null,
            };
        }
        self::assertSame(Corrections::NOT_READY, $seen);
        self::assertNull(Corrections::why(self::AT, ['delta' => $delta, 'newer' => $newer], self::facts($facts), $limit), 'none left: ready');
    }

    public function testEveryReasonHasItsWordsAndTheSettingItsRule(): void
    {
        foreach (Corrections::NOT_READY as $code) {
            self::assertArrayHasKey('why_' . $code, Words::CONFIRM_ALL, $code);
            self::assertArrayHasKey('rule_' . $code, Words::CONFIRM_ALL, $code);
        }
        // A row's line, as the board writes them (figures from the data).
        self::assertSame('Counted on the shelf after ERPNext set it', StoreQueuesController::confirmAllWhy(['code' => 'counted']));
        self::assertSame('A newer ERPNext correction of the item was confirmed already', StoreQueuesController::confirmAllWhy(['code' => 'newer']));
        self::assertSame('Big change: +150 (over 100 units)', StoreQueuesController::confirmAllWhy(['code' => 'big', 'delta' => 150, 'limit' => 100]));
        self::assertSame('Big change: −1,200 (over 100 units)', StoreQueuesController::confirmAllWhy(['code' => 'big', 'delta' => -1200, 'limit' => 100]));
        self::assertSame('The item would go below zero', StoreQueuesController::confirmAllWhy(['code' => 'below_zero', 'after' => -1]));
        self::assertSame('Sold as 2 variants: ERPNext set only one', StoreQueuesController::confirmAllWhy(['code' => 'variants', 'links' => 2]));
        self::assertSame('Made before the one-time stock fix of 31 Oct 2026 for an item the fix set',
            StoreQueuesController::confirmAllWhy(['code' => 'fixed', 'fix_at' => '2026-10-31 07:26:00.000000']), 'the UK day of the fix');
        self::assertSame('Changed while Confirm all ran: answer it on its own', StoreQueuesController::confirmAllWhy(['code' => 'changed', 'error' => 'not_ready']));
        self::assertSame('Not reached: run it again', StoreQueuesController::confirmAllWhy(['code' => 'not_reached']));
        self::assertSame(Words::CONFIRM_ALL['why_changed'], StoreQueuesController::confirmAllWhy(['code' => 'nonsense']), 'anything else reads as changed');
        // A ready row's line.
        $ready = static fn (int $set, int $delta): string => StoreQueuesController::confirmAllLine(['why' => null, 'proposed_qty' => $set, 'effect' => ['delta' => $delta]]);
        self::assertSame(['ERPNext set 22 · adds 22', 'ERPNext set 7 · takes off 3', 'ERPNext set 6 · no difference'], [$ready(22, 22), $ready(7, -3), $ready(6, 0)]);
        // The board's list, in its order, the limit from the setting.
        self::assertSame(['Counted on the shelf after ERPNext set it (the count wins)', 'A newer ERPNext correction of the item was confirmed already',
            'Big change: over 250 units up or down', 'The item would go below zero', 'Sold as 2 or more variants: ERPNext set only one of them',
            'Made before a one-time stock fix for an item the fix set'], StoreQueuesController::confirmAllRules(250));
        // The setting: its rule, its words, under Messages from ERPNext with the other relay settings, in units.
        self::assertSame(['min' => 0, 'max' => 100_000], Settings::RULES[Corrections::BIG_SETTING]);
        self::assertArrayHasKey(Corrections::BIG_SETTING, Words::SETTING);
        self::assertArrayHasKey(Corrections::BIG_SETTING, Words::SETTING_HELP);
        self::assertSame(Words::settingTopic('relay.correction_is_for_sale'), Words::settingTopic(Corrections::BIG_SETTING));
        self::assertSame(Words::SETTINGS_PAGE['unit_units'], \CW\Ui\Controller\ReferenceController::unit(Corrections::BIG_SETTING, 'int'));
        self::assertSame('Confirm all ERPNext corrections', Words::AUDIT_ACTION[Corrections::RUN_ACTION]);
        self::assertArrayHasKey('not_ready', Words::QUEUE_ERROR);
    }

    /** CA12, CA15: the check page's signature binds the person, the time and the ids in order; the press's time bounds fit the UI pool. */
    public function testTheCheckPagesSignatureAndThePressesTimeBounds(): void
    {
        $csrf = Csrf::fromSecretKey(base64_encode(str_repeat('k', 32)));
        $sig = StoreQueuesController::confirmAllSignature($csrf, 7, 1_761_900_000, '12,15');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $sig);
        self::assertSame($sig, StoreQueuesController::confirmAllSignature($csrf, 7, 1_761_900_000, '12,15'));
        foreach ([[8, 1_761_900_000, '12,15'], [7, 1_761_900_001, '12,15'], [7, 1_761_900_000, '15,12'], [7, 1_761_900_000, '12,15,16'], [7, 1_761_900_000, '12']] as [$who, $at, $ids]) {
            self::assertNotSame($sig, StoreQueuesController::confirmAllSignature($csrf, $who, $at, $ids), "{$who} {$at} {$ids}");
        }
        self::assertNotSame($sig, StoreQueuesController::confirmAllSignature(Csrf::fromSecretKey(base64_encode(str_repeat('j', 32))), 7, 1_761_900_000, '12,15'));
        self::assertNotSame($csrf->forSession("7\n1761900000\n12,15"), $sig, 'never a CSRF token of the same text');
        self::assertSame(1800, StoreQueuesController::CONFIRM_ALL_PAGE_SECONDS, '30 minutes');
        // The press: no id starts after 25 s; one id waits at most twice the short lock wait; the end has its reserve; all inside 50 s.
        self::assertSame(25, Corrections::BULK_SECONDS - 2 * Corrections::BULK_LOCK_WAIT - Corrections::BULK_FINAL_SECONDS);
        self::assertSame(5, Corrections::BULK_LOCK_WAIT);
        self::assertLessThan(50, Corrections::BULK_SECONDS, 'the UI pool stops a page at 50 s');
    }
}
