<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Integration\Reorder\ReorderFixtures;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\AuthController;
use CW\Ui\Duplicates;
use CW\Ui\Html;
use CW\Ui\Words;

/**
 * The behaviour items of plan §8.6 built as provisional defaults (owner to confirm, docs/decisions.md), through the real /ui kernel:
 *  1 back to the page asked for after signing in (a safe local page only), and "NOT saved" for a form sent while signed out;
 *  2 GET /ui/logout leads on and signs nobody out;
 *  3 a sign-in form older than its cookie is shown again with a plain word;
 *  6 the cancel and correct reasons start with "— choose a reason —" and one must be chosen;
 *  8 goods-in and the purchasing desk see the orders list without drafts unless they ask;
 *  9 the supplier form keeps what was typed after someone else saved, and marks what they changed;
 * 11 a website product's sales come from the sales history when it is loaded (as on Possible duplicates);
 * 13 an already matched website product has no answer form (a matching lead can still change it, folded).
 * Item 4 (the tick-box before "Stop this person signing in") is in PeopleScreenTest, item 5 (only the spot check's owner answers
 * its matches) in KeySampleScreenTest.
 */
final class BehaviourItemsTest extends KernelUiTestCase
{
    use ReorderFixtures;

    protected function tearDown(): void
    {
        $this->cleanReorderFixtures();
        parent::tearDown();
    }

    // ---- 1, 2, 3: signing in ------------------------------------------------------------------------------------------

    public function testOnlyASafeLocalPageIsReturnedTo(): void
    {
        foreach (['/ui/search?q=elux', '/ui/purchasing/orders', '/ui/review/listing/12?queue=Key&fq=1', '/ui/review?queue=Key&page=2',
            '/ui/purchasing/suppliers/3/edit', '/ui/search?q=blue%20razz'] as $ok) {
            self::assertSame($ok, AuthController::safeBack($ok), $ok);
        }
        foreach ([null, '', '/ui', '/ui/', '/ui/?notice=x', '//evil.example/ui/', 'https://evil.example/ui/search', '/ui//evil.example', '/ui/../etc/passwd',
            '/ui/./search', "/ui/search\r\nSet-Cookie: x=1", '/ui/search\\..\\x', '/ui/login', '/ui/login?back=/ui/search', '/ui/logout', '/ui/password',
            '/ui/purchasing/orders.csv', '/ui/purchasing/orders/1/pdf', '/ui/documents/4/pdf?x=1', '/ui/reference/company/sample.pdf', '/ui/purchasing/orders/1/lines.xlsx', '/ui/files/3', '/ui/assets/app.css',
            '/ui/search?q=<script>', 'javascript:alert(1)', '/elsewhere', '/ui/search?q=' . str_repeat('a', 600)] as $bad) {
            self::assertNull(AuthController::safeBack($bad), var_export($bad, true));
        }
    }

