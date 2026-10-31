<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\Ui\HomeTasks;
use CW\Ui\Kernel;
use CW\Ui\Sections;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * Home's "What needs doing" (plan §3.2, design A with B's parts; Ui\HomeTasks, pure): which cards a person gets for their
 * jobs, that nothing waiting means no card, the order (an urgent job first, then what holds other people up, then the owner's
 * own checks, then routine work), ONE short line and one button each to a page the person may open, and the notes after the
 * jobs. Since the owner's checklist of 31 Oct 2026 (docs/decisions.md RD-H1-RD-H8): no job numbers and no "Start here", each
 * job in the tab of the sidebar section its row opens, the tabs with their counts, a tab chosen by `?jobs=`, 8 rows and the rest
 * in a fold. The counts themselves are HomeCounts' (HomeScreenTest, through the real kernel).
 */
final class HomeTasksTest extends TestCase
{
    private const OWNER = ['mapping_lead', 'reviewer'];

    /** Today on staging, as the owner sees it (plan §3.2 "Owner on a phone, today"). @return array<string, mixed> */
    private static function ownerToday(): array
    {
        return [
            'company' => ['confirmed' => false, 'missing' => ['company number', 'purchasing e-mail']],
            'checks' => ['approval' => 1, 'review' => 2],
            'samples' => [['id' => 1, 'name' => 'owner-1', 'size' => 20, 'decided' => 0, 'rest' => 1097, 'next_listing' => 501, 'next_position' => 1]],
            'held' => [['sample_id' => 1, 'name' => 'owner-1', 'open' => 254]],
            'duplicates' => 156,
            'pending' => 0,
            'bands' => ['Key' => 1371, 'Check' => 5, 'New item' => 0, "Can't tell" => 2, 'Conflict' => 3, 'Manual' => 0],
            'barcodes' => 4,
            'sales' => null,
        ];
    }

    /** @param list<array<string, mixed>> $cards @return list<string> */
    private static function keys(array $cards): array
    {
        return array_column($cards, 'key');
    }

    /** @param array<string, mixed> $card */
    private static function assertOneButtonToAPageTheyMayOpen(array $card, array $roles): void
    {
        static $router = null;
        $router ??= (new Kernel(static fn (): never => throw new \RuntimeException('no database'), static fn (): ?string => null, static function (): void {
        }))->router();
        $what = (string) $card['key'];
        self::assertIsString($card['button'] ?? null, "{$what}: one button");
        self::assertIsString($card['href'] ?? null, "{$what}: the button leads somewhere");
        $path = (string) parse_url((string) $card['href'], PHP_URL_PATH);
        [$route] = $router->match('GET', $path);
        self::assertTrue($route->access === 'any' || Permissions::can($roles, $route->access), "{$what}: {$card['href']} is a page the person may open");
    }

    public function testNeedsAsksOnlyForTheFactsTheJobsUse(): void
    {
        self::assertSame(['company', 'checks', 'samples', 'held', 'duplicates', 'pending', 'bands', 'barcodes', 'sales', 'integrity', 'staff_requests',
            'watch', 'setup_closed', 'code_off'], HomeTasks::needs(self::OWNER),
            'a reviewer also looks at the staff and rule changes (I1), closed set-ups (I5) and whether 2-step verification is off (TF5)');
        self::assertSame(['company', 'orders', 'demand', 'sales', 'suppliers'], HomeTasks::needs(['buyer']));
        self::assertSame(['staff', 'integrity', 'setup_closed', 'code_off'], HomeTasks::needs(['admin']),
            'the admin looks after the system: the nightly safety check (G36), closed set-ups (I5), 2-step verification off (TF5)');
        self::assertSame(['staff', 'integrity', 'setup_closed', 'code_off'], HomeTasks::needs(['admin', 'mapping_lead', 'reviewer']), 'Admin switches the owner\'s jobs off: no matching or checking counts');
        self::assertSame(['bands'], HomeTasks::needs(['mapper']));
        self::assertSame([], HomeTasks::needs(['viewer']), 'look only: nothing to count');
        self::assertSame([], HomeTasks::needs([]));
        foreach (HomeTasks::FACTS as $fact => $perms) {
            foreach ($perms as $perm) {
                self::assertArrayHasKey($perm, Permissions::MAP, $fact);
            }
        }
    }

    /** 2-step verification switched off (auth.totp_off_until, TF5): the first card of the people who can switch it back on, nobody else's. */
    public function testTwoStepOffIsTheFirstCardOfAdminsAndReviewers(): void
    {
        $f = ['code_off' => '2026-10-27 18:00:00.000000', 'integrity' => ['ok' => false, 'problems' => 2, 'finished_at' => '2026-10-20 01:00:00', 'hours' => 1]];
        foreach ([['admin'], self::OWNER] as $roles) {
            $first = HomeTasks::build(7, $roles, $f)['jobs'][0];
            self::assertSame(['code_off', 'blocked', true, '2-step verification is OFF', 'It comes back on by itself on 27 Oct 2026, 18:00',
                '/ui/reference/settings/setting?key=auth.totp_off_until'],
                [$first['key'], $first['tone'], $first['urgent'], $first['title'], $first['line'], $first['href']], implode(',', $roles));
        }
        self::assertNotContains('code_off', array_column(HomeTasks::build(7, ['buyer'], $f)['jobs'], 'key'));
        self::assertNotContains('code_off', array_column(HomeTasks::build(7, self::OWNER, ['code_off' => null])['jobs'], 'key'), 'off: no card');
    }

