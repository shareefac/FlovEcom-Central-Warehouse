<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Counts\CountSheets;
use CW\Counts\StockDoubt;
use CW\CwException;
use CW\Documents\Documents;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Router;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Inventory › Doubtful stock (0066; docs/decisions.md DS1-): the items of one store whose stock figure is in doubt, ONLY those, to check on
 * the shelf one at a time (CW\Counts\StockDoubt).
 *
 *   To check   the open rows of the store chosen (the one store that has rows, else the first; a choice when several have), in the order to
 *              check them (StockDoubt::order), 50 a page: the item (its page), the shop's figure when the list was made, CW's figure now at the
 *              store's selling warehouse, why, the units sold in 30 days, and per row ONE plain form: "On the shelf" and "Save count"; under
 *              More, "Not needed" with a note. A search (CW number or name) and the filter by why. No script.
 *   Checked    the rows answered, newest first: who, when, what was on the shelf and what it changed, or why it was not needed.
 *
 * Who: documents.view looks (the GET routes); doc.CNT.post answers (the POST routes; checked again by StockDoubt, never admin). An answer
 * goes back to the list as it was (the store, why, the search and the page travel in each form) with its line ("Counted 42 of CW-000123. CW
 * had 40: booked +2."); a refusal draws the page again with the words next to the box and what was typed in it, nothing booked. Plain words:
 * Words::DOUBT, DOUBT_WHY, DOUBT_STATE, DOUBT_NOTICE, DOUBT_ERROR.
 */
final class DoubtController
{
    public const PATH = '/ui/stock/doubtful';

    public static function routes(Router $r): void
    {
        $c = new self();
        $post = 'doc.' . CountSheets::TYPE . '.post';
        $r->add('GET', self::PATH, 'documents.view', $c->index(...));
        $r->add('GET', self::PATH . '/checked', 'documents.view', $c->checked(...));
        $r->add('POST', self::PATH . '/{id}/count', $post, $c->count(...));
        $r->add('POST', self::PATH . '/{id}/dismiss', $post, $c->dismiss(...));
    }

    /**
     * GET: the items to check. After a refused answer: the page again ($status, $error) with what the form sent ($typed: id, form, qty or
     * note, and the list's store, why, q and page).
     *
     * @param array<string, mixed>|null $typed
     */
    public function index(Context $ctx, int $status = 200, ?CwException $error = null, ?array $typed = null): HtmlResponse
    {
        $svc = new StockDoubt($ctx->db);
        $src = $typed ?? $ctx->req->query;
        $stores = $svc->stores();
        $store = self::store($stores, $src['store'] ?? null, 'open');
        $why = is_string($src['why'] ?? null) && in_array($src['why'], StockDoubt::REASONS, true) ? $src['why'] : null;
        $q = is_string($src['q'] ?? null) ? mb_substr(trim($src['q']), 0, 100) : '';
        $page = UiRequest::id(is_string($src['page'] ?? null) ? $src['page'] : null) ?? 1;
        $list = $store === null ? ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1] : $svc->open($store['id'], $why, $q, $page);
        $canCount = Documents::mayPost($ctx->me()->roles, CountSheets::TYPE);
        // What every answer and every page link sends back, so the list comes back as it was: the store shown (also when it was not asked
        // for: the default may change once its last row is answered; Codex round 1), why, the search and the page.
        $back = ['store' => $store['code'] ?? null, 'why' => $why, 'q' => $q === '' ? null : $q, 'page' => $list['page'] > 1 ? (string) $list['page'] : null];
        $typedId = isset($typed['id']) ? (int) $typed['id'] : null;
        $onPage = false;
        $rows = [];
        foreach ($list['rows'] as $r) {
            $mine = $typedId === $r['id'];
            $onPage = $onPage || $mine;
            $rows[] = $r + [
                'why' => Words::of('DOUBT_WHY', $r['reason']),
                'tone' => Words::tone('DOUBT_WHY', $r['reason']),
                'count_action' => self::PATH . '/' . $r['id'] . '/count',
                'dismiss_action' => self::PATH . '/' . $r['id'] . '/dismiss',
                'typed_qty' => $mine && ($typed['form'] ?? '') === 'count' ? (string) ($typed['qty'] ?? '') : '',
                'typed_note' => $mine && ($typed['form'] ?? '') === 'dismiss' ? (string) ($typed['note'] ?? '') : '',
                'count_error' => $mine && $error !== null && ($typed['form'] ?? '') === 'count' ? self::plain($error) : null,
                'dismiss_error' => $mine && $error !== null && ($typed['form'] ?? '') === 'dismiss' ? self::plain($error) : null,
                'error_code' => $mine ? $error?->errorCode : null,
            ];
        }
        $narrowed = $why !== null || $q !== '';
        $first = ($list['page'] - 1) * StockDoubt::PAGE;
        return $ctx->page('stock_doubt', [
            'rows' => $rows,
            'stores' => array_values(array_filter($stores, static fn (array $s): bool => $s['rows'] > 0 || $s['id'] === ($store['id'] ?? null))),
            'store' => $store,
            'storeCode' => $back['store'],
            'why' => $why,
            'whys' => StockDoubt::REASONS,
            'q' => $q,
            'back' => array_filter($back, static fn (?string $v): bool => $v !== null),
            'narrowed' => $narrowed,
            'total' => $list['total'],
            'shown' => $list['total'] > StockDoubt::PAGE ? Words::say('DOUBT', 'shown', $first + 1, $first + count($rows), $list['total']) : null,
            'prev' => $list['page'] > 1 ? Html::url(self::PATH, ['page' => (string) ($list['page'] - 1)] + $back) : null,
            'next' => $list['page'] < $list['pages'] ? Html::url(self::PATH, ['page' => (string) ($list['page'] + 1)] + $back) : null,
            'canCount' => $canCount,
            'lookOnly' => $canCount ? null : Words::whoCan('doc.' . CountSheets::TYPE . '.post'),
            'noteMin' => \CW\Queues\QueueRules::NOTE_MIN,
            'noteMax' => \CW\Queues\QueueRules::NOTE_MAX,
            // A refusal about a row this page does not show (answered meanwhile, not there): its words at the top.
            'error' => $error !== null && !$onPage ? self::plain($error) : null,
            'errorCode' => $error !== null && !$onPage ? $error->errorCode : null,
        ], $status, ['title' => Words::title('doubtful'), 'notice' => $error === null ? self::notice($ctx, $svc) : null]);
    }

    /** GET checked: the rows answered, newest first. */
    public function checked(Context $ctx): HtmlResponse
    {
        $svc = new StockDoubt($ctx->db);
        $stores = $svc->stores();
        // The same store as the list's when none is asked for (Codex round 1: Checked of another store than the list showed).
        $store = self::store($stores, $ctx->req->param('store'), 'open');
        $page = UiRequest::id($ctx->req->param('page')) ?? 1;
        $list = $store === null ? ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1] : $svc->checked($store['id'], $page);
        $keep = ['store' => $store['code'] ?? null];
        $rows = [];
        foreach ($list['rows'] as $r) {
            $rows[] = $r + [
                'state' => Words::of('DOUBT_STATE', $r['status']),
                'tone' => Words::tone('DOUBT_STATE', $r['status']),
                'booked' => $r['booked_delta'] === null ? '' : self::signed($r['booked_delta']),
                'record' => $r['number'] === null ? null : Words::say('DOUBT', 'record', (string) $r['number']),
                'record_href' => $r['count_document_id'] === null ? null : CountsController::PATH . '/' . $r['count_document_id'],
            ];
        }
        $first = ($list['page'] - 1) * StockDoubt::PAGE;
        return $ctx->page('stock_doubt_checked', [
            'rows' => $rows,
            'stores' => array_values(array_filter($stores, static fn (array $s): bool => $s['rows'] > 0 || $s['id'] === ($store['id'] ?? null))),
            'store' => $store,
            'shown' => $list['total'] > StockDoubt::PAGE ? Words::say('DOUBT', 'shown', $first + 1, $first + count($rows), $list['total']) : null,
            'prev' => $list['page'] > 1 ? Html::url(self::PATH . '/checked', ['page' => (string) ($list['page'] - 1)] + $keep) : null,
            'next' => $list['page'] < $list['pages'] ? Html::url(self::PATH . '/checked', ['page' => (string) ($list['page'] + 1)] + $keep) : null,
        ], 200, ['title' => Words::title('doubtful')]);
    }

    /**
     * POST {id}/count: `qty`, what is on the shelf. A number of 9 digits or more, or one that is a barcode CW knows (the count sheet's own
     * guard, review M-5), is refused as a barcode; anything but a whole number from 0 to the most a count takes is refused; nothing is booked.
     */
    public function count(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $typed = (string) ($ctx->req->field('qty') ?? '');
        $back = self::backOf($ctx->req);
        try {
            $v = trim($typed);
            if (preg_match('/^[0-9]{9,}$/D', $v) === 1 || CountsController::barcodeIn($ctx->db, [1 => $v]) !== null) {
                throw new CwException('barcode_in_quantity', 'a barcode was typed into the box', 422, ['field' => 'qty']);
            }
            $qty = StockDoubt::quantity($typed);
            (new StockDoubt($ctx->db, $ctx->countSheets()))->count($ctx->caller(), $id, $qty);
        } catch (CwException $e) {
            return $this->index($ctx, $e->httpStatus, $e, ['id' => $id, 'form' => 'count', 'qty' => mb_substr($typed, 0, 20)] + $back);
        }
        return HtmlResponse::redirect(Html::url(self::PATH, $back + ['notice' => 'counted', 'row' => (string) $id]));
    }

    /** POST {id}/dismiss: "Not needed", with `note`. */
    public function dismiss(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $note = (string) ($ctx->req->field('note') ?? '');
        $back = self::backOf($ctx->req);
        try {
            (new StockDoubt($ctx->db))->dismiss($ctx->caller(), $id, $note);
        } catch (CwException $e) {
            return $this->index($ctx, $e->httpStatus, $e, ['id' => $id, 'form' => 'dismiss', 'note' => mb_substr($note, 0, 600)] + $back);
        }
        return HtmlResponse::redirect(Html::url(self::PATH, $back + ['notice' => 'dismissed', 'row' => (string) $id]));
    }

    /** A refusal in the page's words (DOUBT_ERROR by code; the count sheets' and the general ones otherwise). */
    public static function plain(CwException $e): string
    {
        return Words::DOUBT_ERROR[$e->errorCode] ?? CountsController::plain($e);
    }

    /** +2, -3, 0. */
    private static function signed(int $n): string
    {
        return ($n > 0 ? '+' : ($n < 0 ? '-' : '')) . number_format(abs($n));
    }

    /** The line after an answer: what was counted and booked, or "marked not needed" (notice=counted|dismissed, row=<id>). */
    private static function notice(Context $ctx, StockDoubt $svc): ?string
    {
        $n = $ctx->req->param('notice');
        $id = UiRequest::id($ctx->req->param('row'));
        $a = $id === null || !in_array($n, ['counted', 'dismissed'], true) ? null : $svc->answered($id);
        if ($a === null) {
            return null;
        }
        if ($a['status'] === 'dismissed') {
            return Words::say('DOUBT_NOTICE', 'dismissed', $a['code']);
        }
        if ($a['delta'] === 0 || $a['delta'] === null) {
            return Words::say('DOUBT_NOTICE', 'counted_same', (int) $a['counted'], $a['code']);
        }
        return Words::say('DOUBT_NOTICE', 'counted', (int) $a['counted'], $a['code'], (int) $a['had'], self::signed($a['delta']));
    }

    /**
     * The store a page shows: the one asked for (its code), else the first that has rows of $kind ('open' rows, or any 'rows'), else the
     * first that has any, else the first store; null with no store at all.
     *
     * @param list<array{id: int, code: string, name: string, open: int, rows: int}> $stores
     * @return array{id: int, code: string, name: string, open: int, rows: int}|null
     */
    private static function store(array $stores, mixed $asked, string $kind): ?array
    {
        foreach ($stores as $s) {
            if (is_string($asked) && $s['code'] === $asked) {
                return $s;
            }
        }
        foreach ([$kind, 'rows'] as $k) {
            foreach ($stores as $s) {
                if ($s[$k] > 0) {
                    return $s;
                }
            }
        }
        return $stores[0] ?? null;
    }

    /** @return array<string, string> the list's store, why, search and page an answer carries back */
    private static function backOf(UiRequest $req): array
    {
        $out = [];
        foreach (['store' => '/^[a-z][a-z0-9_]{0,31}$/D', 'why' => '/^[a-z_]{1,20}$/D', 'page' => '/^[1-9][0-9]{0,5}$/D'] as $k => $re) {
            $v = $req->field($k);
            if ($v !== null && preg_match($re, $v) === 1) {
                $out[$k] = $v;
            }
        }
        $q = mb_substr(trim((string) ($req->field('q') ?? '')), 0, 100);
        if ($q !== '') {
            $out['q'] = $q;
        }
        return $out;
    }
}
