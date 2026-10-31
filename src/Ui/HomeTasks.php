<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Auth\Permissions;
use CW\Ui\Controller\PeopleController;

/**
 * Home's "What needs doing" (plan §3.2, design A with B's parts): one person's task cards, built from counts (Ui\HomeCounts)
 * by what their jobs let them do. Pure, no database: every card, its words and the order are unit-tested (HomeTasksTest).
 *
 * needs() names the facts a person's jobs can use, so HomeCounts runs no query for a card they never see. build() turns the
 * facts into cards. A card with nothing waiting is left out. Each job has ONE short line, its number when there is one and ONE
 * button, to a page the person may open (the owner's checklist of 31 Oct 2026; docs/decisions.md RD-H1-RD-H8). Jobs are sorted:
 * an urgent one first (the tone "blocked": a red box and "Urgent" on Home), then by RANK: what holds other people up first (below
 * 100), then the owner's own checks (100-199), then routine work (200 and up). Some cards are notes, not jobs (NOTES: the company
 * details a buyer waits for, sales data that is out of date, ...): they follow the jobs with their own words, outside the tabs. A
 * person with no job gets only the card that says so.
 *
 * checklist() lays the jobs out as Home's "Waiting for you" draws them: All, then one tab per sidebar section that has jobs (GROUP:
 * the section of the page a job's row opens; GROUPS: the tabs' order), the tab asked for by `?jobs=` (an unknown or empty one is All),
 * its first FOLD rows and the rest for the fold.
 *
 * The counts are the badges' (Context::badges, Context::checks) wherever a badge exists, so a card and its badge always agree,
 * and every card counts only what this person can act on (no card for work they could never clear).
 */
final class HomeTasks
{
    /** Card key => its place: blocking others (< 100), the owner's own checks (100-199), routine (200+). */
    public const RANK = [
        'code_off' => 3, // 2-step verification switched off (TF5): before everything else
        'integrity' => 5,
        'integrity_stale' => 6,
        'company_confirm' => 10,
        'approvals' => 20,
        'website_bugs' => 22, // an order for a landing page (docs/decisions.md LP8): a customer waits for the right option
        'staff_requests' => 25,
        'setup_closed' => 27,
        'second_ok' => 30,
        'test_accounts' => 40,
        'own_clash' => 41,
        'admin_clash' => 42,
        'reviewers' => 43,
        'not_ok' => 50,
        'not_sent' => 60,
        'bench' => 65,
        'bench_refused' => 67,
        'to_post' => 70,
        'spot_check' => 100,
        'set_aside' => 110,
        'duplicates' => 120,
        'clues' => 130,
        'checks' => 140,
        'deliveries_check' => 145,
        'drafts' => 200,
        'stock_drafts' => 205,
        'supplier_drafts' => 210,
        'checks_due' => 215,
        'incidents' => 217,
        'to_buy' => 220,
        'strong' => 230,
        'other' => 240,
        'barcodes' => 250,
    ];

    /**
     * Cards that inform and wait (no job number, after the jobs): the company details a buyer waits for, old sales data, and for a
     * reviewer the staff and rule changes of the last days to look at (Y45: nothing waits on them, so it is not a job).
     */
    public const NOTES = ['company_wait', 'old_sales', 'watch', 'stock_waiting'];

