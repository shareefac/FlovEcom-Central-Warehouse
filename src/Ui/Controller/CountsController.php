<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Clock;
use CW\Counts\CountPlan;
use CW\Counts\CountReviews;
use CW\Counts\CountSheets;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Matching\Gtin;
use CW\Movements;
use CW\OpResult;
use CW\Output\CsvWriter;
use CW\Receiving\SellingModes;
use CW\Settings;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Router;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Stock > Counts (pack A2a; docs/decisions.md CT14; the approved design of 29 Oct 2026: CV1-CV12): three segments and a sheet's two pages.
 *
 *   Next to count   what to count next at one warehouse (CountPlan) as a board: overdue, never counted, inside the plan (folded), each
 *                   heading with the plan's figure; tick items, then the bar's one Count menu (the ticked items, the next N due, a
 *                   search's result, or an empty sheet) and its one button open a count sheet (FormOnce: one sheet however often the
 *                   form is sent)
 *   Count sheets    every count record, newest first, as a board by status, with Search, Filter and Export
 *   Differences     the count differences to check (CountReviews): accept one with a note, or reject it, which puts that count back
 *   a sheet         while it is open: the scan box, its lines 50 a page (a quantity is saved when Enter is pressed in its box: the
 *                   page goes, only its changed lines are kept), each with its "Saved" time; the bar: More (the download and the
 *                   upload with its preview, stop it), Save, "Save and review"; on a phone the line to count now as one card with
 *                   "Save and next item"; once posted: what was booked per line, cancel it as a whole
 *   Before you post the strip (counted, the same, different, a reviewer checks), the lines that differ or need an answer as a board
 *                   with what making it final does to each (count it again, keep my count), the same ones folded, and the bar's one
 *                   "Make the count final"
 *
 * Who: documents.view looks (every GET); doc.CNT.post opens, enters, posts and clears (every POST; checked again by CountSheets,
 * CountReviews and the document base, never admin). Every refusal comes back on the page in words (COUNT_ERROR by code), saying
 * whether anything was saved.
 */
final class CountsController
{
    public const PATH = CountSheets::PATH;
    /** The board's groups of the list of sheets, in order. */
    public const GROUPS = ['draft', 'posted', 'reversed', 'put_back', 'cancelled'];
    /** The groups of "Next to count", in the approved design's order, and their colours (docs/decisions.md CV1). */
    public const PLAN_GROUPS = ['overdue', 'never', 'inside'];
    private const PLAN_TONES = ['overdue' => 'blocked', 'never' => 'review', 'inside' => 'done'];
    /** Lines a sheet's page shows. */
    public const LINES = 50;

