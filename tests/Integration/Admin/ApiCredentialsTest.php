<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Admin;

use CW\Admin\ApiCredentials;
use CW\Caller;
use CW\Config;
use CW\CwException;
use CW\Invoices\ErpApiReader;
use CW\Staff\SecretBox;
use CW\Tests\Support\ErpFakeTransport;
use CW\Tests\Support\IntegrationTestCase;

/**
 * The keys of the APIs CW calls (0063, Admin\ApiCredentials; docs/decisions.md AK1-AK8): a save seals the key and the secret (the stored
 * bytes are not them, and open with this server's key only), shows the key's last 4 and nothing more, and is audited without them; a later
 * save keeps what is left empty and replaces what is typed; a form drawn at another version, a person who may not change settings and
 * every malformed value are refused with nothing written; a removal empties the row. The ERPNext reader prefers the saved key to app.env's,
 * falls back to app.env when nothing is saved, and never falls back when the saved key cannot be opened. Fake keys only.
 */
final class ApiCredentialsTest extends IntegrationTestCase
{
    private const KEY = 'fakeKey0000ABCD';
    private const SECRET = 'fakeSecret000099';
    private const ENV = ['erp_read_url' => 'https://env-erp.example.test', 'erp_read_key' => 'envFakeKey123', 'erp_read_secret' => 'envFakeSecret456'];

