<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Invariants;
use CW\Movements;
use CW\Queues\Corrections;
use CW\Queues\NightlyConfirm;
use CW\Relay\RelayIntake;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The nightly Confirm all on the screens (NightlyConfirm.dc.html; docs/decisions.md NC8-NC10): Settings > Rules and numbers shows the
 * "Last night" line at the top (the switch off: it says so; on: the next run, and the last night's run linked to its done page for who
 * may open it) and the card "Messages from ERPNext" with the four rows in the board's order; a nightly run's done page opens for everyone
 * who answers ERPNext corrections, while a person's press stays its presser's.
 */
final class NightlyConfirmScreensTest extends KernelUiTestCase
{
    private const SETTINGS = '/ui/reference/settings';

    /** @var list<array<string, mixed>> */
    private array $was = [];
    private int $site = 0;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->was = self::$db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v, updated_actor, updated_at FROM app_setting WHERE setting_key IN (?, ?, ?)',
            [NightlyConfirm::SWITCH, NightlyConfirm::AT, NightlyConfirm::MAX]);
    }

    protected function tearDown(): void
    {
        foreach ($this->was as $r) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), updated_actor = ?, updated_at = ? WHERE setting_key = ?',
                [$r['v'], $r['updated_actor'], $r['updated_at'], $r['setting_key']]);
        }
        parent::tearDown();
    }

    /** One correction of a new item: `has` on hand, ERPNext sets `set` (relayed now). */
    private function correction(int $has, int $set): int
    {
        if ($this->site === 0) {
            $this->site = self::makeChannel('vpg', 'live');
            self::$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$this->site, self::warehouseId('MAIN')]);
            self::$db->exec('UPDATE channel SET movement_types = ? WHERE id = ?', [json_encode(['erp_reconciliation']), $this->site]);
        }
        $v = 'NS' . (++$this->seq);
        $sku = self::makeSku('Night pod ' . $this->seq);
        self::$db->exec("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status) VALUES (?, ?, ?, 1, 'mapped')", [$this->site, $v, $sku]);
        if ($has > 0) {
            (new Movements(self::$db))->record(Caller::system('test'), ['type' => 'goods_in', 'doc_ref' => 'OPEN-' . $v, 'lines' => [['sku_id' => $sku, 'qty' => $has]]], 'open-' . $v);
        }
        (new RelayIntake(self::$db))->reconciliations(Caller::channel($this->site, 'vpg'), ['doc_ref' => 'MAT-RECO-' . $v, 'lines' => [['variant_id' => $v, 'qty' => $set]]], 'reco-' . $v);
        return (int) self::$db->value('SELECT id FROM count_review WHERE sku_id = ?', [$sku]);
    }

    private function on(string $at = '04:30'): void
    {
        self::$db->exec("UPDATE app_setting SET value_json = CAST('true' AS JSON), updated_actor = 'system:settings' WHERE setting_key = ?", [NightlyConfirm::SWITCH]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [json_encode($at), NightlyConfirm::AT]);
    }

    /** Tonight's run, as the job would make it at 04:31 UK today. @return int its run id */
    private function tonight(): int
    {
        $uk = new \DateTimeZone('Europe/London');
        $at = new \DateTimeImmutable((new \DateTimeImmutable('now', $uk))->format('Y-m-d') . ' 04:31:00', $uk);
        $r = (new NightlyConfirm(self::$db, static fn (): \DateTimeImmutable => $at))->tick();
        self::assertSame('ran', $r['result']);
        return (int) $r['run'];
    }

    private static function xp(UiResponse $r): \DOMXPath
    {
        return new \DOMXPath($r->dom());
    }

    private static function lastNight(UiResponse $r): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) self::xp($r)->evaluate('string(//section[@aria-label="' . Words::NIGHTLY['label'] . '"])')));
    }

    public function testRulesAndNumbersShowsLastNightAndTheFourRows(): void
    {
        $sc = $this->signIn($this->uiUser('stock_controller'));
        $page = $sc->get(self::SETTINGS);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(Words::NIGHTLY['off'], self::lastNight($page), 'the switch off: the line says so');
        self::assertSame(0, self::xp($page)->query('//section[@aria-label="' . Words::NIGHTLY['label'] . '"]//a')->length);
        // The card: the four rows first, in the board's order, with its words; the switch reads On / Off.
        $xp = self::xp($page);
        $card = '//section[contains(@class, "set-card")][h2="' . Words::SETTING_TOPIC['relay'] . '"]';
        $keys = array_map(static fn (\DOMElement $r): string => $r->getAttribute('data-key'), iterator_to_array($xp->query($card . '//*[@data-key]')));
        self::assertSame([NightlyConfirm::SWITCH, NightlyConfirm::AT, Corrections::BIG_SETTING, NightlyConfirm::MAX], array_slice($keys, 0, 4));
        self::assertSame('Off', trim((string) $xp->evaluate('string(' . $card . '//*[@data-key="relay.nightly_confirm"]//span[contains(@class, "set-val")])')),
            'who only looks: the value in words');
        // Who changes settings (settings.manage): the switch's box reads On / Off, the time and the most a night are boxes.
        $rev = self::xp($this->signIn($this->uiUser('reviewer'))->get(self::SETTINGS));
        self::assertSame(['Off', 'On'], [trim((string) $rev->evaluate('string(//select[@id="v-relay.nightly_confirm"]/option[@selected])')),
            trim((string) $rev->evaluate('string(//select[@id="v-relay.nightly_confirm"]/option[@value="true"])'))]);
        self::assertSame('04:30', (string) $rev->evaluate('string(//input[@id="v-relay.nightly_confirm_at"]/@value)'));
        self::assertSame('500', (string) $rev->evaluate('string(//input[@id="v-relay.nightly_confirm_max"]/@value)'));
        self::assertStringContainsString('Not agreed yet', (string) $xp->evaluate('string(' . $card . '//*[@data-key="relay.nightly_confirm"])'), 'seeded not agreed, as every new setting');
        // On, no run yet: the next run, nothing to open.
        $this->on('04:30');
        self::assertSame(Words::NIGHTLY['none'] . ' ' . sprintf(Words::NIGHTLY['next'], '04:30'), self::lastNight($sc->get(self::SETTINGS)));
        // Last night's run: its time (UK), what it confirmed and left, linked to its done page.
        $this->correction(10, 13);
        $this->correction(10, 7);
        $this->correction(0, 400);
        $run = $this->tonight();
        $when = (new \DateTimeImmutable((string) self::$db->value('SELECT created_at FROM audit_log WHERE id = ?', [$run]), new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/London'))->format('H:i');
        $page = $sc->get(self::SETTINGS);
        self::assertSame("Last night, {$when}: 2 ERPNext corrections confirmed, 1 left for you in Approvals. Open last night's run › "
            . sprintf(Words::NIGHTLY['next'], '04:30'), self::lastNight($page));
        self::assertSame(['/ui/queues/corrections/confirm-all/' . $run], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array(self::xp($page)->query('//section[@aria-label="' . Words::NIGHTLY['label'] . '"]//a'))));
        // Someone who may look at the settings but not answer corrections: the line, without the link.
        $buyer = $this->signIn($this->uiUser('buyer'));
        $bp = $buyer->get(self::SETTINGS);
        self::assertSame(200, $bp->status);
        self::assertStringContainsString("Last night, {$when}: 2 ERPNext corrections confirmed", self::lastNight($bp));
        self::assertSame(0, self::xp($bp)->query('//section[@aria-label="' . Words::NIGHTLY['label'] . '"]//a')->length);
        self::assertSame([], Invariants::check(self::$db));
    }

    public function testANightlyRunsDonePageIsForEveryoneWhoAnswersCorrections(): void
    {
        $this->on('04:30');
        $this->correction(1, 3);
        $this->correction(0, 300);
        $run = $this->tonight();
        foreach (['stock_controller', 'stock_controller'] as $role) {
            $web = $this->signIn($this->uiUser($role));
            $done = $web->get('/ui/queues/corrections/confirm-all/' . $run);
            self::assertSame(200, $done->status, $done->describe());
            self::assertSame(['1 confirmed. 1 left for you.'], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
                iterator_to_array(self::xp($done)->query('//p[contains(@class, "ca-status")]'))));
            self::assertStringContainsString('Saved as one run in the audit log: Confirm all ERPNext corrections, ', $done->text());
            self::assertStringContainsString(', by ' . Words::CONFIRM_ALL['by_night'] . '.', $done->text());
        }
        // Who does not answer corrections: the route refuses (as for any press).
        self::assertSame(403, $this->signIn($this->uiUser('buyer'))->get('/ui/queues/corrections/confirm-all/' . $run)->status);
        // A person's press stays its presser's.
        $presser = $this->uiUser('stock_controller');
        $id = $this->correction(1, 2);
        $own = (new Corrections(self::$db))->confirmAll(Caller::staff($presser['id']), [$id], 'my own press');
        self::assertSame(404, $this->signIn($this->uiUser('stock_controller'))->get('/ui/queues/corrections/confirm-all/' . $own['run'])->status);
        self::assertSame(200, $this->signIn($presser)->get('/ui/queues/corrections/confirm-all/' . $own['run'])->status);
    }
}
