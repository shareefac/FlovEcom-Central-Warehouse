<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Staff;

use CW\Caller;
use CW\CwException;
use CW\Staff\StaffAdmin;
use CW\Staff\Totp;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\TestDb;

/**
 * Recovery of an existing staff account (StaffAdmin::reset, bin/reset_staff.php): a new TOTP seed, a
 * new one-time password, switching the account off and on. Accounts are never re-created or deleted
 * (decisions name them). Regression tests of the review finding (secrets lens): no tool could replace
 * a leaked TOTP seed, so a seed from /etc/cw/initial_staff.txt stayed a valid second factor forever.
 */
final class StaffResetTest extends KernelUiTestCase
{
    public function testANewTotpSecretReplacesTheLeakedOneAndEndsEverySession(): void
    {
        $site = $this->site('vpg');
        $sku = $this->item('legacy', 0, 'Rotation item');
        $l = $this->queued($site, 'ROT1', 'Key', $sku, ['product_title' => 'Rotation item']);
        $u = $this->uiUser('mapper');
        $this->decide(Caller::staff($u['id']), 'link', $l, ['sku_id' => $sku, 'proposal_id' => $this->openProposalId($l)]);
        $web = $this->signIn($u);
        $seedBefore = (string) self::$db->value('SELECT totp_secret_enc FROM staff_user WHERE id = ?', [$u['id']]);

        // What used to be the only "recovery" is still refused: re-creating the account, deleting it.
        $e = self::refused(409, 'staff_exists', fn () => (new StaffAdmin(self::$db))->create(Caller::system('t'), $u['email'], 'mapper', self::$box));
        self::assertStringContainsString('already exists', $e->getMessage());
        self::assertSame(1451, self::mysqlError(static fn () => self::$db->exec('DELETE FROM staff_user WHERE id = ?', [$u['id']])));

        $r = (new StaffAdmin(self::$db))->reset(Caller::system('reset_test'), strtoupper($u['email']), self::$box, false, true, null);
        self::assertSame([$u['id'], null, 1], [$r['id'], $r['password'], $r['sessions_ended']]);
        parse_str((string) parse_url((string) $r['otpauth'], PHP_URL_QUERY), $q);
        $newSecret = (string) $q['secret'];
        self::assertNotSame($u['secret'], $newSecret);
        $row = self::$db->one('SELECT totp_secret_enc, totp_last_step, password_must_change, is_active FROM staff_user WHERE id = ?', [$u['id']]);
        self::assertNotSame($seedBefore, (string) $row['totp_secret_enc']);
        self::assertSame($newSecret, self::$box->decrypt((string) $row['totp_secret_enc']));
        self::assertSame([null, 0, 1], [$row['totp_last_step'], $row['password_must_change'], $row['is_active']]);
        self::assertSame('/ui/login', $web->get('/ui/')->location(), 'the reset signed the person out');

        // The leaked seed no longer signs in; the new one does (same password).
        $login = $this->loginService();
        self::assertSame('invalid', $login->attempt($u['email'], $u['password'], self::code($u['secret']), '198.51.100.60', null, null)['status']);
        self::assertSame('ok', $login->attempt($u['email'], $u['password'], Totp::code($newSecret), '198.51.100.60', null, null)['status']);
        $audit = self::$db->one("SELECT actor, detail FROM audit_log WHERE action = 'staff.reset'");
        self::assertSame('system:reset_test', $audit['actor']);
        $detail = (string) $audit['detail'];
        self::assertEquals(['new_password' => false, 'new_totp' => true], array_intersect_key(json_decode($detail, true), ['new_password' => 1, 'new_totp' => 1]));
        self::assertStringNotContainsString($newSecret, $detail, 'no secret in the audit');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_decision WHERE decided_by = ?', [$u['id']]), 'the decisions keep their person');
    }

