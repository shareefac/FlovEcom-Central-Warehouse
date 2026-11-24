<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Expenses;

use CW\Expenses\ExpenseViews;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

/**
 * bin/seed_regular_costs.php (the brief's point 6; docs/decisions.md EX): the owner's weekly list as regular costs of Vape and Go, every week,
 * from 1 Nov 2026 (or --from): a dry run by default (nothing written, every line said), --apply writes them as system:seed_regular_costs
 * with their history, a second run adds nothing (a line of the same name and shop that is not deleted is already there). Unit 3 ends on
 * 31 Mar 2027, Reboot on 28 Feb 2027. Weekly total GBP 9,771.94.
 */
final class SeedRegularCostsTest extends IntegrationTestCase
{
    /** @return array{code: int, out: string} */
    private static function seed(string ...$args): array
    {
        $cmd = [PHP_BINARY, '-d', 'memory_limit=256M', dirname(__DIR__, 3) . '/bin/seed_regular_costs.php', '--db=' . TestDb::name(), '--admin', ...$args];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out];
    }

    public function testADryRunThenTheListOnceThenNothing(): void
    {
        $none = self::seed();
        self::assertSame(1, $none['code'], $none['out']);
        self::assertStringContainsString('no shop vapeandgo', $none['out']);

        $ch = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
        $dry = self::seed();
        self::assertSame(0, $dry['code'], $dry['out']);
        self::assertStringContainsString('DRY RUN', $dry['out']);
        self::assertStringContainsString('16 to add, 0 already there', $dry['out']);
        self::assertStringContainsString('every week in all £9,771.94', $dry['out']);
        self::assertStringContainsString('Unit 3', $dry['out']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'), 'a dry run writes nothing');

        $run = self::seed('--apply');
        self::assertSame(0, $run['code'], $run['out']);
        self::assertStringContainsString('16 added', $run['out']);
        $rows = self::$db->all('SELECT r.name, r.every, r.amount_pence, r.from_day, r.until_day, r.created_actor, t.name AS grp FROM regular_cost r '
            . 'JOIN expense_type t ON t.id = r.type_id WHERE r.channel_id = ? ORDER BY r.id', [$ch]);
        self::assertSame(['Unit 1 rent', 'Unit 2 rent', 'Unit 3 rent', 'Hub Box', 'Phone and internet', 'Verisure', 'Car and van lease', 'Adobe', 'Accounting', 'Sage',
            'Cardboard', 'Bin', 'Netcore', 'Novos', 'Reboot', 'Dean'], array_column($rows, 'name'));
        self::assertSame([20769, 23077, 92308, 10900, 2640, 3600, 15200, 2000, 13800, 4200, 1300, 17700, 96900, 276900, 207600, 188300],
            array_map('intval', array_column($rows, 'amount_pence')));
        self::assertSame(977194, array_sum(array_map('intval', array_column($rows, 'amount_pence'))));
        self::assertSame(['week'], array_values(array_unique(array_column($rows, 'every'))));
        self::assertSame(['2026-11-01'], array_values(array_unique(array_column($rows, 'from_day'))));
        self::assertSame(['system:seed_regular_costs'], array_values(array_unique(array_column($rows, 'created_actor'))));
        self::assertSame('2027-03-31', $rows[2]['until_day']);
        self::assertSame('2027-02-28', $rows[14]['until_day']);
        self::assertSame(14, count(array_filter($rows, static fn (array $r): bool => $r['until_day'] === null)));
        self::assertSame(['Rent', 'Rent', 'Rent'], array_slice(array_column($rows, 'grp'), 0, 3));
        self::assertSame(['Marketing, website and people'], array_values(array_unique(array_slice(array_column($rows, 'grp'), 12))));
        self::assertSame(['Running the business'], array_values(array_unique(array_slice(array_column($rows, 'grp'), 3, 9))));
        self::assertSame(16, (int) self::$db->value("SELECT COUNT(*) FROM expense_history WHERE subject = 'regular_cost' AND action = 'add'"));
        self::assertSame(4187973, (new ExpenseViews(self::$db))->regularBy('2026-11-01', '2026-11-30', null)[$ch], 'November, each line rounded once');

        $again = self::seed('--apply');
        self::assertSame(0, $again['code'], $again['out']);
        self::assertStringContainsString('0 added, 16 already there', $again['out']);
        self::assertSame(16, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'));

        // Another first day (the owner confirms it before go-live) on a fresh schema: --from.
        self::$db->exec('SET FOREIGN_KEY_CHECKS = 0');
        self::$db->exec("DELETE FROM expense_history WHERE subject = 'regular_cost'");
        self::$db->exec('DELETE FROM regular_cost');
        self::$db->exec('SET FOREIGN_KEY_CHECKS = 1');
        self::assertSame(0, self::seed('--apply', '--from=2026-11-02')['code']);
        self::assertSame(['2026-11-02'], self::$db->column('SELECT DISTINCT from_day FROM regular_cost'));
        self::assertSame(2, self::seed('--apply', '--from=2026-02-30')['code'], 'not a day');
    }
}