    public function testEveryCardHasItsWords(): void
    {
        foreach ([...array_keys(HomeTasks::RANK), ...HomeTasks::NOTES, 'no_job'] as $key) {
            self::assertArrayHasKey($key, Words::TASK, $key);
            self::assertNotSame('', Words::TASK[$key]['title'], $key);
        }
        self::assertSame([], array_diff(array_keys(Words::TASK), [...array_keys(HomeTasks::RANK), ...HomeTasks::NOTES, 'no_job']), 'no words for a card that does not exist');
        self::assertSame([], array_intersect(array_keys(HomeTasks::RANK), HomeTasks::NOTES), 'a note is not a job');
    }

    /**
     * ONE short line per job (the owner's checklist, 31 Oct 2026; RD-H3): plain words of at most 60 characters, which replace the long
     * paragraphs on Home's rows. The jobs keep no paragraph and no "What happens:" line; the notes keep theirs (they stay as they were).
     */
    public function testEveryJobHasOneShortLine(): void
    {
        foreach (array_keys(HomeTasks::RANK) as $key) {
            self::assertIsString(Words::TASK[$key]['line'] ?? null, "{$key}: its line");
            foreach (Words::TASK[$key] as $field => $text) {
                if (is_string($text) && str_starts_with((string) $field, 'line')) {
                    self::assertLessThanOrEqual(60, mb_strlen($text), "{$key} {$field}: one short line");
                    self::assertDoesNotMatchRegularExpression('/[.!]$/', $text, "{$key} {$field}: a line, not a sentence");
                }
            }
            self::assertArrayNotHasKey('text', Words::TASK[$key], "{$key}: no paragraph on a row");
            self::assertArrayNotHasKey('what', Words::TASK[$key], "{$key}: no \"What happens:\" line");
        }
        foreach ([...HomeTasks::NOTES, 'no_job'] as $key) {
            self::assertIsString(Words::TASK[$key]['text'] ?? null, "{$key}: a note keeps its text");
            self::assertArrayNotHasKey('line', Words::TASK[$key], "{$key}: a note is not a row of the checklist");
        }
        self::assertSame('Order PDFs say DO NOT SEND until then', Words::TASK['company_confirm']['line'], 'the safety message stays');
    }

    /**
     * Each job in a tab (RD-H2): every card but "no job" has a sidebar section, every section is the sidebar's, and the tabs come in the
     * owner's order; a tab's name is its section's.
     */
    public function testEveryJobHasATab(): void
    {
        self::assertSame([], array_values(array_diff(array_keys(Words::TASK), [...array_keys(HomeTasks::GROUP), 'no_job'])), 'every card has a tab');
        self::assertSame([], array_values(array_diff(array_keys(HomeTasks::GROUP), array_keys(Words::TASK))), 'no tab for a card that does not exist');
        self::assertArrayNotHasKey('no_job', HomeTasks::GROUP);
        self::assertSame(['settings', 'purchases', 'inventory', 'items', 'sales', 'listings', 'approvals'], HomeTasks::GROUPS);
        foreach (HomeTasks::GROUP as $key => $group) {
            self::assertContains($group, HomeTasks::GROUPS, $key);
            self::assertArrayHasKey($group, Sections::MAP, "{$key}: a section of the sidebar");
        }
        self::assertSame(['Settings', 'Purchases', 'Inventory', 'Items', 'Sales', 'Store listings', 'Approvals'],
            array_map(static fn (string $g): string => Words::SECTION[$g], HomeTasks::GROUPS));
        self::assertSame(8, HomeTasks::FOLD);
    }

