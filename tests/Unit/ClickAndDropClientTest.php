<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\CarrierKeys;
use CW\Profit\ClickAndDropClient;
use CW\Profit\CurlHttpGet;
use CW\Profit\HttpGet;
use PHPUnit\Framework\TestCase;

/**
 * The Click & Drop read client (docs/decisions.md DL49) against a fake transport: GET only (its source has no other verb), the key in the
 * header exactly as Click & Drop took it and never in a message, the pages followed, 100 orders a call, the gaps, Retry-After, the backoff,
 * the answers cut down to what CW keeps, the labels' shapes; and CW's key file (root only, the key never said). No call leaves the machine.
 */
final class ClickAndDropClientTest extends TestCase
{
    private const KEY = 'test-key-0000-not-real-5f3a9c';

    /** @var list<array{url: string, headers: list<string>}> */
    private array $calls = [];
    /** @var list<int> microseconds waited */
    private array $sleeps = [];
    private float $now = 1000.0;

    /** @param list<array{status: int, body?: string, retry_after?: ?int}> $answers */
    private function client(array $answers): ClickAndDropClient
    {
        $http = new class ($answers, $this->calls) implements HttpGet {
            /** @param list<array<string, mixed>> $answers */
            public function __construct(private array $answers, private array &$calls)
            {
            }

            public function get(string $url, array $headers, int $timeoutSec): array
            {
                $this->calls[] = ['url' => $url, 'headers' => $headers];
                $a = array_shift($this->answers) ?? ['status' => 500];
                return ['status' => (int) $a['status'], 'body' => (string) ($a['body'] ?? ''), 'retry_after' => $a['retry_after'] ?? null];
            }
        };
        return new ClickAndDropClient($http, self::KEY, function (int $micro): void {
            $this->sleeps[] = $micro;
            $this->now += $micro / 1_000_000;
        }, ClickAndDropClient::BASE, fn (): float => $this->now);
    }

    private static function ok(mixed $body): array
    {
        return ['status' => 200, 'body' => json_encode($body, JSON_THROW_ON_ERROR)];
    }

    private static function order(int $id, array $over = []): array
    {
        return $over + ['orderIdentifier' => $id, 'orderReference' => (string) (1358000 + $id), 'createdOn' => '2026-11-09T11:12:42.3769294',
            'orderDate' => '2026-11-09T11:12:42', 'shippedOn' => '2026-11-09T11:12:42.8641326', 'recipient' => ['name' => 'Testy McTestface', 'address' => '1 Imaginary Road'],
            'packages' => [['packageNumber' => 1]]];
    }

    public function testTheListIsFollowedPageByPageAndCutDownToWhatCwKeeps(): void
    {
        $c = $this->client([self::ok(['orders' => [self::order(1), self::order(2)], 'continuationToken' => 'tok/1+=']), self::ok(['orders' => [self::order(3)]])]);
        $orders = $c->orders('2026-11-09T00:00:00', '2026-11-09T23:59:59', 2);
        self::assertSame([1, 2, 3], array_column($orders, 'orderIdentifier'));
        self::assertSame(['orderIdentifier', 'orderReference', 'createdOn', 'manifestedOn', 'trackingNumber', 'packages'], array_keys($orders[0]));
        self::assertStringNotContainsString('Testy', json_encode($orders, JSON_THROW_ON_ERROR), 'nothing else of an order is kept');
        self::assertCount(2, $this->calls);
        self::assertSame(ClickAndDropClient::BASE . '/orders?startDateTime=2026-11-09T00%3A00%3A00&endDateTime=2026-11-09T23%3A59%3A59&pageSize=2', $this->calls[0]['url']);
        self::assertStringEndsWith('&continuationToken=tok%2F1%2B%3D', $this->calls[1]['url']);
        self::assertSame(['Authorization: ' . self::KEY, 'Accept: application/json'], $this->calls[0]['headers'], 'the key as Click & Drop took it, not Bearer');
        self::assertSame([250_000], $this->sleeps, '250 ms between two calls');
    }

    public function testAPageNamingItsOwnTokenAgainIsNeverFollowedRound(): void
    {
        $c = $this->client([self::ok(['orders' => [], 'continuationToken' => 'same']), self::ok(['orders' => [], 'continuationToken' => 'same'])]);
        try {
            $c->orders('2026-11-09T00:00:00', '2026-11-09T23:59:59');
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('cnd_bad_answer', $e->errorCode);
        }
        self::assertCount(2, $this->calls);
    }

