<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Items\Families;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\FamiliesController;
use CW\Ui\Words;

/**
 * A family's "Add items" picker through the real /ui kernel as cw_app (the owner, 5 Nov 2026: "i need a drop down with search and multicheck
 * option"; docs/decisions.md FP1-FP11): the search's choices (a fragment: every word, a CW number however it is written, a barcode; an item
 * in a family shown but not tickable, with the family's name; a joined item left out; at most FamiliesController::FIND_LIMIT, said; who may
 * search), and the ticked items put in the family through Families::bulkAssign (added, skipped with the reason, sent twice done once,
 * nothing ticked or more than the bulk setting refused with nothing saved and the ticks drawn again, who may add, the CSRF token, a family
 * switched off). The pasted list stays, folded under the picker.
 */
final class FamilyPickerScreensTest extends KernelUiTestCase
{
    protected function handlers(Db $db): array
    {
        return DocumentHandlers::all($db);
    }

    private static function xp(UiResponse $r): \DOMXPath
    {
        return new \DOMXPath($r->dom());
    }

    private static function cw(int $sku): string
    {
        return sprintf('CW-%06d', $sku);
    }

    /** The choices of a fragment: each item's id => [tickable, its family line, its name, its facts line]. @return array<int, array{0: bool, 1: string, 2: string, 3: string}> */
    private static function choices(UiResponse $r): array
    {
        $xp = self::xp($r);
        $out = [];
        foreach ($xp->query('//ul[@data-fp-choices]/li[@data-sku]') ?: [] as $li) {
            self::assertInstanceOf(\DOMElement::class, $li);
            $box = $xp->query('.//input[@type="checkbox"][@data-fp-box]', $li)->item(0);
            self::assertInstanceOf(\DOMElement::class, $box, 'each item has its tick');
            self::assertSame('', $box->getAttribute('name'), 'a tick in the list sends nothing itself');
            self::assertSame($li->getAttribute('data-sku'), $box->getAttribute('value'));
            $out[(int) $li->getAttribute('data-sku')] = [!$box->hasAttribute('disabled'), trim((string) $xp->evaluate('string(.//span[contains(@class, "tag")][1])', $li)),
                trim((string) $xp->evaluate('string(.//span[@class="fp-name"])', $li)), trim((string) $xp->evaluate('string(.//span[@class="fp-meta"])', $li))];
        }
        return $out;
    }

    private static function says(UiResponse $r): string
    {
        return trim((string) self::xp($r)->evaluate('string(//*[@data-fp-says])'));
    }

    /** The picker's form as the family page draws it. @return array<string, string> */
    private function pickForm(\CW\Tests\Support\KernelBrowser $web, string $path): array
    {
        $page = $web->get($path);
        self::assertSame(200, $page->status, $page->describe());
        $form = $page->form($path . '/pick');
        self::assertArrayHasKey('csrf', $form);
        self::assertArrayHasKey('form_key', $form);
        return $form;
    }