    /** A job's tab is the sidebar section of the page its row opens (RD-H2): the tab and the page it leads to agree. */
    public function testAJobsTabIsTheSectionItsRowOpens(): void
    {
        $f = array_replace(self::ownerToday(), [
            'code_off' => '2026-10-27 18:00:00.000000', 'pending' => 2, 'orders' => ['drafts' => 1, 'not_sent' => 1, 'not_ok' => 1], 'demand' => true, 'sales' => 4,
            'suppliers' => ['drafts' => 1, 'due' => 1], 'receiving' => ['bench' => 1, 'to_post' => 1, 'refused' => 1], 'incidents' => 1, 'website_bugs' => 1,
            'stock_ops' => ['drafts' => 1, 'waiting' => 1, 'drafts_kind' => 'transfer', 'waiting_kind' => 'release'],
            'staff' => ['test' => [['id' => 31, 'name' => 'T']], 'reviewers' => 1, 'clashes' => [['id' => 9, 'name' => 'Owner', 'off' => ['reviewer']], ['id' => 4, 'name' => 'Me', 'off' => ['buyer']]]],
            'integrity' => ['ok' => false, 'problems' => 2, 'finished_at' => '2026-10-20 01:00:00', 'hours' => 1], 'staff_requests' => 1,
            'watch' => [['at' => '2026-10-20 01:00:00', 'action' => 'staff.create', 'who' => 'A', 'person' => 'B', 'rule' => null]], 'setup_closed' => [['id' => 5, 'name' => 'C']],
        ]);
        $f['checks']['deliveries'] = 1;
        $f['checks']['review'] = 3;
        $seen = [];
        foreach ([['mapping_lead', 'reviewer'], ['admin'], ['buyer'], ['purchasing_desk'], ['stock_controller'], ['purchasing_manager', 'goods_in'], ['reviewer']] as $roles) {
            $home = HomeTasks::build(4, $roles, $f);
            foreach ([...$home['jobs'], ...$home['notes']] as $card) {
                parse_str((string) parse_url((string) $card['href'], PHP_URL_QUERY), $query);
                /** @var array<string, string> $query */
                $at = Sections::locate((string) parse_url((string) $card['href'], PHP_URL_PATH), $query);
                self::assertSame(HomeTasks::GROUP[$card['key']], $at['section'] ?? null, "{$card['key']}: {$card['href']}");
                $seen[$card['key']] = true;
            }
        }
        $stale = HomeTasks::build(4, ['admin'], ['integrity' => ['ok' => true, 'problems' => 0, 'finished_at' => '2026-10-01 01:00:00', 'hours' => 99]])['jobs'][0];
        self::assertSame(['integrity_stale', 'settings'], [$stale['key'], Sections::locate('/ui/system/checks')['section']]);
        $seen['integrity_stale'] = true;
        self::assertSame([], array_values(array_diff(array_keys(HomeTasks::GROUP), array_keys($seen))), 'every card was seen');
    }

    /** An urgent job (the card tone "blocked") comes first, red box and "Urgent" (RD-H4); the rank keeps the order inside each. */
    public function testUrgentJobsComeFirst(): void
    {
        $f = ['company' => ['confirmed' => false, 'missing' => []], 'checks' => ['approval' => 2, 'review' => 0], 'staff_requests' => 1,
            'setup_closed' => [['id' => 5, 'name' => 'C']], 'integrity' => ['ok' => false, 'problems' => 1, 'finished_at' => '2026-10-20 01:00:00', 'hours' => 1]];
        $jobs = HomeTasks::build(1, ['reviewer'], $f)['jobs'];
        self::assertSame(['integrity', 'setup_closed', 'company_confirm', 'approvals', 'staff_requests'], self::keys($jobs),
            'a closed set-up (rank 27) before the company details (rank 10): urgent first');
        self::assertSame([true, true, false, false, false], array_column($jobs, 'urgent'));
        foreach ($jobs as $card) {
            self::assertArrayNotHasKey('job', $card, 'no job numbers');
            self::assertArrayNotHasKey('hero', $card, 'no "Start here"');
        }
    }

    /**
     * The checklist (RD-H1-RD-H5): All with every job, then one tab per section that has jobs, in the owner's order, each with its count
     * and its plain link (`/ui/?jobs=<section>`; All `/ui/`); the tab shown has no link. A tab shows its jobs in their order; an unknown or
     * empty one is All; the head's total stays. 8 rows, the rest in the fold. "No job" is not a row.
     */
    public function testTheChecklist(): void
    {
        $jobs = HomeTasks::build(9, self::OWNER, self::ownerToday())['jobs'];
        $all = HomeTasks::checklist($jobs, null);
        self::assertSame(['total' => 10, 'tab' => 'all'], array_intersect_key($all, ['total' => 1, 'tab' => 1]));
        self::assertSame([
            ['key' => 'all', 'label' => 'All', 'count' => 10, 'href' => '/ui/', 'current' => true],
            ['key' => 'settings', 'label' => 'Settings', 'count' => 1, 'href' => '/ui/?jobs=settings', 'current' => false],
            ['key' => 'items', 'label' => 'Items', 'count' => 1, 'href' => '/ui/?jobs=items', 'current' => false],
            ['key' => 'listings', 'label' => 'Store listings', 'count' => 6, 'href' => '/ui/?jobs=listings', 'current' => false],
            ['key' => 'approvals', 'label' => 'Approvals', 'count' => 2, 'href' => '/ui/?jobs=approvals', 'current' => false],
        ], $all['tabs'], 'empty tabs hidden');
        self::assertSame(['company_confirm', 'approvals', 'spot_check', 'set_aside', 'duplicates', 'clues', 'checks', 'strong'], self::keys($all['rows']), '8 rows');
        self::assertSame(['other', 'barcodes'], self::keys($all['more']), 'the rest in the fold');
        self::assertNull($all['none']);

        $listings = HomeTasks::checklist($jobs, 'listings');
        self::assertSame(['listings', 10], [$listings['tab'], $listings['total']], 'the head keeps the total');
        self::assertSame(['spot_check', 'set_aside', 'duplicates', 'clues', 'strong', 'other'], self::keys($listings['rows']));
        self::assertSame([], $listings['more']);
        self::assertSame([['all', false, '/ui/'], ['listings', true, '/ui/?jobs=listings']],
            array_map(static fn (array $t): array => [$t['key'], $t['current'], $t['href']], array_values(array_filter($listings['tabs'],
                static fn (array $t): bool => in_array($t['key'], ['all', 'listings'], true)))));
        self::assertSame(['checks'], array_slice(self::keys(HomeTasks::checklist($jobs, 'approvals')['rows']), 1), 'the approvals tab');

        foreach (['nonsense', 'sales', 'home', 'reports', '', 'ALL'] as $want) {
            $c = HomeTasks::checklist($jobs, $want);
            self::assertSame(['all', self::keys($all['rows'])], [$c['tab'], self::keys($c['rows'])], "{$want}: unknown or empty is All");
        }
        self::assertSame(['total' => 0, 'tab' => 'all', 'tabs' => [], 'rows' => [], 'more' => [], 'none' => null], HomeTasks::checklist([], 'settings'), 'nothing waiting: no tab row');
        $none = HomeTasks::checklist(HomeTasks::build(8, [], [])['jobs'], null);
        self::assertSame(['no_job', 0, [], []], [$none['none']['key'] ?? null, $none['total'], $none['tabs'], $none['rows']], 'no job: not a row');
    }

