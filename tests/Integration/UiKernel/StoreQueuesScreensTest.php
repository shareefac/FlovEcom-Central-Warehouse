<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Movements;
use CW\Relay\RelayIntake;
use CW\Reservations;
use CW\Stock;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Controller\StoreQueuesController;
use CW\Ui\Words;

/**
 * Approvals › From the stores (pack F; docs/decisions.md PF5-PF9) through the real /ui kernel as cw_app: the tab and its four segments,
 * who may look and who may answer each queue (the badge counts only what a person may answer), every answer on the screens with its
 * notice, a second answer refused in words, and the CSRF token on every POST.
 */
final class StoreQueuesScreensTest extends KernelUiTestCase
{
    private const PATH = StoreQueuesController::PATH;
    private const POSTS = ['/suspense/%d/pick', '/suspense/%d/dismiss', '/corrections/%d/confirm', '/corrections/%d/dismiss', '/modes/%d/accept',
        '/modes/%d/dismiss', '/oversold/%d/handle', '/oversold/%d/note'];

    /** @return array{suspense: int, correction: int, mode: int, oversold: int, sku: int, code: string} one row in each queue */
    private function fill(): array
    {
        $site = self::makeChannel('vpg', 'live');
        self::$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$site, self::warehouseId('MAIN')]);
        self::$db->exec('UPDATE channel SET movement_types = ? WHERE id = ?', [json_encode(['goods_in', 'erp_reconciliation', 'erp_mode']), $site]);
        $caller = Caller::channel($site, 'vpg');
        $sku = self::makeSku('Blue Razz 20mg');
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        self::$db->exec("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status) VALUES (?, 'V1', ?, 1, 'mapped')", [$site, $sku]);
        $moves = new Movements(self::$db);
        $moves->record(Caller::system('test'), ['type' => 'goods_in', 'doc_ref' => 'OPEN-1', 'lines' => [['sku_id' => $sku, 'qty' => 1]]], 'open-1');
        (new Stock(self::$db))->setPolicy(Caller::system('test'), $sku, 'strict', 'pol-1');
        $moves->record($caller, ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-1', 'lines' => [['variant_id' => 'NEW', 'qty' => 2, 'description' => 'Mango 10ml']]], 'relay-1');
        $relay = new RelayIntake(self::$db);
        $relay->reconciliations($caller, ['doc_ref' => 'MAT-RECO-1', 'lines' => [['variant_id' => 'V1', 'qty' => 4]]], 'reco-1');
        $relay->modes($caller, ['doc_ref' => 'vpg-mode-1', 'lines' => [['variant_id' => 'V1', 'mode' => 'In-Stock']]], 'mode-1');
        (new Reservations(self::$db))->commit($caller, '500', [['variant_id' => 'V1', 'qty' => 3, 'unit_ids' => ['a', 'b', 'c']]], 'unreserved', 'commit-1');
        return ['suspense' => (int) self::$db->value('SELECT id FROM goods_in_suspense'), 'correction' => (int) self::$db->value('SELECT id FROM count_review'),
            'mode' => (int) self::$db->value('SELECT id FROM policy_review'), 'oversold' => (int) self::$db->value('SELECT id FROM oversell_event'), 'sku' => $sku, 'code' => $code];
    }

    public function testTheTabIsForThePeopleWhoReadRecordsAndTheCountsForThoseWhoAnswer(): void
    {
        $ids = $this->fill();
        self::assertSame(403, $this->signIn($this->uiUser('viewer'))->get(self::PATH . '/suspense')->status);
        self::assertSame(403, $this->signIn($this->uiUser('admin'))->get(self::PATH . '/oversold')->status);

        $buyer = $this->signIn($this->uiUser('buyer'));
        $page = $buyer->get(self::PATH . '/suspense');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(['History', 'Lines to place', 'ERPNext corrections', 'Mode changes', 'Oversold', 'Website bugs'], array_column(self::segments($page), 'label'),
            'Approvals\' one Show (RD7): a buyer reads the history and the queues');
        self::assertStringContainsString('You can look', $page->text());
        self::assertFalse($page->hasForm(self::PATH . '/suspense/' . $ids['suspense'] . '/pick'));
        self::assertSame(0, array_sum(array_column(self::segments($page), 'count')), 'a buyer answers none: no counts');

        $controller = $this->signIn($this->uiUser('stock_controller'));
        foreach (['suspense', 'corrections', 'modes', 'oversold'] as $q) {
            $r = $controller->get(self::PATH . '/' . $q);
            self::assertSame(200, $r->status, $q . ' ' . $r->describe() . ' ' . implode(' | ', self::$log));
            self::assertStringNotContainsString('You can look', $r->text(), $q);
        }
        self::assertSame([1, 1, 1, 1, 0], array_slice(array_column(self::segments($controller->get(self::PATH . '/modes')), 'count'), -5));
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        self::assertSame([1, 0, 1, 1, 0], array_slice(array_column(self::segments($desk->get(self::PATH . '/modes')), 'count'), -5), 'the desk does not answer corrections');
        self::assertStringContainsString('You can look', $desk->get(self::PATH . '/corrections')->text());
    }

    public function testEveryAnswerOnTheScreens(): void
    {
        $ids = $this->fill();
        $web = $this->signIn($this->uiUser('stock_controller'));

        // Lines to place: the item's code and a note; the line is booked and the notice names the item.
        $page = $web->get(self::PATH . '/suspense');
        self::assertStringContainsString(Words::LQ['goods_waiting'], $page->text(), 'the board of Lines to place (docs/decisions.md LQ2)');
        self::assertStringContainsString('Mango 10ml', $page->text());
        $form = $page->form(self::PATH . '/suspense/' . $ids['suspense'] . '/pick');
        $r = $web->post(self::PATH . '/suspense/' . $ids['suspense'] . '/pick', ['item' => $ids['code'], 'units' => '1', 'note' => 'the new mango'] + $form);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $after = $web->follow($r);
        self::assertStringContainsString(Words::say('QUEUE_NOTICE', 'booked', $ids['code']), $after->text());
        self::assertSame(3, (int) self::$db->value("SELECT on_hand FROM stock_balance WHERE sku_id = ? AND warehouse_id = ?", [$ids['sku'], self::warehouseId('MAIN')]));
        // A second answer is refused in words; nothing more is booked.
        $again = $web->post(self::PATH . '/suspense/' . $ids['suspense'] . '/dismiss', ['csrf' => $form['csrf'], 'note' => 'too late now']);
        self::assertSame(409, $again->status);
        self::assertStringContainsString(Words::QUEUE_ERROR['queue_settled'], $again->text());

        // ERPNext corrections: the effect is on the page; confirm books it.
        $page = $web->get(self::PATH . '/corrections');
        self::assertStringContainsString('ERPNext set 4', $page->text());
        self::assertStringContainsString(Words::CORRECTIONS['not_count'], $page->text());
        $form = $page->form(self::PATH . '/corrections/' . $ids['correction'] . '/confirm');
        $short = $web->post(self::PATH . '/corrections/' . $ids['correction'] . '/confirm', ['note' => 'x'] + $form);
        self::assertSame(400, $short->status);
        self::assertStringContainsString(Words::QUEUE_ERROR['note_required'], $short->text());
        $r = $web->post(self::PATH . '/corrections/' . $ids['correction'] . '/confirm', ['note' => 'agreed with the shelf'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('resolved', (string) self::$db->value('SELECT CAST(status AS CHAR) FROM count_review WHERE id = ?', [$ids['correction']]));

        // Mode changes: the item is protected (strict), so accepting changes its stock rule.
        $page = $web->get(self::PATH . '/modes');
        self::assertStringContainsString(Words::say('MODES', 'will_policy', Words::of('POLICY', 'backorder')), $page->text());
        $form = $page->form(self::PATH . '/modes/' . $ids['mode'] . '/accept');
        $r = $web->post(self::PATH . '/modes/' . $ids['mode'] . '/accept', ['note' => 'the owner wants back orders'] + $form);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame('backorder', (string) self::$db->value('SELECT sell_policy FROM sku WHERE id = ?', [$ids['sku']]));
        self::assertStringContainsString(Words::QUEUE_NOTICE['accepted_policy'], $web->follow($r)->text());

        // Oversold: a note, then handled; the note stays on the page.
        $page = $web->get(self::PATH . '/oversold');
        $note = $page->form(self::PATH . '/oversold/' . $ids['oversold'] . '/note');
        self::assertSame(303, $web->post(self::PATH . '/oversold/' . $ids['oversold'] . '/note', ['note' => 'customer phoned'] + $note)->status);
        $handle = $web->get(self::PATH . '/oversold')->form(self::PATH . '/oversold/' . $ids['oversold'] . '/handle');
        self::assertSame(303, $web->post(self::PATH . '/oversold/' . $ids['oversold'] . '/handle', ['resolution' => 'refunded', 'note' => 'refunded in full'] + $handle)->status);
        $done = $web->get(self::PATH . '/oversold', ['status' => 'resolved']);
        self::assertStringContainsString('customer phoned', $done->text());
        self::assertStringContainsString('refunded in full', $done->text());
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM goods_in_suspense WHERE status = 'open'")
            + (int) self::$db->value("SELECT COUNT(*) FROM oversell_event WHERE status = 'open'"));
        self::assertSame(4, (int) self::$db->value('SELECT COUNT(*) FROM goods_in_suspense') + (int) self::$db->value('SELECT COUNT(*) FROM count_review')
            + (int) self::$db->value('SELECT COUNT(*) FROM policy_review') + (int) self::$db->value('SELECT COUNT(*) FROM oversell_event'), 'nothing removed');
    }

    /** Review finding 6 (Codex, 14 Oct 2026): the settings pages and the setting's history never show the alerts' web address whole. */
    public function testTheSettingsPagesNeverShowTheWebAddressWhole(): void
    {
        $saved = self::$db->one("SELECT CAST(value_json AS CHAR) AS v, updated_actor, updated_at FROM app_setting WHERE setting_key = 'alerts.webhook_url'");
        try {
            (new \CW\Settings(self::$db))->set(Caller::system('screen_test'), 'alerts.webhook_url', 'https://hooks.example.com/services/SECRET456', 'on-call channel');
            $buyer = $this->signIn($this->uiUser('buyer'));
            foreach (['/ui/reference/settings' => [], '/ui/reference/settings/setting' => ['key' => 'alerts.webhook_url']] as $path => $query) {
                $page = $buyer->get($path, $query);
                self::assertSame(200, $page->status, $path . ' ' . $page->describe());
                self::assertStringNotContainsString('SECRET456', $page->body, $path);
            }
            $audit = $this->signIn($this->uiUser('auditor'))->get('/ui/system/audit');
            self::assertStringNotContainsString('SECRET456', $audit->body);
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON), updated_actor = ?, updated_at = ? WHERE setting_key = 'alerts.webhook_url'",
                [$saved['v'], $saved['updated_actor'], $saved['updated_at']]);
        }
    }

    public function testEveryPostNeedsTheJobAndTheCsrfToken(): void
    {
        $ids = $this->fill();
        $byQueue = ['suspense' => $ids['suspense'], 'corrections' => $ids['correction'], 'modes' => $ids['mode'], 'oversold' => $ids['oversold']];
        $buyer = $this->signIn($this->uiUser('buyer'));
        $controller = $this->signIn($this->uiUser('stock_controller'));
        $fields = ['item' => $ids['code'], 'units' => '1', 'note' => 'a fine note', 'resolution' => 'refunded'];
        foreach (self::POSTS as $pattern) {
            $queue = explode('/', $pattern)[1];
            $path = self::PATH . sprintf($pattern, $byQueue[$queue]);
            self::assertSame(403, $buyer->post($path, ['csrf' => $this->token($buyer)] + $fields)->status, "buyer {$path}");
            self::assertSame(403, $controller->post($path, $fields)->status, "no token {$path}");
            self::assertSame(403, $controller->post($path, ['csrf' => 'forged'] + $fields)->status, "a forged token {$path}");
        }
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM goods_in_suspense WHERE status = 'open'"), 'nothing was answered');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM oversell_event WHERE status = 'open'"));
    }
}
