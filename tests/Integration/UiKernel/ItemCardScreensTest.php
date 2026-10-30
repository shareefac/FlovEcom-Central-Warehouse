<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Catalogue\BarcodeSync;
use CW\Catalogue\ItemCards;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The item card screens through the real /ui kernel as cw_app (IM3; docs/decisions.md I109, I110): the catalogue team fills in a
 * card on the item page (suggestions accepted one by one, the form, the confirmation), a rule warns and then needs an explicit
 * acknowledgement and blocks; stale and invalid forms come back with what was typed; one effect per form; who sees what and who is
 * refused (buyer, admin: 403 even when they POST; CSRF); the barcodes on the item page and the barcode review queue; the item cards
 * list in stock order with its filters and CSV; the CSV import screen; phone-width markup; hostile text shown as text.
 */
final class ItemCardScreensTest extends KernelUiTestCase
{
    /** An item holding $stock units at MAIN, with the matcher's identity card. */
    private function stocked(string $name, int $stock, array $identity = []): int
    {
        $sku = $this->item('legacy', $stock, $name);
        if ($identity !== []) {
            self::$db->exec('UPDATE sku SET ' . implode(', ', array_map(static fn (string $k): string => "{$k} = ?", array_keys($identity))) . ' WHERE id = ?',
                [...array_values($identity), $sku]);
        }
        return $sku;
    }

    /** @return array<string, string> the inputs of the form an XPath expression finds */
    private static function formAt(UiResponse $r, string $xpath): array
    {
        $form = (new \DOMXPath($r->dom()))->query($xpath)->item(0);
        self::assertInstanceOf(\DOMElement::class, $form, "no form at {$xpath}: " . $r->describe());
        $out = [];
        foreach ($form->getElementsByTagName('input') as $in) {
            /** @var \DOMElement $in */
            $type = strtolower($in->getAttribute('type') ?: 'text');
            if ($in->getAttribute('name') === '' || in_array($type, ['submit', 'file'], true) || (in_array($type, ['radio', 'checkbox'], true) && !$in->hasAttribute('checked'))) {
                continue;
            }
            $out[$in->getAttribute('name')] = $in->getAttribute('value');
        }
        return $out;
    }

    /**
     * The card form's fields as a browser sends them (UiResponse::form reads a checked radio of value "" as "on"; a browser sends "").
     *
     * @return array<string, string>
     */
    private static function cardForm(UiResponse $r, int $sku): array
    {
        $f = $r->form("/ui/items/{$sku}/card");
        foreach (['duty_liable', 'single_use'] as $k) {
            if (($f[$k] ?? null) === 'on') {
                $f[$k] = '';
            }
        }
        return $f;
    }

    private static function acceptForm(UiResponse $r, string $field, string $value): array
    {
        return self::formAt($r, "//form[contains(@action, '/card/accept')][.//input[@name='field' and @value='{$field}']][.//input[@name='value' and @value='{$value}']]");
    }

    private static function cw(int $sku): string
    {
        return sprintf('CW-%06d', $sku);
    }

    public function testTheCatalogueTeamFillsInAndConfirmsACard(): void
    {
        $sku = $this->stocked('Elux Legend Cola 10ml', 40, ['brand' => 'Elux', 'strength_mg' => 20, 'volume_ml' => 10, 'form' => 'nic_salt', 'flavour' => 'cola']);
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get("/ui/items/{$sku}");
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::ITEM_PAGE['g_legal'], $page->text(), 'the card is Details\' LEGAL (the redesign\'s artboard Item, RD-A4)');
        self::assertStringContainsString(Words::CARD_STATE['none'], $page->text());
        self::assertContains("/ui/items/{$sku}/card", $page->hrefs());
        self::assertFalse($page->hasForm('/card/confirm'), 'nothing to confirm yet');
        self::assertStringContainsString(Words::CARD['suggestions_text'], $page->text());
        self::assertStringContainsString(Words::CARD['src_identity'], $page->text(), 'where a suggestion comes from, in words');

        // A suggestion, used with one press; the same form sent twice has one effect.
        $accept = self::acceptForm($page, 'nicotine_mg', '20.00');
        self::assertSame(['csrf', 'form_key', 'version', 'field', 'value'], array_keys($accept));
        $r = $web->post("/ui/items/{$sku}/card/accept", $accept);
        self::assertSame([303, "/ui/items/{$sku}?notice=accepted"], [$r->status, $r->location()], $r->describe());
        self::assertSame("/ui/items/{$sku}?notice=accepted", $web->post("/ui/items/{$sku}/card/accept", $accept)->location());
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM item_card_change WHERE sku_id = ?', [$sku]));
        $page = $web->follow($r);
        self::assertStringContainsString(Words::CARD_NOTICE['accepted'], $page->text());
        self::assertStringContainsString('Nicotine (mg/ml) 20 mg/ml', $page->text());

        // The form: every field; saved; the item page shows it.
        $form = $web->get("/ui/items/{$sku}/card");
        self::assertSame(200, $form->status, $form->describe());
        $f = self::cardForm($form, $sku);
        self::assertSame(['csrf', 'form_key', 'version', 'liquid_ml', 'nicotine_mg', 'duty_liable', 'single_use', 'ecid', 'manufacturer', 'brand', 'flavour', 'product_type'],
            array_keys($f));
        self::assertSame(['1', '20', '', ''], [$f['version'], $f['nicotine_mg'], $f['duty_liable'], $f['single_use']], 'not known yet: the empty answers are checked');
        self::assertSame(['yes', 'no', ''], $form->radios('single_use'));
        $saved = $web->post("/ui/items/{$sku}/card", ['product_type' => 'e_liquid', 'liquid_ml' => '10', 'duty_liable' => 'yes', 'flavour' => 'Cola'] + $f);
        self::assertSame("/ui/items/{$sku}?notice=card_saved", $saved->location(), $saved->describe());
        $page = $web->follow($saved);
        self::assertStringContainsString(Words::CARD_FIELD['product_type'] . ' e-liquid', $page->text());
        self::assertStringContainsString(Words::CARD_STATE['unconfirmed'], $page->text());
        $confirm = $page->form('/card/confirm');
        self::assertSame(['csrf', 'form_key', 'version'], array_keys($confirm), 'no acknowledgement box: nothing is broken');
        $c = $web->post("/ui/items/{$sku}/card/confirm", $confirm);
        self::assertSame("/ui/items/{$sku}?notice=confirmed", $c->location(), $c->describe());
        $page = $web->follow($c);
        self::assertMatchesRegularExpression('/Confirmed by Stock_controller \d+ on \d{1,2} [A-Z][a-z]{2} \d{4}, \d\d:\d\d\./', $page->text(), 'UK time, never UTC');
        self::assertStringNotContainsString('UTC', $page->text());
        self::assertFalse($page->hasForm('/card/confirm'));
        self::assertStringContainsString('History of the card (3)', $page->text());
        self::assertStringContainsString('used a suggestion: nicotine (mg/ml): (empty) → 20 mg/ml', $page->text());

