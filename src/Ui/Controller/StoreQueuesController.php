<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Auth\Csrf;
use CW\CwException;
use CW\Matching\Gtin;
use CW\Queues\Corrections;
use CW\Queues\LandingOrders;
use CW\Queues\ModeChanges;
use CW\Queues\Oversells;
use CW\Queues\QueueRules;
use CW\Queues\Suspense;
use CW\Relay\RelayIntake;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Router;
use CW\Ui\Words;

/**
 * Approvals › From the stores (pack F; docs/decisions.md PF5-PF9): the four queues of what the stores send, each a segment:
 *   Lines to place      goods_in_suspense            pick the item and book the line, or close it         queues.suspense
 *   ERPNext corrections count_review (ERPNext's)     confirm the figure (books the difference), or close   queues.corrections
 *   Mode changes        policy_review (ERPNext's)    accept the change, or keep what CW has                queues.modes
 *   Oversold            oversell_event               mark it handled with how and a note; add notes       queues.oversell
 *   Website bugs        landing_order (0050, LP8)    mark it handled with how and a note                  queues.oversell
 * Everyone who reads records looks (queues.view, the GET routes); each POST needs its action's permission (the route) and is checked
 * again by its service (CW\Queues\*) inside its transaction. Every answer needs a note. A row is settled once: a second answer is
 * refused (409) and says so; a second click of the same booking shows its first answer. Plain words: Words::QUEUES, SUSPENSE,
 * CORRECTIONS, MODES, OVERSOLD, QUEUE_STATE, QUEUE_NOTICE, QUEUE_ERROR, RELAY_KIND, RELAY_REASON, OVERSELL_KIND, OVERSELL_RESOLUTION.
 */
final class StoreQueuesController
{
    public const PATH = '/ui/queues';

    public static function routes(Router $r): void
    {
        $c = new self();
        $r->add('GET', self::PATH . '/suspense', 'queues.view', $c->suspense(...));
        $r->add('GET', self::PATH . '/suspense/items', Suspense::PERM, $c->suspenseItems(...));
        $r->add('POST', self::PATH . '/suspense/{id}/pick', 'queues.suspense', $c->suspensePick(...));
        $r->add('POST', self::PATH . '/suspense/{id}/dismiss', 'queues.suspense', $c->suspenseDismiss(...));
        $r->add('GET', self::PATH . '/corrections', 'queues.view', $c->corrections(...));
        $r->add('POST', self::PATH . '/corrections/{id}/confirm', 'queues.corrections', $c->correctionConfirm(...));
        $r->add('POST', self::PATH . '/corrections/{id}/dismiss', 'queues.corrections', $c->correctionDismiss(...));
        // "Confirm all ready" (docs/decisions.md CA1-CA12): the check page (saves nothing), the press, the done page (the press's audit row).
        $r->add('GET', self::PATH . '/corrections/confirm-all', Corrections::PERM, $c->confirmAllCheck(...));
        $r->add('POST', self::PATH . '/corrections/confirm-all', Corrections::PERM, $c->confirmAllRun(...));
        $r->add('GET', self::PATH . '/corrections/confirm-all/{id}', Corrections::PERM, $c->confirmAllDone(...));
        $r->add('GET', self::PATH . '/modes', 'queues.view', $c->modes(...));
        $r->add('POST', self::PATH . '/modes/{id}/accept', 'queues.modes', $c->modeAccept(...));
        $r->add('POST', self::PATH . '/modes/{id}/dismiss', 'queues.modes', $c->modeDismiss(...));
        $r->add('GET', self::PATH . '/oversold', 'queues.view', $c->oversold(...));
        $r->add('POST', self::PATH . '/oversold/{id}/handle', 'queues.oversell', $c->oversoldHandle(...));
        $r->add('POST', self::PATH . '/oversold/{id}/note', 'queues.oversell', $c->oversoldNote(...));
        $r->add('GET', self::PATH . '/landing', 'queues.view', $c->landing(...));
        $r->add('POST', self::PATH . '/landing/{id}/handle', 'queues.oversell', $c->landingHandle(...));
    }

    // ------------------------------------------------------------------------------------------
    // Lines to place (goods-in suspense)
    // ------------------------------------------------------------------------------------------

    /**
     * @param array{id: int, form: string, item: string, units: string, note: string}|null $typed what a refused answer sent, drawn again in
     *        its row (docs/decisions.md OV7)
     */
    public function suspense(Context $ctx, int $status = 200, ?CwException $error = null, ?array $typed = null): HtmlResponse
    {
        $state = QueueRules::status($ctx->req->param('status'));
        $rows = (new Suspense($ctx->db))->list($state);
        $me = $ctx->me();
        $canAct = $me->can(Suspense::PERM);
        $canAdd = $me->can('items.create');
        // The item a refused answer named, drawn as picked again (LQ6): its name and code when the code is an item's.
        $picked = $typed !== null && $typed['form'] === 'pick' ? self::pickedLabel($ctx, $typed['item']) : '';
        $words = static fn (array $r): array => self::suspenseRow($r, $canAct, $canAdd, $typed !== null && $typed['id'] === (int) $r['id'] ? $typed + ['picked' => $picked] : null);
        return $this->page($ctx, 'queue_suspense', 'queue_suspense', Suspense::PERM, $state, $rows, $words, $status, $error, [
            'groups' => self::suspenseGroups(array_map($words, array_slice($rows, 0, QueueRules::PAGE)), $state),
            'itemsHref' => self::PATH . '/suspense/items?',
            'addWords' => $canAdd ? Words::LQ['add_new'] : '',
            'askWords' => $canAdd ? '' : Words::askToAdd('items.create'),
        ]);
    }

