<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\PaypalApi;
use CW\Profit\PaypalKey;
use CW\Tests\Support\FakePaypalHttp;
use CW\Tests\Support\PaypalTx;
use PHPUnit\Framework\TestCase;

/**
 * CW-1172 round 5 (the Codex astra final of 66b62d5; the lead's decisions 1, 6, 12; docs/decisions.md PPF58-): the key file is owned by root
 * AND its group is root, its mode exactly 0600 or 0400 (the go-live gate's own rule, so the nightly never takes a key the gate refuses); a
 * repeat in PayPal's answer is found BEFORE its raw count is compared (A, A, B for two declared rows: read again, then given back with its
 * repeats, never a refused window); a page's start and end are read to the fraction of a second PayPal gives. MADE-UP rows and keys.
 */
final class PaypalReadR5Test extends TestCase
{
    private const KEY = ['client_id' => 'AclientIDmadeUp0123456789', 'client_secret' => 'EsecretMadeUp0123456789xyz', 'api_base' => 'https://api-m.paypal.com',
        'shop' => 'vapeandgo', 'account' => 'vapeandgo', 'merchant_id' => 'TESTACCOUNT'];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    private static function api(FakePaypalHttp $http): PaypalApi
    {
        return new PaypalApi(PaypalKey::fromArray(self::KEY, 'vapeandgo'), $http, static function (int $s): void {
        });
    }

    public function testTheKeyFileIsRootRootAndItsModeExactly0600Or0400(): void
    {
        $f = sys_get_temp_dir() . '/cw_pp_key_r5_' . bin2hex(random_bytes(5));
        file_put_contents($f, json_encode(self::KEY));
        $this->files[] = $f;
        chown($f, 'root');
        // root's group only (the gate's root:root): a root-owned key of the www-data group is refused even at 0600
        chgrp($f, 'www-data');
        chmod($f, 0600);
        try {
            PaypalKey::load($f, 'vapeandgo');
            self::fail('root:www-data 0600 is refused');
        } catch (CwException $e) {
            self::assertSame('paypal_key_refused', $e->errorCode);
            self::assertStringContainsString('root:root', $e->getMessage());
        }
        chgrp($f, 'root');
        // exactly 0600 or 0400: a write-only key, an owner-run bit or a sticky bit is refused too
        foreach ([0200, 0700, 0500, 0601, 01600, 04600] as $mode) {
            chmod($f, $mode);
            try {
                PaypalKey::load($f, 'vapeandgo');
                self::fail(sprintf('mode %04o is refused', $mode));
            } catch (CwException $e) {
                self::assertSame('paypal_key_refused', $e->errorCode, sprintf('%04o', $mode));
                self::assertStringContainsString('0600', $e->getMessage());
            }
        }
        foreach ([0600, 0400] as $mode) {
            chmod($f, $mode);
            self::assertSame('vapeandgo', PaypalKey::load($f, 'vapeandgo')->account, sprintf('root:root %04o passes', $mode));
        }
    }

    public function testARepeatIsFoundBeforeTheRawCountIsCompared(): void
    {
        $a = PaypalTx::sale('5P00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001');
        $b = PaypalTx::row('5P00000000000F01', 'T0114', '2026-11-03T12:00:00Z', '-60.00', null, '1312002');
        // PayPal declares two transactions and gives A, A, B: read again (it may have been its pages moving), then given back with the repeat
        $http = new FakePaypalHttp([$a, $a, $b]);
        $http->alter = static function (array $ans, array $q): array {
            $ans['total_items'] = 2;
            return $ans;
        };
        $w = self::api($http)->window(new \DateTimeImmutable('2026-11-03T00:00:00Z'), new \DateTimeImmutable('2026-11-03T23:59:59Z'));
        self::assertSame([3, 1], [count($w['rows']), $w['repeated']]);
        self::assertSame(PaypalApi::READS, count(array_filter($http->calls, static fn (array $c): bool => $c['path'] === PaypalApi::SEARCH_PATH)));
        // a wrong count with no repeat at all: still not PayPal's whole window (refused; the next run reads it again)
        $http = new FakePaypalHttp([$a, $b]);
        $http->alter = static function (array $ans, array $q): array {
            $ans['total_items'] = 3;
            return $ans;
        };
        try {
            self::api($http)->window(new \DateTimeImmutable('2026-11-03T00:00:00Z'), new \DateTimeImmutable('2026-11-03T23:59:59Z'));
            self::fail('a count PayPal\'s rows do not make, with no repeat, is refused');
        } catch (CwException $e) {
            self::assertSame('paypal_moved', $e->errorCode);
        }
    }

    public function testAPagesIntervalIsReadToTheFractionOfASecond(): void
    {
        self::assertSame('2026-11-23 00:00:00.900000', PaypalApi::time('2026-11-23T00:00:00.900Z'));
        self::assertSame('2026-11-23 00:00:00.000000', PaypalApi::time('2026-11-23T00:00:00Z'));
        $rows = [PaypalTx::sale('5Q00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001')];
        $at = static fn (array $q, string $f, int $shift, string $frac): string => gmdate('Y-m-d\TH:i:s', (new \DateTimeImmutable($q[$f]))->getTimestamp() + $shift) . $frac;
        $window = static function (\Closure $alter) use ($rows): array {
            $http = new FakePaypalHttp($rows);
            $http->alter = $alter;
            return self::api($http)->window(new \DateTimeImmutable('2026-11-03T00:00:00Z'), new \DateTimeImmutable('2026-11-03T23:59:59Z'));
        };
        // a start 0.9 s after the one asked for is later than it
        $w = $window(static fn (array $a, array $q): array => ['start_date' => $at($q, 'start_date', 0, '.900Z')] + $a);
        self::assertTrue($w['short_start']);
        // the very start asked for (PayPal's real shape): not later
        $w = $window(static fn (array $a, array $q): array => ['start_date' => $at($q, 'start_date', 0, 'Z'), 'end_date' => $at($q, 'end_date', 0, 'Z')] + $a);
        self::assertSame([false, null], [$w['short_start'], $w['returned_to']]);
        // an end a tenth of a second short of the one asked for ends early, at that fraction
        $w = $window(static fn (array $a, array $q): array => ['end_date' => $at($q, 'end_date', -1, '.900Z')] + $a);
        self::assertSame('2026-11-03 23:59:58.900000', $w['returned_to']);
        // an end later than asked (by a fraction too): PayPal's own, the whole asked for
        $w = $window(static fn (array $a, array $q): array => ['end_date' => $at($q, 'end_date', 0, '.500Z')] + $a);
        self::assertNull($w['returned_to']);
    }
}
