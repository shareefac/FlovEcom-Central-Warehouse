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
 * facts into cards. A card with nothing waiting is left out. Each card has a plain sentence, a big number when there is one,
 * B's "What happens:" line and ONE button, to a page the person may open. Cards are sorted (RANK): what holds other people up
 * first (below 100), then the owner's own checks (100-199), then routine work (200 and up); they carry B's job numbers, and
 * the first one is "Start here". Two cards are notes, not jobs (NOTES: the company details a buyer waits for, sales data that
 * is out of date): they follow the jobs, without a number. A person with no job gets only the card that says so.
 *
 * The counts are the badges' (Context::badges, Context::checks) wherever a badge exists, so a card and its badge always agree,
 * and every card counts only what this person can act on (no card for work they could never clear).
 */
final class HomeTasks
{
    /** Card key => its place: blocking others (< 100), the owner's own checks (100-199), routine (200+). */
    public const RANK = [
        'company_confirm' => 10,
        'approvals' => 20,
        'second_ok' => 30,
        'test_accounts' => 40,
        'own_clash' => 41,
        'admin_clash' => 42,
        'reviewers' => 43,
        'not_ok' => 50,
        'not_sent' => 60,
        'bench' => 65,
        'to_post' => 70,
        'spot_check' => 100,
        'set_aside' => 110,
        'duplicates' => 120,
        'clues' => 130,
        'checks' => 140,
        'deliveries_check' => 145,
        'drafts' => 200,
        'supplier_drafts' => 210,
        'checks_due' => 215,
        'incidents' => 217,
        'to_buy' => 220,
        'strong' => 230,
        'other' => 240,
        'barcodes' => 250,
    ];

    /** Cards that inform and wait (no job number, after the jobs). */
    public const NOTES = ['company_wait', 'old_sales'];

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
        'incidents' => ['incidents.resolve'],
    ];

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

        // The company details: a reviewer confirms them; until then a buyer's PDFs say DO NOT SEND.
        $company = $f['company'] ?? null;
        if (is_array($company) && !$company['confirmed']) {
            if ($can('company.confirm')) {
                $missing = $company['missing'] ?? [];
                $out[] = self::card('company_confirm', [
                    'text' => self::word('company_confirm', 'text') . ($missing === [] ? '' : ' ' . sprintf(self::word('company_confirm', 'missing'), Words::andList($missing))),
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
                    'text' => (int) $s['rest'] > 0
                        ? sprintf(self::word('spot_check', 'text'), Html::int($s['size']), Html::int($s['rest']))
                        : sprintf(self::word('spot_check', 'text_no_rest'), Html::int($s['size'])),
                    'button' => $next !== null
                        ? sprintf(self::word('spot_check', 'button'), Html::int($s['next_position'] ?? 0), Html::int($s['size']))
                        : self::word('spot_check', 'button_list'),
                    'href' => $next !== null ? Html::url('/ui/review/listing/' . (int) $next, ['sample' => (int) $s['id']]) : '/ui/review/samples/' . (int) $s['id'],
                ]);
            }
            foreach ($f['held'] ?? [] as $h) {
                $out[] = self::counted('set_aside', (int) $h['open'], '/ui/review/samples/' . (int) $h['sample_id'] . '#held',
                    ['text' => sprintf(self::word('set_aside', 'text'), $h['name'])]);
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
            $spotFirst = is_array($mine) && (int) $mine['rest'] > 0
                ? ' ' . sprintf(self::word('strong', 'spot_first'), $mine['name'], Html::int(min((int) $mine['rest'], $strong))) : '';
            $out[] = self::counted('strong', $strong, Html::url('/ui/review', ['queue' => 'Key']), ['text' => self::word('strong', 'text') . $spotFirst]);
            $parts = [];
            $first = null;
            $total = 0;
            foreach (self::OTHER_BANDS as $band) {
                $n = (int) ($bands[$band] ?? 0);
                if ($n > 0) {
                    $parts[] = sprintf(self::word('other', 'part'), Words::of('BAND_TITLE', $band), Html::int($n));
                    $first ??= $band;
                    $total += $n;
                }
            }
            $out[] = self::counted('other', $total, Html::url('/ui/review', ['queue' => $first ?? 'Check']), ['text' => implode(' · ', $parts)]);
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

        // Deliveries: waiting for the goods-in bench, checked and waiting to be booked in, open incidents to close.
        if ($can('doc.GRN.post') && is_array($f['receiving'] ?? null)) {
            $out[] = self::counted('bench', (int) $f['receiving']['bench'], '/ui/receiving/bench');
            $out[] = self::counted('to_post', (int) $f['receiving']['to_post'], Html::url('/ui/receiving', ['state' => 'checked']));
        }
        if ($can('incidents.resolve')) {
            $out[] = self::counted('incidents', (int) ($f['incidents'] ?? 0), '/ui/receiving/incidents');
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
            if ($reviewers < PeopleController::MIN_REVIEWERS) {
                $out[] = self::card('reviewers', ['title' => self::word('reviewers', $reviewers === 0 ? 'title_none' : 'title'),
                    'text' => sprintf(self::word('reviewers', 'text'), Html::int(PeopleController::MIN_REVIEWERS)), 'href' => '/ui/people']);
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
                    'text' => sprintf(self::word('admin_clash', 'text'), $c['name'], $jobs),
                    'button' => sprintf(self::word('admin_clash', 'button'), $c['name']), 'href' => '/ui/people/' . (int) $c['id']]);
            }
        }

        $ranked = [];
        $notes = [];
        foreach (array_values(array_filter($out)) as $i => $card) {
            if (in_array($card['key'], self::NOTES, true)) {
                $notes[] = $card + ['quiet' => true];
            } else {
                // Equal ranks keep the order above (one card per spot check, per set-aside list, per person).
                $ranked[] = [self::RANK[$card['key']], $i, $card];
            }
        }
        usort($ranked, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $jobs = [];
        foreach ($ranked as $n => [, , $card]) {
            $jobs[] = $card + ['job' => $n + 1] + ($n === 0 ? ['hero' => true] : []);
        }
        return ['jobs' => $jobs, 'notes' => $notes];
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

    /** One card: the words of Words::TASK[$key], then $over. @param array<string, mixed> $over @return array<string, mixed> */
    private static function card(string $key, array $over = []): array
    {
        $w = Words::TASK[$key];
        $card = $over + ['key' => $key, 'tone' => 'needs', 'chip' => Words::HOME['needs_you'], 'title' => $w['title'], 'text' => $w['text'] ?? null,
            'what' => $w['what'] ?? null, 'button' => $w['button'] ?? null];
        return array_filter($card, static fn (mixed $v): bool => $v !== null);
    }

    /** A word of a card (Words::TASK). */
    private static function word(string $key, string $field): string
    {
        return (string) Words::TASK[$key][$field];
    }
}
