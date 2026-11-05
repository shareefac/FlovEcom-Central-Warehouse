<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Admin\ApiCredentials;
use CW\Config;
use CW\CwException;
use CW\Invoices\ErpAddressRefused;
use CW\Invoices\ErpApiReader;
use CW\Invoices\ErpHttpTransport;
use CW\Invoices\ErpTransport;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * Settings › APIs without a database (0063; docs/decisions.md AK1-AK12): the address a person may save (https and a name only), the
 * public-only transport that refuses an address leading inside (no network: the look-up is given), "Check the connection" through the
 * reader (ONE GET, its answer in a fixed word, never the key, the secret or ERPNext's answer), and the page's words. Every key and secret
 * here is an obviously fake value.
 */
final class ApiCredentialsUnitTest extends TestCase
{
    private const KEY = 'fakeKey0000ABCD';
    private const SECRET = 'fakeSecret000099';

    public function testTheAddressIsHttpsAndANameOnly(): void
    {
        foreach ([
            'https://vpg-erp.floverfy.com' => 'https://vpg-erp.floverfy.com',
            ' HTTPS://VPG-ERP.Floverfy.COM/ ' => 'https://vpg-erp.floverfy.com',
            'https://erp.example.test' => 'https://erp.example.test',
            'https://a.b.co.uk' => 'https://a.b.co.uk',
            "https://vpg-erp.floverfy.com\n" => 'https://vpg-erp.floverfy.com', // pasted with its line end
        ] as $typed => $saved) {
            self::assertSame($saved, ApiCredentials::url($typed), $typed);
        }
        foreach (['', 'http://vpg-erp.floverfy.com', 'vpg-erp.floverfy.com', 'https://localhost', 'https://127.0.0.1', 'https://10.0.0.5', 'https://[::1]',
            'https://169.254.169.254', 'https://vpg-erp.floverfy.com:8443', 'https://vpg-erp.floverfy.com/api', 'https://vpg-erp.floverfy.com?x=1',
            'https://vpg-erp.floverfy.com#x', 'https://user:pw@vpg-erp.floverfy.com', 'https://user@vpg-erp.floverfy.com', 'https://vpg erp.com',
            "https://vpg-\nerp.floverfy.com", "https://vpg-erp\x00.floverfy.com", 'https://-bad.example.com', 'https://bad-.example.com', 'https://example.123', 'https://exa_mple.com',
            'https://' . str_repeat('a', 60) . '.' . str_repeat('b', 60) . '.' . str_repeat('c', 60) . '.com', 'ftp://vpg-erp.floverfy.com',
            'https://vpg-erp.floverfy.com\\@evil.com', 'https://0x7f000001'] as $bad) {
            try {
                ApiCredentials::url($bad);
                self::fail('refused: ' . json_encode($bad));
            } catch (CwException $e) {
                self::assertSame(['api_bad_url', 422], [$e->errorCode, $e->httpStatus], json_encode($bad));
            }
        }
    }

    public function testOnlyAPublicInternetAddressIsCalledForASavedAddress(): void
    {
        foreach (['138.68.117.234' => true, '8.8.8.8' => true, '127.0.0.1' => false, '10.1.2.3' => false, '172.16.0.1' => false, '192.168.1.1' => false,
            '169.254.169.254' => false, '100.64.0.1' => false, '0.0.0.0' => false, '224.0.0.1' => false, '255.255.255.255' => false, '192.0.2.1' => false,
            '::1' => false, '2606:4700::1' => false, 'not an ip' => false] as $ip => $public) {
            self::assertSame($public, ErpHttpTransport::isPublic($ip), $ip);
        }
        $asked = [];
        $lookup = static function (array $answer) use (&$asked): \Closure {
            return static function (string $host) use ($answer, &$asked): array|false {
                $asked[] = $host;
                return $answer;
            };
        };
        // A name that leads inside (the metadata service, the server itself, a private network, or one address of several): refused, nothing sent.
        foreach ([['169.254.169.254'], ['127.0.0.1'], ['10.0.0.7'], ['138.68.117.234', '192.168.0.9']] as $answer) {
            $t = new ErpHttpTransport(true, $lookup($answer));
            try {
                $t->get('https://erp.example.test/api/resource/Purchase%20Invoice', ['Authorization: token ' . self::KEY . ':' . self::SECRET], 5);
                self::fail('refused: ' . implode(',', $answer));
            } catch (ErpAddressRefused $e) {
                self::assertStringNotContainsString(self::SECRET, $e->getMessage());
                self::assertStringNotContainsString(self::KEY, $e->getMessage());
            }
        }
        self::assertSame(array_fill(0, 4, 'erp.example.test'), $asked, 'the name of the address, looked up');
        // An address that is not a name on the usual port is refused before any look-up.
        $asked = [];
        foreach (['https://127.0.0.1/api', 'https://erp.example.test:8443/api', 'https://[::1]/api', 'https://u:p@erp.example.test/api'] as $url) {
            try {
                (new ErpHttpTransport(true, $lookup(['138.68.117.234'])))->get($url, [], 5);
                self::fail("refused: {$url}");
            } catch (ErpAddressRefused) {
            }
        }
        self::assertSame([], $asked);
        // A name that gives no address: cannot be reached (not "refused").
        try {
            (new ErpHttpTransport(true, static fn (string $h): false => false))->get('https://erp.example.test/x', [], 5);
            self::fail('no address');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(ErpAddressRefused::class, $e);
            self::assertStringContainsString('not found', $e->getMessage());
        }
    }

    public function testCheckMakesOneGetAndSaysHowItEndedInAWord(): void
    {
        $answers = [
            'ok' => [200, ['data' => [['name' => 'ACC-PINV-2026-00001']]], null],
            'ok_empty' => [200, ['data' => []], null],
            'wrong_key' => [401, ['exc_type' => 'AuthenticationError'], null],
            'wrong_key_403' => [403, ['exc_type' => 'AuthenticationError'], null],
            'not_allowed' => [403, ['exc_type' => 'PermissionError', 'exception' => 'User ' . self::KEY . ' has no read'], null],
            'other_500' => [500, ['exc_type' => 'Whatever', 'exception' => self::SECRET], 500],
            'other_html' => [200, '<html>login</html>', 200],
            'other_302' => [302, '', 302],
        ];
        foreach ($answers as $case => [$status, $body, $code]) {
            $t = new class ($status, $body) implements ErpTransport {
                /** @var list<array{url: string, headers: list<string>, timeout: int}> */
                public array $calls = [];

                public function __construct(private readonly int $status, private readonly mixed $body)
                {
                }

                public function get(string $url, array $headers, int $timeoutSec): array
                {
                    $this->calls[] = ['url' => $url, 'headers' => $headers, 'timeout' => $timeoutSec];
                    return ['status' => $this->status, 'body' => is_string($this->body) ? $this->body : (string) json_encode($this->body)];
                }
            };
            $r = new ErpApiReader($t, 'https://erp.example.test', self::KEY, self::SECRET, 10);
            $out = $r->check();
            $expect = explode('_', $case)[0] === 'other' ? 'other' : (str_starts_with($case, 'wrong_key') ? 'wrong_key' : (str_starts_with($case, 'ok') ? 'ok' : $case));
            self::assertSame(['outcome' => $expect, 'status' => $code], $out, $case);
            self::assertContains($out['outcome'], ErpApiReader::CHECKS);
            self::assertCount(1, $t->calls, "{$case}: ONE read");
            self::assertSame('https://erp.example.test/api/resource/Purchase%20Invoice?fields=%5B%22name%22%5D&limit_page_length=1', $t->calls[0]['url']);
            self::assertSame(['Authorization: token ' . self::KEY . ':' . self::SECRET, 'Accept: application/json'], $t->calls[0]['headers']);
            self::assertSame(10, $t->calls[0]['timeout']);
            self::assertStringNotContainsString(self::SECRET, (string) json_encode($out), $case);
        }
        $down = new class () implements ErpTransport {
            public function get(string $url, array $headers, int $timeoutSec): array
            {
                throw new \RuntimeException('ERPNext could not be reached (curl error 7)');
            }
        };
        self::assertSame(['outcome' => 'unreachable', 'status' => null], (new ErpApiReader($down, 'https://erp.example.test', self::KEY, self::SECRET))->check());
        $refused = new class () implements ErpTransport {
            public function get(string $url, array $headers, int $timeoutSec): array
            {
                throw new ErpAddressRefused('refused');
            }
        };
        self::assertSame(['outcome' => 'not_public', 'status' => null], (new ErpApiReader($refused, 'https://erp.example.test', self::KEY, self::SECRET))->check());
        // What a screen or an audit row may name of the reader: the address and the key's last 4, never the key or the secret.
        $r = new ErpApiReader($down, 'https://erp.example.test/', self::KEY, self::SECRET);
        self::assertSame(['https://erp.example.test', 'ABCD', 'given'], [$r->base(), $r->keyLast4(), $r->source()]);
        self::assertStringNotContainsString(self::SECRET, print_r($r, true));
    }

    public function testWithoutADatabaseTheReaderIsAppEnvsAndAMissingKeyNamesBothPlaces(): void
    {
        $r = ErpApiReader::fromConfig(Config::fromArrays([], ['erp_read_url' => 'https://erp.example.test', 'erp_read_key' => 'envKey123', 'erp_read_secret' => 'envSecret456']),
            new class () implements ErpTransport {
                public function get(string $url, array $headers, int $timeoutSec): array
                {
                    return ['status' => 200, 'body' => '{"data":[]}'];
                }
            });
        self::assertSame(['app_env', 'y123'], [$r->source(), $r->keyLast4()]);
        try {
            ErpApiReader::fromConfig(Config::fromArrays([], []));
            self::fail('nothing set up');
        } catch (CwException $e) {
            self::assertSame(['erp_read_unconfigured', 503], [$e->errorCode, $e->httpStatus]);
            self::assertStringContainsString('Settings > APIs', $e->getMessage());
            self::assertStringContainsString('missing in app.env: erp_read_url, erp_read_key, erp_read_secret', $e->getMessage());
        }
    }

    public function testEveryCodeOfThePageHasItsPlainWords(): void
    {
        foreach (ApiCredentials::OUTCOMES as $o) {
            self::assertTrue(Words::has('APIS_CHECK', $o), $o);
            self::assertMatchesRegularExpression('/^[A-Z].*\.$/', Words::APIS_CHECK[$o], "{$o}: a full sentence");
        }
        self::assertSame([], array_values(array_diff(ErpApiReader::CHECKS, ApiCredentials::OUTCOMES)), 'every answer of the reader is an outcome');
        self::assertSame(1, substr_count(Words::APIS_CHECK['other'], '%s'), 'the code');
        // Every refusal Admin\ApiCredentials throws has words that say nothing was saved.
        preg_match_all("/new CwException\\('(api_[a-z_]+)'/", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/ApiCredentials.php'), $m);
        $codes = array_values(array_unique(array_diff($m[1], ['api_unknown'])));
        self::assertGreaterThanOrEqual(7, count($codes));
        foreach ([...$codes, 'api_confirm', 'saved_twice', 'bad_version'] as $code) {
            self::assertArrayHasKey($code, Words::APIS_ERROR, $code);
            self::assertMatchesRegularExpression('/^(Nothing was (saved|removed)|This form was sent already)/', Words::APIS_ERROR[$code], $code);
        }
        foreach (Words::APIS_NOTICE as $code => $text) {
            self::assertMatchesRegularExpression('/^[A-Z].*\.$/', $text, "{$code}: a full sentence");
        }
        foreach (['api.save', 'api.remove', 'api.check'] as $action) {
            self::assertTrue(Words::has('AUDIT_ACTION', $action), $action);
        }
        self::assertSame(['API keys', 'API key'], [Words::AUDIT_FAMILY['api'], Words::AUDIT_RECORD['api_credential']]);
        self::assertSame(['APIs', 'APIs', 'APIs'], [Words::SEGMENT['apis'], Words::RD_SETTINGS['apis'], Words::APIS['title']]);
        self::assertSame('https://vpg-erp.floverfy.com', ApiCredentials::DEFAULT_URL[ApiCredentials::ERP_READ]);
        self::assertSame(ApiCredentials::DEFAULT_URL[ApiCredentials::ERP_READ], ApiCredentials::url(ApiCredentials::DEFAULT_URL[ApiCredentials::ERP_READ]), 'the default saves as it is');
    }
}