    public function testTheOwnersHomeToday(): void
    {
        $home = HomeTasks::build(9, self::OWNER, self::ownerToday());
        // Blocking others first (company details, approvals), then the owner's own checks, then routine work.
        self::assertSame(['company_confirm', 'approvals', 'spot_check', 'set_aside', 'duplicates', 'clues', 'checks', 'strong', 'other', 'barcodes'],
            self::keys($home['jobs']));
        self::assertSame([], $home['notes']);
        foreach ($home['jobs'] as $card) {
            self::assertOneButtonToAPageTheyMayOpen($card, self::OWNER);
            self::assertSame(['needs', false], [$card['tone'], $card['urgent']]);
            self::assertSame(Words::HOME['needs_you'], $card['chip']);
            self::assertNotSame('', $card['line'] ?? '', "{$card['key']}: ONE short line");
            self::assertSame([], array_intersect(['text', 'what', 'job', 'hero'], array_keys($card)), "{$card['key']}: no paragraph, no job number, no \"Start here\"");
        }
        $by = array_column($home['jobs'], null, 'key');
        self::assertSame('Order PDFs say DO NOT SEND until then', $by['company_confirm']['line'], 'the missing details are the company page\'s');
        self::assertSame(Words::TASK['company_confirm']['button_missing'], $by['company_confirm']['button']);
        self::assertSame('/ui/reference/company', $by['company_confirm']['href']);
        self::assertSame('/ui/documents/reviews#approvals', $by['approvals']['href']);
        self::assertSame([1, 'waits for you'], [$by['approvals']['count'], $by['approvals']['unit']]);
        self::assertSame([2, 'wait for you'], [$by['checks']['count'], $by['checks']['unit']]);
        self::assertSame('/ui/documents/reviews#reviews', $by['checks']['href']);
        // The spot check: 0 of 20, one tap to the next member, opened from the spot check.
        self::assertSame('Spot check owner-1: are these 20 matches right?', $by['spot_check']['title']);
        self::assertSame([0, 'of 20 checked', [0, 20]], [$by['spot_check']['count'], $by['spot_check']['unit'], $by['spot_check']['progress']]);
        self::assertSame('Check the next one (1 of 20)', $by['spot_check']['button']);
        self::assertSame('/ui/review/listing/501?sample=1', $by['spot_check']['href']);
        self::assertSame('If all 20 are right, about 1,097 more are confirmed', $by['spot_check']['line']);
        self::assertSame([254, '/ui/review/samples/1#held'], [$by['set_aside']['count'], $by['set_aside']['href']]);
        self::assertSame('Looked doubtful: check each by hand (spot check owner-1)', $by['set_aside']['line']);
        self::assertSame([156, 'groups', '/ui/review/duplicates'], [$by['duplicates']['count'], $by['duplicates']['unit'], $by['duplicates']['href']],
            'the list, biggest sellers first (finding the first group is too slow for Home)');
        self::assertSame(Words::TASK['duplicates']['button'], $by['duplicates']['button']);
        self::assertSame([3, '/ui/review?queue=Conflict'], [$by['clues']['count'], $by['clues']['href']]);
        self::assertSame([1371, '/ui/review?queue=Key'], [$by['strong']['count'], $by['strong']['href']]);
        self::assertSame('Do spot check owner-1 first: it confirms about 1,097 of them', $by['strong']['line'],
            'the lead\'s own spot check comes first: it confirms most of them together');
        // The other lists: one card, the first list with work behind its button.
        self::assertSame(7, $by['other']['count']);
        self::assertSame(Words::TASK['other']['line'], $by['other']['line']);
        self::assertSame('/ui/review?queue=Check', $by['other']['href']);
        self::assertSame([4, '/ui/items/barcodes'], [$by['barcodes']['count'], $by['barcodes']['href']]);
        self::assertArrayNotHasKey('second_ok', $by, 'nothing waits for a second OK: no card');
    }

