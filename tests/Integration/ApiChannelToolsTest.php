<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Api\ApiKey;
use CW\Tests\Support\ApiTestCase;
use CW\Tests\Support\TestDb;

/** bin/create_channel.php and bin/rotate_key.php, end to end: the printed key works over HTTP. */
final class ApiChannelToolsTest extends ApiTestCase
{
    public function testCreateChannelPrintsAWorkingKeyOnceAndRotateReplacesIt(): void
    {
        [$status, $out, $err] = self::tool('create_channel.php', '--code=newsite', '--name=New site', '--ips=127.0.0.1,198.51.100.0/24', '--mode=shadow',
            '--movement-types=goods_in,erp_sale,supplier_return');
        self::assertSame(0, $status, $err);
        self::assertMatchesRegularExpression('/^cwk_[0-9a-f]{64}\n$/', $out, 'stdout is the key and nothing else');
        self::assertStringContainsString('shown ONLY now', $err);
        $key = trim($out);

        $ch = self::$db->one("SELECT id, mode, api_key_hash, allowed_ips, reserve_ttl_sec, movement_types FROM channel WHERE code = 'newsite'");
        self::assertSame(['erp_sale', 'goods_in', 'supplier_return'], json_decode((string) $ch['movement_types'], true), 'the ERP relay site (R17)');
        self::assertSame(['shadow', ApiKey::hash($key), 2400], [$ch['mode'], $ch['api_key_hash'], $ch['reserve_ttl_sec']]);
        self::assertSame(['127.0.0.1', '198.51.100.0/24'], json_decode((string) $ch['allowed_ips'], true));
        self::assertSame(self::warehouseId('MAIN'), (int) self::$db->value('SELECT warehouse_id FROM channel_warehouse WHERE channel_id = ? AND is_sellable = 1', [$ch['id']]));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'channel.create' AND entity_id = 'newsite'"));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM audit_log WHERE detail LIKE ?', ['%' . $key . '%']), 'the key is never stored');

        $r = self::assertEnvelope($this->call('GET', '/v1/health', $key), 200);
        self::assertSame(['newsite', 'shadow'], [$r->data()['channel'], $r->data()['mode']]);

        // A second create with the same code is refused and prints no key.
        [$status, $out, $err] = self::tool('create_channel.php', '--code=newsite', '--name=Again');
        self::assertSame([1, ''], [$status, $out]);
        self::assertStringContainsString('already exists', $err);

        // Rotation: the old key stops working at once, the new one works.
        [$status, $out, $err] = self::tool('rotate_key.php', '--code=newsite');
        self::assertSame(0, $status, $err);
        $newKey = trim($out);
        self::assertMatchesRegularExpression('/^cwk_[0-9a-f]{64}$/', $newKey);
        self::assertNotSame($key, $newKey);
        self::assertEnvelope($this->call('GET', '/v1/health', $key), 401, 'unauthorized');
        self::assertEnvelope($this->call('GET', '/v1/health', $newKey), 200);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'channel.key_rotate'"));

        [$status, $out] = self::tool('rotate_key.php', '--code=nosuch');
        self::assertSame([1, ''], [$status, $out]);
    }

    public function testCreateChannelDefaultsFailClosedAndValidates(): void
    {
        [$status, $out, $err] = self::tool('create_channel.php', '--code=quiet', '--name=Quiet site');
        self::assertSame(0, $status, $err);
        self::assertStringContainsString('every call is refused', $err);
        self::assertSame('off', self::$db->value("SELECT mode FROM channel WHERE code = 'quiet'"));
        self::assertSame([], json_decode((string) self::$db->value("SELECT movement_types FROM channel WHERE code = 'quiet'"), true), 'no movements by default (R17)');
        self::assertEnvelope($this->call('GET', '/v1/health', trim($out)), 403, 'forbidden');

        foreach ([
            ['--code=Bad-Code', '--name=x'],
            ['--code=ok_code', '--name=x', '--ips=10.0.0.0/0'],
            ['--code=ok_code', '--name=x', '--ips=not-an-ip'],
            ['--code=ok_code', '--name=x', '--mode=on'],
            ['--code=ok_code', '--name=x', '--warehouse=VERIFY'],
            ['--code=ok_code', '--name=x', '--warehouse=NOPE'],
            ['--code=ok_code', '--name=x', '--ttl=5'],
            ['--code=ok_code', '--name=x', '--movement-types=goods_in,count'],
        ] as $args) {
            [$status, $out, $err] = self::tool('create_channel.php', ...$args);
            self::assertSame([1, ''], [$status, $out], implode(' ', $args) . ': ' . $err);
        }
        self::assertNull(self::$db->value("SELECT id FROM channel WHERE code = 'ok_code'"));
    }

    /** @return array{0: int, 1: string, 2: string} exit status, stdout, stderr */
    private static function tool(string $script, string ...$args): array
    {
        $cmd = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/' . $script, ...$args, '--db=' . TestDb::name()];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out, $err];
    }
}