    public function testSigningInLeadsBackToThePageThatWasAskedFor(): void
    {
        $user = $this->uiUser('buyer');
        $web = $this->browser();
        $r = $web->get('/ui/purchasing/orders', ['state' => 'sent', 'notice' => 'created']);
        self::assertSame(303, $r->status);
        self::assertSame('/ui/login?back=' . rawurlencode('/ui/purchasing/orders?state=sent'), $r->location(), 'the page asked for, without an old notice');
        $page = $web->follow($r);
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame('/ui/purchasing/orders?state=sent', $page->form('/ui/login')['back'] ?? null, 'the form carries it');
        $in = $this->submitSignIn($web, $page, $user);
        self::assertSame(303, $in->status, $in->describe());
        self::assertSame('/ui/purchasing/orders?state=sent', $in->location());
        self::assertSame(200, $web->follow($in)->status);

        // Signed in already (another tab): the sign-in address leads straight on.
        self::assertSame('/ui/search', $web->get('/ui/login', ['back' => '/ui/search'])->location());
        self::assertSame('/ui/', $web->get('/ui/login', ['back' => '//evil.example/'])->location());

        // A back that is not a safe page is dropped, here and in the form: Home.
        $other = $this->browser('198.51.100.31');
        $page = $other->get('/ui/login', ['back' => 'https://evil.example/ui/search']);
        self::assertArrayNotHasKey('back', $page->form('/ui/login'));
        $forged = $this->submitSignIn($other, $page, $user, ['back' => '//evil.example/x']);
        self::assertSame('/ui/', $forged->location());

        // A session that ended: the page asked for, and why.
        self::$db->exec('UPDATE staff_session SET last_seen_at = NOW(6) - INTERVAL 31 MINUTE');
        $r = $web->get('/ui/purchasing/orders');
        self::assertSame('/ui/login?why=signed_out&back=' . rawurlencode('/ui/purchasing/orders'), $r->location());
        $page = $web->follow($r);
        self::assertStringContainsString(Words::UI['signed_out'], $page->text());
        // A forced password change still comes first.
        self::$db->exec('UPDATE staff_user SET password_must_change = 1 WHERE id = ?', [$user['id']]);
        self::assertSame('/ui/password', $this->submitSignIn($web, $page, $user)->location());
    }

    public function testAFormSentWhileSignedOutIsNotSavedAndTheSignInPageSaysSo(): void
    {
        $site = $this->site('vpg');
        $sku = $this->item('legacy', 0, 'Lost Target');
        $listing = $this->queued($site, 'L1', 'Key', $sku, ['product_title' => 'Lost Target', 'units_30d' => 3]);
        $user = $this->uiUser('mapper');
        $web = $this->signIn($user);
        $form = $this->decideForm($web, $listing, ['queue' => 'Key']);
        $decisions = $this->decisions();
        self::$db->exec('UPDATE staff_session SET last_seen_at = NOW(6) - INTERVAL 31 MINUTE');

        $page = 'http://cw-ui.review.invalid/ui/review/listing/' . $listing . '?queue=Key';
        $r = $web->post('/ui/review/listing/' . $listing . '/decide', $form, ['referer' => $page]);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('/ui/login?why=lost&back=' . rawurlencode('/ui/review/listing/' . $listing . '?queue=Key'), $r->location(),
            'back to the page the form was on');
        self::assertSame('suggested', $this->link($listing)['status'], 'nothing was saved');
        self::assertSame($decisions, $this->decisions());
        $login = $web->follow($r);
        self::assertSame(200, $login->status);
        $xp = new \DOMXPath($login->dom());
        self::assertSame(Words::UI['lost'], trim((string) $xp->evaluate('string(//div[contains(@class, "lost") and @role="alert"]/p)')), 'said first, as an alert');
        $in = $this->submitSignIn($web, $login, $user);
        self::assertSame('/ui/review/listing/' . $listing . '?queue=Key', $in->location());
        self::assertTrue($web->follow($in)->hasForm('/decide'), 'the form is there to send again');

        // From another site's page, or with no Referer: no way back, still the warning.
        $stranger = $this->browser('198.51.100.32');
        $r = $stranger->post('/ui/review/listing/' . $listing . '/decide', $form, ['referer' => 'https://evil.example/ui/search']);
        self::assertSame('/ui/login?why=lost', $r->location());
        self::assertStringContainsString(Words::UI['lost'], $stranger->follow($r)->text());
        // Signing out without a session loses nothing.
        self::assertSame('/ui/login', $stranger->post('/ui/logout', ['csrf' => 'x'])->location());
    }

