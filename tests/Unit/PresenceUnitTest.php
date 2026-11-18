<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Live\Presence;
use PHPUnit\Framework\TestCase;

/**
 * Who is here on a draft (the live drafts plan, step 3; docs/decisions.md LV1-LV12): the colour each person has on every screen, the
 * rules of "here", "away" and "gone" on a fixed clock, the write only on a change, what a poll may send and how the settings are read.
 * No database: the parts of CW\Live\Presence that are pure.
 */
final class PresenceUnitTest extends TestCase
{
    private const NOW = '2026-11-18 14:02:30.000000';

    public function testEachPersonHasOneColourOnEveryScreenTheLowerIdFirstOnAClash(): void
    {
        self::assertSame([], Presence::colours([]));
        self::assertSame([2 => 3], Presence::colours([2]), 'id % 8 + 1');
        self::assertSame([8 => 1], Presence::colours([8]));
        // 1, 9 and 17 all want 2: the lower id keeps it, the next ones take the next free colour, in id order (2 has 3 before 9 is seen).
        self::assertSame([1 => 2, 2 => 3, 9 => 4, 17 => 5], Presence::colours([17, 9, 2, 1]), 'in id order whatever the order given');
        self::assertSame(Presence::colours([17, 9, 2, 1]), Presence::colours([1, 2, 9, 17]), 'the same set gives the same colours on every screen');
        self::assertSame([7 => 8, 15 => 1], Presence::colours([15, 7]), 'the next free one wraps round to 1');
        self::assertSame([3 => 4], Presence::colours([3, 3]), 'one person, one colour');
        // Nine people or more: eight colours, then a colour is shared (the person's own one).
        $ten = Presence::colours(range(1, 10));
        self::assertSame([1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 7, 7 => 8, 8 => 1, 9 => 2, 10 => 3], $ten);
        foreach ($ten as $c) {
            self::assertGreaterThanOrEqual(1, $c);
            self::assertLessThanOrEqual(Presence::COLOURS, $c);
        }
    }