    public function testANewPasswordIsOneTimeAndDeactivationLocksTheAccountOut(): void
    {
        $u = $this->uiUser('mapping_lead');
        $web = $this->signIn($u);
        $admin = new StaffAdmin(self::$db);
        $r = $admin->reset(Caller::system('reset_test'), $u['email'], null, true, false, null);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{20}$/D', (string) $r['password']);
        self::assertNull($r['otpauth']);
        self::assertSame('/ui/login', $web->get('/ui/')->location());
        $login = $this->loginService();
        self::assertSame('invalid', $login->attempt($u['email'], $u['password'], self::code($u['secret']), '198.51.100.61', null, null)['status']);
        $ok = $login->attempt($u['email'], (string) $r['password'], self::code($u['secret'], 1), '198.51.100.61', null, null);
        self::assertSame(['ok', true], [$ok['status'], $ok['must_change']], 'the new password must be changed at once');

        $off = $admin->reset(Caller::system('reset_test'), $u['email'], null, false, false, false);
        self::assertSame([false, 1], [$off['active'], $off['sessions_ended']]);
        self::assertSame(0, (int) self::$db->value('SELECT is_active FROM staff_user WHERE id = ?', [$u['id']]));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM staff_session WHERE staff_user_id = ? AND revoked = 0', [$u['id']]));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.deactivate'"));
        self::$db->exec('UPDATE staff_user SET totp_last_step = NULL WHERE id = ?', [$u['id']]);
        self::assertSame('invalid', $login->attempt($u['email'], (string) $r['password'], self::code($u['secret']), '198.51.100.62', null, null)['status']);
        self::refused(403, 'staff_not_allowed', fn () => $this->ds->approve(Caller::staff($u['id']), 1));

        $on = $admin->reset(Caller::system('reset_test'), $u['email'], null, false, false, true);
        self::assertTrue($on['active']);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.activate'"));
        self::refused(404, 'unknown_staff', fn () => $admin->reset(Caller::system('t'), 'nobody@test.invalid', null, true, false, null));
        self::refused(400, 'nothing_to_do', fn () => $admin->reset(Caller::system('t'), $u['email'], null, false, false, null));
    }

    public function testTheResetToolPrintsTheNewSecretsOnceAndOnlyThem(): void
    {
        $u = $this->uiUser('mapper');
        $r = self::tool('--email=' . $u['email'], '--new-password', '--new-totp');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertSame(1, preg_match('/^password=([A-Za-z0-9]{20})\notpauth=(otpauth:\/\/totp\/\S+)\n$/D', $r['out'], $m), $r['out']);
        self::assertStringContainsString('shown ONLY now', $r['err']);
        parse_str((string) parse_url($m[2], PHP_URL_QUERY), $q);
        $row = self::$db->one('SELECT password_hash, totp_secret_enc, password_must_change FROM staff_user WHERE id = ?', [$u['id']]);
        self::assertTrue(password_verify($m[1], (string) $row['password_hash']));
        self::assertSame($q['secret'], self::$box->decrypt((string) $row['totp_secret_enc']));
        self::assertSame(1, $row['password_must_change']);

        $off = self::tool('--email=' . $u['email'], '--deactivate');
        self::assertSame([0, ''], [$off['code'], $off['out']], $off['err']);
        self::assertStringContainsString('active=no', $off['err']);
        self::assertSame(2, self::tool('--email=' . $u['email'])['code'], 'nothing asked: usage');
        self::assertSame(2, self::tool('--email=' . $u['email'], '--deactivate', '--activate')['code']);
        self::assertSame(1, self::tool('--email=nobody@test.invalid', '--deactivate')['code']);
    }

    private function openProposalId(int $listingId): int
    {
        return (int) self::$db->value("SELECT id FROM match_proposal WHERE listing_id = ? AND status = 'open'", [$listingId]);
    }

    /** @return array{code: int, out: string, err: string} */
    private static function tool(string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/reset_staff.php", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }
}