    public function testTheSearchFindsItemsByEveryWordTheirCwNumberAndBarcodeAndSaysWhereEachIs(): void
    {
        $fam = new Families(self::$db);
        $buyer = $this->staffUser('buyer');
        $here = $fam->create($buyer, 'SKE Crystal 20mg')['id'];
        $other = $fam->create($buyer, 'Old crystal range')['id'];
        $a = $this->item('legacy', 5, 'SKE Crystal Original 20mg Blue Razz');
        $b = $this->item('legacy', 0, 'SKE Crystal Original 10mg Blue Razz');
        $c = $this->item('legacy', 2, 'Crystal Clear Bar 20mg Mango');
        $joined = $this->item('legacy', 0, 'SKE Crystal Original 20mg Joined');
        $in = $this->item('legacy', 0, 'SKE Crystal Original 20mg Cherry');
        $away = $this->item('legacy', 0, 'SKE Crystal Original 20mg Grape');
        self::$db->exec("UPDATE sku SET brand = 'SKE' WHERE id IN (?, ?, ?, ?, ?)", [$a, $b, $joined, $in, $away]);
        self::$db->exec("UPDATE sku SET brand = 'Clear' WHERE id = ?", [$c]);
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$a, $joined]);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, units_per_scan) VALUES ('5012345678900', ?, 1)", [$c]);
        $fam->assign($buyer, $in, $here, null);
        $fam->assign($buyer, $away, $other, null);
        $fam->setActive($buyer, $other, 1, false);

        $web = $this->signIn($this->uiUser('buyer'));
        $path = FamiliesController::PATH . '/' . $here;
        $r = $web->get($path . '/find', ['q' => 'ske crystal original 20mg']);
        self::assertSame(200, $r->status, $r->describe());
        self::assertSame(1, self::xp($r)->query('/html/body/*[@data-fp-fragment]')->length, 'a fragment, not a page');
        self::assertSame(0, self::xp($r)->query('//header | //nav | //form')->length);
        $got = self::choices($r);
        self::assertSame([$a, $in, $away], array_keys($got), 'every word, in any order; by name; the joined item left out, the 10mg and the other brand too');
        self::assertSame([true, '', 'SKE Crystal Original 20mg Blue Razz', self::cw($a) . ' · SKE · ' . sprintf(Words::FAMILY_PICK['stock'], 5)], $got[$a]);
        self::assertSame([false, Words::FAMILY_PICK['here']], array_slice($got[$in], 0, 2), 'already in this family: shown, not tickable');
        self::assertSame([false, sprintf(Words::FAMILY_PICK['other_off'], 'Old crystal range')], array_slice($got[$away], 0, 2),
            'in another family (switched off): shown with its name, not tickable');
        self::assertSame(sprintf(Words::FAMILY_PICK['found_many'], 3), self::says($r));
        self::assertSame(self::cw($a), self::xp($r)->evaluate('string(//li[@data-sku="' . $a . '"]//input/@data-cw)'), 'what app.js copies into the ticked list');
        self::assertSame('SKE Crystal Original 20mg Blue Razz', self::xp($r)->evaluate('string(//li[@data-sku="' . $a . '"]//input/@data-name)'));

        // A CW number however it is written, a barcode; the brand is a word too.
        self::assertSame([$b], array_keys(self::choices($web->get($path . '/find', ['q' => self::cw($b)]))));
        self::assertSame([$b], array_keys(self::choices($web->get($path . '/find', ['q' => sprintf('%06d', $b)]))), 'the number without CW');
        self::assertSame([$c], array_keys(self::choices($web->get($path . '/find', ['q' => '5012345678900']))), 'a scanned barcode');
        $one = $web->get($path . '/find', ['q' => 'clear mango']);
        self::assertSame([$c], array_keys(self::choices($one)));
        self::assertSame(Words::FAMILY_PICK['found_one'], self::says($one));
        // A joined item is left out, also by its CW number; one letter asks nothing; nothing found is said.
        $gone = $web->get($path . '/find', ['q' => self::cw($joined)]);
        self::assertSame([], self::choices($gone));
        self::assertSame(sprintf(Words::FAMILY_PICK['none'], self::cw($joined)), self::says($gone));
        $short = $web->get($path . '/find', ['q' => 's']);
        self::assertSame([[], ''], [self::choices($short), self::says($short)]);
        self::assertSame(sprintf(Words::FAMILY_PICK['none'], 'zzzz'), self::says($web->get($path . '/find', ['q' => 'zzzz'])));
        // No such family: refused, not an empty list.
        self::assertSame(404, $web->get(FamiliesController::PATH . '/999999/find', ['q' => 'crystal'])->status);

        // At most FIND_LIMIT, and it says so.
        for ($i = 0; $i <= FamiliesController::FIND_LIMIT; $i++) {
            self::makeSku(sprintf('Bulk pod %03d', $i));
        }
        $many = $web->get($path . '/find', ['q' => 'bulk pod']);
        self::assertCount(FamiliesController::FIND_LIMIT, self::choices($many));
        self::assertSame(sprintf(Words::FAMILY_PICK['more'], FamiliesController::FIND_LIMIT), self::says($many));
        self::assertSame(sprintf('Bulk pod %03d', 0), self::choices($many)[array_key_first(self::choices($many))][2], 'by name');

        // Who: everyone who reads the family may search (catalogue.view); the picker itself is for whoever may add.
        $viewer = $this->signIn($this->uiUser('viewer'));
        self::assertSame([$c], array_keys(self::choices($viewer->get($path . '/find', ['q' => '5012345678900']))));
        self::assertFalse($viewer->get($path)->hasForm($path . '/pick'), 'no picker for someone who only looks');
        $nobody = $this->browser()->get($path . '/find', ['q' => 'crystal']);
        self::assertSame(303, $nobody->status, 'signed out: the sign-in page');
        self::assertStringStartsWith('/ui/login', (string) $nobody->location());
    }

    /**
     * The Codex review of 3047cb7, MEDIUM (FP11): with the bulk setting at its top (1,000), a field per tick passed PHP's max_input_vars
     * (998 ticks + the token + the key = 1,000 fields) and Kernel refused the whole form (400 form_truncated) before the ticks could come
     * back. The ticks are now ONE field: 998 ticks arrive whole and are put through the bulk list (here as items that do not exist, each
     * left out with its reason), and anything in the field that is not an id is left out.
     */
    public function testAThousandTicksArriveAsOneField(): void
    {
        $here = (new Families(self::$db))->create($this->staffUser('buyer'), 'Big family')['id'];
        $path = FamiliesController::PATH . '/' . $here;
        $web = $this->signIn($this->uiUser('buyer'));
        $before = self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'items.family_bulk_max_rows'");
        self::$db->exec("UPDATE app_setting SET value_json = '1000' WHERE setting_key = 'items.family_bulk_max_rows'");
        try {
            $form = $this->pickForm($web, $path);
            $many = range(900001, 900998);
            $fields = ['csrf' => $form['csrf'], 'form_key' => $form['form_key']];
            foreach ($many as $id) {
                $fields['pick_' . $id] = '1';
            }
            self::assertSame(1000, count($fields), 'the token, the key and 998 fields: the way of 3047cb7');
            $old = $web->post($path . '/pick', $fields);
            self::assertSame([400, 'form_truncated'], [$old->status, $old->errorCode()], 'a field a tick: the whole form refused (Kernel, I73)');
            $r = $web->post($path . '/pick', ['items' => implode(',', $many) . ',x,0,-3, 900001'] + $form);
            self::assertSame(200, $r->status, ($r->errorCode() ?? '') . ' ' . $r->describe());
            self::assertStringContainsString(sprintf(Words::FAMILY['result_skipped'], number_format(998)), $r->text(), 'every one read, each once; the rest left out');
            self::assertStringContainsString(Words::FAMILY_SKIP['not_found'], $r->text());
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = ? WHERE setting_key = 'items.family_bulk_max_rows'", [$before]);
        }
    }

    public function testTheTickedItemsArePutInTheFamilyThroughTheBulkListAndTheRefusalsKeepTheTicks(): void
    {
        $fam = new Families(self::$db);
        $buyer = $this->staffUser('buyer');
        $here = $fam->create($buyer, 'Elux Legend')['id'];
        $other = $fam->create($buyer, 'Lost Mary BM600')['id'];
        [$a, $b, $c] = [$this->item('legacy', 3, 'Legend Blue'), $this->item('legacy', 0, 'Legend Red'), $this->item('legacy', 0, 'Legend Mint')];
        $fam->assign($buyer, $c, $other, null);
        $fam->assign($buyer, $this->item('legacy', 0, 'Legend Kiwi'), $here, null); // not empty: Add items starts shut

        $web = $this->signIn($this->uiUser('buyer'));
        $path = FamiliesController::PATH . '/' . $here;
        $page = $web->get($path);
        self::assertSame(200, $page->status, $page->describe());
        $xp = self::xp($page);
        $picker = '//div[@class="doc-head"]//details[contains(@class, "family-add") and not(@open)]/div[contains(@class, "doc-act-panel")]'
            . '/form[@action="' . $path . '/pick" and @method="post" and @hidden and @data-fp and @data-options="' . $path . '/find?" and @data-max="200"'
            . ' and @data-unsaved="1" and @data-leaves="1" and not(@data-dirty)]';
        self::assertSame(1, $xp->query($picker)->length, 'the picker: one POST form, hidden until the script shows it');
        self::assertSame(1, $xp->query($picker . '//input[@type="search" and @data-fp-q and not(@name)]')->length, 'its search sends nothing');
        self::assertSame(1, $xp->query($picker . '//input[@type="hidden" and @name="items" and @value="" and @data-fp-items]')->length, 'the ticks\' one field');
        self::assertSame([sprintf(Words::FAMILY_PICK['button'], 0)], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array($xp->query($picker . '//button[@type="submit" and @disabled and contains(@class, "primary")]'))), 'its one button, off at none ticked');
        self::assertSame(1, $xp->query('//details[contains(@class, "family-add")]//details[contains(@class, "fp-paste") and @open and @data-fold]'
            . '/form[@action="' . $path . '/add"]//textarea[@name="list"]')->length, 'the pasted list: a fold under the picker, open without the script');

        // Three ticked: two put in, the one in another family left out with its name; ONE bulk change, audited.
        $form = $this->pickForm($web, $path);
        self::assertSame(['csrf', 'form_key', 'items'], array_keys($form), 'its fields: the token, its key and ONE field of the ticks (FP11)');
        self::assertSame('', $form['items'], 'nothing ticked yet');
        $sent = ['items' => "{$a},{$c},{$b}"] + $form;
        $r = $web->post($path . '/pick', $sent);
        self::assertSame(200, $r->status, ($r->errorCode() ?? '') . ' ' . $r->describe());
        self::assertStringContainsString(sprintf(Words::FAMILY['result_added'], 2), $r->text());
        self::assertStringContainsString(sprintf(Words::FAMILY['result_skipped'], 1), $r->text());
        self::assertStringContainsString(sprintf(Words::FAMILY_SKIP['in_other_family'], 'Lost Mary BM600'), $r->text());
        $now = array_map(static fn (array $f): int => $f['id'], Families::ofItems(self::$db, [$a, $b, $c]));
        ksort($now);
        self::assertSame([$a => $here, $b => $here, $c => $other], $now);
        self::assertSame([self::cw($a), self::cw($b)], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array(self::xp($r)->query('//ul[contains(@class, "result-added")]/li/a'))), 'in the order ticked');
        $audits = static fn (): int => (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'family.bulk_assign'");
        self::assertSame(1, $audits());
        $again = $web->post($path . '/pick', $sent);
        self::assertSame(200, $again->status);
        self::assertStringContainsString(sprintf(Words::FAMILY['result_added'], 2), $again->text(), 'sent twice: the first answer');
        self::assertSame(1, $audits(), 'done once');
        self::assertSame(0, self::xp($r)->query('//form[@data-dirty]')->length, 'the page after: nothing ticked, nothing unsaved');

        // Nothing ticked: refused before anything is read, said in words.
        $none = $web->post($path . '/pick', $this->pickForm($web, $path));
        self::assertSame(422, $none->status, $none->describe());
        self::assertSame('nothing_ticked', $none->errorCode());
        self::assertStringContainsString(Words::ITEMS_ERROR['nothing_ticked'], $none->text());
        self::assertSame(1, $audits());

        // More than the bulk setting allows: refused, nothing saved; the ticks come back in the picker (open, guarded as typed).
        [$x, $y, $z] = [$this->item('legacy', 0, 'Legend X'), $this->item('legacy', 0, 'Legend Y'), $this->item('legacy', 0, 'Legend Z')];
        $before = self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'items.family_bulk_max_rows'");
        self::$db->exec("UPDATE app_setting SET value_json = '2' WHERE setting_key = 'items.family_bulk_max_rows'");
        try {
            $page = $web->get($path);
            self::assertSame(1, self::xp($page)->query('//form[@action="' . $path . '/pick" and @data-max="2" and @data-too-many="' . sprintf(Words::FAMILY_PICK['too_many'], 2) . '"]')->length,
                'the limit the script refuses more than, and its words');
            $r = $web->post($path . '/pick', ['items' => "{$x},{$y},{$z}"] + $page->form($path . '/pick'));
            self::assertSame(422, $r->status, $r->describe());
            self::assertSame('too_many_rows', $r->errorCode());
            self::assertStringContainsString(sprintf(Words::FAMILY_PICK['too_many_sent'], 2), $r->text());
            self::assertSame([], Families::ofItems(self::$db, [$x, $y, $z]), 'nothing saved');
            $rx = self::xp($r);
            $back = '//details[contains(@class, "family-add") and @open]//form[@action="' . $path . '/pick" and @data-dirty="1"]';
            self::assertSame([(string) $x, (string) $y, (string) $z], array_map(static fn (\DOMElement $li): string => $li->getAttribute('data-sku'),
                iterator_to_array($rx->query($back . '//ul[@data-fp-ticked]/li'))), 'the ticks drawn again, in the order sent');
            self::assertSame("{$x},{$y},{$z}", $r->form($path . '/pick')['items'] ?? null, 'as the form\'s one field');
            self::assertSame('Legend X', trim((string) $rx->evaluate('string(' . $back . '//ul[@data-fp-ticked]/li[1]/span[@class="fp-t-name"])')));
            self::assertSame(0, $rx->query($back . '//ul[@data-fp-ticked]//input')->length, 'a ticked row has no field of its own');
            self::assertSame(sprintf(Words::FAMILY_PICK['button'], 3), trim((string) $rx->evaluate('string(' . $back . '//button[@type="submit"])')));
            self::assertSame(1, $rx->query('//form[@data-dirty]')->length, 'that form alone');
            self::assertSame(1, $rx->query('//details[contains(@class, "fp-paste") and @data-fold]')->length, 'the pasted list stays folded');
            self::assertSame(1, $audits());
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = ? WHERE setting_key = 'items.family_bulk_max_rows'", [$before]);
        }

        // Who may add: whoever keeps the families; the rest are refused before anything is read; every POST needs the token.
        $form = $this->pickForm($web, $path);
        foreach (['viewer', 'mapper', 'admin', 'goods_in'] as $role) {
            $them = $this->signIn($this->uiUser($role));
            $no = $them->post($path . '/pick', ['csrf' => $this->token($them), 'form_key' => str_repeat('a', 32), 'items' => (string) $x]);
            self::assertSame(403, $no->status, "{$role} POST pick");
            self::assertFalse($them->get($path)->hasForm($path . '/pick'), "{$role}: no picker");
        }
        $noToken = $web->post($path . '/pick', ['form_key' => $form['form_key'], 'items' => (string) $x]);
        self::assertSame(403, $noToken->status);
        self::assertSame('csrf', $noToken->errorCode());
        self::assertSame([], Families::ofItems(self::$db, [$x]), 'nothing saved by a refused POST');

        // A family switched off takes no new items: no picker, and a picker sent from an older page is refused.
        $fam->setActive($buyer, $here, 1, false);
        self::assertFalse($web->get($path)->hasForm($path . '/pick'));
        $off = $web->post($path . '/pick', ['items' => (string) $x] + $form);
        self::assertSame(422, $off->status, $off->describe());
        self::assertSame('family_off', $off->errorCode());
        self::assertSame([], Families::ofItems(self::$db, [$x]));
    }
}
