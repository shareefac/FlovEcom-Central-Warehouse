<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Counts\CountPlan;
use CW\Counts\CountReviews;
use CW\Counts\CountSheetHandler;
use CW\CwException;
use CW\Ui\Controller\CountsController;
use CW\Ui\Sections;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * The words of Stock › Counts (pack A2a; docs/decisions.md CT15): every code of the counting pack has its word, every word a counting
 * template or its controller asks for exists (a missing one would be shown as its code made readable, and nobody would notice), every
 * notice and refusal is a full sentence, the house says "final" and never "posted", and the menu names the tab's three segments.
 */
final class CountWordsTest extends TestCase
{
    private const TEMPLATES = ['stock_counts', 'stock_count_sheets', 'stock_count_reviews', 'stock_count', 'stock_count_before'];

    /** @return array<string, string> file => source of the counting templates and their controller */
    private static function sources(): array
    {
        $root = dirname(__DIR__, 2) . '/src/Ui/';
        $out = ['CountsController.php' => (string) file_get_contents($root . 'Controller/CountsController.php')];
        foreach (self::TEMPLATES as $t) {
            $out[$t . '.php'] = (string) file_get_contents($root . 'views/' . $t . '.php');
        }
        return $out;
    }

    public function testEveryWordTheCountingPagesAskForExists(): void
    {
        $asked = 0;
        foreach (self::sources() as $file => $src) {
            preg_match_all('/(?:\$word|\$say|Words::say|Words::of)\(\'([A-Z_]+)\',\s*\'([a-z_.A-Z]+)\'\s*[,)]/', $src, $calls, PREG_SET_ORDER);
            preg_match_all('/Words::([A-Z_]+)\[\'([a-z_.A-Z]+)\'\]/', $src, $consts, PREG_SET_ORDER);
            foreach ([...$calls, ...$consts] as $m) {
                $asked++;
                self::assertTrue(Words::has($m[1], $m[2]), "{$file}: no word {$m[1]} `{$m[2]}`");
            }
        }
        self::assertGreaterThan(200, $asked, 'the scan found the pages\' words');
        // The words built from a code at run time.
        foreach (['unknown', 'ambiguous', 'not_on_sheet', 'case'] as $scan) {
            self::assertTrue(Words::has('COUNTS', 'scan_' . $scan), $scan);
        }
        foreach (['opened', 'lines', 'lines_same', 'added', 'uploaded', 'posted', 'posted_check', 'stopped', 'cancelled', 'cancel_waits', 'accepted', 'rejected',
            'rejected_already', 'recount', 'recount_times', 'as_counted'] as $notice) {
            self::assertTrue(Words::has('COUNT_NOTICE', $notice), $notice);
        }
    }