    public function testNothingWaitingMeansNoCard(): void
    {
        $f = ['company' => ['confirmed' => true, 'missing' => []], 'checks' => ['approval' => 0, 'review' => 0], 'samples' => [], 'held' => [],
            'duplicates' => 0, 'pending' => 0, 'bands' => [], 'barcodes' => 0, 'sales' => null];
        self::assertSame(['jobs' => [], 'notes' => []], HomeTasks::build(9, self::OWNER, $f));
        self::assertSame(['jobs' => [], 'notes' => []], HomeTasks::build(9, self::OWNER, []), 'no facts, no cards');
        self::assertSame(['jobs' => [], 'notes' => []], HomeTasks::build(5, ['viewer'], self::ownerToday()), 'look only: facts never make a card');
        $one = HomeTasks::build(9, self::OWNER, ['pending' => 1]);
        self::assertSame(['second_ok'], self::keys($one['jobs']));
        self::assertSame([1, 'waits for you', '/ui/review?queue=pending'], [$one['jobs'][0]['count'], $one['jobs'][0]['unit'], $one['jobs'][0]['href']]);
        self::assertSame([], self::keys(HomeTasks::build(9, self::OWNER, ['samples' => [], 'held' => [['sample_id' => 1, 'name' => 'x', 'open' => 0]]])['jobs']));
        // A matcher's strong matches: no spot check of theirs to do first (only a matching lead's own spot check says so).
        $mapper = HomeTasks::build(6, ['mapper'], self::ownerToday())['jobs'];
        self::assertSame(['strong', 'other'], self::keys($mapper));
        self::assertSame(Words::TASK['strong']['line'], $mapper[0]['line']);
        // The lead's spot check with nothing left beyond its 20: no "do the spot check first".
        $done = self::ownerToday();
        $done['samples'][0]['rest'] = 0;
        self::assertSame(Words::TASK['strong']['line'], array_column(HomeTasks::build(9, self::OWNER, $done)['jobs'], null, 'key')['strong']['line']);
        // A spot check with every member answered but not all confirmed yet: the button opens the spot check.
        $s = HomeTasks::build(9, self::OWNER, ['samples' => [['id' => 4, 'name' => 's', 'size' => 20, 'decided' => 20, 'rest' => 0, 'next_listing' => null,
            'next_position' => null]]])['jobs'][0];
        self::assertSame([Words::TASK['spot_check']['button_list'], '/ui/review/samples/4'], [$s['button'], $s['href']]);
        self::assertSame('If all 20 are right, the rest are confirmed together', $s['line']);
    }

    public function testTheOwnersAccountWithAdminGetsNoWorkItCannotDoOnlyItsOwnClash(): void
    {
        $roles = ['admin', 'mapping_lead', 'reviewer'];
        $f = self::ownerToday() + ['staff' => ['test' => [], 'reviewers' => 2, 'clashes' => [['id' => 9, 'name' => 'Owner', 'off' => ['mapping_lead', 'reviewer']]]]];
        $home = HomeTasks::build(9, $roles, $f);
        self::assertSame(['own_clash'], self::keys($home['jobs']), 'no spot check, duplicates or checks: Admin switches those jobs off, so this account '
            . 'cannot answer them');
        self::assertSame(Words::TASK['own_clash']['line'], $home['jobs'][0]['line'], 'a pointer to the strip, which has the whole sentence');
        self::assertNotNull(Words::switchedOffNote($roles));
        self::assertStringNotContainsString('second account', (string) $home['jobs'][0]['line'], 'correction a');
        self::assertOneButtonToAPageTheyMayOpen($home['jobs'][0], $roles);
        self::assertSame('/ui/people/9', $home['jobs'][0]['href']);
    }

