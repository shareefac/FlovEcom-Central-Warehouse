<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\PaypalApi;
use CW\Profit\PaypalCodes;
use CW\Profit\PaypalKey;
use CW\Profit\PaypalRow;
use CW\Tests\Support\FakePaypalHttp;
use CW\Tests\Support\PaypalTx;
use PHPUnit\Framework\TestCase;

/**
 * PayPal's rows, codes, key and API as CW reads them (docs/decisions.md PPF2-PPF4, PPF10, PPF11), from MADE-UP rows in PayPal's shape (never a
 * real id or amount): a row read strictly (a field not understood refuses it by row and field, never its content), every kind and its cost,
 * the key file's checks (owner, mode, shape, shop) with nothing of it in a refusal, and the API read page by page with its tries, the token
 * asked again once on a 401, only its two paths ever called, and a window refused whole when PayPal's count moves.
 */
final class PaypalReadTest extends TestCase
{
    private const KEY = ['client_id' => 'AclientIDmadeUp0123456789', 'client_secret' => 'EsecretMadeUp0123456789xyz', 'api_base' => 'https://api-m.paypal.com',
        'shop' => 'vapeandgo', 'account' => 'vapeandgo'];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            if (is_file($f) || is_link($f)) {
                unlink($f);
            }
        }
    }

    public function testARowIsReadIntoCwsShapesWithNothingPersonalKept(): void
    {
        $r = PaypalRow::parse(PaypalTx::sale('9XA00001', '2026-10-31T23:30:00Z', '198.99', '-2.84', '1312345'), 1);
        self::assertSame(['txn_id' => '9XA00001', 'event_code' => 'T0006', 'ref_txn_id' => 'REF9XA00001', 'ref_type' => 'TXN', 'status' => 'S',
            'initiated_at' => '2026-10-31 23:30:00.000000', 'pp_updated_at' => '2026-10-31 23:30:00.000000', 'day' => '2026-10-31', 'currency' => 'GBP',
            'amount_pence' => 19899, 'fee_currency' => 'GBP', 'fee_pence' => -284, 'invoice_state' => 'kept', 'invoice_ref' => '1312345-1763700000', 'custom_state' => 'kept',
            'custom_ref' => '1312345-1763700000'], $r);
        // the UK day: 23:30 UTC on 24 Oct (BST) is the 25th in the UK; on 31 Oct (GMT) it is the 31st
        self::assertSame('2026-10-25', PaypalRow::parse(PaypalTx::sale('9XA00002', '2026-10-24T23:30:00Z', '1.00', '-0.01', null), 1)['day']);
        self::assertSame('2026-11-22', PaypalRow::parse(PaypalTx::sale('9XA00003', '2026-11-22T10:00:00+0000', '1.00', '-0.01', null), 1)['day']);
        // a reference is kept as written (the shop's form is decided by the settings later, PPF21); one that could be a name or an e-mail never
        $o = PaypalRow::parse(PaypalTx::sale('9XA00004', '2026-11-22T10:00:00Z', '5', '-0.5', null) + ['invoice_id' => 'faaeeb-1234', 'custom_field' => '1234'], 1);
        self::assertSame(['kept', 'faaeeb-1234', 'kept', '1234', 500, -50], [$o['invoice_state'], $o['invoice_ref'], $o['custom_state'], $o['custom_ref'], $o['amount_pence'],
            $o['fee_pence']]);
        $n = PaypalRow::parse(PaypalTx::sale('9XA00005', '2026-11-22T10:00:00Z', '5.00', '-0.50', null) + ['invoice_id' => 'Jane Smith 1234', 'custom_field' => 'jane@example.com'], 1);
        self::assertSame(['dropped', null, 'dropped', null], [$n['invoice_state'], $n['invoice_ref'], $n['custom_state'], $n['custom_ref']]);
        self::assertStringNotContainsString('Jane', json_encode($n));
        self::assertStringNotContainsString('example.com', json_encode($n));
        // no fee given: 0 and no currency (a hold, many refunds)
        $h = PaypalRow::parse(PaypalTx::row('9XA00006', 'T1501', '2026-11-22T10:00:00Z', '-10.00', null), 1);
        self::assertSame([null, 0, 'none', null], [$h['fee_currency'], $h['fee_pence'], $h['invoice_state'], $h['invoice_ref']]);
        self::assertArrayNotHasKey('transaction_subject', $r);
    }

    public function testARowNotUnderstoodIsRefusedByRowAndFieldNeverItsContent(): void
    {
        $ok = PaypalTx::sale('9XB00001', '2026-11-22T10:00:00Z', '10.00', '-0.50', '1312345');
        $cases = [
            'transaction_amount' => ['transaction_amount' => ['currency_code' => 'GBP', 'value' => '10.005']],
            'fee_amount' => ['fee_amount' => ['currency_code' => 'GBP', 'value' => 'abc']],
            'transaction_event_code' => ['transaction_event_code' => 'X0006'],
            'transaction_initiation_date' => ['transaction_initiation_date' => '2026-02-30T10:00:00Z'],
            'transaction_id' => ['transaction_id' => 'SECRET-LOOKING value'],
            'transaction_status' => ['transaction_status' => 'ok'],
            'invoice_id' => ['invoice_id' => ['an', 'array']],
        ];
        foreach ($cases as $field => $over) {
            try {
                PaypalRow::parse($over + $ok, 7);
                self::fail("{$field}: refused");
            } catch (CwException $e) {
                self::assertSame(['paypal_bad_row', 7, $field], [$e->errorCode, $e->detail['row'], $e->detail['field']], $field);
                self::assertStringNotContainsString('SECRET', $e->getMessage());
                self::assertStringNotContainsString('abc', $e->getMessage());
            }
        }
        $missing = $ok;
        unset($missing['transaction_amount']);
        try {
            PaypalRow::parse($missing, 2);
            self::fail('a row with no amount is refused');
        } catch (CwException $e) {
            self::assertSame('transaction_amount', $e->detail['field']);
        }
    }

    public function testEveryKindAndWhatItCosts(): void
    {
        // the owner's rules (24 Nov 2026): a sale's fee; a refund's fee back; a chargeback's money and fee; a chargeback or dispute fee; holds,
        // bank moves and payments sent ignored; anything else not guessed
        self::assertSame(['sale', 284], [PaypalCodes::kind('T0006', 19899), PaypalCodes::cost('sale', 19899, -284)]);
        self::assertSame(['sale', 120], [PaypalCodes::kind('T0005', 5000), PaypalCodes::cost('sale', 5000, -120)]);
        self::assertSame(['refund', -68], [PaypalCodes::kind('T1107', -4000), PaypalCodes::cost('refund', -4000, 68)]);
        self::assertSame(['chargeback', 17203], [PaypalCodes::kind('T1106', -17271), PaypalCodes::cost('chargeback', -17271, 68)]);
        self::assertSame(['chargeback', 11608], [PaypalCodes::kind('T1201', -11608), PaypalCodes::cost('chargeback', -11608, 0)]);
        self::assertSame(['chargeback', -11608], [PaypalCodes::kind('T1202', 11608), PaypalCodes::cost('chargeback', 11608, 0)]);
        self::assertSame(['fee', 1400], [PaypalCodes::kind('T0106', -1400), PaypalCodes::cost('fee', -1400, 0)]);
        self::assertSame(['fee', 1200], [PaypalCodes::kind('T0114', -1200), PaypalCodes::cost('fee', -1200, 0)]);
        foreach (['T1501', 'T1105', 'T1110', 'T1111', 'T0403', 'T0500', 'T0200', 'T0801'] as $c) {
            self::assertSame('ignore', PaypalCodes::kind($c, -1000), $c);
            self::assertSame(0, PaypalCodes::cost('ignore', -1000, 0));
        }
        // a payment code: money sent is ignored (rule 5), money received with any code but T0005 / T0006 is never guessed
        self::assertSame(['ignore', 'unknown', 'ignore', 'unknown'], [PaypalCodes::kind('T0003', -2499), PaypalCodes::kind('T0003', 2499), PaypalCodes::kind('T0011', -100),
            PaypalCodes::kind('T0000', 3798)]);
        self::assertSame(['unknown', 'unknown', 0], [PaypalCodes::kind('T9999', -100), PaypalCodes::kind('T0107', -100), PaypalCodes::cost('unknown', -100, -5)]);
        self::assertSame([true, false, true, false], [PaypalCodes::signOk('sale', 1), PaypalCodes::signOk('sale', -1), PaypalCodes::signOk('refund', -1),
            PaypalCodes::signOk('refund', 1)]);
    }

    public function testTheKeyFileIsCheckedAndNeverQuoted(): void
    {
        $ok = $this->keyFile(json_encode(self::KEY), 0640);
        $k = PaypalKey::load($ok, 'vapeandgo');
        self::assertSame(['vapeandgo', 'vapeandgo', 'https://api-m.paypal.com'], [$k->shop, $k->account, $k->apiBase]);
        self::assertStringNotContainsString('EsecretMadeUp', print_r($k, true));
        self::assertStringNotContainsString('EsecretMadeUp', (string) json_encode($k));
        foreach ([0600, 0440, 0400] as $mode) {
            chmod($ok, $mode);
            self::assertSame('vapeandgo', PaypalKey::load($ok, 'vapeandgo')->shop);
        }
        $refusals = [
            'missing' => [null, null],
            'not set' => ['', null],
            'world readable' => [$this->keyFile(json_encode(self::KEY), 0644), 'mode'],
            'group writable' => [$this->keyFile(json_encode(self::KEY), 0660), 'mode'],
            'not JSON' => [$this->keyFile('client_id=x', 0640), 'JSON'],
            'a field more' => [$this->keyFile(json_encode(self::KEY + ['extra' => 'x']), 0640), 'exactly'],
            'another account' => [$this->keyFile(json_encode(['account' => 'electrofag'] + self::KEY), 0640), 'another account'],
            'not PayPal' => [$this->keyFile(json_encode(['api_base' => 'https://evil.example.com'] + self::KEY), 0640), 'api_base'],
        ];
        $refusals['missing'][0] = sys_get_temp_dir() . '/cw_pp_missing_' . bin2hex(random_bytes(4));
        $link = sys_get_temp_dir() . '/cw_pp_link_' . bin2hex(random_bytes(4));
        symlink($ok, $link);
        $this->files[] = $link;
        $refusals['a link'] = [$link, 'plain file'];
        foreach ($refusals as $what => [$path, $says]) {
            try {
                PaypalKey::load($path, 'vapeandgo');
                self::fail("{$what}: refused");
            } catch (CwException $e) {
                self::assertSame(['paypal_key_refused', 403], [$e->errorCode, $e->httpStatus], $what);
                if ($says !== null) {
                    self::assertStringContainsString($says, $e->getMessage(), $what);
                }
                foreach (['EsecretMadeUp', 'AclientIDmadeUp', 'evil.example'] as $secret) {
                    self::assertStringNotContainsString($secret, $e->getMessage(), $what);
                }
            }
        }
    }

    public function testTheApiReadsEveryPageAndCallsOnlyItsTwoPaths(): void
    {
        $rows = [];
        for ($i = 0; $i < 1203; $i++) {
            $rows[] = PaypalTx::sale(sprintf('9XC%05d', $i), sprintf('2026-11-2%dT%02d:%02d:00Z', 1 + intdiv($i, 600), intdiv($i % 600, 60), $i % 60), '10.00', '-0.40',
                (string) (1300000 + $i));
        }
        $http = new FakePaypalHttp($rows, base64_encode(self::KEY['client_id'] . ':' . self::KEY['client_secret']));
        $api = new PaypalApi(PaypalKey::fromArray(self::KEY, 'vapeandgo'), $http, static function (int $s): void {
        });
        $w = $api->window(new \DateTimeImmutable('2026-11-21T00:00:00Z'), new \DateTimeImmutable('2026-11-23T23:59:59Z'));
        self::assertSame([1203, 3, '2026-11-25T02:00:00Z'], [count($w['rows']), $w['pages'], $w['refreshed']]);
        self::assertSame(['/v1/oauth2/token', '/v1/reporting/transactions', '/v1/reporting/transactions', '/v1/reporting/transactions'], array_column($http->calls, 'path'));
        self::assertSame(['transaction_info', '500', '3', '2026-11-21T00:00:00+00:00', '2026-11-23T23:59:59+00:00'], [$http->calls[3]['query']['fields'],
            $http->calls[3]['query']['page_size'], $http->calls[3]['query']['page'], $http->calls[3]['query']['start_date'], $http->calls[3]['query']['end_date']]);
        // a second window of the same run: the same token (kept in memory for the run only)
        $api->window(new \DateTimeImmutable('2026-11-21T00:00:00Z'), new \DateTimeImmutable('2026-11-21T09:59:59Z'));
        self::assertSame(1, count(array_filter($http->calls, static fn (array $c): bool => $c['path'] === '/v1/oauth2/token')));
    }

    public function testFailuresAreTriedAgainThenTheWindowIsRefusedWhole(): void
    {
        $rows = [PaypalTx::sale('9XD00001', '2026-11-21T10:00:00Z', '10.00', '-0.40', '1300001')];
        $waits = [];
        $sleep = static function (int $s) use (&$waits): void {
            $waits[] = $s;
        };
        // a 503 and a lost connection, then PayPal answers: tried again (2 s, 4 s), read
        $http = new FakePaypalHttp($rows, '', static fn (int $n, string $p, array $q): ?array => match ($n) {
            2 => ['status' => 503, 'body' => 'busy'],
            3 => FakePaypalHttp::lost(),
            default => null,
        });
        $w = (new PaypalApi(PaypalKey::fromArray(self::KEY, 'vapeandgo'), $http, $sleep))->window(new \DateTimeImmutable('2026-11-21T00:00:00Z'),
            new \DateTimeImmutable('2026-11-21T23:59:59Z'));
        self::assertSame([1, [2, 4]], [count($w['rows']), $waits]);
        // the token ran out: a new one once, then read
        $http = new FakePaypalHttp($rows, '', static fn (int $n, string $p, array $q): ?array => $n === 2 ? ['status' => 401, 'body' => '{}'] : null);
        self::assertCount(1, (new PaypalApi(PaypalKey::fromArray(self::KEY, 'vapeandgo'), $http, $sleep))->window(new \DateTimeImmutable('2026-11-21T00:00:00Z'),
            new \DateTimeImmutable('2026-11-21T23:59:59Z'))['rows']);
        self::assertSame(2, count(array_filter($http->calls, static fn (array $c): bool => $c['path'] === '/v1/oauth2/token')));
        $refused = [
            'always 500' => [static fn (int $n, string $p): ?array => $p === '/v1/reporting/transactions' ? ['status' => 500, 'body' => 'x'] : null, 'paypal_unreachable'],
            'a 400' => [static fn (int $n, string $p): ?array => $p === '/v1/reporting/transactions' ? ['status' => 400, 'body' => '{"message":"SECRET text"}'] : null, 'paypal_http'],
            'key refused' => [static fn (int $n, string $p): ?array => $p === '/v1/oauth2/token' ? ['status' => 401, 'body' => '{}'] : null, 'paypal_token'],
            'not JSON' => [static fn (int $n, string $p): ?array => $p === '/v1/reporting/transactions' ? ['status' => 200, 'body' => '<html>'] : null, 'paypal_bad_answer'],
            'count moved' => [static fn (int $n, string $p): ?array => $p === '/v1/reporting/transactions' ? ['status' => 200, 'body' => json_encode(['transaction_details' => [],
                'total_items' => 1, 'total_pages' => 1])] : null, 'paypal_moved'],
            'too many pages' => [static fn (int $n, string $p): ?array => $p === '/v1/reporting/transactions' ? ['status' => 200, 'body' => json_encode(['transaction_details' => [],
                'total_items' => 60000, 'total_pages' => 120])] : null, 'paypal_too_many'],
        ];
        foreach ($refused as $what => [$fail, $code]) {
            $http = new FakePaypalHttp($rows, '', $fail);
            try {
                (new PaypalApi(PaypalKey::fromArray(self::KEY, 'vapeandgo'), $http, $sleep))->window(new \DateTimeImmutable('2026-11-21T00:00:00Z'),
                    new \DateTimeImmutable('2026-11-21T23:59:59Z'));
                self::fail("{$what}: refused");
            } catch (CwException $e) {
                self::assertSame($code, $e->errorCode, $what);
                self::assertStringNotContainsString('SECRET', $e->getMessage(), $what);
                self::assertStringNotContainsString('A21AA', $e->getMessage(), $what);
            }
        }
        // a window longer than PayPal's 31 days is never asked for
        try {
            (new PaypalApi(PaypalKey::fromArray(self::KEY, 'vapeandgo'), new FakePaypalHttp([]), $sleep))->window(new \DateTimeImmutable('2026-10-01T00:00:00Z'),
                new \DateTimeImmutable('2026-11-05T00:00:00Z'));
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('paypal_bad_window', $e->errorCode);
        }
    }

    private function keyFile(string $content, int $mode): string
    {
        $f = sys_get_temp_dir() . '/cw_pp_key_' . bin2hex(random_bytes(6));
        file_put_contents($f, $content);
        chmod($f, $mode);
        $this->files[] = $f;
        return $f;
    }
}
