<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\Ui\HomeTasks;
use CW\Ui\Kernel;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * Home's "What needs doing" (plan §3.2, design A with B's parts; Ui\HomeTasks, pure): which cards a person gets for their
 * jobs, that nothing waiting means no card, the order (what holds other people up first, then the owner's own checks, then
 * routine work), B's job numbers with "Start here" on the first, one button each to a page the person may open, and the
 * notes after the jobs. The counts themselves are HomeCounts' (HomeScreenTest, through the real kernel).
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
        self::assertSame(['company', 'checks', 'samples', 'held', 'duplicates', 'pending', 'bands', 'barcodes', 'sales'], HomeTasks::needs(self::OWNER));
        self::assertSame(['company', 'orders', 'demand', 'sales', 'suppliers'], HomeTasks::needs(['buyer']));
        self::assertSame(['staff'], HomeTasks::needs(['admin']));
        self::assertSame(['staff'], HomeTasks::needs(['admin', 'mapping_lead', 'reviewer']), 'Admin switches the owner\'s jobs off: no matching or checking counts');
        self::assertSame(['bands'], HomeTasks::needs(['mapper']));
        self::assertSame([], HomeTasks::needs(['viewer']), 'look only: nothing to count');
        self::assertSame([], HomeTasks::needs([]));
        foreach (HomeTasks::FACTS as $fact => $perms) {
            foreach ($perms as $perm) {
                self::assertArrayHasKey($perm, Permissions::MAP, $fact);
            }
        }
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

    public function testTheOwnersHomeToday(): void
    {
        $home = HomeTasks::build(9, self::OWNER, self::ownerToday());
        // Blocking others first (company details, approvals), then the owner's own checks, then routine work.
        self::assertSame(['company_confirm', 'approvals', 'spot_check', 'set_aside', 'duplicates', 'clues', 'checks', 'strong', 'other', 'barcodes'],
            self::keys($home['jobs']));
        self::assertSame([], $home['notes']);
        self::assertSame(range(1, 10), array_column($home['jobs'], 'job'), 'B\'s job numbers, in order');
        self::assertTrue($home['jobs'][0]['hero'] ?? false, 'the first is "Start here"');
        self::assertSame(1, count(array_filter($home['jobs'], static fn (array $c): bool => !empty($c['hero']))));
        foreach ($home['jobs'] as $card) {
            self::assertOneButtonToAPageTheyMayOpen($card, self::OWNER);
            self::assertSame('needs', $card['tone']);
            self::assertSame(Words::HOME['needs_you'], $card['chip']);
            self::assertArrayHasKey('what', $card, "{$card['key']}: B's \"What happens:\" line");
        }
        $by = array_column($home['jobs'], null, 'key');
        self::assertSame('Until they are confirmed, every purchase order PDF says DO NOT SEND. Still missing: company number and purchasing e-mail.',
            $by['company_confirm']['text']);
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
        self::assertStringContainsString('about 1,097 more strong matches are confirmed together', $by['spot_check']['text']);
        self::assertSame([254, '/ui/review/samples/1#held'], [$by['set_aside']['count'], $by['set_aside']['href']]);
        self::assertSame([156, 'groups', '/ui/review/duplicates'], [$by['duplicates']['count'], $by['duplicates']['unit'], $by['duplicates']['href']],
            'the list, biggest sellers first (finding the first group is too slow for Home)');
        self::assertSame(Words::TASK['duplicates']['button'], $by['duplicates']['button']);
        self::assertSame([3, '/ui/review?queue=Conflict'], [$by['clues']['count'], $by['clues']['href']]);
        self::assertSame([1371, '/ui/review?queue=Key'], [$by['strong']['count'], $by['strong']['href']]);
        self::assertSame(Words::TASK['strong']['text'] . ' If your spot check owner-1 passes, about 1,097 of them are confirmed together, so do the spot check first.',
            $by['strong']['text'], 'the lead\'s own spot check comes first: it confirms most of them together');
        self::assertStringContainsString('someone else\'s spot check, leave it to them', $by['strong']['what'], 'another lead\'s answer fails a spot check');
        // The other lists: one card, the first list with work behind its button.
        self::assertSame(7, $by['other']['count']);
        self::assertSame('Likely matches – check them: 5 · Not sure – you choose: 2', $by['other']['text']);
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
        self::assertSame(Words::TASK['strong']['text'], $mapper[0]['text']);
        // The lead's spot check with nothing left beyond its 20: no "do the spot check first".
        $done = self::ownerToday();
        $done['samples'][0]['rest'] = 0;
        self::assertSame(Words::TASK['strong']['text'], array_column(HomeTasks::build(9, self::OWNER, $done)['jobs'], null, 'key')['strong']['text']);
        // A spot check with every member answered but not all confirmed yet: the button opens the spot check.
        $s = HomeTasks::build(9, self::OWNER, ['samples' => [['id' => 4, 'name' => 's', 'size' => 20, 'decided' => 20, 'rest' => 0, 'next_listing' => null,
            'next_position' => null]]])['jobs'][0];
        self::assertSame([Words::TASK['spot_check']['button_list'], '/ui/review/samples/4'], [$s['button'], $s['href']]);
        self::assertStringStartsWith('If all 20 are right, the other strong matches', $s['text']);
    }

    public function testTheOwnersAccountWithAdminGetsNoWorkItCannotDoOnlyItsOwnClash(): void
    {
        $roles = ['admin', 'mapping_lead', 'reviewer'];
        $f = self::ownerToday() + ['staff' => ['test' => [], 'reviewers' => 2, 'clashes' => [['id' => 9, 'name' => 'Owner', 'off' => ['mapping_lead', 'reviewer']]]]];
        $home = HomeTasks::build(9, $roles, $f);
        self::assertSame(['own_clash'], self::keys($home['jobs']), 'no spot check, duplicates or checks: Admin switches those jobs off, so this account '
            . 'cannot answer them');
        self::assertSame(Words::TASK['own_clash']['text'], $home['jobs'][0]['text'], 'a pointer to the strip, which has the whole sentence');
        self::assertNotNull(Words::switchedOffNote($roles));
        self::assertStringNotContainsString('second account', (string) $home['jobs'][0]['text'], 'correction a');
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
        self::assertSame('Owner has Admin, so these jobs do nothing: Reviewer and Matching lead.', $by['admin_clash']['text']);
        self::assertSame(['Open Owner', '/ui/people/9'], [$by['admin_clash']['button'], $by['admin_clash']['href']]);
        self::assertSame(Words::TASK['reviewers']['title'], $by['reviewers']['title']);
        self::assertSame('At least 2 Reviewers are needed, so a holiday never stops the checks.', $by['reviewers']['text']);
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
        self::assertStringContainsString('over the approval limit goes to a reviewer first', $by['drafts']['what'], 'correction f');
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
    public function testTheDeliveryCards(): void
    {
        self::assertSame(['company', 'receiving'], HomeTasks::needs(['goods_in']));
        self::assertSame(['company', 'receiving', 'incidents'], HomeTasks::needs(['purchasing_desk']));
        self::assertSame(['barcodes', 'incidents'], HomeTasks::needs(['stock_controller']));

        $f = ['receiving' => ['bench' => 2, 'to_post' => 1], 'incidents' => 3, 'company' => ['confirmed' => true, 'missing' => []]];
        $goodsIn = HomeTasks::build(5, ['goods_in'], $f);
        self::assertSame(['bench', 'to_post'], self::keys($goodsIn['jobs']), 'goods in may book in too (doc.GRN.post), but closes no incident');
        $desk = HomeTasks::build(6, ['purchasing_desk'], $f);
        self::assertSame(['bench', 'to_post', 'incidents'], self::keys($desk['jobs']));
        $by = array_column($desk['jobs'], null, 'key');
        self::assertSame([2, 'deliveries', '/ui/receiving/bench'], [$by['bench']['count'], $by['bench']['unit'], $by['bench']['href']]);
        self::assertSame([1, 'delivery', '/ui/receiving?state=checked'], [$by['to_post']['count'], $by['to_post']['unit'], $by['to_post']['href']]);
        self::assertSame([3, 'incidents', '/ui/receiving/incidents'], [$by['incidents']['count'], $by['incidents']['unit'], $by['incidents']['href']]);
        foreach ($desk['jobs'] as $card) {
            self::assertOneButtonToAPageTheyMayOpen($card, ['purchasing_desk']);
            self::assertArrayHasKey('what', $card);
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
}
