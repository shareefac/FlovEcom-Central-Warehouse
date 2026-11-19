<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Live\Presence;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\LineUids;
use CW\Tests\Support\UiResponse;
use CW\Ui\DocGrid;
use CW\Ui\Words;

/**
 * Who is here on a draft order, on the screen (the live drafts plan, step 3b; docs/decisions.md LV18-LV25; the owner, 19 Nov 2026: "the
 * on-screen 'Fazil is here too' with coloured names on lines. Do this."), as cw_app through the real kernel:
 *  - with live.mode off (the default) nothing of it is drawn: no poll, no chips;
 *  - with it on, a draft's editor form (the paper, and the old form of po.draft_look = form) carries the poll's address, its own tab, the
 *    version it shows, the rhythm and every word app.js shows (data-live-*, Words::LIVE: dev.md rule 17); the chips' place sits under the
 *    title; each saved line names its own id (data-uid = Documents::lineUids) and each box its place (data-live-cell, data-live-box);
 *  - a person who may only look polls too (the paper carries the same), so the others see them looking; a confirmed order and a new one
 *    show nobody;
 *  - a line keeps its id through the page's whole save (a figure changed, another line removed, a line added, its item changed in place),
 *    so a mark on it stays;
 *  - the poll of a page that shows an older version says who saved the draft since and when; "Show it" draws the draft as it is now with
 *    the new lines typed kept, and saves nothing.
 */
final class PoPresenceScreensTest extends KernelUiTestCase
{
    use LineUids;

    /** @var array<string, string> setting key => its JSON as it was */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([...array_keys(Presence::SETTINGS), 'po.draft_look'] as $key) {
            $this->saved[$key] = (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        }
        self::mode('presence');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        parent::tearDown();
    }

    private static function mode(string $mode): void
    {
        self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'live.mode'", [json_encode($mode)]);
    }

    /** @return array<string, mixed> an active supplier (made by a buyer, approved by a reviewer) */
    private function supplier(string $name): array
    {
        $buyer = $this->uiUser('buyer')['id'];
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyer), ['name' => $name, 'address_line1' => '4 Bridge Street', 'city' => 'Birmingham', 'postcode' => 'B1 1AA',
            'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyer, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation(Caller::staff($buyer), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
    }

    private function pos(): PurchaseOrders
    {
        return new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
    }

    private static function docVersion(int $id): int
    {
        return (new Documents(self::$db, DocumentHandlers::all(self::$db)))->get($id)->version;
    }

    /** @return array<int, ?int> */
    private static function lineUids(int $id): array
    {
        return (new Documents(self::$db, DocumentHandlers::all(self::$db)))->lineUids($id);
    }

    private static function nodes(UiResponse $r, string $xpath): int
    {
        return (new \DOMXPath($r->dom()))->query($xpath)->length;
    }

    /** @return list<string> */
    private static function attrs(UiResponse $r, string $xpath, string $attr): array
    {
        return array_map(static fn (\DOMElement $n): string => $n->getAttribute($attr), iterator_to_array((new \DOMXPath($r->dom()))->query($xpath)));
    }

    private static function why(UiResponse $r): string
    {
        return $r->describe() . "\n" . implode("\n", array_slice(self::$log, -3));
    }

