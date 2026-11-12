<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Catalogue\ItemNames;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * Changing an item's name on its page through the real /ui kernel as cw_app (docs/decisions.md IR1-IR6): the Name part of Details with
 * "Change the name" for the catalogue team only (catalogue.edit; a buyer, admin and auditor see the name and no form, and are refused 403
 * when they POST anyway); Save the name (one effect per form), its notice and the line in the page's history; every refusal in words with
 * what was typed kept and the form open (empty, too long, hidden characters; 409 when someone renamed it meanwhile, the page showing the
 * name it has now; an item joined into another); the same name writes nothing; Find by a name it had; markup that fits a 390 px phone.
 */
final class ItemNameScreensTest extends KernelUiTestCase
{
    private static function path(int $sku): string
    {
        return "/ui/items/{$sku}/name";
    }

    /** The Name part's form as a browser sends it. @return array<string, string> */
    private static function nameForm(UiResponse $page, int $sku): array
    {
        $f = $page->form(self::path($sku));
        self::assertSame(['csrf', 'form_key', 'name_was', 'name'], array_keys($f), $page->describe());
        return $f;
    }

    private static function xp(UiResponse $r): \DOMXPath
    {
        return new \DOMXPath($r->dom());
    }

    private static function squash(?string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $s));
    }

    /** Whether the Name part's fold is drawn open. */
    private static function open(UiResponse $r): bool
    {
        $d = self::xp($r)->query('//details[contains(concat(" ", @class, " "), " name-change ")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $d, 'the Name part\'s fold: ' . $r->describe());
        return $d->hasAttribute('open');
    }

    private function renames(int $sku): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'sku.rename' AND entity_id = ?", [(string) $sku]);
    }

    public function testTheFormIsForTheCatalogueTeamOnly(): void
    {
        $sku = $this->item('legacy', 3, 'Elux Legend Cola 20mg');
        foreach (['stock_controller', 'mapping_lead', 'purchasing_manager'] as $role) {
            $page = $this->signIn($this->uiUser($role))->get("/ui/items/{$sku}");
            self::assertSame(200, $page->status);
            $f = self::nameForm($page, $sku);
            self::assertSame(['Elux Legend Cola 20mg', 'Elux Legend Cola 20mg'], [$f['name_was'], $f['name']], $role);
            self::assertSame(1, self::xp($page)->query('//section[@id="card"]//div[contains(@class, "facts-name")]//details[contains(@class, "name-change")]//form')->length,
                "{$role}: the Name part of Details holds the form, behind \"Change the name\"");
            self::assertFalse(self::open($page), 'closed until asked');
            self::assertSame(Words::ITEM_NAME['change'], self::squash(self::xp($page)->evaluate('string(//details[contains(@class, "name-change")]/summary)')));
        }
        foreach ([['buyer', 'Buyer'], ['warehouse', 'Warehouse'], [['admin', 'auditor'], 'Admin · Auditor (look only)']] as [$roles, $jobs]) {
            $web = $this->signIn($this->uiUser($roles));
            $page = $web->get("/ui/items/{$sku}");
            self::assertSame(200, $page->status);
            self::assertFalse($page->hasForm(self::path($sku)), json_encode($roles) . ': no form');
            self::assertSame(0, self::xp($page)->query('//details[contains(@class, "name-change")]')->length);
            self::assertStringContainsString('Elux Legend Cola 20mg', $page->text(), 'everyone reads the name');
            $r = $web->post(self::path($sku), ['csrf' => $this->token($web), 'form_key' => str_repeat('b', 32), 'name_was' => 'Elux Legend Cola 20mg', 'name' => 'Hijacked']);
            self::assertSame(403, $r->status, $r->describe());
            self::assertStringContainsString(strtolower('Only ' . Words::whoCan('catalogue.edit') . ' can do this. Nothing was changed. You work as: ' . $jobs . '.'),
                strtolower($r->text()));
        }
        // An item joined into another: no form; a POST is refused in words.
        $keep = $this->item('legacy', 0, 'Kept');
        $gone = $this->item('legacy', 0, 'Joined');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$keep, $gone]);
        $web = $this->signIn($this->uiUser('stock_controller'));
        self::assertFalse($web->get("/ui/items/{$gone}")->hasForm(self::path($gone)));
        $r = $web->post(self::path($gone), ['csrf' => $this->token($web), 'form_key' => str_repeat('c', 32), 'name_was' => 'Joined', 'name' => 'New']);
        self::assertSame(409, $r->status, $r->describe());
        self::assertSame(Words::ITEMS_ERROR['merged_item'], self::squash(self::xp($r)->evaluate('string(//p[@class="error"])')), 'the item page\'s own words for it');
        // No CSRF: refused before anything.
        $f = self::nameForm($web->get("/ui/items/{$sku}"), $sku);
        unset($f['csrf']);
        self::assertSame([403, 'csrf'], [($r = $web->post(self::path($sku), ['name' => 'X'] + $f))->status, $r->errorCode()]);
        self::assertSame(['Elux Legend Cola 20mg', 0, 'Joined'], [(string) self::$db->value('SELECT name FROM sku WHERE id = ?', [$sku]), $this->renames($sku),
            (string) self::$db->value('SELECT name FROM sku WHERE id = ?', [$gone])]);
    }

    public function testSaveTheNameThenItsNoticeAndHistoryLine(): void
    {
        $sku = $this->item('legacy', 3, 'IVG 2400 Blue Raspberry Lemonade 20mg Prefilled Pod');
        $who = $this->uiUser('stock_controller');
        $web = $this->signIn($who);
        $f = self::nameForm($web->get("/ui/items/{$sku}"), $sku);
        $r = $web->post(self::path($sku), ['name' => '  IVG 2400   Blue Raspberry Lemonade 20mg '] + $f);
        self::assertSame("/ui/items/{$sku}?notice=name_saved", $r->location(), $r->describe());
        self::assertSame("/ui/items/{$sku}?notice=name_saved", $web->post(self::path($sku), ['name' => '  IVG 2400   Blue Raspberry Lemonade 20mg '] + $f)->location(),
            'the same form again: one rename');
        self::assertSame(['IVG 2400 Blue Raspberry Lemonade 20mg', 1], [(string) self::$db->value('SELECT name FROM sku WHERE id = ?', [$sku]), $this->renames($sku)]);
        $page = $web->follow($r);
        self::assertStringContainsString(Words::CARD_NOTICE['name_saved'], $page->text());
        self::assertSame('IVG 2400 Blue Raspberry Lemonade 20mg', self::squash(self::xp($page)->evaluate('string(//div[contains(@class, "item-head")]//h1/span[@class="stmt"])')),
            'the page\'s name is the new one');
        self::assertSame('IVG 2400 Blue Raspberry Lemonade 20mg', self::nameForm($page, $sku)['name_was']);
        $lines = array_map(static fn (\DOMNode $li): string => self::squash($li->textContent), iterator_to_array(self::xp($page)->query('//details[@id="it-history"]//li')));
        self::assertCount(1, $lines);
        self::assertStringContainsString(Words::say('ITEM_NAME', 'h_renamed', 'IVG 2400 Blue Raspberry Lemonade 20mg Prefilled Pod', 'IVG 2400 Blue Raspberry Lemonade 20mg'),
            $lines[0]);
        self::assertStringContainsString((string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$who['id']]), $lines[0], 'who');
        self::assertStringContainsString(Words::say('CARD', 'history', 1), $page->text());

        // A card change after it: both in the history, newest first.
        $card = $web->get("/ui/items/{$sku}/card")->form("/ui/items/{$sku}/card");
        foreach (['duty_liable', 'single_use'] as $k) {
            $card[$k] = '';
        }
        $c = $web->post("/ui/items/{$sku}/card", ['brand' => 'IVG'] + $card);
        self::assertSame(303, $c->status, $c->describe());
        $page = $web->get("/ui/items/{$sku}");
        $lines = array_map(static fn (\DOMNode $li): string => self::squash($li->textContent), iterator_to_array(self::xp($page)->query('//details[@id="it-history"]//li')));
        self::assertCount(2, $lines);
        self::assertStringContainsString(mb_strtolower(Words::of('CARD_FIELD', 'brand')) . ': ' . Words::CARD['h_empty'] . ' → IVG', $lines[0], 'the card change, newest');
        self::assertStringContainsString(Words::say('ITEM_NAME', 'h_renamed', 'IVG 2400 Blue Raspberry Lemonade 20mg Prefilled Pod', 'IVG 2400 Blue Raspberry Lemonade 20mg'),
            $lines[1]);

        // The same name: nothing written, said so.
        $same = $web->post(self::path($sku), ['name' => 'IVG 2400 Blue Raspberry Lemonade 20mg'] + self::nameForm($page, $sku));
        self::assertSame("/ui/items/{$sku}?notice=name_unchanged", $same->location());
        self::assertStringContainsString(Words::CARD_NOTICE['name_unchanged'], $web->follow($same)->text());
        self::assertSame(1, $this->renames($sku));

        // Find: by the name it had, said so.
        $found = $web->get('/ui/search', ['q' => 'prefilled pod lemonade']);
        self::assertSame(["/ui/items/{$sku}"], array_values(array_filter($found->hrefs(), static fn (string $h): bool => preg_match('#^/ui/items/[0-9]+$#D', $h) === 1)));
        self::assertStringContainsString(Words::say('SEARCH', 'was_called', 'IVG 2400 Blue Raspberry Lemonade 20mg Prefilled Pod'), $found->text());
        self::assertStringNotContainsString(Words::say('SEARCH', 'was_called', 'IVG 2400 Blue Raspberry Lemonade 20mg Prefilled Pod'),
            $web->get('/ui/search', ['q' => 'blue raspberry'])->text(), 'found by its name now: nothing more said');
    }

    public function testRefusalsInWordsKeepWhatWasTyped(): void
    {
        $sku = $this->item('legacy', 0, 'Old name');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $f = self::nameForm($web->get("/ui/items/{$sku}"), $sku);
        foreach ([['   ', 422, Words::CARD_ERROR['name_empty']], [str_repeat('n', 256), 422, Words::say('CARD_ERROR', 'name_too_long', 255, 256)],
            ["Bad\u{202E}name", 422, Words::CARD_ERROR['name_not_plain']]] as [$typed, $status, $words]) {
            $r = $web->post(self::path($sku), ['name' => $typed] + $f);
            self::assertSame($status, $r->status, $r->describe());
            self::assertSame($words, self::squash(self::xp($r)->evaluate('string(//p[@class="error"])')));
            self::assertTrue(self::open($r), 'the form is open where the refusal is');
            $again = self::nameForm($r, $sku);
            self::assertSame([$typed, 'Old name', $f['form_key']], [$again['name'], $again['name_was'], $again['form_key']], 'what was typed is kept, the same key');
        }
        self::assertSame(['Old name', 0], [(string) self::$db->value('SELECT name FROM sku WHERE id = ?', [$sku]), $this->renames($sku)]);

        // Someone renamed it meanwhile: 409, the page shows the name it has now and keeps what this person typed; sending it again saves it.
        (new ItemNames(self::$db))->rename(Caller::staff($this->uiUser('mapping_lead')['id']), $sku, 'Old name', 'Their name');
        $r = $web->post(self::path($sku), ['name' => 'My name'] + $f);
        self::assertSame(409, $r->status, $r->describe());
        self::assertSame(Words::say('CARD_ERROR', 'name_changed', 'Their name'), self::squash(self::xp($r)->evaluate('string(//p[@class="error"])')));
        self::assertSame('Their name', self::squash(self::xp($r)->evaluate('string(//div[contains(@class, "facts-name")]//p[contains(@class, "item-name-now")])')));
        self::assertTrue(self::open($r));
        $again = self::nameForm($r, $sku);
        self::assertSame(['My name', 'Their name'], [$again['name'], $again['name_was']]);
        $ok = $web->post(self::path($sku), $again);
        self::assertSame("/ui/items/{$sku}?notice=name_saved", $ok->location(), $ok->describe());
        self::assertSame(['My name', 2], [(string) self::$db->value('SELECT name FROM sku WHERE id = ?', [$sku]), $this->renames($sku)]);

        // A form key used for one name and sent again with another: refused in words, a new key drawn.
        $r = $web->post(self::path($sku), ['name' => 'Third name'] + $again);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::error('idempotency_key_reused'), $r->text());
        self::assertNotSame($again['form_key'], self::nameForm($r, $sku)['form_key']);
        self::assertSame('My name', (string) self::$db->value('SELECT name FROM sku WHERE id = ?', [$sku]));
    }

    public function testAPhoneWidthFormAndHostileTextShownAsText(): void
    {
        $sku = $this->item('legacy', 0, 'Plain');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $r = $web->post(self::path($sku), ['name' => '<script>alert(1)</script> "><b>x'] + self::nameForm($web->get("/ui/items/{$sku}"), $sku));
        self::assertSame(303, $r->status, $r->describe());
        $page = $web->follow($r);
        self::assertStringNotContainsString('<script>alert', $page->body);
        self::assertStringNotContainsString('"><b>x', $page->body);
        self::assertSame('<script>alert(1)</script> "><b>x', self::nameForm($page, $sku)['name_was']);
        $xp = self::xp($page);
        // The form: a label for the one box, the box as wide as the column, the column's maximum, the button under it; no script of its own.
        $form = $xp->query('//form[contains(@action, "/name")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        self::assertStringContainsString('record', $form->getAttribute('class'));
        self::assertStringContainsString('item-name-form', $form->getAttribute('class'));
        $box = $xp->query('.//input[@name="name"]', $form)->item(0);
        self::assertInstanceOf(\DOMElement::class, $box);
        self::assertSame(['text', (string) ItemNames::NAME_MAX, ''], [$box->getAttribute('type'), $box->getAttribute('maxlength'), $box->getAttribute('style')]);
        self::assertSame(1, $xp->query('.//label[@for="' . $box->getAttribute('id') . '"]', $form)->length);
        self::assertSame(Words::ITEM_NAME['save'], self::squash($xp->evaluate('string(.//button[@type="submit"])', $form)));
        self::assertSame(0, $xp->query('.//script | .//*[@style]', $form)->length);
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/ui/assets/app.css');
        self::assertMatchesRegularExpression('/@container \(max-width: 640px\) \{[^}]*form\.item-name-form button \{ width: 100%; \}/s', $css, 'the button is the phone\'s width');
        self::assertDoesNotMatchRegularExpression('/\.item-name-form[^{]*\{[^}]*\b(min-)?width: \d{3,}px/', $css, 'nothing wider than a phone');
    }
}