    public function testEveryCodeOfTheCountingPackHasItsWord(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0029_stock_counts.sql');
        $enum = static function (string $column) use ($sql): array {
            self::assertSame(1, preg_match('/^\s+' . $column . '\s+ENUM\(([^)]*)\)/m', $sql, $m), $column);
            return array_map(static fn (string $v): string => trim($v, " '"), explode(',', $m[1]));
        };
        self::assertSame(CountSheetHandler::KINDS, $enum('kind'));
        self::assertSame(CountSheetHandler::KINDS, array_keys(Words::COUNT_KIND));
        self::assertSame(['none', 'open', 'accepted', 'rejected'], $enum('review'));
        self::assertSame(['open', 'accepted', 'rejected'], array_keys(Words::COUNT_CHECK));
        self::assertSame(['counted', 'stale'], $enum('outcome'));
        self::assertSame(['booked', 'stale', 'put_back'], array_keys(Words::COUNT_LINE));
        self::assertSame(['line', ...CountReviews::LISTED_SOURCES], array_keys(Words::COUNT_REVIEW));
        self::assertSame([...CountReviews::ENGINE_SOURCES, ...CountReviews::NEAR_SOURCES], CountReviews::LISTED_SOURCES, 'the engine\'s two and the two about a count\'s time');
        foreach (['counted_again', 'line_counted_again', 'cancel_waiting', 'accept_only', 'bad_count_time'] as $code) {
            self::assertTrue(Words::has('COUNT_ERROR', $code), $code);
        }
        self::assertSame(array_keys(Words::COUNT_REVIEW), array_keys(Words::COUNT_REVIEW_HELP));
        self::assertSame(CountsController::GROUPS, array_keys(Words::COUNT_SHEET_GROUP));
        self::assertSame(['never', 'overdue', 'inside'], array_keys(Words::COUNT_GROUP));
        self::assertSame(['all', 'todo', 'done'], array_keys(Words::COUNT_SHOW));
        self::assertSame(['no_code', 'not_whole', 'negative', 'too_large', 'twice'], array_keys(Words::COUNT_BAD));
        self::assertSame([50, 100, 200], CountPlan::SHOW);
        // The menu: Counts is a tab with three segments, each with its name; the tab has its line on the Dashboard, its create button
        // and its badge.
        $pages = Sections::MAP['stock']['tabs']['counts']['pages'];
        self::assertSame(['counts', 'count_sheets', 'count_differences'], array_column($pages, 'key'));
        foreach ($pages as $page) {
            self::assertTrue(Words::has('SEGMENT', (string) $page['key']));
            self::assertSame('documents.view', $page['perm'], 'the people who read records look');
        }
        self::assertSame(['Next to count', 'Count sheets', 'Differences'], array_map(static fn (array $p): string => Words::SEGMENT[$p['key']], $pages));
        self::assertSame(['perm' => 'doc.CNT.post', 'href' => '/ui/stock/counts#new', 'label' => 'counts'], $pages[0]['new']);
        self::assertSame('counts_open', $pages[2]['badge']);
        foreach (['MENU_HELP' => 'counts', 'NEW' => 'counts', 'BADGE' => 'counts_open', 'PAGE_TITLE' => 'count_differences'] as $group => $code) {
            self::assertTrue(Words::has($group, $code), "{$group} {$code}");
        }
        self::assertFalse(Words::has('COMING_LATER', 'counts'), 'stock counts are built: they are no longer "coming later"');
        self::assertStringNotContainsString('coming later', Words::PERMISSION['doc.CNT.post']);
        foreach (['count.open', 'count.upload', 'count.accept', 'count.reject'] as $action) {
            self::assertTrue(Words::has('AUDIT_ACTION', $action), $action);
        }
    }

