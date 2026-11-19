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
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\PurchaseOrdersController;
use CW\Ui\Words;

/**
 * Each box of a draft order saves by itself, on the screen (the live drafts plan, step 4, 4.3-4.5 and section 5; docs/decisions.md
 * LS1-LS14), as cw_app through the real kernel, with live.mode = on:
 *  - the paper's editor form carries what the script needs to save each box (the line and head addresses, the draft's own fragment, the
 *    newest change it shows, the retry rhythm, Confirm's address, every word: data-live-*), each saved line its own version and who changed it
 *    last; the bar's status place; with live.mode = presence, or the old form, none of it (the whole-form Save as before);
 *  - GET /live/doc: the fragment po_live (the head's values, every line with its id and version, the totals, the notes, the History card with
 *    the line-by-line changes grouped, the changes since the page's newest in words), for whoever may open the order, passive (it marks no
 *    session seen; signed out: 401);
 *  - POST /live/line and /live/header: doc.PO.post, the session's token and the same origin; the draft as it is now in the answer; a refusal
 *    in words on its line (line_conflict with both values and the two choices, line_gone, line_exists, field_conflict, a figure that is not
 *    one, a draft confirmed meanwhile);
 *  - Confirm with the version the page shows is refused when someone changed the draft since, saying who changed which line;
 *  - the page without the script still saves the whole form.
 */