    private static SecretBox $box;
    /** The base64 of the box's key (as app.env's ui_secret_key holds it: the cron's way). */
    private static string $boxKey;
    private int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$boxKey = SecretBox::newKeyBase64();
        self::$box = SecretBox::fromBase64(self::$boxKey);
    }

    /** An audit row's detail, its keys in order (MySQL's JSON keeps its own order). @param array<string, mixed> $row @return array<string, mixed> */
    private static function detail(array $row): array
    {
        return self::sorted((array) json_decode((string) $row['detail'], true));
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private static function sorted(array $a): array
    {
        ksort($a);
        return $a;
    }

    private function staff(string $role): Caller
    {
        $n = ++$this->seq;
        $id = self::$db->insert('INSERT INTO staff_user (username, display_name, email, password_hash, is_active) VALUES (?, ?, ?, ?, 1)',
            ["{$role}{$n}@test.invalid", ucfirst($role) . " {$n}", "{$role}{$n}@test.invalid", 'x']);
        self::$db->exec('INSERT INTO staff_role (staff_user_id, role) VALUES (?, ?)', [$id, $role]);
        return Caller::staff($id);
    }

    /** @return array<string, mixed> */
    private static function row(): array
    {
        return (array) self::$db->one("SELECT * FROM api_credential WHERE name = 'erp_read'");
    }

    /** Every audit row of the APIs page, and the whole audit log as text (to look for a key or a secret in it). @return array{0: list<array<string, mixed>>, 1: string} */
    private static function audit(): array
    {
        $rows = self::$db->all("SELECT action, entity_type, entity_id, CAST(detail AS CHAR) AS detail FROM audit_log WHERE action LIKE 'api.%' ORDER BY id");
        return [$rows, (string) json_encode(self::$db->all('SELECT * FROM audit_log'))];
    }

    private static function refused(int $status, string $code, callable $fn): CwException
    {
        try {
            $fn();
        } catch (CwException $e) {
            self::assertSame([$code, $status], [$e->errorCode, $e->httpStatus], $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            return $e;
        }
        self::fail("expected {$code}");
    }

    public function testASaveIsSealedShownAsItsLastFourOnlyAndAuditedWithoutTheKey(): void
    {
        $c = new ApiCredentials(self::$db);
        self::assertSame(['erp_read', false, null, null, 0, null], [$c->shown('erp_read')['name'], $c->shown('erp_read')['saved'], $c->shown('erp_read')['url'],
            $c->shown('erp_read')['key_last4'], $c->shown('erp_read')['version'], $c->shown('erp_read')['updated_by']], 'made by 0063, empty');
        self::assertNull(ApiCredentials::open(self::$db, 'erp_read', self::$box));
        $rev = $this->staff('reviewer');
        $r = $c->save($rev, 'erp_read', 0, ' HTTPS://VPG-ERP.Floverfy.com/ ', ' ' . self::KEY . ' ', self::SECRET . "\n", self::$box);
        self::assertSame(['result' => 'saved', 'version' => 1, 'key_last4' => 'ABCD'], $r);

        $row = self::row();
        self::assertSame(['https://vpg-erp.floverfy.com', 'ABCD', 1, $rev->staffUserId, $rev->actor, 'system:migrate'],
            [$row['url'], $row['key_last4'], (int) $row['version'], (int) $row['updated_by'], $row['updated_actor'], $row['created_actor']]);
        foreach (['key_enc' => self::KEY, 'secret_enc' => self::SECRET] as $col => $plain) {
            $sealed = (string) $row[$col];
            self::assertNotSame($plain, $sealed, "{$col}: not the plain text");
            self::assertStringNotContainsString($plain, $sealed, $col);
            self::assertSame(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + strlen($plain), strlen($sealed), "{$col}: nonce || box");
            self::assertSame($plain, self::$box->decrypt($sealed), "{$col}: opens with this server's key");
        }
        self::assertNotSame(substr((string) $row['key_enc'], 0, 24), substr((string) $row['secret_enc'], 0, 24), 'a nonce each');
        $shown = $c->shown('erp_read');
        self::assertSame([true, 'https://vpg-erp.floverfy.com', 'ABCD', 1, 'Reviewer 1'], [$shown['saved'], $shown['url'], $shown['key_last4'], $shown['version'],
            $shown['updated_by_name']]);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($shown));
        self::assertStringNotContainsString(self::KEY, (string) json_encode($shown));
        self::assertSame(['url' => 'https://vpg-erp.floverfy.com', 'key' => self::KEY, 'secret' => self::SECRET], ApiCredentials::open(self::$db, 'erp_read', self::$box));

        [$rows, $all] = self::audit();
        self::assertCount(1, $rows);
        self::assertSame(['api.save', 'api_credential', 'erp_read'], [$rows[0]['action'], $rows[0]['entity_type'], $rows[0]['entity_id']]);
        self::assertSame(self::sorted(['name' => 'erp_read', 'url' => 'https://vpg-erp.floverfy.com', 'key_ends' => 'ABCD', 'key' => 'new', 'secret' => 'new', 'version' => 1]),
            self::detail($rows[0]));
        self::assertStringNotContainsString(self::SECRET, $all, 'never the secret in the audit log');
        self::assertStringNotContainsString(self::KEY, $all, 'never the key in the audit log');

        // The reader prefers the saved key to app.env's, and calls the saved address.
        $erp = new ErpFakeTransport();
        $reader = ErpApiReader::fromConfig(Config::fromArrays([], self::ENV), $erp, self::$db, self::$box);
        self::assertSame(['saved', 'https://vpg-erp.floverfy.com', 'ABCD'], [$reader->source(), $reader->base(), $reader->keyLast4()]);
        $reader->changed('VAPE AND GO', '2026-09-01', null);
        self::assertSame('Authorization: token ' . self::KEY . ':' . self::SECRET, $erp->calls[0]['headers'][0]);
        self::assertStringStartsWith('https://vpg-erp.floverfy.com/api/resource/', $erp->calls[0]['url']);
        // Without the database (the old way) it is app.env's.
        self::assertSame('app_env', ErpApiReader::fromConfig(Config::fromArrays([], self::ENV), $erp)->source());
    }

    public function testALaterSaveKeepsWhatIsLeftEmptyAndReplacesWhatIsTyped(): void
    {
        $c = new ApiCredentials(self::$db);
        $admin = $this->staff('admin'); // settings.manage: an Admin too (Y1)
        $c->save($admin, 'erp_read', 0, 'https://vpg-erp.floverfy.com', self::KEY, self::SECRET, self::$box);
        $first = self::row();

        self::assertSame(['result' => 'unchanged', 'version' => 1, 'key_last4' => 'ABCD'], $c->save($admin, 'erp_read', 1, 'https://vpg-erp.floverfy.com/', '', '  ', self::$box));
        self::assertSame($first, self::row(), 'nothing written');

        // A new secret only: the key's sealed bytes stay as they were.
        $c->save($admin, 'erp_read', 1, 'https://vpg-erp.floverfy.com', '', 'fakeSecretNEW222', self::$box);
        $two = self::row();
        self::assertSame([$first['key_enc'], 'ABCD', 2], [$two['key_enc'], $two['key_last4'], (int) $two['version']]);
        self::assertNotSame($first['secret_enc'], $two['secret_enc']);
        self::assertSame('fakeSecretNEW222', self::$box->decrypt((string) $two['secret_enc']));

        // A new key only: the secret's sealed bytes stay.
        $c->save($admin, 'erp_read', 2, 'https://vpg-erp.floverfy.com', 'fakeKeyNEW0WXYZ', '', self::$box);
        $three = self::row();
        self::assertSame([$two['secret_enc'], 'WXYZ', 3], [$three['secret_enc'], $three['key_last4'], (int) $three['version']]);
        self::assertSame('fakeKeyNEW0WXYZ', self::$box->decrypt((string) $three['key_enc']));

        // Another address: only a name the server trusts (AK12), and never with a saved key or secret (the Codex review of 9ddf7a0, HIGH):
        // both are typed again, so a saved key is never sent anywhere it was not typed for.
        $e = self::refused(422, 'api_host_not_allowed', fn () => $c->save($admin, 'erp_read', 3, 'https://erp2.example.test', 'fakeKeyEVIL0000', 'fakeSecretEVIL00', self::$box));
        self::assertSame('erp2.example.test', $e->detail['host']);
        self::refused(422, 'api_host_not_allowed', fn () => $c->save($admin, 'erp_read', 3, 'https://erp2.example.test', '', '', self::$box, Config::fromArrays([], self::ENV)));
        $trusted = Config::fromArrays([], ['erp_read_hosts' => 'erp2.example.test, nope_not_a_name'] + self::ENV);
        foreach ([['', ''], ['fakeKeyNEW0WXYZ', ''], ['', 'fakeSecretNEW333']] as [$k, $sec]) {
            self::refused(422, 'api_new_address', fn () => $c->save($admin, 'erp_read', 3, 'https://erp2.example.test', $k, $sec, self::$box, $trusted));
        }
        self::assertSame($three, self::row(), 'nothing written');
        $c->save($admin, 'erp_read', 3, 'https://erp2.example.test', 'fakeKeyERP20000', 'fakeSecretERP200', self::$box, $trusted);
        $four = self::row();
        self::assertSame(['https://erp2.example.test', '0000', 4], [$four['url'], $four['key_last4'], (int) $four['version']]);
        self::assertSame(['fakeKeyERP20000', 'fakeSecretERP200'], [self::$box->decrypt((string) $four['key_enc']), self::$box->decrypt((string) $four['secret_enc'])]);
        [$rows, $all] = self::audit();
        self::assertSame(['api.save', 'api.save', 'api.save', 'api.save'], array_column($rows, 'action'), 'the unchanged save and the refusals wrote nothing');
        self::assertSame(['key' => 'kept', 'secret' => 'new'], array_intersect_key(self::detail($rows[1]), ['key' => 1, 'secret' => 1]));
        self::assertSame(self::sorted(['name' => 'erp_read', 'url_before' => 'https://vpg-erp.floverfy.com', 'url' => 'https://erp2.example.test', 'key_ends' => '0000',
            'key' => 'new', 'secret' => 'new', 'version' => 4]), self::detail($rows[3]));
        foreach ([self::KEY, self::SECRET, 'fakeSecretNEW222', 'fakeKeyNEW0WXYZ', 'fakeKeyERP20000', 'fakeSecretERP200'] as $plain) {
            self::assertStringNotContainsString($plain, $all, 'never a key or a secret in the audit log');
        }

        // A form drawn before the last save: refused, naming who saved it; nothing written.
        $e = self::refused(409, 'api_changed', fn () => $c->save($admin, 'erp_read', 3, 'https://erp2.example.test', '', 'fakeSecretNEW333', self::$box, $trusted));
        self::assertSame('Admin 1', $e->detail['by']);
        self::refused(409, 'api_changed', fn () => $c->remove($admin, 'erp_read', 1));
        self::assertSame($four, self::row());
    }

    public function testEveryRefusalWritesNothing(): void
    {
        $c = new ApiCredentials(self::$db);
        $rev = $this->staff('reviewer');
        foreach (['buyer', 'auditor', 'manager', 'stock_controller'] as $role) {
            self::refused(403, 'role_not_allowed', fn () => $c->save($this->staff($role), 'erp_read', 0, 'https://vpg-erp.floverfy.com', self::KEY, self::SECRET, self::$box));
        }
        self::refused(422, 'api_need_key', fn () => $c->save($rev, 'erp_read', 0, 'https://vpg-erp.floverfy.com', '', self::SECRET, self::$box));
        self::refused(422, 'api_need_secret', fn () => $c->save($rev, 'erp_read', 0, 'https://vpg-erp.floverfy.com', self::KEY, '', self::$box));
        foreach (['http://vpg-erp.floverfy.com', 'https://127.0.0.1', 'https://vpg-erp.floverfy.com:8080', 'https://vpg-erp.floverfy.com/x', 'https://localhost'] as $url) {
            self::refused(422, 'api_bad_url', fn () => $c->save($rev, 'erp_read', 0, $url, self::KEY, self::SECRET, self::$box));
        }
        foreach (['fake:key000', 'short', str_repeat('k', 65), 'fake key 0000'] as $bad) {
            self::refused(422, 'api_bad_key', fn () => $c->save($rev, 'erp_read', 0, 'https://vpg-erp.floverfy.com', $bad, self::SECRET, self::$box));
            self::refused(422, 'api_bad_secret', fn () => $c->save($rev, 'erp_read', 0, 'https://vpg-erp.floverfy.com', self::KEY, $bad, self::$box));
        }
        self::assertSame([0, null, null], [(int) self::row()['version'], self::row()['key_enc'], self::row()['url']]);
        self::refused(403, 'role_not_allowed', fn () => $c->remove($this->staff('buyer'), 'erp_read', 0));

        // A kept key or secret this server can no longer open (the key of the 2-step codes changed): typed again, never kept unusable.
        $c->save($rev, 'erp_read', 0, 'https://vpg-erp.floverfy.com', self::KEY, self::SECRET, self::$box);
        $saved = self::row();
        $other = SecretBox::fromBase64(SecretBox::newKeyBase64());
        self::refused(422, 'api_cannot_open', fn () => $c->save($rev, 'erp_read', 1, 'https://vpg-erp.floverfy.com', '', 'fakeSecretNEW222', $other));
        self::refused(422, 'api_cannot_open', fn () => $c->save($rev, 'erp_read', 1, 'https://vpg-erp.floverfy.com', 'fakeKeyNEW0WXYZ', '', $other));
        self::assertSame($saved, self::row());
        $c->save($rev, 'erp_read', 1, 'https://vpg-erp.floverfy.com', 'fakeKeyNEW0WXYZ', 'fakeSecretNEW222', $other);
        self::assertSame('fakeSecretNEW222', $other->decrypt((string) self::row()['secret_enc']), 'both typed again: saved with this server\'s key');
        [$rows] = self::audit();
        self::assertSame(['api.save', 'api.save'], array_column($rows, 'action'), 'only the two saves are in the log');
    }

    public function testARemovalEmptiesTheRowAndTheReaderGoesBackToAppEnv(): void
    {
        $c = new ApiCredentials(self::$db);
        $rev = $this->staff('reviewer');
        $c->save($rev, 'erp_read', 0, 'https://vpg-erp.floverfy.com', self::KEY, self::SECRET, self::$box);

        // A saved key this server cannot open: the reader stops, never silently using app.env's instead.
        $other = SecretBox::fromBase64(SecretBox::newKeyBase64());
        $e = self::refused(503, 'api_cannot_open', fn () => ErpApiReader::fromConfig(Config::fromArrays([], self::ENV), new ErpFakeTransport(), self::$db, $other));
        self::assertStringContainsString('Settings > APIs', $e->getMessage());
        // The box from app.env's ui_secret_key (the cron's way): none there, or one that does not open it.
        self::refused(503, 'api_cannot_open', fn () => ErpApiReader::fromConfig(Config::fromArrays([], self::ENV), new ErpFakeTransport(), self::$db));
        self::refused(503, 'api_cannot_open', fn () => ErpApiReader::fromConfig(Config::fromArrays([], self::ENV + ['ui_secret_key' => SecretBox::newKeyBase64()]),
            new ErpFakeTransport(), self::$db));
        $cron = ErpApiReader::fromConfig(Config::fromArrays([], ['ui_secret_key' => self::$boxKey] + self::ENV), new ErpFakeTransport(), self::$db);
        self::assertSame('saved', $cron->source(), 'app.env\'s ui_secret_key opens it');

        // A row written around the page with a name CW does not trust: never called (AK12); trusted by app.env, it is.
        $sealed = self::row();
        self::$db->exec("UPDATE api_credential SET url = 'https://evil.example.com' WHERE name = 'erp_read'");
        $erp = new ErpFakeTransport();
        self::refused(503, 'api_host_not_allowed', fn () => ErpApiReader::fromConfig(Config::fromArrays([], self::ENV), $erp, self::$db, self::$box));
        self::assertSame([], $erp->calls, 'nothing sent');
        self::assertSame('https://evil.example.com', ErpApiReader::fromConfig(Config::fromArrays([], ['erp_read_hosts' => 'evil.example.com'] + self::ENV), $erp, self::$db,
            self::$box)->base());
        self::$db->exec("UPDATE api_credential SET url = ? WHERE name = 'erp_read'", [$sealed['url']]);

        self::assertSame(['result' => 'removed', 'version' => 2], $c->remove($rev, 'erp_read', 1));
        $row = self::row();
        self::assertSame([null, null, null, null, 2, $rev->staffUserId], [$row['url'], $row['key_enc'], $row['key_last4'], $row['secret_enc'], (int) $row['version'],
            (int) $row['updated_by']]);
        self::assertSame(['result' => 'unchanged', 'version' => 2], $c->remove($rev, 'erp_read', 2), 'nothing saved: nothing to remove');
        self::assertFalse($c->shown('erp_read')['saved']);
        self::assertNull(ApiCredentials::open(self::$db, 'erp_read', self::$box));

        $erp = new ErpFakeTransport();
        $reader = ErpApiReader::fromConfig(Config::fromArrays([], self::ENV), $erp, self::$db, self::$box);
        self::assertSame(['app_env', 'https://env-erp.example.test'], [$reader->source(), $reader->base()]);
        $reader->changed('VAPE AND GO', '2026-09-01', null);
        self::assertSame('Authorization: token envFakeKey123:envFakeSecret456', $erp->calls[0]['headers'][0]);
        [$rows, $all] = self::audit();
        self::assertSame(['api.save', 'api.remove'], array_column($rows, 'action'));
        self::assertSame(self::sorted(['name' => 'erp_read', 'url' => 'https://vpg-erp.floverfy.com', 'key_ends' => 'ABCD', 'version' => 2]), self::detail($rows[1]));
        self::assertStringNotContainsString(self::SECRET, $all);
        self::assertStringNotContainsString(self::KEY, $all);
    }

    public function testACheckIsAuditedWithoutTheKey(): void
    {
        $c = new ApiCredentials(self::$db);
        $rev = $this->staff('reviewer');
        $c->recordCheck($rev, 'erp_read', 'saved', 'wrong_key', null, 'https://vpg-erp.floverfy.com', 'ABCD');
        $c->recordCheck($rev, 'erp_read', 'none', 'nothing', null, null, null);
        $c->recordCheck($rev, 'erp_read', 'app_env', 'other', 502, 'https://env-erp.example.test', 'y123');
        [$rows, $all] = self::audit();
        self::assertSame(array_map(self::sorted(...), [
            ['name' => 'erp_read', 'source' => 'saved', 'outcome' => 'wrong_key', 'url' => 'https://vpg-erp.floverfy.com', 'key_ends' => 'ABCD'],
            ['name' => 'erp_read', 'source' => 'none', 'outcome' => 'nothing'],
            ['name' => 'erp_read', 'source' => 'app_env', 'outcome' => 'other', 'status' => 502, 'url' => 'https://env-erp.example.test', 'key_ends' => 'y123'],
        ]), array_map(self::detail(...), $rows));
        self::assertStringNotContainsString(self::SECRET, $all);
        self::assertSame(['api.check', 'api.check', 'api.check'], array_column($rows, 'action'));
        $this->expectException(\InvalidArgumentException::class);
        $c->recordCheck($rev, 'erp_read', 'saved', 'the answer was ' . self::SECRET, null, null, null);
    }
}
