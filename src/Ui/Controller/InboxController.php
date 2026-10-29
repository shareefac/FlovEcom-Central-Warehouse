<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Auth\Permissions;
use CW\CwException;
use CW\Documents\Documents;
use CW\Queues\Corrections;
use CW\Queues\LandingOrders;
use CW\Queues\ModeChanges;
use CW\Queues\Oversells;
use CW\Queues\QueueRules;
use CW\Queues\Suspense;
use CW\Staff\RoleRequests;
use CW\Staff\StaffAdmin;
use CW\Counts\CountReviews;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Route;
use CW\Ui\Router;
use CW\Ui\Words;

/**
 * Approvals › Waiting for you (docs/decisions.md AV1-AV14; the approved mockups AP_Inbox and AP_Phone of 29 Oct 2026): ONE page listing
 * every record the signed-in person may answer, grouped by kind, each group with its colour and count; only the kinds their jobs answer.
 * A row opens, on the right (first on a phone), that record's facts as big figures and ONE decision: the main answer in magenta, the
 * rarer answers under More, the note when the kind needs one.
 *
 * No decision logic of its own (AV5): every answer is a form of the kind's OWN route (StoreQueuesController, CountsController,
 * StaffRequestsController, ReviewsController, SuppliersController, CompanyController), so its permission, CSRF, service, note rules,
 * outcome and audit are that page's. The form carries `inbox=1`, the record that comes next (`inbox_next`) and its place in the list
 * (`inbox_pos`); the kind's action then leads back here (answered(): the next record of the list, keeping the place, with the action's own
 * notice) instead of to its page, and a refusal comes back here on the record (refused(): the kind's own words, what was typed kept).
 * The per-kind pages stay as they were (their addresses keep working); the menu's Approvals entry opens this page and its badge counts
 * it (Ui\Sections `count`, Ui\Context::badges `approvals_inbox`).
 *
 *   ?open=<kind>-<id>   the record to open (a row's link; without it, the first one, or the one at `pos`)
 *   ?pos=<n>            the place in the list to open when `open` is not waiting any more (after an answer)
 *   ?notice=&words=&arg= the notice of the answer just given: its code in the notice words of the kind's own page
 */
final class InboxController
{
    public const PATH = '/ui/approvals';

    /**
     * The kinds, in the page's order (the brief's): who answers them (any of these permissions; Count differences: Documents::mayPost of a
     * count), the colour of the group (a tone of Words::TONES), and the kind's own page (each group's "All" link).
     */
    public const KINDS = [
        'corrections' => ['perms' => [Corrections::PERM], 'tone' => 'needs', 'page' => '/ui/queues/corrections'],
        'suspense' => ['perms' => [Suspense::PERM], 'tone' => 'info', 'page' => '/ui/queues/suspense'],
        'counts' => ['perms' => ['doc.CNT.post'], 'tone' => 'review', 'page' => '/ui/stock/counts/differences'],
        'oversold' => ['perms' => [Oversells::PERM], 'tone' => 'blocked', 'page' => '/ui/queues/oversold'],
        'modes' => ['perms' => [ModeChanges::PERM], 'tone' => 'off', 'page' => '/ui/queues/modes'],
        'landing' => ['perms' => [LandingOrders::PERM], 'tone' => 'waiting', 'page' => '/ui/queues/landing'],
        'staff' => ['perms' => ['staff.approve'], 'tone' => 'info', 'page' => '/ui/staff-requests'],
        // The routes of its answers (AV16): a document's OK and Not OK need documents.review (Kernel), a supplier's task suppliers.approve,
        // the company details' check company.confirm; documents.approve alone answers none of them.
        'documents' => ['perms' => ['documents.review', 'suppliers.approve', 'company.confirm'], 'tone' => 'review', 'page' => '/ui/documents/reviews'],
    ];

    /** The permission the route of a task's answers checks, by what it is about (ReviewsController::tasks `subject`; AV16). */
    public const TASK_ROUTE_PERMS = ['document' => 'documents.review', 'supplier' => 'suppliers.approve', 'company' => 'company.confirm'];

    /** Every permission that answers a kind (KINDS' `perms`, once each; tests/Unit/InboxUnitTest holds them equal): Ui\Sections shows the tab to their holders. */
    public const PERMS = ['queues.corrections', 'queues.suspense', 'doc.CNT.post', 'queues.oversell', 'queues.modes', 'staff.approve', 'documents.review',
        'suppliers.approve', 'company.confirm'];