    public function testTheAdminsCards(): void
    {
        $f = ['staff' => ['test' => [['id' => 31, 'name' => 'Placeholder']], 'reviewers' => 1, 'clashes' => [['id' => 9, 'name' => 'Owner', 'off' => ['reviewer', 'mapping_lead']]]]];
        $home = HomeTasks::build(3, ['admin'], $f);
        self::assertSame(['test_accounts', 'admin_clash', 'reviewers'], self::keys($home['jobs']));
        $by = array_column($home['jobs'], null, 'key');
        self::assertSame([1, 'account', '/ui/people/31', Words::TASK['test_accounts']['button']],
            [$by['test_accounts']['count'], $by['test_accounts']['unit'], $by['test_accounts']['href'], $by['test_accounts']['button']]);
        self::assertSame('Owner: jobs switched off by Admin', $by['admin_clash']['title']);
        self::assertSame('With Admin, these do nothing: Reviewer and Matching lead', $by['admin_clash']['line']);
        self::assertSame(['Open Owner', '/ui/people/9'], [$by['admin_clash']['button'], $by['admin_clash']['href']]);
        self::assertSame(Words::TASK['reviewers']['title'], $by['reviewers']['title']);
        self::assertSame('At least 2 are needed: give another person Reviewer', $by['reviewers']['line']);
        foreach ($home['jobs'] as $card) {
            self::assertOneButtonToAPageTheyMayOpen($card, ['admin']);
        }
        $two = HomeTasks::build(3, ['admin'], ['staff' => ['test' => [['id' => 31, 'name' => 'a'], ['id' => 32, 'name' => 'b']], 'reviewers' => 0, 'clashes' => []]]);
        self::assertSame([2, '/ui/people', Words::TASK['test_accounts']['button_many']], [$two['jobs'][0]['count'], $two['jobs'][0]['href'], $two['jobs'][0]['button']]);
        self::assertSame(Words::TASK['reviewers']['title_none'], $two['jobs'][1]['title']);
        self::assertSame([], HomeTasks::build(3, ['admin'], ['staff' => ['test' => [], 'reviewers' => 2, 'clashes' => []]])['jobs']);
    }

    public function testTheBuyersHomeWithNotesAfterTheJobs(): void
    {
        $f = ['company' => ['confirmed' => false, 'missing' => []], 'orders' => ['drafts' => 2, 'not_sent' => 1, 'not_ok' => 1], 'demand' => true, 'sales' => 6,
            'suppliers' => ['drafts' => 1, 'due' => 3]];
        $home = HomeTasks::build(4, ['buyer'], $f);
        self::assertSame(['not_ok', 'not_sent', 'drafts', 'supplier_drafts', 'checks_due', 'to_buy'], self::keys($home['jobs']));
        self::assertSame(['company_wait', 'old_sales'], self::keys($home['notes']), 'notes: something the buyer waits for, not a job');
        foreach ([...$home['jobs'], ...$home['notes']] as $card) {
            self::assertOneButtonToAPageTheyMayOpen($card, ['buyer']);
        }
        foreach ($home['notes'] as $note) {
            self::assertArrayNotHasKey('job', $note, 'a note has no job number');
            self::assertArrayNotHasKey('hero', $note);
            self::assertTrue($note['quiet']);
        }
        $by = array_column([...$home['jobs'], ...$home['notes']], null, 'key');
        self::assertSame(['/ui/purchasing/orders?rejected=1', '/ui/purchasing/orders?state=approved', '/ui/purchasing/orders?state=draft'],
            [$by['not_ok']['href'], $by['not_sent']['href'], $by['drafts']['href']]);
        self::assertSame([2, 'drafts'], [$by['drafts']['count'], $by['drafts']['unit']]);
        self::assertSame('Your own drafts: the supplier does not have them', $by['drafts']['line']);
        self::assertSame(Words::TASK['company_wait']['text'], $by['company_wait']['text'], 'a note keeps its words');
        self::assertSame(['/ui/purchasing/suppliers?status=draft', '/ui/purchasing/suppliers?due=1'], [$by['supplier_drafts']['href'], $by['checks_due']['href']]);
        self::assertSame('/ui/purchasing/reorder', $by['to_buy']['href']);
        self::assertArrayNotHasKey('count', $by['to_buy'], 'What to buy has no cheap count: the card has no number (plan C16)');
        self::assertSame(['Sales data is 6 days old', 'blocked', Words::HOME['out_of_date']], [$by['old_sales']['title'], $by['old_sales']['tone'], $by['old_sales']['chip']]);
        self::assertSame(['waiting', '/ui/reference/company'], [$by['company_wait']['tone'], $by['company_wait']['href']]);
        // A buyer's Home without demand data or late sales: no What to buy card, no note.
        self::assertSame(['not_ok', 'not_sent', 'drafts', 'supplier_drafts', 'checks_due'],
            self::keys(HomeTasks::build(4, ['buyer'], ['demand' => false, 'sales' => null] + $f)['jobs']));
        // The reviewer reads What to buy but does not buy: told the sales are late, never asked to buy.
        $reviewer = HomeTasks::build(5, ['reviewer'], ['sales' => 6, 'demand' => true]);
        self::assertSame([[], ['old_sales']], [self::keys($reviewer['jobs']), self::keys($reviewer['notes'])]);
    }