    public function testHereAwayAndGoneOnAFixedClock(): void
    {
        $ago = static fn (string $by): string => (new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))->modify('-' . $by)->format('Y-m-d H:i:s.u');
        // Active: here while seen less than the gone time ago.
        self::assertTrue(Presence::isHere('active', $ago('24999 milliseconds'), self::NOW, 25));
        self::assertFalse(Presence::isHere('active', $ago('25 seconds'), self::NOW, 25), 'gone at exactly the gone time');
        self::assertFalse(Presence::isHere('active', $ago('9 minutes'), self::NOW, 25));
        self::assertTrue(Presence::isHere('active', $ago('39 seconds'), self::NOW, 40), 'the setting decides');
        // Away: shown ten minutes, whatever the gone time.
        self::assertTrue(Presence::isHere('away', $ago('599 seconds'), self::NOW, 25));
        self::assertFalse(Presence::isHere('away', $ago('600 seconds'), self::NOW, 25));
        self::assertSame(600, Presence::AWAY_SECONDS);
        // The database's one clock writes and reads: a row "from the future" is a row just written.
        self::assertTrue(Presence::isHere('active', '2026-11-18 14:02:31.000000', self::NOW, 25));
        self::assertFalse(Presence::isHere('elsewhere', self::NOW, self::NOW, 25), 'an unknown state is never here');
    }

    public function testTheGoneTimeIsNeverShorterThanAPersonsNextPollCanTake(): void
    {
        // A row is written again at most every REFRESH seconds; a tab polls every poll_seconds (others here) or alone_seconds: an active
        // person is never called gone before their next poll could have written their row (plus a few seconds for the network).
        self::assertSame([10, 5], [Presence::REFRESH_SECONDS, Presence::SLACK_SECONDS]);
        self::assertSame(25, Presence::goneAfter(['mode' => 'presence', 'poll' => 3, 'alone' => 10, 'gone' => 25]), 'the defaults');
        self::assertSame(75, Presence::goneAfter(['mode' => 'presence', 'poll' => 3, 'alone' => 60, 'gone' => 25]), 'alone for a minute');
        self::assertSame(120, Presence::goneAfter(['mode' => 'presence', 'poll' => 10, 'alone' => 10, 'gone' => 120]));
        self::assertSame(3000, Presence::pollMs(['mode' => 'presence', 'poll' => 3, 'alone' => 10, 'gone' => 25], true), 'someone else is here');
        self::assertSame(10000, Presence::pollMs(['mode' => 'presence', 'poll' => 3, 'alone' => 10, 'gone' => 25], false), 'alone');
    }

    public function testTheRowIsWrittenOnlyOnAChangeOrWhenItIsTenSecondsOld(): void
    {
        $row = ['focus_uid' => 7, 'focus_field' => 'qty', 'state' => 'active', 'typing' => 0, 'edits' => 1, 'seen_at' => '2026-11-18 14:02:25.000000'];
        $in = ['tab' => str_repeat('a', 16), 'uid' => 7, 'field' => 'qty', 'state' => 'active', 'typing' => 0, 'bye' => false];
        self::assertTrue(Presence::needsWrite(null, $in, true, self::NOW), 'no row yet');
        self::assertFalse(Presence::needsWrite($row, $in, true, '2026-11-18 14:02:34.999999'), 'the same, 9.999 seconds old');
        self::assertTrue(Presence::needsWrite($row, $in, true, '2026-11-18 14:02:35.000000'), 'the same, 10 seconds old');
        // Values as the database returns them (strings) compare as values.
        self::assertFalse(Presence::needsWrite(['focus_uid' => '7', 'typing' => '0', 'edits' => '1'] + $row, $in, true, self::NOW));
        foreach ([['uid' => 8], ['uid' => null], ['field' => 'price'], ['field' => null], ['state' => 'away'], ['typing' => 1]] as $change) {
            self::assertTrue(Presence::needsWrite($row, $change + $in, true, self::NOW), json_encode($change));
        }
        self::assertTrue(Presence::needsWrite($row, $in, false, self::NOW), 'the person may no longer change the draft');
        $none = ['focus_uid' => null, 'focus_field' => 'h:note'] + $row;
        self::assertFalse(Presence::needsWrite($none, ['uid' => null, 'field' => 'h:note'] + $in, true, self::NOW), 'a header box, unchanged');
    }

    public function testWhatAPollMaySend(): void
    {
        $tab = 'abcdef0123456789';
        self::assertSame(['tab' => $tab, 'uid' => 7, 'field' => 'qty', 'state' => 'active', 'typing' => 1, 'bye' => false],
            Presence::input(['tab' => $tab, 'focus' => '7:qty', 'state' => 'active', 'typing' => '1', 'v' => '41', 'active' => '1', 'csrf' => 'x']));
        self::assertSame(['tab' => $tab, 'uid' => null, 'field' => 'h:expected_date', 'state' => 'away', 'typing' => 0, 'bye' => true],
            Presence::input(['tab' => $tab, 'focus' => 'h:expected_date', 'state' => 'away', 'bye' => '1']));
        self::assertSame(['tab' => $tab, 'uid' => null, 'field' => null, 'state' => 'active', 'typing' => 0, 'bye' => false], Presence::input(['tab' => $tab]));
        // A place that is not a line's box or a header box is no place (never a refusal: a heartbeat does not fail on its focus).
        foreach (['', '0:qty', '-1:qty', '7:QTY', '7:qty;x', '7:qty ', ' 7:qty', '4294967296:qty', 'h:', '7', ':qty', '7:', '<b>:qty', '7:<script>', 'h:note:x',
            '7:' . str_repeat('a', 25), 'h:' . str_repeat('a', 23), '07:qty', '7:9ty', 'x:qty', "7:qty\n"] as $bad) {
            $got = Presence::input(['tab' => $tab, 'focus' => $bad]);
            self::assertSame([null, null], [$got['uid'], $got['field']], json_encode($bad));
        }
        self::assertSame([4294967295, 'a_b'], array_values(array_intersect_key(Presence::input(['tab' => $tab, 'focus' => '4294967295:a_b']), ['uid' => 0, 'field' => 0])));
        self::assertSame(24, strlen(Presence::input(['tab' => $tab, 'focus' => 'h:' . str_repeat('a', 22)])['field'] ?? ''), 'a field name of 24 characters at most');
        // Anything but "away" is active; anything but "1" is no.
        foreach (['Away', 'AWAY', 'gone', '', 'away '] as $state) {
            self::assertSame('active', Presence::input(['tab' => $tab, 'state' => $state])['state'], $state);
        }
        self::assertSame([0, false], [Presence::input(['tab' => $tab, 'typing' => 'yes', 'bye' => 'true'])['typing'], Presence::input(['tab' => $tab, 'bye' => 'true'])['bye']]);
        self::assertSame([0, null], [Presence::input(['tab' => $tab, 'typing' => ['1'], 'focus' => ['7:qty']])['typing'], Presence::input(['tab' => $tab, 'focus' => ['7:qty']])['uid']],
            'a field sent as a list is not sent');
        // The tab names the row: without a well-formed one nothing can be done.
        foreach ([[], ['tab' => ''], ['tab' => 'ABCDEF0123456789'], ['tab' => 'abcdef012345678'], ['tab' => 'abcdef01234567890'], ['tab' => 'abcdef012345678g'],
            ['tab' => ['abcdef0123456789']], ['tab' => "abcdef0123456789\n"]] as $bad) {
            try {
                Presence::input($bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (CwException $e) {
                self::assertSame(['bad_tab', 400], [$e->errorCode, $e->httpStatus], json_encode($bad));
            }
        }
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', Presence::newTab());
        self::assertNotSame(Presence::newTab(), Presence::newTab());
    }

    public function testTheSettingsAreReadWithTheirDefaultsAndTheirRules(): void
    {
        $defaults = ['mode' => 'off', 'poll' => 3, 'alone' => 10, 'gone' => 25];
        self::assertSame($defaults, Presence::settingsFrom([]), 'no row yet (a schema before the migration): off');
        self::assertSame(['mode' => 'presence', 'poll' => 5, 'alone' => 20, 'gone' => 40], Presence::settingsFrom(['live.mode' => '"presence"',
            'live.poll_seconds' => '5', 'live.alone_seconds' => '20', 'live.gone_seconds' => '40']));
        self::assertSame('on', Presence::settingsFrom(['live.mode' => '"on"'])['mode']);
        // A stored value outside its rule (only SQL could store one) reads as its default, each setting on its own.
        foreach ([['live.mode' => '"maybe"'], ['live.mode' => '""'], ['live.mode' => '"Presence"'], ['live.mode' => 'true'], ['live.poll_seconds' => '1'],
            ['live.poll_seconds' => '11'], ['live.poll_seconds' => '"3"'], ['live.poll_seconds' => '3.5'], ['live.alone_seconds' => '4'], ['live.alone_seconds' => '61'],
            ['live.gone_seconds' => '24'], ['live.gone_seconds' => '301'], ['live.gone_seconds' => ''], ['live.gone_seconds' => '-30']] as $bad) {
            self::assertSame($defaults, Presence::settingsFrom($bad), json_encode($bad));
        }
        self::assertSame(['live.mode', 'live.poll_seconds', 'live.alone_seconds', 'live.gone_seconds'], array_keys(Presence::SETTINGS));
        self::assertSame(['in' => ['off', 'presence', 'on']], \CW\Settings::RULES['live.mode'], 'the Settings page holds the same rule');
        self::assertSame(['min' => 2, 'max' => 10], \CW\Settings::RULES['live.poll_seconds']);
        self::assertSame(['min' => 5, 'max' => 60], \CW\Settings::RULES['live.alone_seconds']);
        self::assertSame(['min' => 25, 'max' => 300], \CW\Settings::RULES['live.gone_seconds']);
    }

    public function testTheKindsAreDraftPagesOfOneDocumentType(): void
    {
        self::assertSame(['po' => ['type' => 'PO', 'path' => '/ui/purchasing/orders/%d']], Presence::KINDS, 'step 3: purchase orders only');
        self::assertSame('/ui/purchasing/orders/55', Presence::pagePath('po', 55));
    }
}