    public function testTheSignOutAddressOpenedFromTheHistoryLeadsOnAndSignsNobodyOut(): void
    {
        $user = $this->uiUser('viewer');
        $web = $this->signIn($user);
        $r = $web->get('/ui/logout');
        self::assertSame([303, '/ui/'], [$r->status, $r->location()]);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM staff_session WHERE revoked = 1'), 'a GET signs nobody out');
        self::assertSame(200, $web->get('/ui/')->status);
        $out = $web->post('/ui/logout', ['csrf' => $this->token($web)]);
        self::assertSame('/ui/login', $out->location());
        $r = $web->get('/ui/logout');
        self::assertSame([303, '/ui/login'], [$r->status, $r->location()], 'signed out: the sign-in page, not "use the button"');
        self::assertSame(200, $web->follow($r)->status);
        $fresh = $this->browser('198.51.100.33')->get('/ui/logout');
        self::assertSame([303, '/ui/login'], [$fresh->status, $fresh->location()], 'a browser that never signed in');
    }

    public function testASignInFormOpenTooLongIsShownAgainWithThePersonsEmail(): void
    {
        $user = $this->uiUser('viewer');
        $web = $this->browser();
        $page = $web->get('/ui/login', ['back' => '/ui/search']);
        $fields = $page->form('/ui/login');
        unset($web->cookies['cw_pre']); // the form's cookie lives one hour: gone

        $r = $web->post('/ui/login', ['email' => $user['email'], 'password' => $user['password'], 'code' => self::code($user['secret'])] + $fields);
        self::assertSame(403, $r->status, 'refused like any other token');
        self::assertStringContainsString(Words::SIGN_IN['expired'], $r->text());
        self::assertSame('csrf', $r->errorCode(), 'the code stays for support and tests');
        $again = $r->form('/ui/login');
        self::assertSame($user['email'], $again['email'] ?? null, 'the e-mail is kept');
        self::assertSame('', $again['password'] ?? '', 'never the password');
        self::assertSame('/ui/search', $again['back'] ?? null, 'and the way back');
        self::assertNotNull($r->setCookie('cw_pre'), 'a new form cookie');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM login_attempt'), 'no sign-in was tried');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM staff_session'));

        $in = $this->submitSignIn($web, $r, $user);
        self::assertSame([303, '/ui/search'], [$in->status, $in->location()], $in->describe());
    }

    // ---- 6: cancel and correct reasons --------------------------------------------------------------------------------

    public function testCancelAndCorrectNeedAReasonChosenByThePerson(): void
    {
        ['buyer' => $buyerUser, 'draft' => $draft, 'confirmed' => $confirmed] = $this->orders();
        $buyer = $this->signIn($buyerUser);
        $page = $buyer->get('/ui/purchasing/orders/' . $confirmed);
        self::assertSame(200, $page->status, $page->describe());
        $xp = new \DOMXPath($page->dom());
        foreach (['/cancel', '/amend'] as $action) {
            $select = '//form[contains(@action, "' . $action . '")]//select[@name="reason_code"]';
            self::assertSame(1, $xp->query($select . '[@required]')->length, "{$action}: a choice is required");
            self::assertSame('', (string) $xp->evaluate('string(' . $select . '/option[1]/@value)'), $action);
            self::assertSame(Words::ORDER['choose_reason'], trim((string) $xp->evaluate('string(' . $select . '/option[1])')), $action);
            self::assertSame(0, $xp->query($select . '/option[@selected]')->length, "{$action}: nothing chosen for the person");
            self::assertSame('', $page->form('/ui/purchasing/orders/' . $confirmed . $action)['reason_code'], $action);
        }
        // Sent without a choice (a browser that skips `required`): refused, nothing changes, and that form opens again.
        $r = $buyer->post('/ui/purchasing/orders/' . $confirmed . '/cancel', $page->form('/ui/purchasing/orders/' . $confirmed . '/cancel'));
        self::assertSame(400, $r->status, $r->describe());
        self::assertStringContainsString(Words::BUY_ERROR['bad_reason'], $r->text());
        self::assertSame(1, (new \DOMXPath($r->dom()))->query('//details[@open]//form[contains(@action, "/cancel")]')->length, 'the cancel form is open');
        $am = $page->form('/ui/purchasing/orders/' . $confirmed . '/amend');
        $r = $buyer->post('/ui/purchasing/orders/' . $confirmed . '/amend', $am);
        self::assertSame(400, $r->status, $r->describe());
        self::assertStringContainsString(Words::BUY_ERROR['bad_reason'], $r->text());
        self::assertSame(1, (new \DOMXPath($r->dom()))->query('//details[@open]//form[contains(@action, "/amend")]')->length, 'the correct form is open');
        self::assertSame(['posted', 0], [self::$db->value('SELECT status FROM document WHERE id = ?', [$confirmed]),
            (int) self::$db->value('SELECT COUNT(*) FROM document WHERE reverses_id = ?', [$confirmed])], 'nothing was cancelled');
        // The same form with a reason chosen goes through (a refusal stored nothing under its form key).
        $ok = $buyer->post('/ui/purchasing/orders/' . $confirmed . '/amend', ['reason_code' => 'po_amended'] + $am);
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame('po_amended', self::$db->value('SELECT reason_code FROM document WHERE reverses_id = ?', [$confirmed]));

        // The draft editor's cancel list starts the same way.
        $ed = $buyer->get('/ui/purchasing/orders/' . $draft);
        self::assertSame('', $ed->form('/ui/purchasing/orders/' . $draft . '/cancel')['reason_code']);
        self::assertSame(Words::ORDER['choose_reason'], trim((string) (new \DOMXPath($ed->dom()))->evaluate(
            'string(//form[contains(@action, "/cancel")]//select[@name="reason_code"]/option[1])')));
        $r = $buyer->post('/ui/purchasing/orders/' . $draft . '/cancel', $ed->form('/ui/purchasing/orders/' . $draft . '/cancel'));
        self::assertSame(400, $r->status, $r->describe());
        self::assertSame('draft', self::$db->value('SELECT status FROM document WHERE id = ?', [$draft]));
    }

    // ---- 8: drafts on the orders list ---------------------------------------------------------------------------------

    public function testGoodsInAndTheDeskSeeNoDraftsUnlessTheyAsk(): void
    {
        ['buyer' => $buyerUser, 'draft' => $draft, 'confirmed' => $confirmed] = $this->orders();
        $draftHref = '/ui/purchasing/orders/' . $draft;
        $confirmedHref = '/ui/purchasing/orders/' . $confirmed;
        foreach (['goods_in', 'purchasing_desk'] as $n => $role) {
            $web = $this->signIn($this->uiUser($role), $this->browser('198.51.100.' . (40 + $n)));
            $list = $web->get('/ui/purchasing/orders');
            self::assertSame(200, $list->status, $list->describe());
            self::assertContains($confirmedHref, $list->hrefs(), $role);
            self::assertNotContains($draftHref, $list->hrefs(), "{$role}: no draft by default");
            self::assertStringContainsString(Words::ORDERS['drafts_hidden'], $list->text(), $role);
            self::assertContains('/ui/purchasing/orders?drafts=1', $list->hrefs(), "{$role}: a way to see them");
            self::assertSame(1, (new \DOMXPath($list->dom()))->query('//form[@method="get"]//input[@name="drafts" and @type="checkbox" and not(@checked)]')->length, $role);
            self::assertContains('/ui/purchasing/orders.csv?drafts=0', $list->hrefs(), "{$role}: the download is the same list");
            $all = $web->get('/ui/purchasing/orders', ['drafts' => '1']);
            self::assertContains($draftHref, $all->hrefs(), "{$role}: asked for");
            self::assertStringNotContainsString(Words::ORDERS['drafts_hidden'], $all->text(), $role);
            self::assertContains($draftHref, $web->get('/ui/purchasing/orders', ['state' => 'draft'])->hrefs(), "{$role}: the Drafts filter shows them");
            $csv = $web->get('/ui/purchasing/orders.csv', ['drafts' => '0']);
            self::assertSame(200, $csv->status);
            self::assertStringNotContainsString('draft #' . $draft, $csv->body, $role);
            self::assertStringContainsString('draft #' . $draft, $web->get('/ui/purchasing/orders.csv')->body, "{$role}: the CSV alone is unchanged");
        }
        // People who make orders, and everyone else, see drafts as before (no choice offered).
        foreach ([$buyerUser, $this->uiUser('reviewer'), $this->uiUser(['goods_in', 'buyer'])] as $n => $who) {
            $web = $this->signIn($who, $this->browser('198.51.100.' . (50 + $n)));
            $list = $web->get('/ui/purchasing/orders');
            self::assertContains($draftHref, $list->hrefs(), implode('+', $who['roles']));
            self::assertStringNotContainsString(Words::ORDERS['drafts_hidden'], $list->text());
            self::assertSame(0, (new \DOMXPath($list->dom()))->query('//input[@name="drafts"]')->length);
        }
    }

    // ---- 9: the supplier form after someone else saved ----------------------------------------------------------------

    public function testTheSupplierFormKeepsWhatWasTypedAndMarksWhatSomeoneElseChanged(): void
    {
        $a = $this->uiUser('buyer');
        $b = $this->uiUser('buyer');
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($a['id']), ['name' => 'Meanwhile Supplies', 'email' => 'old@meanwhile.example', 'phone' => '0161 000 0001', 'notes' => 'first note']);
        $id = (int) $s['id'];
        $web = $this->signIn($a);
        $edit = $web->get("/ui/purchasing/suppliers/{$id}/edit");
        $form = $edit->form("/ui/purchasing/suppliers/{$id}", true);
        self::assertSame('1', $form['version']);
        // B saves first: a new phone, and a note.
        $sup->update(Caller::staff($b['id']), $id, 1, ['phone' => '0161 222 3333', 'notes' => 'B checked the address']);
        $bName = (string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$b['id']]);

        // A sends a new e-mail (B left it alone) and a note of their own (B changed it too); A did not touch the phone.
        $r = $web->post("/ui/purchasing/suppliers/{$id}", ['email' => 'orders@meanwhile.example', 'notes' => 'A rang them'] + $form);
        self::assertSame(409, $r->status, $r->describe());
        $at = Html::when((string) self::$db->value('SELECT updated_at FROM supplier WHERE id = ?', [$id]));
        self::assertStringContainsString(Words::say('SUPPLIER_FORM', 'changed_meanwhile', $bName, $at), $r->text());
        $again = $r->form("/ui/purchasing/suppliers/{$id}", true);
        self::assertSame('2', $again['version'], 'Save works again');
        self::assertSame('orders@meanwhile.example', $again['email'], 'what A typed is kept');
        self::assertSame('A rang them', $again['notes'], 'also where B changed it too');
        self::assertSame('0161 222 3333', $again['phone'], 'B\'s change where A typed nothing');
        $xp = new \DOMXPath($r->dom());
        self::assertSame(Words::say('SUPPLIER_FORM', 'theirs_taken', $bName), trim((string) $xp->evaluate('string(//p[@id="t-phone"])')));
        self::assertSame(Words::say('SUPPLIER_FORM', 'theirs_kept', $bName, 'B checked the address'), trim((string) $xp->evaluate('string(//p[@id="t-notes"])')));
        self::assertSame(0, $xp->query('//p[@id="t-email"]')->length, 'B did not change the e-mail');
        self::assertSame(2, $xp->query('//div[@role="alert"]//ul[contains(@class, "changes")]/li')->length, 'both listed at the top');
        $kept = $sup->get($id);
        self::assertSame(['old@meanwhile.example', 'B checked the address'], [$kept['email'], $kept['notes']], 'nothing of A\'s was saved');

        $ok = $web->post("/ui/purchasing/suppliers/{$id}", $again);
        self::assertSame(303, $ok->status, $ok->describe());
        $now = $sup->get($id);
        self::assertSame(['orders@meanwhile.example', '0161 222 3333', 'A rang them'], [$now['email'], $now['phone'], $now['notes']]);
    }

    // ---- 11, 13: one website product ----------------------------------------------------------------------------------

    public function testAWebsiteProductsSalesComeFromTheSalesHistoryLikeOnPossibleDuplicates(): void
    {
        $vpg = $this->site('vapeandgo', 'off');
        $alt = $this->site('electrofag');
        $sku = $this->item('legacy', 0, 'History Target');
        $loaded = $this->queued($vpg, '601', 'Check', $sku, ['product_title' => 'History product', 'units_30d' => 0, 'units_365d' => 0]);
        $this->history((int) $vpg->channelId, '2026-07-04', '2026-10-01', self::daily('601', '2026-07-04', '2026-10-01', 5));
        $own = $this->queued($alt, '77', 'Check', $sku, ['product_title' => 'Own figures product', 'units_30d' => 7, 'units_365d' => 70]);
        $web = $this->signIn($this->uiUser('mapper'));

        $page = $web->get('/ui/review/listing/' . $loaded);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::say('LISTING', 'sold_line', 150, 450) . ' ' . Words::say('DUPS', 'from_history', '1 Oct 2026'), self::squash($page->text()));
        $dup = (new Duplicates(self::$db))->listings([$loaded])[$loaded];
        self::assertSame([150, 450], [$dup['units_30d'], $dup['units_365d']], 'the same numbers as Possible duplicates');
        $page = $web->get('/ui/review/listing/' . $own);
        self::assertStringContainsString(Words::say('LISTING', 'sold_line', 7, 70) . ' ' . Words::DUPS['from_profile'], self::squash($page->text()),
            'no sales loaded for this site: its own figures, said so');
    }

    public function testAMatchedWebsiteProductHasNothingToDecide(): void
    {
        $site = $this->site('vpg');
        $alt = $this->site('alt');
        $sku = $this->item('legacy', 0, 'Matched Blue Razz');
        $other = $this->item('legacy', 0, 'Other Blue Razz');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, units_per_scan) VALUES ('5012345000028', ?, 1)", [$sku]);
        $id = $this->profiled($site, 'M1', ['product_title' => 'Matched', 'variant_title' => 'Blue Razz', 'barcodes' => ['5012345000028']], $sku);
        $this->profiled($alt, 'M2', ['product_title' => 'Matched Blue Razz', 'barcodes' => ['5012345000028']], $sku);
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        $note = Words::say('LISTING', 'matched_nothing', $code . ' Matched Blue Razz', Words::saleUses(1));

        $mapper = $this->signIn($this->uiUser('mapper'));
        $page = $mapper->get('/ui/review/listing/' . $id);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString($note . ' ' . Words::LISTING['matched_ask'], $page->text());
        self::assertFalse($page->hasForm('/decide'), 'no answer form and no quick yes');
        self::assertStringNotContainsString(Words::LISTING['pick_other'], $page->text());
        self::assertStringNotContainsString(Words::LISTING['elsewhere'], $page->text(), 'its own product is no "may duplicate"');
        self::assertStringContainsString(Words::LISTING['matched_heading'], $page->text(), 'compared with the product it is matched to');
        self::assertStringContainsString(Words::LISTING['compare'], $page->text());

        // A matching lead: the same note, and "Change this match" folded with the answers (none about the matched product itself).
        $lead = $this->signIn($this->uiUser('mapping_lead'), $this->browser('198.51.100.60'));
        $page = $lead->get('/ui/review/listing/' . $id);
        self::assertStringContainsString($note . ' ' . Words::LISTING['matched_lead'], $page->text());
        $xp = new \DOMXPath($page->dom());
        self::assertSame(1, $xp->query('//details[@id="change-match" and not(@open)]//form[contains(@action, "/decide")]')->length, 'folded');
        self::assertSame(1, $xp->query('//details[@id="change-match"]//input[@name="action" and @value="link" and @disabled]')->length, 'pick another product first');
        self::assertSame(1, $xp->query('//details[@id="change-match"]//input[@name="action" and @value="reject" and @disabled]')->length);
        self::assertStringContainsString(Words::LISTING['pick_other'], $page->text());
        $picked = $lead->get('/ui/review/listing/' . $id, ['pick' => (string) $other]);
        $xp = new \DOMXPath($picked->dom());
        self::assertSame(1, $xp->query('//details[@id="change-match" and @open]')->length, 'open once a product is picked');
        $form = $picked->form('/ui/review/listing/' . $id . '/decide');
        self::assertSame((string) $other, $form['sku_id']);
        $r = $lead->post('/ui/review/listing/' . $id . '/decide', ['action' => 'link'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame($other, (int) $this->link($id)['sku_id'], 'the lead changed the match');

        // A website product not matched yet keeps its answer form (the control).
        $open = $this->queued($site, 'M3', 'Key', $other, ['product_title' => 'Not matched yet']);
        self::assertTrue($mapper->get('/ui/review/listing/' . $open)->hasForm('/decide'));
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------

    /**
     * Sends the sign-in form of $page for $user (with $over replacing fields).
     *
     * @param array{id: int, email: string, password: string, secret: string} $user
     * @param array<string, string> $over
     */
    private function submitSignIn(KernelBrowser $web, UiResponse $page, array $user, array $over = []): UiResponse
    {
        self::$db->exec('UPDATE staff_user SET totp_last_step = NULL WHERE id = ?', [$user['id']]);
        return $web->post('/ui/login', $over + ['email' => $user['email'], 'password' => $user['password'], 'code' => self::code($user['secret'])] + $page->form('/ui/login'));
    }

    /**
     * An approved supplier of a buyer's, a draft order and a confirmed one (through the services).
     *
     * @return array{buyer: array{id: int, email: string, roles: list<string>, password: string, secret: string}, draft: int, confirmed: int}
     */
    private function orders(): array
    {
        $buyer = $this->uiUser('buyer');
        $reviewer = $this->uiUser('reviewer');
        $sup = new Suppliers(self::$db);
        $made = $sup->create(Caller::staff($buyer['id']), ['name' => 'Behaviour Supplies', 'address_line1' => '2 Mill Lane', 'postcode' => 'M1 1AA',
            'email' => 'sales@behaviour.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400), 'dd_checked_by' => (string) $buyer['id'],
            'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $made = $sup->requestActivation(Caller::staff($buyer['id']), (int) $made['id'], (int) $made['version']);
        $sup->approve(Caller::staff($reviewer['id']), (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'",
            [(int) $made['id']]), null);
        $sku = self::makeSku('Behaviour pod 2ml');
        $si = (new SupplierItems(self::$db))->create(Caller::staff($buyer['id']), (int) $made['id'], $sku, ['units_per_pack' => '10', 'supplier_code' => 'BH-10'],
            ['pack_price' => '12.50']);
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $line = ['kind' => 'item', 'sku_id' => $sku, 'supplier_item_id' => (int) $si['id'], 'units_per_pack' => 10, 'packs' => 2, 'pack_price' => '12.50'];
        $draft = $pos->createDraft(Caller::staff($buyer['id']), (int) $made['id'], []);
        $pos->saveDraft(Caller::staff($buyer['id']), $draft->id, $draft->version, [], [$line]);
        $confirmed = $pos->createDraft(Caller::staff($buyer['id']), (int) $made['id'], []);
        $confirmed = $pos->saveDraft(Caller::staff($buyer['id']), $confirmed->id, $confirmed->version, [], [$line]);
        $confirmed = $pos->approve(Caller::staff($buyer['id']), $confirmed->id, $confirmed->version);
        return ['buyer' => $buyer, 'draft' => $draft->id, 'confirmed' => $confirmed->id];
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