    /**
     * Card key => its tab on Home: the sidebar section (Sections::MAP) of the page its row opens (docs/decisions.md RD-H2; HomeTasksTest
     * checks each against Sections::locate). The notes have one too, though they are drawn outside the tabs.
     */
    public const GROUP = [
        'code_off' => 'settings', 'company_confirm' => 'settings', 'company_wait' => 'settings', 'test_accounts' => 'settings', 'reviewers' => 'settings',
        'admin_clash' => 'settings', 'own_clash' => 'settings', 'integrity' => 'settings', 'integrity_stale' => 'settings', 'setup_closed' => 'settings',
        'watch' => 'settings',
        'bench' => 'purchases', 'bench_refused' => 'purchases', 'to_post' => 'purchases', 'incidents' => 'purchases', 'drafts' => 'purchases',
        'not_sent' => 'purchases', 'not_ok' => 'purchases', 'to_buy' => 'purchases', 'old_sales' => 'purchases', 'supplier_drafts' => 'purchases',
        'checks_due' => 'purchases',
        'stock_drafts' => 'inventory', 'stock_waiting' => 'inventory',
        'barcodes' => 'items',
        'spot_check' => 'listings', 'duplicates' => 'listings', 'set_aside' => 'listings', 'second_ok' => 'listings', 'clues' => 'listings',
        'strong' => 'listings', 'other' => 'listings',
        'approvals' => 'approvals', 'checks' => 'approvals', 'deliveries_check' => 'approvals', 'website_bugs' => 'approvals', 'staff_requests' => 'approvals',
    ];

    /** The tabs after All, in the owner's order (a tab with no job is not drawn). */
    public const GROUPS = ['settings', 'purchases', 'inventory', 'items', 'sales', 'listings', 'approvals'];

    /** Rows shown before "Show N more" (Main.dc.html). */
    public const FOLD = 8;

    /** Fact (HomeCounts) => the permissions whose cards use it (any one of them). */
    public const FACTS = [
        'company' => ['company.confirm', 'doc.PO.post', 'purchasing.view'],
        'checks' => ['documents.review'],
        'samples' => ['mapping.approve'],
        'held' => ['mapping.approve'],
        'duplicates' => ['mapping.approve'],
        'pending' => ['mapping.approve'],
        'bands' => ['mapping.decide', 'mapping.approve'],
        'barcodes' => ['catalogue.edit'],
        'orders' => ['doc.PO.post'],
        'demand' => ['reorder.manage'],
        'sales' => ['reorder.view'],
        'suppliers' => ['suppliers.manage'],
        'staff' => ['staff.manage'],
        'receiving' => ['doc.GRN.post'],
        'stock_ops' => ['doc.SIN.post', 'doc.SOUT.post', 'doc.ADJ.post', 'doc.TRF.post', 'doc.REL.post'],
        'incidents' => ['incidents.resolve'],
        'website_bugs' => ['queues.oversell'],
        'integrity' => ['system.view'],
        'staff_requests' => ['staff.approve'],
        'watch' => ['staff.approve'],
        'setup_closed' => ['staff.manage', 'staff.approve'],
        'code_off' => ['settings.manage'],
    ];
    /** Lines the "changes to look at" card shows (the rest: the audit log). */
    public const WATCH_LINES = 8;

    /** The lists of "Other website products to match", in the order the button takes the first one with work. */
    public const OTHER_BANDS = ['Check', 'New item', "Can't tell", 'Manual'];

