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
 * CW-1172 round 4 (the Fable final review F-M1, F-L5; docs/decisions.md PPF53, PPF55): the key file is root's alone (root:root 0600: no group,
 * so nothing the web app runs as can read it); a row PayPal repeats in its answer is given back after the reads again, with the count of
 * repeats, never a refused window. MADE-UP rows and keys.
 */
final class PaypalReadR4Test extends TestCase
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

    public function testTheKeyFileIsRootsAloneNoGroupMayReadIt(): void
    {
        $f = sys_get_temp_dir() . '/cw_pp_key_r4_' . bin2hex(random_bytes(5));
        file_put_contents($f, json_encode(self::KEY));
        $this->files[] = $f;
        chmod($f, 0640);
        foreach (['www-data', 'root'] as $group) {
            chgrp($f, $group);
            try {
                PaypalKey::load($f, 'vapeandgo');
                self::fail("root:{$group} 0640 is refused: the key is root's alone (0600)");
            } catch (CwException $e) {
                self::assertSame('paypal_key_refused', $e->errorCode);
                self::assertStringContainsString('0600', $e->getMessage());
            }
        }
        chgrp($f, 'root');
        chmod($f, 0600);
        self::assertSame('TESTACCOUNT', PaypalKey::load($f, 'vapeandgo')->merchantId);
        chmod($f, 0400);
        self::assertSame('vapeandgo', PaypalKey::load($f, 'vapeandgo')->account, 'read only by root: passes too');
    }

    public function testARowPaypalRepeatsIsGivenBackWithTheCountOfRepeatsNeverARefusedWindow(): void
    {
        $s = PaypalTx::sale('4P00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001');
        $http = new FakePaypalHttp([$s, $s, PaypalTx::sale('4P00000000000S02', '2026-11-03T11:00:00Z', '20.00', '-0.70', '1312002')]);
        $api = new PaypalApi(PaypalKey::fromArray(self::KEY, 'vapeandgo'), $http, static function (int $s): void {
        });
        $w = $api->window(new \DateTimeImmutable('2026-11-03T00:00:00Z'), new \DateTimeImmutable('2026-11-03T23:59:59Z'));
        self::assertSame([3, 1], [count($w['rows']), $w['repeated']]);
        self::assertSame(PaypalApi::READS, count(array_filter($http->calls, static fn (array $c): bool => $c['path'] === PaypalApi::SEARCH_PATH)),
            'read again first (PayPal\'s pages may have moved), then given back as it stands');
    }
}