    /**
     * GET /suspense/items?q=: the item box's choices (LQ5; queues.suspense, read only): a fragment of what the words name (StockOps::resolve:
     * a barcode, a CW number, else words of the name, brand or range, at most StockOps::SEARCH_LIMIT), each with its CW code and the units on
     * hand in our own warehouses; fewer than 2 characters: nothing; nothing found: said.
     */
    public function suspenseItems(Context $ctx): HtmlResponse
    {
        $q = mb_substr(trim((string) ($ctx->req->param('q') ?? '')), 0, 100);
        $choices = [];
        $exact = false;
        if (mb_strlen($q) >= 2) {
            $r = $ctx->stockOps()->resolve($q);
            // Exact: a usable barcode or a CW number (StockOps::resolve `found`); only that is chosen by Enter (app.js). A barcode that is on
            // an item but not usable (shared with another item) offers nothing: the word search reads every barcode (the Codex review of
            // 7fa7fd3, finding 5). Nor do its digits fall back to the item whose CW number they are (the Codex review of c38293c, LQ11):
            // only a usable barcode of those digits is exact; "CW-" with the number is not a scan and still names that item.
            $scan = preg_match('/^[0-9]{6,64}$/D', $q) === 1;
            $unusable = $scan ? array_map('intval', $ctx->db->column('SELECT sku_id FROM sku_barcode WHERE barcode IN (?, ?) AND is_usable = 0', [$q, Gtin::key($q) ?? $q])) : [];
            if ($unusable !== [] && $r['status'] === 'found' && $ctx->db->value('SELECT 1 FROM sku_barcode WHERE barcode IN (?, ?) AND is_usable = 1 AND sku_id = ?',
                [$q, Gtin::key($q) ?? $q, (int) $r['sku_id']]) === null) {
                $r = ['status' => 'not_found'];
            }
            $exact = $r['status'] === 'found';
            $rows = $r['status'] === 'choices' ? $r['choices']
                : ($exact ? $ctx->db->all('SELECT id AS sku_id, code, name, brand FROM sku WHERE id = ?', [(int) $r['sku_id']]) : []);
            if (!$exact && $unusable !== []) {
                $rows = array_values(array_filter($rows, static fn (array $c): bool => !in_array((int) $c['sku_id'], $unusable, true)));
            }
            $ids = array_map(static fn (array $c): int => (int) $c['sku_id'], $rows);
            $held = $ids === [] ? [] : array_column($ctx->db->all('SELECT b.sku_id, SUM(b.on_hand) AS units FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id '
                . "WHERE w.stock_owner = 'own' AND b.sku_id IN (" . implode(', ', array_fill(0, count($ids), '?')) . ') GROUP BY b.sku_id', $ids), 'units', 'sku_id');
            foreach ($rows as $c) {
                $name = self::oneLine((string) $c['name']);
                $choices[] = ['label' => Words::say('LQ', 'label', $name, (string) $c['code']), 'code' => (string) $c['code'], 'name' => $name,
                    'meta' => implode(' · ', array_filter([(string) $c['code'], (string) ($c['brand'] ?? ''), Words::say('LQ', 'in_stock', (int) ($held[(int) $c['sku_id']] ?? 0))],
                        static fn (string $v): bool => $v !== ''))];
            }
        }
        return $ctx->fragment('queue_items', ['choices' => $choices, 'exact' => $exact && count($choices) === 1,
            'says' => $choices === [] && mb_strlen($q) >= 2 ? Words::say('LQ', 'none', $q) : '']);
    }

    /** The item box's chip for a typed CW code (LQ6): the item's name and code when the code is an item's, else ''. */
    public static function pickedLabel(Context $ctx, string $item): string
    {
        if (trim($item) === '') {
            return '';
        }
        $sku = $ctx->db->one('SELECT code, name FROM sku WHERE code = ? AND merged_into_sku_id IS NULL', [strtoupper(trim($item))]);
        return $sku === null ? '' : Words::say('LQ', 'label', self::oneLine((string) $sku['name']), (string) $sku['code']);
    }

    private static function oneLine(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * The board's groups (LQ2): goods in (blue), then corrections (amber), each with its rows, its heading (waiting for an item while the list
     * shows the waiting lines), its count with the stores the lines came from, and the units column's name (a correction: "Set to").
     *
     * @param list<array<string, mixed>> $rows suspenseRow() rows
     * @return list<array{key: string, tone: string, name: string, count: string, units_col: string, rows: list<array<string, mixed>>}>
     */
    private static function suspenseGroups(array $rows, ?string $state): array
    {
        $out = [];
        foreach (['goods' => false, 'corrections' => true] as $key => $correction) {
            $in = array_values(array_filter($rows, static fn (array $r): bool => $r['correction'] === $correction));
            if ($in === []) {
                continue;
            }
            $stores = array_values(array_unique(array_filter(array_map(static fn (array $r): string => (string) ($r['store']['name'] ?? ''), $in))));
            $count = count($in) === 1 ? Words::LQ['count_one'] : Words::say('LQ', 'count_many', count($in));
            $out[] = ['key' => $key, 'tone' => $correction ? 'needs' : 'info', 'name' => Words::LQ[$key . ($state === 'open' ? '_waiting' : '')],
                'count' => $stores === [] ? $count : Words::say('LQ', 'count_from', $count, Words::andList($stores)),
                'units_col' => Words::LQ[$correction ? 'col_set_to' : 'col_units'], 'rows' => $in];
        }
        return $out;
    }