    public function testDetailsAreAskedAHundredOrdersACallJoinedBySemicolons(): void
    {
        $ids = range(1, 250);
        $answers = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $answers[] = self::ok(array_map(static fn (int $i): array => self::order($i, ['trackingNumber' => sprintf('JV%09dGB', $i),
                'manifestedOn' => '2026-11-09T18:00:35.4961013']), $chunk));
        }
        $c = $this->client($answers);
        $got = $c->ordersByIds([...$ids, 5]);
        self::assertCount(250, $got);
        self::assertCount(3, $this->calls);
        self::assertSame(ClickAndDropClient::BASE . '/orders/' . implode(';', range(1, 100)), $this->calls[0]['url']);
        self::assertSame(ClickAndDropClient::BASE . '/orders/' . implode(';', range(201, 250)), $this->calls[2]['url']);
        self::assertSame([250_000, 250_000], $this->sleeps);
        $this->calls = [];
        self::assertSame([], $this->client([['status' => 404, 'body' => 'Not found: order 1 of Testy McTestface']])->ordersByIds([1]), 'none found');
    }

    public function testA429WaitsRetryAfterOrBacksOffAndGivesUpAfterSixTries(): void
    {
        $c = $this->client([['status' => 429, 'retry_after' => 3], ['status' => 429], ['status' => 429, 'retry_after' => 999], self::ok(['orders' => []])]);
        self::assertSame([], $c->orders('2026-11-09T00:00:00', '2026-11-09T23:59:59'));
        self::assertSame([3_000_000, 2_000_000, 60_000_000], $this->sleeps, 'Retry-After 3 s, else the second backoff (2 s), at most 60 s');
        $this->sleeps = [];
        $c = $this->client(array_fill(0, 7, ['status' => 429]));
        try {
            $c->orders('2026-11-09T00:00:00', '2026-11-09T23:59:59');
            self::fail('busy');
        } catch (CwException $e) {
            self::assertSame(['cnd_busy', 503], [$e->errorCode, $e->httpStatus]);
        }
        self::assertSame([1_000_000, 2_000_000, 4_000_000, 8_000_000, 16_000_000], $this->sleeps);
    }

    public function testNoAnswerOrA5xxIsTriedThreeTimes(): void
    {
        foreach ([0, 500, 503] as $status) {
            $this->sleeps = [];
            $this->calls = [];
            try {
                $this->client(array_fill(0, 5, ['status' => $status, 'body' => 'stack trace with Testy McTestface']))->ordersByIds([1]);
                self::fail('down');
            } catch (CwException $e) {
                self::assertSame(['cnd_down', 503], [$e->errorCode, $e->httpStatus]);
                self::assertStringNotContainsString('Testy', $e->getMessage());
            }
            self::assertCount(3, $this->calls, "status {$status}");
            self::assertSame([1_000_000, 2_000_000], $this->sleeps);
        }
        self::assertSame([self::order(7, ['packages' => []])['orderIdentifier']], array_column($this->client([['status' => 502], self::ok([self::order(7)])])->ordersByIds([7]),
            'orderIdentifier'), 'a 5xx then an answer');
    }

    public function testARefusedKeyOrAnErrorNeverSaysTheKeyTheQueryOrTheAnswer(): void
    {
        foreach ([[401, 'cnd_refused'], [403, 'cnd_refused'], [400, 'cnd_error'], [404, 'cnd_error']] as [$status, $code]) {
            try {
                $this->client([['status' => $status, 'body' => '{"message":"bad key ' . self::KEY . ' for Testy McTestface"}']])
                    ->orders('2026-11-09T00:00:00', '2026-11-09T23:59:59');
                self::fail("{$status}: refused");
            } catch (CwException $e) {
                self::assertSame($code, $e->errorCode);
                self::assertStringContainsString("HTTP {$status}", $e->getMessage());
                foreach ([self::KEY, 'Testy', 'startDateTime', '2026-11-09', 'bad key', 'Authorization'] as $never) {
                    self::assertStringNotContainsString($never, $e->getMessage());
                }
            }
        }
        foreach (['not json at all ' . self::KEY, '"a string"', '{"orders": "x"}', '[{"orderIdentifier": "7"}]'] as $body) {
            try {
                $c = $this->client([['status' => 200, 'body' => $body]]);
                str_starts_with($body, '[') ? $c->ordersByIds([7]) : $c->orders('2026-11-09T00:00:00', '2026-11-09T23:59:59');
                self::fail('a bad answer: refused');
            } catch (CwException $e) {
                self::assertSame('cnd_bad_answer', $e->errorCode);
                self::assertStringNotContainsString(self::KEY, $e->getMessage());
            }
        }
    }

    public function testLabelsComeOnePerPackageInTheirShapesOnly(): void
    {
        $labels = ClickAndDropClient::labels([
            ['orderIdentifier' => 764755, 'orderReference' => '1358046', 'createdOn' => '2026-11-09T11:12:42.3769294', 'manifestedOn' => '2026-11-09T18:00:35.4961013',
                'trackingNumber' => 'JV828539300GB', 'packages' => [['trackingNumber' => 'JV828539300GB']]],
            ['orderIdentifier' => 2, 'orderReference' => '#2833', 'createdOn' => null, 'manifestedOn' => null, 'trackingNumber' => null,
                'packages' => [['trackingNumber' => 'ox000000001gb'], ['trackingNumber' => 'OX000000002GB'], ['trackingNumber' => null]]],
            ['orderIdentifier' => 3, 'orderReference' => 'man return Test Person', 'createdOn' => 'yesterday', 'manifestedOn' => '2026-02-30T10:00:00',
                'trackingNumber' => 'JV000000003GB', 'packages' => [['trackingNumber' => null]]],
            ['orderIdentifier' => 4, 'orderReference' => '2900', 'createdOn' => null, 'manifestedOn' => '2026-11-09T23:30:00Z', 'trackingNumber' => 'bad!',
                'packages' => []],
            ['orderIdentifier' => 5, 'orderReference' => '1358050', 'createdOn' => null, 'manifestedOn' => '2026-03-29T01:30:00', 'trackingNumber' => 'JV000000005GB',
                'packages' => []],
        ]);
        self::assertSame([
            ['tracking_no' => 'JV828539300GB', 'source_id' => 764755, 'reference' => '1358046', 'created_on' => '2026-11-09 11:12:42.376929',
                'manifested_at' => '2026-11-09 18:00:35.496101', 'manifest_day' => '2026-11-09'],
            ['tracking_no' => 'OX000000001GB', 'source_id' => 2, 'reference' => '#2833', 'created_on' => null, 'manifested_at' => null, 'manifest_day' => null],
            ['tracking_no' => 'OX000000002GB', 'source_id' => 2, 'reference' => '#2833', 'created_on' => null, 'manifested_at' => null, 'manifest_day' => null],
            ['tracking_no' => 'JV000000003GB', 'source_id' => 3, 'reference' => null, 'created_on' => null, 'manifested_at' => null, 'manifest_day' => null],
            ['tracking_no' => 'JV000000005GB', 'source_id' => 5, 'reference' => '1358050', 'created_on' => null, 'manifested_at' => '2026-03-29 01:30:00.000000',
                'manifest_day' => '2026-03-29'],
        ], $labels, 'packages first, else the order\'s own; a bad number dropped; free text and bad times kept as nothing; no zone: the clock as given');
        self::assertSame('2026-07-01 00:30:00.000000', ClickAndDropClient::time('2026-06-30T23:30:00Z'), 'a UTC time in summer is put in UK time');
    }

    public function testTheClientAndItsTransportHaveNoVerbButGet(): void
    {
        foreach (['ClickAndDropClient.php', 'CurlHttpGet.php', 'HttpGet.php'] as $f) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Profit/' . $f);
            foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'CURLOPT_POST', 'CURLOPT_CUSTOMREQUEST', 'CURLOPT_UPLOAD', 'CURLOPT_POSTFIELDS'] as $verb) {
                self::assertDoesNotMatchRegularExpression('/\b' . $verb . '\b/i', $src, "{$f} holds no {$verb}");
            }
        }
        self::assertStringContainsString('CURLOPT_HTTPGET => true', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Profit/CurlHttpGet.php'));
        foreach (['http://api.parcel.royalmail.com/api/v1/orders', 'ftp://x/y', 'file:///etc/passwd'] as $url) {
            try {
                (new CurlHttpGet())->get($url, [], 1);
                self::fail("{$url}: refused");
            } catch (CwException $e) {
                self::assertSame('bad_url', $e->errorCode);
            }
        }
    }

    public function testAnEmptyKeyOrOneOfTwoLinesIsRefused(): void
    {
        foreach (['', "abc\r\nX-Other: 1"] as $key) {
            try {
                new ClickAndDropClient(new CurlHttpGet(), $key);
                self::fail('refused');
            } catch (CwException $e) {
                self::assertSame('no_key', $e->errorCode);
                self::assertStringNotContainsString('X-Other', $e->getMessage());
            }
        }
    }

    /** The Codex check r7, H1: an order's details are whole only with their fields in their shapes (an id alone, or packages not a list, is not). */
    public function testAnOrdersDetailsAreWholeOnlyInTheirShapes(): void
    {
        $base = ['orderIdentifier' => 1, 'orderReference' => '1', 'createdOn' => '2026-11-10T10:00:00', 'manifestedOn' => '2026-11-10T18:00:00',
            'trackingNumber' => 'JV000000001GB', 'packages' => [['trackingNumber' => 'JV000000001GB']]];
        $http = static fn (array $o): HttpGet => new class ($o) implements HttpGet {
            public function __construct(private array $o)
            {
            }

            public function get(string $url, array $headers, int $timeoutSec): array
            {
                return ['status' => 200, 'body' => json_encode([$this->o]), 'retry_after' => null];
            }
        };
        $whole = static fn (array $o): bool => ClickAndDropClient::complete((new ClickAndDropClient($http($o), self::KEY, static function (int $m): void {
        }))->ordersByIds([1])[0]);
        self::assertTrue($whole($base));
        self::assertTrue($whole(['manifestedOn' => null, 'trackingNumber' => null, 'packages' => []] + $base), 'not manifested yet: read again later');
        self::assertFalse($whole(['orderIdentifier' => 1]), 'an id alone is no sign it is not manifested');
        self::assertFalse($whole(['packages' => 'JV000000001GB'] + $base), 'packages not a list');
        self::assertFalse($whole(['packages' => [['trackingNumber' => '']]] + $base), 'a package with no tracking number');
    }

    /** The Codex check r7, H8: one key value under two names (a key copied to another account's name) is refused. */
    public function testOneKeyUnderTwoNamesIsRefused(): void
    {
        $dir = sys_get_temp_dir() . '/cw_keys_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $file = $dir . '/carrier_keys.env';
        try {
            file_put_contents($file, 'click_and_drop_0706703000=' . self::KEY . "\nclick_and_drop_vapebig=" . self::KEY . "\n");
            chmod($file, 0600);
            try {
                CarrierKeys::key('click_and_drop_vapebig', $file);
                self::fail('one key under two names');
            } catch (CwException $e) {
                self::assertSame('no_key', $e->errorCode);
                self::assertStringNotContainsString(self::KEY, $e->getMessage());
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testTheKeyFileIsReadOnlyWhenItIsTheOwnersOnly(): void
    {
        $dir = sys_get_temp_dir() . '/cw_keys_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $file = $dir . '/carrier_keys.env';
        try {
            file_put_contents($file, "# CW's carrier keys\nclick_and_drop_0706703000=" . self::KEY . "\nclick_and_drop_vapebig=other-test-key\n");
            chmod($file, 0600);
            self::assertSame(self::KEY, CarrierKeys::key('click_and_drop_0706703000', $file));
            self::assertSame('other-test-key', CarrierKeys::key('click_and_drop_vapebig', $file));
            $cases = ['a name not in the file' => ['click_and_drop_9999', 0600], 'a bad name' => ['Click-And-Drop', 0600], 'readable by others' => ['click_and_drop_0706703000', 0644],
                'readable by the group' => ['click_and_drop_0706703000', 0640]];
            foreach ($cases as $why => [$name, $mode]) {
                chmod($file, $mode);
                try {
                    CarrierKeys::key($name, $file);
                    self::fail("{$why}: refused");
                } catch (CwException $e) {
                    self::assertSame('no_key', $e->errorCode, $why);
                    self::assertStringNotContainsString(self::KEY, $e->getMessage(), $why);
                    self::assertStringNotContainsString('other-test-key', $e->getMessage(), $why);
                }
            }
            chmod($file, 0600);
            // the Codex check r1, LOW 15: root's only (a file another user owns, a link to one, is refused)
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                chown($file, 65534);
                try {
                    CarrierKeys::key('click_and_drop_0706703000', $file);
                    self::fail('a file another user owns: refused');
                } catch (CwException $e) {
                    self::assertSame('no_key', $e->errorCode);
                }
                chown($file, 0);
                symlink($file, $dir . '/link.env');
                try {
                    CarrierKeys::key('click_and_drop_0706703000', $dir . '/link.env');
                    self::fail('a link: refused');
                } catch (CwException $e) {
                    self::assertSame('no_key', $e->errorCode);
                } finally {
                    @unlink($dir . '/link.env');
                }
            }
            file_put_contents($file, "this line is not a key " . self::KEY . "\n");
            try {
                CarrierKeys::key('click_and_drop_0706703000', $file);
                self::fail('a bad line: refused');
            } catch (CwException $e) {
                self::assertStringNotContainsString(self::KEY, $e->getMessage());
            }
            try {
                CarrierKeys::key('click_and_drop_0706703000', $dir . '/missing.env');
                self::fail('no file: refused');
            } catch (CwException $e) {
                self::assertSame('no_key', $e->errorCode);
            }
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }
}