    /**
     * The notice words of the kinds' own pages, by the `words` an answer names (answered()): the first table that has the code, as the page
     * the answer would have led to reads it (a record's check: its own page's words, then a record's; AV17).
     */
    private const NOTICES = ['queue' => [Words::QUEUE_NOTICE], 'count' => [Words::COUNT_NOTICE], 'staff' => [StaffRequestsController::NOTICES],
        'record' => [Words::RECORD_NOTICE], 'po' => [Words::PO_NOTICE], 'receipt' => [Words::RECEIPT_NOTICE], 'stock' => [Words::STOCK_NOTICE, Words::RECORD_NOTICE],
        'handover' => [Words::HANDOVER_NOTICE, Words::RECORD_NOTICE], 'invoice' => [Words::INVOICE_NOTICE, Words::RECORD_NOTICE],
        'return' => [Words::RETURN_NOTICE, Words::INVOICE_NOTICE, Words::RECORD_NOTICE], 'supplier' => [Words::SUPPLIER_NOTICE], 'company' => [Words::COMPANY_NOTICE]];

    /** A record's key: its kind and its id in that kind (a count line: l<sheet>_<line>, a count review: r<id>; a task: its id). */
    private const KEY = '/^[a-z]{4,12}-[a-z0-9_]{1,40}$/D';

    public static function routes(Router $r): void
    {
        // Every signed-in person may ask; the page refuses (403) a person whose jobs answer none of the kinds, as the menu hides it.
        $r->add('GET', self::PATH, Route::ANY, (new self())->index(...));
    }

    /** @param list<string> $roles @return list<string> the kinds these jobs answer, in the page's order */
    public static function kinds(array $roles): array
    {
        $out = [];
        foreach (self::KINDS as $kind => $k) {
            $may = $kind === 'counts' ? Documents::mayPost($roles, 'CNT')
                : array_filter($k['perms'], static fn (string $p): bool => Permissions::can($roles, $p)) !== [];
            if ($may) {
                $out[] = $kind;
            }
        }
        return $out;
    }

    /** Whether this POST was sent from the inbox (its forms carry inbox=1). */
    public static function from(Context $ctx): bool
    {
        return $ctx->req->method === 'POST' && $ctx->req->field('inbox') === '1';
    }

    /**
     * After an answer sent from the inbox: back to the inbox on the record that came next ($stay: on this one, an answer that settles
     * nothing), else on the same place in the list, with the notice of the kind's own page ($words: which notice words; $arg: its %s).
     */
    public static function answered(Context $ctx, string $words, string $notice, string $arg = '', ?string $stay = null): HtmlResponse
    {
        $q = ['notice' => $notice, 'words' => $words];
        if ($arg !== '') {
            $q['arg'] = mb_substr($arg, 0, 64);
        }
        $next = $stay ?? $ctx->req->field('inbox_next');
        if ($next !== null && preg_match(self::KEY, $next) === 1) {
            $q['open'] = $next;
        }
        $pos = $ctx->req->field('inbox_pos');
        if ($pos !== null && preg_match('/^\d{1,4}$/D', $pos) === 1) {
            $q['pos'] = $pos;
        }
        return HtmlResponse::redirect(Html::url(self::PATH, $q));
    }

    /** A refused answer sent from the inbox: the inbox again, on that record ($key), with the refusal in the kind's own words. */
    public static function refused(Context $ctx, string $key, string $words, CwException $e): HtmlResponse
    {
        return (new self())->page($ctx, $e->httpStatus, ['key' => $key, 'error' => $words, 'code' => $e->errorCode]);
    }

    /** The key of the count difference a POST answers (its route: a sheet line, or a review the count engine opened). */
    public static function countKey(Context $ctx): string
    {
        return str_contains($ctx->req->path, '/differences/line/') ? 'counts-l' . $ctx->id() . '_' . $ctx->id('line') : 'counts-r' . $ctx->id();
    }

    public function index(Context $ctx): HtmlResponse
    {
        return $this->page($ctx);
    }