final class PoLiveScreensTest extends KernelUiTestCase
{
    private const TAB = '0123456789abcdef';
    private const TAB2 = 'fedcba9876543210';
    /** @var array<string, string> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([...array_keys(Presence::SETTINGS), 'po.draft_look'] as $key) {
            $this->saved[$key] = (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        }
        self::mode('on');
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

    private function pos(): PurchaseOrders
    {
        return new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
    }

    /** @return array{id: int, si: list<int>, name: list<string>} a draft of Fazil's: pods 3 packs of 10 at £29.10, liquid 50 at £0.92, coil 2 packs of 5 at £4.00 */
    private function draft(int $starter, string $name): array
    {
        $buyer = Caller::staff($starter);
        $sup = new Suppliers(self::$db);
        $s = $sup->create($buyer, ['name' => $name, 'address_line1' => '4 Bridge Street', 'city' => 'Birmingham', 'postcode' => 'B1 1AA',
            'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $starter, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
        $si = [];
        $names = [];
        $lines = [];
        foreach ([['Live Pods Berry', 10, '29.10', 3], ['Live Mint 10ml', 1, '0.92', 50], ['Live Tank Glass', 1, '1.25', 0]] as [$item, $upp, $price, $packs]) {
            $names[] = $n = $name . ' ' . $item;
            $si[] = $x = (int) (new SupplierItems(self::$db))->create($buyer, (int) $s['id'], self::makeSku($n), ['units_per_pack' => (string) $upp, 'supplier_code' => strtoupper(substr($item, 5, 3))],
                ['pack_price' => $price])['id'];
            if ($packs > 0) {
                $lines[] = ['supplier_item_id' => $x, 'packs' => $packs];
            }
        }
        $lines[] = ['sku_id' => self::makeSku($name . ' Live Coil Loose'), 'units_per_pack' => 5, 'packs' => 2, 'pack_price' => '4.00'];
        $d = $this->pos()->createDraft($buyer, (int) $s['id'], []);
        $this->pos()->saveDraft($buyer, $d->id, $d->version, [], $lines);
        return ['id' => $d->id, 'si' => $si, 'name' => $names];
    }

    private static function docVersion(int $id): int
    {
        return (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$id]);
    }

    private static function x(UiResponse $r): \DOMXPath
    {
        return new \DOMXPath($r->dom());
    }

    private static function nodes(UiResponse $r, string $xpath): int
    {
        return self::x($r)->query($xpath)->length;
    }

    /** @return list<string> */
    private static function attrs(UiResponse $r, string $xpath, string $attr): array
    {
        return array_map(static fn (\DOMElement $n): string => $n->getAttribute($attr), iterator_to_array(self::x($r)->query($xpath)));
    }

    private static function text(UiResponse $r, string $xpath): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) self::x($r)->query($xpath)->item(0)?->textContent));
    }

    private static function why(UiResponse $r): string
    {
        return $r->describe() . "\n" . implode("\n", array_slice(self::$log, -3));
    }

    private static function sessionId(KernelBrowser $web): string
    {
        return hash('sha256', $web->cookies['cw_session'] ?? self::fail('the browser holds no session'));
    }

    private static function lastSeen(KernelBrowser $web): string
    {
        return (string) self::$db->value('SELECT last_seen_at FROM staff_session WHERE id = ?', [self::sessionId($web)]);
    }

    /** @param array<string, string> $fields */
    private function lineSave(KernelBrowser $web, int $id, array $fields, string $tab = self::TAB): UiResponse
    {
        return $web->post("/ui/purchasing/orders/{$id}/live/line", $fields + ['csrf' => $this->token($web), 'tab' => $tab]);
    }

    private static function rev(int $id, int $uid): int
    {
        return (int) self::$db->value('SELECT rev FROM document_line WHERE document_id = ? AND uid = ?', [$id, $uid]);
    }

    private static function fragment(UiResponse $r, int $id): \DOMElement
    {
        $root = self::x($r)->query('//div[@data-live-fragment="po:' . $id . '"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $root, 'the draft\'s own fragment: ' . substr($r->body, 0, 400));
        return $root;
    }

    public function testThePaperCarriesWhatEachBoxNeedsToSaveItselfAndPresenceOrTheOldFormDoNot(): void
    {
        $fazil = $this->uiUser('buyer');
        $d = $this->draft($fazil['id'], 'Live Save Marks');
        $id = $d['id'];
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get("/ui/purchasing/orders/{$id}");
        self::assertSame(200, $page->status, self::why($page));
        $form = '//form[@id="po-form"][contains(@class, "kg-paper")]';
        $el = self::x($page)->query($form)->item(0);
        self::assertInstanceOf(\DOMElement::class, $el);
        self::assertSame(["/ui/purchasing/orders/{$id}/live/line", "/ui/purchasing/orders/{$id}/live/header", "/ui/purchasing/orders/{$id}/live/doc",
            "/ui/purchasing/orders/{$id}/approve", '0', '1000 3000 10000'],
            array_map(static fn (string $a): string => $el->getAttribute($a), ['data-live-save', 'data-live-head', 'data-live-doc', 'data-live-confirm', 'data-live-e',
                'data-live-retry-ms']), 'the addresses, the newest change shown (none yet) and the retry rhythm: the script holds none of its own');
        foreach (Words::LIVE as $code => $words) {
            self::assertSame($words, $el->getAttribute('data-live-' . str_replace('_', '-', $code)), "the word {$code} from the page");
        }
        // Each saved line: its id, its own version, who changed it last (none yet: started by the whole save, Fazil's).
        self::assertSame(['1', '2', '3'], self::attrs($page, $form . '//table[@data-kg-grid]/tbody/tr[@data-kg-line]', 'data-uid'));
        self::assertSame(['1', '1', '1'], self::attrs($page, $form . '//table[@data-kg-grid]/tbody/tr[@data-kg-line]', 'data-rev'));
        self::assertSame([(string) $fazil['id']], array_values(array_unique(self::attrs($page, $form . '//table[@data-kg-grid]/tbody/tr[@data-kg-line]', 'data-by'))));
        // The head's boxes say what they are and the value they were drawn with.
        foreach (['expected_date' => 'po-expected', 'external_ref' => 'po-ref', 'note' => 'po-note'] as $field => $box) {
            self::assertSame([$field], self::attrs($page, '//input[@id="' . $box . '"]', 'data-live-field'), $field);
            self::assertSame([''], self::attrs($page, '//input[@id="' . $box . '"]', 'data-live-base'), $field);
        }
        // The bar: Save stays (without the script it is the save), with the status beside it the script shows instead.
        self::assertSame(1, self::nodes($page, '//div[contains(@class, "po-edit-bar")]//button[@name="action"][@value="save"]'));
        self::assertSame(1, self::nodes($page, '//div[contains(@class, "po-edit-bar")]//*[@data-live-status][@hidden][@role="status"]'));
        self::assertSame(1, self::nodes($page, '//div[@data-live-notes]'), 'the notes\' place, drawn again with each answer');
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $page->body);

        // presence: who is here only; nothing of the saving.
        self::mode('presence');
        $p = $web->get("/ui/purchasing/orders/{$id}");
        self::assertSame(1, self::nodes($p, '//*[@data-live]'));
        self::assertSame(0, self::nodes($p, '//*[@data-live-save] | //*[@data-live-status] | //tr[@data-rev]'), 'the whole-form Save as before (the undo)');
        // on, but the old form (po.draft_look = form): who is here, never the saving.
        self::mode('on');
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"form\"' AS JSON) WHERE setting_key = 'po.draft_look'");
        $old = $web->get("/ui/purchasing/orders/{$id}");
        self::assertSame(1, self::nodes($old, '//form[@id="po-form"][not(contains(@class, "kg-paper"))][@data-live]'));
        self::assertSame(0, self::nodes($old, '//*[@data-live-save]'));
        // A person who may only look: who is here, not the saving.
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"paper\"' AS JSON) WHERE setting_key = 'po.draft_look'");
        $look = $this->signIn($this->uiUser('purchasing_desk'))->get("/ui/purchasing/orders/{$id}");
        self::assertSame(1, self::nodes($look, '//article[contains(@class, "po-paper")][@data-live]'));
        self::assertSame(0, self::nodes($look, '//*[@data-live-save]'));
    }

    public function testTheDraftsFragmentHasEveryPartAndMarksNoSessionSeen(): void
    {
        $fazil = $this->uiUser('buyer');
        $d = $this->draft($fazil['id'], 'Live Doc Parts');
        $id = $d['id'];
        $web = $this->signIn($this->uiUser('buyer'));
        $before = self::lastSeen($web);
        self::$db->exec('UPDATE staff_session SET last_seen_at = NOW(6) - INTERVAL 60 SECOND WHERE id = ?', [self::sessionId($web)]);
        $idle = self::lastSeen($web);
        $r = $web->get("/ui/purchasing/orders/{$id}/live/doc", ['since' => '0', 'tab' => self::TAB]);
        self::assertSame(200, $r->status, self::why($r));
        self::assertSame($idle, self::lastSeen($web), 'passive: the page fetches it by itself, it marks no session seen');
        self::assertNotSame('', $before);
        $root = self::fragment($r, $id);
        self::assertSame([(string) self::docVersion($id), '0', 'draft'], [$root->getAttribute('data-v'), $root->getAttribute('data-e'), $root->getAttribute('data-status')]);
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $root->getAttribute('data-at'), 'the time (UK) for "All changes saved 14:02"');
        $f = '//div[@data-live-fragment="po:' . $id . '"]';
        foreach (['header', 'lines', 'foot', 'notes', 'history', 'events'] as $part) {
            self::assertSame(1, self::nodes($r, $f . '//*[@data-live-part="' . $part . '"]'), $part);
        }
        self::assertSame(0, self::nodes($r, $f . '//*[@data-live-part="conflict"]'), 'no refusal');
        // The lines: the editor's own rows, with their ids, versions and who changed them; the boxes named as on the page.
        self::assertSame(['1', '2', '3'], self::attrs($r, $f . '//*[@data-live-part="lines"]//tr[@data-kg-row]', 'data-uid'));
        self::assertSame(['1', '1', '1'], self::attrs($r, $f . '//*[@data-live-part="lines"]//tr[@data-kg-row]', 'data-rev'));
        self::assertSame(['line_1_packs', 'line_2_packs'], array_slice(self::attrs($r, $f . '//input[@data-kg-cell="qty"]', 'name'), 0, 2));
        self::assertSame(['3'], self::attrs($r, $f . '//*[@data-live-part="lines"]', 'data-count'));
        // The head's values, the totals, the History card (the order's one history).
        self::assertSame([''], self::attrs($r, $f . '//*[@data-live-part="header"]//*[@data-live-field="note"]', 'value'));
        self::assertSame(1, self::nodes($r, $f . '//*[@data-live-part="foot"]//*[@data-live-total="net"]'));
        self::assertSame('£' . number_format(3 * 29.10 + 50 * 0.92 + 2 * 4.00, 2), self::text($r, $f . '//*[@data-live-total="net"]'));
        self::assertSame(1, self::nodes($r, $f . '//*[@data-live-part="history"]//section[contains(@class, "po-history")]'));
        self::assertSame(0, self::nodes($r, $f . '//*[@data-live-part="events"]/li'), 'nothing changed since');

        // Signed out: 401, never the sign-in page (a passive route).
        $nobody = $this->browser();
        $out = $nobody->get("/ui/purchasing/orders/{$id}/live/doc");
        self::assertSame([401, null], [$out->status, $out->location()]);
        // Someone who may only look gets it too (the order's words, no boxes).
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $look = $desk->get("/ui/purchasing/orders/{$id}/live/doc");
        self::assertSame(200, $look->status, self::why($look));
        self::assertSame(0, self::nodes($look, '//input[@data-kg-cell]'), 'words, not boxes');
        // live.mode not on: refused, so the page goes back to the whole-form Save.
        self::mode('presence');
        $off = $web->get("/ui/purchasing/orders/{$id}/live/doc");
        self::assertSame(409, $off->status);
        self::assertSame(['live_off'], self::attrs($off, '//*[@data-live-part="conflict"]', 'data-code'));
        self::mode('on');
        // Not an order: 404.
        self::assertSame(404, $web->get('/ui/purchasing/orders/999999999/live/doc')->status);
    }

    public function testALineSavedByItselfAnswersTheDraftAsItIsNowAndRefusalsAreInWordsOnTheirLine(): void
    {
        $fazilUser = $this->uiUser('buyer');
        $d = $this->draft($fazilUser['id'], 'Live Line Words');
        $id = $d['id'];
        $fazil = $this->signIn($fazilUser);
        $shareef = $this->signIn($this->uiUser('buyer'));
        $fazilName = (string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$fazilUser['id']]);

        // Fazil: line 1's packs, from 3 to 8.
        $r = $this->lineSave($fazil, $id, ['op' => 'set', 'uid' => '1', 'rev' => '1', 'packs' => '8']);
        self::assertSame(200, $r->status, self::why($r));
        $root = self::fragment($r, $id);
        self::assertSame(['set', 'saved', '1', (string) self::docVersion($id), '2'], [$root->getAttribute('data-op'), $root->getAttribute('data-op-status'),
            $root->getAttribute('data-op-uid'), $root->getAttribute('data-v'), $root->getAttribute('data-op-rev')], 'the version this save itself wrote');
        $same = self::fragment($this->lineSave($fazil, $id, ['op' => 'set', 'uid' => '1', 'rev' => '1', 'packs' => '8']), $id);
        self::assertSame(['unchanged', ''], [$same->getAttribute('data-op-status'), $same->getAttribute('data-op-rev')],
            'nothing written: no version of its own (the page never moves its waiting saves onto someone else\'s: the Codex review of b40f96a)');
        self::assertSame(['2'], self::attrs($r, '//tr[@data-uid="1"]', 'data-rev'));
        self::assertSame(['8'], self::attrs($r, '//tr[@data-uid="1"]//input[@data-kg-cell="qty"]', 'value'));
        $e = (int) $root->getAttribute('data-e');
        self::assertGreaterThan(0, $e, 'the newest change');
        self::assertSame(0, self::nodes($r, '//*[@data-live-part="events"]/li'), 'his own page is not told of his own change');

        // Shareef, whose page still shows rev 1: the same line, another figure: refused for that line, in words, with both values and the choices.
        $c = $this->lineSave($shareef, $id, ['op' => 'set', 'uid' => '1', 'rev' => '1', 'packs' => '6'], self::TAB2);
        self::assertSame(409, $c->status, self::why($c));
        $box = '//*[@data-live-part="conflict"][@data-code="line_conflict"][@data-uid="1"][@data-rev="2"]';
        self::assertSame(1, self::nodes($c, $box . '[@role="alertdialog"]'));
        $words = self::text($c, $box);
        self::assertStringContainsString(Words::say('LIVE_SAVE', 'conflict_line', $fazilName, (string) self::x($c)->query($box)->item(0)?->getAttribute('data-at')), $words);
        self::assertStringContainsString(Words::say('LIVE_SAVE', 'theirs', $fazilName, Words::say('LIVE_SAVE', 'packs', '8', PurchaseOrdersController::pack('each', 10))), $words);
        self::assertStringContainsString(Words::say('LIVE_SAVE', 'yours', Words::say('LIVE_SAVE', 'packs', '6', PurchaseOrdersController::pack('each', 10))), $words);
        self::assertSame([Words::say('LIVE_SAVE', 'keep', $fazilName), Words::LIVE_SAVE['use_mine']],
            array_map('trim', array_map(static fn (\DOMElement $b): string => $b->textContent, iterator_to_array(self::x($c)->query($box . '//button[@data-live-choice]')))));
        self::assertSame(['theirs', 'mine'], self::attrs($c, $box . '//button', 'data-live-choice'));
        self::assertSame(['2'], self::attrs($c, '//*[@data-live-part="lines"]//tr[@data-uid="1"]', 'data-rev'), 'the draft as it is now');
        // His page asks what changed since its newest: Fazil's change, in words.
        $doc = $shareef->get("/ui/purchasing/orders/{$id}/live/doc", ['since' => '0', 'tab' => self::TAB2]);
        self::assertSame([Words::say('LIVE_SAVE', 'ev_set', $fazilName, '1', $d['name'][0])], array_map('trim', array_map(static fn (\DOMElement $li): string => $li->textContent,
            iterator_to_array(self::x($doc)->query('//*[@data-live-part="events"]/li')))));
        self::assertSame([(string) $e], self::attrs($doc, '//*[@data-live-part="events"]/li', 'data-id'));
        self::assertSame(0, self::nodes($shareef->get("/ui/purchasing/orders/{$id}/live/doc", ['since' => (string) $e, 'tab' => self::TAB2]), '//*[@data-live-part="events"]/li'),
            'nothing newer than what the page shows');

        // "Use mine": saved.
        self::assertSame(200, $this->lineSave($shareef, $id, ['op' => 'set', 'uid' => '1', 'rev' => '2', 'packs' => '6'], self::TAB2)->status);
        self::assertSame(3, self::rev($id, 1));
        // A figure that is not one: 422, its words on its line, nothing saved.
        $bad = $this->lineSave($shareef, $id, ['op' => 'set', 'uid' => '2', 'rev' => '1', 'packs' => '2.5']);
        self::assertSame(422, $bad->status, self::why($bad));
        self::assertSame(['bad_line'], self::attrs($bad, '//*[@data-live-part="conflict"][@data-uid="2"]', 'data-code'));
        self::assertNotSame('', self::text($bad, '//*[@data-live-part="conflict"]'));
        self::assertSame(1, self::rev($id, 2));
        // A line removed by Fazil: "Fazil removed this line" with its two choices.
        self::assertSame(200, $this->lineSave($fazil, $id, ['op' => 'remove', 'uid' => '2', 'rev' => '1'])->status);
        $gone = $this->lineSave($shareef, $id, ['op' => 'set', 'uid' => '2', 'rev' => '1', 'packs' => '9']);
        self::assertSame(409, $gone->status);
        self::assertSame(['put_back', 'leave'], self::attrs($gone, '//*[@data-live-part="conflict"][@data-code="line_gone"]//button', 'data-live-choice'));
        self::assertStringContainsString(Words::say('LIVE_SAVE', 'gone', $fazilName, $d['name'][1]), self::text($gone, '//*[@data-live-part="conflict"]'));
        // The same product added by both within two minutes: asked, "Add 5 more".
        self::assertSame(200, $this->lineSave($fazil, $id, ['op' => 'add', 'si' => (string) $d['si'][2], 'packs' => '5', 'k' => 'a000000000000001'])->status);
        $ask = $this->lineSave($shareef, $id, ['op' => 'add', 'si' => (string) $d['si'][2], 'packs' => '5', 'k' => 'b000000000000001']);
        self::assertSame(409, $ask->status);
        self::assertSame(['more', 'leave'], self::attrs($ask, '//*[@data-live-part="conflict"][@data-code="line_exists"]//button', 'data-live-choice'));
        self::assertStringContainsString(Words::say('LIVE_SAVE', 'add_more', '5'), self::text($ask, '//*[@data-live-part="conflict"]'));
        $more = $this->lineSave($shareef, $id, ['op' => 'add', 'si' => (string) $d['si'][2], 'packs' => '5', 'k' => 'b000000000000001', 'more' => '1']);
        self::assertSame(['incremented'], self::attrs($more, '//*[@data-live-fragment]', 'data-op-status'));
        // The same add again (its answer lost): done once.
        self::assertSame(['done'], self::attrs($this->lineSave($shareef, $id, ['op' => 'add', 'si' => (string) $d['si'][2], 'packs' => '5', 'k' => 'b000000000000001', 'more' => '1']),
            '//*[@data-live-fragment]', 'data-op-status'));
        self::assertSame(10, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND supplier_item_id = ?', [$id, $d['si'][2]]));
        // The delivery typed: a charge line.
        $dl = $this->lineSave($fazil, $id, ['op' => 'add', 'charge' => '6.50', 'k' => 'a000000000000002']);
        self::assertSame(['added'], self::attrs($dl, '//*[@data-live-fragment]', 'data-op-status'));
        self::assertSame(1, self::nodes($dl, '//*[@data-live-part="lines"]//tr[@data-kg-charge]'));
        // Undo: Fazil's own removed line, back at its place.
        $u = $this->lineSave($fazil, $id, ['op' => 'restore', 'uid' => '2', 'after' => '1', 'k' => 'a000000000000003']);
        self::assertSame(['restored'], self::attrs($u, '//*[@data-live-fragment]', 'data-op-status'));
        self::assertSame('2', self::attrs($u, '//*[@data-live-part="lines"]//tr[@data-kg-row]', 'data-kg-line')[1]);

        // History: the line-by-line changes grouped, each in its fold.
        $page = $fazil->get("/ui/purchasing/orders/{$id}");
        $hist = '//section[contains(@class, "po-history")]';
        self::assertStringContainsString(Words::say('LIVE_SAVE', 'ev_lines', $fazilName, '2'), self::text($page, $hist));
        self::assertSame(1, self::nodes($page, $hist . '//details[contains(@class, "live-fold")]//li[contains(normalize-space(.), "' . Words::say('LIVE_SAVE', 'fold_packs', '3', '8', $d['name'][0]) . '")]'));
    }

    public function testTheHeadsBoxesAndAConfirmedDraftAndTheGuards(): void
    {
        $fazilUser = $this->uiUser('buyer');
        $d = $this->draft($fazilUser['id'], 'Live Head Guards');
        $id = $d['id'];
        $fazil = $this->signIn($fazilUser);
        $shareef = $this->signIn($this->uiUser('buyer'));
        $fazilName = (string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$fazilUser['id']]);
        $head = fn (KernelBrowser $web, array $f, string $tab = self::TAB): UiResponse => $web->post("/ui/purchasing/orders/{$id}/live/header",
            $f + ['csrf' => $this->token($web), 'tab' => $tab]);
        $r = $head($fazil, ['field' => 'note', 'base' => '', 'value' => 'Call before delivery']);
        self::assertSame(200, $r->status, self::why($r));
        self::assertSame(['Call before delivery'], self::attrs($r, '//*[@data-live-part="header"]//*[@data-live-field="note"]', 'value'));
        $c = $head($shareef, ['field' => 'note', 'base' => '', 'value' => 'Ring first'], self::TAB2);
        self::assertSame(409, $c->status);
        $box = '//*[@data-live-part="conflict"][@data-code="field_conflict"][@data-field="note"]';
        self::assertSame(['theirs', 'mine'], self::attrs($c, $box . '//button', 'data-live-choice'));
        self::assertStringContainsString(Words::say('LIVE_SAVE', 'theirs', $fazilName, 'Call before delivery'), self::text($c, $box));
        self::assertSame(['Call before delivery'], self::attrs($c, $box, 'data-theirs'), 'what "Keep theirs" puts back in the box');
        self::assertSame(400, $head($shareef, ['field' => 'supplier_id', 'base' => '', 'value' => '1'])->status, 'the supplier is a whole save');

        // The guards: someone who may only look, no token, another origin.
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        self::assertSame(403, $this->lineSave($desk, $id, ['op' => 'set', 'uid' => '1', 'rev' => '1', 'packs' => '9'])->status);
        $r = $fazil->post("/ui/purchasing/orders/{$id}/live/line", ['csrf' => 'x', 'tab' => self::TAB, 'op' => 'set', 'uid' => '1', 'rev' => '1', 'packs' => '9']);
        self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()]);
        $r = $fazil->post("/ui/purchasing/orders/{$id}/live/line", ['csrf' => $this->token($fazil), 'tab' => self::TAB, 'op' => 'set', 'uid' => '1', 'rev' => '1', 'packs' => '9'],
            ['Origin' => 'http://evil.example']);
        self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()]);
        self::assertSame(1, self::rev($id, 1), 'nothing saved by a refused request');
        self::assertSame(400, $this->lineSave($fazil, $id, ['op' => 'nonsense', 'uid' => '1'])->status);
        self::assertSame(400, $this->lineSave($fazil, $id, ['op' => 'set', 'uid' => 'x', 'rev' => '1', 'packs' => '2'])->status);
        self::assertSame(400, $this->lineSave($fazil, $id, ['op' => 'add', 'si' => (string) $d['si'][0], 'packs' => '1', 'k' => 'NOTAKEY'])->status);

        // Confirm with the version the page shows, after Fazil changed line 3: refused, saying who changed which line.
        $shown = self::docVersion($id);
        self::assertSame(200, $this->lineSave($fazil, $id, ['op' => 'set', 'uid' => '3', 'rev' => '1', 'packs' => '4'])->status);
        $f = $shareef->get("/ui/purchasing/orders/{$id}")->form('/approve');
        self::assertSame([], $f, 'the page has no form of its own for it: the script sends csrf and the version');
        $no = $shareef->post("/ui/purchasing/orders/{$id}/approve", ['csrf' => $this->token($shareef), 'version' => (string) $shown]);
        self::assertSame(409, $no->status, self::why($no));
        self::assertStringContainsString(Words::say('LIVE_SAVE', 'confirm_changed_line', $fazilName, '3'), self::text($no, '//p[contains(@class, "error")][@role="alert"]'));
        self::assertSame('draft', (string) self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));
        $ok = $shareef->post("/ui/purchasing/orders/{$id}/approve", ['csrf' => $this->token($shareef), 'version' => (string) self::docVersion($id)]);
        self::assertSame(303, $ok->status, self::why($ok));

        // Confirmed: a box saved now is refused, the page is told who confirmed it and its number, and nothing is saved.
        $late = $this->lineSave($fazil, $id, ['op' => 'set', 'uid' => '1', 'rev' => '1', 'packs' => '7']);
        self::assertSame(409, $late->status);
        $number = (string) self::$db->value('SELECT number FROM document WHERE id = ?', [$id]);
        self::assertSame(['not_draft'], self::attrs($late, '//*[@data-live-part="conflict"]', 'data-code'));
        $shareefName = (string) self::$db->value('SELECT display_name FROM staff_user u JOIN document d ON d.posted_by = u.id WHERE d.id = ?', [$id]);
        self::assertStringContainsString(Words::say('LIVE', 'confirmed', $shareefName, $number), self::text($late, '//*[@data-live-part="conflict"]'));
        self::assertSame(1, self::rev($id, 1));
    }

    public function testThePageWithoutTheScriptStillSavesTheWholeForm(): void
    {
        $fazil = $this->uiUser('buyer');
        $d = $this->draft($fazil['id'], 'Live No Script');
        $id = $d['id'];
        $web = $this->signIn($this->uiUser('buyer'));
        $f = $web->get("/ui/purchasing/orders/{$id}")->form('/lines');
        $r = $web->post("/ui/purchasing/orders/{$id}/lines", ['line_2_packs' => '60', 'note' => 'whole', 'action' => 'save'] + $f);
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame(60, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 2', [$id]));
        self::assertSame([1, 2, 1], array_map(static fn (int $u): int => self::rev($id, $u), [1, 2, 3]), 'only the line changed moves its version');
        // A tab from before: its whole save carries the old version and is refused naming who changed it (nothing overwritten).
        self::assertSame(200, $this->lineSave($web, $id, ['op' => 'set', 'uid' => '1', 'rev' => '1', 'packs' => '9'])->status);
        $stale = $web->post("/ui/purchasing/orders/{$id}/lines", ['line_1_packs' => '1', 'action' => 'save'] + $f);
        self::assertSame(409, $stale->status);
        self::assertSame(9, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 1', [$id]));
        // A whole form refused (a figure that is not one) is drawn again with what was typed: it stays the whole form (its boxes are not the
        // stored values) until it is saved or drawn afresh (the Codex review of b40f96a, a2).
        $bad = $web->post("/ui/purchasing/orders/{$id}/lines", ['line_2_packs' => 'x', 'action' => 'save'] + $web->get("/ui/purchasing/orders/{$id}")->form('/lines'));
        self::assertSame(422, $bad->status, self::why($bad));
        self::assertSame(0, self::nodes($bad, '//*[@data-live-save]'));
        self::assertSame(1, self::nodes($web->get("/ui/purchasing/orders/{$id}"), '//*[@data-live-save]'));
    }
}
