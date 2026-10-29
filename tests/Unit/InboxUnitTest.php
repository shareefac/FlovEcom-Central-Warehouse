<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\Ui\Assets;
use CW\Ui\Controller\InboxController;
use CW\Ui\Sections;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * Approvals › Waiting for you (docs/decisions.md AV1-AV14), without a database: who sees the tab (the holders of any kind's permission,
 * exactly the people the page lets in), the kinds' words and colours, the section's count, the approved design's layout as CSS (a phone
 * first shows the open record; 44 px rows), and the templates (every tag closed; no script, no style).
 */
final class InboxUnitTest extends TestCase
{
    public function testTheTabIsForThePeopleWhoAnswerAnyKindAndItCountsTheSection(): void
    {
        $perms = array_values(array_unique(array_merge(...array_map(static fn (array $k): array => $k['perms'], array_values(InboxController::KINDS)))));
        sort($perms);
        $listed = InboxController::PERMS;
        sort($listed);
        self::assertSame($perms, $listed, 'PERMS is every kind\'s permission, once');
        self::assertSame(InboxController::PERMS, Sections::MAP['approvals']['tabs']['inbox']['pages'][0]['perm']);
        self::assertSame('inbox', array_key_first(Sections::MAP['approvals']['tabs']), 'the menu\'s Approvals entry opens the inbox');
        self::assertSame('inbox', Sections::MAP['approvals']['count']);
        self::assertSame(['corrections', 'suspense', 'counts', 'oversold', 'modes', 'landing', 'staff', 'documents'], array_keys(InboxController::KINDS), 'the brief\'s order');
        foreach (Permissions::ROLES as $role) {
            self::assertSame(InboxController::kinds([$role]) !== [], Sections::may([$role], InboxController::PERMS), "{$role}: the tab is shown exactly to who the page lets in");
        }
        self::assertSame([], InboxController::kinds(['buyer']));
        self::assertSame([], InboxController::kinds(['admin', 'auditor']), 'admin answers nothing (I12)');
        self::assertSame(['counts'], InboxController::kinds(['warehouse']));
        self::assertSame(['corrections', 'suspense', 'counts', 'oversold', 'modes', 'landing'], InboxController::kinds(['stock_controller']));
        self::assertSame(['staff', 'documents'], InboxController::kinds(['reviewer']));
        // The section counts its inbox once (the other tabs list the same records again).
        $frame = Sections::frame(['stock_controller'], ['approvals_inbox' => 4, 'queue_suspense' => 1, 'queue_corrections' => 1, 'queue_modes' => 1, 'queue_oversold' => 1,
            'counts_open' => 0], '/ui/approvals');
        self::assertSame(4, array_column($frame['nav'], 'count', 'key')['approvals']);
        self::assertSame(['inbox', 'approvals', 'inbox'], [$frame['here']['tab'], $frame['here']['section'], $frame['here']['page']]);
    }

    public function testEveryKindHasItsWordsAndAColour(): void
    {
        foreach (InboxController::KINDS as $kind => $k) {
            self::assertNotSame('', Words::INBOX['kind_' . $kind] ?? '', $kind);
            self::assertNotSame('', Words::INBOX['one_' . $kind] ?? '', $kind);
            self::assertContains($k['tone'], Words::TONES, $kind);
            self::assertStringStartsWith('/ui/', $k['page']);
        }
        self::assertSame('Waiting for you', Words::MENU['inbox']);
        self::assertSame(Words::MENU['reviews'], Words::INBOX['kind_documents'], 'one name for one thing (rule 13): the old tab is the inbox\'s group');
        self::assertSame(Words::SEGMENT['queue_corrections'], Words::INBOX['kind_corrections']);
        self::assertSame(Words::SEGMENT['queue_suspense'], Words::INBOX['kind_suspense']);
        self::assertSame(Words::SEGMENT['queue_oversold'], Words::INBOX['kind_oversold']);
        self::assertSame(Words::SEGMENT['queue_modes'], Words::INBOX['kind_modes']);
        self::assertSame(Words::SEGMENT['queue_landing'], Words::INBOX['kind_landing']);
        self::assertSame(Words::MENU['staff_requests'], Words::INBOX['kind_staff']);
    }

    /** The approved design (AP_Inbox, AP_Phone) as CSS: the list beside the open record; on a phone the record first, 44 px rows. */
    public function testTheLayoutFollowsTheApprovedDesign(): void
    {
        $css = (string) file_get_contents(Assets::dir() . '/app.css');
        $rule = static function (string $selector, string $in) : string {
            self::assertSame(1, preg_match('/^\s*' . preg_quote($selector, '/') . ' \{([^}]*)\}/m', $in, $m), "the rule {$selector}");
            return $m[1];
        };
        self::assertStringContainsString('flex-wrap: wrap', $rule('.ap-inbox', $css));
        self::assertStringContainsString('max-width: 400px', $rule('.ap-list', $css));
        self::assertStringContainsString('border-top: 6px solid var(--t-fill', $rule('.ap-card', $css), 'the kind\'s colour on the record\'s top edge');
        self::assertStringContainsString('min-height: 44px', $rule('.ap-row', $css), 'a row is a 44 px target');
        self::assertStringContainsString('border-left-color: var(--accent)', $rule('.ap-row.sel', $css));
        self::assertStringContainsString('background: var(--t-fill', $rule('.ap-dot', $css));
        self::assertStringContainsString('font: 700 18px', $rule('.ap-fact dd', $css), 'the facts are big figures');
        self::assertSame(1, preg_match('/@media \(max-width: 699\.98px\) \{\n  \.ap-list \{ flex-basis: 100%; max-width: none; \}\n  \.ap-record \{ flex-basis: 100%; order: -1; \}/', $css),
            'on a phone the open record comes first, the list after it');
        self::assertStringNotContainsString('#c90e9f', substr($css, (int) strpos($css, '.ap-inbox {')), 'CW\'s tokens only');
        foreach (['approvals_inbox', 'approvals_record', 'lq_item_box'] as $name) {
            $src = (string) file_get_contents(\CW\Ui\View::defaultDir() . '/' . $name . '.php');
            foreach (['div', 'details', 'form', 'section', 'ul', 'p', 'dl'] as $tag) {
                self::assertSame(preg_match_all('/<' . $tag . '\b/', $src), substr_count($src, '</' . $tag . '>'), "{$name}: every <{$tag}> closed");
            }
            self::assertStringNotContainsString('<script', $src);
            self::assertStringNotContainsString('style=', $src);
        }
    }
}
