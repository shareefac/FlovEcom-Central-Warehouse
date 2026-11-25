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
 * CW-1172 fix round 1 (the Codex review of dd93337; the lead's decision 4): the key names its PayPal merchant and every page PayPal gives must
 * be that merchant's; a key file readable by a group other than www-data is refused; a page that repeats a row of another page (PayPal's pages
 * moved) reads the window again, then refuses it; PayPal's refresh time is read strictly; the run's deadline is kept. MADE-UP rows and keys.
 */
final class PaypalReadR1Test extends TestCase
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

    private static function api(FakePaypalHttp $http, ?float $deadline = null): PaypalApi
    {
        return new PaypalApi(PaypalKey::fromArray(self::KEY, 'vapeandgo'), $http, static function (int $s): void {
        }, $deadline);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = PaypalTx::sale(sprintf('9XR%05d', $i), sprintf('2026-11-21T%02d:%02d:00Z', intdiv($i, 60) % 24, $i % 60), '10.00', '-0.40', (string) (1300000 + $i));
        }
        return $out;
    }

    public function testEveryPageMustBeTheKeysMerchant(): void
    {
        try {
            PaypalKey::fromArray(array_diff_key(self::KEY, ['merchant_id' => 1]), 'vapeandgo');
            self::fail('a key without its merchant is refused');
        } catch (CwException $e) {
            self::assertSame('paypal_key_refused', $e->errorCode);
        }
        $http = new FakePaypalHttp(self::rows(3), '', null, '2026-11-25T02:00:00Z', 'OTHERMERCHANT1');
        try {
            self::api($http)->window(new \DateTimeImmutable('2026-11-21T00:00:00Z'), new \DateTimeImmutable('2026-11-21T23:59:59Z'));
            self::fail('another merchant\'s rows are refused');
        } catch (CwException $e) {
            self::assertSame('paypal_wrong_account', $e->errorCode);
            self::assertStringNotContainsString('OTHERMERCHANT1', $e->getMessage());
        }
        self::assertCount(3, self::api(new FakePaypalHttp(self::rows(3)))->window(new \DateTimeImmutable('2026-11-21T00:00:00Z'),
            new \DateTimeImmutable('2026-11-21T23:59:59Z'))['rows']);
    }

    public function testAKeyFileReadableByAnotherGroupIsRefused(): void
    {
        $f = sys_get_temp_dir() . '/cw_pp_key_r1_' . bin2hex(random_bytes(5));
        file_put_contents($f, json_encode(self::KEY));
        $this->files[] = $f;
        chmod($f, 0640);
        // round 4 (F-M1): no group may read it, www-data's neither (the key file is root:root 0600)
        foreach (['bin', 'www-data'] as $group) {
            chgrp($f, $group);
            try {
                PaypalKey::load($f, 'vapeandgo');
                self::fail("root:{$group} 0640 is refused");
            } catch (CwException $e) {
                self::assertStringContainsString('only root may read it', $e->getMessage());
            }
        }
        chgrp($f, 'bin');
        chmod($f, 0600);
        self::assertSame('vapeandgo', PaypalKey::load($f, 'vapeandgo')->account, 'no group read: its group does not matter');
    }

    public function testARepeatedRowAcrossPagesReadsTheWindowAgainThenGivesItBackWithItsRepeats(): void
    {
        $rows = self::rows(501);
        $dup = static function (int $n, string $p, array $q) use ($rows): ?array {
            if ($p !== '/v1/reporting/transactions' || $q['page'] !== '2') {
                return null;
            }
            // page 2 repeats page 1's last row instead of the 501st (PayPal's pages moved while read)
            return ['status' => 200, 'body' => json_encode(['transaction_details' => [['transaction_info' => $rows[499]]], 'account_number' => 'TESTACCOUNT',
                'total_items' => 501, 'total_pages' => 2, 'last_refreshed_datetime' => '2026-11-25T02:00:00Z'])];
        };
        $once = false;
        $http = new FakePaypalHttp($rows, '', static function (int $n, string $p, array $q) use ($dup, &$once): ?array {
            if (!$once && ($a = $dup($n, $p, $q)) !== null) {
                $once = true;
                return $a;
            }
            return null;
        });
        $w = self::api($http)->window(new \DateTimeImmutable('2026-11-21T00:00:00Z'), new \DateTimeImmutable('2026-11-21T23:59:59Z'));
        self::assertCount(501, $w['rows']);
        self::assertCount(501, array_unique(array_map(static fn (array $r): string => $r['transaction_id'], $w['rows'])), 'read again whole: every row once');
        // a window that keeps repeating a row: read READS times, then given back as it stands with the count of repeats (round 4, F-L5: never
        // refused night after night; the caller keeps one of each and proves none of its days, so a row its moving pages left out is read again)
        $w = self::api(new FakePaypalHttp($rows, '', $dup))->window(new \DateTimeImmutable('2026-11-21T00:00:00Z'), new \DateTimeImmutable('2026-11-21T23:59:59Z'));
        self::assertSame([501, 500, 1], [count($w['rows']), count(array_unique(array_map(static fn (array $r): string => $r['transaction_id'], $w['rows']))),
            $w['repeated']]);
    }

    public function testRefreshTimeReadStrictlyAndTheDeadlineKept(): void
    {
        $w = self::api(new FakePaypalHttp(self::rows(1), '', null, 'garbage<script>'))->window(new \DateTimeImmutable('2026-11-21T00:00:00Z'),
            new \DateTimeImmutable('2026-11-21T23:59:59Z'));
        self::assertNull($w['refreshed'], 'not a time: none');
        $w = self::api(new FakePaypalHttp(self::rows(1), '', null, '2026-11-25T02:00:00+0000'))->window(new \DateTimeImmutable('2026-11-21T00:00:00Z'),
            new \DateTimeImmutable('2026-11-21T23:59:59Z'));
        self::assertSame('2026-11-25 02:00:00.000000', $w['refreshed'], 'as CW\'s UTC time');
        $http = new FakePaypalHttp(self::rows(1));
        try {
            self::api($http, microtime(true) - 1)->window(new \DateTimeImmutable('2026-11-21T00:00:00Z'), new \DateTimeImmutable('2026-11-21T23:59:59Z'));
            self::fail('past its deadline nothing is asked');
        } catch (CwException $e) {
            self::assertSame('paypal_deadline', $e->errorCode);
        }
        self::assertSame([], $http->calls);
    }
}