    /**
     * The facts the roles' cards use (HomeCounts computes only these).
     *
     * @param list<string> $roles
     * @return list<string>
     */
    public static function needs(array $roles): array
    {
        $out = [];
        foreach (self::FACTS as $fact => $perms) {
            foreach ($perms as $perm) {
                if (Permissions::can($roles, $perm)) {
                    $out[] = $fact;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * The cards of one person.
     *
     * Facts (HomeCounts::facts; a fact the person's jobs do not use is absent):
     *  company   {confirmed: bool, missing: list<string>}         checks  {approval: int, review: int}
     *  samples   list<{id, name, size, decided, rest, next_listing: ?int, next_position: ?int}>: the person's own spot checks
     *            still waiting for answers
     *  held      list<{sample_id, name, open}>: matches set aside from a spot check that has not failed, still waiting
     *  duplicates int (open groups)   pending int   bands array<band, int>   barcodes int
     *  orders    {drafts: int (the person's own), not_sent: int, not_ok: int}   demand bool   sales ?int (days the oldest sales
     *            data is late, null when none is late)   suppliers {drafts: int, due: int}
     *  staff     {test: list<{id, name}>, reviewers: int, clashes: list<{id, name, off: list<string>}>}
     *  receiving {bench: int (deliveries not booked in that wait for the goods-in bench), to_post: int (checked at the bench, not booked in)}
     *  incidents int (open incidents, the badge's count)
 *  website_bugs int (orders for a landing page not handled yet: the badge's count, LP8)
 *  integrity {ok: bool, problems: int, finished_at: string, hours: int}|null: the latest nightly safety check (null: none ran yet)
 *  staff_requests int (open requests for Admin or Reviewer this reviewer may decide)
 *  watch     list<{at: string, action: string, who: ?string, person: ?string, rule: ?string}>: people added, sign-ins reset and
 *            approval rules made looser in the last 7 days, newest first (review finding I1)
 *  setup_closed list<{id: int, name: string}>: active people whose sign-in set-up closed after too many wrong tries (I5)
 *  code_off  ?string: until when (a UTC database time) the sign-in asks for no 6-digit code (auth.totp_off_until, TF5), null: it asks
     *  checks    also `deliveries` (optional): the part of `review` that is deliveries booked in (their own card)
     *
     * @param list<string> $roles
     * @param array<string, mixed> $f
     * @return array{jobs: list<array<string, mixed>>, notes: list<array<string, mixed>>} cards for the `$cards` view helper
     */
    public static function build(int $meId, array $roles, array $f): array
    {
        if ($roles === []) {
            return ['jobs' => [self::card('no_job', ['tone' => 'blocked', 'chip' => null, 'href' => null])], 'notes' => []];
        }
        $can = static fn (string $perm): bool => Permissions::can($roles, $perm);
        $out = [];

        // 2-step verification switched off for a while (auth.totp_off_until, TF5): the people who can switch it back on see it first.
        if ($can('settings.manage') && is_string($f['code_off'] ?? null)) {
            $out[] = self::card('code_off', ['line' => sprintf(self::word('code_off', 'line'), Html::when($f['code_off'])), 'tone' => 'blocked',
                'href' => Html::url('/ui/reference/settings/setting', ['key' => \CW\Auth\SecondStep::SETTING])]);
        }

        // The company details: a reviewer confirms them; until then a buyer's PDFs say DO NOT SEND (what is missing: the company page's).
        $company = $f['company'] ?? null;
        if (is_array($company) && !$company['confirmed']) {
            if ($can('company.confirm')) {
                $missing = $company['missing'] ?? [];
                $out[] = self::card('company_confirm', [
                    'button' => self::word('company_confirm', $missing === [] ? 'button' : 'button_missing'),
                    'href' => '/ui/reference/company',
                ]);
            } elseif ($can('doc.PO.post') || $can('purchasing.view')) {
                $out[] = self::card('company_wait', ['href' => '/ui/reference/company', 'tone' => 'waiting', 'chip' => Words::HOME['waiting']]);
            }
        }

        // The review queue, split: blocking approvals, done work to check, and deliveries booked in to check (the reviews of goods
        // receipts, never offered to their bench checker: Documents::decidableCountsByType, I133).
        if ($can('documents.review') && is_array($f['checks'] ?? null)) {
            $deliveries = (int) ($f['checks']['deliveries'] ?? 0);
            $out[] = self::counted('approvals', (int) $f['checks']['approval'], '/ui/documents/reviews#approvals');
            $out[] = self::counted('checks', (int) $f['checks']['review'] - $deliveries, '/ui/documents/reviews#reviews');
            $out[] = self::counted('deliveries_check', $deliveries, Html::url('/ui/documents/reviews', ['type' => 'GRN']) . '#reviews');
        }

        // Matching: the person's own spot checks, set-aside matches, duplicates, the second OK, the lists.
        if ($can('mapping.approve')) {
            foreach ($f['samples'] ?? [] as $s) {
                $next = $s['next_listing'] ?? null;
                $out[] = self::card('spot_check', [
                    'title' => sprintf(self::word('spot_check', 'title'), $s['name'], Html::int($s['size'])),
                    'count' => (int) $s['decided'], 'unit' => sprintf(self::word('spot_check', 'unit'), Html::int($s['size'])),
                    'progress' => [(int) $s['decided'], (int) $s['size']],
                    'line' => (int) $s['rest'] > 0
                        ? sprintf(self::word('spot_check', 'line'), Html::int($s['size']), Html::int($s['rest']))
                        : sprintf(self::word('spot_check', 'line_no_rest'), Html::int($s['size'])),
                    'button' => $next !== null
                        ? sprintf(self::word('spot_check', 'button'), Html::int($s['next_position'] ?? 0), Html::int($s['size']))
                        : self::word('spot_check', 'button_list'),
                    'href' => $next !== null ? Html::url('/ui/review/listing/' . (int) $next, ['sample' => (int) $s['id']]) : '/ui/review/samples/' . (int) $s['id'],
                ]);
            }
            foreach ($f['held'] ?? [] as $h) {
                $out[] = self::counted('set_aside', (int) $h['open'], '/ui/review/samples/' . (int) $h['sample_id'] . '#held',
                    ['line' => sprintf(self::word('set_aside', 'line'), $h['name'])]);
            }
            $out[] = self::counted('duplicates', (int) ($f['duplicates'] ?? 0), '/ui/review/duplicates');
            $out[] = self::counted('second_ok', (int) ($f['pending'] ?? 0), Html::url('/ui/review', ['queue' => 'pending']));
            $out[] = self::counted('clues', (int) ($f['bands']['Conflict'] ?? 0), Html::url('/ui/review', ['queue' => 'Conflict']));
        }
        if ($can('mapping.decide')) {
            $bands = is_array($f['bands'] ?? null) ? $f['bands'] : [];
            $strong = (int) ($bands['Key'] ?? 0);
            // The lead's own spot check still waiting: most strong matches are confirmed together if it passes, so it comes first.
            $mine = $can('mapping.approve') ? (($f['samples'] ?? [])[0] ?? null) : null;
            $out[] = self::counted('strong', $strong, Html::url('/ui/review', ['queue' => 'Key']), is_array($mine) && (int) $mine['rest'] > 0
                ? ['line' => sprintf(self::word('strong', 'line_spot'), $mine['name'], Html::int(min((int) $mine['rest'], $strong)))] : []);
            $first = null;
            $total = 0;
            foreach (self::OTHER_BANDS as $band) {
                $n = (int) ($bands[$band] ?? 0);
                if ($n > 0) {
                    $first ??= $band;
                    $total += $n;
                }
            }
            $out[] = self::counted('other', $total, Html::url('/ui/review', ['queue' => $first ?? 'Check']));
        }
        if ($can('catalogue.edit')) {
            $out[] = self::counted('barcodes', (int) ($f['barcodes'] ?? 0), '/ui/items/barcodes');
        }

        // Buying.
        if ($can('doc.PO.post') && is_array($f['orders'] ?? null)) {
            $out[] = self::counted('drafts', (int) $f['orders']['drafts'], Html::url('/ui/purchasing/orders', ['state' => 'draft']));
            $out[] = self::counted('not_sent', (int) $f['orders']['not_sent'], Html::url('/ui/purchasing/orders', ['state' => 'approved']));
            $out[] = self::counted('not_ok', (int) $f['orders']['not_ok'], Html::url('/ui/purchasing/orders', ['rejected' => '1']));
        }
        if ($can('reorder.manage') && ($f['demand'] ?? false) === true) {
            $out[] = self::card('to_buy', ['href' => '/ui/purchasing/reorder']);
        }
        if ($can('reorder.view') && is_int($f['sales'] ?? null)) {
            $out[] = self::card('old_sales', ['title' => sprintf(self::word('old_sales', 'title'), Html::int($f['sales'])), 'href' => '/ui/purchasing/sales-history',
                'tone' => 'blocked', 'chip' => Words::HOME['out_of_date']]);
        }
        if ($can('suppliers.manage') && is_array($f['suppliers'] ?? null)) {
            $out[] = self::counted('supplier_drafts', (int) $f['suppliers']['drafts'], Html::url('/ui/purchasing/suppliers', ['status' => 'draft']));
            $out[] = self::counted('checks_due', (int) $f['suppliers']['due'], Html::url('/ui/purchasing/suppliers', ['due' => '1']));
        }

        // Deliveries: waiting for the goods-in bench, refused there (to cancel), checked and waiting to be booked in, open incidents.
        if ($can('doc.GRN.post') && is_array($f['receiving'] ?? null)) {
            $out[] = self::counted('bench', (int) $f['receiving']['bench'], '/ui/receiving/bench');
            $out[] = self::counted('bench_refused', (int) ($f['receiving']['refused'] ?? 0), Html::url('/ui/receiving', ['state' => 'refused']));
            $out[] = self::counted('to_post', (int) $f['receiving']['to_post'], Html::url('/ui/receiving', ['state' => 'checked']));
        }
        // Stock records (pack A1): the person's own drafts, and their records waiting for a reviewer's OK (a note: the reviewer acts).
        if (is_array($f['stock_ops'] ?? null)) {
            $so = $f['stock_ops'];
            $out[] = self::counted('stock_drafts', (int) $so['drafts'], Html::url(\CW\StockOps\StockOps::PATHS[$so['drafts_kind'] ?? 'in'], ['state' => 'draft']));
            if ((int) $so['waiting'] > 0) {
                $out[] = self::counted('stock_waiting', (int) $so['waiting'], Html::url(\CW\StockOps\StockOps::PATHS[$so['waiting_kind'] ?? 'in'], ['state' => 'awaiting_approval']),
                    ['tone' => 'review', 'chip' => Words::HOME['waiting']]);
            }
        }
        if ($can('incidents.resolve')) {
            $out[] = self::counted('incidents', (int) ($f['incidents'] ?? 0), '/ui/receiving/incidents');
        }
        // Website bugs (LP8): orders a store took for a landing page, for the people who sort orders out with the customer.
        if ($can('queues.oversell')) {
            $out[] = self::counted('website_bugs', (int) ($f['website_bugs'] ?? 0), '/ui/queues/landing');
        }

        // Staff access (the admin): test accounts, too few reviewers, jobs that Admin switches off.
        $staff = $f['staff'] ?? null;
        if ($can('staff.manage') && is_array($staff)) {
            $test = $staff['test'] ?? [];
            if ($test !== []) {
                $one = count($test) === 1;
                $out[] = self::counted('test_accounts', count($test), $one ? '/ui/people/' . (int) $test[0]['id'] : '/ui/people',
                    ['button' => self::word('test_accounts', $one ? 'button' : 'button_many')]);
            }
            $reviewers = (int) ($staff['reviewers'] ?? 0);
            $min = (int) ($staff['min'] ?? PeopleController::MIN_REVIEWERS); // staff.min_reviewers (the Settings page)
            if ($reviewers < $min) {
                $out[] = self::card('reviewers', ['title' => self::word('reviewers', $reviewers === 0 ? 'title_none' : 'title'),
                    'line' => sprintf(self::word('reviewers', 'line'), Html::int($min)), 'href' => '/ui/people']);
            }
            foreach ($staff['clashes'] ?? [] as $c) {
                if ((int) $c['id'] === $meId) {
                    $out[] = self::card('own_clash', ['href' => '/ui/people/' . $meId]);
                    continue;
                }
                $off = $c['off'];
                usort($off, static fn (string $a, string $b): int => array_search($a, Words::JOB_ORDER, true) <=> array_search($b, Words::JOB_ORDER, true));
                $jobs = Words::andList(array_map(static fn (string $r): string => Words::of('ROLE', $r), $off));
                $out[] = self::card('admin_clash', ['title' => sprintf(self::word('admin_clash', 'title'), $c['name']),
                    'line' => sprintf(self::word('admin_clash', 'line'), $jobs),
                    'button' => sprintf(self::word('admin_clash', 'button'), $c['name']), 'href' => '/ui/people/' . (int) $c['id']]);
            }
        }

        // The nightly safety check (G36): a problem it found, or no check for STALE_HOURS (only once one has ever run).
        $check = $f['integrity'] ?? null;
        if ($can('system.view') && is_array($check)) {
            if (!$check['ok']) {
                $out[] = self::card('integrity', ['title' => (int) $check['problems'] === 1 ? self::word('integrity', 'title_one')
                    : sprintf(self::word('integrity', 'title'), Html::int((int) $check['problems'])), 'href' => '/ui/system/checks', 'tone' => 'blocked']);
            } elseif ((int) $check['hours'] >= \CW\Ops\IntegrityRuns::STALE_HOURS) {
                $out[] = self::card('integrity_stale', ['title' => sprintf(self::word('integrity_stale', 'title'), Html::when((string) $check['finished_at'])),
                    'href' => '/ui/system/checks', 'tone' => 'blocked']);
            }
        }
        // Grants of Admin or Reviewer waiting for this reviewer's OK (approvals.staff_grant, Y25), and resets (staff_reset, Y44).
        if ($can('staff.approve')) {
            $out[] = self::counted('staff_requests', (int) ($f['staff_requests'] ?? 0), '/ui/staff-requests');
            // What the admins did to staff access and the approval rules lately (I1, Y45): one line each.
            $watch = is_array($f['watch'] ?? null) ? $f['watch'] : [];
            if ($watch !== []) {
                $lines = array_map(self::watchLine(...), array_slice($watch, 0, self::WATCH_LINES));
                if (count($watch) > self::WATCH_LINES) {
                    $lines[] = sprintf(Words::WATCH['more'], Html::int(count($watch) - self::WATCH_LINES));
                }
                $out[] = self::counted('watch', count($watch), '/ui/system/audit', ['lines' => $lines, 'tone' => 'info', 'chip' => Words::HOME['to_look'],
                    'unit' => sprintf(Words::TASK['watch']['unit'][count($watch) === 1 ? 0 : 1], Html::int(\CW\Admin\AuditSearch::DEFAULT_DAYS))]);
            }
        }
        // Sign-in set-ups closed after too many wrong tries (I5, Y42): the admin makes a new sheet; a reviewer asks the person.
        $closed = is_array($f['setup_closed'] ?? null) ? $f['setup_closed'] : [];
        if ($closed !== [] && ($can('staff.manage') || $can('staff.approve'))) {
            $admin = $can('staff.manage');
            $one = count($closed) === 1;
            $out[] = self::counted('setup_closed', count($closed),
                $admin ? ($one ? '/ui/people/' . (int) $closed[0]['id'] : '/ui/people') : Html::url('/ui/system/audit', ['action' => 'staff.setup_closed']),
                ['button' => self::word('setup_closed', $admin && $one ? 'button_admin' : 'button'), 'tone' => 'blocked']);
        }

        $ranked = [];
        $notes = [];
        foreach (array_values(array_filter($out)) as $i => $card) {
            if (in_array($card['key'], self::NOTES, true)) {
                $notes[] = $card + ['quiet' => true];
            } else {
                // An urgent job first (RD-H4), then by rank; equal ranks keep the order above (one card per spot check, per set-aside list, per person).
                $urgent = $card['tone'] === 'blocked';
                $ranked[] = [$urgent ? 0 : 1, self::RANK[$card['key']], $i, $card + ['urgent' => $urgent]];
            }
        }
        usort($ranked, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        return ['jobs' => array_column($ranked, 3), 'notes' => $notes];
    }

    /**
     * Home's "Waiting for you" as the owner's checklist in tabs (31 Oct 2026; Main.dc.html; docs/decisions.md RD-H1-RD-H5): All, then one
     * tab per GROUPS section that has jobs, each with its count and its plain link (All `/ui/`, a section `/ui/?jobs=<section>`; the tab
     * shown has none); the tab $want when it is a section with jobs, else All; its jobs in their order, the first FOLD as rows and the rest
     * for the fold. The head's total counts every job. A card with no tab ("no job") is not a row: it is `none`.
     *
     * @param list<array<string, mixed>> $jobs the jobs of build(), in their order
     * @return array{total: int, tab: string, tabs: list<array{key: string, label: string, count: int, href: string, current: bool}>,
     *     rows: list<array<string, mixed>>, more: list<array<string, mixed>>, none: ?array<string, mixed>}
     */
    public static function checklist(array $jobs, ?string $want): array
    {
        $grouped = [];
        $none = null;
        $counts = array_fill_keys(self::GROUPS, 0);
        foreach ($jobs as $card) {
            $group = self::GROUP[$card['key']] ?? null;
            if ($group === null) {
                $none ??= $card;
                continue;
            }
            $grouped[] = [$group, $card];
            $counts[$group]++;
        }
        $tab = is_string($want) && ($counts[$want] ?? 0) > 0 ? $want : 'all';
        $tabs = [];
        if ($grouped !== []) {
            $tabs[] = ['key' => 'all', 'label' => Words::RD_HOME['all'], 'count' => count($grouped), 'href' => '/ui/', 'current' => $tab === 'all'];
            foreach ($counts as $group => $n) {
                if ($n > 0) {
                    $tabs[] = ['key' => $group, 'label' => Words::SECTION[$group], 'count' => $n, 'href' => Html::url('/ui/', ['jobs' => $group]), 'current' => $tab === $group];
                }
            }
        }
        $shown = [];
        foreach ($grouped as [$group, $card]) {
            if ($tab === 'all' || $tab === $group) {
                $shown[] = $card;
            }
        }
        return ['total' => count($grouped), 'tab' => $tab, 'tabs' => $tabs, 'rows' => array_slice($shown, 0, self::FOLD),
            'more' => array_slice($shown, self::FOLD), 'none' => $none];
    }

    /** One line of the "changes to look at" card. @param array<string, mixed> $w */
    private static function watchLine(array $w): string
    {
        $who = $w['who'] ?? Words::WATCH['server'];
        $at = Html::when((string) $w['at']);
        return match ($w['action']) {
            'staff.create' => sprintf(Words::WATCH['staff.create'], $at, $who, (string) ($w['person'] ?? '')),
            'staff.reset' => sprintf(Words::WATCH['staff.reset'], $at, $who, (string) ($w['person'] ?? '')),
            default => sprintf(Words::WATCH['loosened'], $at, $who, (string) ($w['rule'] ?? '')),
        };
    }

    /** A card with a count, or null when nothing waits (the card is left out). @param array<string, mixed> $over */
    private static function counted(string $key, int $n, string $href, array $over = []): ?array
    {
        if ($n <= 0) {
            return null;
        }
        $unit = Words::TASK[$key]['unit'] ?? null;
        return self::card($key, $over + ['count' => $n, 'unit' => is_array($unit) ? $unit[$n === 1 ? 0 : 1] : $unit, 'href' => $href]);
    }

    /**
     * One card: the words of Words::TASK[$key], then $over. A job has ONE short line (`line`, RD-H3); a note and "no job" keep their text.
     *
     * @param array<string, mixed> $over @return array<string, mixed>
     */
    private static function card(string $key, array $over = []): array
    {
        $w = Words::TASK[$key];
        $card = $over + ['key' => $key, 'tone' => 'needs', 'chip' => Words::HOME['needs_you'], 'title' => $w['title'], 'line' => $w['line'] ?? null,
            'text' => $w['text'] ?? null, 'button' => $w['button'] ?? null];
        return array_filter($card, static fn (mixed $v): bool => $v !== null);
    }

    /** A word of a card (Words::TASK). */
    private static function word(string $key, string $field): string
    {
        return (string) Words::TASK[$key][$field];
    }
}