    /** @param array{key: string, error: string, code: string}|null $refused */
    private function page(Context $ctx, int $status = 200, ?array $refused = null): HtmlResponse
    {
        $kinds = self::kinds($ctx->me()->roles);
        if ($kinds === []) {
            return $ctx->error(403, 'role_not_allowed', Words::INBOX['not_yours']);
        }
        $groups = [];
        $flat = [];
        foreach ($kinds as $kind) {
            $g = $this->group($ctx, $kind);
            if ($g['rows'] === []) {
                continue;
            }
            $groups[] = ['kind' => $kind, 'name' => Words::INBOX['kind_' . $kind], 'tone' => self::KINDS[$kind]['tone'], 'count' => $g['count'], 'more' => $g['more'],
                'href' => self::KINDS[$kind]['page'], 'rows' => $g['rows']];
            array_push($flat, ...$g['rows']);
        }
        // The record to open: the one asked for (or refused), else the one now at the place given, else the first.
        $keys = array_column($flat, 'key');
        $want = $refused['key'] ?? $ctx->req->param('open');
        $i = is_string($want) ? array_search($want, $keys, true) : false;
        if ($i === false && $flat !== []) {
            $pos = $refused !== null ? $ctx->req->field('inbox_pos') : $ctx->req->param('pos');
            $i = is_string($pos) && preg_match('/^\d{1,4}$/D', $pos) === 1 ? min((int) $pos, count($flat) - 1) : 0;
        }
        $record = null;
        if ($i !== false && $flat !== []) {
            $row = $flat[$i];
            $record = $this->record($ctx, $row, (int) $i, count($flat), $flat[$i + 1] ?? null, $refused !== null && $refused['key'] === $row['key'] ? $refused : null);
        }
        $open = $record['key'] ?? null;
        $groups = array_map(static fn (array $g): array => ['rows' => array_map(static fn (array $r): array => ['open' => $r['key'] === $open] + $r, $g['rows'])] + $g, $groups);
        $notice = self::notice($ctx->req->param('words'), $ctx->req->param('notice'), (string) ($ctx->req->param('arg') ?? ''));
        return $ctx->page('approvals_inbox', [
            'groups' => $groups,
            'total' => array_sum(array_column($groups, 'count')),
            'record' => $record,
            // A refusal of a record that is not waiting any more (answered meanwhile): said above the list.
            'error' => $refused !== null && $refused['key'] !== $open ? $refused['error'] : null,
            'errorCode' => $refused !== null && $refused['key'] !== $open ? $refused['code'] : null,
        ], $status, ['title' => Words::MENU['inbox'], 'notice' => $notice]);
    }