    /**
     * Deliveries (the plain-words pass of the delivery screens, U87): goods in and the purchasing desk see what waits for the
     * goods-in bench and what is checked and waits to be booked in (holding the stock up, so before routine work); a reviewer sees
     * the deliveries booked in to check as their own card, taken out of "Done work to check" so the two add up to the badge; the
     * people who close incidents see the open ones.
     */
    /**
     * The stock records (pack A1): the person's own drafts are a job; their records waiting for a reviewer's OK a note (the reviewer
     * acts: their "Things that need your OK" card counts them); each card opens the list of that kind, filtered.
     */
    public function testTheStockRecordCards(): void
    {
        self::assertSame(['stock_ops'], HomeTasks::needs(['warehouse']), 'the warehouse keeps transfers');
        $f = ['stock_ops' => ['drafts' => 2, 'waiting' => 1, 'drafts_kind' => 'out', 'waiting_kind' => 'adjust']];
        $cards = HomeTasks::build(9, ['stock_controller'], $f);
        self::assertSame(['stock_drafts'], self::keys($cards['jobs']));
        self::assertSame(['stock_waiting'], self::keys($cards['notes']));
        self::assertSame([2, 'drafts', '/ui/stock/out?state=draft'], [$cards['jobs'][0]['count'], $cards['jobs'][0]['unit'], $cards['jobs'][0]['href']]);
        self::assertSame([1, '/ui/stock/adjustments?state=awaiting_approval'], [$cards['notes'][0]['count'], $cards['notes'][0]['href']]);
        self::assertOneButtonToAPageTheyMayOpen($cards['jobs'][0], ['stock_controller']);
        self::assertSame([], HomeTasks::build(9, ['stock_controller'], ['stock_ops' => ['drafts' => 0, 'waiting' => 0, 'drafts_kind' => null, 'waiting_kind' => null]])['jobs']);
    }

    public function testTheDeliveryCards(): void
    {
        self::assertSame(['company', 'receiving'], HomeTasks::needs(['goods_in']));
        self::assertSame(['company', 'receiving', 'stock_ops', 'incidents', 'website_bugs'], HomeTasks::needs(['purchasing_desk']), 'they sort orders out (LP8)');
        self::assertSame(['barcodes', 'stock_ops', 'incidents', 'website_bugs'], HomeTasks::needs(['stock_controller']));

        $f = ['receiving' => ['bench' => 2, 'to_post' => 1, 'refused' => 1], 'incidents' => 3, 'company' => ['confirmed' => true, 'missing' => []]];
        $goodsIn = HomeTasks::build(5, ['goods_in'], $f);
        self::assertSame(['bench', 'bench_refused', 'to_post'], self::keys($goodsIn['jobs']), 'goods in may book in too (doc.GRN.post), but closes no incident');
        $desk = HomeTasks::build(6, ['purchasing_desk'], $f);
        self::assertSame(['bench', 'bench_refused', 'to_post', 'incidents'], self::keys($desk['jobs']));
        $by = array_column($desk['jobs'], null, 'key');
        self::assertSame([2, 'deliveries', '/ui/receiving/bench'], [$by['bench']['count'], $by['bench']['unit'], $by['bench']['href']]);
        self::assertSame([1, 'delivery', '/ui/receiving?state=refused'], [$by['bench_refused']['count'], $by['bench_refused']['unit'], $by['bench_refused']['href']],
            'a delivery the bench refused (paperwork not right) is on nobody\'s other card: the desk cancels it');
        self::assertSame([1, 'delivery', '/ui/receiving?state=checked'], [$by['to_post']['count'], $by['to_post']['unit'], $by['to_post']['href']]);
        self::assertSame([3, 'incidents', '/ui/receiving/incidents'], [$by['incidents']['count'], $by['incidents']['unit'], $by['incidents']['href']]);
        foreach ($desk['jobs'] as $card) {
            self::assertOneButtonToAPageTheyMayOpen($card, ['purchasing_desk']);
            self::assertArrayHasKey('line', $card);
        }
        self::assertSame(['incidents'], self::keys(HomeTasks::build(7, ['stock_controller'], $f)['jobs']));
        self::assertSame([], HomeTasks::build(5, ['goods_in'], ['receiving' => ['bench' => 0, 'to_post' => 0]])['jobs'], 'nothing waiting: no card');
        self::assertSame([], HomeTasks::build(8, ['reviewer'], $f)['jobs'], 'a reviewer neither checks at the bench nor closes incidents');

        // The reviewer: 3 reviews, 2 of them deliveries booked in (the bench checker's own are never counted: Documents, I133).
        $rev = HomeTasks::build(8, ['reviewer'], ['checks' => ['approval' => 0, 'review' => 3, 'deliveries' => 2]])['jobs'];
        self::assertSame(['checks', 'deliveries_check'], self::keys($rev));
        $by = array_column($rev, null, 'key');
        self::assertSame([1, 2], [$by['checks']['count'], $by['deliveries_check']['count']], 'the two cards add up to the badge');
        self::assertSame('/ui/documents/reviews?type=GRN#reviews', $by['deliveries_check']['href']);
        self::assertOneButtonToAPageTheyMayOpen($by['deliveries_check'], ['reviewer']);
        self::assertSame(['deliveries_check'], self::keys(HomeTasks::build(8, ['reviewer'], ['checks' => ['approval' => 0, 'review' => 2, 'deliveries' => 2]])['jobs']),
            'only deliveries: no "Done work to check" card');
        // The order: holding the stock up first, the checks after the owner's own, incidents with the routine work.
        self::assertLessThan(HomeTasks::RANK['spot_check'], HomeTasks::RANK['bench']);
        self::assertLessThan(HomeTasks::RANK['spot_check'], HomeTasks::RANK['to_post']);
        self::assertLessThan(HomeTasks::RANK['spot_check'], HomeTasks::RANK['bench_refused']);
        self::assertGreaterThan(HomeTasks::RANK['checks'], HomeTasks::RANK['deliveries_check']);
        self::assertGreaterThan(200, HomeTasks::RANK['incidents']);
    }

