<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Tests\Support\ApiTestCase;
use CW\Tests\Support\TestDb;

/** The JSON envelope, request validation before the core, and errors that never leak internals. */
final class ApiEnvelopeTest extends ApiTestCase
{
    public function testSuccessAndErrorEnvelopesAndHeaders(): void
    {
        [$site, $key] = $this->apiSite();
        $ok = self::assertEnvelope($this->call('GET', '/v1/health', $key), 200);
        self::assertSame('application/json; charset=utf-8', $ok->header('content-type'));
        self::assertSame('no-store', $ok->header('cache-control'));
        self::assertSame('nosniff', $ok->header('x-content-type-options'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $ok->header('x-request-id'));
        self::assertNull($ok->header('x-powered-by'));
        self::assertNull($ok->header('access-control-allow-origin'), 'no CORS');

        $this->listing($site, 'V1', $this->item('strict', 1));
        $err = self::assertEnvelope($this->call('POST', '/v1/reservations', $key,
            ['order_ref' => '1', 'lines' => [self::line('V1', 'a', 'b')]]), 409, 'refused');
        self::assertSame('the order cannot be held; see data.lines', $err->message());
        self::assertSame('short', $err->data()['lines'][0]['result']);
        self::assertSame(1, $err->data()['lines'][0]['available']);
        self::assertArrayNotHasKey('error', $err->data(), 'the error is not repeated inside data');
    }

    public function testMalformedRequestsAreRefusedAndStoreNothing(): void
    {
        [$site, $key] = $this->apiSite();
        $this->listing($site, 'V1', $this->item('strict', 5));
        $good = ['order_ref' => '1', 'lines' => [self::line('V1', 'a')]];
        $before = $this->fingerprint();
        $cases = [
            'invalid JSON' => [400, 'bad_json', '{"order_ref": ', 'application/json'],
            'JSON list' => [400, 'bad_json', '[1, 2]', 'application/json'],
            'JSON scalar' => [400, 'bad_json', '"x"', 'application/json'],
            'empty body' => [400, 'bad_json', '', 'application/json'],
            'form body' => [415, 'unsupported_media_type', 'order_ref=1', 'application/x-www-form-urlencoded'],
            'no content type' => [415, 'unsupported_media_type', json_encode($good), null],
        ];
        foreach ($cases as $what => [$status, $code, $raw, $type]) {
            $r = $this->call('POST', '/v1/reservations', $key, headers: ['Content-Type' => $type], raw: $raw);
            self::assertEnvelope($r, $status, $code);
        }
        $bodies = [
            'order_ref too long' => [400, 'bad_order_ref', ['order_ref' => str_repeat('9', 65)] + $good],
            'order_ref an object' => [400, 'bad_order_ref', ['order_ref' => ['x' => 1]] + $good],
            'lines missing' => [400, 'bad_lines', ['order_ref' => '1']],
            'lines an object' => [400, 'bad_lines', ['order_ref' => '1', 'lines' => ['a' => 1]]],
            'qty != units' => [400, 'bad_lines', ['order_ref' => '1', 'lines' => [['variant_id' => 'V1', 'qty' => 2, 'unit_ids' => ['a']]]]],
            'unit twice' => [400, 'duplicate_unit', ['order_ref' => '1', 'lines' => [self::line('V1', 'a', 'a')]]],
        ];
        foreach ($bodies as $what => [$status, $code, $body]) {
            $r = self::assertEnvelope($this->call('POST', '/v1/reservations', $key, $body), $status, $code);
            self::assertStringNotContainsString('Exception', $r->raw, $what);
        }
        self::assertSame($before, $this->fingerprint(), 'a refused request changed something');
        // ...so the same key still works for a good request.
        self::assertEnvelope($this->call('POST', '/v1/reservations', $key, ['order_ref' => ['x' => 1]] + $good, 'same-key'), 400);
        self::assertEnvelope($this->call('POST', '/v1/reservations', $key, $good, 'same-key'), 201);
    }

    public function testBodiesOverTheLimitAre413(): void
    {
        [, $key] = $this->apiSite();
        $raw = '{"order_ref":"1","pad":"' . str_repeat('x', 9 * 1024 * 1024) . '"}';
        self::assertEnvelope($this->call('POST', '/v1/reservations', $key, raw: $raw, headers: ['Content-Type' => 'application/json']), 413, 'body_too_large');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM idempotency'));
    }

    public function testUnexpectedFailuresAnswer500WithoutInternals(): void
    {
        [, $key] = $this->apiSite();
        $user = (string) TestDb::config()->appDbUser();
        $table = '`' . TestDb::name() . '`.`channel_health`';
        // Take away a right the heartbeat needs: the INSERT fails deep inside the transaction.
        self::$db->pdo()->exec("REVOKE INSERT ON {$table} FROM '{$user}'@'%'");
        try {
            $r = self::assertEnvelope($this->call('POST', '/v1/heartbeat', $key, ['outbox_depth' => 1], 'hb-500'), 500, 'internal');
        } finally {
            self::restoreAppGrants();
        }
        $rid = (string) $r->header('x-request-id');
        self::assertSame("internal error (request {$rid})", $r->message());
        self::assertNull($r->data());
        foreach (['SQLSTATE', 'channel_health', 'INSERT', $user, '.php', 'PDO', TestDb::name(), '#0'] as $secret) {
            self::assertStringNotContainsString($secret, $r->raw);
        }
        // Nothing was stored under the key: the retry succeeds.
        $again = self::assertEnvelope($this->call('POST', '/v1/heartbeat', $key, ['outbox_depth' => 1], 'hb-500'), 200);
        self::assertFalse($again->replayed());
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM channel_health'));
    }
}
