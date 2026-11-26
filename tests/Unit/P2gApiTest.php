<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\P2gApi;
use CW\Profit\P2gKey;
use CW\Tests\Support\FakeP2gHttp;
use CW\Tests\Support\P2gFixtures;
use PHPUnit\Framework\TestCase;

/**
 * CW-1160 (docs/decisions.md PGL6): Parcel2Go's API read only. Only the listed calls leave CW (a booking, a payment, a label, an address change
 * are refused before any request); the token asks for `public-api` only (it cannot pay); each answer is reduced to the kept fields (no name,
 * address, phone or contents); tries on 5xx and 429, one new token on 401, a refusal on 4xx in CW's words (never the key, the token or
 * Parcel2Go's text); the key file's rules. Parcel2Go is never called: its answers are scripted (FakeP2gHttp).
 */
final class P2gApiTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cw_p2g_key_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private static function api(FakeP2gHttp $http): P2gApi
    {
        return new P2gApi(P2gFixtures::key(), $http, static function (int $s): void {
        });
    }

    public function testOnlyTheListedCallsCanBeSent(): void
    {
        foreach ([['POST', '/api/orders'], ['POST', '/api/orders/150000001/paywithprepay'], ['POST', '/api/orders/150000001/parcelnumbers'], ['POST', '/api/orders/verify'],
            ['POST', '/api/prepay/create-topup-links'], ['GET', '/api/labels/150000001'], ['GET', '/api/me/addresses/saved'], ['PUT', '/api/me/addresses/saved/home'],
            ['DELETE', '/api/me/tags/service/remove'], ['GET', '/api/orders?orderId=1&hash=x'], ['GET', '/api/me/orders/detail?take=50'], ['GET', '/api/tracking/P2G1'],
            ['GET', '/api/invoices/1?referenceType=OrderId&other=1'], ['POST', '/api/me/orders/detail']] as [$m, $p]) {
            try {
                P2gApi::allowed($m, $p);
                self::fail("{$m} {$p} is refused");
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
        foreach ([['POST', '/auth/connect/token'], ['GET', '/api/me/orders/detail'], ['GET', '/api/tracking/150000001'],
            ['GET', '/api/invoices/95792472?referenceType=OrderId'], ['GET', '/api/invoices/150000001?referenceType=OrderLineId&hash=abc%2Bdef%3D12']] as [$m, $p]) {
            P2gApi::allowed($m, $p);
        }
    }

    public function testTheTokenCannotPayAndTheAnswerKeepsNoPersonalField(): void
    {
        $http = new FakeP2gHttp(static fn (string $m, string $p): array => [200, ['Orders' => [P2gFixtures::apiLine(150000001, 95000001, '2026-11-24T10:00:00Z'),
            P2gFixtures::apiLine(150000002, null, null, 2, 'AutoTopup')]]]);
        $lines = self::api($http)->recent();
        self::assertSame('POST', $http->requests[0]['method']);
        parse_str((string) $http->requests[0]['body'], $form);
        self::assertSame(['client_credentials', 'public-api'], [$form['grant_type'], $form['scope']], 'a token that cannot pay (no payment scope)');
        self::assertSame(['GET', '/api/me/orders/detail', true], [$http->requests[1]['method'], $http->requests[1]['path'], $http->requests[1]['auth']]);
        self::assertSame([150000001, 95000001, '2026-11-24 10:00:00.000000', 'evri-europe-standard-parcelshop', 'evri', 'OrderProcess', 'parcel', 'DEU', 1],
            [$lines[0]['order_line_id'], $lines[0]['transaction_id'], $lines[0]['paid_at'], $lines[0]['service_slug'], $lines[0]['courier_slug'], $lines[0]['p2g_source'],
                $lines[0]['kind'], $lines[0]['country_iso3'], $lines[0]['parcels']]);
        self::assertSame(['topup', null, 2], [$lines[1]['kind'], $lines[1]['paid_at'], $lines[1]['parcels']], 'a top-up is known as one');
        $json = (string) json_encode($lines);
        foreach ([P2gFixtures::PERSON, 'example.com', '07700', 'TT1 1TT', 'Test Street', 'Test contents', 'doc/'] as $personal) {
            self::assertStringNotContainsString($personal, $json, "{$personal} is never kept");
        }
    }

    public function testTriesNewTokenAndRefusalsInCwWords(): void
    {
        $n = 0;
        $http = new FakeP2gHttp(static function (string $m, string $p) use (&$n): array {
            $n++;
            return match (true) {
                str_starts_with($p, '/api/tracking/150000001') => $n === 1 ? [503, 'down'] : [200, ['Results' => [['ParcelNumber' => 'H00TST 0000000001'], ['ParcelNumber' => 'H00TST0000000001']]]],
                str_starts_with($p, '/api/tracking/150000002') => [404, ''],
                str_starts_with($p, '/api/tracking/150000003') => [401, 'expired'],
                default => [403, 'Forbidden: needs scope payment for Test Person'],
            };
        });
        $api = self::api($http);
        self::assertSame(['H00TST0000000001'], $api->tracking(150000001), 'a 503 is tried again; the numbers in CW\'s form, once');
        self::assertSame([], $api->tracking(150000002), 'not tracked yet');
        try {
            $api->tracking(150000003);
            self::fail('a 401 after a new token is a refusal');
        } catch (CwException $e) {
            self::assertSame(['p2g_refused', 401], [$e->errorCode, $e->detail['status']]);
        }
        self::assertSame(2, count(array_filter($http->requests, static fn (array $r): bool => $r['path'] === '/auth/connect/token')), 'one new token on 401');
        try {
            $api->invoice(95792472, 'OrderId', null);
            self::fail('403');
        } catch (CwException $e) {
            self::assertSame(['p2g_refused', 403], [$e->errorCode, $e->detail['status']]);
            foreach (['Test Person', 'Made-Up-Secret', 'test.token', 'scope payment'] as $never) {
                self::assertStringNotContainsString($never, $e->getMessage());
            }
        }
    }

    public function testAnInvoiceIsAPdf(): void
    {
        $http = new FakeP2gHttp(static fn (string $m, string $p): array => str_contains($p, '95792472')
            ? [200, ['OrderId' => 95792472, 'Base64EncodedInvoice' => base64_encode('%PDF-1.4 test')]] : [200, ['OrderId' => 1, 'Base64EncodedInvoice' => base64_encode('<html>')]]);
        self::assertSame('%PDF-1.4 test', self::api($http)->invoice(95792472, 'OrderId', null));
        self::assertSame('/api/invoices/95792472?referenceType=OrderId', $http->requests[1]['path']);
        try {
            self::api($http)->invoice(150000001, 'OrderLineId', 'ab+c/d=12');
            self::fail('not a PDF');
        } catch (CwException $e) {
            self::assertSame('p2g_no_invoice', $e->errorCode);
        }
        self::assertSame('/api/invoices/150000001?referenceType=OrderLineId&hash=ab%2Bc%2Fd%3D12', end($http->requests)['path']);
    }

    public function testTheKeyFileIsRootsAloneAndNeverShowsItsSecret(): void
    {
        $key = P2gFixtures::key();
        self::assertStringNotContainsString('Made-Up-Secret', print_r($key, true));
        self::assertStringNotContainsString('Made-Up-Secret', var_export((array) $key->__debugInfo(), true));
        $path = P2gKey::path($this->dir, 'p2g_vapeandgo');
        file_put_contents($path, $key->json());
        chmod($path, 0600);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::assertSame('p2g_vapeandgo', P2gKey::load($path, 'p2g_vapeandgo')->account);
            chmod($path, 0640);
            self::assertStringContainsString('mode 0640', self::refusal(static fn () => P2gKey::load($path, 'p2g_vapeandgo')));
            chmod($path, 0600);
            self::assertStringContainsString('another account', self::refusal(static fn () => P2gKey::load($path, 'p2g_vapebig')));
            symlink($path, $this->dir . '/link.json');
            self::assertStringContainsString('not a plain file', self::refusal(fn () => P2gKey::load($this->dir . '/link.json', 'p2g_vapeandgo')));
        }
        self::assertStringContainsString('missing', self::refusal(fn () => P2gKey::load($this->dir . '/none.json', 'p2g_vapeandgo')));
        foreach ([['client_secret' => 'short'], ['client_secret' => 'has a space in it'], ['api_base' => 'https://evil.example.com'], ['client_id' => 'x']] as $bad) {
            $msg = self::refusal(static fn () => P2gKey::fromArray($bad + json_decode($key->json(), true), 'p2g_vapeandgo'));
            self::assertStringNotContainsString('Made-Up-Secret', $msg);
        }
    }

    private static function refusal(\Closure $fn): string
    {
        try {
            $fn();
        } catch (CwException $e) {
            self::assertSame('p2g_key_refused', $e->errorCode);
            return $e->getMessage();
        }
        self::fail('refused');
    }
}
