<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\Settings;
use CW\Ui\Controller\ReportsController;
use CW\Ui\Sections;
use CW\Ui\UiRequest;
use CW\Ui\View;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * Pack E (docs/decisions.md PE1, PE8-PE12) without a database: the Reports tabs in the menu, the period of Sales by Store, the pages'
 * templates (cards on a phone, every word from Words), the report job's one cron line, and the settings it adds.
 */
final class ReportsUnitTest extends TestCase
{
    private const TEMPLATES = ['report_store', 'report_sales', 'report_low', 'report_value', 'report_slow', 'access_permission', 'access'];

    public function testTheReportsAreRealTabsGuardedByTheirPermissions(): void
    {
        $tabs = Sections::MAP['reports']['tabs'];
        self::assertSame(['stock_by_store', 'sales_by_store', 'low_stock', 'stock_value', 'slow_stock', 'audit'], array_keys($tabs), 'no new sidebar item, Audit Log last');
        $slugs = ['stock_by_store' => 'stock-by-store', 'sales_by_store' => 'sales-by-store', 'low_stock' => 'low-stock', 'stock_value' => 'stock-value', 'slow_stock' => 'slow-stock'];
        foreach ($slugs as $tab => $slug) {
            self::assertArrayNotHasKey('soon', $tabs[$tab], $tab);
            $page = $tabs[$tab]['pages'][0];
            self::assertSame('/ui/reports/' . $slug, $page['path']);
            self::assertSame(ReportsController::PAGES[$slug], $page['perm'], "{$tab}: the menu and the route name the same permission");
            self::assertArrayHasKey($tab, Words::MENU);
            self::assertArrayHasKey($tab, Words::MENU_HELP);
            self::assertArrayHasKey($tab, Words::PAGE_INTRO);
        }
        self::assertSame(['reports.view', 'values.view'], array_values(array_unique(ReportsController::PAGES)));
        self::assertSame(array_keys(Sections::MAP), ['dashboard', 'products', 'matching', 'stock', 'purchasing', 'sales', 'reports', 'approvals', 'settings'],
            'the sections (Matching and Sales of their own since HV3)');
        // A buyer sees four reports and no money; a reviewer all five and the audit log; admin only the audit log.
        $visible = static fn (array $roles): array => array_keys(Sections::visible($roles)['reports'] ?? []);
        self::assertSame(['stock_by_store', 'sales_by_store', 'low_stock', 'slow_stock'], $visible(['buyer']));
        self::assertSame(['stock_by_store', 'sales_by_store', 'low_stock', 'stock_value', 'slow_stock', 'audit'], $visible(['reviewer']));
        self::assertSame(['audit'], $visible(['admin']));
        self::assertSame([], $visible(['viewer']));
        self::assertTrue(Permissions::can(['admin', 'accountant'], 'values.view'), 'admin with a look-only job keeps that job\'s looks');
    }

    public function testThePeriodOfSalesByStore(): void
    {
        $req = static fn (array $q): UiRequest => new UiRequest('GET', '/ui/reports/sales-by-store', $q, [], [], [], '127.0.0.1', false);
        self::assertSame(['last_30', '2026-09-15', '2026-10-14', null], ReportsController::period($req([]), '2026-10-14', 366));
        self::assertSame(['last_7', '2026-10-08', '2026-10-14', null], ReportsController::period($req(['period' => 'last_7']), '2026-10-14', 366));
        self::assertSame(['this_month', '2026-10-01', '2026-10-14', null], ReportsController::period($req(['period' => 'this_month']), '2026-10-14', 366));
        self::assertSame(['last_month', '2026-09-01', '2026-09-30', null], ReportsController::period($req(['period' => 'last_month']), '2026-10-14', 366));
        self::assertSame(['last_month', '2026-02-01', '2026-02-28', null], ReportsController::period($req(['period' => 'last_month']), '2026-03-31', 366));
        self::assertSame(['choose', '2026-01-01', '2026-01-31', null], ReportsController::period($req(['period' => 'choose', 'from' => '2026-01-31', 'to' => '2026-01-01']), '2026-10-14', 366),
            'given the wrong way round');
        $long = ReportsController::period($req(['period' => 'choose', 'from' => '2025-01-01', 'to' => '2026-10-14']), '2026-10-14', 7);
        self::assertSame(['choose', '2026-10-08', '2026-10-14'], array_slice($long, 0, 3), 'the last 7 days are kept');
        self::assertSame(sprintf(Words::REPORTS['too_long'], '7'), $long[3]);
        foreach ([['2026-02-31', '2026-03-01'], ['x', '2026-03-01'], [null, null]] as [$from, $to]) {
            $bad = ReportsController::period($req(['period' => 'choose', 'from' => $from, 'to' => $to]), '2026-10-14', 366);
            self::assertSame(['last_30', Words::REPORTS['bad_dates']], [$bad[0], $bad[3]]);
        }
        self::assertSame('last_30', ReportsController::period($req(['period' => 'every_day']), '2026-10-14', 366)[0]);
    }

