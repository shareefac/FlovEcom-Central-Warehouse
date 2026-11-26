<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Support\KernelUiTestCase;

/**
 * CW-1153 B1 (docs/decisions.md VB4) through the real /ui kernel as the app login: Settings > Own items. A reviewer adds one of Vape Big's
 * own items with a reason and takes it off again (its history keeps both, with who and why); the words never say "partner"; someone who
 * may only look sees the list but no form, and a hand-made POST is refused.
 */
final class OwnItemsScreensTest extends KernelUiTestCase
{
    private const PAGE = '/ui/reference/own-items';

    private static function text(\CW\Tests\Support\UiResponse $r): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $r->text()));
    }

    public function testAReviewerAddsAnItemAndTakesItOffAndTheHistoryKeepsBoth(): void
    {
        $vb = $this->site('vapebig');
        $sku = $this->item('legacy', 12, 'Hayati 4000 Strawberry Ice');
        $this->listing($vb, 'VB-1', $sku);
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        $web = $this->signIn($this->uiUser('reviewer'));
        $page = $web->get(self::PAGE);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString('VAPEBIG test site\'s own items', self::text($page));
        $r = $web->post(self::PAGE . '/add', ['code' => $code, 'reason' => 'bought with Vape Big\'s own money'] + $page->form(self::PAGE . '/add', true));
        self::assertSame(303, $r->status, $r->describe());
        $page = $web->get(self::PAGE);
        $t = self::text($page);
        self::assertStringContainsString($code, $t);
        self::assertStringContainsString('1 items, 12 units on our shelves', $t);
        self::assertStringNotContainsStringIgnoringCase('partner', $page->body);
        // a typo is refused on the page in plain words, nothing added
        $bad = $web->post(self::PAGE . '/add', ['code' => 'CW-999999', 'reason' => 'a typo'] + $page->form(self::PAGE . '/add', true));
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('there is no item CW-999999', self::text($bad));
        $r = $web->post(self::PAGE . '/remove', ['code' => $code, 'reason' => 'ours again'] + $page->form(self::PAGE . '/remove'));
        self::assertSame(303, $r->status, $r->describe());
        $t = self::text($web->get(self::PAGE));
        self::assertStringContainsString('No item is on the list.', $t);
        self::assertStringContainsString('added by', $t);
        self::assertStringContainsString('Taken off', $t);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM item_owner WHERE to_at IS NOT NULL'));
    }

    public function testSomeoneWhoMayOnlyLookSeesNoFormAndAHandMadePostIsRefused(): void
    {
        $this->site('vapebig');
        $sku = $this->item('legacy', 0, 'Elux Legend 4000 Kiwi');
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $page = $web->get(self::PAGE);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringNotContainsString('/ui/reference/own-items/add', $page->body);
        $r = $web->post(self::PAGE . '/add', ['code' => $code, 'reason' => 'trying', 'csrf' => $this->token($web)]);
        self::assertSame(403, $r->status);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_owner'));
    }
}