    /**
     * A draft of three lines (one of an item not set up with the supplier, so it has an items-per-pack box), started by $starter.
     *
     * @return array{id: int, supplier: array<string, mixed>, si: list<int>, skus: list<int>}
     */
    private function draft(int $starter, string $name): array
    {
        $s = $this->supplier($name);
        $skus = [];
        $sis = [];
        $lines = [];
        foreach ([['Live Mint 10ml', 1, 'LV-MNT', '0.92', 50], ['Live Pods Berry', 10, 'LV-PB10', '29.10', 3]] as [$item, $upp, $code, $price, $packs]) {
            $sku = self::makeSku($name . ' ' . $item);
            $si = (new SupplierItems(self::$db))->create(Caller::staff($starter), (int) $s['id'], $sku, ['units_per_pack' => (string) $upp, 'supplier_code' => $code],
                ['pack_price' => $price]);
            $skus[] = $sku;
            $sis[] = (int) $si['id'];
            $lines[] = ['supplier_item_id' => (int) $si['id'], 'packs' => $packs];
        }
        $skus[] = $loose = self::makeSku($name . ' Live Coil Loose');
        $lines[] = ['sku_id' => $loose, 'units_per_pack' => 5, 'packs' => 2, 'pack_price' => '4.00'];
        $d = $this->pos()->createDraft(Caller::staff($starter), (int) $s['id'], []);
        $this->pos()->saveDraft(Caller::staff($starter), $d->id, $d->version, [], $lines);
        return ['id' => $d->id, 'supplier' => $s, 'si' => $sis, 'skus' => $skus];
    }