    public function testTheCountingWordsKeepTheWritingRules(): void
    {
        foreach ([Words::COUNT_NOTICE, Words::COUNT_ERROR, Words::COUNT_REVIEW_HELP, Words::COUNT_HOW] as $texts) {
            foreach ($texts as $code => $text) {
                self::assertMatchesRegularExpression('/^[A-Z"].*[.:]$/s', $text, "{$code}: a full sentence (writing rule 7)");
            }
        }
        foreach (Words::COUNT_ERROR as $code => $text) {
            self::assertMatchesRegularExpression('/Nothing was |not saved|must answer/', $text, "{$code}: a refusal says whether anything was saved (writing rule 7)");
        }
        // One name for one thing (rule 13): a record is made "final", as every other record; "posted" is not a word of the screens.
        $all = [];
        foreach ((new \ReflectionClass(Words::class))->getReflectionConstants() as $c) {
            if (str_starts_with($c->getName(), 'COUNT')) {
                $v = $c->getValue();
                array_walk_recursive($v, static function (mixed $text, mixed $key) use (&$all, $c): void {
                    $all[$c->getName() . ' ' . $key] = (string) $text;
                });
            }
        }
        foreach (['counts', 'count_sheets', 'count_differences', 'count_sheet_open', 'count_sheet', 'count_before'] as $page) {
            $all["PAGE_INTRO {$page}"] = implode(' ', Words::PAGE_INTRO[$page]);
        }
        foreach (['counts.plan_days', 'counts.sheet_shows_stock', 'counts.review_over_units', 'counts.review_over_percent', 'counts.time_window_max_minutes'] as $key) {
            $all["SETTING {$key}"] = Words::SETTING[$key] . ' ' . Words::SETTING_HELP[$key];
            self::assertSame('Stock counts', Words::settingTopic($key));
        }
        self::assertGreaterThan(250, count($all));
        foreach ($all as $where => $text) {
            self::assertDoesNotMatchRegularExpression('/\bpost(ed|ing|s)?\b/i', $text, "{$where}: the screens say \"final\"");
            self::assertDoesNotMatchRegularExpression('/\b(SKU|sku|ledger|variance|tolerance|stale|engine)\b/', $text, "{$where}: a technical word");
        }
        // The numbers of the rules are never typed into a word: they are settings and are filled in.
        self::assertStringContainsString('%s', Words::COUNTS['plan']);
        self::assertStringContainsString('%s', Words::COUNTS['over_units']);
        self::assertStringContainsString('%s%%', Words::COUNTS['over_percent']);
        self::assertSame('more than 10% of what the system held', Words::say('COUNTS', 'over_percent', 10));
        self::assertSame('If you keep your count, the shelf figure becomes 8: your count less the 2 that left after it.', Words::say('COUNTS', 'does_ships', 8, 2, 0));
        self::assertStringContainsString('the 6 units that came in', Words::say('COUNTS', 'does_overwrite', 5, 0, 6));
    }

    public function testARefusalIsSaidInThePagesWordsByItsCode(): void
    {
        $e = static fn (string $code, array $detail = [], int $status = 409): CwException => new CwException($code, 'the service text', $status, $detail);
        self::assertSame(Words::say('COUNT_ERROR', 'moved_since', '2, 5'), CountsController::plain($e('moved_since', ['lines' => [2, 5]])));
        self::assertSame(Words::say('COUNT_ERROR', 'count_too_old', '3', 90), CountsController::plain($e('count_too_old', ['lines' => [3], 'days' => 90], 422)));
        self::assertSame(Words::say('COUNT_ERROR', 'bad_quantity', 4), CountsController::plain($e('bad_quantity', ['line' => 4], 422)));
        self::assertSame(Words::say('COUNT_ERROR', 'too_many_lines', 500), CountsController::plain($e('too_many_lines', ['max' => 500], 422)));
        self::assertSame(Words::COUNT_ERROR['not_creator'], CountsController::plain($e('not_creator', [], 403)));
        self::assertSame(Words::COUNT_ERROR['own_count'], CountsController::plain($e('own_count', [], 403)));
        // Review fixes of 14 Oct 2026 (C-1, M-1, M-7, I-1, M-2).
        self::assertSame(Words::COUNT_ERROR['counted_again'], CountsController::plain($e('counted_again')));
        self::assertSame(Words::say('COUNT_ERROR', 'line_counted_again', 3), CountsController::plain($e('line_counted_again', ['line' => 3])));
        self::assertSame(Words::COUNT_ERROR['cancel_waiting'], CountsController::plain($e('cancel_waiting')));
        self::assertSame(Words::say('COUNT_ERROR', 'bad_count_time', 2), CountsController::plain($e('bad_count_time', ['line' => 2], 422)));
        self::assertSame(Words::say('COUNT_ERROR', 'too_many_times', 20), CountsController::plain($e('too_many_times', ['max' => 20])));
        self::assertSame(Words::STOCK_ERROR['warehouse_inactive'], CountsController::plain($e('warehouse_inactive', [], 422)), 'the stock records\' words where the refusal is theirs');
        self::assertSame('the service text', CountsController::plain($e('some_new_code')), 'a code without words: the service\'s message');
    }
}
