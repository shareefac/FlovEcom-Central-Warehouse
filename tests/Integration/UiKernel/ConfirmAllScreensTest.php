<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Auth\Csrf;
use CW\Caller;
use CW\Invariants;
use CW\Movements;
use CW\Queues\Corrections;
use CW\Relay\RelayIntake;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\InboxController;
use CW\Ui\Controller\StoreQueuesController;
use CW\Ui\Words;

/**
 * "Confirm all ready" for ERPNext corrections through the real /ui kernel as cw_app (the owner, 31 Oct 2026; docs/decisions.md CA1-CA12; the
 * approved boards ConfirmAll.dc.html, ConfirmAllCheck.dc.html, ConfirmAllDone.dc.html): Approvals with Show = ERPNext corrections splits the
 * list into Ready to confirm and Needs a look (each row's line), the green "Confirm all ready (N)" is in the header only for who answers
 * them and only when N > 0, the board's "The list is split in two" stands until a row is opened, and a row opens as before; the check page
 * saves nothing; the press needs the token and the job, books the ready ones the page showed and nothing else, comes back on a refused
 * note, and a second press books nothing twice; the done page lists what is left and is only its presser's. Plain forms and links.
 */
final class ConfirmAllScreensTest extends KernelUiTestCase
{
    private const CHECK = '/ui/queues/corrections/confirm-all';

    private int $site = 0;
    private int $seq = 0;