        // A stale form (someone saved meanwhile): nothing written, what was typed kept, what changed shown, the new version carried.
        $stale = self::cardForm($web->get("/ui/items/{$sku}/card"), $sku);
        (new ItemCards(self::$db))->save(Caller::staff((int) self::$db->value("SELECT id FROM staff_user WHERE email LIKE 'k-stock_controller-%'")), $sku, 3, ['manufacturer' => 'Elux Tech']);
        $r = $web->post("/ui/items/{$sku}/card", ['brand' => 'Typed brand'] + $stale);
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString(Words::CARD_ERROR['card_changed'], $r->text());
        self::assertStringContainsString(Words::CARD['changed_meanwhile'], $r->text());
        self::assertStringContainsString('manufacturer: (empty) → Elux Tech', $r->text());
        $again = self::cardForm($r, $sku);
        self::assertSame(['4', 'Typed brand'], [$again['version'], $again['brand']]);
        self::assertNull(self::$db->value('SELECT brand FROM item_card WHERE sku_id = ?', [$sku]), 'nothing written');
        // An invalid value: the field says why, the rest is kept.
        $bad = $web->post("/ui/items/{$sku}/card", ['liquid_ml' => '2.55', 'ecid' => 'x'] + $again);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString(Words::CARD['invalid'], $bad->text());
        self::assertStringContainsString('The liquid is given to 0.1 ml', $bad->text());
        self::assertSame(['2.55', 'Typed brand', $again['form_key']], [$bad->form("/ui/items/{$sku}/card")['liquid_ml'], $bad->form("/ui/items/{$sku}/card")['brand'],
            $bad->form("/ui/items/{$sku}/card")['form_key']]);
        $ok = $web->post("/ui/items/{$sku}/card", ['brand' => 'Typed brand'] + $again);
        self::assertSame("/ui/items/{$sku}?notice=card_saved", $ok->location(), $ok->describe());
        self::assertSame('Typed brand', self::$db->value('SELECT brand FROM item_card WHERE sku_id = ?', [$sku]));
        // A legal field changed on a confirmed card: the page says it is no longer confirmed.
        $c = $web->post("/ui/items/{$sku}/card/confirm", $web->get("/ui/items/{$sku}")->form('/card/confirm'));
        self::assertSame("/ui/items/{$sku}?notice=confirmed", $c->location(), $c->describe());
        $r = $web->post("/ui/items/{$sku}/card", ['liquid_ml' => '10.0', 'flavour' => 'Cola Ice'] + self::cardForm($web->get("/ui/items/{$sku}/card"), $sku));
        self::assertSame("/ui/items/{$sku}?notice=card_saved_unconfirmed", $r->location(), $r->describe());
        self::assertStringContainsString(Words::CARD_NOTICE['card_saved_unconfirmed'], $web->follow($r)->text());
        self::assertStringContainsString(Words::CARD_STATE['changed'], $web->follow($r)->text());
    }

    public function testARuleWarnsThenNeedsAnAcknowledgementThenBlocks(): void
    {
        $sku = $this->stocked('Big tank', 3);
        $web = $this->signIn($this->uiUser('purchasing_manager'));
        $f = self::cardForm($web->get("/ui/items/{$sku}/card"), $sku);
        $saved = $web->post("/ui/items/{$sku}/card", ['product_type' => 'tank', 'liquid_ml' => '5', 'duty_liable' => 'no'] + $f);
        self::assertSame("/ui/items/{$sku}?notice=card_saved", $saved->location(), $saved->describe());
        $page = $web->get("/ui/items/{$sku}");
        self::assertStringContainsString(Words::CARD['warning'], $page->text());
        self::assertStringContainsString('tank or pod over 2 ml A tank or cartridge may hold at most 2 ml (TRPR 2016 reg 36).', $page->text());
        $xp = new \DOMXPath($page->dom());
        self::assertSame(1, $xp->query('//section[contains(@class, "item-card") and contains(@class, "warned")]')->length);
        $confirm = $page->form('/card/confirm');
        self::assertArrayNotHasKey('acknowledge_block', $confirm, 'the box is not ticked for the person');
        self::assertSame(1, $xp->query('//form[contains(@action, "/card/confirm")]//input[@name="acknowledge_block"]')->length);
        $r = $web->post("/ui/items/{$sku}/card/confirm", $confirm);
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::say('CARD_ERROR', 'card_breaches', 'tank or pod over 2 ml'), $r->text());
        self::assertNull(self::$db->value('SELECT confirmed_at FROM item_card WHERE sku_id = ?', [$sku]));
        self::assertStringContainsString(Words::CARD['acknowledge'], $r->text());
        $r = $web->post("/ui/items/{$sku}/card/confirm", ['acknowledge_block' => '1'] + $r->form('/card/confirm'));
        self::assertSame("/ui/items/{$sku}?notice=confirmed_blocked", $r->location(), $r->describe());
        $page = $web->follow($r);
        self::assertStringContainsString(Words::CARD['blocked'], $page->text(), 'what a block stops TODAY (I121, I139, I160, correction d)');
        self::assertStringContainsString('a delivery of it cannot be booked in', Words::CARD['blocked'], 'receiving asks the card since IM6 (I139)');
        self::assertStringNotContainsString('cannot be ordered, received or sold', $page->text());
        self::assertStringContainsString('It stays blocked until someone corrects the card and confirms it again.', $page->text());
        // Corrected on the form: still blocked, and the page says why; confirmed again: lifted.
        $f = self::cardForm($web->get("/ui/items/{$sku}/card"), $sku);
        $r = $web->post("/ui/items/{$sku}/card", ['liquid_ml' => '2'] + $f);
        self::assertSame("/ui/items/{$sku}?notice=card_saved_unconfirmed", $r->location(), $r->describe());
        $page = $web->follow($r);
        self::assertStringContainsString(Words::CARD['still_blocked'], $page->text());
        $confirm = $page->form('/card/confirm');
        self::assertArrayNotHasKey('acknowledge_block', $confirm);
        self::assertSame(0, (new \DOMXPath($page->dom()))->query('//form[contains(@action, "/card/confirm")]//input[@name="acknowledge_block"]')->length,
            'nothing broken now: no box to tick');
        $r = $web->post("/ui/items/{$sku}/card/confirm", $confirm);
        self::assertSame("/ui/items/{$sku}?notice=confirmed_lifted", $r->location(), $r->describe());
        self::assertStringContainsString(Words::CARD_NOTICE['confirmed_lifted'], $web->follow($r)->text());
        self::assertSame(1, (new \DOMXPath($page->dom()))->query('//section[contains(@class, "item-card") and contains(@class, "blocked")]')->length);
    }

    /** I114: a flavour a file proposed is marked on the form, and saving the form for another field leaves it proposed. */
    public function testTheFormKeepsAFileFlavourProposed(): void
    {
        $sku = $this->stocked('Flavoured', 1);
        $who = $this->uiUser('mapping_lead');
        (new ItemCards(self::$db))->save(Caller::staff($who['id']), $sku, 0, ['flavour' => 'Mint'], 'import', ['import_run' => 1]);
        $web = $this->signIn($who);
        $form = $web->get("/ui/items/{$sku}/card");
        self::assertStringContainsString(Words::CARD['flavour_file'], $form->text());
        $f = self::cardForm($form, $sku);
        self::assertSame('Mint', $f['flavour']);
        $r = $web->post("/ui/items/{$sku}/card", ['liquid_ml' => '10'] + $f);
        self::assertSame("/ui/items/{$sku}?notice=card_saved", $r->location(), $r->describe());
        self::assertSame(['Mint', 'proposed'], array_values((array) self::$db->one('SELECT flavour, flavour_status FROM item_card WHERE sku_id = ?', [$sku])));
        $page = $web->follow($r);
        self::assertStringContainsString('Mint (' . Words::CARD['from_file'] . ')', $page->text());
        $c = $web->post("/ui/items/{$sku}/card/accept", self::acceptForm($page, 'flavour', 'Mint'));
        self::assertSame("/ui/items/{$sku}?notice=accepted", $c->location(), $c->describe());
        self::assertSame('confirmed', self::$db->value('SELECT flavour_status FROM item_card WHERE sku_id = ?', [$sku]));
        self::assertStringNotContainsString(Words::CARD['flavour_file'], $web->get("/ui/items/{$sku}/card")->text());
    }

    public function testWhoSeesWhatAndWhoIsRefused(): void
    {
        $sku = $this->stocked('Guarded', 1, ['strength_mg' => 20]);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id) VALUES ('4006381333931', ?)", [$sku]);
        $refused = static fn (string $jobs): string => 'Only ' . Words::whoCan('catalogue.edit') . ' can do this. Nothing was changed. You work as: ' . $jobs . '.';
        foreach ([['buyer', $refused('Buyer')], [['admin', 'auditor'], $refused('Admin · Auditor (look only)')]] as [$roles, $why]) {
            $web = $this->signIn($this->uiUser($roles));
            $page = $web->get("/ui/items/{$sku}");
            self::assertSame(200, $page->status);
            self::assertStringContainsString(Words::ITEM_PAGE['g_legal'], $page->text());
            foreach (['/card/accept', '/card/confirm', '/barcodes/remove', '/barcodes/units', "/ui/items/{$sku}/barcodes"] as $action) {
                self::assertFalse($page->hasForm($action), "{$action}: no form for " . json_encode($roles));
            }
            self::assertNotContains("/ui/items/{$sku}/card", $page->hrefs());
            self::assertSame(200, $web->get('/ui/items/cards')->status, 'everyone reads the list');
            $token = $this->token($web);
            foreach (["/ui/items/{$sku}/card" => ['brand' => 'X', 'version' => '0'], "/ui/items/{$sku}/card/confirm" => ['version' => '0'],
                "/ui/items/{$sku}/card/accept" => ['field' => 'nicotine_mg', 'value' => '20', 'version' => '0'], "/ui/items/{$sku}/barcodes" => ['barcode' => '96385074'],
                "/ui/items/{$sku}/barcodes/remove" => ['barcode' => '4006381333931'], '/ui/items/cards/import' => ['mode' => 'apply'], '/ui/items/barcodes/1/decide' => []] as $path => $form) {
                $r = $web->post($path, ['csrf' => $token, 'form_key' => str_repeat('a', 32)] + $form);
                self::assertSame(403, $r->status, "{$path}: " . $r->describe());
                self::assertStringContainsString(strtolower($why), strtolower($r->text()));
            }
            foreach (["/ui/items/{$sku}/card", '/ui/items/barcodes', '/ui/items/cards/import'] as $path) {
                self::assertSame(403, $web->get($path)->status, $path);
            }
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_card'));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM sku_barcode'));

        // The catalogue team: a CSRF-less post is refused; the menu has the barcode review with its count.
        $web = $this->signIn($this->uiUser('mapping_lead'));
        $f = $web->get("/ui/items/{$sku}/card")->form("/ui/items/{$sku}/card");
        unset($f['csrf']);
        $r = $web->post("/ui/items/{$sku}/card", ['brand' => 'X'] + $f);
        self::assertSame([403, 0], [$r->status, (int) self::$db->value('SELECT COUNT(*) FROM item_card')]);
        self::assertSame('csrf', $r->errorCode());
        $vpg = $this->site('vpg');
        $other = $this->item('legacy', 0, 'Other');
        $this->profiled($vpg, 'V1', ['barcodes' => ['4006381333931']], $other);
        (new BarcodeSync(self::$db))->run(Caller::system('sync_barcodes'), true);
        self::assertSame(['href' => '/ui/items/cards', 'count' => 1], self::nav($web->get('/ui/'))['All products'], 'the sidebar\'s All products: the Products section (HV2)');
        self::assertSame(1, array_column(self::sectionTabs($web->get('/ui/items/barcodes')), 'count', 'label')['Barcodes'], 'Products › Barcodes: 1 to check');
    }

    public function testBarcodesOnTheItemPageAndTheReviewQueue(): void
    {
        $sku = $this->stocked('Barcoded', 2);
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get("/ui/items/{$sku}");
        $add = $page->form("/ui/items/{$sku}/barcodes");
        self::assertSame(['csrf', 'form_key', 'barcode', 'units'], array_keys($add));
        self::assertSame('1', $add['units']);
        $bad = $web->post("/ui/items/{$sku}/barcodes", ['barcode' => '4006381333932'] + $add);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('the last one would be 1', $bad->text());
        self::assertSame('4006381333932', $bad->form("/ui/items/{$sku}/barcodes")['barcode'], 'what was typed is kept');
        $ok = $web->post("/ui/items/{$sku}/barcodes", ['barcode' => '10012345678902', 'units' => '10'] + $bad->form("/ui/items/{$sku}/barcodes"));
        self::assertSame("/ui/items/{$sku}?notice=barcode_added", $ok->location(), $ok->describe());
        $page = $web->follow($ok);
        self::assertStringContainsString(Words::CARD_NOTICE['barcode_added'], $page->text());
        self::assertSame(10, (int) self::$db->value("SELECT units_per_scan FROM sku_barcode WHERE barcode = '10012345678902'"));
        $units = self::formAt($page, "//form[contains(@action, '/barcodes/units')][.//input[@name='barcode' and @value='10012345678902']]");
        self::assertSame(['csrf', 'form_key', 'barcode', 'units_was', 'units'], array_keys($units));
        $r = $web->post("/ui/items/{$sku}/barcodes/units", ['units' => '12'] + $units);
        self::assertSame("/ui/items/{$sku}?notice=units_saved", $r->location(), $r->describe());
        self::assertSame(12, (int) self::$db->value("SELECT units_per_scan FROM sku_barcode WHERE barcode = '10012345678902'"));
        $page = $web->get("/ui/items/{$sku}");
        self::assertSame(1, (new \DOMXPath($page->dom()))->query("//details[contains(@class, 'remove-barcode')]//form[contains(@action, '/barcodes/remove')]")->length,
            'removing is behind a second step (one tap on a phone must not remove a barcode)');
        self::assertStringContainsString(Words::say('BARCODE', 'remove_text', '10012345678902', self::cw($sku)), $page->text());
        $remove = self::formAt($page, "//form[contains(@action, '/barcodes/remove')][.//input[@name='barcode' and @value='10012345678902']]");
        $r = $web->post("/ui/items/{$sku}/barcodes/remove", ['reason' => 'old case code'] + $remove);
        self::assertSame("/ui/items/{$sku}?notice=barcode_removed", $r->location(), $r->describe());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM sku_barcode'));
        self::assertSame("/ui/items/{$sku}?notice=barcode_removed", $web->post("/ui/items/{$sku}/barcodes/remove", ['reason' => 'old case code'] + $remove)->location(),
            'the same form again: one removal');

        // The review queue: a conflict found by the sync, decided on the screen.
        $vpg = $this->site('vpg');
        $other = $this->item('legacy', 0, 'Other');
        // A barcode of another item: refused, with a link to that item.
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id) VALUES ('96385074', ?)", [$other]);
        $clash = $web->post("/ui/items/{$sku}/barcodes", ['barcode' => '96385074', 'units' => '1'] + $web->get("/ui/items/{$sku}")->form("/ui/items/{$sku}/barcodes"));
        self::assertSame(409, $clash->status, $clash->describe());
        self::assertContains("/ui/items/{$other}", $clash->hrefs());
        self::assertStringContainsString(Words::say('BARCODE', 'open_other', self::cw($other)), $clash->text());
        self::$db->exec("DELETE FROM sku_barcode WHERE barcode = '96385074'");
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id) VALUES ('4006381333931', ?)", [$sku]);
        $this->profiled($vpg, 'V1', ['barcodes' => ['4006381333931']], $other);
        (new BarcodeSync(self::$db))->run(Caller::system('sync_barcodes'), true);
        $q = $web->get('/ui/items/barcodes');
        self::assertSame(200, $q->status, $q->describe());
        // An inbox (docs/decisions.md W11.8): the open record names its barcode and why it waits.
        $rec = new \DOMXPath($q->dom());
        self::assertSame('4006381333931', trim((string) $rec->evaluate('string(//section[contains(@class, "ap-record")]//h2)')));
        self::assertStringStartsWith(Words::BARCODE_REASON['on_another_item'], trim((string) $rec->evaluate('string(//section[contains(@class, "ap-record")]//p[@class="ap-kind"])')));
        self::assertStringContainsString(Words::BARCODE['on_product'] . ' ' . self::cw($sku) . ' Barcoded (2 in stock)', $q->text());
        self::assertStringContainsString(Words::BARCODE['carried_by'] . ' ' . self::cw($other) . ' Other', $q->text());
        self::assertStringContainsString(Words::BARCODE['cannot_use'], $q->text());
        $id = (int) self::$db->value("SELECT id FROM barcode_review WHERE status = 'open'");
        $decide = $q->form("/ui/items/barcodes/{$id}/decide");
        self::assertSame(['keep_holder', 'move', 'unusable'], $q->radios('decision'));
        $none = $web->post("/ui/items/barcodes/{$id}/decide", $decide);
        self::assertSame(400, $none->status, 'a decision must be chosen: ' . $none->describe());
        $r = $web->post("/ui/items/barcodes/{$id}/decide", ['decision' => 'keep_holder', 'note' => 'site typo'] + $none->form("/ui/items/barcodes/{$id}/decide"));
        self::assertSame('/ui/items/barcodes?notice=decided', $r->location(), $r->describe());
        $q = $web->follow($r);
        self::assertStringContainsString(Words::BARCODE['nothing'], $q->text());
        self::assertSame(1, (int) self::$db->value("SELECT is_usable FROM sku_barcode WHERE barcode = '4006381333931'"));
        self::assertStringContainsString(Words::BARCODE_DECISION['keep_holder'], $web->get('/ui/items/barcodes', ['show' => 'decided'])->text());
    }

    public function testTheListInStockOrderItsFiltersAndTheCsv(): void
    {
        $who = $this->uiUser('stock_controller');
        $cards = new ItemCards(self::$db);
        $low = $this->stocked('Low', 5);
        $high = $this->stocked('High', 50);
        $none = $this->stocked('<b>None</b>', 0);
        $cards->save(Caller::staff($who['id']), $high, 0, ['product_type' => 'tank', 'liquid_ml' => '5', 'duty_liable' => 'no', 'flavour' => '=1+1']);
        $cards->confirm(Caller::staff($who['id']), $high, 1, true);
        $cards->save(Caller::staff($who['id']), $low, 0, ['nicotine_mg' => '25']);
        $web = $this->signIn($this->uiUser('buyer'));
        $list = $web->get('/ui/items/cards');
        self::assertSame(200, $list->status, $list->describe());
        self::assertSame(['All products', 'All products'], [self::currentSection($list), self::currentTab($list)], 'the sidebar\'s All products (HV2)');
        // The row's link is the name; its CW number is the line under it (the redesign's Items artboard, docs/decisions.md RD-A3).
        $codes = static fn (UiResponse $r): array => array_map(static fn (\DOMElement $a): string => trim($a->textContent),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//table[contains(@class, "item-cards")]//th[@scope="row"]/span[@class="o-sub"][1]')));
        self::assertSame([self::cw($high), self::cw($low), self::cw($none)], $codes($list), 'most stock first');
        // (the list is grouped by card: high is blocked and low not confirmed yet, both Needs a look; none has no card; most stock first in each)
        self::assertStringContainsString('tank or pod over 2 ml', $list->text());
        self::assertStringContainsString(Words::say('CARDS', 'warning_line', 'nicotine over 20 mg/ml'), $list->text());
        self::assertStringNotContainsString('<b>None</b>', $list->body);
        self::assertSame([self::cw($high), self::cw($low)], $codes($web->get('/ui/items/cards', ['stock' => '1'])));
        self::assertSame([self::cw($high), self::cw($low)], $codes($web->get('/ui/items/cards', ['warnings' => '1'])));
        self::assertSame([self::cw($high)], $codes($web->get('/ui/items/cards', ['blocked' => '1'])), 'the blocked items, to take off sale by hand');
        self::assertSame([self::cw($high)], $codes($web->get('/ui/items/cards', ['state' => 'confirmed'])));
        self::assertSame([self::cw($low), self::cw($none)], $codes($web->get('/ui/items/cards', ['state' => 'unconfirmed'])));
        self::assertSame([self::cw($none)], $codes($web->get('/ui/items/cards', ['state' => 'none'])));
        self::assertSame([self::cw($high)], $codes($web->get('/ui/items/cards', ['type' => 'tank'])));
        self::assertSame([self::cw($low), self::cw($none)], $codes($web->get('/ui/items/cards', ['type' => 'none'])));
        self::assertSame([self::cw($low)], $codes($web->get('/ui/items/cards', ['q' => 'low'])));
        self::assertFalse($list->hasForm('/ui/items/cards/import'));
        self::assertNotContains('/ui/items/cards/import', $list->hrefs(), 'the import is for the catalogue team');
        self::assertContains('/ui/items/cards.csv?stock=1', $web->get('/ui/items/cards', ['stock' => '1'])->hrefs());

        $csv = $web->get('/ui/items/cards.csv', ['stock' => '1']);
        self::assertSame([200, 'text/csv; charset=utf-8'], [$csv->status, $csv->header('content-type')]);
        self::assertMatchesRegularExpression('/^attachment; filename="item-cards-\d{8}\.csv"/', (string) $csv->header('content-disposition'));
        $lines = explode("\r\n", trim(substr($csv->body, 3)));
        self::assertCount(3, $lines);
        self::assertStringStartsWith('"' . self::cw($high) . '","High","",50,"tank",5.0,', $lines[1]);
        self::assertStringContainsString('"\'=1+1"', $lines[1], 'formula-safe');
    }

    public function testTheCsvImportScreen(): void
    {
        $sku = $this->stocked('Imported', 1);
        $web = $this->signIn($this->uiUser('purchasing_manager'));
        $page = $web->get('/ui/items/cards/import');
        self::assertSame(200, $page->status, $page->describe());
        $xp = new \DOMXPath($page->dom());
        self::assertSame(1, $xp->query('//form[@action="/ui/items/cards/import" and @enctype="multipart/form-data"]//input[@type="file" and @name="file"]')->length);
        self::assertSame(['check', 'apply'], $page->radios('mode'));
        $f = $page->form('/ui/items/cards/import');
        self::assertSame('check', $f['mode']);
        $file = tempnam(sys_get_temp_dir(), 'cwui');
        file_put_contents($file, "code,product_type,liquid_ml,nicotine_mg,duty_liable\n" . self::cw($sku) . ",e-liquid,10,20,yes\n");
        $check = $web->postMultipart('/ui/items/cards/import', $f, ['file' => ['path' => $file, 'name' => 'cards.csv']]);
        self::assertSame(200, $check->status, $check->describe());
        self::assertStringContainsString(Words::say('CARD_IMPORT', 'checked', 'cards.csv'), $check->text());
        self::assertStringContainsString(Words::say('CARD_IMPORT', 'counts', 1, 1, Words::CARD_IMPORT['would_change'], 0, 0), $check->text());
        self::assertStringContainsString('up to 2,000 products changed in one import', $page->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_card'));
        $apply = ['mode' => 'apply'] + $check->form('/ui/items/cards/import');
        $r = $web->postMultipart('/ui/items/cards/import', $apply, ['file' => ['path' => $file, 'name' => 'cards.csv']]);
        self::assertSame(200, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('CARD_IMPORT', 'imported', 'cards.csv'), $r->text());
        self::assertSame('10.0', self::$db->value('SELECT liquid_ml FROM item_card WHERE sku_id = ?', [$sku]));
        $replay = $web->postMultipart('/ui/items/cards/import', $apply, ['file' => ['path' => $file, 'name' => 'cards.csv']]);
        self::assertStringContainsString('Imported: cards.csv', $replay->text(), 'the same form and file again: the first report, one import');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'item_card.import'"));
        file_put_contents($file, "code,nicotine_mg\n" . self::cw($sku) . ",lots\nCW-999999,\n");
        $bad = $web->postMultipart('/ui/items/cards/import', ['mode' => 'apply'] + $r->form('/ui/items/cards/import'), ['file' => ['path' => $file, 'name' => 'bad.csv']]);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString(Words::say('CARD_IMPORT', 'not_imported', 'bad.csv'), $bad->text());
        self::assertStringContainsString('Row 1 (' . self::cw($sku) . '), nicotine_mg: The nicotine is a number of mg/ml', $bad->text());
        self::assertStringContainsString('Row 2 (CW-999999), code: there is no item CW-999999', $bad->text());
        // A file refused as a whole on the real run: refused, and the run recorded although FormOnce's transaction rolled back (I120).
        file_put_contents($file, "code,nicotine\n" . self::cw($sku) . ",20\n");
        $runs = (int) self::$db->value("SELECT COUNT(*) FROM import_run WHERE kind = 'item_cards'");
        $refused = $web->postMultipart('/ui/items/cards/import', ['mode' => 'apply'] + $bad->form('/ui/items/cards/import'), ['file' => ['path' => $file, 'name' => 'typo.csv']]);
        self::assertSame(400, $refused->status, $refused->describe());
        self::assertSame([$runs + 1, 'failed', 'typo.csv'], array_values(array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, (array) self::$db->one(
            "SELECT (SELECT COUNT(*) FROM import_run WHERE kind = 'item_cards') AS n, status, file_name FROM import_run WHERE kind = 'item_cards' ORDER BY id DESC LIMIT 1"))));
        $none = $web->postMultipart('/ui/items/cards/import', $refused->form('/ui/items/cards/import'), []);
        self::assertSame(400, $none->status);
        self::assertStringContainsString(Words::CARD_IMPORT['no_file'], $none->text());
        unlink($file);
    }

    public function testPhoneWidthAndHostileText(): void
    {
        $sku = $this->stocked('Phone', 1, ['strength_mg' => 20]);
        $who = $this->uiUser('stock_controller');
        (new ItemCards(self::$db))->save(Caller::staff($who['id']), $sku, 0, ['flavour' => '<script>alert(1)</script>', 'brand' => '"><b>x']);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id) VALUES ('96385074', ?)", [$sku]);
        $web = $this->signIn($who);
        $page = $web->get("/ui/items/{$sku}");
        self::assertStringNotContainsString('<script>alert', $page->body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page->body);
        self::assertStringNotContainsString('"><b>x', $page->body);
        self::assertStringNotContainsString('<script>alert', $web->get('/ui/items/cards')->body);
        self::assertStringNotContainsString('"><b>x', $web->get("/ui/items/{$sku}/card")->body);
        $xp = new \DOMXPath($page->dom());
        self::assertSame(1, $xp->query('//meta[@name="viewport" and @content="width=device-width, initial-scale=1, viewport-fit=cover"]')->length);
        self::assertSame(['96385074 · ' . Words::ITEM_PAGE['single']], array_map(static fn (\DOMNode $p): string => trim((string) preg_replace('/\s+/u', ' ', (string) $p->textContent)),
            iterator_to_array($xp->query('//section[@id="barcodes"]//li/p[@class="side-line"]'))), 'the Barcodes card: one line a barcode (RD-A4)');
        self::assertSame(1, $xp->query('//div[@class="scroll"]/table[contains(@class, "stack") and contains(@class, "proposals")]')->length);
        self::assertSame(1, $xp->query('//dl[contains(@class, "item-card-fields")]')->length);
        $list = new \DOMXPath($web->get('/ui/items/cards')->dom());
        // A list table: cards on a phone inside a .table-wrap (rule 14), which drops its own box then (no card inside a card).
        self::assertSame(1, $list->query('//div[contains(@class, "table-wrap")]/table[contains(@class, "stack") and contains(@class, "item-cards")]')->length);
        self::assertGreaterThan(0, $list->query('//table[contains(@class, "item-cards")]//td[@data-label="' . Words::INV_LIST['col_on_hand'] . '"]')->length);
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/ui/assets/app.css');
        self::assertMatchesRegularExpression('/@container \(max-width: 640px\) \{[^}]*dl\.item-card-fields \{ grid-template-columns: minmax\(0, 1fr\); \}/s', $css,
            'one column when the page column is phone width (the page is the container since the design A frame)');
        self::assertStringContainsString('form.add-barcode button, form.confirm-card button, form.barcode-decide button { width: 100%; }', $css);
        self::assertStringContainsString('table.stack td[data-label]::before', $css, 'the shared phone-width table rule');
    }

    // ------------------------------------------------------------------------------------------
    // All Products for normal staff (the owner's request of 21 Oct 2026: easier and friendlier, phone first)
    // ------------------------------------------------------------------------------------------

    /** The texts of one group's column heads, in order. @return list<string> */
    private static function heads(\DOMXPath $xp, string $group): array
    {
        return array_map(static fn (\DOMElement $th): string => trim((string) preg_replace('/\s+/', ' ', $th->textContent)),
            array_values(iterator_to_array($xp->query('//div[@id="g-' . $group . '"]//table[contains(@class, "item-cards")]/thead/tr/th'))));
    }

    /** The list's row of one product. */
    private static function rowOf(\DOMXPath $xp, int $sku): \DOMElement
    {
        $row = $xp->query('//table[contains(@class, "item-cards")]/tbody/tr[th/a[@href="/ui/items/' . $sku . '"]]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $row, "the row of {$sku}");
        return $row;
    }

    /** The group a product's row is in (its board's id without "g-"). */
    private static function groupOf(\DOMXPath $xp, int $sku): string
    {
        return substr((string) $xp->evaluate('string(//div[contains(@class, "doc-board")][.//tbody/tr/th/a[@href="/ui/items/' . $sku . '"]]/@id)'), 2);
    }

    /**
     * A product without a card (the owner's request of 21 Oct 2026; the redesign's Items artboard since 30 Oct, docs/decisions.md RD-A3): it is in
     * the "No card yet" group, its status label "No card" grey (not started, never navy "switched off"); every group has the same three columns
     * (the item, its status, on hand). A card not confirmed yet moves it to "Needs a look" ("Check the card", amber, its warning a soft tag); a
     * confirmed card that a rule blocks stays there, "Blocked" in red. The card's form is one tap away on the item page (Details' Change).
     */
    public function testAProductWithoutACardIsQuietAndItsCardMovesItToItsGroup(): void
    {
        $who = $this->uiUser('stock_controller');
        $a = $this->stocked('Elux Legend Cola 10ml', 3);
        $b = $this->stocked('Nothing filled in', 0);
        $web = $this->signIn($who);
        $xp = new \DOMXPath($web->get('/ui/items/cards')->dom());
        self::assertSame([Words::INV_LIST['col_item'], Words::INV_LIST['col_status'], Words::INV_LIST['col_on_hand']], self::heads($xp, 'none'));
        foreach ([$a => 'Elux Legend Cola 10ml', $b => 'Nothing filled in'] as $sku => $name) {
            self::assertSame('none', self::groupOf($xp, $sku));
            $row = self::rowOf($xp, $sku);
            self::assertSame($name, trim((string) $xp->evaluate('string(th[@scope="row"]/a)', $row)), 'the name opens it');
            self::assertSame(self::cw($sku), trim((string) $xp->evaluate('string(th[@scope="row"]/span[@class="o-sub"][1])', $row)), 'its CW number under it');
            self::assertSame(Words::INV_LIST['p_none'], trim((string) $xp->evaluate('string(td[contains(@class, "doc-st") and contains(@class, "waiting")])', $row)));
            self::assertSame(0, $xp->query('td//a', $row)->length, 'nothing else to press in the row');
        }
        self::assertSame(['/ui/items/' . $a . '/card'], array_values(array_filter($web->get('/ui/items/' . $a)->hrefs(), static fn (string $h): bool => str_ends_with($h, '/card'))),
            'the card\'s form from the item page');

        // A card: its row moves to its group; the one without a card stays where it was.
        $cards = new ItemCards(self::$db);
        $cards->save(Caller::staff($who['id']), $a, 0, ['product_type' => 'tank', 'liquid_ml' => '5', 'duty_liable' => 'no', 'flavour' => 'cola']);
        $xp = new \DOMXPath($web->get('/ui/items/cards')->dom());
        self::assertSame(['look', 'none'], [self::groupOf($xp, $a), self::groupOf($xp, $b)]);
        self::assertSame([Words::INV_LIST['col_item'], Words::INV_LIST['col_status'], Words::INV_LIST['col_on_hand']], self::heads($xp, 'look'), 'the same three columns');
        $rowA = self::rowOf($xp, $a);
        self::assertSame([Words::INV_LIST['p_check'], 'c-status doc-st needs'], [trim((string) $xp->evaluate('string(td[contains(@class, "doc-st")])', $rowA)),
            (string) $xp->evaluate('string(td[contains(@class, "doc-st")]/@class)', $rowA)], 'Check the card: amber');
        self::assertSame(['tag needs'], array_map(static fn (\DOMElement $t): string => $t->getAttribute('class'),
            array_values(iterator_to_array($xp->query('th/span[@class="o-sub"]/span', $rowA)))), 'its warning a soft tag');
        $cards->confirm(Caller::staff($who['id']), $a, 1, true);
        $xp = new \DOMXPath($web->get('/ui/items/cards')->dom());
        self::assertSame('look', self::groupOf($xp, $a), 'confirmed, and blocked by the rule');
        self::assertSame(Words::INV_LIST['p_blocked'], trim((string) $xp->evaluate('string(td[contains(@class, "doc-st") and contains(@class, "blocked")])', self::rowOf($xp, $a))));
        self::assertSame('waiting', Words::tone('CARD_STATE', 'none'), 'No card yet is "not started" (grey), never "switched off" (navy)');
    }

    /**
     * The search box for someone new: it says what can be typed, Enter sends the toolbar's GET form (it has a submit button, the
     * form's default) and the phone's key says Search; a search or a filter that is on has the toolbar's one Clear (every product again), and
     * a search that finds nothing says what to try, with a link that clears the search alone.
     */
    public function testTheSearchSaysWhatToTypeAndClearsInOneTap(): void
    {
        $this->stocked('Elux Legend Cola 10ml', 3);
        $web = $this->signIn($this->uiUser('buyer'));
        $xp = new \DOMXPath($web->get('/ui/items/cards')->dom());
        $box = $xp->query('//form[@method="get" and contains(@class, "toolbar")]//input[@type="search" and @name="q"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $box);
        self::assertSame([Words::INV_LIST['items_search'], 'search'], [$box->getAttribute('placeholder'), $box->getAttribute('enterkeyhint')]);
        self::assertSame(1, $xp->query('//form[@method="get" and contains(@class, "toolbar")]//input[not(@type="hidden")]')->length,
            'the search is the form\'s one field: Enter sends it (no button needed)');
        self::assertSame(0, $xp->query('//header//a[@href="/ui/items/cards"]')->length, 'no search: nothing to clear');

        $xp = new \DOMXPath($web->get('/ui/items/cards', ['q' => 'elux', 'stock' => '1'])->dom());
        self::assertSame([Words::CARDS['clear_filters']], array_map(static fn (\DOMElement $a): string => trim($a->textContent),
            array_values(iterator_to_array($xp->query('//header//details[contains(@class, "page-more")]//a[@href="/ui/items/cards"]')))), 'one Clear, under the head\'s More (RD-A2)');
        self::assertSame(['stock'], array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'),
            array_values(iterator_to_array($xp->query('//form[contains(@class, "toolbar")]/input[@type="hidden"]')))), 'a new search keeps the Show choice');

        $none = $web->get('/ui/items/cards', ['q' => 'zzz nothing', 'stock' => '1']);
        self::assertSame(200, $none->status, $none->describe());
        $xp = new \DOMXPath($none->dom());
        self::assertSame(Words::say('CARDS', 'none_search', 'zzz nothing'), trim((string) $xp->evaluate('string(//div[@class="empty"]/p[@class="empty-title"])')));
        self::assertStringContainsString(Words::CARDS['none_search_text'], $none->text(), 'what to try');
        self::assertSame(['/ui/items/cards?stock=1', Words::CARDS['clear_search']], [(string) $xp->evaluate('string(//div[@class="empty"]//a/@href)'),
            trim((string) $xp->evaluate('string(//div[@class="empty"]//a)'))], 'the search goes, the filter stays');
    }

    /**
     * The filters for someone new (the owner's patterns of 29 Oct 2026; the redesign of 30 Oct, docs/decisions.md RD-A2, RD-A3): the ONE Show choice
     * holds the artboard's four, and the filters of before (the card, the kind of product, four short ticks) are a GET form in More's "More
     * filters" fold, whose inputs say what is on; ONE Clear under More takes every filter and the search off.
     */
    public function testTheFiltersAreUnderMoreAndTheShowChoiceSaysWhatIsShown(): void
    {
        $this->stocked('Tank', 3);
        $web = $this->signIn($this->uiUser('buyer'));
        $fold = '//header//details[contains(@class, "page-more")]//details[contains(@class, "doc-act")]//form[@method="get"]';
        $xp = new \DOMXPath($web->get('/ui/items/cards')->dom());
        self::assertSame(1, $xp->query('//form[contains(@class, "toolbar")]/details[contains(@class, "show-pick")]')->length, 'ONE Show choice');
        self::assertSame(0, $xp->query('//*[contains(@class, "filters-on")] | //div[@class="tb-state"]')->length, 'no chips, no Show row of selects');
        self::assertSame([Words::CARDS['stock'], Words::CARDS['warnings'], Words::CARDS['blocked'], Words::CARDS['discontinued']],
            array_map(static fn (\DOMElement $l): string => trim((string) preg_replace('/\s+/', ' ', $l->textContent)), array_values(iterator_to_array($xp->query($fold . '//label[contains(@class, "tb-check")]')))));
        self::assertSame([Words::CARDS['any_card'], Words::CARDS['any_kind']], array_map(static fn (\DOMElement $s): string => trim((string) $s->getElementsByTagName('option')->item(0)?->textContent),
            array_values(iterator_to_array($xp->query($fold . '//select')))), 'each choice says what it shows when nothing is chosen');
        self::assertSame(Words::INV_LIST['filter_button'], trim((string) $xp->evaluate('string(' . $fold . '//button[@type="submit"])')));

        $xp = new \DOMXPath($web->get('/ui/items/cards', ['q' => 'tank', 'state' => 'changed', 'type' => 'tank', 'stock' => '1', 'blocked' => '1'])->dom());
        self::assertSame(['changed', 'tank'], array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'), array_values(iterator_to_array($xp->query($fold . '//select/option[@selected]')))));
        self::assertSame(['stock', 'blocked'], array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'), array_values(iterator_to_array($xp->query($fold . '//label/input[@checked]')))));
        self::assertSame(['tank'], array_map(static fn (\DOMElement $i): string => $i->getAttribute('value'), array_values(iterator_to_array($xp->query($fold . '/input[@name="q"]')))),
            'the search goes along');
        self::assertSame(1, $xp->query('//header//details[contains(@class, "page-more")]//details[contains(@class, "doc-act") and @open]')->length, 'open in the menu: a filter is on');
        self::assertSame(Words::INV_LIST['filtered'], trim((string) $xp->evaluate('string(//details[contains(@class, "show-pick")]//*[@aria-current])')));
        self::assertSame(1, $xp->query('//header//details[contains(@class, "page-more")]//a[@href="/ui/items/cards"]')->length, 'one Clear, every filter off');
        $kind = new \DOMXPath($web->get('/ui/items/cards', ['type' => 'none', 'warnings' => '1', 'discontinued' => '1'])->dom());
        self::assertSame(['none'], array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'), array_values(iterator_to_array($kind->query('//select[@name="type"]/option[@selected]')))));
        self::assertSame(['warnings', 'discontinued'], array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'), array_values(iterator_to_array($kind->query($fold . '//label/input[@checked]')))));
        // Each tick is a 44 px target.
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/ui/assets/app.css');
        self::assertStringContainsString('.page-more .doc-act-panel form.record .tb-check { min-height: 44px; }', $css);
    }

    /**
     * Less to scroll past on a phone: the rules about warnings and blocks fold into the page's "?" (Words::HELP card_rules) instead of a
     * paragraph above the list; the board's groups say what the list is (the approved board has no figures and no heading of its own).
     */
    public function testTheRulesFoldAwayAndTheGroupsSayWhatTheListIs(): void
    {
        $this->stocked('Folded', 2);
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get('/ui/items/cards');
        $xp = new \DOMXPath($page->dom());
        $rules = trim(str_replace('**', '', Words::HELP['card_rules']));
        self::assertSame(0, $xp->query('//p[contains(@class, "hint") and contains(., "' . mb_substr($rules, 0, 30) . '")]')->length, 'no rules paragraph');
        // ONE "?" beside the heading (docs/decisions.md DC34): the rules, and for a buyer, who only looks, who changes them as its last line.
        self::assertSame(1, $xp->query('//header[@class="page-head"]//details[contains(concat(" ", @class, " "), " help ")]')->length, 'one "?"');
        $help = $xp->query('//header[@class="page-head"]//details[contains(concat(" ", @class, " "), " help ")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $help, 'the "?" beside the heading');
        self::assertSame($rules, trim((string) preg_replace('/\s+/', ' ', (string) $xp->evaluate('string(div[@class="help-body"]/p[1])', $help))));
        self::assertSame(sprintf(Words::UI['look_only'], Words::whoCan('catalogue.edit')), trim((string) $xp->evaluate('string(div[@class="help-body"]/p[last()])', $help)));
        self::assertSame('What does "' . Words::CARDS['rules_label'] . '" mean?', (string) $xp->evaluate('string(summary/@aria-label)', $help));
        self::assertSame([Words::INV_LIST['items'], Words::INV_LIST['g_none'], '1'], [trim((string) $xp->evaluate('string(//h1)')),
            trim((string) $xp->evaluate('string(//h3[@class="grp-title"]/button)')), trim((string) $xp->evaluate('string(//h3[@class="grp-title"]/span[@class="grp-count"])'))]);
        self::assertSame(0, $xp->query('//h2[contains(@class, "board-title")] | //ul[contains(@class, "summary-line")]')->length);
    }

    /**
     * The section's tabs on a narrow screen (every page with tabs; the strip scrolls inside itself): app.js scrolls the open tab into
     * sight and marks the side the strip can still scroll to (more-left / more-right), and the stylesheet fades that edge, so By
     * Item, New Variants and Duplicates no longer hide off the screen without a cue. The tabs' markup is unchanged.
     */
    public function testTheTabsShowThereIsMoreAndKeepTheOpenTabInSight(): void
    {
        $web = $this->signIn($this->uiUser('buyer'));
        $xp = new \DOMXPath($web->get('/ui/items/cards')->dom());
        self::assertSame(1, $xp->query('//nav[@aria-label="Main"]//ul[@class="nav-sub"]/li/a[@aria-current="page"]')->length, 'the page shown: the sidebar\'s sub-item (DC28)');
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/public/ui/assets/app.js');
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/ui/assets/app.css');
        self::assertStringContainsString("each(document.querySelectorAll('nav.tabs'), function (strip) {", $js);
        self::assertStringContainsString("strip.classList.toggle('more-left', max > 1 && strip.scrollLeft > 1);", $js);
        self::assertStringContainsString("strip.classList.toggle('more-right', max > 1 && strip.scrollLeft < max - 1);", $js);
        self::assertStringContainsString("var open = strip.querySelector('[aria-current]');", $js, 'the open tab ...');
        self::assertMatchesRegularExpression('/strip\.scrollLeft = Math\.max\(0, open\.offsetLeft - /', $js, '... is scrolled into sight, the page itself never moves');
        self::assertStringContainsString("strip.addEventListener('scroll', mark, { passive: true });", $js);
        // Codex's review: a narrower window (a phone turned, a window made smaller) brings the open tab back into sight too, but
        // only when the width changed: a phone's address bar showing or hiding is a resize too, and must not undo the person's scroll.
        self::assertStringContainsString('if (window.innerWidth !== width) { width = window.innerWidth; reveal(); }', $js);
        self::assertMatchesRegularExpression('/\.tabs\.more-right \{[^}]*mask-image: linear-gradient\(to right, var\(--mask\) calc\(100% - 48px\), transparent\)/', $css);
        self::assertMatchesRegularExpression('/\.tabs\.more-left \{[^}]*mask-image: linear-gradient\(to left, var\(--mask\) calc\(100% - 48px\), transparent\)/', $css);
        self::assertMatchesRegularExpression('/\.tabs\.more-left\.more-right \{[^}]*mask-image: linear-gradient\(to right, transparent, var\(--mask\) 48px, var\(--mask\) calc\(100% - 48px\), transparent\)/', $css);
    }
}