    /** The notice of an answer just given: its code in the first of its page's notice tables that has it (NOTICES), else null. */
    public static function notice(?string $words, ?string $code, string $arg = ''): ?string
    {
        foreach (is_string($words) && is_string($code) ? (self::NOTICES[$words] ?? []) : [] as $table) {
            if (isset($table[$code])) {
                $text = (string) $table[$code];
                return str_contains($text, '%s') && substr_count($text, '%') === 1 ? sprintf($text, $arg) : $text;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------------------------------
    // The lists, one kind at a time
    // ------------------------------------------------------------------------------------------

    /**
     * One kind's waiting records this person may answer, oldest first as on the kind's own page (at most a page of it: `more`), with the
     * kind's count of them (the badge's), each row as key, kind, title, meta (the line under it) and data (the kind's own row).
     *
     * @return array{rows: list<array<string, mixed>>, count: int, more: bool}
     */
    private function group(Context $ctx, string $kind): array
    {
        $me = $ctx->me();
        $rows = [];
        $count = 0;
        $more = false;
        $row = static fn (string $id, string $title, string $meta, array $data): array => ['key' => $kind . '-' . $id, 'kind' => $kind, 'title' => $title,
            'meta' => $meta, 'data' => $data];
        switch ($kind) {
            case 'corrections':
                $svc = new Corrections($ctx->db);
                $list = $svc->list('open');
                $count = $svc->openCount();
                foreach (array_slice($list, 0, QueueRules::PAGE) as $r) {
                    $rows[] = $row((string) $r['id'], (string) $r['sku_name'], Words::say('INBOX', 'meta_correction', (int) $r['proposed_qty'], Html::day((string) $r['created_at'])), $r);
                }
                $more = count($list) > QueueRules::PAGE;
                break;
            case 'suspense':
                $svc = new Suspense($ctx->db);
                $list = $svc->list('open');
                $count = $svc->openCount();
                foreach (array_slice($list, 0, QueueRules::PAGE) as $r) {
                    $what = (string) ($r['description'] ?? '') !== '' ? (string) $r['description'] : Words::SUSPENSE['no_description'];
                    $rows[] = $row((string) $r['id'], $what, Words::say('INBOX', 'meta_suspense', Words::of('RELAY_KIND', (string) $r['movement_type']), abs((int) $r['qty']),
                        (string) ($r['store']['name'] ?? Words::QUEUES['store_unknown'])), $r);
                }
                $more = count($list) > QueueRules::PAGE;
                break;
            case 'counts':
                $open = CountsController::differences($ctx, null, true); // a page of the rows they may clear (AV15)
                $count = $ctx->countReviews()->openCount($me->id, $me->roles);
                foreach ($open['groups'] as $source => $list) {
                    foreach ($list as $r) {
                        if ($r['refusal'] !== null) {
                            continue; // someone else answers it (their own count while a second person is asked for)
                        }
                        $id = $source === 'line' ? 'l' . (int) $r['document_id'] . '_' . (int) $r['line_no'] : 'r' . (int) $r['id'];
                        $meta = $source === 'line' ? Words::say('INBOX', 'meta_count', (string) $r['number'], (int) $r['counted'], (int) $r['held'])
                            : Words::of('COUNT_REVIEW', (string) $source);
                        $rows[] = $row($id, (string) $r['name'], $meta, ['source' => $source] + $r);
                    }
                }
                $more = $open['more'];
                break;
            case 'oversold':
                $svc = new Oversells($ctx->db);
                $list = $svc->list('open');
                $count = $svc->openCount();
                foreach (array_slice($list, 0, QueueRules::PAGE) as $r) {
                    $rows[] = $row((string) $r['id'], (string) $r['sku_name'], Words::say('INBOX', 'meta_oversold', (int) $r['shortfall'],
                        (string) ($r['store']['name'] ?? Words::QUEUES['store_unknown'])), $r);
                }
                $more = count($list) > QueueRules::PAGE;
                break;
            case 'modes':
                $svc = new ModeChanges($ctx->db);
                $list = $svc->list('open');
                $count = $svc->openCount();
                foreach (array_slice($list, 0, QueueRules::PAGE) as $r) {
                    $title = (string) ($r['sku_name'] ?? '') !== '' ? (string) $r['sku_name'] : Words::say('MODES', 'variant', (string) ($r['external_variant_id'] ?? ''));
                    $rows[] = $row((string) $r['id'], $title, Words::say('INBOX', 'meta_mode', (string) $r['site_mode'], (string) ($r['store']['name'] ?? Words::QUEUES['store_unknown'])), $r);
                }
                $more = count($list) > QueueRules::PAGE;
                break;
            case 'landing':
                $svc = new LandingOrders($ctx->db);
                $list = $svc->list('open');
                $count = $svc->openCount();
                foreach (array_slice($list, 0, QueueRules::PAGE) as $r) {
                    $title = (string) ($r['product_title'] ?? '') !== '' ? (string) $r['product_title']
                        : ((string) ($r['variant_title'] ?? '') !== '' ? (string) $r['variant_title'] : Words::LISTING['no_title']);
                    $rows[] = $row((string) $r['id'], $title, Words::say('INBOX', 'meta_landing', (string) $r['order_ref'], (string) ($r['store']['name'] ?? Words::QUEUES['store_unknown'])), $r);
                }
                $more = count($list) > QueueRules::PAGE;
                break;
            case 'staff':
                foreach ((new RoleRequests($ctx->db))->pending($me->id) as $r) {
                    if ($r['can']) {
                        $rows[] = $row((string) $r['id'], (string) $r['person'], Words::say('INBOX', 'meta_staff', Words::of('RESET_KIND', (string) $r['kind']),
                            Html::day((string) $r['requested_at'])), $r);
                    }
                }
                $count = count($rows);
                break;
            case 'documents':
                $tasks = ReviewsController::tasks($ctx);
                foreach ([...$tasks['approval'], ...$tasks['review']] as $r) {
                    // Only what its route lets this person send (AV16): the service's refusal and the route's permission.
                    if ($r['refusal'] === null && $me->can(self::TASK_ROUTE_PERMS[(string) $r['subject']])) {
                        $meta = Words::say('INBOX', 'meta_document', Words::INBOX['task_' . $r['kind']], Html::day((string) $r['due_at']));
                        $rows[] = $row((string) $r['task_id'], (string) $r['label'], $r['overdue'] ? Words::say('INBOX', 'late', $meta) : $meta, $r);
                    }
                }
                $count = count($rows);
                break;
        }
        return ['rows' => $rows, 'count' => max($count, count($rows)), 'more' => $more];
    }

    // ------------------------------------------------------------------------------------------
    // The open record: its facts and its one decision
    // ------------------------------------------------------------------------------------------

    /**
     * The open record in words: its head (kind, where from, its place in the list), its title (a link to its own page where it has one),
     * the facts as big figures, the lines that say what it is and what the answers do, the refusal of its last answer, and its decision:
     * the main answer (its route, its words), the fields its form sends (the note when the kind needs one, as typed after a refusal),
     * and the rarer answers (More): each either a button of the same form with another route (`formaction`), or a fold with its own small
     * form and note (`fold`).
     *
     * @param array<string, mixed> $row a row of group()
     * @param array<string, mixed>|null $next the row after it
     * @param array{key: string, error: string, code: string}|null $refused
     * @return array<string, mixed>
     */
    private function record(Context $ctx, array $row, int $pos, int $total, ?array $next, ?array $refused): array
    {
        $kind = (string) $row['kind'];
        $d = $row['data'];
        $req = $ctx->req;
        // What a refused answer sent, to draw again as typed (a value is never cut into another one: kept whole while sane, else left out).
        $typed = static function (string $name, int $max) use ($req, $refused): ?string {
            if ($refused === null) {
                return null;
            }
            $v = (string) ($req->field($name) ?? '');
            return mb_strlen($v) <= $max ? $v : '';
        };
        $note = static fn (string $label, bool $required, string $hint = '', int $min = QueueRules::NOTE_MIN, int $max = QueueRules::NOTE_MAX): array
            => ['label' => $label, 'required' => $required, 'hint' => $hint, 'min' => $required ? $min : 0, 'max' => $max, 'value' => $typed('note', 2 * $max) ?? ''];
        $fact = static fn (string $label, string $value, string $tone = ''): array => ['label' => $label, 'value' => $value, 'tone' => $tone];
        $signed = static fn (int $v): array => [$v > 0 ? '+' . Html::int($v) : ($v < 0 ? '−' . Html::int(-$v) : '0'), $v > 0 ? 'up' : ($v < 0 ? 'down' : '')];
        $store = (string) ($d['store']['name'] ?? Words::QUEUES['store_unknown']);
        $r = [
            'key' => $row['key'], 'kind' => $kind, 'tone' => self::KINDS[$kind]['tone'], 'one' => Words::INBOX['one_' . $kind], 'from' => '',
            'place' => Words::say('INBOX', 'of', $pos + 1, $total), 'title' => (string) $row['title'], 'href' => null, 'sub' => null,
            'facts' => [], 'lines' => [], 'list' => [], 'links' => [],
            'error' => $refused['error'] ?? null, 'errorCode' => $refused['code'] ?? null,
            'action' => '', 'main' => '', 'mainName' => null, 'mainValue' => null, 'hidden' => [],
            'note' => null, 'resolution' => null, 'item' => null, 'more' => [], 'moreOpen' => false,
            'pos' => $pos, 'nextKey' => $next['key'] ?? null, 'next' => $next === null ? null : Words::say('INBOX', 'next', (string) $next['title']),
        ];
        $q = StoreQueuesController::PATH;
        switch ($kind) {
            case 'corrections':
                $w = StoreQueuesController::correctionRow($d);
                $e = $d['effect'];
                $r['from'] = $w['from'];
                $r['href'] = '/ui/items/' . (int) $d['sku_id'];
                $r['sub'] = (string) $d['sku_code'];
                $r['facts'][] = $fact(Words::INBOX['f_set_to'], Html::int((int) $d['proposed_qty']));
                if ($e !== null && !$e['newer']) {
                    $r['facts'][] = $fact(Words::INBOX['f_held'], Html::int((int) $e['shelf']));
                    $r['facts'][] = $fact(Words::INBOX['f_paid'], Html::int(max(0, (int) $e['allocated'])));
                    [$v, $tone] = $signed((int) $e['delta']);
                    $r['facts'][] = $fact(Words::INBOX['f_change'], $v, $tone);
                }
                $r['lines'] = [$w['set_at'], $w['document'], ...$w['effect_lines'], Words::CORRECTIONS['not_count']];
                $r['action'] = $q . '/corrections/' . (int) $d['id'] . '/confirm';
                $r['main'] = Words::CORRECTIONS['confirm'];
                $r['note'] = $note(Words::INBOX['note'], true, Words::INBOX['note_hint']);
                $r['more'][] = ['label' => Words::CORRECTIONS['dismiss'], 'formaction' => $q . '/corrections/' . (int) $d['id'] . '/dismiss'];
                break;
            case 'suspense':
                $refusedPick = $refused !== null && str_ends_with($req->path, '/pick');
                $refusedClose = $refused !== null && str_ends_with($req->path, '/dismiss');
                $w = StoreQueuesController::suspenseRow($d, true, $ctx->me()->can('items.create'), $refused === null ? null : ['id' => (int) $d['id'],
                    'form' => $refusedPick ? 'pick' : 'dismiss', 'item' => $typed('item', 64) ?? '', 'units' => $typed('units', 20) ?? '', 'note' => $typed('note', 2 * QueueRules::NOTE_MAX) ?? '',
                    'picked' => $refusedPick ? StoreQueuesController::pickedLabel($ctx, (string) ($typed('item', 64) ?? '')) : '']);
                $r['from'] = $w['from'];
                $r['sub'] = $w['names'];
                $r['facts'][] = $fact(Words::LQ[$w['correction'] ? 'col_set_to' : 'col_units'], Html::int((int) $w['units']));
                $r['facts'][] = $fact(Words::INBOX['f_came'], Words::of('RELAY_KIND', (string) $d['movement_type']));
                $r['facts'][] = $fact(Words::INBOX['f_from'], $store);
                $r['lines'] = [$w['where'], $w['why']];
                $r['action'] = $q . '/suspense/' . (int) $d['id'] . '/pick';
                $r['main'] = Words::LQ[$w['correction'] ? 'to_corrections' : 'place'];
                $canAdd = $ctx->me()->can('items.create');
                $r['item'] = ['value' => $w['typed_item'], 'units' => $w['typed_units'], 'picked' => $w['picked_label'], 'add_href' => $w['add_href'],
                    'itemsHref' => $q . '/suspense/items?', 'addWords' => $canAdd ? Words::LQ['add_new'] : '', 'askWords' => $canAdd ? '' : Words::askToAdd('items.create')];
                $r['note'] = ['value' => $refusedPick ? $w['typed_note'] : ''] + $note(Words::LQ['col_note'], true, Words::LQ['note_hint']);
                $r['more'][] = ['label' => Words::LQ['close'], 'fold' => ['action' => $q . '/suspense/' . (int) $d['id'] . '/dismiss', 'label' => Words::LQ['close_note'],
                    'required' => true, 'min' => QueueRules::NOTE_MIN, 'max' => QueueRules::NOTE_MAX, 'value' => $refusedClose ? (string) ($w['typed_close'] ?? '') : '']];
                $r['moreOpen'] = $refusedClose;
                break;
            case 'counts':
                $r['from'] = $d['number'] !== null ? (string) $d['number'] : Words::of('COUNT_REVIEW', (string) $d['source']);
                $r['href'] = '/ui/items/' . (int) $d['sku_id'];
                $r['sub'] = (string) $d['code'] . ' · ' . (string) $d['warehouse'];
                if ($d['counted'] !== null) {
                    // A flag about a count's time has no counted figure (AV18): none is shown, as on Differences.
                    $r['facts'][] = $fact(Words::COUNTS['counted'], Html::int((int) $d['counted']));
                }
                if ($d['held'] !== null) {
                    $r['facts'][] = $fact(Words::INBOX['f_system'], Html::int((int) $d['held']));
                }
                if ($d['delta'] !== null) {
                    [$v, $tone] = $signed((int) $d['delta']);
                    $r['facts'][] = $fact(Words::COUNTS['difference'], $v, $tone);
                }
                if ((string) ($d['counted_by_name'] ?? '') !== '') {
                    $r['facts'][] = $fact(Words::COUNTS['counted_by'], (string) $d['counted_by_name']);
                }
                $r['lines'] = [(string) $d['why'], ...($d['counted_again'] ? [Words::COUNTS['counted_again']] : [])];
                if ($d['href'] !== null) {
                    $r['links'][] = ['label' => Words::INBOX['open_sheet'], 'href' => (string) $d['href']];
                }
                $r['action'] = (string) $d['action'];
                $r['main'] = Words::COUNTS['accept'];
                $r['mainName'] = 'answer';
                $r['mainValue'] = 'accept';
                $r['note'] = $note(Words::COUNTS['answer_note'], true, '', CountReviews::NOTE_MIN, CountReviews::NOTE_MAX);
                if ($d['canReject']) {
                    $r['more'][] = ['label' => Words::COUNTS['reject'], 'formaction' => (string) $d['action'], 'name' => 'answer', 'value' => 'reject'];
                }
                break;
            case 'oversold':
                $w = StoreQueuesController::oversoldRow($d);
                $r['from'] = $w['from'];
                $r['href'] = '/ui/items/' . (int) $d['sku_id'];
                $r['facts'][] = $fact(Words::INBOX['f_short'], Html::int((int) $d['shortfall']));
                $r['facts'][] = $fact(Words::INBOX['f_left'], Html::int((int) $d['available_after']));
                if ($d['order_ref'] !== null) {
                    $r['facts'][] = $fact(Words::INBOX['f_order'], (string) $d['order_ref']);
                }
                $r['facts'][] = $fact(Words::INBOX['f_from'], $store);
                $r['lines'] = [$w['order'], $w['what']];
                $r['list'] = $w['note_lines'];
                $r['action'] = $q . '/oversold/' . (int) $d['id'] . '/handle';
                $r['main'] = Words::OVERSOLD['handle'];
                $r['resolution'] = ['label' => Words::OVERSOLD['how'], 'group' => 'OVERSELL_RESOLUTION', 'options' => Oversells::RESOLUTIONS,
                    'value' => $typed('resolution', 40) ?? Oversells::RESOLUTIONS[0]];
                $r['note'] = $note(Words::INBOX['note'], true, Words::INBOX['note_hint']);
                $r['more'][] = ['label' => Words::OVERSOLD['add_note'], 'formaction' => $q . '/oversold/' . (int) $d['id'] . '/note'];
                break;
            case 'modes':
                $w = StoreQueuesController::modeRow($d);
                $detail = $d['detail'];
                $r['from'] = $w['from'];
                $r['href'] = $d['sku_id'] !== null ? '/ui/items/' . (int) $d['sku_id'] : null;
                $r['sub'] = $w['variant'];
                $r['facts'][] = $fact(Words::INBOX['f_mode'], (string) $d['site_mode']);
                $r['facts'][] = $fact(Words::INBOX['f_cw'], is_string($detail['cw_mode'] ?? null) ? (string) $detail['cw_mode'] : Words::INBOX['none_yet']);
                if (is_int($detail['low_stock'] ?? null)) {
                    $r['facts'][] = $fact(Words::INBOX['f_low'], Html::int((int) $detail['low_stock']));
                }
                $r['lines'] = [$w['will']];
                $r['action'] = $q . '/modes/' . (int) $d['id'] . '/accept';
                $r['main'] = Words::MODES['accept'];
                $r['note'] = $note(Words::INBOX['note'], true, Words::INBOX['note_hint']);
                $r['more'][] = ['label' => Words::MODES['dismiss'], 'formaction' => $q . '/modes/' . (int) $d['id'] . '/dismiss'];
                break;
            case 'landing':
                $w = StoreQueuesController::landingRow($d);
                $r['from'] = $w['from'];
                $r['href'] = $ctx->me()->can('linking.view') ? $w['link'] : null;
                $r['facts'][] = $fact(Words::INBOX['f_ordered'], Html::int((int) $d['units']));
                $r['facts'][] = $fact(Words::INBOX['f_paid_q'], Words::INBOX[(int) $d['paid'] === 1 ? 'yes' : 'no']);
                $r['facts'][] = $fact(Words::INBOX['f_option'], (string) $d['external_variant_id']);
                $r['lines'] = [$w['title'], Words::WEBSITE_BUG['no_stock'], ...($w['still'] !== null ? [$w['still']] : [])];
                $r['action'] = $q . '/landing/' . (int) $d['id'] . '/handle';
                $r['main'] = Words::WEBSITE_BUG['handle'];
                $r['hidden'] = ['paid' => (string) (int) $d['paid']];
                $r['resolution'] = ['label' => Words::WEBSITE_BUG['how'], 'group' => 'LANDING_RESOLUTION', 'options' => LandingOrders::RESOLUTIONS,
                    'value' => $typed('resolution', 40) ?? LandingOrders::RESOLUTIONS[0]];
                $r['note'] = $note(Words::INBOX['note'], true, Words::INBOX['note_hint']);
                break;
            case 'staff':
                $setup = StaffAdmin::setupInfo($ctx->db, (int) $d['staff_user_id']);
                $roles = $d['kind'] === 'roles';
                $r['from'] = Words::say('INBOX', 'asked', Html::when((string) $d['requested_at']));
                $r['sub'] = (string) ($d['email'] ?? '');
                $r['facts'][] = $fact(Words::STAFF_REQUESTS['what'], ucfirst(Words::of('RESET_KIND', (string) $d['kind'])));
                $r['facts'][] = $fact(Words::STAFF_REQUESTS['now'], Words::roles($d['before']));
                if ($roles) {
                    $r['facts'][] = $fact(Words::STAFF_REQUESTS['asked'], Words::roles($d['after']));
                }
                $r['facts'][] = $fact(Words::STAFF_REQUESTS['by'], (string) ($d['requested_by_name'] ?? Words::QUEUES['someone']));
                $r['lines'] = [$setup['finished'] === null ? Words::say('INBOX', 'set_up', Words::STAFF_REQUESTS[$setup['state'] === 'signup' ? 'set_up_not_yet' : 'set_up_server'])
                    : Words::say('INBOX', 'set_up', Words::say('STAFF_REQUESTS', 'set_up_at', Html::when((string) $setup['finished']['at']), (string) ($setup['finished']['ip'] ?? '')))];
                if ($setup['finished'] === null && $setup['state'] === 'signup') {
                    $r['lines'][] = Words::STAFF_REQUESTS['not_set_up_note'];
                }
                $r['action'] = '/ui/staff-requests/' . (int) $d['id'] . '/approve';
                $r['main'] = Words::STAFF_REQUESTS[$roles ? 'ok' : 'ok_reset'];
                $r['more'][] = ['label' => Words::STAFF_REQUESTS[$roles ? 'not_ok' : 'not_ok_reset'], 'fold' => ['action' => '/ui/staff-requests/' . (int) $d['id'] . '/reject',
                    'label' => Words::STAFF_REQUESTS['note'], 'required' => true, 'min' => RoleRequests::NOTE_MIN, 'max' => RoleRequests::NOTE_MAX,
                    'value' => $refused !== null ? ($typed('note', 1000) ?? '') : '']];
                $r['moreOpen'] = $refused !== null && str_ends_with($req->path, '/reject');
                break;
            case 'documents':
                $this->documentRecord($ctx, $r, $d, $refused, $typed);
                break;
        }
        return $r;
    }

    /**
     * A task of the review queue (a document, a supplier, the company details): the facts the queue lists, what each answer does (the
     * record page's words for a document), and its OK / Not OK on the task's own routes (Not OK under More, with its own note).
     *
     * @param array<string, mixed> $r the record being drawn
     * @param array<string, mixed> $d a row of ReviewsController::tasks()
     * @param array{key: string, error: string, code: string}|null $refused
     * @param \Closure(string, int): ?string $typed
     */
    private function documentRecord(Context $ctx, array &$r, array $d, ?array $refused, \Closure $typed): void
    {
        $approval = $d['kind'] === 'approval';
        $task = (int) $d['task_id'];
        $r['from'] = Words::INBOX['task_' . $d['kind']];
        $r['href'] = (string) $d['href'];
        if ($d['money'] !== null) {
            $r['facts'][] = ['label' => Words::CHECKS['value'], 'value' => Html::money($d['money']), 'tone' => ''];
        } elseif ($d['items'] !== null) {
            $r['facts'][] = ['label' => Words::INBOX['f_items'], 'value' => Html::int((int) $d['items']), 'tone' => ''];
        }
        $r['facts'][] = ['label' => Words::CHECKS['asked_by'], 'value' => (string) ($d['opened_by'] ?? Words::RECORD['by_cw']), 'tone' => ''];
        $r['facts'][] = ['label' => Words::CHECKS['check_by'], 'value' => Html::day((string) $d['due_at']), 'tone' => $d['overdue'] ? 'down' : ''];
        if ((string) $d['reason'] !== '') {
            $r['lines'][] = Words::say('RECORD', 'why_line', Words::of('CHECK_REASON', (string) $d['reason']));
        }
        $notOkOpen = $refused !== null && str_ends_with($ctx->req->path, '/reject');
        $fold = static fn (string $action, string $label, string $words): array => ['label' => $words, 'fold' => ['action' => $action, 'label' => $label,
            'required' => true, 'min' => 3, 'max' => 500, 'value' => $notOkOpen ? ($typed('note', 1000) ?? '') : '']];
        $optional = static fn (string $label): array => ['label' => $label, 'required' => false, 'hint' => '', 'min' => 0, 'max' => 500,
            'value' => $refused !== null && !$notOkOpen ? ($typed('note', 1000) ?? '') : ''];
        $r['moreOpen'] = $notOkOpen;
        if ($d['subject'] === 'document') {
            $doc = $ctx->documents()->get((int) $d['document_id']);
            $box = DocumentsController::decideBox($ctx, $doc, DocumentsController::tasks($ctx->db, $doc->id), $ctx->documents()->typeInfo($doc->docType),
                $d['money'] === null ? null : (string) $d['money']);
            if ($box !== null) {
                $r['lines'][] = Words::say('INBOX', 'does', $box['ok'], $box['okDoes']);
                $r['lines'][] = Words::say('INBOX', 'does', Words::RECORD['not_ok'], $box['notOkDoes']);
            }
            $r['action'] = '/ui/documents/reviews/' . $task . '/approve';
            $r['main'] = $box['ok'] ?? Words::RECORD[$approval ? 'ok_approval' : 'ok_review'];
            $r['note'] = $optional(Words::RECORD['note_optional']);
            $r['more'][] = $fold('/ui/documents/reviews/' . $task . '/reject', Words::RECORD['why_not_ok'], Words::RECORD['not_ok']);
        } elseif ($d['subject'] === 'supplier') {
            $slot = match (true) {
                $d['kind'] === 'review' => 'review',
                $d['reason'] === 'import_route' => 'route',
                default => 'activation',
            };
            $r['lines'][] = Words::say('INBOX', 'does', Words::SUPPLIER['ok_' . $slot], Words::SUPPLIER['does_ok_' . $slot]);
            $r['links'][] = ['label' => Words::INBOX['open_supplier'], 'href' => (string) $d['href']];
            $r['action'] = '/ui/purchasing/suppliers/tasks/' . $task . '/approve';
            $r['main'] = Words::SUPPLIER['ok_' . $slot];
            $r['note'] = $optional(Words::SUPPLIER['note_optional']);
            $r['more'][] = $fold('/ui/purchasing/suppliers/tasks/' . $task . '/reject', Words::SUPPLIER['why_not_ok'], Words::SUPPLIER['not_ok']);
        } else {
            $r['lines'][] = Words::say('INBOX', 'does', Words::COMPANY['right'], Words::COMPANY['right_does']);
            $r['lines'][] = Words::say('INBOX', 'does', Words::COMPANY['wrong'], Words::COMPANY['wrong_does']);
            $r['action'] = '/ui/reference/company/reviews/' . $task . '/approve';
            $r['main'] = Words::COMPANY['right'];
            $r['note'] = $optional(Words::COMPANY['note_optional']);
            $r['more'][] = $fold('/ui/reference/company/reviews/' . $task . '/reject', Words::COMPANY['why_wrong'], Words::COMPANY['wrong']);
        }
    }
}