    /** The report pages and the roles page are cards on a phone, never print UTC, and take every word from Words. */
    public function testTheTemplatesTurnIntoCardsAndTakeEveryWordFromWords(): void
    {
        foreach (self::TEMPLATES as $name) {
            $src = (string) file_get_contents(View::defaultDir() . '/' . $name . '.php');
            preg_match_all('/<table\b([^>]*)>/i', $src, $tables, PREG_SET_ORDER);
            foreach ($tables as $t) {
                self::assertMatchesRegularExpression('/class="[^"]*\bstack\b/', $t[1], "{$name}: {$t[0]} is cards on a phone");
            }
            preg_match_all('/<td\b[^>]*>/i', $src, $cells);
            foreach ($cells[0] as $td) {
                self::assertMatchesRegularExpression('/data-label=|class="[^"]*\bc-(status|next|head)\b/', $td, "{$name}: {$td} says what it is on a phone");
            }
            foreach (['(UTC)', '$dt(', '(GBP)', '<svg', '<img', 'data:', '<script', 'style=', 'onclick'] as $old) {
                self::assertStringNotContainsString($old, $src, "{$name}: {$old}");
            }
            $text = (string) preg_replace(['/<\?.*?\?>/s', '/<[^>]*>/', '/&[a-z]+;/'], ' ', $src);
            self::assertSame([], preg_match_all('/[A-Za-z]{2,}/', $text, $m) > 0 ? $m[0] : [], "{$name}: a word typed in the template instead of taken from Words");
        }
    }

    public function testTheReportJobHasOneCronLineAndASmokeThatWritesNothing(): void
    {
        $dir = dirname(__DIR__, 2) . '/deploy/staging';
        $cron = (string) file_get_contents($dir . '/cw-staging.cron');
        self::assertMatchesRegularExpression('#^\*/15 \* \* \* \*  root  cd /opt/cw-staging && php bin/refresh_reports\.php --db=cw_staging >> /var/log/cw/refresh_reports\.log 2>&1$#m', $cron);
        self::assertSame(1, preg_match_all('#^[^\#\n]*refresh_reports\.php#m', $cron), 'one line');
        $install = (string) file_get_contents($dir . '/install_cron.sh');
        self::assertMatchesRegularExpression('#^for job in [^\n]*"refresh_reports\.php --status"[^\n]*; do$#m', $install, 'the smoke reads only');
        $tool = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/refresh_reports.php');
        self::assertStringContainsString("Cli::main(ReportRefresh::JOB", $tool, 'one run at a time (the job lock)');
        self::assertStringContainsString('}, true, true));', $tool, 'single run, and the command line read whole or not at all');
    }

    /** Every number the reports use is a setting with a rule, a name, help and a topic (the owner's rule: nothing hard-coded). */
    public function testTheReportSettings(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0034_reports_roles.sql');
        preg_match_all("/^ \\('(reports\\.[a-z_]+)','(int|bool)','([^']*)',1,'PE\\d+','/m", $sql, $m, PREG_SET_ORDER);
        $seeded = [];
        foreach ($m as $row) {
            $seeded[$row[1]] = [$row[2], $row[3]];
        }
        self::assertSame(['reports.low_stock_default' => ['int', '5'], 'reports.slow_after_days' => ['int', '60'], 'reports.dead_after_days' => ['int', '180'],
            'reports.sales_max_days' => ['int', '366'], 'reports.refresh_on' => ['bool', 'true'], 'reports.refresh_max_rows' => ['int', '50000'],
            'reports.refresh_max_days' => ['int', '31']], $seeded);
        foreach (array_keys($seeded) as $key) {
            self::assertArrayHasKey($key, Words::SETTING, $key);
            self::assertArrayHasKey($key, Words::SETTING_HELP, $key);
            self::assertSame('Reports', Words::settingTopic($key), $key);
            if ($key !== 'reports.refresh_on') {
                self::assertArrayHasKey($key, Settings::RULES, "{$key} has its allowed values");
                $rule = Settings::RULES[$key];
                self::assertTrue($rule['min'] <= (int) $seeded[$key][1] && (int) $seeded[$key][1] <= $rule['max'], "{$key}: the baseline is allowed");
            }
        }
        self::assertSame(['reports.dead_after_days'], Settings::RULES['reports.slow_after_days']['at_most']);
        self::assertSame(['reports.slow_after_days'], Settings::RULES['reports.dead_after_days']['at_least']);
        self::assertStringContainsString("SELECT 'setting', setting_key, 1, 'baseline'", $sql, 'each with its baseline version');
    }

    public function testEverySalesPeriodHonoursTheConfiguredMaximum(): void
    {
        $req = static fn (array $q): UiRequest => new UiRequest('GET', '/ui/reports/sales-by-store', $q, [], [], [], '127.0.0.1', false);
        $limit = Words::say('REPORTS', 'too_long', '7');
        foreach ([
            [[], 'last_30', '2026-10-08', '2026-10-14', $limit],
            [['period' => 'this_month'], 'this_month', '2026-10-08', '2026-10-14', $limit],
            [['period' => 'last_month'], 'last_month', '2026-09-24', '2026-09-30', $limit],
            [['period' => 'unknown'], 'last_30', '2026-10-08', '2026-10-14', $limit],
            [['period' => 'choose', 'from' => 'bad', 'to' => '2026-10-14'], 'last_30', '2026-10-08', '2026-10-14', Words::REPORTS['bad_dates'] . ' ' . $limit],
            [['period' => 'last_7'], 'last_7', '2026-10-08', '2026-10-14', null],
        ] as [$query, $period, $from, $to, $warn]) {
            self::assertSame([$period, $from, $to, $warn], ReportsController::period($req($query), '2026-10-14', 7), json_encode($query));
        }
    }
}
