<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Mapping\StoreChoices;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\InboxController;
use CW\Ui\Words;

/**
 * Approvals › New products (docs/decisions.md SC9; the owner's drawing "CW asks first" of 3 Nov 2026) through the real /ui kernel as cw_app:
 * a store's choice at Add product that waits for a person is on its page (why, whose choice, the variant beside the item found) and in
 * Waiting for you; each answer (the same item, a new item, not stock) works from both, as that person, with the page's notice; a variant
 * that changed since the page was drawn is refused in the kind's words with nothing saved; who looks without answering sees no answer and
 * the route refuses them; the badge counts what waits for who answers.
 */
final class NewProductsScreensTest extends KernelUiTestCase
{
    private const PATH = '/ui/queues/new-products';

    /**
     * Three choices of Vape and Go that wait for a person: a barcode on an item (1001), a name close to an item's (1002), a pack of a CW
     * number that is no item (1003, refused).
     *
     * @return array{shop: Caller, item: int, code: string, close: int, ids: array<string, int>, lids: array<string, int>}
     */
    private function fill(): array
    {
        $shop = $this->site('vapeandgo');
        $item = $this->item('legacy', 25, 'Banana Ice Nic Salt E-Liquid by Elux Legend 10ml - 10mg');
        self::$db->exec("INSERT INTO sku_barcode (sku_id, barcode, units_per_scan) VALUES (?, '5059000999999', 1)", [$item]);
        $close = $this->item('legacy', 0, 'Menthol Nic-Salt E Liquid by Elux Legend 10ml (10mg)');
        $profile = static fn (string $title, array $barcodes = []): array => ['product_title' => 'Elux Legend Nic Salt E-Liquid 10ml', 'variant_title' => $title,
            'brand' => 'Elux Legend', 'barcodes' => $barcodes];
        $lids = [
            '1001' => $this->profiled($shop, '1001', $profile('Banana Ice / 10mg', ['5059000999999'])),
            '1002' => $this->profiled($shop, '1002', $profile('Menthol Nic Salt E-Liquid by Elux Legend 10ml - 10mg')),
            '1003' => $this->profiled($shop, '1003', $profile('Mystery pack')),
        ];
        $svc = new StoreChoices(self::$db, $this->ds);
        $who = ['staff' => ['id' => '17', 'name' => 'Aswin'], 'chosen_at' => '2026-11-03T10:42:00Z'];
        $svc->receive($shop, [['variant_id' => '1001', 'choice' => 'new', 'pack_of' => null, 'units' => null] + $who,
            ['variant_id' => '1002', 'choice' => 'new', 'pack_of' => null, 'units' => null] + $who,
            ['variant_id' => '1003', 'choice' => 'pack', 'pack_of' => 'CW-999999', 'units' => 5] + $who]);
        $svc->run(true);
        $ids = [];
        foreach (array_keys($lids) as $v) {
            $ids[(string) $v] = (int) self::$db->value('SELECT id FROM store_choice WHERE channel_id = ? AND external_variant_id = ?', [$shop->channelId, (string) $v]);
        }
        self::assertSame(3, $svc->openCount(), 'three wait for a person');
        return ['shop' => $shop, 'item' => $item, 'code' => (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$item]), 'close' => $close, 'ids' => $ids,
            'lids' => $lids];
    }

    /** @return array{state: string, outcome: string, settled_by: ?int, note: ?string} */
    private static function row(int $id): array
    {
        $r = self::$db->one('SELECT CAST(state AS CHAR) AS state, outcome, settled_by, CAST(detail AS CHAR) AS detail FROM store_choice WHERE id = ?', [$id]);
        return ['state' => (string) $r['state'], 'outcome' => (string) $r['outcome'], 'settled_by' => $r['settled_by'] === null ? null : (int) $r['settled_by'],
            'note' => json_decode((string) $r['detail'], true)['note'] ?? null];
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

    /** The open record's title, its main action and its main button (words, value). @return array{title: string, action: string, main: string, value: string} */
    private static function open(UiResponse $page): array
    {
        $xp = new \DOMXPath($page->dom());
        return ['title' => trim((string) $xp->evaluate('string(//h2[@id="ap-h"])')), 'action' => (string) $xp->evaluate('string(//form[@id="ap-answer"]/@action)'),
            'main' => trim((string) $xp->evaluate('string(//button[@form="ap-answer" and contains(@class, "primary")])')),
            'value' => (string) $xp->evaluate('string(//button[@form="ap-answer" and contains(@class, "primary")]/@value)')];
    }

    public function testThePageListsWhatWaitsAndTheBadgeCountsItForWhoAnswers(): void
    {
        $f = $this->fill();
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get(self::PATH);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $text = $page->text();
        self::assertStringContainsString(Words::NEW_PRODUCTS['heading'], $text);
        foreach (['barcode_on_item', 'close_name', 'no_such_item'] as $why) {
            self::assertStringContainsString(Words::NEW_PRODUCT_WHY[$why], $text, $why);
        }
        self::assertStringContainsString(Words::say('NEW_PRODUCTS', 'chosen', 'Aswin', Words::NEW_PRODUCTS['choice_new']), $text);
        self::assertStringContainsString(Words::say('NEW_PRODUCTS', 'chosen', 'Aswin', Words::say('NEW_PRODUCTS', 'choice_pack', 5, 'CW-999999')), $text);
        self::assertStringContainsString(Words::say('NEW_PRODUCTS', 'same_barcode', '5059000999999'), $text, 'the barcode they share');
        $xp = new \DOMXPath($page->dom());
        $card = '//article[@id="new-products-' . $f['ids']['1001'] . '"]';
        self::assertSame($f['code'], trim((string) $xp->evaluate('string(' . $card . '//a[@href="/ui/items/' . $f['item'] . '"])')), 'the item found, with its link');
        self::assertSame('25', trim((string) $xp->evaluate('string(' . $card . '//dt[.="' . Words::NEW_PRODUCTS['on_hand'] . '"]/following-sibling::dd[1])')));
        // The item found is the main answer; without one, a new item is.
        $main = static fn (int $id): array => [trim((string) $xp->evaluate('string(//form[@action="' . self::PATH . '/' . $id . '/answer"]//button[contains(@class, "primary")])')),
            (string) $xp->evaluate('string(//form[@action="' . self::PATH . '/' . $id . '/answer"]//button[contains(@class, "primary")]/@value)')];
        self::assertSame([Words::say('NEW_PRODUCTS', 'same', $f['code']), 'same'], $main($f['ids']['1001']));
        self::assertSame([Words::NEW_PRODUCTS['make_new'], 'new'], $main($f['ids']['1003']));
        self::assertSame(['same', 'new', 'none'], array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'),
            iterator_to_array($xp->query('//form[@action="' . self::PATH . '/' . $f['ids']['1001'] . '/answer"]//button'))));
        self::assertSame('/ui/review/listing/' . $f['lids']['1003'], (string) $xp->evaluate('string(//form[@action="' . self::PATH . '/' . $f['ids']['1003'] . '/answer"]//a/@href)'),
            'a pack is said on the variant\'s own page');

        // The badge: the inbox counts them for who answers them, and the page's Show choice too.
        self::assertSame(['href' => InboxController::PATH, 'count' => 3], self::nav($page)['Approvals']);
        self::assertSame([['Waiting for you', 3], ['New products', 3]], array_map(static fn (array $s): array => [$s['label'], $s['count']], self::segments($page)));

        // Who looks at the queues sees it, with no answer and no count; anyone else is refused.
        $buyer = $this->signIn($this->uiUser('buyer'));
        $look = $buyer->get(self::PATH);
        self::assertSame(200, $look->status, $look->describe());
        self::assertStringContainsString('You can look', $look->text());
        self::assertFalse($look->hasForm(self::PATH . '/' . $f['ids']['1001'] . '/answer'));
        self::assertSame(0, array_column(self::segments($look), 'count', 'label')['New products']);
        foreach (['admin', 'viewer'] as $role) {
            $r = $this->signIn($this->uiUser($role))->get(self::PATH);
            self::assertSame(403, $r->status, $role);
            self::assertStringContainsString(Words::NEW_PRODUCTS['not_yours'], $r->text(), $role);
        }
    }

    public function testEachAnswerFromTheInboxAndThePageIsThePersonsOwnDecision(): void
    {
        $f = $this->fill();
        $user = $this->uiUser('mapper');
        $web = $this->signIn($user);
        $inbox = $web->get(InboxController::PATH);
        self::assertSame(200, $inbox->status, $inbox->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(['New products' => 3], self::kindsShown($inbox), 'a Matcher answers the new products only');

        // The same item, from the inbox: the record says why and what each answer does; the main answer is the item found.
        $route = self::PATH . '/' . $f['ids']['1001'] . '/answer';
        $page = $web->get(InboxController::PATH, ['open' => 'new_products-' . $f['ids']['1001']]);
        self::assertSame(['title' => 'Banana Ice / 10mg', 'action' => $route, 'main' => Words::say('NEW_PRODUCTS', 'same', $f['code']), 'value' => 'same'], self::open($page));
        self::assertStringContainsString(Words::NEW_PRODUCT_WHY['barcode_on_item'], $page->text());
        self::assertStringContainsString(Words::say('NEW_PRODUCTS', 'line_same', $f['code']), $page->text());
        $xp = new \DOMXPath($page->dom());
        self::assertSame(['new', 'none'], array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'),
            iterator_to_array($xp->query('//details[contains(@class, "more")]//button[@form="ap-answer" and @formaction="' . $route . '"]'))), 'the other answers under More');
        self::assertSame('/ui/review/listing/' . $f['lids']['1001'], (string) $xp->evaluate('string(//details[contains(@class, "more")]//a[.="' . Words::NEW_PRODUCTS['pack'] . '"]/@href)'));
        $form = $page->form($route);
        self::assertSame('1', $form['inbox'] ?? null);
        $r = $web->post($route, ['answer' => 'same'] + $form);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringStartsWith(InboxController::PATH . '?', (string) $r->location(), 'back to the inbox');
        $next = $web->follow($r);
        self::assertStringContainsString(Words::say('QUEUE_NOTICE', 'joined', $f['code']), $next->text());
        self::assertSame(self::PATH . '/' . $f['ids']['1002'] . '/answer', self::open($next)['action'], 'the next record opens');
        self::assertSame([$f['item'], 'mapped', 1], [$this->link($f['lids']['1001'])['sku_id'], $this->link($f['lids']['1001'])['status'], $this->link($f['lids']['1001'])['units_per_item']]);
        self::assertSame(['done', 'joined', $user['id']], array_slice(array_values(self::row($f['ids']['1001'])), 0, 3));
        self::assertSame($user['id'], (int) self::$db->value("SELECT decided_by FROM match_decision WHERE listing_id = ? AND action = 'link'", [$f['lids']['1001']]));

        // A new item, on the page: the notice names the item made.
        $page = $web->get(self::PATH);
        $route = self::PATH . '/' . $f['ids']['1002'] . '/answer';
        $r = $web->post($route, ['answer' => 'new'] + $page->form($route));
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $made = $this->link($f['lids']['1002']);
        self::assertSame('mapped', $made['status']);
        self::assertNotContains($made['sku_id'], [$f['item'], $f['close']], 'a new item');
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$made['sku_id']]);
        self::assertSame(self::PATH . '?notice=made_new&item=' . $code, $r->location());
        self::assertStringContainsString(Words::say('QUEUE_NOTICE', 'made_new', $code), $web->follow($r)->text());
        self::assertSame(['done', 'made_by_person'], array_slice(array_values(self::row($f['ids']['1002'])), 0, 2));

        // Not stock, from the inbox under More, with a note.
        $route = self::PATH . '/' . $f['ids']['1003'] . '/answer';
        $page = $web->get(InboxController::PATH, ['open' => 'new_products-' . $f['ids']['1003']]);
        self::assertSame([Words::NEW_PRODUCTS['make_new'], 'new'], [self::open($page)['main'], self::open($page)['value']], 'nothing found: a new item is the main answer');
        $r = $web->post($route, ['answer' => 'none', 'note' => 'A page only'] + $page->form($route));
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $after = $web->follow($r);
        self::assertStringContainsString(Words::QUEUE_NOTICE['not_stock'], $after->text());
        self::assertStringContainsString(Words::INBOX['none'], $after->text(), 'nothing is left');
        self::assertSame('landing', $this->link($f['lids']['1003'])['status']);
        self::assertSame(['done', 'not_stock_by_person', $user['id'], 'A page only'], array_values(self::row($f['ids']['1003'])));

        // Done: how each ended and who answered; Answered today lists them.
        $done = $web->get(self::PATH, ['status' => 'resolved']);
        self::assertSame(200, $done->status, $done->describe());
        foreach (['joined', 'made_by_person', 'not_stock_by_person'] as $outcome) {
            self::assertStringContainsString(Words::NEW_PRODUCT_DONE[$outcome] . ' on ', $done->text(), $outcome);
        }
        self::assertStringContainsString(Words::say('NEW_PRODUCTS', 'note_line', 'A page only'), $done->text());
        self::assertFalse($done->hasForm(self::PATH . '/' . $f['ids']['1003'] . '/answer'), 'a done one has no answer');
        self::assertSame(['New products' => 3], self::kindsShown($web->get(InboxController::PATH, ['show' => 'answered'])));
        self::assertSame(0, self::nav($done)['Approvals']['count']);
    }

    public function testAVariantThatChangedIsRefusedInTheKindsWordsAndNothingIsSaved(): void
    {
        $f = $this->fill();
        $web = $this->signIn($this->uiUser('mapping_lead'));
        $route = self::PATH . '/' . $f['ids']['1001'] . '/answer';
        $page = $web->get(self::PATH);
        $inbox = $web->get(InboxController::PATH, ['open' => 'new_products-' . $f['ids']['1001']]);
        self::$db->exec('UPDATE channel_listing SET map_version = map_version + 1 WHERE id = ?', [$f['lids']['1001']]);

        // On the page: 409, the kind's words, the row as it was.
        $r = $web->post($route, ['answer' => 'same'] + $page->form($route));
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString(Words::QUEUE_ERROR['map_version_conflict'], $r->text());
        self::assertSame(['to_check', 'barcode_on_item', null], array_slice(array_values(self::row($f['ids']['1001'])), 0, 3));
        self::assertSame('unmapped', $this->link($f['lids']['1001'])['status']);

        // From the inbox: back on the same record, in the same words, the note as typed.
        $r = $web->post($route, ['answer' => 'same', 'note' => 'looked at the box'] + $inbox->form($route));
        self::assertSame(409, $r->status, $r->describe());
        self::assertSame('Banana Ice / 10mg', self::open($r)['title']);
        self::assertStringContainsString(Words::QUEUE_ERROR['map_version_conflict'], (string) (new \DOMXPath($r->dom()))->evaluate('string(//section[contains(@class, "ap-record")]//p[@role="alert"])'));
        self::assertSame('looked at the box', $r->form($route)['note'] ?? null);
        self::assertSame('to_check', self::row($f['ids']['1001'])['state']);

        // A form that is not the page's (no answer, no version) is refused whole.
        $bad = $web->post($route, ['answer' => 'maybe'] + $page->form($route));
        self::assertSame(400, $bad->status);
        self::assertStringContainsString(Words::QUEUE_ERROR['bad_answer'], $bad->text());
        // Drawn again from now, the answer goes through.
        $again = $web->get(self::PATH);
        self::assertSame(303, $web->post($route, ['answer' => 'same'] + $again->form($route))->status);
        self::assertSame('joined', self::row($f['ids']['1001'])['outcome']);
    }

    public function testAConflictIsAMatchingLeadsAndAVariantSetAsideIsDecidedOnItsOwnPage(): void
    {
        $f = $this->fill();
        // Two items look like it (a Conflict suggestion): no "same" answer; a new item or not stock, by a matching lead.
        $lid = $this->profiled($f['shop'], '1004', ['product_title' => 'Elux Legend Nic Salt E-Liquid 10ml', 'variant_title' => 'Cola Ice / 10mg']);
        $this->propose($lid, 'Conflict', $f['close']);
        $svc = new StoreChoices(self::$db, $this->ds);
        $svc->receive($f['shop'], [['variant_id' => '1004', 'choice' => 'new', 'pack_of' => null, 'units' => null, 'staff' => ['id' => '17', 'name' => 'Aswin'],
            'chosen_at' => '2026-11-03T10:42:00Z']]);
        $svc->run(true);
        $id = (int) self::$db->value("SELECT id FROM store_choice WHERE external_variant_id = '1004'");
        self::assertSame(['to_check', 'conflict'], array_slice(array_values(self::row($id)), 0, 2));
        $route = self::PATH . '/' . $id . '/answer';
        $mapper = $this->signIn($this->uiUser('mapper'));
        $page = $mapper->get(self::PATH);
        self::assertStringContainsString(Words::NEW_PRODUCT_WHY['conflict'], $page->text());
        $xp = new \DOMXPath($page->dom());
        self::assertSame(['new', 'none'], array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'), iterator_to_array($xp->query('//form[@action="' . $route . '"]//button'))),
            'no "same" answer');
        $r = $mapper->post($route, ['answer' => 'new'] + $page->form($route));
        self::assertSame(403, $r->status, $r->describe());
        self::assertStringContainsString(Words::MATCH_ERROR['lead_required'], $r->text(), 'the matching pages\' words');
        $lead = $this->signIn($this->uiUser('mapping_lead'));
        self::assertSame(303, $lead->post($route, ['answer' => 'new'] + $lead->get(self::PATH)->form($route))->status);
        self::assertSame('made_by_person', self::row($id)['outcome']);

        // Set aside (as the job writes a variant held back or in a spot check): no answer here, on the page or in the inbox; its own page.
        self::$db->exec("UPDATE store_choice SET outcome = 'held', detail = JSON_OBJECT('seen', 'held') WHERE id = ?", [$f['ids']['1002']]);
        $route = self::PATH . '/' . $f['ids']['1002'] . '/answer';
        $page = $mapper->get(self::PATH);
        self::assertFalse($page->hasForm($route));
        $card = '//article[@id="new-products-' . $f['ids']['1002'] . '"]';
        $xp = new \DOMXPath($page->dom());
        self::assertStringContainsString(Words::NEW_PRODUCTS['held_line'], (string) $xp->evaluate('string(' . $card . ')'));
        self::assertSame('/ui/review/listing/' . $f['lids']['1002'], (string) $xp->evaluate('string(' . $card . '//a[.="' . Words::NEW_PRODUCTS['open_page'] . '"]/@href)'));
        $inbox = $mapper->get(InboxController::PATH, ['open' => 'new_products-' . $f['ids']['1002']]);
        self::assertSame(200, $inbox->status, $inbox->describe());
        $xp = new \DOMXPath($inbox->dom());
        self::assertSame(0, $xp->query('//form[@id="ap-answer"]')->length, 'nothing to answer here');
        self::assertSame('/ui/review/listing/' . $f['lids']['1002'], (string) $xp->evaluate('string(//section[contains(@class, "ap-record")]//a[contains(@class, "primary")]/@href)'),
            'the main action is its own page');
        self::assertStringContainsString(Words::NEW_PRODUCTS['held_line'], $inbox->text());
        // A refusal of an answer to it (StoreChoices::answer(), 409 one_at_a_time) says the same.
        self::assertSame(Words::QUEUE_ERROR['one_at_a_time'], \CW\Ui\Controller\StoreQueuesController::plain(new \CW\CwException('one_at_a_time', 'set aside', 409)));
    }

    public function testWhoDoesNotAnswerSeesNoAnswerAndTheRouteRefusesThem(): void
    {
        $f = $this->fill();
        $route = self::PATH . '/' . $f['ids']['1001'] . '/answer';
        $sc = $this->signIn($this->uiUser('stock_controller'));
        self::assertArrayNotHasKey('New products', self::kindsShown($sc->get(InboxController::PATH)), 'a stock controller does not answer them');
        $look = $sc->get(self::PATH);
        self::assertSame(200, $look->status);
        self::assertFalse($look->hasForm($route));
        $fields = ['answer' => 'same', 'version' => (string) $this->version($f['lids']['1001']), 'proposal' => ''];
        self::assertSame(403, $sc->post($route, ['csrf' => $this->token($sc)] + $fields)->status, 'the route needs mapping.decide');
        $mapper = $this->signIn($this->uiUser('mapper'));
        self::assertSame(403, $mapper->post($route, $fields)->status, 'no token');
        self::assertSame(403, $mapper->post($route, ['csrf' => 'forged'] + $fields)->status, 'a forged token');
        self::assertSame(3, (new StoreChoices(self::$db, $this->ds))->openCount(), 'nothing was answered');
    }
}
