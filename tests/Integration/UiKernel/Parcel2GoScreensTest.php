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

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/cw_p2g_ui_' . bin2hex(random_bytes(5));
        $this->drop = $base . '/drop';
        $this->keys = $base . '/keys';
        mkdir($this->drop, 0700, true);
        mkdir($this->keys, 0700, true);
        putenv('CW_P2G_KEY_DROP_DIR=' . $this->drop);
        $vpg = (int) $this->site('vapeandgo', 'live')->channelId;
        $vb = (int) $this->site('vapebig', 'live')->channelId;
        $ef = (int) $this->site('electrofag', 'live')->channelId;
        P2gFixtures::accounts(self::$db, $vpg, $vb, $ef);
    }

    protected function tearDown(): void
    {
        putenv('CW_P2G_KEY_DROP_DIR');
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
        $after = $job->follow($ok);
        self::assertStringContainsString(Words::DELIVERY['p2g_key_waiting'], $after->text());
        self::assertStringNotContainsString('Owner-Chosen-Secret-1', $after->body);
        self::assertStringNotContainsString('Owner-Chosen-Secret-1', (string) json_encode(self::$db->all('SELECT detail FROM audit_log')), 'never in the audit');
        // the job's take (root): the key file root's alone, the drop gone
        self::assertTrue(P2gKeyDrop::take(self::$db, $this->drop, $this->keys, 'p2g_vapeandgo'));
        self::assertFalse(is_file($file));
        $path = P2gKey::path($this->keys, 'p2g_vapeandgo');
        self::assertSame(0600, fileperms($path) & 07777);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::assertSame('abc-client-1', P2gKey::load($path, 'p2g_vapeandgo')->clientId);
        }
        self::assertStringContainsString('A key was set', $job->get('/ui/profit/delivery/parcel2go/key')->text(), 'the page says when, never what');
        self::assertFalse(P2gKeyDrop::take(self::$db, $this->drop, $this->keys, 'p2g_vapeandgo'), 'nothing to take');
    }

    public function testADropNoPasteNamesIsRefusedAndRemoved(): void
    {
        $file = P2gKeyDrop::file($this->drop, 'p2g_vapebig');
        file_put_contents($file, json_encode(['key' => json_decode(P2gFixtures::key('p2g_vapebig')->json(), true), 'nonce' => str_repeat('a', 32)]));
        chmod($file, 0600);
        try {
            P2gKeyDrop::take(self::$db, $this->drop, $this->keys, 'p2g_vapebig');
            self::fail('a drop no paste names is refused');
        } catch (\CW\CwException $e) {
            self::assertSame('p2g_key_refused', $e->errorCode);
            self::assertStringNotContainsString('Made-Up-Secret', $e->getMessage());
        }
        self::assertFalse(is_file($file), 'it is removed');
        self::assertFalse(is_file(P2gKey::path($this->keys, 'p2g_vapebig')), 'no key was written');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'p2g.key_refused'"));
    }
}