    /**
     * Items of the store, each with `has` units on hand and an ERPNext correction setting it to `set` (relayed now), sold as `variants`
     * website products: the correction ids and item ids by name.
     *
     * @param array<string, array{has: int, set: int, variants?: int}> $items
     * @return array<string, array{id: int, sku: int, variant: string}>
     */
    private function corrections(array $items): array
    {
        if ($this->site === 0) {
            $this->site = self::makeChannel('vpg', 'live');
            self::$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$this->site, self::warehouseId('MAIN')]);
            self::$db->exec('UPDATE channel SET movement_types = ? WHERE id = ?', [json_encode(['erp_reconciliation']), $this->site]);
        }
        $out = [];
        foreach ($items as $name => $it) {
            $sku = self::makeSku($name);
            $variant = 'V' . (++$this->seq);
            for ($v = 0; $v < ($it['variants'] ?? 1); $v++) {
                self::$db->exec("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status) VALUES (?, ?, ?, 1, 'mapped')",
                    [$this->site, $variant . ($v === 0 ? '' : '-' . $v), $sku]);
            }
            if ($it['has'] > 0) {
                (new Movements(self::$db))->record(Caller::system('test'), ['type' => 'goods_in', 'doc_ref' => 'OPEN-' . $variant, 'lines' => [['sku_id' => $sku, 'qty' => $it['has']]]],
                    'open-' . $variant);
            }
            $out[$name] = ['sku' => $sku, 'variant' => $variant];
        }
        // The corrections after every item's stock, in the order given (the oldest first).
        foreach ($items as $name => $it) {
            $variant = $out[$name]['variant'];
            (new RelayIntake(self::$db))->reconciliations(Caller::channel($this->site, 'vpg'), ['doc_ref' => 'MAT-RECO-' . $variant,
                'lines' => [['variant_id' => $variant, 'qty' => $it['set']]]], 'reco-' . $variant);
            $out[$name]['id'] = (int) self::$db->value('SELECT id FROM count_review WHERE sku_id = ?', [$out[$name]['sku']]);
        }
        return $out;
    }

    private function onHand(int $sku): int
    {
        return (int) (self::$db->value('SELECT on_hand FROM stock_balance WHERE warehouse_id = ? AND sku_id = ?', [self::warehouseId('MAIN'), $sku]) ?? 0);
    }

    /** What a page may never change: the ledger, the queue, the stored answers, the audit log. @return list<mixed> */
    private static function saved(): array
    {
        return [(int) self::$db->value('SELECT COUNT(*) FROM stock_ledger'), self::$db->all('SELECT id, CAST(status AS CHAR) AS s, resolution FROM count_review ORDER BY id'),
            (int) self::$db->value('SELECT COUNT(*) FROM idempotency'), (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'count_review.%'")];
    }

    private static function xp(UiResponse $r): \DOMXPath
    {
        return new \DOMXPath($r->dom());
    }

    /** @return list<string> */
    private static function texts(\DOMXPath $xp, string $q): array
    {
        $out = [];
        foreach ($xp->query($q) ?: [] as $n) {
            $out[] = trim((string) preg_replace('/\s+/u', ' ', (string) $n->textContent));
        }
        return $out;
    }

    /** The groups of the inbox: name => count. @return array<string, int> */
    private static function groupsShown(UiResponse $page): array
    {
        $xp = self::xp($page);
        $out = [];
        foreach ($xp->query('//div[contains(concat(" ", @class, " "), " ap-group ")]/h2') ?: [] as $h) {
            $out[trim((string) $xp->evaluate('string(span[@class="ap-gname"])', $h))] = (int) trim((string) $xp->evaluate('string(span[@class="ap-gcount"])', $h));
        }
        return $out;
    }

    /** The rows of the inbox: title => [its line, whether the line is a Needs a look reason]. @return array<string, array{0: string, 1: bool}> */
    private static function rowsShown(UiResponse $page): array
    {
        $xp = self::xp($page);
        $out = [];
        foreach ($xp->query('//a[contains(@class, "ap-row")]') ?: [] as $a) {
            $m = $xp->query('span[contains(@class, "ap-m")]', $a)->item(0);
            $out[trim((string) $xp->evaluate('string(span[@class="ap-t"])', $a))] = [trim((string) $m?->textContent), str_contains((string) $m?->getAttribute('class'), 'ap-why')];
        }
        return $out;
    }

    private function sc(): KernelBrowser
    {
        return $this->signIn($this->uiUser('stock_controller'));
    }

    public function testApprovalsSplitsTheCorrectionsAndOffersConfirmAllReady(): void
    {
        $c = $this->corrections(['Alpha pod' => ['has' => 10, 'set' => 13], 'Beta pod' => ['has' => 10, 'set' => 7], 'Gamma pod' => ['has' => 1, 'set' => 150],
            'Delta pod' => ['has' => 5, 'set' => 6, 'variants' => 2]]);
        $web = $this->sc();
        $page = $web->get(InboxController::PATH, ['show' => 'corrections']);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $xp = self::xp($page);
        self::assertSame([Words::CONFIRM_ALL['ready'] => 2, Words::CONFIRM_ALL['look'] => 2], self::groupsShown($page), 'ready first (green), then needs a look (amber)');
        self::assertSame(['done', 'needs'], array_map(static fn (string $c): string => trim(str_replace('ap-group', '', $c)),
            self::texts($xp, '//div[contains(concat(" ", @class, " "), " ap-group ")]/@class')));
        self::assertSame(['Alpha pod' => ['ERPNext set 13 · adds 3', false], 'Beta pod' => ['ERPNext set 7 · takes off 3', false],
            'Gamma pod' => ['Big change: +149 (over 100 units)', true], 'Delta pod' => ['Sold as 2 variants: ERPNext set only one', true]], self::rowsShown($page));
        // The green button in the header, after the Show: a plain link to the check page; not a main button.
        $go = $xp->query('//header//a[contains(@class, "ap-confirm-all")]');
        self::assertSame(1, $go->length);
        /** @var \DOMElement $link */
        $link = $go->item(0);
        self::assertSame([self::CHECK, 'Confirm all ready (2)', true], [$link->getAttribute('href'), trim($link->textContent), str_contains($link->getAttribute('class'), 'btn ok')]);
        self::assertSame(1, $xp->query('//header//form[@class="ap-show"]/following-sibling::a[contains(@class, "ap-confirm-all")]')->length);
        // No row open by itself: the board's "The list is split in two" stands at the right, with the rules in their order.
        self::assertSame(0, $xp->query('//form[@id="ap-answer"]')->length);
        self::assertSame(0, $xp->query('//a[contains(@class, "ap-row") and @aria-current]')->length);
        self::assertSame([Words::CONFIRM_ALL['split']], self::texts($xp, '//article[@aria-label="' . Words::CONFIRM_ALL['how'] . '"]/h2'));
        self::assertSame(\CW\Ui\Controller\StoreQueuesController::confirmAllRules(100), self::texts($xp, '//article[contains(@class, "ap-split")]/ul/li'));
        self::assertSame(0, $xp->query('//main//*[contains(concat(" ", @class, " "), " primary ")]')->length, 'no magenta button until a record is open');
        // A row opens its record as before (the one magenta button is the record's Confirm), the list and the button stay.
        $open = $web->get(InboxController::PATH, ['show' => 'corrections', 'open' => 'corrections-' . $c['Gamma pod']['id']]);
        $ox = self::xp($open);
        self::assertSame('Gamma pod', trim((string) $ox->evaluate('string(//h2[@id="ap-h"])')));
        self::assertSame('/ui/queues/corrections/' . $c['Gamma pod']['id'] . '/confirm', (string) $ox->evaluate('string(//form[@id="ap-answer"]/@action)'));
        self::assertSame(1, $ox->query('//main//*[contains(concat(" ", @class, " "), " primary ") and not(@aria-hidden)]')->length);
        self::assertSame(1, $ox->query('//header//a[contains(@class, "ap-confirm-all")]')->length);
        self::assertSame(0, $ox->query('//article[contains(@class, "ap-split")]')->length);
        // One by one, a person still confirms one that needs a look: the record's answer, as today, back to the list kept on the kind.
        $r = $web->post('/ui/queues/corrections/' . $c['Gamma pod']['id'] . '/confirm', ['note' => 'counted it: 150 is right'] + $open->form('/ui/queues/corrections/' . $c['Gamma pod']['id'] . '/confirm'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString('show=corrections', (string) $r->location());
        self::assertSame(150, $this->onHand($c['Gamma pod']['sku']));
        $after = $web->follow($r);
        self::assertSame([Words::CONFIRM_ALL['ready'] => 2, Words::CONFIRM_ALL['look'] => 1], self::groupsShown($after));
        // Everything waiting: one ERPNext corrections group as before, no button, the first record open.
        $all = $web->get(InboxController::PATH);
        self::assertSame(3, self::groupsShown($all)[Words::INBOX['kind_corrections']] ?? null);
        self::assertSame(0, self::xp($all)->query('//a[contains(@class, "ap-confirm-all")]')->length);
        self::assertSame(1, self::xp($all)->query('//form[@id="ap-answer"]')->length);
    }

    public function testTheButtonIsForWhoAnswersAndOnlyWhenOneIsReady(): void
    {
        $this->corrections(['Huge pod' => ['has' => 0, 'set' => 400]]);
        $web = $this->sc();
        $page = $web->get(InboxController::PATH, ['show' => 'corrections']);
        self::assertSame([Words::CONFIRM_ALL['look'] => 1], self::groupsShown($page));
        self::assertSame(0, self::xp($page)->query('//a[contains(@class, "ap-confirm-all")]')->length, 'none ready: no button');
        // The check page with nothing ready: a plain line, Back, no form, no button.
        $check = $web->get(self::CHECK);
        self::assertSame(200, $check->status, $check->describe());
        self::assertStringContainsString(Words::CONFIRM_ALL['none_ready'], $check->text());
        self::assertFalse($check->hasForm(self::CHECK));
        self::assertSame(0, self::xp($check)->query('//main//*[contains(concat(" ", @class, " "), " primary ")]')->length);
        self::assertSame(['/ui/approvals?show=corrections'], self::texts(self::xp($check), '//main//a[contains(@class, "btn")]/@href'));
        // Who does not answer ERPNext corrections: no kind, no button, and the routes refuse them (403), GET and POST alike.
        $this->corrections(['Small pod' => ['has' => 1, 'set' => 2]]);
        foreach (['purchasing_desk', 'buyer'] as $role) {
            $other = $this->signIn($this->uiUser($role));
            self::assertSame(0, self::xp($other->get(InboxController::PATH, ['show' => 'corrections']))->query('//a[contains(@class, "ap-confirm-all")]')->length, $role);
            self::assertSame(403, $other->get(self::CHECK)->status, $role);
            self::assertSame(403, $other->post(self::CHECK, ['csrf' => $this->token($other), 'ids' => '1', 'note' => 'not my job at all'])->status, $role);
        }
        // The setting is on the Settings page, with the relay settings.
        $settings = $web->get('/ui/reference/settings');
        self::assertSame(200, $settings->status, $settings->describe());
        self::assertStringContainsString(Words::settingName(Corrections::BIG_SETTING), $settings->text());
    }

    public function testTheCheckPageSavesNothingAndThePressBooksTheReadyOnes(): void
    {
        $c = $this->corrections(['Alpha pod' => ['has' => 10, 'set' => 13], 'Beta pod' => ['has' => 10, 'set' => 7], 'Gamma pod' => ['has' => 1, 'set' => 150],
            'Delta pod' => ['has' => 5, 'set' => 6, 'variants' => 2]]);
        $user = $this->uiUser('stock_controller');
        $web = $this->signIn($user);
        $before = self::saved();
        $check = $web->get(self::CHECK);
        self::assertSame(200, $check->status, $check->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame($before, self::saved(), 'the check page saves nothing');
        self::assertSame($before, self::saved());
        $xp = self::xp($check);
        self::assertSame(['Confirm 2 ERPNext corrections?'], self::texts($xp, '//header[@class="page-head"]//h1'));
        self::assertSame([Words::CONFIRM_ALL['eyebrow']], self::texts($xp, '//header[@class="page-head"]//p[@class="ph-eyebrow"]'));
        self::assertSame(['From VPG test site · Main warehouse · nothing is saved until you press Confirm'], self::texts($xp, '//header[@class="page-head"]//p[@class="ph-meta"]'));
        self::assertSame(['Confirmed, the stock set to ERPNext\'s figure', 'Units added / taken off in Main warehouse', 'Left for you under Needs a look'],
            self::texts($xp, '//section[contains(@class, "ca-lines")]//span[@class="ca-text"]'));
        self::assertSame(['2 items', '+3 / −3', '2 items'], self::texts($xp, '//section[contains(@class, "ca-lines")]//span[@class="ca-fig"]'));
        self::assertSame(['ca-dot done', 'ca-dot info', 'ca-dot needs'], self::texts($xp, '//section[contains(@class, "ca-lines")]//span[starts-with(@class, "ca-dot")]/@class'));
        self::assertSame([Words::CONFIRM_ALL['again'], Words::CONFIRM_ALL['own_line'], Words::CONFIRM_ALL['no_shop']], self::texts($xp, '//div[@class="ca-says"]/p'));
        self::assertSame([Words::CONFIRM_ALL['note']], self::texts($xp, '//form[@action="' . self::CHECK . '"]//label[@for="ca-note"]'));
        self::assertSame(['Confirm 2'], self::texts($xp, '//form[@action="' . self::CHECK . '"]//button[@type="submit" and contains(@class, "primary")]'));
        self::assertSame(['/ui/approvals?show=corrections'], self::texts($xp, '//form[@action="' . self::CHECK . '"]//a[contains(@class, "btn")]/@href'), 'Back');
        $form = $check->form(self::CHECK);
        self::assertSame(['csrf', 'ids', 'at', 'sig', 'note'], array_keys($form), 'the ids, signed with the page\'s time (CA12)');
        self::assertSame($c['Alpha pod']['id'] . ',' . $c['Beta pod']['id'], $form['ids'], 'the ready ones shown, oldest first');

        $r = $web->post(self::CHECK, ['note' => 'Confirm all after the morning check'] + $form);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $run = (int) self::$db->value('SELECT MAX(id) FROM audit_log WHERE action = ?', [Corrections::RUN_ACTION]);
        self::assertSame(self::CHECK . '/' . $run, $r->location());
        self::assertSame([13, 7, 1, 5], array_map(fn (string $n): int => $this->onHand($c[$n]['sku']), ['Alpha pod', 'Beta pod', 'Gamma pod', 'Delta pod']));
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'count_review.confirm'"), 'each confirmation its own audit row');
        self::assertSame(['Confirm all after the morning check', 'Confirm all after the morning check'],
            array_map('strval', self::$db->column('SELECT note FROM count_review WHERE id IN (?, ?) ORDER BY id', [$c['Alpha pod']['id'], $c['Beta pod']['id']])), 'the note on each one');

        // The done page: the status line, what is left with why and Open ›, where the press is saved, the way back. No script needed.
        $done = $web->follow($r);
        self::assertSame(200, $done->status, $done->describe());
        $dx = self::xp($done);
        self::assertSame([Words::CONFIRM_ALL['done_title']], self::texts($dx, '//header[@class="page-head"]//h1'));
        self::assertSame(['2 confirmed. 2 left for you.'], self::texts($dx, '//p[@role="status" and contains(@class, "ca-status")]'));
        self::assertSame(['Left for you 2'], self::texts($dx, '//section[contains(@class, "ca-left")]/h2'));
        self::assertSame(['Gamma pod', 'Delta pod'], self::texts($dx, '//ul[@class="ca-rows"]/li//span[@class="ca-t"]'));
        self::assertSame(['Big change: +149 (over 100 units)', 'Sold as 2 variants: ERPNext set only one'], self::texts($dx, '//ul[@class="ca-rows"]/li//span[@class="ca-why"]'));
        self::assertSame(['/ui/approvals?show=corrections&open=corrections-' . $c['Gamma pod']['id'], '/ui/approvals?show=corrections&open=corrections-' . $c['Delta pod']['id']],
            self::texts($dx, '//ul[@class="ca-rows"]/li/a[@class="ca-open"]/@href'));
        $when = \CW\Ui\Html::when((string) self::$db->value('SELECT created_at FROM audit_log WHERE id = ?', [$run]));
        self::assertSame(['Saved as one run in the audit log: Confirm all ERPNext corrections, ' . $when . ', by ' . self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$user['id']]) . '.'],
            self::texts($dx, '//p[@class="ca-saved"]'));
        self::assertSame([Words::CONFIRM_ALL['back_list']], self::texts($dx, '//main//a[@href="/ui/approvals?show=corrections"]'));
        self::assertSame(0, $dx->query('//script[not(@src)]')->length);
        // Opened from the done page, a left one is the record in Approvals.
        $opened = $web->get(InboxController::PATH, ['show' => 'corrections', 'open' => 'corrections-' . $c['Delta pod']['id']]);
        self::assertSame('Delta pod', trim((string) self::xp($opened)->evaluate('string(//h2[@id="ap-h"])')));
        self::assertSame([Words::CONFIRM_ALL['look'] => 2], self::groupsShown($opened), 'only the two that need a look are waiting');
        self::assertSame([], Invariants::check(self::$db));
    }

    public function testThePressIsGuardedAndTouchesOnlyWhatItsPageShowed(): void
    {
        $c = $this->corrections(['Alpha pod' => ['has' => 2, 'set' => 4], 'Beta pod' => ['has' => 2, 'set' => 4]]);
        $web = $this->sc();
        $form = $web->get(self::CHECK)->form(self::CHECK);
        self::assertSame($c['Alpha pod']['id'] . ',' . $c['Beta pod']['id'], $form['ids']);
        $before = self::saved();
        // No token, or another site's form: refused, nothing saved.
        self::assertSame(403, $web->post(self::CHECK, ['note' => 'pressed without a token'] + array_diff_key($form, ['csrf' => 1]))->status);
        self::assertSame(403, $web->post(self::CHECK, ['note' => 'pressed from elsewhere'] + $form, ['Origin' => 'https://elsewhere.example'])->status);
        self::assertSame($before, self::saved());
        // A form that is not a check page's: refused whole.
        foreach (['', 'abc', '1,,2', '0', '-1', str_repeat('1,', 5000) . '1'] as $bad) {
            self::assertSame(400, $web->post(self::CHECK, ['ids' => $bad, 'note' => 'a hand-made form'] + $form)->status, "ids={$bad}");
        }
        // A note too short: the check page again, in the queue's words, the note kept; nothing saved.
        $short = $web->post(self::CHECK, ['note' => 'x'] + $form);
        self::assertSame(400, $short->status, $short->describe());
        self::assertStringContainsString(Words::QUEUE_ERROR['note_required'], $short->text());
        self::assertSame('x', $short->form(self::CHECK)['note'] ?? null);
        self::assertSame($before, self::saved());
        // Meanwhile: Beta gets a second website product; a new correction arrives (ready, but not on the page).
        self::$db->exec("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status) VALUES (?, 'BETA-2', ?, 1, 'mapped')",
            [$this->site, $c['Beta pod']['sku']]);
        $late = $this->corrections(['Epsilon pod' => ['has' => 1, 'set' => 3]]);
        $r = $web->post(self::CHECK, ['note' => 'pressed after a while'] + $form);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $done = $web->follow($r);
        $dx = self::xp($done);
        self::assertSame(['1 confirmed. 1 left for you.'], self::texts($dx, '//p[contains(@class, "ca-status")]'));
        self::assertSame(['Beta pod'], self::texts($dx, '//ul[@class="ca-rows"]/li//span[@class="ca-t"]'));
        self::assertSame([Words::CONFIRM_ALL['why_changed']], self::texts($dx, '//ul[@class="ca-rows"]/li//span[@class="ca-why"]'), 'it was ready on the page');
        self::assertSame([4, 2, 1], [$this->onHand($c['Alpha pod']['sku']), $this->onHand($c['Beta pod']['sku']), $this->onHand($late['Epsilon pod']['sku'])]);
        self::assertSame('open', (string) self::$db->value('SELECT CAST(status AS CHAR) FROM count_review WHERE id = ?', [$late['Epsilon pod']['id']]), 'not on the page: not touched');
        // The same form again (a double click, back and again): nothing booked twice.
        $ledger = (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger');
        $again = $web->post(self::CHECK, ['note' => 'pressed after a while'] + $form);
        self::assertSame(303, $again->status, $again->describe());
        self::assertSame($ledger, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger'));
        self::assertSame([4, 2], [$this->onHand($c['Alpha pod']['sku']), $this->onHand($c['Beta pod']['sku'])]);
        // CW-109: its done page says nothing new was booked, not "1 confirmed".
        self::assertSame(['Already confirmed before: 1. Nothing was booked again. 1 left for you.'],
            self::texts(self::xp($web->follow($again)), '//p[contains(@class, "ca-status")]'));
        // The done page is its presser's: another person, an unknown run, another audit row: 404 (the same answer for each).
        $run = (string) $r->location();
        $stranger = $this->sc();
        self::assertSame(404, $stranger->get($run)->status);
        self::assertSame(404, $web->get(self::CHECK . '/999999999')->status);
        $other = (int) self::$db->value("SELECT MAX(id) FROM audit_log WHERE action = 'count_review.confirm'");
        self::assertSame(404, $web->get(self::CHECK . '/' . $other)->status);
        self::assertSame(200, $web->get($run)->status);
        self::assertSame([], Invariants::check(self::$db));
    }

    public function testThePhoneLookIsInTheStylesheetOnly(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/ui/assets/app.css');
        $at = (int) strpos($css, '/* ==== ca: "Confirm all ready"');
        self::assertGreaterThan(0, $at);
        $block = substr($css, $at, (int) strpos($css, '/* ==== end ca', $at) - $at);
        $phone = substr($block, (int) strpos($block, '@media (max-width: 600px)'));
        foreach (['.ap-confirm-all { min-height: 44px', 'a.ca-open { min-height: 44px; }', '.ca-line, .ca-rows > li { padding-left: 16px; padding-right: 16px; }'] as $rule) {
            self::assertStringContainsString($rule, $phone);
        }
        self::assertStringContainsString('.ca-actions > .btn { min-height: 44px; }', $block, 'Back and Confirm are 44 px everywhere');
        self::assertStringContainsString('form.ca-form > input[type="text"] { width: 100%; height: 44px;', $block);
        foreach (['confirm_all', 'confirm_all_done', 'approvals_split'] as $t) {
            $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Ui/views/' . $t . '.php');
            self::assertDoesNotMatchRegularExpression('/\sstyle=|<script|<svg/i', $src, $t);
        }
    }

    /**
     * CA12 (Codex HIGH 1): the press books only the ids its check page drew, in its order, for the person it drew them for, within 30
     * minutes: an id added, taken away or moved, another person's page, a page older than that, or a touched signature or time refuses
     * the whole press (409) with the check page drawn again from now, and nothing is booked.
     */
    public function testThePressIsBoundToTheIdsItsCheckPageDrew(): void
    {
        $c = $this->corrections(['Alpha pod' => ['has' => 2, 'set' => 4], 'Beta pod' => ['has' => 2, 'set' => 5]]);
        $user = $this->uiUser('stock_controller');
        $web = $this->signIn($user);
        $form = $web->get(self::CHECK)->form(self::CHECK);
        $a = (string) $c['Alpha pod']['id'];
        $b = (string) $c['Beta pod']['id'];
        self::assertSame($a . ',' . $b, $form['ids']);
        self::assertMatchesRegularExpression('/^[0-9]{10}$/D', $form['at']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $form['sig']);
        $late = $this->corrections(['Gamma pod' => ['has' => 1, 'set' => 3]]);
        $g = (string) $late['Gamma pod']['id'];
        $before = self::saved();
        $refused = function (KernelBrowser $who, array $post, string $why) use ($before): UiResponse {
            $r = $who->post(self::CHECK, $post);
            self::assertSame(409, $r->status, $why . ': ' . $r->describe());
            self::assertStringContainsString(Words::QUEUE_ERROR['check_page_changed'], $r->text(), $why);
            self::assertSame($before, self::saved(), "{$why}: nothing booked");
            return $r;
        };
        $note = ['note' => 'pressed with a changed page'];
        $again = $refused($web, ['ids' => "{$a},{$b},{$g}"] + $note + $form, 'an id added');
        self::assertSame(['Confirm 3 ERPNext corrections?'], self::texts(self::xp($again), '//header[@class="page-head"]//h1'), 'the check page again, drawn now');
        self::assertSame("{$a},{$b},{$g}", $again->form(self::CHECK)['ids']);
        $refused($web, ['ids' => "{$b},{$a}"] + $note + $form, 'the order changed');
        $refused($web, ['ids' => $a] + $note + $form, 'an id taken away');
        $refused($web, ['sig' => str_repeat('0', 64)] + $note + $form, 'a touched signature');
        $refused($web, ['at' => (string) ((int) $form['at'] - 1)] + $note + $form, 'a touched time');
        $refused($web, ['at' => ''] + $note + $form, 'no time');
        $old = time() - StoreQueuesController::CONFIRM_ALL_PAGE_SECONDS - 1;
        $refused($web, ['at' => (string) $old, 'sig' => StoreQueuesController::confirmAllSignature(Csrf::fromSecretKey(self::$uiKey), $user['id'], $old, $form['ids'])]
            + $note + $form, 'a page more than 30 minutes old (signed then)');
        $stranger = $this->sc();
        $refused($stranger, ['csrf' => $this->token($stranger)] + $note + $form, 'another person\'s page');
        // The page as drawn: its two are booked; the one that arrived after it is not.
        $r = $web->post(self::CHECK, ['note' => 'the page as it was drawn'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame([4, 5, 1], [$this->onHand($c['Alpha pod']['sku']), $this->onHand($c['Beta pod']['sku']), $this->onHand($late['Gamma pod']['sku'])]);
        self::assertSame('open', (string) self::$db->value('SELECT CAST(status AS CHAR) FROM count_review WHERE id = ?', [(int) $g]));
    }

    /**
     * CA18 (Codex MEDIUM 8): a correction asked for by its link (the done page's Open ›, a bookmark) opens even when it is not among the
     * first 50 of its group, on ERPNext corrections shown and on everything waiting; the list shows it, marked open.
     */
    public function testACorrectionAskedForOpensBeyondTheFirstFifty(): void
    {
        $items = [];
        for ($i = 1; $i <= 55; $i++) {
            $items[sprintf('Big pod %02d', $i)] = ['has' => 0, 'set' => 150 + $i];
        }
        $c = $this->corrections($items);
        $last = $c['Big pod 55']['id'];
        $web = $this->sc();
        foreach ([['show' => 'corrections'], []] as $query) {
            $page = $web->get(InboxController::PATH, $query + ['open' => 'corrections-' . $last]);
            self::assertSame(200, $page->status, $page->describe());
            $xp = self::xp($page);
            self::assertSame('Big pod 55', trim((string) $xp->evaluate('string(//h2[@id="ap-h"])')), json_encode($query));
            self::assertSame('/ui/queues/corrections/' . $last . '/confirm', (string) $xp->evaluate('string(//form[@id="ap-answer"]/@action)'));
            self::assertSame(['Big pod 55'], self::texts($xp, '//a[contains(@class, "ap-row") and @aria-current="true"]/span[@class="ap-t"]'), 'the row shown, marked open');
            self::assertSame(51, $xp->query('//a[contains(@class, "ap-row")]')->length, 'the first 50, and the one asked for');
            self::assertStringContainsString(Words::say('INBOX', 'more_rows', 50), $page->text());
        }
        self::assertSame([Words::CONFIRM_ALL['look'] => 55], self::groupsShown($web->get(InboxController::PATH, ['show' => 'corrections'])));
    }
}
