<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Auth\Permissions;
use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\Counts\CountSheets;
use CW\Documents\Document;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Movements;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\Suppliers;
use CW\Relay\RelayIntake;
use CW\Reservations;
use CW\Settings;
use CW\Stock;
use CW\Tests\Support\Documents\FixtureAdjustmentHandler;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\InboxController;
use CW\Ui\Words;

/**
 * Approvals › Waiting for you (docs/decisions.md AV1-AV14) through the real /ui kernel as cw_app: a person sees only the kinds their jobs
 * answer (the menu's Approvals entry opens the inbox and its badge counts it; a person who answers none is refused); each kind's answer
 * sent from the inbox does exactly what the same answer does on the kind's own page (the record, the stock, the audit: two twin records,
 * one answered on each page); after an answer the next record of the list opens, with the page's own notice; a refusal comes back on the
 * record in the kind's words with what was typed; every answer is a plain form (no script) and the old addresses keep working.
 */
final class ApprovalsInboxScreensTest extends KernelUiTestCase
{
    private const INBOX = InboxController::PATH;

    /** @var list<array<string, mixed>> the settings as they were (a test switches the staff-grant rule on) */
    private array $settings = [];
    /** @var list<array<string, mixed>> the record kinds' rules as they were (a test asks a second person for every count difference) */
    private array $rules = [];
    /** @var list<array<string, mixed>> who held each permission (a test gives the OK of documents without their check) */
    private array $perms = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = self::$db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v, provisional, updated_actor FROM app_setting');
        $this->rules = self::$db->all('SELECT code, CAST(review_rule AS CHAR) AS review_rule, review_limit_units FROM document_type');
        $this->perms = self::$db->all('SELECT permission, roles, updated_actor FROM role_permission');
    }

    protected function tearDown(): void
    {
        foreach ($this->settings as $r) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ? WHERE setting_key = ?',
                [$r['v'], (int) $r['provisional'], $r['updated_actor'], $r['setting_key']]);
        }
        foreach ($this->rules as $r) {
            self::$db->exec('UPDATE document_type SET review_rule = ?, review_limit_units = ? WHERE code = ?', [$r['review_rule'], $r['review_limit_units'], $r['code']]);
        }
        $kept = array_column($this->perms, 'permission');
        self::$db->exec('DELETE FROM role_permission WHERE permission NOT IN (' . implode(', ', array_fill(0, max(1, count($kept)), '?')) . ')', $kept === [] ? [''] : $kept);
        foreach ($this->perms as $r) {
            self::$db->exec('UPDATE role_permission SET roles = ?, updated_actor = ? WHERE permission = ?', [$r['roles'], $r['updated_actor'], $r['permission']]);
        }
        Permissions::useStored(null);
        parent::tearDown();
    }

    /**
     * One row in each of the stores' queues, for the store $code (twins: each call makes its own store and item, the same figures).
     *
     * @return array{suspense: int, correction: int, mode: int, oversold: int, sku: int, code: string}
     */
    private function fill(string $code, string $item = 'Blue Razz 20mg'): array
    {
        $site = self::makeChannel($code, 'live');
        self::$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$site, self::warehouseId('MAIN')]);
        self::$db->exec('UPDATE channel SET movement_types = ? WHERE id = ?', [json_encode(['goods_in', 'erp_reconciliation', 'erp_mode']), $site]);
        $caller = Caller::channel($site, $code);
        $sku = self::makeSku($item);
        $skuCode = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        self::$db->exec("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status) VALUES (?, 'V1', ?, 1, 'mapped')", [$site, $sku]);
        $moves = new Movements(self::$db);
        $moves->record(Caller::system('test'), ['type' => 'goods_in', 'doc_ref' => 'OPEN-' . $code, 'lines' => [['sku_id' => $sku, 'qty' => 1]]], 'open-' . $code);
        (new Stock(self::$db))->setPolicy(Caller::system('test'), $sku, 'strict', 'pol-' . $code);
        $moves->record($caller, ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-' . $code, 'lines' => [['variant_id' => 'NEW', 'qty' => 2, 'description' => 'Mango 10ml']]], 'relay-' . $code);
        $relay = new RelayIntake(self::$db);
        $relay->reconciliations($caller, ['doc_ref' => 'MAT-RECO-1', 'lines' => [['variant_id' => 'V1', 'qty' => 4]]], 'reco-' . $code);
        $relay->modes($caller, ['doc_ref' => $code . '-mode-1', 'lines' => [['variant_id' => 'V1', 'mode' => 'In-Stock']]], 'mode-' . $code);
        (new Reservations(self::$db))->commit($caller, '500', [['variant_id' => 'V1', 'qty' => 3, 'unit_ids' => ['a', 'b', 'c']]], 'unreserved', 'commit-' . $code);
        $one = static fn (string $table, string $where): int => (int) self::$db->value("SELECT MAX(id) FROM {$table} WHERE {$where}", [$site]);
        return ['suspense' => $one('goods_in_suspense', 'channel_id = ?'), 'correction' => (int) self::$db->value('SELECT MAX(id) FROM count_review WHERE sku_id = ?', [$sku]),
            'mode' => (int) self::$db->value('SELECT MAX(id) FROM policy_review WHERE sku_id = ?', [$sku]),
            'oversold' => (int) self::$db->value('SELECT MAX(id) FROM oversell_event WHERE sku_id = ?', [$sku]), 'sku' => $sku, 'code' => $skuCode];
    }

    /** The groups of the inbox, in order: name => count. @return array<string, int> */
    private static function kindsShown(UiResponse $page): array
    {
        $xp = new \DOMXPath($page->dom());
        $out = [];
        foreach ($xp->query('//div[contains(concat(" ", @class, " "), " ap-group ")]/h2') ?: [] as $h) {
            $out[trim((string) $xp->evaluate('string(span[@class="ap-gname"])', $h))] = (int) trim((string) $xp->evaluate('string(span[@class="ap-gcount"])', $h));
        }
        return $out;
    }

    /** The open record's title, and the main action of its decision. @return array{title: string, action: string} */
    private static function open(UiResponse $page): array
    {
        $xp = new \DOMXPath($page->dom());
        return ['title' => trim((string) $xp->evaluate('string(//h2[@id="ap-h"])')), 'action' => (string) $xp->evaluate('string(//form[@id="ap-answer"]/@action)')];
    }

    /**
     * Opens a record in the inbox and sends one of its answers: the main one (the form's own route), a More button of the same form
     * (another route, `formaction`) or a More fold (its own form). Returns the answer's response and what the audit log got from it.
     *
     * @param array<string, string> $fields
     * @return array{0: UiResponse, 1: list<string>}
     */
    private function inbox(KernelBrowser $web, string $key, string $route, array $fields): array
    {
        $page = $web->get(self::INBOX, ['open' => $key]);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $main = self::open($page)['action'];
        self::assertNotSame('', $main, "{$key}: a decision form");
        $xp = new \DOMXPath($page->dom());
        if ($route !== $main && $xp->query('//form[@action="' . $route . '"]')->length === 0) {
            self::assertSame(1, $xp->query('//details[contains(@class, "more")]//button[@form="ap-answer" and @formaction="' . $route . '"]')->length, "{$route}: under More");
        }
        $form = $xp->query('//form[@action="' . $route . '"]')->length > 0 ? $page->form($route) : $page->form($main);
        self::assertSame('1', $form['inbox'] ?? null, 'the form says it came from the inbox');
        return $this->sent(fn (): UiResponse => $web->post($route, $fields + $form));
    }

    /** Sends an answer on the kind's own page (its form there), as the inbox's twin. @param array<string, string> $fields @return array{0: UiResponse, 1: list<string>} */
    private function own(KernelBrowser $web, string $page, string $route, array $fields, ?string $formOf = null): array
    {
        $p = $web->get($page);
        self::assertSame(200, $p->status, $p->describe());
        $form = $p->form($formOf ?? $route);
        self::assertNotSame([], $form, "{$route} on {$page}");
        return $this->sent(fn (): UiResponse => $web->post($route, $fields + $form));
    }

    /** @param \Closure(): UiResponse $send @return array{0: UiResponse, 1: list<string>} the response, and the audit rows it wrote (action, kind of record) */
    private function sent(\Closure $send): array
    {
        $before = (int) self::$db->value('SELECT COALESCE(MAX(id), 0) FROM audit_log');
        $r = $send();
        $audit = array_map(static fn (array $a): string => $a['action'] . ' ' . $a['entity_type'],
            self::$db->all('SELECT action, entity_type FROM audit_log WHERE id > ? ORDER BY id', [$before]));
        return [$r, $audit];
    }

    private function onHand(int $sku): int
    {
        return (int) (self::$db->value('SELECT on_hand FROM stock_balance WHERE warehouse_id = ? AND sku_id = ?', [self::warehouseId('MAIN'), $sku]) ?? 0);
    }

    /** A queue row as its answer left it (status, resolution, note, who), without its id. @return array<string, mixed> */
    private static function settled(string $table, int $id): array
    {
        $res = $table === 'goods_in_suspense' ? 'NULL' : 'resolution';
        return (array) self::$db->one("SELECT CAST(status AS CHAR) AS status, {$res} AS resolution, note, resolved_by IS NOT NULL AS by_someone FROM {$table} WHERE id = ?", [$id]);
    }

    public function testAPersonSeesOnlyTheKindsTheyAnswerAndTheMenuOpensTheInbox(): void
    {
        $this->fill('vpg');
        // Nobody who answers none of the kinds: no tab, and the page refuses them.
        foreach (['buyer', 'viewer', 'admin', 'auditor'] as $role) {
            $web = $this->signIn($this->uiUser($role));
            self::assertSame(403, $web->get(self::INBOX)->status, $role);
            self::assertNotSame(self::INBOX, self::nav($web->get('/ui/'))['Approvals']['href'] ?? null, "{$role}: the menu does not open it");
        }
        // The stock controller answers the four queues (and the count differences, none waiting): the menu opens the inbox, its count is
        // the inbox's, and the tab comes first.
        $sc = $this->signIn($this->uiUser('stock_controller'));
        $page = $sc->get(self::INBOX);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(['ERPNext corrections' => 1, 'Lines to place' => 1, 'Oversold' => 1, 'Mode changes' => 1], self::kindsShown($page), 'the kinds in the brief\'s order');
        self::assertSame(['href' => self::INBOX, 'count' => 4], self::nav($page)['Approvals'], 'the badge counts the inbox, once');
        self::assertSame(['Waiting for you', 4, true], [self::segments($page)[0]['label'], self::segments($page)[0]['count'], self::segments($page)[0]['current']],
            'Approvals is one entry: Waiting for you is its first Show choice (RD7)');
        self::assertSame(self::INBOX, self::nav($sc->get('/ui/'))['Approvals']['href'], 'the menu\'s Approvals entry opens the inbox');
        // The purchasing desk does not answer ERPNext corrections; a reviewer answers none of the stores' queues.
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $p = $desk->get(self::INBOX);
        self::assertSame(['Lines to place' => 1, 'Oversold' => 1, 'Mode changes' => 1], self::kindsShown($p));
        self::assertSame(3, self::nav($p)['Approvals']['count']);
        $rev = $this->signIn($this->uiUser('reviewer'));
        $p = $rev->get(self::INBOX);
        self::assertSame(200, $p->status);
        self::assertSame([], self::kindsShown($p));
        self::assertStringContainsString(Words::INBOX['none'], $p->text(), 'nothing waits for them: said, with no record open');
        self::assertSame(0, self::nav($p)['Approvals']['count']);
        // The per-kind pages stay where they were.
        foreach (['/ui/queues/suspense', '/ui/queues/corrections', '/ui/queues/modes', '/ui/queues/oversold', '/ui/queues/landing', '/ui/stock/counts/differences'] as $path) {
            self::assertSame(200, $sc->get($path)->status, $path);
        }
        foreach (['/ui/documents/reviews', '/ui/staff-requests'] as $path) {
            self::assertSame(200, $rev->get($path)->status, $path);
        }
        // Each group links to its own page; each row opens its record, but the open one (the page shown: no link, AV19).
        $xp = new \DOMXPath($page->dom());
        self::assertSame(['/ui/queues/corrections', '/ui/queues/suspense', '/ui/queues/oversold', '/ui/queues/modes'],
            array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'), iterator_to_array($xp->query('//a[@class="ap-all"]'))));
        self::assertSame(3, $xp->query('//ul[@class="ap-rows"]/li/a[starts-with(@href, "/ui/approvals?open=")]')->length);
        self::assertSame(1, $xp->query('//a[contains(@class, "ap-row") and contains(@class, "sel") and @aria-current="true"]')->length, 'the first one is open');
    }

    public function testEachAnswerOfTheStoresQueuesDoesFromTheInboxWhatItDoesOnItsOwnPage(): void
    {
        $a = $this->fill('vpg', 'Blue Razz 20mg');
        $b = $this->fill('efg', 'Blue Razz 20mg too');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $q = '/ui/queues';

        // Lines to place: placed on the item, the stock booked, the same audit.
        $onA = $this->onHand($a['sku']);
        [$r, $auditA] = $this->own($web, $q . '/suspense', $q . '/suspense/' . $a['suspense'] . '/pick', ['item' => $a['code'], 'units' => '1', 'note' => 'the new mango']);
        self::assertSame(303, $r->status, $r->describe());
        $onB = $this->onHand($b['sku']);
        [$r, $auditB] = $this->inbox($web, 'suspense-' . $b['suspense'], $q . '/suspense/' . $b['suspense'] . '/pick', ['item' => $b['code'], 'units' => '1', 'note' => 'the new mango']);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringStartsWith(self::INBOX . '?', (string) $r->location(), 'back to the inbox');
        self::assertStringContainsString(Words::say('QUEUE_NOTICE', 'booked', $b['code']), $web->follow($r)->text(), 'the page\'s own notice');
        self::assertSame($auditA, $auditB);
        self::assertNotSame([], $auditB);
        self::assertSame([self::settled('goods_in_suspense', $a['suspense']), $this->onHand($a['sku']) - $onA],
            [self::settled('goods_in_suspense', $b['suspense']), $this->onHand($b['sku']) - $onB]);
        self::assertSame($a['sku'], (int) self::$db->value('SELECT resolved_sku_id FROM goods_in_suspense WHERE id = ?', [$a['suspense']]));
        self::assertSame($b['sku'], (int) self::$db->value('SELECT resolved_sku_id FROM goods_in_suspense WHERE id = ?', [$b['suspense']]));

        // ERPNext corrections: confirm books the difference.
        $onA = $this->onHand($a['sku']);
        $onB = $this->onHand($b['sku']);
        [$r, $auditA] = $this->own($web, $q . '/corrections', $q . '/corrections/' . $a['correction'] . '/confirm', ['note' => 'agreed with the shelf']);
        self::assertSame(303, $r->status, $r->describe());
        [$r, $auditB] = $this->inbox($web, 'corrections-' . $b['correction'], $q . '/corrections/' . $b['correction'] . '/confirm', ['note' => 'agreed with the shelf']);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame($auditA, $auditB);
        self::assertSame([self::settled('count_review', $a['correction']), $this->onHand($a['sku']) - $onA], [self::settled('count_review', $b['correction']), $this->onHand($b['sku']) - $onB]);
        self::assertSame('resolved', self::settled('count_review', $b['correction'])['status']);

        // Mode changes: "Keep what CW has" is under More (a button of the same form, its own route).
        [$r, $auditA] = $this->own($web, $q . '/modes', $q . '/modes/' . $a['mode'] . '/dismiss', ['note' => 'the owner keeps strict'], $q . '/modes/' . $a['mode'] . '/accept');
        self::assertSame(303, $r->status, $r->describe());
        [$r, $auditB] = $this->inbox($web, 'modes-' . $b['mode'], $q . '/modes/' . $b['mode'] . '/dismiss', ['note' => 'the owner keeps strict']);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame($auditA, $auditB);
        self::assertSame(self::settled('policy_review', $a['mode']), self::settled('policy_review', $b['mode']));
        self::assertSame('dismissed', self::settled('policy_review', $b['mode'])['status']);
        self::assertSame(['strict', 'strict'], [self::$db->value('SELECT sell_policy FROM sku WHERE id = ?', [$a['sku']]), self::$db->value('SELECT sell_policy FROM sku WHERE id = ?', [$b['sku']])]);

        // Oversold: a note first (it settles nothing: the record stays open in the inbox), then "Mark handled" with how.
        [$r, $auditA] = $this->own($web, $q . '/oversold', $q . '/oversold/' . $a['oversold'] . '/note', ['note' => 'customer phoned']);
        self::assertSame(303, $r->status, $r->describe());
        [$r, $auditB] = $this->inbox($web, 'oversold-' . $b['oversold'], $q . '/oversold/' . $b['oversold'] . '/note', ['note' => 'customer phoned']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame($auditA, $auditB);
        $after = $web->follow($r);
        self::assertSame($q . '/oversold/' . $b['oversold'] . '/handle', self::open($after)['action'], 'a note keeps the same record open');
        self::assertStringContainsString('customer phoned', $after->text(), 'the notes so far');
        [$r, $auditA] = $this->own($web, $q . '/oversold', $q . '/oversold/' . $a['oversold'] . '/handle', ['resolution' => 'refunded', 'note' => 'refunded in full']);
        self::assertSame(303, $r->status, $r->describe());
        [$r, $auditB] = $this->inbox($web, 'oversold-' . $b['oversold'], $q . '/oversold/' . $b['oversold'] . '/handle', ['resolution' => 'refunded', 'note' => 'refunded in full']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame($auditA, $auditB);
        self::assertSame(self::settled('oversell_event', $a['oversold']), self::settled('oversell_event', $b['oversold']));
        self::assertSame(['resolved', 'refunded'], [self::settled('oversell_event', $b['oversold'])['status'], self::settled('oversell_event', $b['oversold'])['resolution']]);

        // Every twin is answered: nothing is left, and the badge says so.
        $left = $web->get(self::INBOX);
        self::assertSame([], self::kindsShown($left));
        self::assertSame(0, self::nav($left)['Approvals']['count']);
    }

    public function testAfterAnAnswerTheNextRecordOpensAndARefusalComesBackOnTheRecord(): void
    {
        $a = $this->fill('vpg', 'Alpha pod');
        $b = $this->fill('efg', 'Beta pod');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get(self::INBOX);
        self::assertSame(['ERPNext corrections' => 2, 'Lines to place' => 2, 'Oversold' => 2, 'Mode changes' => 2], self::kindsShown($page));
        self::assertSame(['title' => 'Alpha pod', 'action' => '/ui/queues/corrections/' . $a['correction'] . '/confirm'], self::open($page), 'the first record is open');
        self::assertStringContainsString(Words::say('INBOX', 'next', 'Beta pod'), $page->text(), 'the next one is named');
        self::assertStringContainsString(Words::say('INBOX', 'of', 1, 8), $page->text());

        // A refusal (a note too short) comes back on the same record, in the queue's words, with what was typed.
        $form = $page->form('/ui/queues/corrections/' . $a['correction'] . '/confirm');
        $short = $web->post('/ui/queues/corrections/' . $a['correction'] . '/confirm', ['note' => 'x'] + $form);
        self::assertSame(400, $short->status, $short->describe());
        self::assertSame('Alpha pod', self::open($short)['title']);
        self::assertStringContainsString(Words::QUEUE_ERROR['note_required'], $short->text());
        self::assertSame('x', $short->form('/ui/queues/corrections/' . $a['correction'] . '/confirm')['note'] ?? null, 'what was typed is kept');
        self::assertSame('open', self::settled('count_review', $a['correction'])['status'], 'nothing was saved');

        // Answered: the next record of the same list opens, with the notice of the corrections' page.
        $r = $web->post('/ui/queues/corrections/' . $a['correction'] . '/confirm', ['note' => 'checked on the shelf'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        $next = $web->follow($r);
        self::assertSame(['title' => 'Beta pod', 'action' => '/ui/queues/corrections/' . $b['correction'] . '/confirm'], self::open($next));
        self::assertStringContainsString(Words::QUEUE_NOTICE['confirmed'], $next->text());
        self::assertStringContainsString(Words::say('INBOX', 'of', 1, 7), $next->text(), 'the place in the list is kept');
        self::assertSame(['ERPNext corrections' => 1, 'Lines to place' => 2, 'Oversold' => 2, 'Mode changes' => 2], self::kindsShown($next));
        self::assertSame(7, self::nav($next)['Approvals']['count']);

        // The last of its kind: the next record is the first of the next kind.
        $form = $next->form('/ui/queues/corrections/' . $b['correction'] . '/confirm');
        self::assertSame('suspense-' . $a['suspense'], $form['inbox_next'] ?? null);
        $r = $web->post('/ui/queues/corrections/' . $b['correction'] . '/confirm', ['note' => 'checked on the shelf'] + $form);
        self::assertSame('/ui/queues/suspense/' . $a['suspense'] . '/pick', self::open($web->follow($r))['action']);

        // Someone else answered the record meanwhile: the refusal is said above the list, and the record now at that place opens.
        $page = $web->get(self::INBOX, ['open' => 'oversold-' . $a['oversold']]);
        $form = $page->form('/ui/queues/oversold/' . $a['oversold'] . '/handle');
        self::$db->exec("UPDATE oversell_event SET status = 'resolved', resolution = 'no_action', resolved_at = NOW(6), note = 'by hand' WHERE id = ?", [$a['oversold']]);
        $late = $web->post('/ui/queues/oversold/' . $a['oversold'] . '/handle', ['resolution' => 'refunded', 'note' => 'refunded in full'] + $form);
        self::assertSame(409, $late->status, $late->describe());
        self::assertStringContainsString(Words::QUEUE_ERROR['queue_settled'], (string) (new \DOMXPath($late->dom()))->evaluate('string(//main//p[@role="alert"][1])'));
        self::assertSame('/ui/queues/oversold/' . $b['oversold'] . '/handle', self::open($late)['action'], 'the record now at that place');
        self::assertSame('resolved', self::settled('oversell_event', $a['oversold'])['status']);
        self::assertSame('no_action', self::settled('oversell_event', $a['oversold'])['resolution'], 'nothing more was saved');
    }

    public function testEveryAnswerIsAPlainFormWithOneMainButtonAndTheItemBoxOfLinesToPlace(): void
    {
        $a = $this->fill('vpg');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $xp = new \DOMXPath($web->get(self::INBOX, ['open' => 'suspense-' . $a['suspense']])->dom());
        // One form, a POST of the kind's own route with the CSRF token, the inbox's fields, the item box and the note; the main answer names it.
        $form = $xp->query('//form[@id="ap-answer"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        self::assertSame(['post', '/ui/queues/suspense/' . $a['suspense'] . '/pick'], [$form->getAttribute('method'), $form->getAttribute('action')]);
        foreach (['csrf', 'inbox', 'inbox_pos', 'inbox_next', 'units', 'note'] as $name) {
            self::assertSame(1, $xp->query('.//input[@name="' . $name . '"]', $form)->length, $name);
        }
        self::assertSame(1, $xp->query('//input[@name="item" and @form="ap-answer" and @data-lq-pick and @data-options="/ui/queues/suspense/items?"]')->length, 'Lines to place keeps its item box (LQ)');
        self::assertSame(1, $xp->query('//ul[@data-lq-list and @id="lq-list-ap"]')->length);
        $mains = $xp->query('//*[contains(concat(" ", @class, " "), " primary ")]');
        self::assertSame(1, $mains->length, 'one main answer');
        self::assertSame(['ap-answer', Words::LQ['place']], [$mains->item(0)->getAttribute('form'), trim($mains->item(0)->textContent)]);
        // The rarer answer (close the line) under More: a fold with its own form and its own note, no script needed.
        self::assertSame(1, $xp->query('//details[contains(@class, "more")]//details[@class="doc-act"]//form[@method="post" and @action="/ui/queues/suspense/' . $a['suspense'] . '/dismiss"]//input[@name="note" and @required]')->length);
        // The rows are plain links, but the open one, which is the page shown (CV13's rule for a tab, AV19: a click on it would reload the
        // page and lose what was typed); the facts are big figures.
        self::assertGreaterThan(0, $xp->query('//dl[@class="ap-facts"]/div[@class="ap-fact"]/dd')->length);
        $rows = 0;
        foreach ($xp->query('//a[contains(@class, "ap-row")]') ?: [] as $link) {
            /** @var \DOMElement $link */
            $rows++;
            if ($link->getAttribute('aria-current') === 'true') {
                self::assertFalse($link->hasAttribute('href'), 'the open record\'s row is no link');
                self::assertStringContainsString('sel', $link->getAttribute('class'));
            } else {
                self::assertStringStartsWith('/ui/approvals?open=', $link->getAttribute('href'));
            }
        }
        self::assertSame(4, $rows);
        self::assertSame(1, $xp->query('//a[contains(@class, "ap-row") and @aria-current="true"]')->length);
        // What is typed in an answer is guarded: a link that leaves the page asks first (main's data-unsaved, CV14), the folds' notes too.
        self::assertSame(1, $xp->query('//form[@id="ap-answer" and @data-unsaved="1"]')->length);
        self::assertSame(1, $xp->query('//details[@class="doc-act"]//form[@data-unsaved="1"]')->length);
        // A correction's rarer answer is a button of the same form (its route by formaction): the note goes with it.
        $xp = new \DOMXPath($web->get(self::INBOX, ['open' => 'corrections-' . $a['correction']])->dom());
        self::assertSame(1, $xp->query('//details[contains(@class, "more")]//button[@form="ap-answer" and @formaction="/ui/queues/corrections/' . $a['correction'] . '/dismiss"]')->length);
        // A key that is not waiting opens the first record (never an error).
        self::assertSame(200, $web->get(self::INBOX, ['open' => 'corrections-999999'])->status);
        self::assertSame(200, $web->get(self::INBOX, ['open' => '<x>', 'pos' => 'y'])->status);
    }

    /**
     * Enter in an answer's field sends the main answer, on every kind (the fix round of 30 Oct 2026, docs/decisions.md RD-F1; the Codex
     * review of 8cba061): the form's implicit submitter (the first submit button whose form is the answer's, in the page's order) is the main
     * answer, never a rarer one under More (Reject, Dismiss), which since the redesign stands before the main button.
     */
    public function testEnterSendsTheMainAnswerOnEveryKind(): void
    {
        $a = $this->fill('vpg');
        $x = $this->item('strict', 100, 'Zeta pod');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $r = $web->post('/ui/stock/counts', ['mode' => 'ticked', 'pick_' . $x => '1'] + $web->get('/ui/stock/counts')->form('/ui/stock/counts'));
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $sheet = (string) parse_url((string) $r->location(), PHP_URL_PATH);
        $line = (int) self::$db->value('SELECT line_no FROM count_sheet_line WHERE document_id = ? AND sku_id = ?', [(int) basename($sheet), $x]);
        $web->post($sheet . '/lines', ['csrf' => $this->token($web), 'q_' . $line => '80']);
        self::assertSame(303, $web->post($sheet . '/post', $web->get($sheet . '/differences')->form($sheet . '/post'))->status);
        // Every record of the list: the one open at first, then each row's own link.
        $queries = [[]];
        foreach ((new \DOMXPath($web->get(self::INBOX)->dom()))->query('//a[contains(@class, "ap-row")][@href]') ?: [] as $link) {
            /** @var \DOMElement $link */
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            $queries[] = $query;
        }
        $kinds = [];
        foreach ($queries as $query) {
            $page = $web->get(self::INBOX, $query);
            self::assertSame(200, $page->status, $page->describe());
            $xp = new \DOMXPath($page->dom());
            self::assertInstanceOf(\DOMElement::class, $xp->query('//form[@id="ap-answer"]')->item(0));
            $kind = substr((string) $xp->evaluate('string(//a[contains(@class, "ap-row") and @aria-current="true"]/ancestor::div[contains(@class, "ap-group")]/h2/@id)'), 5);
            // The submit buttons whose form is the answer's, in the page's order (an XPath union is in document order).
            $buttons = array_values(array_filter(iterator_to_array($xp->query('//form[@id="ap-answer"]//button[not(@form)] | //button[@form="ap-answer"]')),
                static fn (\DOMElement $b): bool => in_array($b->getAttribute('type'), ['', 'submit'], true)));
            $main = $xp->query('//button[@form="ap-answer" and contains(concat(" ", @class, " "), " primary ")]')->item(0);
            self::assertInstanceOf(\DOMElement::class, $main, $kind);
            $first = $buttons[0];
            self::assertFalse($first->hasAttribute('disabled'), "{$kind}: Enter answers");
            self::assertSame('', $first->getAttribute('formaction'), "{$kind}: Enter goes to the main answer's route, not a rarer one under More");
            self::assertSame([$main->getAttribute('name'), $main->getAttribute('value')], [$first->getAttribute('name'), $first->getAttribute('value')],
                "{$kind}: Enter sends the main answer");
            $kinds[$kind] = true;
        }
        foreach (['corrections', 'suspense', 'modes', 'oversold', 'counts'] as $kind) {
            self::assertArrayHasKey($kind, $kinds, "{$kind} was opened");
        }
        unset($a);
    }

    public function testWebsiteBugsFromTheInbox(): void
    {
        $site = $this->site('electrofag');
        $title = 'Blue Razz Cherry Elux Legend Nic Salt 10ml';
        $landing = $this->profiled($site, '960', ['product_title' => $title, 'variant_title' => $title, 'units_365d' => 4]);
        $this->ds->decide(Caller::system('landing_pages'), ['action' => 'landing', 'listing_id' => $landing, 'expected_map_version' => $this->version($landing),
            'landing' => ['reason' => 'website_flag', 'source' => 'tool']]);
        $orders = new Reservations(self::$db);
        $orders->commit($site, '5001', [['variant_id' => '960', 'qty' => 2, 'unit_ids' => ['a', 'b']]], 'unreserved', 'c-5001');
        $orders->commit($site, '5002', [['variant_id' => '960', 'qty' => 2, 'unit_ids' => ['c', 'd']]], 'unreserved', 'c-5002');
        [$one, $two] = array_map('intval', self::$db->column('SELECT id FROM landing_order ORDER BY id'));
        $web = $this->signIn($this->uiUser('stock_controller'));
        self::assertSame(['Website bugs' => 2], self::kindsShown($web->get(self::INBOX)));
        [$r, $auditA] = $this->own($web, '/ui/queues/landing', '/ui/queues/landing/' . $one . '/handle', ['resolution' => 'fixed_by_hand', 'note' => 'sent the 20mg']);
        self::assertSame(303, $r->status, $r->describe());
        [$r, $auditB] = $this->inbox($web, 'landing-' . $two, '/ui/queues/landing/' . $two . '/handle', ['resolution' => 'fixed_by_hand', 'note' => 'sent the 20mg']);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame($auditA, $auditB);
        self::assertSame(self::settled('landing_order', $one), self::settled('landing_order', $two));
        self::assertSame(['resolved', 'fixed_by_hand'], [self::settled('landing_order', $two)['status'], self::settled('landing_order', $two)['resolution']]);
        $done = $web->follow($r);
        self::assertStringContainsString(Words::QUEUE_NOTICE['handled'], $done->text());
        self::assertStringContainsString(Words::INBOX['none'], $done->text(), 'nothing is left');
    }

    public function testCountDifferencesFromTheInbox(): void
    {
        $x = $this->item('strict', 100, 'Gamma pod');
        $y = $this->item('strict', 100, 'Delta pod');
        $z = $this->item('strict', 100, 'Epsilon pod');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $list = $web->get('/ui/stock/counts');
        $form = $list->form('/ui/stock/counts');
        $r = $web->post('/ui/stock/counts', ['mode' => 'ticked', 'pick_' . $x => '1', 'pick_' . $y => '1', 'pick_' . $z => '1'] + $form);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $sheet = (string) parse_url((string) $r->location(), PHP_URL_PATH);
        $id = (int) basename($sheet);
        $lines = array_column(self::$db->all('SELECT line_no, sku_id FROM count_sheet_line WHERE document_id = ?', [$id]), 'line_no', 'sku_id');
        $web->post($sheet . '/lines', ['csrf' => $this->token($web), 'q_' . $lines[$x] => '80', 'q_' . $lines[$y] => '80', 'q_' . $lines[$z] => '80']);
        $before = $web->get($sheet . '/differences');
        self::assertSame(303, $web->post($sheet . '/post', $before->form($sheet . '/post'))->status);
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM count_sheet_line WHERE document_id = ? AND review = 'open'", [$id]), 'three differences wait');

        $page = $web->get(self::INBOX);
        self::assertSame(['Count differences' => 3], self::kindsShown($page));
        $route = static fn (int $sku): string => '/ui/stock/counts/differences/line/' . $id . '/' . $lines[$sku];
        // Accept: the count stays, the same as on the Differences page.
        [$r, $auditA] = $this->own($web, '/ui/stock/counts/differences', $route($x), ['answer' => 'accept', 'note' => 'two boxes thrown away']);
        self::assertSame(303, $r->status, $r->describe());
        [$r, $auditB] = $this->inbox($web, 'counts-l' . $id . '_' . $lines[$y], $route($y), ['answer' => 'accept', 'note' => 'two boxes thrown away']);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame($auditA, $auditB);
        $line = static fn (int $sku): array => (array) self::$db->one('SELECT review, review_note, reviewed_by IS NOT NULL AS by_someone FROM count_sheet_line WHERE document_id = ? AND sku_id = ?', [$id, $sku]);
        self::assertSame($line($x), $line($y));
        self::assertSame('accepted', $line($y)['review']);
        self::assertStringContainsString(Words::COUNT_NOTICE['accepted'], $web->follow($r)->text());
        // Reject, under More (a button of the same form): the count of that line is put back by a new record, as on its own page.
        [$r] = $this->inbox($web, 'counts-l' . $id . '_' . $lines[$z], $route($z), ['answer' => 'reject', 'note' => 'the shelf was not counted']);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame('rejected', $line($z)['review']);
        self::assertSame([100, 80, 80], [$this->onHand($z), $this->onHand($x), $this->onHand($y)], 'the rejected count is put back; the accepted ones stay');
        self::assertSame([0, 0, 1], array_map(static fn (int $sku): int => (int) self::$db->value('SELECT reversal_document_id IS NOT NULL FROM count_sheet_line WHERE document_id = ? AND sku_id = ?',
            [$id, $sku]), [$x, $y, $z]), 'a record puts that line back');
    }

    public function testStaffRequestsAndDocumentsToReviewFromTheInbox(): void
    {
        // Two grants of Reviewer wait (the owner's staff-grant rule on): one is said OK on its page, the other in the inbox.
        $admin = $this->uiUser('admin');
        (new Settings(self::$db))->change(Caller::staff($admin['id']), 'approvals.staff_grant', 'true', 'the owner wants a second person');
        $p1 = $this->uiUser('buyer');
        $p2 = $this->uiUser('buyer');
        $adm = $this->signIn($admin);
        foreach ([$p1, $p2] as $p) {
            $form = $adm->get('/ui/people/' . $p['id'])->form('/ui/people/' . $p['id'] . '/roles');
            self::assertSame(303, $adm->post('/ui/people/' . $p['id'] . '/roles', ['role_reviewer' => '1'] + $form)->status);
        }
        [$r1, $r2] = array_map('intval', self::$db->column("SELECT id FROM staff_role_request WHERE state = 'open' ORDER BY id"));
        // Three records to review (the test-only adjustments of ReviewScreensTest).
        $poster = $this->uiUser(['stock_controller', 'reviewer']);
        $docs = new Documents(self::$db, ['ADJ' => new FixtureAdjustmentHandler(self::$db)]);
        $sku = self::makeSku('Screen item');
        $post = static function (array $lines) use ($docs, $poster): Document {
            $who = Caller::staff($poster['id']);
            $d = $docs->createDraft($who, 'ADJ', ['external_ref' => 'SUP-UI']);
            $d = $docs->setLines($who, $d->id, $d->version, $lines);
            return $docs->post($who, $d->id, $d->version);
        };
        $a = $post([['sku_id' => $sku, 'qty' => 3, 'unit_cost' => '1.5']]);
        $b = $post([['sku_id' => $sku, 'qty' => 3, 'unit_cost' => '1.5']]);
        $c = $post([['sku_id' => $sku, 'qty' => -1]]);
        $task = static fn (Document $d): int => (int) self::$db->value("SELECT id FROM review_task WHERE subject_id = ? AND state = 'open'", [$d->id]);
        [$ta, $tb, $tc] = [$task($a), $task($b), $task($c)];

        $rev = $this->signIn($this->uiUser('reviewer'));
        $page = $rev->get(self::INBOX);
        self::assertSame(['Staff requests' => 2, 'Documents to review' => 3], self::kindsShown($page));
        self::assertSame(5, self::nav($page)['Approvals']['count'], 'the badge counts the staff requests too');
        // The poster sees their own records nowhere in their inbox: they may not answer them.
        self::assertArrayNotHasKey('Documents to review', self::kindsShown($this->signIn($poster)->get(self::INBOX)));

        // Staff requests: OK on its page and in the inbox do the same.
        [$r, $auditA] = $this->own($rev, '/ui/staff-requests', '/ui/staff-requests/' . $r1 . '/approve', []);
        self::assertSame(303, $r->status, $r->describe());
        [$r, $auditB] = $this->inbox($rev, 'staff-' . $r2, '/ui/staff-requests/' . $r2 . '/approve', []);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame($auditA, $auditB);
        $state = static fn (int $id): array => (array) self::$db->one('SELECT state, decided_by IS NOT NULL AS by_someone, decision_note FROM staff_role_request WHERE id = ?', [$id]);
        self::assertSame($state($r1), $state($r2));
        self::assertSame('approved', $state($r2)['state']);
        self::assertStringContainsString(Words::STAFF_NOTICE['request_approved'], $rev->follow($r)->text());

        // Documents to review: OK on the record's page and in the inbox do the same; Not OK (under More, its own note) reverses the record.
        [$r, $auditA] = $this->own($rev, '/ui/documents/' . $a->id, '/ui/documents/reviews/' . $ta . '/approve', ['note' => 'counted again: right']);
        self::assertSame(303, $r->status, $r->describe());
        [$r, $auditB] = $this->inbox($rev, 'documents-' . $tb, '/ui/documents/reviews/' . $tb . '/approve', ['note' => 'counted again: right']);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame($auditA, $auditB);
        $decided = static fn (int $t): array => (array) self::$db->one('SELECT state, decision_note, decided_by IS NOT NULL AS by_someone FROM review_task WHERE id = ?', [$t]);
        self::assertSame($decided($ta), $decided($tb));
        self::assertSame(['approved', 'approved'], [self::$db->value('SELECT review_state FROM document WHERE id = ?', [$a->id]), self::$db->value('SELECT review_state FROM document WHERE id = ?', [$b->id])]);
        self::assertStringContainsString(Words::RECORD_NOTICE['approved'], $rev->follow($r)->text());
        // Not OK without a note: refused on the record, the fold open again; with one, the record is reversed.
        [$short] = $this->inbox($rev, 'documents-' . $tc, '/ui/documents/reviews/' . $tc . '/reject', ['note' => '']);
        self::assertSame(400, $short->status, $short->describe());
        self::assertSame(1, (new \DOMXPath($short->dom()))->query('//details[contains(@class, "more") and @open]//details[@open]//form[@action="/ui/documents/reviews/' . $tc . '/reject"]')->length);
        [$r] = $this->inbox($rev, 'documents-' . $tc, '/ui/documents/reviews/' . $tc . '/reject', ['note' => 'wrong item scanned']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM document WHERE reverses_id = ?', [$c->id]), 'reversed, as from its page');
        self::assertStringContainsString(Words::INBOX['none'], $rev->follow($r)->text());
    }

    /** Every POST of the inbox is a route of a kind's own page: the same permission and CSRF token (StoreQueuesScreensTest's checks hold). */
    public function testTheInboxFieldsChangeNothingButWhereTheAnswerLeads(): void
    {
        $a = $this->fill('vpg');
        $buyer = $this->signIn($this->uiUser('buyer'));
        $r = $buyer->post('/ui/queues/corrections/' . $a['correction'] . '/confirm', ['csrf' => $this->token($buyer), 'note' => 'a fine note', 'inbox' => '1', 'inbox_pos' => '0']);
        self::assertSame(403, $r->status, 'the route\'s permission');
        $sc = $this->signIn($this->uiUser('stock_controller'));
        self::assertSame(403, $sc->post('/ui/queues/corrections/' . $a['correction'] . '/confirm', ['note' => 'a fine note', 'inbox' => '1'])->status, 'the CSRF token');
        self::assertSame('open', self::settled('count_review', $a['correction'])['status']);
        // Where it leads: only the inbox's own address, with a key of the right shape.
        $r = $sc->post('/ui/queues/corrections/' . $a['correction'] . '/confirm', ['csrf' => $this->token($sc), 'note' => 'a fine note', 'inbox' => '1',
            'inbox_next' => 'https://elsewhere.example/', 'inbox_pos' => '-1']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(self::INBOX . '?notice=confirmed&words=queue', $r->location());
    }

    /**
     * The Codex review of c5780dc, finding 1 (AV15): the inbox lists a page of the count differences this person may answer. With a second
     * person asked for every difference, a page of their own newest differences hid an older one by someone else: the badge said 1 and the
     * inbox showed nothing.
     */
    public function testACountDifferenceSomeoneElseMayAnswerIsNotHiddenByAPageOfTheirOwn(): void
    {
        self::$db->exec("UPDATE document_type SET review_rule = 'all' WHERE code = 'CNT'");
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $sheets = new CountSheets(self::$db, $docs);
        $count = static function (Caller $who, array $skus, int $qty) use ($sheets, $docs): int {
            $sheet = $sheets->open($who, ['warehouse' => self::warehouseId('MAIN')], $skus);
            $lines = array_map('intval', self::$db->column('SELECT line_no FROM count_sheet_line WHERE document_id = ? ORDER BY line_no', [$sheet->id]));
            $sheets->setQuantities($who, $sheet->id, array_fill_keys($lines, $qty));
            $sheets->post($who, $sheet->id, $docs->get($sheet->id)->version);
            return $sheet->id;
        };
        $other = $this->uiUser('warehouse');
        $older = $this->item('strict', 100, 'Counted by someone else');
        $count(Caller::staff($other['id']), [$older], 80);
        $mine = [];
        for ($i = 1; $i <= \CW\Counts\CountReviews::PAGE + 1; $i++) {
            $mine[] = $this->item('strict', 100, sprintf('My count %02d', $i));
        }
        $me = $this->uiUser('stock_controller');
        $count(Caller::staff($me['id']), $mine, 80);
        $web = $this->signIn($me);
        $page = $web->get(self::INBOX);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(['Count differences' => 1], self::kindsShown($page), 'the one they may answer');
        self::assertSame('Counted by someone else', self::open($page)['title']);
        self::assertSame(1, self::nav($page)['Approvals']['count'], 'the badge says what the list shows');
        // The other counter may answer every one of mine: a page of them, and the count of all.
        $theirs = $this->signIn($other)->get(self::INBOX);
        self::assertSame(['Count differences' => \CW\Counts\CountReviews::PAGE + 1], self::kindsShown($theirs));
        self::assertSame(\CW\Counts\CountReviews::PAGE, (new \DOMXPath($theirs->dom()))->query('//a[contains(@class, "ap-row")]')->length);
        self::assertStringContainsString(Words::say('INBOX', 'more_rows', \CW\Counts\CountReviews::PAGE), $theirs->text());
    }

    /**
     * Finding 2 (AV16): the inbox offers what the kind's own route lets the person send. The OK of a document without the check of documents
     * (documents.approve given alone on Roles and permissions) was listed, refused with 403 when sent, and not counted.
     */
    public function testTheOkOfADocumentIsOfferedOnlyToWhoItsRouteLetsIn(): void
    {
        $holders = static function (array $roles): void {
            $roles = array_values(array_intersect(Permissions::ROLES, $roles));
            self::$db->exec("INSERT INTO role_permission (permission, roles, updated_actor) VALUES ('documents.approve', ?, 'system:test') "
                . 'ON DUPLICATE KEY UPDATE roles = VALUES(roles)', [implode(',', $roles)]);
        };
        $holders(['reviewer', 'goods_in', 'stock_controller']);
        $poster = $this->uiUser(['stock_controller', 'reviewer']);
        $docs = new Documents(self::$db, ['ADJ' => new FixtureAdjustmentHandler(self::$db)]);
        $who = Caller::staff($poster['id']);
        $d = $docs->createDraft($who, 'ADJ', []);
        $d = $docs->setLines($who, $d->id, $d->version, [['sku_id' => self::makeSku('Held item'), 'qty' => 25]]);
        $docs->post($who, $d->id, $d->version);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_id = ? AND state = 'open' AND kind = 'approval'", [$d->id]);
        self::assertGreaterThan(0, $task, 'a blocking OK waits');

        $desk = $this->signIn($this->uiUser('goods_in'));
        self::assertSame(403, $desk->post('/ui/documents/reviews/' . $task . '/approve', ['csrf' => $this->token($desk)])->status, 'its route refuses them');
        self::assertSame(403, $desk->get(self::INBOX)->status, 'so the inbox does not offer it: they answer no kind');
        self::assertNotSame(self::INBOX, self::nav($desk->get('/ui/'))['Approvals']['href'] ?? null);
        $sc = $this->signIn($this->uiUser('stock_controller'));
        $page = $sc->get(self::INBOX);
        self::assertSame(200, $page->status);
        self::assertArrayNotHasKey('Documents to review', self::kindsShown($page));
        self::assertSame(0, self::nav($page)['Approvals']['count'], 'nor counts it');
        $rev = $this->signIn($this->uiUser('reviewer'))->get(self::INBOX);
        self::assertSame(['Documents to review' => 1], self::kindsShown($rev), 'a reviewer does');
        self::assertSame(1, self::nav($rev)['Approvals']['count']);
    }

    /** Finding 3 (AV17): an answer to a purchase order's check says what the order's page says ("approved_review" is the order's word). */
    public function testAnOrdersCheckAnsweredInTheInboxSaysWhatTheOrdersPageSays(): void
    {
        $buyer = $this->uiUser('buyer');
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyer['id']), ['name' => 'Inbox Supplies', 'address_line1' => '2 Mill Lane', 'city' => 'Leeds', 'postcode' => 'M1 1AA',
            'email' => 'sales@inboxsupplies.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyer['id'], 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation(Caller::staff($buyer['id']), (int) $s['id'], (int) $s['version']);
        $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND state = 'open'"), null);
        $sku = self::makeSku('Inbox ordered item');
        (new ItemCards(self::$db))->save(Caller::staff($this->uiUser('stock_controller')['id']), $sku, 0, ['product_type' => 'coil', 'duty_liable' => 'no']);
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $d = $pos->createDraft(Caller::staff($buyer['id']), (int) $s['id'], []);
        $d = $pos->saveDraft(Caller::staff($buyer['id']), $d->id, $d->version, [], [['sku_id' => $sku, 'packs' => 2, 'units_per_pack' => 1, 'pack_price' => '2.00']]);
        $d = $pos->approve(Caller::staff($buyer['id']), $d->id, $d->version);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND state = 'open'", [$d->id]);
        self::assertGreaterThan(0, $task, 'every order waits for a reviewer\'s check');
        $web = $this->signIn($this->uiUser('reviewer'));
        [$r] = $this->inbox($web, 'documents-' . $task, '/ui/documents/reviews/' . $task . '/approve', []);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringContainsString(Words::PO_NOTICE['approved_review'], $web->follow($r)->text(), 'the order page\'s own notice');
        self::assertSame('approved', self::$db->value('SELECT review_state FROM document WHERE id = ?', [$d->id]));
    }

    /** Finding 4 (AV18): a flag about the time of a count has no counted figure: none is shown (the Differences page shows none either). */
    public function testAFlagAboutACountsTimeShowsNoCountedFigure(): void
    {
        $a = $this->item('strict', 100, 'Alpha pod 2ml');
        self::$db->exec("INSERT INTO count_review (warehouse_id, sku_id, source, ref, detail) VALUES (?, ?, 'ship_near_count', ?, ?)", [self::warehouseId('MAIN'), $a,
            '1:ORD-77:u9', json_encode(['unit_id' => 'u9', 'dispatched_at' => \CW\Clock::db(\CW\Clock::now()->modify('-2 minutes')),
                'counted_at' => \CW\Clock::db(\CW\Clock::now()), 'applied' => 'normal'])]);
        $page = $this->signIn($this->uiUser('stock_controller'))->get(self::INBOX);
        self::assertSame(['Count differences' => 1], self::kindsShown($page));
        $labels = array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent), iterator_to_array((new \DOMXPath($page->dom()))->query('//dl[@class="ap-facts"]//dt')));
        self::assertNotContains(Words::COUNTS['counted'], $labels, 'no "Counted 0"');
        self::assertStringContainsString('ORD-77', $page->text(), 'why it waits: the order it names');
    }
}
