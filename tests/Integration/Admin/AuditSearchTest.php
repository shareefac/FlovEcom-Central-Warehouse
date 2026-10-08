<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Admin;

use CW\Admin\AuditSearch;
use CW\Tests\Support\IntegrationTestCase;

/**
 * The audit log viewer's search (G07; docs/decisions.md Y32): by day (always a window), person, the CW jobs or the websites, the
 * kind of record and its number, and what was done (a kind, or one action), newest first, a page at a time, and the same search
 * for the CSV file (capped).
 */
final class AuditSearchTest extends IntegrationTestCase
{
    private function row(string $at, string $actor, ?int $staff, ?int $channel, string $action, ?string $type, ?string $id): int
    {
        return self::$db->insert('INSERT INTO audit_log (actor, staff_user_id, channel_id, action, entity_type, entity_id, detail, created_at) '
            . "VALUES (?, ?, ?, ?, ?, ?, JSON_OBJECT('n', 1), ?)", [$actor, $staff, $channel, $action, $type, $id, $at]);
    }

    public function testSearchByDayPersonRecordAndWhatWasDone(): void
    {
        $staff = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('a@test.example', 'Ann', 'a@test.example', 'x')");
        $site = self::makeChannel('vpg');
        $a = $this->row('2026-10-01 09:00:00', 'staff:' . $staff, $staff, null, 'setting.change', 'app_setting', 'po.terms');
        $b = $this->row('2026-10-03 23:30:00', 'system:settings', null, null, 'setting.change', 'app_setting', 'po.terms');
        $c = $this->row('2026-10-04 10:00:00', 'staff:' . $staff, $staff, null, 'supplier.approve', 'supplier', '5');
        $d = $this->row('2026-10-05 10:00:00', 'channel:vpg', null, $site, 'reservation.expire', 'reservation', 'R1');
        $e = $this->row('2026-10-07 08:00:00', 'staff:' . $staff, $staff, null, 'logout', 'staff_user', (string) $staff);
        $s = new AuditSearch(self::$db);
        $ids = static fn (array $page): array => array_column($page['rows'], 'id');
        $f = static fn (array $q): array => AuditSearch::filters($q, '2026-10-08');

        self::assertSame([$e, $d, $c, $b], $ids($s->page($f([]))), 'the last 7 days (2 Oct to 8 Oct), newest first');
        self::assertSame([$e, $d, $c, $b, $a], $ids($s->page($f(['from' => '2026-09-25']))));
        self::assertSame([$c, $b], $ids($s->page($f(['from' => '2026-10-03', 'to' => '2026-10-04']))), 'the last day included');
        self::assertSame([$e, $c, $a], $ids($s->page($f(['from' => '2026-09-25', 'who' => 'staff:' . $staff]))));
        self::assertSame([$b], $ids($s->page($f(['from' => '2026-09-25', 'who' => 'system']))));
        self::assertSame([$d], $ids($s->page($f(['from' => '2026-09-25', 'who' => 'site']))));
        self::assertSame('vpg test site', strtolower($s->page($f(['who' => 'site']))['rows'][0]['site']));
        self::assertSame([$b, $a], $ids($s->page($f(['from' => '2026-09-25', 'record' => 'app_setting', 'id' => 'po.terms']))));
        self::assertSame([$c], $ids($s->page($f(['from' => '2026-09-25', 'action' => 'supplier']))), 'a kind of action');
        self::assertSame([$e], $ids($s->page($f(['from' => '2026-09-25', 'action' => 'logout']))), 'an action without a dot');
        self::assertSame([], $ids($s->page($f(['from' => '2026-09-25', 'action' => 'setting_']))), 'the _ of a prefix is not a wildcard');
        self::assertSame('Ann', $s->page($f(['from' => '2026-09-25', 'action' => 'supplier.approve']))['rows'][0]['person']);

        // A page at a time: the next one starts before the last id shown.
        $p1 = $s->page($f(['from' => '2026-09-25']), 2);
        self::assertSame([[$e, $d], $d], [$ids($p1), $p1['next']]);
        $p2 = $s->page($f(['from' => '2026-09-25', 'before' => (string) $p1['next']]), 2);
        self::assertSame([[$c, $b], $b], [$ids($p2), $p2['next']]);
        $p3 = $s->page($f(['from' => '2026-09-25', 'before' => (string) $p2['next']]), 2);
        self::assertSame([[$a], null], [$ids($p3), $p3['next']]);

        $x = $s->export($f(['from' => '2026-09-25']));
        self::assertSame([5, false], [count($x['rows']), $x['more']]);
        self::assertSame([['id' => $staff, 'name' => 'Ann']], $s->people());
    }
}