    /** Every data-live-* the page draws on $root for the script: the poll, the tab, the version, the rhythm and every word of Words::LIVE. */
    private static function assertLiveRoot(UiResponse $page, string $root, int $id, int $version): void
    {
        $xp = new \DOMXPath($page->dom());
        $el = $xp->query($root)->item(0);
        self::assertInstanceOf(\DOMElement::class, $el, 'the element the script starts on: ' . $root);
        self::assertSame("/ui/purchasing/orders/{$id}/live", $el->getAttribute('data-live'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $el->getAttribute('data-live-tab'), 'the page\'s own tab (Presence::newTab)');
        self::assertSame((string) $version, $el->getAttribute('data-live-v'), 'the version the page shows');
        self::assertSame("/ui/purchasing/orders/{$id}", $el->getAttribute('data-live-page'));
        self::assertSame('/ui/login?back=' . rawurlencode("/ui/purchasing/orders/{$id}"), $el->getAttribute('data-live-login'));
        $s = Presence::settings(self::$db);
        self::assertSame([(string) ($s['alone'] * 1000), (string) (Presence::IDLE_AWAY_SECONDS * 1000), (string) (Presence::AWAY_SECONDS * 1000),
            (string) (Presence::BACKOFF_SECONDS * 1000), (string) Presence::SLOW_MS, (string) (Presence::ACTIVE_SECONDS * 1000), (string) Presence::JITTER_MS,
            (string) (Presence::SAY_SECONDS * 1000)],
            array_map(static fn (string $a): string => $el->getAttribute($a), ['data-live-ms', 'data-live-away-ms', 'data-live-stop-ms', 'data-live-max-ms',
                'data-live-slow-ms', 'data-live-active-ms', 'data-live-jitter-ms', 'data-live-say-ms']), 'the rhythm from the server: the script holds no number of its own');
        foreach (Words::LIVE as $code => $text) {
            self::assertSame($text, $el->getAttribute('data-live-' . str_replace('_', '-', $code)), "the word {$code} from the page");
        }
    }

    public function testWithLiveModeOffNothingOfWhoIsHereIsDrawn(): void
    {
        self::mode('off');
        $fazil = $this->uiUser('buyer');
        $d = $this->draft($fazil['id'], 'Live Off Ltd');
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get("/ui/purchasing/orders/{$d['id']}");
        self::assertSame(200, $page->status, self::why($page));
        self::assertSame(1, self::nodes($page, '//form[@id="po-form"][contains(@class, "kg-paper")]'));
        self::assertSame(0, self::nodes($page, '//*[@data-live] | //*[@data-live-here] | //*[@data-live-tab]'), 'no poll, no chips');
        $viewer = $this->signIn($this->uiUser('purchasing_desk'))->get("/ui/purchasing/orders/{$d['id']}");
        self::assertSame(0, self::nodes($viewer, '//*[@data-live] | //*[@data-live-here] | //*[@data-uid] | //*[@data-live-cell] | //*[@data-live-box]'),
            'the read-only paper as before');
    }

    public function testTheDraftCarriesThePollItsWordsAndEachLinesAndBoxsPlace(): void
    {
        $fazil = $this->uiUser('buyer');
        $d = $this->draft($fazil['id'], 'Live Marks Ltd');
        $id = $d['id'];
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get("/ui/purchasing/orders/{$id}");
        self::assertSame(200, $page->status, self::why($page));
        $form = '//form[@id="po-form"][contains(@class, "kg-paper")]';
        self::assertLiveRoot($page, $form, $id, self::docVersion($id));
        self::assertSame(1, self::nodes($page, '//*[@data-live]'), 'one element the script starts on');
        $tab = self::attrs($page, $form, 'data-live-tab')[0];
        self::assertNotSame($tab, self::attrs($web->get("/ui/purchasing/orders/{$id}"), $form, 'data-live-tab')[0], 'a new tab for every page drawn');

        // The chips' place under the title (before the form, after the head), empty and hidden until the script fills it; one polite region.
        $here = '//div[contains(@class, "doc-head")]/following-sibling::div[@data-live-here][following-sibling::form[@id="po-form"]]';
        self::assertSame(1, self::nodes($page, $here));
        self::assertSame(1, self::nodes($page, $here . '/p[@data-live-who][@hidden][@aria-label="' . Words::LIVE['label'] . '"]'));
        self::assertSame(1, self::nodes($page, $here . '/p[@data-live-note][@hidden][@role="status"]'));
        self::assertSame(1, self::nodes($page, $here . '/p[@data-live-say][@aria-live="polite"]'));
        self::assertSame(1, self::nodes($page, '//*[@aria-live="polite"][@data-live-say]'), 'one region says who came and went');
        self::assertSame('', trim((string) (new \DOMXPath($page->dom()))->query($here)->item(0)?->textContent), 'nothing said before the first answer');

        // Each saved line names its own id, in line order; its boxes their places; a new line (the template) has none.
        $uids = array_values(self::lineUids($id));
        self::assertSame([1, 2, 3], $uids);
        self::assertSame(array_map('strval', $uids), self::attrs($page, $form . '//table[@data-kg-grid]/tbody/tr[@data-kg-row][@data-kg-line]', 'data-uid'));
        foreach ([1, 2, 3] as $n) {
            $row = $form . '//table[@data-kg-grid]/tbody/tr[@data-kg-line="' . $n . '"]';
            self::assertSame(1, self::nodes($page, $row . '/th[@data-live-cell="item"]'), "line {$n}: the item");
            self::assertSame(1, self::nodes($page, $row . '/td[@data-live-cell="qty"]//input[@data-kg-cell="qty"]'), "line {$n}: the packs");
            self::assertSame(1, self::nodes($page, $row . '/td[@data-live-cell="price"]//input[@data-kg-cell="price"]'), "line {$n}: the price");
        }
        self::assertSame(1, self::nodes($page, $form . '//tr[@data-kg-line="3"]/th//label[@data-live-cell="upp"]//input[@data-kg-cell="upp"]'), 'items per pack');
        self::assertSame(0, self::nodes($page, $form . '//tr[@data-kg-new][@data-uid] | //template//*[@data-uid]'), 'a new line has no id yet');
        // The head's and the foot's boxes name their place (the plan's 3.2).
        foreach (['h:supplier' => 'select[@name="supplier_id"]', 'h:expected_date' => 'input[@name="expected_date"]', 'h:external_ref' => 'input[@name="external_ref"]',
            'h:note' => 'input[@name="note"]', 'h:delivery' => 'input[@name="charge_amount"]'] as $box => $field) {
            self::assertSame(1, self::nodes($page, $form . '//*[@data-live-box="' . $box . '"]'), $box);
            self::assertSame(1, self::nodes($page, $form . '//*[@data-live-box="' . $box . '"]//' . $field), "{$box} holds its box");
        }
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $page->body, 'no inline style (the CSP): the colours are classes');

        // The old one-page form (po.draft_look = form) carries the same.
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"form\"' AS JSON) WHERE setting_key = 'po.draft_look'");
        $old = $web->get("/ui/purchasing/orders/{$id}");
        self::assertSame(200, $old->status, self::why($old));
        $form = '//form[@id="po-form"][not(contains(@class, "kg-paper"))]';
        self::assertLiveRoot($old, $form, $id, self::docVersion($id));
        self::assertSame(1, self::nodes($old, '//div[@data-live-here]/p[@data-live-say][@aria-live="polite"]'));
        self::assertSame(array_map('strval', $uids), self::attrs($old, $form . '//table[@data-kg-grid]/tbody/tr[@data-kg-row][@data-kg-line]', 'data-uid'));
        self::assertSame(3, self::nodes($old, $form . '//tr[@data-kg-line]/td[@data-live-cell="qty"]//input[@data-kg-cell="qty"]'));
        foreach (['h:supplier', 'h:expected_date', 'h:external_ref', 'h:note', 'h:delivery'] as $box) {
            self::assertSame(1, self::nodes($old, $form . '//*[@data-live-box="' . $box . '"]'), "the old form: {$box}");
        }
    }

    public function testALookerPollsTooAndAConfirmedOrderOrANewOneShowsNobody(): void
    {
        $fazil = $this->uiUser('buyer');
        $d = $this->draft($fazil['id'], 'Live Look Ltd');
        $id = $d['id'];
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $page = $desk->get("/ui/purchasing/orders/{$id}");
        self::assertSame(200, $page->status, self::why($page));
        self::assertFalse($page->hasForm("/ui/purchasing/orders/{$id}/lines"), 'words, no editor');
        $paper = '//article[contains(@class, "po-paper")]';
        self::assertLiveRoot($page, $paper, $id, self::docVersion($id));
        $csrf = self::attrs($page, $paper . '/input[@type="hidden"][@name="csrf"]', 'value');
        self::assertCount(1, $csrf, 'the poll\'s token beside it');
        self::assertSame($page->form('/ui/logout')['csrf'], $csrf[0], 'the session\'s token, as every form of the page');
        self::assertSame(1, self::nodes($page, '//div[@data-live-here]'));
        self::assertSame(array_map('strval', array_values(self::lineUids($id))), self::attrs($page, $paper . '//table[contains(@class, "doc-lines")]/tbody/tr', 'data-uid'));
        self::assertSame(3, self::nodes($page, $paper . '//tbody/tr/td[@data-live-cell="qty"]'));
        foreach (['h:supplier', 'h:expected_date', 'h:external_ref', 'h:note'] as $box) {
            self::assertSame(1, self::nodes($page, $paper . '//*[@data-live-box="' . $box . '"]'), "read only: {$box}");
        }
        // Its poll answers as anyone's (purchasing.view), and says the person cannot change the draft (they show as looking).
        $j = json_decode($desk->post("/ui/purchasing/orders/{$id}/live", ['csrf' => $csrf[0], 'tab' => 'abcdef0123456789', 'v' => (string) self::docVersion($id),
            'focus' => '', 'state' => 'active', 'typing' => '0', 'active' => '0'])->body, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(['draft', []], [$j['status'], $j['here']]);
        self::assertSame(0, (int) self::$db->value("SELECT edits FROM draft_presence WHERE record_id = ? AND kind = 'po'", [$id]));

        // Confirmed: nobody is shown, nothing polls.
        $buyer = $this->signIn($this->uiUser('buyer'));
        $f = $buyer->get("/ui/purchasing/orders/{$id}")->form('/lines');
        $r = $buyer->post("/ui/purchasing/orders/{$id}/lines", ['action' => 'approve'] + $f);
        self::assertSame(303, $r->status, self::why($r));
        self::assertNotSame('draft', (string) self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));
        foreach ([$buyer, $desk] as $web) {
            $done = $web->get("/ui/purchasing/orders/{$id}");
            self::assertSame(200, $done->status, self::why($done));
            self::assertSame(0, self::nodes($done, '//*[@data-live] | //*[@data-live-here] | //*[@data-uid] | //*[@data-live-box]'), 'a confirmed order shows nobody');
        }
        // A new order has no id yet: nobody either.
        $new = $buyer->get('/ui/purchasing/orders/new', ['supplier' => $d['supplier']['id']]);
        self::assertSame(200, $new->status, self::why($new));
        self::assertSame(0, self::nodes($new, '//*[@data-live] | //*[@data-live-here]'));
    }

    public function testALineKeepsItsIdThroughThePagesWholeSave(): void
    {
        $fazil = $this->uiUser('buyer');
        $d = $this->draft($fazil['id'], 'Live Ids Ltd');
        $id = $d['id'];
        $path = "/ui/purchasing/orders/{$id}";
        $web = $this->signIn($this->uiUser('buyer'));
        self::assertSame([1, 2, 3], self::uidsOf($id));

        // A figure changed (the form without the script): every line keeps its id.
        $r = $web->post("{$path}/lines", ['line_2_packs' => '5', 'action' => 'save'] + $web->get($path)->form('/lines'));
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame([1, 2, 3], self::uidsOf($id));
        self::assertSame(5, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 2', [$id]));

        // A new line typed in the grid (the script's one field): the next id; the others keep theirs.
        $r = $web->post("{$path}/lines", ['action' => 'save', DocGrid::FIELD => json_encode([['sku' => self::makeSku('Live Ids extra'), 'q' => '2', 'p' => '1.00']])]
            + $web->get($path)->form('/lines'));
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame([1, 2, 3, 4], self::uidsOf($id));

        // Another line removed (its x): the others keep theirs and move up.
        $r = $web->post("{$path}/lines", ['remove_line' => '1'] + $web->get($path)->form('/lines'));
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame([2, 3, 4], self::uidsOf($id));
        $page = $web->get($path);
        self::assertSame(['2', '3', '4'], self::attrs($page, '//table[@data-kg-grid]/tbody/tr[@data-kg-line]', 'data-uid'), 'the marks follow the lines');

        // A line's item changed in place (KG22: the saved line removed, a new line in its place): the line keeps its id.
        $f = $page->form('/lines');
        $r = $web->post("{$path}/lines", ['action' => 'save', 'line_1_remove' => '1', DocGrid::FIELD => json_encode([['si' => $d['si'][0], 'line' => 1, 'q' => '7', 'p' => '']])] + $f);
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame([2, 3, 4], self::uidsOf($id));
        self::assertSame([(int) $d['si'][0], 7], array_map('intval', array_values((array) self::$db->one('SELECT supplier_item_id, packs FROM po_line WHERE document_id = ? AND line_no = 1', [$id]))));

        // Add by its code (the scan box): the same pack adds to its line, which keeps its id.
        $r = $web->post("{$path}/lines", ['q' => 'LV-MNT', 'packs' => '1', 'action' => 'add'] + $web->get($path)->form('/lines'));
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame([2, 3, 4], self::uidsOf($id));
        self::assertSame(8, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 1', [$id]));
    }

    public function testThePollSaysWhoSavedSinceAndShowItKeepsTheNewLinesTyped(): void
    {
        $fazilUser = $this->uiUser('buyer');
        $d = $this->draft($fazilUser['id'], 'Live Show Ltd');
        $id = $d['id'];
        $path = "/ui/purchasing/orders/{$id}";
        $shareef = $this->signIn($this->uiUser('buyer'));
        $fazil = $this->signIn($fazilUser);
        $page = $shareef->get($path);
        $stale = $page->form('/lines');
        $seen = (int) $stale['version'];
        $poll = fn (int $v): array => json_decode($shareef->post("{$path}/live", ['csrf' => $stale['csrf'], 'tab' => '0123456789abcdef', 'v' => (string) $v,
            'focus' => '', 'state' => 'active', 'typing' => '0', 'active' => '0'])->body, true, 8, JSON_THROW_ON_ERROR);
        $j = $poll($seen);
        self::assertSame($seen, $j['v']);
        self::assertArrayNotHasKey('changed', $j, 'the page shows the draft as it is: nothing to say');

        // Fazil saves: the stale page's poll says who and when (UK time), the page's own version told apart.
        $r = $fazil->post("{$path}/lines", ['line_1_packs' => '60', 'action' => 'save'] + $fazil->get($path)->form('/lines'));
        self::assertSame(303, $r->status, self::why($r));
        $j = $poll($seen);
        self::assertGreaterThan($seen, $j['v']);
        self::assertSame(['by', 'at', 'you'], array_keys($j['changed']));
        self::assertSame([(string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$fazilUser['id']]), false], [$j['changed']['by'], $j['changed']['you']]);
        self::assertMatchesRegularExpression('/^(\d{1,2} [A-Z][a-z]{2}, )?\d{2}:\d{2}$/D', $j['changed']['at'], 'the clock, as the 409 page says it');
        self::assertArrayNotHasKey('changed', $poll($j['v']));

        // "Show it": the draft as it is now (its version, Fazil's figure), the new line typed kept, nothing saved, no refusal.
        $version = self::docVersion($id);
        $typed = json_encode([['sku' => $d['skus'][0], 'q' => '4', 'p' => '1.00']], JSON_THROW_ON_ERROR);
        $r = $shareef->post("{$path}/lines", ['action' => 'show', 'line_2_packs' => '9', DocGrid::FIELD => $typed] + $stale);
        self::assertSame(200, $r->status, self::why($r));
        self::assertSame(0, self::nodes($r, '//p[contains(@class, "error")][@role="alert"]'), 'no refusal');
        self::assertStringContainsString(Words::LIVE['shown'], $r->text());
        self::assertSame((string) $version, $r->form('/lines')['version'], 'drawn with the version now');
        self::assertSame('60', $r->form('/lines')['line_1_packs'], 'Fazil\'s change shown');
        self::assertSame('3', $r->form('/lines')['line_2_packs'], 'a figure typed on a saved line is not kept (it may have changed under it)');
        self::assertSame($typed, $r->form('/lines')[DocGrid::FIELD]);
        self::assertSame(1, self::nodes($r, '//table[@data-kg-grid]/tbody/tr[@data-kg-new][@data-sku="' . $d['skus'][0] . '"]//input[@data-kg-cell="qty"][@value="4"]'));
        self::assertSame($version, self::docVersion($id), 'nothing saved');
        self::assertSame(3, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 2', [$id]));
        $live = '//form[@id="po-form"]';
        self::assertSame((string) $version, self::attrs($r, $live, 'data-live-v')[0], 'the page now shows the newest version');
        // A field the script sends that is not the grid's shape is left out, never a refusal.
        $r = $shareef->post("{$path}/lines", ['action' => 'show', DocGrid::FIELD => '{not json'] + $stale);
        self::assertSame(200, $r->status, self::why($r));
        self::assertSame('', $r->form('/lines')[DocGrid::FIELD]);

        // My own save in another page: "you".
        $mine = $shareef->get($path)->form('/lines');
        $r = $shareef->post("{$path}/lines", ['line_1_packs' => '61', 'action' => 'save'] + $mine);
        self::assertSame(303, $r->status, self::why($r));
        $j = $poll((int) $mine['version']);
        self::assertTrue($j['changed']['you']);
    }
}