    public function testAPersonWithNoJobIsToldWhoToAsk(): void
    {
        $home = HomeTasks::build(8, [], self::ownerToday());
        self::assertSame(['no_job'], self::keys($home['jobs']));
        $card = $home['jobs'][0];
        self::assertSame(Words::TASK['no_job']['text'], $card['text']);
        self::assertStringContainsString(Words::ASK_ROLE, $card['text']);
        self::assertArrayNotHasKey('href', $card, 'nothing to open');
        self::assertArrayNotHasKey('job', $card);
        self::assertSame('blocked', $card['tone']);
    }

    /**
     * The set-it-yourself pack (G36, Y25, Y8): the nightly safety check's card for the people who look after the system (a problem
     * found, or no check for STALE_HOURS once one has run; nothing before the first run), a reviewer's card for grants of Admin or
     * Reviewer waiting for their OK, and the reviewers card following staff.min_reviewers.
     */
    public function testTheSafetyCheckStaffRequestAndReviewerCards(): void
    {
        $bad = HomeTasks::build(1, ['admin'], ['staff' => ['test' => [], 'reviewers' => 2, 'clashes' => [], 'min' => 2],
            'integrity' => ['ok' => false, 'problems' => 3, 'finished_at' => '2026-10-08 02:47:00', 'hours' => 2]]);
        self::assertSame(['integrity'], self::keys($bad['jobs']));
        self::assertSame(sprintf(Words::TASK['integrity']['title'], '3'), $bad['jobs'][0]['title']);
        self::assertSame('/ui/system/checks', $bad['jobs'][0]['href']);
        self::assertTrue($bad['jobs'][0]['urgent'], 'a broken check is urgent');
        $one = HomeTasks::build(1, ['admin'], ['integrity' => ['ok' => false, 'problems' => 1, 'finished_at' => '2026-10-08 02:47:00', 'hours' => 2]]);
        self::assertSame(Words::TASK['integrity']['title_one'], $one['jobs'][0]['title']);
        $stale = HomeTasks::build(1, ['reviewer'], ['integrity' => ['ok' => true, 'problems' => 0, 'finished_at' => '2026-10-05 02:47:00',
            'hours' => \CW\Ops\IntegrityRuns::STALE_HOURS]]);
        self::assertSame(['integrity_stale'], self::keys($stale['jobs']));
        self::assertStringContainsString('5 Oct 2026', $stale['jobs'][0]['title']);
        $fresh = HomeTasks::build(1, ['reviewer'], ['integrity' => ['ok' => true, 'problems' => 0, 'finished_at' => '2026-10-08 02:47:00', 'hours' => 3]]);
        self::assertSame([], self::keys($fresh['jobs']), 'a fresh check that held: no card');
        self::assertSame([], self::keys(HomeTasks::build(1, ['reviewer'], ['integrity' => null])['jobs']), 'no check ran yet: no card');
        self::assertSame([], self::keys(HomeTasks::build(1, ['buyer'], ['integrity' => ['ok' => false, 'problems' => 2, 'finished_at' => '2026-10-08 02:47:00',
            'hours' => 1]])['jobs']), 'not for a buyer (system.view)');

        $req = HomeTasks::build(1, ['reviewer'], ['staff_requests' => 2]);
        self::assertSame(['staff_requests'], self::keys($req['jobs']));
        self::assertSame('/ui/staff-requests', $req['jobs'][0]['href']);
        self::assertSame(2, $req['jobs'][0]['count']);
        self::assertSame([], self::keys(HomeTasks::build(1, ['reviewer'], ['staff_requests' => 0])['jobs']));

        $few = HomeTasks::build(1, ['admin'], ['staff' => ['test' => [], 'reviewers' => 1, 'clashes' => [], 'min' => 1]]);
        self::assertSame([], self::keys($few['jobs']), 'the owner set staff.min_reviewers to 1: one reviewer is enough');
        $more = HomeTasks::build(1, ['admin'], ['staff' => ['test' => [], 'reviewers' => 2, 'clashes' => [], 'min' => 3]]);
        self::assertSame(['reviewers'], self::keys($more['jobs']));
        self::assertSame(sprintf(Words::TASK['reviewers']['line'], '3'), $more['jobs'][0]['line']);
    }
}