    /** Adds every route of the tab to the router (Kernel::router). */
    public static function routes(Router $r): void
    {
        $c = new self();
        $post = 'doc.' . CountSheets::TYPE . '.post';
        $r->add('GET', self::PATH, 'documents.view', $c->index(...));
        $r->add('POST', self::PATH, $post, $c->open(...));
        $r->add('GET', self::PATH . '/sheets', 'documents.view', $c->sheets(...));
        $r->add('GET', self::PATH . '/sheets.csv', 'documents.view', $c->sheetsCsv(...));
        $r->add('GET', self::PATH . '/differences', 'documents.view', $c->reviews(...));
        $r->add('POST', self::PATH . '/differences/line/{id}/{line}', $post, $c->decideLine(...));
        $r->add('POST', self::PATH . '/differences/review/{id}', $post, $c->decideEngine(...));
        $r->add('GET', self::PATH . '/{id}', 'documents.view', $c->show(...));
        $r->add('GET', self::PATH . '/{id}/sheet.csv', 'documents.view', $c->download(...));
        $r->add('GET', self::PATH . '/{id}/differences', 'documents.view', $c->before(...));
        foreach (['lines', 'scan', 'add', 'upload', 'recount', 'as-counted', 'post', 'cancel', 'reverse'] as $action) {
            $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $action))));
            $r->add('POST', self::PATH . '/{id}/' . $action, $post, static fn (Context $ctx): HtmlResponse => $c->{$method}($ctx));
        }
    }

    // ------------------------------------------------------------------------------------------
    // Next to count
    // ------------------------------------------------------------------------------------------

    /** @param array<string, mixed>|null $typed what a refused "open a sheet" form sent (picked: the ticked items) */
    public function index(Context $ctx, int $status = 200, ?CwException $error = null, ?array $typed = null): HtmlResponse
    {
        $me = $ctx->me();
        $canCount = Documents::mayPost($me->roles, CountSheets::TYPE);
        $warehouses = $ctx->db->all('SELECT id, code, name, is_sellable FROM warehouse WHERE is_active = 1 ORDER BY sort_order, id');
        $f = self::planFilters($ctx->req, $warehouses, $typed);
        $plan = new CountPlan($ctx->db);
        $days = max(1, Settings::number($ctx->db, 'counts.plan_days', 120));
        $now = Clock::now();
        $tiles = $f['warehouse'] === null ? ['items' => 0, 'inside' => 0, 'overdue' => 0, 'never' => 0] : $plan->tiles($f['warehouse'], $days, $now);
        $filtered = $f['q'] !== '';
        $rows = $f['warehouse'] === null ? [] : ($filtered ? $plan->search($f['warehouse'], $f['q'], $f['show']) : $plan->next($f['warehouse'], $f['show']));
        $cutoff = Clock::db($now->modify("-{$days} days"));
        $picked = array_map('intval', (array) ($typed['picked'] ?? []));
        $listed = [];
        foreach ($rows as $r) {
            $state = $r['counted_at'] === null ? 'never' : ($r['counted_at'] < $cutoff ? 'overdue' : 'inside');
            // "Sells a week" as CT_Board draws it (docs/decisions.md DC36): the same sales figure (units a day) a week, whole units, as the item page says it.
            $listed[$state][] = $r + ['state' => $state, 'sells' => $r['rate'] === null ? null : ItemController::weekly((string) $r['rate'], 0)['n'],
                'ticked' => in_array($r['sku_id'], $picked, true)];
        }
        // Each group's heading says the plan's figure for the warehouse (the plan's "overdue" counts the never counted too: here they are
        // apart), and how many of them the list shows when that is fewer; a search counts what it found (CV1). A group with nothing on the
        // list is a heading only (the inside one: the list holds the overdue and never counted first).
        $figures = ['overdue' => $tiles['overdue'] - $tiles['never'], 'never' => $tiles['never'], 'inside' => $tiles['inside']];
        $groups = [];
        foreach (self::PLAN_GROUPS as $g) {
            $list = $listed[$g] ?? [];
            $n = $filtered ? count($list) : $figures[$g];
            if ($list === [] && ($filtered || $n === 0)) {
                continue;
            }
            $words = $n === 1 ? Words::COUNTS['group_item'] : Words::say('COUNTS', 'group_items', $n);
            $groups[] = ['key' => $g, 'tone' => self::PLAN_TONES[$g], 'rows' => $list, 'folded' => $g === 'inside',
                'count' => count($list) < $n ? $words . ' · ' . Words::say('COUNTS', 'group_listed', count($list)) : $words];
        }
        // The bar's one Count menu (CV2): what the four buttons were. "The next N due" takes N from the Show choice (the list is the next
        // ones); a search offers everything it found instead.
        $modes = [];
        if ($rows !== []) {
            $modes['ticked'] = Words::COUNTS['count_ticked'];
            if ($filtered) {
                $modes['found'] = Words::say('COUNTS', 'count_found', count($rows));
            } else {
                $modes['next'] = Words::say('COUNTS', 'count_next', $f['show']);
            }
        }
        $modes['empty'] = Words::COUNTS['count_empty'];
        $mode = (string) ($typed['mode'] ?? '');
        $places = $f['warehouse'] === null ? [] : $ctx->db->all('SELECT id, name FROM warehouse_location WHERE warehouse_id = ? AND is_active = 1 ORDER BY code', [$f['warehouse']]);
        // The open sheets of this warehouse: drafts are sheets (the record index (doc_type, status, id): CT13).
        $openSheets = $f['warehouse'] === null ? 0 : (int) $ctx->db->value("SELECT COUNT(*) FROM document WHERE doc_type = ? AND status = 'draft' AND warehouse_id = ?",
            [CountSheets::TYPE, $f['warehouse']]);
        return $ctx->page('stock_counts', [
            'f' => $f,
            'warehouses' => array_map(static fn (array $w): array => ['id' => (int) $w['id'], 'name' => (string) $w['name']], $warehouses),
            'filtered' => $filtered,
            'shows' => CountPlan::SHOW,
            'days' => $days,
            'groups' => $groups,
            'rows_n' => count($rows),
            'places' => array_map(static fn (array $p): array => ['id' => (int) $p['id'], 'name' => (string) $p['name']], $places),
            'modes' => $modes,
            'mode' => isset($modes[$mode]) ? $mode : (string) array_key_first($modes),
            'typed' => ['location' => (string) ($typed['location'] ?? ''), 'note' => (string) ($typed['note'] ?? '')],
            'ticked_n' => count(array_filter($rows, static fn (array $r): bool => in_array($r['sku_id'], $picked, true))),
            'openSheets' => $openSheets,
            'openSheetsHref' => Html::url(self::PATH . '/sheets', ['state' => 'draft', 'warehouse' => $f['warehouse']]),
            'canCount' => $canCount,
            'formKey' => $canCount ? FormOnce::newKey() : null,
            'lookOnly' => $canCount ? null : Words::whoCan('doc.' . CountSheets::TYPE . '.post'),
            'settingHref' => Html::url('/ui/reference/settings/setting', ['key' => 'counts.plan_days']),
            'clear' => Html::url(self::PATH, ['warehouse' => $f['warehouse']]),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::MENU['counts']]);
    }

    /** POST /ui/stock/counts: a new sheet (FormOnce), then its page. `mode`: ticked (the ticked rows), next (the next `n`), found (a search's rows), empty. */
    public function open(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $typed = ['warehouse' => (string) ($req->field('warehouse') ?? ''), 'location' => (string) ($req->field('location') ?? ''), 'note' => (string) ($req->field('note') ?? ''),
            'mode' => (string) ($req->field('mode') ?? ''), 'n' => trim((string) ($req->field('n') ?? '')), 'q' => mb_substr(trim((string) ($req->field('q') ?? '')), 0, 100)];
        $picked = array_map(static fn (string $k): int => (int) substr($k, 5), array_keys($req->fieldsMatching('/^pick_[1-9][0-9]{0,9}$/D')));
        try {
            $r = FormOnce::run($ctx, 'ui.count.open', $typed + ['picked' => $picked], function (Db $db) use ($ctx, $typed, $picked): OpResult {
                $warehouse = UiRequest::id($typed['warehouse']) ?? throw new CwException('warehouse_required', 'say which warehouse is counted', 422);
                $plan = new CountPlan($db);
                $skus = match ($typed['mode']) {
                    'ticked' => $picked !== [] ? $picked : throw new CwException('nothing_ticked', 'tick the items to count first', 422),
                    'next' => preg_match('/^[1-9][0-9]{0,2}$/D', $typed['n']) === 1 && (int) $typed['n'] <= CountPlan::MAX_SHOWN ? $plan->nextIds($warehouse, (int) $typed['n'])
                        : throw new CwException('bad_next', 'how many is a whole number from 1 to ' . CountPlan::MAX_SHOWN, 422, ['max' => CountPlan::MAX_SHOWN]),
                    'found' => $typed['q'] !== '' ? array_column($plan->search($warehouse, $typed['q'], CountPlan::MAX_SHOWN), 'sku_id')
                        : throw new CwException('nothing_ticked', 'search for the items first', 422),
                    'empty' => [],
                    default => throw new CwException('bad_mode', 'choose how to open the sheet', 400),
                };
                $d = $ctx->countSheets()->open($ctx->caller(), ['warehouse' => $warehouse, 'location' => $typed['location'], 'note' => $typed['note']], $skus);
                return OpResult::of(303, ['result' => 'opened', 'document_id' => $d->id, 'redirect' => Html::url(self::PATH . '/' . $d->id, ['notice' => 'opened'])]);
            });
        } catch (CwException $e) {
            return $this->index($ctx, $e->httpStatus, $e, $typed + ['picked' => $picked]);
        }
        return FormOnce::redirect($r);
    }

    // ------------------------------------------------------------------------------------------
    // Count sheets (history)
    // ------------------------------------------------------------------------------------------

    public function sheets(Context $ctx): HtmlResponse
    {
        $f = self::listFilters($ctx->req);
        $page = $ctx->countSheets()->list($f, self::LINES);
        $groups = [];
        foreach ($page['rows'] as $r) {
            $row = self::sheetRow($r);
            $groups[$row['group']][] = $row;
        }
        $query = ['q' => $f['q'] === '' ? null : $f['q'], 'state' => $f['state'], 'warehouse' => $f['warehouse']];
        return $ctx->page('stock_count_sheets', [
            'f' => $f,
            'filtered' => $f['q'] !== '' || $f['state'] !== null || $f['warehouse'] !== null,
            'narrowed' => $f['q'] !== '' || $f['state'] !== null || $f['warehouse'] !== null || $f['before'] !== null,
            'states' => array_intersect_key(Words::DOC_STATUS, array_flip(CountSheets::STATES)),
            'warehouses' => array_map(static fn (array $w): array => ['id' => (int) $w['id'], 'name' => (string) $w['name']],
                $ctx->db->all('SELECT id, name FROM warehouse ORDER BY sort_order, id')),
            'groups' => array_filter(array_map(static fn (string $g): array => $groups[$g] ?? [], array_combine(self::GROUPS, self::GROUPS))),
            'rows_n' => count($page['rows']),
            'byItem' => $page['by_item'],
            'csv' => Html::url(self::PATH . '/sheets.csv', $query),
            'older_link' => $page['next'] === null ? null : Html::url(self::PATH . '/sheets', $query + ['before' => $page['next']]),
            'newest_link' => $f['before'] === null ? null : Html::url(self::PATH . '/sheets', $query),
            'page_size' => self::LINES,
        ], 200, ['title' => Words::SEGMENT['count_sheets']]);
    }

    /** The list of sheets as CSV (the same filters; at most 5,000 rows, read a page at a time by id). */
    public function sheetsCsv(Context $ctx): HtmlResponse
    {
        $cols = [];
        foreach (['number', 'kind', 'status', 'opened', 'warehouse', 'place', 'lines', 'counted', 'units_changed', 'differences_waiting', 'opened_by', 'posted_at_utc', 'about', 'note'] as $c) {
            $cols[] = [$c, in_array($c, ['lines', 'counted', 'units_changed', 'differences_waiting'], true) ? 'number' : 'text'];
        }
        $out = new CsvWriter($cols);
        $f = self::listFilters($ctx->req);
        $f['before'] = null;
        for ($n = 0; $n < 5000;) {
            $page = $ctx->countSheets()->list($f, 500);
            foreach ($page['rows'] as $r) {
                $out->add([$r['number'] ?? '', Words::of('COUNT_KIND', (string) $r['kind']), Words::of('DOC_STATUS', (string) $r['status']), $r['doc_date'] ?? '', $r['warehouse'] ?? '',
                    $r['location'] ?? '', (int) $r['lines_n'], (int) $r['counted_n'], (int) $r['units_changed'], (int) $r['open_n'], $r['created_by_name'] ?? '', $r['posted_at'] ?? '',
                    $r['reverses_number'] ?? $r['line_of_number'] ?? '', $r['note'] ?? '']);
                $n++;
            }
            if ($page['next'] === null) {
                break;
            }
            $f['before'] = $page['next'];
        }
        return FilesController::download($out->output(), 'text/csv; charset=utf-8', 'count_sheets.csv');
    }

    // ------------------------------------------------------------------------------------------
    // Differences to check
    // ------------------------------------------------------------------------------------------

    public function reviews(Context $ctx, int $status = 200, ?CwException $error = null, ?string $notice = null): HtmlResponse
    {
        $me = $ctx->me();
        $warehouse = UiRequest::id($ctx->req->param('warehouse'));
        $n = $ctx->req->param('notice');
        $notice ??= $n !== null && isset(Words::COUNT_NOTICE[$n]) ? Words::say('COUNT_NOTICE', $n, (string) ($ctx->req->param('number') ?? '')) : null;
        $open = self::differences($ctx, $warehouse);
        $rule = $ctx->documents()->typeInfo(CountSheets::TYPE);
        return $ctx->page('stock_count_reviews', [
            'groups' => $open['groups'],
            'tones' => ['line' => 'needs', 'count_after_movements' => 'blocked', 'count_near_ship' => 'review', 'ship_near_count' => 'review', 'unship_near_count' => 'review'],
            'rows_n' => $open['n'],
            'more' => $open['more'],
            'page_size' => CountReviews::PAGE,
            'second' => Words::say('COUNTS', match ((string) $rule['review_rule']) {
                'all' => 'second_all',
                'over_limit' => 'second_over',
                default => 'second_none',
            }, (int) ($rule['review_limit_units'] ?? 0)),
            'noteMin' => CountReviews::NOTE_MIN,
            'noteMax' => CountReviews::NOTE_MAX,
            'lookOnly' => Documents::mayPost($me->roles, CountSheets::TYPE) ? null : Words::whoCan('doc.' . CountSheets::TYPE . '.post'),
            'rulesHref' => '/ui/reference/approvals',
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::title('count_differences'), 'notice' => $notice]);
    }

    /**
     * The open count differences in words, by group (the line above the limits first, then each source of CountReviews::LISTED_SOURCES;
     * an empty group left out): each row with its sheet's page (`href`), its answer's route (`action`), why it waits (`why`) and why this
     * person may not answer it (`refusal`, null when they may). The Differences page and Approvals › Waiting for you (InboxController, AV4)
     * read the same rows; $mine: only the rows this person may clear (a page of rows they may answer, AV15).
     *
     * @return array{groups: array<string, list<array<string, mixed>>>, n: int, more: bool}
     */
    public static function differences(Context $ctx, ?int $warehouse = null, bool $mine = false): array
    {
        $me = $ctx->me();
        $reviews = $ctx->countReviews();
        $open = $reviews->open(['warehouse' => $warehouse], CountReviews::PAGE, $mine ? $me->id : null);
        $groups = array_fill_keys(['line', ...CountReviews::LISTED_SOURCES], []);
        foreach ($open['lines'] as $r) {
            $no = $reviews->refusal($me->id, $me->roles, $r);
            $groups['line'][] = $r + ['href' => self::PATH . '/' . $r['document_id'], 'action' => self::PATH . '/differences/line/' . $r['document_id'] . '/' . $r['line_no'],
                'why' => self::overWords($r['over'], $r['review_units'], $r['review_percent']), 'refusal' => $no === null ? null : self::refusalWords($no['code'])];
        }
        foreach ($open['engine'] as $r) {
            $no = $reviews->refusal($me->id, $me->roles, $r);
            $near = in_array($r['source'], CountReviews::NEAR_SOURCES, true);
            $groups[$r['source']][] = $r + ['href' => $r['document_id'] === null ? null : self::PATH . '/' . $r['document_id'], 'action' => self::PATH . '/differences/review/' . $r['id'],
                'why' => self::engineWhy($r), 'refusal' => $no === null ? null : self::refusalWords($no['code']),
                'number' => $near ? null : $r['ref'], 'held' => null, 'counted' => $r['proposed_qty'], 'counted_by_name' => null];
        }
        return ['groups' => array_filter($groups), 'n' => count($open['lines']) + count($open['engine']), 'more' => $open['more']];
    }

    /** POST differences/line/{id}/{line}: `answer` accept | reject, with a note. */
    public function decideLine(Context $ctx): HtmlResponse
    {
        return $this->decide($ctx, function (string $answer, string $note) use ($ctx): ?Document {
            if ($answer === 'accept') {
                $ctx->countReviews()->acceptLine($ctx->caller(), $ctx->id(), $ctx->id('line'), $note);
                return null;
            }
            return $ctx->countReviews()->rejectLine($ctx->caller(), $ctx->id(), $ctx->id('line'), $note);
        });
    }

    /** POST differences/review/{id}: `answer` accept | reject, with a note (a review the count engine opened). */
    public function decideEngine(Context $ctx): HtmlResponse
    {
        return $this->decide($ctx, function (string $answer, string $note) use ($ctx): ?Document {
            if ($answer === 'accept') {
                $ctx->countReviews()->acceptEngine($ctx->caller(), $ctx->id(), $note);
                return null;
            }
            return $ctx->countReviews()->rejectEngine($ctx->caller(), $ctx->id(), $note);
        });
    }

    /** @param \Closure(string, string): ?Document $fn returns the record that put a count back, when one was made */
    private function decide(Context $ctx, \Closure $fn): HtmlResponse
    {
        $answer = (string) ($ctx->req->field('answer') ?? '');
        try {
            if (!in_array($answer, ['accept', 'reject'], true)) {
                throw new CwException('bad_answer', 'choose accept or reject', 400);
            }
            $back = $fn($answer, (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            // Answered from Approvals › Waiting for you: the refusal comes back there, on the record (docs/decisions.md AV5).
            if (InboxController::from($ctx)) {
                return InboxController::refused($ctx, InboxController::countKey($ctx), self::plain($e), $e);
            }
            return $this->reviews($ctx, $e->httpStatus, $e);
        }
        $notice = $answer === 'accept' ? ['notice' => 'accepted'] : ($back === null ? ['notice' => 'rejected_already'] : ['notice' => 'rejected', 'number' => $back->number]);
        if (InboxController::from($ctx)) {
            return InboxController::answered($ctx, 'count', $notice['notice'], (string) ($notice['number'] ?? ''));
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/differences', $notice));
    }

    // ------------------------------------------------------------------------------------------
    // One sheet
    // ------------------------------------------------------------------------------------------

    public function show(Context $ctx): HtmlResponse
    {
        $n = $ctx->req->param('notice');
        $notice = $n !== null && isset(Words::COUNT_NOTICE[$n]) ? Words::say('COUNT_NOTICE', $n, (string) ($ctx->req->param('number') ?? $ctx->req->param('n') ?? '')) : null;
        return $this->page($ctx, $ctx->id(), 200, null, $notice);
    }

    /** GET {id}/sheet.csv: the sheet to count on paper or elsewhere. */
    public function download(Context $ctx): HtmlResponse
    {
        $sheet = $ctx->countSheets()->sheet($ctx->id());
        if ($sheet === null || $sheet['kind'] !== 'sheet') {
            return $this->notFound($ctx);
        }
        return FilesController::download($ctx->countSheets()->csv($ctx->id()), 'text/csv; charset=utf-8', 'count_sheet_' . ($sheet['number'] ?? $ctx->id()) . '.csv');
    }

    /**
     * POST {id}/lines: the quantities of a page of the sheet (`q_<line>`: whole units, or empty for "not counted"), sent when Enter is
     * pressed in a quantity box (the form's own button: no script needed, so a quantity is saved the moment it is entered). Each box
     * comes with the value the page was drawn with (`was_<line>`): only the boxes changed on this page are saved, so a page left open
     * in another tab never empties or overwrites what was entered since (a hand-made form without `was_` saves what it sends). Each box
     * may also bring the moment it was filled in (`at_<line>`, milliseconds since 1970 UTC, written by app.js from the moment the page
     * was drawn: review M-7, 14 Oct 2026); CountSheets refuses one after now or before the line was on the sheet, and without one the
     * moment it is saved counts. A quantity that is a barcode CW knows, or 9 digits or more, is refused as a barcode (review M-5). A
     * refusal saves nothing of the page and shows it again with what was typed. Sent by "Save and review" (`then` = review, a button of
     * the same form: docs/decisions.md OV6, CV7), the review page opens after the save instead of the sheet. Sent by "Save and next item"
     * (`next` = the line it sits on: CV6), the sheet opens at the next line not counted after that one (else the first one before it),
     * marked and its box ready; when none is left, the sheet as after Save.
     */
    public function lines(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $typed = [];
        $typedAt = [];
        try {
            $quantities = [];
            $times = [];
            foreach ($ctx->req->fieldsMatching('/^q_[1-9][0-9]{0,9}$/D') as $name => $v) {
                $no = (int) substr($name, 2);
                $v = trim($v);
                $typed[$no] = mb_substr($v, 0, 20);
                $at = trim((string) ($ctx->req->field('at_' . $no) ?? ''));
                if (preg_match('/^[0-9]{10,15}$/D', $at) === 1) {
                    $typedAt[$no] = $at;
                }
                $was = $ctx->req->field('was_' . $no);
                if ($was !== null && trim($was) === $v) {
                    continue;
                }
                if ($v !== '' && preg_match('/^[0-9]{9,}$/D', $v) === 1) {
                    throw new CwException('barcode_in_quantity', 'a barcode was typed into a quantity box', 422, ['line' => $no]);
                }
                if ($v !== '' && preg_match('/^[0-9]{1,8}$/D', $v) !== 1) {
                    throw new CwException('bad_quantity', 'a counted quantity is a whole number of units', 422, ['line' => $no]);
                }
                $quantities[$no] = $v === '' ? null : (int) $v;
                if ($v !== '' && isset($typedAt[$no])) {
                    $ms = (int) $typedAt[$no];
                    $times[$no] = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($ms, 1000), ($ms % 1000) * 1000), Clock::utc())
                        ?: throw new CwException('bad_count_time', 'not a moment', 422, ['line' => $no]);
                }
            }
            $barcode = self::barcodeIn($ctx->db, array_filter($typed, static fn (string $v, int $no): bool => array_key_exists($no, $quantities) && $quantities[$no] !== null,
                ARRAY_FILTER_USE_BOTH));
            if ($barcode !== null) {
                throw new CwException('barcode_in_quantity', 'a barcode was typed into a quantity box', 422, ['line' => $barcode]);
            }
            $changed = $ctx->countSheets()->setQuantities($ctx->caller(), $id, $quantities, null, 'typed', $times);
        } catch (CwException $e) {
            // The moments come back with the page, except after a refused moment: Enter again then saves with the time now.
            return $this->page($ctx, $id, $e->httpStatus, $e, null, ['typedQ' => $typed, 'typedAt' => $e->errorCode === 'bad_count_time' ? [] : $typedAt]);
        }
        if ($ctx->req->field('then') === 'review') {
            // "Save and review" is a button of this form (OV6): the quantities typed are saved first, then the review page opens.
            return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id . '/differences', ['notice' => $changed === 0 ? 'lines_same' : 'lines', 'n' => $changed]));
        }
        $after = UiRequest::id($ctx->req->field('next'));
        if ($after !== null) {
            // "Save and next item" (CV6): read after the save, by the line key (the sheet's lines: at most CountSheets::MAX_LINES).
            $next = $ctx->db->value('SELECT line_no FROM count_sheet_line WHERE document_id = ? AND counted_qty IS NULL AND line_no > ? ORDER BY line_no LIMIT 1', [$id, $after])
                ?? $ctx->db->value('SELECT line_no FROM count_sheet_line WHERE document_id = ? AND counted_qty IS NULL ORDER BY line_no LIMIT 1', [$id]);
            if ($next !== null) {
                $before = (int) $ctx->db->value('SELECT COUNT(*) FROM count_sheet_line WHERE document_id = ? AND line_no < ?', [$id, (int) $next]);
                return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => $changed === 0 ? 'lines_same' : 'lines', 'n' => $changed,
                    'page' => intdiv($before, self::LINES) + 1, 'line' => (int) $next]) . '#line-' . (int) $next);
            }
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => $changed === 0 ? 'lines_same' : 'lines', 'n' => $changed, 'page' => self::pageNo($ctx->req, 'field'),
            'show' => self::showOf($ctx->req, 'field')]));
    }

    /** POST {id}/scan: a barcode or a CW number. On the sheet: its line's page, the line marked. Not on it: the offer to add it. */
    public function scan(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $code = mb_substr(trim((string) ($ctx->req->field('code') ?? '')), 0, 80);
        $sheet = $ctx->countSheets()->sheet($id);
        if ($sheet === null || $sheet['kind'] !== 'sheet') {
            return $this->notFound($ctx);
        }
        $found = $ctx->countSheets()->find($id, $code);
        if ($found['status'] === 'on_sheet') {
            $before = (int) $ctx->db->value('SELECT COUNT(*) FROM count_sheet_line WHERE document_id = ? AND line_no < ?', [$id, $found['line_no']]);
            return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['page' => intdiv($before, self::LINES) + 1, 'line' => $found['line_no'],
                'units' => $found['units'] > 1 ? $found['units'] : null]) . '#line-' . $found['line_no']);
        }
        return $this->page($ctx, $id, $found['status'] === 'not_on_sheet' ? 200 : 422, null, null, ['scan' => $found + ['typed' => $code]]);
    }

    /** POST {id}/add: the scan box's offer taken (an item put on the sheet), then its line's page. */
    public function add(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        try {
            $sku = UiRequest::id($ctx->req->field('sku_id')) ?? throw new CwException('unknown_sku', 'there is no such item', 422);
            $r = $ctx->countSheets()->addItem($ctx->caller(), $id, $sku);
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        $before = (int) $ctx->db->value('SELECT COUNT(*) FROM count_sheet_line WHERE document_id = ? AND line_no < ?', [$id, $r['line_no']]);
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => $r['added'] ? 'added' : null, 'page' => intdiv($before, self::LINES) + 1, 'line' => $r['line_no']])
            . '#line-' . $r['line_no']);
    }

    /**
     * POST {id}/upload: a filled-in file. `mode` check (the default): what it would fill, nothing written. `mode` apply: the sheet's
     * own lines are filled (never posted), with `counted_at` (a UK date and time; empty: now) as the moment they were counted.
     */
    public function upload(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $apply = $ctx->req->field('mode') === 'apply';
        $when = trim((string) ($ctx->req->field('counted_at') ?? ''));
        try {
            $file = $ctx->req->file('file');
            if ($file === null) {
                throw new CwException('no_file', 'choose the file first', 400, ['field' => 'file']);
            }
            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > UiRequest::MAX_UPLOAD_BYTES) {
                throw new CwException('too_large', 'the file is too large', 413);
            }
            if ($file['error'] !== UPLOAD_ERR_OK || $file['path'] === '') {
                throw new CwException('upload_failed', 'the file did not arrive', 400);
            }
            $at = null;
            if ($when !== '') {
                $t = preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/D', $when) === 1
                    ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i', str_replace('T', ' ', $when), new \DateTimeZone('Europe/London')) : false;
                if ($t === false) {
                    throw new CwException('bad_counted_at', 'the time it was counted is a date and a time', 400, ['field' => 'counted_at', 'days' => intdiv(Movements::BACKDATED_MAX_AGE_SEC, 86_400)]);
                }
                $at = $t->setTimezone(Clock::utc());
            }
            $report = $apply ? $ctx->countSheets()->applyUpload($ctx->caller(), $id, $file['path'], $at, $file['name']) : $ctx->countSheets()->readUpload($id, $file['path']);
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e, null, ['typedWhen' => $when]);
        }
        if ($apply) {
            return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => 'uploaded', 'n' => $report['filled']]));
        }
        return $this->page($ctx, $id, 200, null, null, ['report' => $report + ['file' => mb_substr((string) $file['name'], 0, 120)], 'typedWhen' => $when]);
    }

    /**
     * GET {id}/differences: "Before you post" (CV9, CV10): the strip (counted, the same as the system, different, a reviewer checks), the
     * lines that differ or need an answer as a board with what making it final does to each, the same ones folded at first, and the bar.
     * A line's status: count again (too many count times, or counted too long ago) and "stock moved: choose" stop the making final
     * (red); left out (a later count, navy); a reviewer checks it (above the limits, amber); else booked as counted (green).
     */
    public function before(Context $ctx, int $status = 200, ?CwException $error = null): HtmlResponse
    {
        $id = $ctx->id();
        $sheets = $ctx->countSheets();
        $sheet = $sheets->sheet($id);
        if ($sheet === null || $sheet['kind'] !== 'sheet') {
            return $this->notFound($ctx);
        }
        if ($sheet['status'] !== 'draft') {
            // No longer open: its page (with the refusal of a form sent too late, in words).
            return $error === null ? HtmlResponse::redirect(self::PATH . '/' . $id) : $this->page($ctx, $id, $status, $error);
        }
        $me = $ctx->me();
        $doc = Document::fromRow($sheet);
        $mine = Documents::mayPost($me->roles, CountSheets::TYPE) && $doc->createdBy === $me->id;
        $diff = $sheets->differences($id);
        $n = $ctx->req->param('notice');
        $lines = [];
        foreach ($diff['lines'] as $l) {
            $moved = [];
            foreach ($l['moved']['rows'] as $m) {
                $moved[] = Words::say('COUNTS', 'moved_row', Words::of('MOVEMENT', $m['movement_type']), ($m['qty_delta'] > 0 ? '+' : '') . number_format($m['qty_delta']), Html::when($m['at']));
            }
            [$tone, $state] = match (true) {
                $l['retime'] => ['blocked', Words::COUNTS['retime_chip']],
                $l['too_old'] => ['blocked', Words::COUNTS['too_old_chip']],
                $l['needs_choice'] => ['blocked', Words::COUNTS['st_choose']],
                $l['stale'] => ['off', Words::COUNTS['st_left_out']],
                $l['over'] !== [] => ['needs', Words::COUNTS['st_reviewer']],
                default => ['done', Words::COUNTS['st_booked']],
            };
            $lines[] = $l + ['why' => self::overWords($l['over'], $diff['units_limit'], $diff['percent_limit']), 'movedWords' => $moved,
                'movedMore' => max(0, $l['moved']['n'] - count($moved)),
                'does' => $l['stale'] ? Words::say('COUNTS', 'does_stale', Html::when((string) $l['last_counted_at']))
                    : ($l['moved']['n'] === 0 ? null : Words::say('COUNTS', $l['moved']['others'] !== 0 || $l['moved']['ships'] === 0 ? 'does_overwrite' : 'does_ships',
                        $l['counted'] + $l['moved']['ships'], -$l['moved']['ships'], $l['moved']['others'])),
                'st_tone' => $tone, 'st_word' => $state,
                // On the board of the lines that differ: a difference (or left out), or anything to look at or answer.
                'differs' => $l['delta'] !== 0 || $l['needs_choice'] || $l['acked'] || $l['too_old'] || $l['retime'] || $l['moved']['n'] > 0 || $l['over'] !== [],
            ];
        }
        $groups = [];
        foreach (['differ' => 'needs', 'same' => 'done'] as $g => $tone) {
            $list = array_values(array_filter($lines, static fn (array $l): bool => $l['differs'] === ($g === 'differ')));
            if ($list !== []) {
                $groups[] = ['key' => $g, 'tone' => $tone, 'rows' => $list, 'folded' => $g === 'same',
                    'count' => count($list) === 1 ? Words::COUNTS['group_item'] : Words::say('COUNTS', 'group_items', count($list))];
            }
        }
        $blocked = $diff['needs_choice'] > 0 || $diff['too_old'] > 0 || $diff['too_many_times'];
        $retime = $diff['too_many_times'] ? Words::say('COUNTS', 'too_many_times', Movements::MAX_DOCUMENT_MOVEMENTS, $diff['limit_minutes'], $diff['retime']) : null;
        return $ctx->page('stock_count_before', [
            'sheet' => $sheet,
            // CT_Final (DC3): "Before it is final" under the sheet's own name, its eyebrow the link back to it.
            'title' => Words::COUNTS['before_heading'],
            'sheetTitle' => Words::docTitle(CountSheets::TYPE, $doc->number),
            'base' => self::PATH . '/' . $id,
            'lines' => $lines,
            'groups' => $groups,
            'steps' => ['same' => count(array_filter($lines, static fn (array $l): bool => !$l['differs'])), 'different' => count(array_filter($lines, static fn (array $l): bool => $l['differs'])),
                'reviewer' => count(array_filter($lines, static fn (array $l): bool => $l['over'] !== []))],
            'diff' => $diff,
            'counted_n' => count($lines),
            'lines_n' => (int) $sheet['lines_n'],
            'days' => intdiv(Movements::BACKDATED_MAX_AGE_SEC, 86_400),
            'mine' => $mine,
            'canPost' => $mine && !$blocked && $lines !== [],
            'blocked' => $blocked,
            'retimeWords' => $retime,
            'retime_n' => $diff['retime'],
            'version' => $doc->version,
            'lookOnly' => $mine ? null : Words::COUNTS['not_opener'],
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::title('count_before'), 'notice' => $n !== null && isset(Words::COUNT_NOTICE[$n])
            ? Words::say('COUNT_NOTICE', $n, (int) ($ctx->req->param('n') ?? 0)) : null]);
    }

    /**
     * POST {id}/recount: "count it again": the line is empty again. With `times` = 1 instead (too many count times: review M-2): every
     * line the page marks is empty again (CountSheets::recountForTimes works them out again).
     */
    public function recount(Context $ctx): HtmlResponse
    {
        if ($ctx->req->field('times') === '1') {
            $id = $ctx->id();
            try {
                $n = $ctx->countSheets()->recountForTimes($ctx->caller(), $id);
            } catch (CwException $e) {
                return $e->errorCode === 'not_count_sheet' ? $this->notFound($ctx) : $this->before($ctx, $e->httpStatus, $e);
            }
            return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id . '/differences', ['notice' => 'recount_times', 'n' => $n]));
        }
        return $this->choose($ctx, static function (CountSheets $s, int $id, int $line) use ($ctx): string {
            $s->recount($ctx->caller(), $id, $line);
            return 'recount';
        });
    }

    /** POST {id}/as-counted: "post it as counted" (`seen`: the newest movement the page showed). */
    public function asCounted(Context $ctx): HtmlResponse
    {
        return $this->choose($ctx, static function (CountSheets $s, int $id, int $line) use ($ctx): string {
            $s->postAsCounted($ctx->caller(), $id, $line, UiRequest::id($ctx->req->field('seen')) ?? 0);
            return 'as_counted';
        });
    }

    /** @param \Closure(CountSheets, int, int): string $fn returns the notice */
    private function choose(Context $ctx, \Closure $fn): HtmlResponse
    {
        $id = $ctx->id();
        try {
            $line = UiRequest::id($ctx->req->field('line')) ?? throw new CwException('unknown_line', 'there is no such line', 422);
            $notice = $fn($ctx->countSheets(), $id, $line);
        } catch (CwException $e) {
            return $e->errorCode === 'not_count_sheet' ? $this->notFound($ctx) : $this->before($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id . '/differences', ['notice' => $notice]));
    }

    public function post(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $version = UiRequest::id($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        try {
            $d = $ctx->countSheets()->post($ctx->caller(), $id, $version);
        } catch (CwException $e) {
            return $e->errorCode === 'not_count_sheet' ? $this->notFound($ctx) : $this->before($ctx, $e->httpStatus, $e);
        }
        $open = (int) $ctx->db->value("SELECT COUNT(*) FROM count_sheet_line WHERE document_id = ? AND review = 'open'", [$id]);
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => $open > 0 ? 'posted_check' : 'posted', 'number' => $d->number]));
    }

    public function cancel(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $why = (string) ($ctx->req->field('reason') ?? '');
        $version = UiRequest::id($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        try {
            $ctx->countSheets()->cancel($ctx->caller(), $id, $version, $why);
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e, null, ['stop' => $why]);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => 'stopped']));
    }

    /** POST {id}/reverse: cancels a posted sheet as a whole by a new record; the person lands on that record. */
    public function reverse(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $code = (string) ($ctx->req->field('reason_code') ?? '');
        $note = $ctx->req->field('note');
        try {
            $rev = $ctx->countSheets()->reverse($ctx->caller(), $id, $code, $note === null || trim($note) === '' ? null : $note);
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e, null, ['typedCancel' => ['code' => $code, 'note' => (string) $note]]);
        }
        // A cancellation that waits for a reviewer's OK has no count header yet (it is given one when it is booked): the person stays on
        // the sheet, which says what waits.
        return HtmlResponse::redirect($rev->status === 'awaiting_approval' ? Html::url(self::PATH . '/' . $id, ['notice' => 'cancel_waits'])
            : Html::url(self::PATH . '/' . $rev->id, ['notice' => 'cancelled', 'number' => $rev->number]));
    }

    /**
     * A sheet's page, also after a refused form (the error in words, what was typed kept).
     *
     * @param array<string, mixed> $extra scan (the scan box's answer), report (an upload's preview), typedWhen, stop, typedCancel, typedQ (line =>
     *                                   what was typed in its box, after a refused page)
     */
    public function page(Context $ctx, int $id, int $status, ?CwException $error, ?string $notice = null, array $extra = []): HtmlResponse
    {
        if ($error !== null && $error->errorCode === 'not_count_sheet') {
            return $this->notFound($ctx);
        }
        $sheets = $ctx->countSheets();
        $sheet = $sheets->sheet($id);
        if ($sheet === null) {
            return $this->notFound($ctx);
        }
        $me = $ctx->me();
        $doc = Document::fromRow($sheet);
        $kind = (string) $sheet['kind'];
        $canCount = Documents::mayPost($me->roles, CountSheets::TYPE);
        $editable = $kind === 'sheet' && $doc->status === 'draft' && $canCount && $doc->createdBy === $me->id;
        $show = self::showOf($ctx->req, 'param');
        $page = $sheets->lines($id, ['show' => $editable ? $show : 'all', 'page' => self::pageNo($ctx->req, 'param') ?? 1, 'size' => self::LINES]);
        $modes = $page['rows'] === [] ? [] : (new SellingModes($ctx->db))->current(array_column($page['rows'], 'sku_id'));
        $marked = UiRequest::id($ctx->req->param('line'));
        $showsStock = Settings::flag($ctx->db, 'counts.sheet_shows_stock', true);
        // The line to count now (CV6; on a phone the one big card): the line a scan or "Save and next item" marked, else the first one on
        // this page not counted yet.
        $now = null;
        if ($editable) {
            $onPage = array_column($page['rows'], 'line_no');
            $todo = array_values(array_filter($page['rows'], static fn (array $l): bool => $l['counted_qty'] === null));
            $now = $marked !== null && in_array($marked, $onPage, true) ? $marked : ($todo[0]['line_no'] ?? null);
        }
        $lines = [];
        foreach ($page['rows'] as $l) {
            $mode = $modes[$l['sku_id']]['mode'] ?? null;
            $lines[] = $l + ['mode' => $mode,
                'marked' => $marked === $l['line_no'],
                'now' => $now === $l['line_no'],
                'row_class' => trim(($marked === $l['line_no'] ? 'marked ' : '') . ($now === $l['line_no'] ? 'now' : '')),
                // Its own count time, kept per line (CT2: the moment its box was filled in), as the "Saved" mark says it.
                'saved' => $l['counted_at'] === null ? null : Html::clock((string) $l['counted_at']),
                // After a refused page: what was typed in each box (nothing of that page was saved), and when it was filled in.
                'typed' => isset($extra['typedQ'][$l['line_no']]) ? (string) $extra['typedQ'][$l['line_no']] : null,
                'typedAt' => isset($extra['typedAt'][$l['line_no']]) ? (string) $extra['typedAt'][$l['line_no']] : '',
                // A blind count never shows the system's figure on the sheet, not even once the line is counted (CV8).
                'shows_stock' => $showsStock,
                'state' => $l['outcome'] === null ? null : ($l['outcome'] === 'stale' ? 'stale' : ($l['reversal_document_id'] !== null ? 'put_back' : 'booked')),
                'reversal_href' => $l['reversal_document_id'] === null ? null : self::PATH . '/' . $l['reversal_document_id']];
        }
        $query = ['show' => $show === 'all' ? null : $show];
        // The record that cancels this sheet: its own page once it is booked; while it waits for a reviewer's OK, the record on History
        // (where the reviewer decides; it has no count header until it is booked).
        $reversedBy = $sheet['reversed_by_id'] === null ? null : ['number' => $sheet['reversed_by_number'], 'href' => $sheet['reversed_by_status'] === 'posted'
            ? self::PATH . '/' . (int) $sheet['reversed_by_id'] : '/ui/documents/' . (int) $sheet['reversed_by_id']];
        $scan = $extra['scan'] ?? null;
        $units = UiRequest::id($ctx->req->param('units'));
        return $ctx->page('stock_count', [
            'doc' => $doc,
            'sheet' => $sheet,
            'kind' => $kind,
            'base' => self::PATH . '/' . $id,
            'listHref' => self::PATH . '/sheets',
            'title' => self::title($sheet, $doc),
            'facts' => self::facts($sheet, $doc),
            // The open sheet's head (CV5): where it is counted and who opened it when, on one line; "N of M counted" with its bar.
            'meta' => $editable ? self::metaLine($sheet, $doc) : '',
            'blind' => !$showsStock,
            'aboutHref' => $sheet['reverses_document_id'] !== null ? self::PATH . '/' . (int) $sheet['reverses_document_id']
                : ($doc->reversesId !== null ? self::PATH . '/' . $doc->reversesId : null),
            'aboutWords' => $sheet['reverses_document_id'] !== null ? Words::say('COUNTS', 'puts_back', (int) $sheet['reverses_line_no'], (string) $sheet['line_of_number'])
                : ($doc->reversesId !== null ? Words::say('COUNTS', 'cancels', (string) $sheet['reverses_number']) : null),
            'reversedBy' => $reversedBy,
            'lines' => $lines,
            'lines_n' => (int) $sheet['lines_n'],
            'counted_n' => (int) $sheet['counted_n'],
            'page_no' => $page['page'],
            'pages' => $page['pages'],
            'prev_link' => $page['page'] > 1 ? Html::url(self::PATH . '/' . $id, $query + ['page' => $page['page'] - 1]) : null,
            'next_link' => $page['page'] < $page['pages'] ? Html::url(self::PATH . '/' . $id, $query + ['page' => $page['page'] + 1]) : null,
            'show' => $show,
            // The lines' Show choice (docs/decisions.md DC35; the tab row before): the same `show` values, the one shown selected.
            'shows' => array_map(static fn (string $s): array => ['key' => $s, 'current' => $s === $show], ['all', 'todo', 'done']),
            'editable' => $editable,
            // When the page was drawn (milliseconds since 1970 UTC): app.js adds how long ago a box was filled in (M-7).
            'drawnMs' => (int) Clock::now()->format('Uv'),
            'notOpener' => $kind === 'sheet' && $doc->status === 'draft' && $canCount && $doc->createdBy !== $me->id,
            'hasMarked' => $marked !== null && in_array($marked, array_column($lines, 'line_no'), true),
            'version' => $doc->version,
            'scan' => $scan === null ? null : ['status' => (string) $scan['status'], 'typed' => (string) $scan['typed'], 'sku_id' => $scan['sku_id'] ?? null,
                'name' => $scan['name'] ?? null, 'code' => $scan['code'] ?? null],
            'scanUnits' => $units !== null && $units > 1 ? $units : null,
            'report' => $extra['report'] ?? null,
            'shown' => CountSheets::UPLOAD_SHOWN,
            'typedWhen' => (string) ($extra['typedWhen'] ?? ''),
            // More opens on what it holds when the server sent that back (a preview, an upload refused, a stop refused: PV26's rule).
            'paperOpen' => ($extra['report'] ?? null) !== null || in_array($error?->errorCode, ['bad_file', 'no_file', 'too_large', 'upload_failed', 'bad_counted_at', 'too_many_rows'], true),
            'typedStop' => (string) ($extra['stop'] ?? ''),
            'typedCancel' => $extra['typedCancel'] ?? ['code' => '', 'note' => ''],
            'maxMb' => intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576),
            'days' => intdiv(Movements::BACKDATED_MAX_AGE_SEC, 86_400),
            'reverse' => $kind === 'sheet' && $doc->status === 'posted' && $reversedBy === null && $canCount
                ? ['reasons' => $ctx->db->all("SELECT code, label FROM reason_code WHERE FIND_IN_SET('reversal', applies_to) > 0 AND system_only = 0 AND is_active = 1 "
                    . "AND code NOT IN ('po_amended', 'supplier_cannot_supply', 'not_needed') ORDER BY sort_order, code")] : null,
            'open_n' => (int) $ctx->db->value("SELECT COUNT(*) FROM count_sheet_line WHERE document_id = ? AND review = 'open'", [$id]),
            'lookOnly' => $canCount ? null : Words::whoCan('doc.' . CountSheets::TYPE . '.post'),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => self::title($sheet, $doc), 'notice' => $notice]);
    }

    /** A refusal in words, by its code (COUNT_ERROR; the stock records' and the document base's where they are the same thing). */
    public static function plain(CwException $e): string
    {
        $d = $e->detail;
        $lines = implode(', ', array_map('strval', (array) ($d['lines'] ?? [])));
        return match ($e->errorCode) {
            'moved_since', 'moved_meanwhile' => Words::say('COUNT_ERROR', $e->errorCode, $lines),
            'count_too_old' => Words::say('COUNT_ERROR', 'count_too_old', $lines, (int) ($d['days'] ?? 0)),
            'bad_counted_at' => Words::say('COUNT_ERROR', 'bad_counted_at', (int) ($d['days'] ?? intdiv(Movements::BACKDATED_MAX_AGE_SEC, 86_400))),
            'too_many_lines', 'bad_next', 'too_many_times' => Words::say('COUNT_ERROR', $e->errorCode, (int) ($d['max'] ?? 0)),
            'bad_quantity', 'barcode_in_quantity', 'unknown_line', 'bad_count_time' => Words::say('COUNT_ERROR', $e->errorCode, (int) ($d['line'] ?? 0)),
            'line_put_back', 'line_counted_again' => Words::say('COUNT_ERROR', $e->errorCode, (int) ($d['line'] ?? 0)),
            'too_large' => Words::say('RECEIPT_ERROR', 'too_large', intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576)),
            default => Words::COUNT_ERROR[$e->errorCode] ?? Words::STOCK_ERROR[$e->errorCode] ?? Words::RECEIPT_ERROR[$e->errorCode] ?? Words::error($e->errorCode, $e->getMessage()),
        };
    }

    // ------------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------------

    private function notFound(Context $ctx): HtmlResponse
    {
        return $ctx->error(404, 'not_found', 'there is no such count sheet', [self::PATH . '/sheets', Words::SEGMENT['count_sheets']]);
    }

    /** @param array<string, mixed> $sheet */
    private static function title(array $sheet, Document $doc): string
    {
        return match ((string) $sheet['kind']) {
            'line_reversal' => Words::say('COUNTS', 'title_put_back', $doc->number ?? Words::COUNTS['no_number']),
            'reversal' => Words::docTitle(CountSheets::TYPE, $doc->number, null, (string) ($sheet['reverses_number'] ?? '')),
            default => $doc->number === null ? Words::COUNTS['title_open'] : Words::say('COUNTS', 'title_sheet', $doc->number),
        };
    }

    /** The limits a difference is above, in words ("more than 5 units · more than 10% of what was held"). @param list<string> $over */
    private static function overWords(array $over, ?int $units, ?int $percent): string
    {
        $parts = [];
        if (in_array('units', $over, true)) {
            $parts[] = Words::say('COUNTS', 'over_units', (int) $units);
        }
        if (in_array('percent', $over, true)) {
            $parts[] = Words::say('COUNTS', 'over_percent', (int) $percent);
        }
        return implode(' · ', $parts);
    }

    /** Why an engine review or a flag about a count's time waits, in words. @param array<string, mixed> $r a row of CountReviews::open()['engine'] */
    private static function engineWhy(array $r): string
    {
        $d = $r['detail'];
        $when = static fn (mixed $at): string => is_string($at) && $at !== '' ? Html::when($at) : '';
        $normal = ($d['applied'] ?? 'normal') !== 'pre_count';
        return match ((string) $r['source']) {
            'count_after_movements' => Words::say('COUNTS', 'why_after', (int) ($d['net'] ?? 0), count((array) ($d['rows'] ?? []))),
            'ship_near_count' => Words::say('COUNTS', 'why_ship_near', (string) ($r['order'] ?? ''), $when($d['dispatched_at'] ?? null), $when($d['counted_at'] ?? null),
                Words::COUNTS[$normal ? 'near_taken_off' : 'near_not_taken_off']),
            'unship_near_count' => Words::say('COUNTS', 'why_unship_near', (string) ($r['order'] ?? ''), $when($d['reset_at'] ?? null), $when($d['counted_at'] ?? null),
                Words::COUNTS[$normal ? 'near_added' : 'near_not_added']),
            default => Words::say('COUNTS', 'why_near', count((array) ($d['units'] ?? []))),
        };
    }

    /**
     * The first line whose quantity is a barcode CW knows (as typed, or as the barcode key: an EAN-8 that starts with 0, a short code of
     * the shop's own; review M-5, 14 Oct 2026). Only 4 digits or more, as the scan box reads a barcode. One indexed read.
     *
     * @param array<int, string> $typed line => what was typed in its box (the changed, non-empty ones)
     */
    private static function barcodeIn(Db $db, array $typed): ?int
    {
        $forms = [];
        foreach ($typed as $no => $v) {
            if (preg_match('/^[0-9]{4,8}$/D', $v) === 1) {
                $forms[$v][] = $no;
                $key = Gtin::key($v);
                if ($key !== null && $key !== $v) {
                    $forms[$key][] = $no;
                }
            }
        }
        if ($forms === []) {
            return null;
        }
        $codes = array_map('strval', array_keys($forms));
        $found = $db->column('SELECT barcode FROM sku_barcode WHERE barcode IN (' . implode(', ', array_fill(0, count($codes), '?')) . ')', $codes);
        $lines = [];
        foreach ($found as $code) {
            array_push($lines, ...($forms[(string) $code] ?? []));
        }
        return $lines === [] ? null : min($lines);
    }

    private static function refusalWords(string $code): string
    {
        return Words::COUNT_ERROR[$code] ?? Words::STOCK_ERROR[$code] ?? Words::error($code, '');
    }

    /**
     * An open sheet's facts on one line, under its heading (CV5): where it is counted (the warehouse, its shelf), who opened it and when,
     * and its note.
     *
     * @param array<string, mixed> $sheet
     */
    private static function metaLine(array $sheet, Document $doc): string
    {
        $parts = [
            $sheet['location_name'] === null ? (string) ($sheet['warehouse_name'] ?? '')
                : Words::say('STOCK_OPS', 'in_place', (string) $sheet['warehouse_name'], (string) $sheet['location_name']),
            Words::say('COUNTS', 'meta_opened', Words::say('STOCK_OPS', 'by', Html::when($doc->createdAt), (string) ($sheet['created_by_name'] ?? Words::RECORD['by_cw']))),
            (string) $doc->note,
        ];
        return implode(' · ', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /**
     * The header facts of a sheet's page (label => value, only those it has).
     *
     * @param array<string, mixed> $sheet
     * @return list<array{label: string, value: string}>
     */
    private static function facts(array $sheet, Document $doc): array
    {
        $by = static fn (?string $at, mixed $who): ?string => $at === null ? null : Words::say('STOCK_OPS', 'by', Html::when($at), (string) ($who ?? Words::RECORD['by_cw']));
        $where = $sheet['location_name'] === null ? (string) ($sheet['warehouse_name'] ?? '')
            : Words::say('STOCK_OPS', 'in_place', (string) $sheet['warehouse_name'], (string) $sheet['location_name']);
        $rows = [
            [Words::COUNTS['kind'], Words::of('COUNT_KIND', (string) $sheet['kind'])],
            [Words::COUNTS['warehouse'], $where],
            [Words::COUNTS['lines'], $sheet['kind'] === 'sheet' ? Words::say('COUNTS', 'lines_counted', (int) $sheet['counted_n'], (int) $sheet['lines_n']) : null],
            [Words::COUNTS['note'], $doc->note],
            [Words::COUNTS['opened'], $by($doc->createdAt, $sheet['created_by_name'])],
            [Words::COUNTS['posted'], $by($doc->postedAt, $sheet['posted_by_name'])],
            [Words::COUNTS['stopped'], $doc->cancelledAt === null ? null : $by($doc->cancelledAt, $sheet['cancelled_by_name']) . ': ' . (string) $doc->cancelReason],
        ];
        $out = [];
        foreach ($rows as [$label, $value]) {
            if ($value !== null && $value !== '') {
                $out[] = ['label' => $label, 'value' => $value];
            }
        }
        return $out;
    }

    /**
     * A row of the list of sheets as the board shows it.
     *
     * @param array<string, mixed> $r CountSheets::list() row
     * @return array<string, mixed>
     */
    private static function sheetRow(array $r): array
    {
        $status = (string) $r['status'];
        $kind = (string) $r['kind'];
        $group = $status === 'cancelled' ? 'cancelled' : ($kind !== 'sheet' ? 'put_back' : $status);
        $next = match (true) {
            $kind === 'line_reversal' => Words::say('COUNTS', 'next_put_back', (int) $r['reverses_line_no'], (string) ($r['line_of_number'] ?? '')),
            $kind === 'reversal' => Words::say('COUNTS', 'next_cancels', (string) ($r['reverses_number'] ?? '')),
            $status === 'draft' => (int) $r['counted_n'] === 0 ? Words::COUNTS['next_enter'] : Words::COUNTS['next_post'],
            $status === 'posted' && (int) $r['open_n'] > 0 => Words::say('COUNTS', 'next_check', (int) $r['open_n']),
            $status === 'cancelled' => Words::COUNTS['next_stopped'],
            default => Words::COUNTS['next_done'],
        };
        return [
            'id' => (int) $r['id'], 'group' => $group, 'href' => self::PATH . '/' . (int) $r['id'],
            'number_line' => $r['number'] === null ? Words::COUNTS['no_number'] : (string) $r['number'],
            'date_line' => $r['number'] === null ? Words::say('COUNTS', 'opened_on', Html::day((string) $r['created_at'])) : Words::say('COUNTS', 'posted_on', Html::day((string) $r['posted_at'])),
            'where' => $r['location'] === null ? (string) ($r['warehouse'] ?? '') : Words::say('STOCK_OPS', 'in_place', (string) $r['warehouse'], (string) $r['location']),
            // An open sheet is "Counting" (blue), as its own page says it (CV5).
            'tone' => $group === 'put_back' ? 'off' : ($group === 'draft' ? 'info' : Words::tone('DOC_STATUS', $status)),
            'state_word' => $group === 'draft' ? Words::COUNTS['counting'] : Words::of('DOC_STATUS', $status),
            'kind_word' => Words::of('COUNT_KIND', $kind), 'lines' => (int) $r['lines_n'], 'counted' => (int) $r['counted_n'], 'changed' => (int) $r['units_changed'],
            'open' => (int) $r['open_n'], 'made_by' => (string) ($r['created_by_name'] ?? ''), 'next' => $next, 'isSheet' => $kind === 'sheet',
        ];
    }

    /**
     * @param list<array<string, mixed>> $warehouses the switched-on warehouses
     * @param array<string, mixed>|null $typed
     * @return array{q: string, warehouse: ?int, show: int}
     */
    private static function planFilters(UiRequest $req, array $warehouses, ?array $typed): array
    {
        $ids = array_map(static fn (array $w): int => (int) $w['id'], $warehouses);
        $sellable = array_values(array_filter($warehouses, static fn (array $w): bool => (int) $w['is_sellable'] === 1));
        $default = $sellable !== [] ? (int) $sellable[0]['id'] : ($ids[0] ?? null);
        $wh = UiRequest::id($typed['warehouse'] ?? $req->param('warehouse'));
        // A refused open: the Show choice is the N its bar sent (the next N due), when it is one of the choices.
        $show = (int) ($typed !== null ? ($typed['n'] ?? '') : ($req->param('show') ?? CountPlan::SHOW[0]));
        return ['q' => mb_substr(trim((string) ($typed['q'] ?? $req->param('q'))), 0, 100), 'warehouse' => $wh !== null && in_array($wh, $ids, true) ? $wh : $default,
            'show' => in_array($show, CountPlan::SHOW, true) ? $show : CountPlan::SHOW[0]];
    }

    /** @return array{q: string, state: ?string, warehouse: ?int, before: ?int} */
    private static function listFilters(UiRequest $req): array
    {
        $state = $req->param('state');
        return ['q' => mb_substr(trim((string) $req->param('q')), 0, 100), 'state' => $state !== null && in_array($state, CountSheets::STATES, true) ? $state : null,
            'warehouse' => UiRequest::id($req->param('warehouse')), 'before' => UiRequest::id($req->param('before'))];
    }

    private static function pageNo(UiRequest $req, string $from): ?int
    {
        return UiRequest::id($from === 'field' ? $req->field('page') : $req->param('page'));
    }

    private static function showOf(UiRequest $req, string $from): string
    {
        $v = $from === 'field' ? $req->field('show') : $req->param('show');
        return in_array($v, ['todo', 'done'], true) ? $v : 'all';
    }
}