    /**
     * What an answer of a row sent, to draw again in its boxes exactly as typed (the Codex review of b6b6512, finding 4: "000012" cut to
     * "00001" was booked as 1 by a second press). A value is never cut into another one: kept whole while it is of a sane length (so the
     * same refusal comes again until it is put right), else left out (the box empty, to be typed again).
     *
     * @return array{id: int, form: string, item: string, units: string, note: string}
     */
    private static function typedAnswer(Context $ctx, string $form): array
    {
        $f = static function (string $name, int $max) use ($ctx): string {
            $v = (string) ($ctx->req->field($name) ?? '');
            return mb_strlen($v) <= $max ? $v : '';
        };
        return ['id' => $ctx->id(), 'form' => $form, 'item' => $f('item', 64), 'units' => $f('units', 20), 'note' => $f('note', 2 * QueueRules::NOTE_MAX)];
    }

    public function suspensePick(Context $ctx): HtmlResponse
    {
        $units = trim((string) ($ctx->req->field('units') ?? '1'));
        try {
            if (preg_match('/^\d{1,5}$/D', $units === '' ? '1' : $units) !== 1) {
                throw new CwException('bad_units', 'one unit of the line is a whole number of the item\'s units', 400, ['field' => 'units']);
            }
            $r = (new Suspense($ctx->db))->pick($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('item') ?? ''), (int) ($units === '' ? '1' : $units),
                (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return self::fromInbox($ctx, 'suspense', $e) ?? $this->suspense($ctx, $e->httpStatus, $e, self::typedAnswer($ctx, 'pick'));
        }
        $notice = $r['result'] === 'queued' ? 'sent_to_corrections' : ($r['replayed'] ? 'booked_before' : 'booked');
        return self::toInbox($ctx, $notice, (string) $r['sku_code'])
            ?? HtmlResponse::redirect(Html::url(self::PATH . '/suspense', ['notice' => $notice, 'item' => $r['sku_code']]));
    }

    public function suspenseDismiss(Context $ctx): HtmlResponse
    {
        try {
            (new Suspense($ctx->db))->dismiss($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return self::fromInbox($ctx, 'suspense', $e) ?? $this->suspense($ctx, $e->httpStatus, $e, self::typedAnswer($ctx, 'dismiss'));
        }
        return self::toInbox($ctx, 'closed') ?? HtmlResponse::redirect(Html::url(self::PATH . '/suspense', ['notice' => 'closed']));
    }

    // ------------------------------------------------------------------------------------------
    // ERPNext corrections (count_review, source erp_reconciliation)
    // ------------------------------------------------------------------------------------------

    public function corrections(Context $ctx, int $status = 200, ?CwException $error = null): HtmlResponse
    {
        $state = QueueRules::status($ctx->req->param('status'));
        $rows = (new Corrections($ctx->db))->list($state);
        return $this->page($ctx, 'queue_corrections', 'queue_corrections', Corrections::PERM, $state, $rows, self::correctionRow(...), $status, $error);
    }

    public function correctionConfirm(Context $ctx): HtmlResponse
    {
        try {
            $r = (new Corrections($ctx->db))->confirm($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return self::fromInbox($ctx, 'corrections', $e) ?? $this->corrections($ctx, $e->httpStatus, $e);
        }
        $notice = $r['replayed'] ? 'confirmed_before' : ($r['delta'] === 0 ? 'confirmed_nothing' : 'confirmed');
        return self::toInbox($ctx, $notice) ?? HtmlResponse::redirect(Html::url(self::PATH . '/corrections', ['notice' => $notice]));
    }

    public function correctionDismiss(Context $ctx): HtmlResponse
    {
        try {
            (new Corrections($ctx->db))->dismiss($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return self::fromInbox($ctx, 'corrections', $e) ?? $this->corrections($ctx, $e->httpStatus, $e);
        }
        return self::toInbox($ctx, 'closed') ?? HtmlResponse::redirect(Html::url(self::PATH . '/corrections', ['notice' => 'closed']));
    }

    // ------------------------------------------------------------------------------------------
    // "Confirm all ready" (docs/decisions.md CA1-CA12; ConfirmAll.dc.html and its check and done steps)
    // ------------------------------------------------------------------------------------------

    /** The most ids one press may carry (more is no check page's form: refused whole). */
    private const CONFIRM_ALL_MAX_IDS = 10 * Corrections::BULK_MAX;
    /** How long a check page may be pressed after it was drawn (CA12): 30 minutes, then it is drawn again. */
    public const CONFIRM_ALL_PAGE_SECONDS = 1800;
    /** The purpose a check page's signature is made for (Csrf::forForm). */
    private const CONFIRM_ALL_FORM = 'confirm-all';

    /**
     * The check page's signature (CA12, Codex HIGH 1): the person, the moment the page was drawn and its ids exactly as drawn, in their order,
     * under the server's secret (Csrf::forForm, its own domain). The press is refused whole unless it sends them back unchanged.
     */
    public static function confirmAllSignature(Csrf $csrf, int $staffId, int $at, string $ids): string
    {
        return $csrf->forForm(self::CONFIRM_ALL_FORM, $staffId . "\n" . $at . "\n" . $ids);
    }

    /**
     * GET /corrections/confirm-all: the check page (CA4). It saves nothing: what a press would do (how many are ready, the units they add and
     * take off, how many stay under Needs a look), the three plain sentences, the note, Back and "Confirm N" (the page's one main button).
     * Its form carries the ids it shows (`ids`), so a correction that arrives after it was drawn is never confirmed by its press. Nothing
     * ready: no button, a plain line. A refused press ($error: the note) comes back here, drawn again from now, with the note as typed.
     */
    public function confirmAllCheck(Context $ctx, int $status = 200, ?CwException $error = null): HtmlResponse
    {
        $rows = (new Corrections($ctx->db))->readiness();
        $ready = array_values(array_filter($rows, static fn (array $r): bool => $r['why'] === null));
        $deltas = array_map(static fn (array $r): int => (int) $r['effect']['delta'], $ready);
        $shown = $ready === [] ? $rows : $ready;
        $stores = array_values(array_unique(array_map(static fn (array $r): string => (string) ($r['store']['name'] ?? Words::QUEUES['store_unknown']), $shown)));
        $places = Words::andList(array_values(array_unique(array_map(static fn (array $r): string => (string) $r['warehouse'], $shown))));
        $items = static fn (int $n): string => $n === 1 ? Words::CONFIRM_ALL['items_one'] : Words::say('CONFIRM_ALL', 'items', $n);
        $n = count($ready);
        $note = $error !== null ? (string) ($ctx->req->field('note') ?? '') : '';
        // The ids drawn, in their order, signed for this person with the moment they were drawn (CA12).
        $ids = implode(',', array_map(static fn (array $r): string => (string) (int) $r['id'], $ready));
        $at = time();
        return $ctx->page('confirm_all', [
            'title' => $n === 1 ? Words::CONFIRM_ALL['check_title_one'] : Words::say('CONFIRM_ALL', 'check_title', $n),
            'from' => $shown === [] ? null : Words::say('CONFIRM_ALL', 'from', Words::andList($stores), $places),
            'lines' => [
                ['tone' => 'done', 'text' => Words::CONFIRM_ALL['line_confirmed'], 'figure' => $items($n)],
                ['tone' => 'info', 'text' => Words::say('CONFIRM_ALL', 'line_units', $places === '' ? Words::QUEUES['store_unknown'] : $places),
                    'figure' => Words::say('CONFIRM_ALL', 'units', array_sum(array_map(static fn (int $d): int => max(0, $d), $deltas)),
                        array_sum(array_map(static fn (int $d): int => max(0, -$d), $deltas)))],
                ['tone' => 'needs', 'text' => Words::CONFIRM_ALL['line_left'], 'figure' => $items(count($rows) - $n)],
            ],
            'ready' => $n,
            'ids' => $ids,
            'at' => $at,
            'sig' => self::confirmAllSignature($ctx->csrf, $ctx->me()->id, $at, $ids),
            'note' => mb_strlen($note) <= 2 * QueueRules::NOTE_MAX ? $note : '',
            'noteMin' => QueueRules::NOTE_MIN,
            'noteMax' => QueueRules::NOTE_MAX,
            'back' => Html::url(InboxController::PATH, ['show' => 'corrections']),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::CONFIRM_ALL['eyebrow']]);
    }

    /**
     * POST /corrections/confirm-all: the press (CA5-CA7, CA12). The ids its check page drew, as it drew them for this person and signed them
     * (confirmAllSignature: an id added, taken away or moved, another person's page, a page older than CONFIRM_ALL_PAGE_SECONDS: 409, the
     * check page drawn again from now, nothing booked), then Corrections::confirmAll() (each confirmed as one by one when still ready, the
     * rest left and listed; the press saved as its run); 303 to the done page. A form that is not a check page's at all (no ids, a bad one,
     * too many) is refused whole (400); a refused note brings the check page back.
     */
    public function confirmAllRun(Context $ctx): HtmlResponse
    {
        $field = (string) ($ctx->req->field('ids') ?? '');
        $ids = self::idList($field);
        if ($ids === null) {
            return $ctx->error(400, 'bad_form', Words::ERROR['bad_form'], [Html::url(InboxController::PATH, ['show' => 'corrections']), Words::CONFIRM_ALL['back_list']]);
        }
        $at = (string) ($ctx->req->field('at') ?? '');
        $sig = (string) ($ctx->req->field('sig') ?? '');
        $now = time();
        if (preg_match('/^[1-9][0-9]{0,11}$/D', $at) !== 1 || (int) $at < $now - self::CONFIRM_ALL_PAGE_SECONDS || (int) $at > $now + 60
            || !hash_equals(self::confirmAllSignature($ctx->csrf, $ctx->me()->id, (int) $at, $field), $sig)) {
            return $this->confirmAllCheck($ctx, 409, new CwException('check_page_changed', 'the press does not carry the ids its check page drew for you, '
                . 'or the page is too old: look at it again', 409));
        }
        try {
            $run = (new Corrections($ctx->db))->confirmAll($ctx->caller(), $ids, (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return $this->confirmAllCheck($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(self::PATH . '/corrections/confirm-all/' . $run['run']);
    }

    /**
     * GET /corrections/confirm-all/{id}: the done page of a press (CA8, CA15), read from its run (Corrections::run(): its end row, or, for a
     * press that stopped before its end, what the queue shows of its ids): how many were confirmed and how many are left for you, each left
     * one with its item, why, and Open › to it in Approvals; where the press is saved, and the way back. Only the person who pressed sees
     * it (anyone else: 404, the same as no such press). A plain page: no script needed.
     */
    public function confirmAllDone(Context $ctx): HtmlResponse
    {
        $run = (new Corrections($ctx->db))->run($ctx->id());
        // A person's press is its presser's; a nightly run (no person) is everyone's who answers ERPNext corrections (the route's
        // permission; docs/decisions.md NC9). Anyone else: 404, the same as no such press.
        $nightly = $run !== null && $run['staff_user_id'] === null && $run['actor'] === 'system:' . Corrections::NIGHTLY_JOB;
        if ($run === null || (!$nightly && $run['staff_user_id'] !== $ctx->me()->id)) {
            return $ctx->error(404, 'not_found', Words::error('not_found'), [Html::url(InboxController::PATH, ['show' => 'corrections']), Words::CONFIRM_ALL['back_list']]);
        }
        $left = $run['left'];
        $ids = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['id'], $left)));
        $names = [];
        if ($ids !== []) {
            foreach ($ctx->db->all('SELECT r.id, s.name FROM count_review r JOIN sku s ON s.id = r.sku_id WHERE r.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids) as $r) {
                $names[(int) $r['id']] = (string) $r['name'];
            }
        }
        $rows = array_map(static fn (array $l): array => [
            'title' => $names[(int) $l['id']] ?? Words::say('CONFIRM_ALL', 'item_gone', (int) $l['id']),
            'line' => self::confirmAllWhy(is_array($l['why'] ?? null) ? $l['why'] : []),
            'href' => Html::url(InboxController::PATH, ['show' => 'corrections', 'open' => 'corrections-' . (int) $l['id']]),
        ], $left);
        return $ctx->page('confirm_all_done', [
            'confirmed' => count($run['confirmed']),
            'stopped' => $run['state'] === 'stopped',
            'left' => $rows,
            'saved' => Words::say('CONFIRM_ALL', 'saved', Words::AUDIT_ACTION[Corrections::RUN_ACTION], Html::when((string) $run['created_at']),
                $nightly ? Words::CONFIRM_ALL['by_night'] : $ctx->me()->displayName),
            'back' => Html::url(InboxController::PATH, ['show' => 'corrections']),
        ], 200, ['title' => Words::CONFIRM_ALL['done_title']]);
    }

    /** The ids a check page's form carries ("12,15,19": each a row id, at most CONFIRM_ALL_MAX_IDS), else null. @return list<int>|null */
    private static function idList(string $field): ?array
    {
        $parts = $field === '' ? [] : explode(',', $field);
        if ($parts === [] || count($parts) > self::CONFIRM_ALL_MAX_IDS) {
            return null;
        }
        $out = [];
        foreach ($parts as $p) {
            if (preg_match('/^[1-9][0-9]{0,17}$/D', $p) !== 1) {
                return null;
            }
            $out[] = (int) $p;
        }
        return $out;
    }

    /**
     * A correction's line on Approvals when the Show is ERPNext corrections (CA1): a ready one says what confirming does ("ERPNext set 22 ·
     * adds 22"), one that needs a look says why (confirmAllWhy()). @param array<string, mixed> $r a Corrections::readiness() row
     */
    public static function confirmAllLine(array $r): string
    {
        if ($r['why'] !== null) {
            return self::confirmAllWhy($r['why']);
        }
        $delta = (int) $r['effect']['delta'];
        return match (true) {
            $delta > 0 => Words::say('CONFIRM_ALL', 'ready_adds', (int) $r['proposed_qty'], $delta),
            $delta < 0 => Words::say('CONFIRM_ALL', 'ready_takes', (int) $r['proposed_qty'], -$delta),
            default => Words::say('CONFIRM_ALL', 'ready_same', (int) $r['proposed_qty']),
        };
    }

    /**
     * Why Confirm all leaves a correction, in words (Words::CONFIRM_ALL why_<code>; Corrections::why() and confirmAll()): a big change with
     * its units and the limit, the number of website products, the day of the stock fix. Anything it does not know reads as "changed".
     *
     * @param array<string, mixed> $why
     */
    public static function confirmAllWhy(array $why): string
    {
        $code = (string) ($why['code'] ?? '');
        return match ($code) {
            'big' => Words::say('CONFIRM_ALL', 'why_big', self::signedUnits((int) ($why['delta'] ?? 0)), (int) ($why['limit'] ?? 0)),
            'variants' => Words::say('CONFIRM_ALL', 'why_variants', (int) ($why['links'] ?? 2)),
            'fixed' => Words::say('CONFIRM_ALL', 'why_fixed', Html::day((string) ($why['fix_at'] ?? ''))),
            'counted', 'newer', 'below_zero', 'not_reached', 'not_reached_or_elsewhere' => Words::CONFIRM_ALL['why_' . $code],
            default => Words::CONFIRM_ALL['why_changed'],
        };
    }

    /** The rules of Needs a look as the board's "The list is split in two" lists them, in their order (CA2). @return list<string> */
    public static function confirmAllRules(int $limit): array
    {
        return [Words::CONFIRM_ALL['rule_counted'], Words::CONFIRM_ALL['rule_newer'], Words::say('CONFIRM_ALL', 'rule_big', $limit),
            Words::CONFIRM_ALL['rule_below_zero'], Words::CONFIRM_ALL['rule_variants'], Words::CONFIRM_ALL['rule_fixed']];
    }

    /** "+140" or "−140" (the minus sign, as the record's figures). */
    private static function signedUnits(int $v): string
    {
        return $v < 0 ? '−' . number_format(-$v) : '+' . number_format($v);
    }

    // ------------------------------------------------------------------------------------------
    // Mode changes (policy_review, source erp_update_product)
    // ------------------------------------------------------------------------------------------

    public function modes(Context $ctx, int $status = 200, ?CwException $error = null): HtmlResponse
    {
        $state = QueueRules::status($ctx->req->param('status'));
        $rows = (new ModeChanges($ctx->db))->list($state);
        return $this->page($ctx, 'queue_modes', 'queue_modes', ModeChanges::PERM, $state, $rows, self::modeRow(...), $status, $error);
    }

    public function modeAccept(Context $ctx): HtmlResponse
    {
        try {
            $r = (new ModeChanges($ctx->db))->accept($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return self::fromInbox($ctx, 'modes', $e) ?? $this->modes($ctx, $e->httpStatus, $e);
        }
        $notice = $r['applied'] === 'mode' ? 'accepted_mode' : 'accepted_policy';
        return self::toInbox($ctx, $notice) ?? HtmlResponse::redirect(Html::url(self::PATH . '/modes', ['notice' => $notice]));
    }

    public function modeDismiss(Context $ctx): HtmlResponse
    {
        try {
            (new ModeChanges($ctx->db))->dismiss($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return self::fromInbox($ctx, 'modes', $e) ?? $this->modes($ctx, $e->httpStatus, $e);
        }
        return self::toInbox($ctx, 'kept') ?? HtmlResponse::redirect(Html::url(self::PATH . '/modes', ['notice' => 'kept']));
    }

    // ------------------------------------------------------------------------------------------
    // Oversold (oversell_event)
    // ------------------------------------------------------------------------------------------

    public function oversold(Context $ctx, int $status = 200, ?CwException $error = null): HtmlResponse
    {
        $state = QueueRules::status($ctx->req->param('status'));
        $rows = (new Oversells($ctx->db))->list($state);
        return $this->page($ctx, 'queue_oversold', 'queue_oversold', Oversells::PERM, $state, $rows, self::oversoldRow(...), $status, $error,
            ['resolutions' => Oversells::RESOLUTIONS]);
    }

    public function oversoldHandle(Context $ctx): HtmlResponse
    {
        try {
            (new Oversells($ctx->db))->handle($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('resolution') ?? ''), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return self::fromInbox($ctx, 'oversold', $e) ?? $this->oversold($ctx, $e->httpStatus, $e);
        }
        return self::toInbox($ctx, 'handled') ?? HtmlResponse::redirect(Html::url(self::PATH . '/oversold', ['notice' => 'handled']));
    }

    public function oversoldNote(Context $ctx): HtmlResponse
    {
        try {
            (new Oversells($ctx->db))->addNote($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return self::fromInbox($ctx, 'oversold', $e) ?? $this->oversold($ctx, $e->httpStatus, $e);
        }
        // A note settles nothing: the inbox keeps this record open.
        return self::toInbox($ctx, 'noted', '', 'oversold-' . $ctx->id())
            ?? HtmlResponse::redirect(Html::url(self::PATH . '/oversold', ['notice' => 'noted']) . '#oversold-' . $ctx->id());
    }

    // ------------------------------------------------------------------------------------------
    // Website bugs (landing_order: an order for a landing page, docs/decisions.md LP8)
    // ------------------------------------------------------------------------------------------

    public function landing(Context $ctx, int $status = 200, ?CwException $error = null): HtmlResponse
    {
        $state = QueueRules::status($ctx->req->param('status'));
        $rows = (new LandingOrders($ctx->db))->list($state);
        return $this->page($ctx, 'queue_landing', 'queue_landing', LandingOrders::PERM, $state, $rows, self::landingRow(...), $status, $error,
            ['resolutions' => LandingOrders::RESOLUTIONS, 'canOpen' => $ctx->me()->can('linking.view')]);
    }

    public function landingHandle(Context $ctx): HtmlResponse
    {
        try {
            $paid = $ctx->req->field('paid');
            (new LandingOrders($ctx->db))->handle($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('resolution') ?? ''), (string) ($ctx->req->field('note') ?? ''),
                $paid === '0' || $paid === '1' ? (int) $paid : null);
        } catch (CwException $e) {
            return self::fromInbox($ctx, 'landing', $e) ?? $this->landing($ctx, $e->httpStatus, $e);
        }
        return self::toInbox($ctx, 'handled') ?? HtmlResponse::redirect(Html::url(self::PATH . '/landing', ['notice' => 'handled']));
    }

    /**
     * An answer sent from Approvals › Waiting for you (docs/decisions.md AV5): the same route, permission, service and notice; only where it
     * leads differs. toInbox: the inbox on the next record (or on $stay), with this notice; null when the answer came from the queue's page.
     */
    private static function toInbox(Context $ctx, string $notice, string $item = '', ?string $stay = null): ?HtmlResponse
    {
        return InboxController::from($ctx) ? InboxController::answered($ctx, 'queue', $notice, $item, $stay) : null;
    }

    /** A refused answer sent from the inbox: back there, on the record, in this page's words; null when it came from the queue's page. */
    private static function fromInbox(Context $ctx, string $kind, CwException $e): ?HtmlResponse
    {
        return InboxController::from($ctx) ? InboxController::refused($ctx, $kind . '-' . $ctx->id(), self::plain($e), $e) : null;
    }

    // ------------------------------------------------------------------------------------------
    // The rows in words
    // ------------------------------------------------------------------------------------------

    /**
     * A row of the board in words (LQ3): what came in, where from, why it waits, its status cell, its units; for someone who may answer an open
     * line, what the answer's boxes hold (as a refused answer typed them: OV7) and where "+ Add new item" leads (items.create).
     *
     * @param array<string, mixed> $r a Suspense::list() row
     * @param array{id: int, form: string, item: string, units: string, note: string, picked?: string}|null $typed
     * @return array<string, mixed>
     */
    public static function suspenseRow(array $r, bool $canAct = false, bool $canAdd = false, ?array $typed = null): array
    {
        $names = [];
        if ($r['external_variant_id'] !== null) {
            $names[] = Words::say('SUSPENSE', 'variant', (string) $r['external_variant_id']);
        }
        if ($r['erp_item_code'] !== null) {
            $names[] = Words::say('SUSPENSE', 'erp_item', (string) $r['erp_item_code']);
        }
        $correction = (string) $r['movement_type'] === RelayIntake::KIND_RECONCILIATION;
        return $r + [
            'title' => Words::say('SUSPENSE', 'title', Words::of('RELAY_KIND', (string) $r['movement_type']), abs((int) $r['qty']),
                (string) ($r['description'] ?? '') !== '' ? (string) $r['description'] : Words::SUSPENSE['no_description']),
            'from' => Words::say('QUEUES', 'from', $r['store']['name'] ?? Words::QUEUES['store_unknown']),
            'document' => Words::say('SUSPENSE', 'document', (string) $r['doc_ref'], (int) $r['line_index']),
            'names' => $names === [] ? null : Words::say('SUSPENSE', 'names', Words::andList($names)),
            'why' => Words::say('SUSPENSE', 'why', Words::of('RELAY_REASON', (string) $r['reason'])),
            'received' => Words::say('QUEUES', 'received', Html::when((string) $r['created_at'])),
            'correction' => $correction,
            'picked' => $r['sku_code'] === null ? null : Words::say('LQ', 'label', self::oneLine((string) $r['sku_name']), (string) $r['sku_code']),
            'closed' => self::closed($r),
            'what' => (string) ($r['description'] ?? '') !== '' ? (string) $r['description'] : Words::SUSPENSE['no_description'],
            'where' => Words::say('LQ', 'where', Words::of('RELAY_KIND', (string) $r['movement_type']), (string) $r['doc_ref'], (int) $r['line_index'],
                Html::when((string) $r['created_at'])),
            'units' => $correction ? (int) $r['qty'] : abs((int) $r['qty']),
            'st_tone' => match ((string) $r['status']) { 'open' => $correction ? 'needs' : 'info', 'resolved' => 'done', default => 'off' },
            'st_word' => match ((string) $r['status']) {
                'open' => Words::LQ[$correction ? 'st_correction' : 'st_waiting'], 'resolved' => Words::LQ[$correction ? 'st_sent' : 'st_placed'], default => Words::LQ['st_closed'] },
            'answer' => $canAct && (string) $r['status'] === 'open',
            'typed_item' => $typed !== null && $typed['form'] === 'pick' ? $typed['item'] : '',
            'typed_units' => $typed !== null && $typed['form'] === 'pick' ? $typed['units'] : '1',
            'typed_note' => $typed !== null && $typed['form'] === 'pick' ? $typed['note'] : '',
            'typed_close' => $typed !== null && $typed['form'] === 'dismiss' ? $typed['note'] : null,
            'picked_label' => $typed !== null && $typed['form'] === 'pick' ? (string) ($typed['picked'] ?? '') : '',
            'add_href' => $canAdd ? Html::url('/ui/items/new', ['name' => mb_substr(trim((string) ($r['description'] ?? '')), 0, 120)]) . '#new' : '',
            'note_shown' => (string) ($r['note'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $r a Corrections::list() row @return array<string, mixed> */
    public static function correctionRow(array $r): array
    {
        $e = $r['effect'];
        $effect = [];
        if ($e !== null && $e['newer']) {
            $effect[] = Words::CORRECTIONS['newer'];
        } elseif ($e !== null) {
            $effect[] = Words::say('CORRECTIONS', 'then', (int) $e['shelf'], (int) $e['allocated']);
            if ((int) $e['carried'] !== 0) {
                $effect[] = Words::say('CORRECTIONS', 'carried', (int) $e['carried'] > 0 ? '+' . (int) $e['carried'] : (string) (int) $e['carried']);
            }
            $effect[] = $e['for_sale'] ? Words::say('CORRECTIONS', 'target_for_sale', (int) $e['target'], (int) $r['proposed_qty'], max(0, (int) $e['allocated']))
                : Words::say('CORRECTIONS', 'target_shelf', (int) $e['target']);
            $effect[] = match (true) {
                $e['delta'] > 0 => Words::say('CORRECTIONS', 'will_add', (int) $e['delta']),
                $e['delta'] < 0 => Words::say('CORRECTIONS', 'will_take', -(int) $e['delta']),
                default => Words::CORRECTIONS['no_difference'],
            };
        }
        return $r + [
            'title' => Words::say('CORRECTIONS', 'title', (string) $r['sku_name'], (int) $r['proposed_qty']),
            'from' => Words::say('QUEUES', 'from', $r['store']['name'] ?? Words::QUEUES['store_unknown']),
            'set_at' => Words::say('CORRECTIONS', 'set_at', Html::when($r['at']), (string) $r['warehouse']),
            'document' => Words::say('CORRECTIONS', 'document', (string) $r['ref']),
            'effect_lines' => $effect,
            'received' => Words::say('QUEUES', 'received', Html::when((string) $r['created_at'])),
            'closed' => self::closed($r),
        ];
    }

    /** @param array<string, mixed> $r a ModeChanges::list() row @return array<string, mixed> */
    public static function modeRow(array $r): array
    {
        $d = $r['detail'];
        $store = $r['store']['name'] ?? Words::QUEUES['store_unknown'];
        $cwMode = is_string($d['cw_mode'] ?? null) ? $d['cw_mode'] : null;
        return $r + [
            'title' => Words::say('MODES', 'title', (string) ($r['sku_name'] ?? ''), (string) $r['site_mode']),
            'from' => Words::say('QUEUES', 'from', $store),
            'variant' => $r['external_variant_id'] === null ? null : Words::say('MODES', 'variant', (string) $r['external_variant_id']),
            'low' => is_int($d['low_stock'] ?? null) ? Words::say('MODES', 'low_stock', (int) $d['low_stock']) : null,
            'cw' => $cwMode === null ? Words::MODES['cw_none'] : Words::say('MODES', 'cw_now', $cwMode),
            'will' => $r['applies'] === 'mode' ? Words::say('MODES', 'will_mode', (string) $r['site_mode'], $store)
                : Words::say('MODES', 'will_policy', Words::of('POLICY', (string) $r['proposed_policy'])),
            'received' => Words::say('QUEUES', 'received', Html::when((string) $r['created_at'])),
            'closed' => $r['resolution'] === 'superseded' ? Words::MODES['superseded'] : self::closed($r),
        ];
    }

    /** @param array<string, mixed> $r an Oversells::list() row @return array<string, mixed> */
    public static function oversoldRow(array $r): array
    {
        return $r + [
            'title' => Words::say('OVERSOLD', 'title', (int) $r['shortfall'], (string) $r['sku_name']),
            'from' => Words::say('QUEUES', 'from', $r['store']['name'] ?? Words::QUEUES['store_unknown']),
            'order' => $r['order_ref'] === null ? Words::OVERSOLD['no_order'] : Words::say('OVERSOLD', 'order', (string) $r['order_ref'], (string) $r['warehouse']),
            'what' => Words::say('OVERSOLD', 'kind', Words::of('OVERSELL_KIND', (string) $r['kind'])),
            'available' => Words::say('OVERSOLD', 'available', (int) $r['available_after']),
            'received' => Words::say('QUEUES', 'received', Html::when((string) $r['created_at'])),
            'note_lines' => array_map(static fn (array $n): string => Words::say('OVERSOLD', 'note_by', (string) ($n['by_name'] ?? Words::QUEUES['someone']),
                Html::when($n['created_at']), $n['note']), $r['notes']),
            'closed' => $r['status'] === 'open' ? null : Words::say('OVERSOLD', 'closed', Words::of('OVERSELL_RESOLUTION', (string) $r['resolution']),
                Html::when((string) $r['resolved_at']), (string) ($r['resolved_by_name'] ?? Words::QUEUES['someone']), (string) ($r['note'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $r a LandingOrders::list() row @return array<string, mixed> */
    public static function landingRow(array $r): array
    {
        $title = (string) ($r['product_title'] ?? '') !== '' ? (string) $r['product_title'] : ((string) ($r['variant_title'] ?? '') !== '' ? (string) $r['variant_title']
            : Words::LISTING['no_title']);
        return $r + [
            'title' => Words::say('WEBSITE_BUG', 'title', (string) $r['order_ref'], $title),
            'from' => Words::say('QUEUES', 'from', $r['store']['name'] ?? Words::QUEUES['store_unknown']),
            'option' => Words::say('WEBSITE_BUG', 'option', (string) $r['external_variant_id']),
            'units_line' => Words::say('WEBSITE_BUG', (int) $r['units'] === 1 ? 'unit' : 'units', (int) $r['units']),
            'paid_line' => Words::WEBSITE_BUG[(int) $r['paid'] === 1 ? 'paid' : 'not_paid'],
            'still' => $r['listing_status'] === 'landing' ? null : Words::WEBSITE_BUG['not_landing_now'],
            'received' => Words::say('QUEUES', 'received', Html::when((string) $r['created_at'])),
            'link' => '/ui/review/listing/' . (int) $r['listing_id'],
            'closed' => $r['status'] === 'open' ? null : Words::say('WEBSITE_BUG', 'closed', Words::of('LANDING_RESOLUTION', (string) $r['resolution']),
                Html::when((string) $r['resolved_at']), (string) ($r['resolved_by_name'] ?? Words::QUEUES['someone']), (string) ($r['note'] ?? '')),
        ];
    }

    /** "Done on 7 Oct 2026, 10:26 by Hari: booked on the right item." for a settled row. @param array<string, mixed> $r */
    private static function closed(array $r): ?string
    {
        if ($r['status'] === 'open') {
            return null;
        }
        if ($r['resolved_by'] === null) {
            return Words::say('QUEUES', 'closed_cw', Words::of('QUEUE_STATE', (string) $r['status']), Html::when((string) $r['resolved_at']));
        }
        return Words::say('QUEUES', 'closed', Words::of('QUEUE_STATE', (string) $r['status']), Html::when((string) $r['resolved_at']),
            (string) ($r['resolved_by_name'] ?? Words::QUEUES['someone']), (string) ($r['note'] ?? ''));
    }

    /**
     * One queue page: its rows in words (at most a page; `more` when there are more), the status filter, whether this person may answer
     * (else "You can look; <who> change this."), the notice of the last answer and the error of a refused one.
     *
     * @param list<array<string, mixed>> $rows
     * @param \Closure(array<string, mixed>): array<string, mixed> $words
     * @param array<string, mixed> $more
     */
    private function page(Context $ctx, string $template, string $page, string $perm, ?string $state, array $rows, \Closure $words, int $status,
        ?CwException $error, array $more = []): HtmlResponse
    {
        $canAct = $ctx->me()->can($perm);
        $n = $ctx->req->param('notice');
        $notice = $n !== null && isset(Words::QUEUE_NOTICE[$n]) ? Words::say('QUEUE_NOTICE', $n, (string) ($ctx->req->param('item') ?? '')) : null;
        return $ctx->page($template, [
            'rows' => array_map($words, array_slice($rows, 0, QueueRules::PAGE)),
            'more' => count($rows) > QueueRules::PAGE,
            'pageSize' => QueueRules::PAGE,
            'state' => $state ?? 'all',
            'states' => [...QueueRules::STATUSES, 'all'],
            'action' => self::PATH . '/' . substr($page, strlen('queue_')),
            'canAct' => $canAct,
            'lookOnly' => $canAct ? null : Words::whoCan($perm),
            'noteMin' => QueueRules::NOTE_MIN,
            'noteMax' => QueueRules::NOTE_MAX,
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ] + $more, $status, ['title' => Words::SEGMENT[$page], 'notice' => $notice]);
    }

    /** A refusal in words: by its code (Words::QUEUE_ERROR), else the service's message as a sentence. */
    public static function plain(CwException $e): string
    {
        return Words::QUEUE_ERROR[$e->errorCode] ?? Words::say('QUEUE_ERROR', 'other', rtrim($e->getMessage(), '.'));
    }
}
