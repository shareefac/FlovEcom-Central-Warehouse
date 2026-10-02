<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Documents;

use CW\CwException;
use CW\Db;
use CW\Documents\NumberSeries;
use CW\Tests\Support\IntegrationTestCase;

/**
 * I20: one continuous series per prefix, taken inside the posting transaction; a rolled-back allocation gives its
 * number back (gapless); a series outgrows its pad instead of wrapping.
 */
final class NumberSeriesTest extends IntegrationTestCase
{
    public function testNumbersAreContinuousGaplessAndPerPrefix(): void
    {
        $s = new NumberSeries(self::$db);
        $tx = static fn (callable $fn): mixed => self::$db->transaction(static fn (Db $db): mixed => $fn());
        self::assertSame('ADJ-000001', $tx(static fn (): string => $s->next('ADJ')));
        self::assertSame('ADJ-000002', $tx(static fn (): string => $s->next('ADJ')));
        self::assertSame('PO-000001', $tx(static fn (): string => $s->next('PO')), 'each prefix has its own series');
        try {
            $tx(static function () use ($s): void {
                self::assertSame('ADJ-000003', $s->next('ADJ'));
                throw new \DomainException('the posting failed');
            });
            self::fail('no rollback');
        } catch (\DomainException) {
        }
        self::assertSame(2, (int) self::$db->value("SELECT last_no FROM number_series WHERE prefix = 'ADJ'"));
        self::assertSame('ADJ-000003', $tx(static fn (): string => $s->next('ADJ')), 'the rolled-back number is given again');
        self::assertSame(['GRN-000001', 'GRN-000002'], $tx(static fn (): array => [$s->next('GRN'), $s->next('GRN')]), 'two in one transaction');
    }

    public function testOutsideATransactionUnknownPrefixAndPadOverflow(): void
    {
        $s = new NumberSeries(self::$db);
        try {
            $s->next('ADJ');
            self::fail('a number outside a transaction');
        } catch (\LogicException $e) {
            self::assertStringContainsString('inside the posting transaction', $e->getMessage());
        }
        self::assertSame(0, (int) self::$db->value("SELECT last_no FROM number_series WHERE prefix = 'ADJ'"));
        try {
            self::$db->transaction(static fn (): string => $s->next('NOPE'));
            self::fail('an unknown series');
        } catch (CwException $e) {
            self::assertSame(['unknown_series', 404, ['prefix' => 'NOPE']], [$e->errorCode, $e->httpStatus, $e->detail]);
        }
        self::$db->exec("UPDATE number_series SET last_no = 999999 WHERE prefix = 'ADJ'");
        self::assertSame('ADJ-1000000', self::$db->transaction(static fn (): string => $s->next('ADJ')), 'wider, never wrapped');
        self::assertSame(1_000_000, NumberSeries::parse('ADJ', 'ADJ-1000000'));
        self::assertSame(12, NumberSeries::parse('ADJ', 'ADJ-000012'));
        self::assertNull(NumberSeries::parse('ADJ', 'WO-000012'));
        self::assertNull(NumberSeries::parse('ADJ', 'ADJ-12a'));
        self::assertSame('TRD-000042', NumberSeries::format('TRD', 42, 6));
    }
}
