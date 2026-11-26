<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Profit\P2gKey;
use CW\Profit\P2gKeyDrop;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\P2gFixtures;
use CW\Ui\Words;

/**
 * Profit > Delivery bills > Parcel2Go through the real /ui kernel as cw_app (CW-1160, docs/decisions.md PGL6, PGL11): Finance and the Delivery
 * bills job see the labels and the known limit (a card refund is not seen); only the job opens the write-only key page and pastes a key (CSRF
 * on its POST); the pasted key is never shown back, never in the page, kept only as a drop of 0600 for the job; the job's take writes the
 * root-only key file and removes the drop; a planted drop no paste names is refused and removed.
 */
final class Parcel2GoScreensTest extends KernelUiTestCase
{
    private string $drop = '';
    private string $keys = '';
    private string $secret = '';
    private string $public = '';

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/cw_p2g_ui_' . bin2hex(random_bytes(5));
        $this->drop = $base . '/drop';
        $this->keys = $base . '/keys';
        mkdir($this->drop, 0700, true);
        mkdir($this->keys, 0700, true);
        putenv('CW_P2G_KEY_DROP_DIR=' . $this->drop);
        // the drop's sealing pair (made by the go-live kit's apply: the private half root's alone, the public half readable by the web app)
        $this->secret = $base . '/p2g_drop.secret';
        $this->public = $base . '/p2g_drop.pub';
        P2gKeyDrop::keypair($this->secret, $this->public);
        putenv('CW_P2G_DROP_PUBLIC=' . $this->public);
        $vpg = (int) $this->site('vapeandgo', 'live')->channelId;
        $vb = (int) $this->site('vapebig', 'live')->channelId;
        $ef = (int) $this->site('electrofag', 'live')->channelId;
        P2gFixtures::accounts(self::$db, $vpg, $vb, $ef);
    }

    protected function tearDown(): void
    {
        putenv('CW_P2G_KEY_DROP_DIR');
        putenv('CW_P2G_DROP_PUBLIC');
        exec('rm -rf ' . escapeshellarg(dirname($this->drop)));
        parent::tearDown();
    }

    public function testTheLabelsPageAndWhoMayPasteAKey(): void
    {
        $a = (int) self::$db->value("SELECT id FROM carrier_account WHERE key_name = 'p2g_vapeandgo'");
        self::$db->exec("INSERT INTO p2g_line (account_id, order_line_id, source, kind, price_state, first_at, updated_at) VALUES (?, 150278180, 'api', 'parcel', 'unpaid', NOW(6), NOW(6))",
            [$a]);
        $fin = $this->signIn($this->uiUser('finance'));
        $page = $fin->get('/ui/profit/delivery/parcel2go');
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        self::assertStringContainsString('P2G150278180', $page->text());
        self::assertStringContainsString(Words::DELIVERY['p2g_card_refunds'], $page->text(), 'the known limit is said');
        self::assertStringContainsString('Parcel2Go (Vape Big)', $page->text());
        self::assertStringNotContainsString('style=', $page->body);
        self::assertSame(403, $fin->get('/ui/profit/delivery/parcel2go/key')->status, 'Finance looks; only the Delivery bills job pastes a key');
        self::assertStringContainsString('/ui/profit/delivery/parcel2go', $fin->get('/ui/profit/delivery')->body, 'the Delivery bills page links to it');
        self::assertSame(403, $this->signIn($this->uiUser('warehouse'))->get('/ui/profit/delivery/parcel2go')->status);
    }

    public function testAPastedKeyIsKeptWriteOnlyAndTakenByTheJob(): void
    {
        $job = $this->signIn($this->uiUser('delivery_bills'));
        $page = $job->get('/ui/profit/delivery/parcel2go/key');
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        self::assertStringContainsString(Words::DELIVERY['p2g_key_none'], $page->text());
        $form = $page->form('/ui/profit/delivery/parcel2go/key');
        self::assertSame(403, $job->post('/ui/profit/delivery/parcel2go/key', ['account' => 'p2g_vapeandgo', 'client_id' => 'abc-client-1',
            'client_secret' => 'Owner-Chosen-Secret-1'])->status, 'no CSRF: refused');
        self::assertFalse(is_file(P2gKeyDrop::file($this->drop, 'p2g_vapeandgo')));
        $bad = $job->post('/ui/profit/delivery/parcel2go/key', ['csrf' => $form['csrf'], 'account' => 'p2g_vapeandgo', 'client_id' => 'abc-client-1', 'client_secret' => 'short']);
        self::assertSame(403, $bad->status);
        self::assertStringContainsString(Words::DELIVERY['p2g_key_bad'], $bad->text());
        self::assertStringNotContainsString('abc-client-1', $bad->body, 'what was typed is never shown back');
        $ok = $job->post('/ui/profit/delivery/parcel2go/key', ['csrf' => $form['csrf'], 'account' => 'p2g_vapeandgo', 'client_id' => 'abc-client-1',
            'client_secret' => 'Owner-Chosen-Secret-1']);
        self::assertSame(303, $ok->status, $ok->describe());
        $file = P2gKeyDrop::file($this->drop, 'p2g_vapeandgo');
        self::assertSame(0600, fileperms($file) & 07777, 'the drop is 0600');
        $sealed = (string) file_get_contents($file);
        self::assertStringNotContainsString('Owner-Chosen-Secret-1', $sealed, 'sealed for root: the web app cannot read it back');
        self::assertStringNotContainsString('abc-client-1', $sealed);
        $copy = $file . '.copy';
        copy($file, $copy);
        $after = $job->follow($ok);
        self::assertStringContainsString(Words::DELIVERY['p2g_key_waiting'], $after->text());
        self::assertStringNotContainsString('Owner-Chosen-Secret-1', $after->body);
        self::assertStringNotContainsString('Owner-Chosen-Secret-1', (string) json_encode(self::$db->all('SELECT detail FROM audit_log')), 'never in the audit');
        // the job's take (root): the key file root's alone, the drop gone
        self::assertTrue(P2gKeyDrop::take(self::$db, $this->drop, $this->keys, $this->secret, 'p2g_vapeandgo', self::tokenOk()));
        self::assertFalse(is_file($file));
        $path = P2gKey::path($this->keys, 'p2g_vapeandgo');
        self::assertSame(0600, fileperms($path) & 07777);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::assertSame('abc-client-1', P2gKey::load($path, 'p2g_vapeandgo')->clientId);
        }
        self::assertStringContainsString('A key was set', $job->get('/ui/profit/delivery/parcel2go/key')->text(), 'the page says when, never what');
        self::assertFalse(P2gKeyDrop::take(self::$db, $this->drop, $this->keys, $this->secret, 'p2g_vapeandgo', self::tokenOk()), 'nothing to take');
        // the same sealed paste again (a replay): its nonce was used: refused, the key as it is
        rename($copy, $file);
        chmod($file, 0600);
        self::assertSame('p2g_key_refused', self::takeCode($this, 'p2g_vapeandgo', self::tokenOk()));
        self::assertSame('abc-client-1', json_decode((string) file_get_contents($path), true)['client_id']);
    }

    public function testANewKeyReplacesTheOldOnlyAfterItsTokenWorksAndATamperedPasteIsRefused(): void
    {
        $job = $this->signIn($this->uiUser('delivery_bills'));
        $form = $job->get('/ui/profit/delivery/parcel2go/key')->form('/ui/profit/delivery/parcel2go/key');
        self::assertSame(303, $job->post('/ui/profit/delivery/parcel2go/key', ['csrf' => $form['csrf'], 'account' => 'p2g_vapeandgo', 'client_id' => 'new-client-2',
            'client_secret' => 'Owner-Chosen-Secret-2'])->status);
        self::assertSame('p2g_key_refused', self::takeCode($this, 'p2g_vapeandgo', static function (): void {
            throw new \CW\CwException('p2g_refused', 'Parcel2Go refused POST /auth/connect/token (HTTP 400)', 502);
        }), 'its token call failed: never set');
        self::assertFalse(is_file(P2gKey::path($this->keys, 'p2g_vapeandgo')), 'no key written');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'p2g.key_set'"));
        self::assertStringContainsString(Words::DELIVERY['p2g_key_not_taken'], $job->get('/ui/profit/delivery/parcel2go/key')->text(), 'G6: the refused paste is said');
        $form = $job->get('/ui/profit/delivery/parcel2go/key')->form('/ui/profit/delivery/parcel2go/key');
        self::assertSame(303, $job->post('/ui/profit/delivery/parcel2go/key', ['csrf' => $form['csrf'], 'account' => 'p2g_vapeandgo', 'client_id' => 'new-client-3',
            'client_secret' => 'Owner-Chosen-Secret-3'])->status);
        $file = P2gKeyDrop::file($this->drop, 'p2g_vapeandgo');
        // G6: Parcel2Go out of reach while the key is proved: the paste waits for the next run (never burnt)
        self::assertSame('p2g_key_later', self::takeCode($this, 'p2g_vapeandgo', static function (): void {
            throw new \CW\CwException('p2g_unreachable', 'Parcel2Go could not be read', 503);
        }));
        self::assertTrue(is_file($file), 'the paste is kept for the next run');
        $refused = (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'p2g.key_refused'");
        $b = (string) file_get_contents($file);
        file_put_contents($file, substr($b, 0, -4) . strrev(substr($b, -4)));
        self::assertSame('p2g_key_refused', self::takeCode($this, 'p2g_vapeandgo', self::tokenOk()), 'a paste changed after its audit: refused');
        self::assertSame($refused + 1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'p2g.key_refused'"), 'the out-of-reach try refused nothing');
        self::assertFalse(is_file(P2gKey::path($this->keys, 'p2g_vapeandgo')));
    }

    public function testADropNoPasteNamesIsRefusedAndRemoved(): void
    {
        $file = P2gKeyDrop::file($this->drop, 'p2g_vapebig');
        file_put_contents($file, base64_encode(sodium_crypto_box_seal(json_encode(['key' => json_decode(P2gFixtures::key('p2g_vapebig')->json(), true), 'nonce' => str_repeat('a', 32)]),
            base64_decode((string) file_get_contents($this->public)))));
        chmod($file, 0600);
        try {
            P2gKeyDrop::take(self::$db, $this->drop, $this->keys, $this->secret, 'p2g_vapebig', self::tokenOk());
            self::fail('a drop no paste names is refused');
        } catch (\CW\CwException $e) {
            self::assertSame('p2g_key_refused', $e->errorCode);
            self::assertStringNotContainsString('Made-Up-Secret', $e->getMessage());
        }
        self::assertFalse(is_file($file), 'it is removed');
        self::assertFalse(is_file(P2gKey::path($this->keys, 'p2g_vapebig')), 'no key was written');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'p2g.key_refused'"));
    }

    private static function tokenOk(): \Closure
    {
        return static function (P2gKey $k): void {
        };
    }

    private static function takeCode(self $t, string $account, \Closure $prove): string
    {
        try {
            return P2gKeyDrop::take(self::$db, $t->drop, $t->keys, $t->secret, $account, $prove) ? 'taken' : 'none';
        } catch (\CW\CwException $e) {
            return $e->errorCode;
        }
    }
}
