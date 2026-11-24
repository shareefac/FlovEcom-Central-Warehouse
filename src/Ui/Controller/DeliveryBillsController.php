<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Profit\DeliveryBills;
use CW\Profit\DeliveryViews;
use CW\Profit\DpdInvoiceFile;
use CW\Profit\RoyalMailBills;
use CW\Profit\RoyalMailLook;
use CW\Profit\RoyalMailViews;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Profit > Delivery bills (delivery cost in profit, step 2 part 1; docs/decisions.md DL23): the carriers' bills CW read (DPD in this
 * version), newest upload first, with the upload; one bill's page with its tie-out ("on orders + collections + before CW start + not
 * matched + to check = the bill's net"), its lines by state (the lines on no order the dearest first), "Replace with a corrected file" and
 * "Undo this upload". Finance and the Delivery bills job look (delivery.view); only the Delivery bills job uploads, replaces and undoes
 * (delivery.upload; the service checks it again). The file is read and dropped: CW keeps its fingerprint, never the file (it holds
 * customer addresses).
 */
final class DeliveryBillsController
{
    public const PATH = '/ui/profit/delivery';
    public const PERM_VIEW = DeliveryBills::PERM_VIEW;
    public const PERM_UPLOAD = DeliveryBills::PERM_UPLOAD;
    /** The notices a redirect may carry back. */
    private const NOTICES = ['stored', 'same', 'replaced', 'undone', 'stored_later', 'replaced_later'];
    /** The carriers the upload offers: DPD's CSV and Royal Mail's bill page (0078: DL69). */
    public const UPLOAD_CARRIERS = ['dpd', 'royal_mail'];
    /** The most pasted text of a Royal Mail bill read (a week's page as text is about 30 KB). */
    public const MAX_TEXT_BYTES = 2_097_152;

    /** GET: the bills, and the upload for the people who may upload. */
    public function list(Context $ctx): HtmlResponse
    {
        return $this->listPage($ctx, 200, null, []);
    }

    /** POST upload: a bill read from its file and stored (DeliveryBills::upload); refused whole, nothing stored, with the reason in words. */
    public function upload(Context $ctx): HtmlResponse
    {
        $carrier = (string) ($ctx->req->field('carrier') ?? '');
        $shop = UiRequest::id($ctx->req->field('shop'));
        $account = UiRequest::id($ctx->req->field('account'));
        try {
            if ($carrier === 'royal_mail') {
                // Royal Mail's bill: its saved page, or its text pasted in the box (DL52); the account it is of chosen (its printed number must agree).
                // Checked first, nothing stored: the check page, then its Store (DL79).
                if ($account === null) {
                    throw new CwException('no_account', Words::DELIVERY['rm_no_account'], 422, ['field' => 'account']);
                }
                return $this->checkPage($ctx, (new RoyalMailBills($ctx->db))->check($ctx->caller(), $account, self::pageOrText($ctx)));
            } else {
                $file = InvoicesController::upload($ctx);
                $bytes = (string) file_get_contents($file['path']);
                $r = (new DeliveryBills($ctx->db))->upload($ctx->caller(), $carrier, $bytes, $shop);
            }
        } catch (CwException $e) {
            return $this->listPage($ctx, $e->httpStatus, $e, ['carrier' => $carrier, 'shop' => $shop, 'account' => $account]);
        }
        // A re-match of the other bills that could not run is said (the Codex check r2, MEDIUM 4): the bill is stored all the same.
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $r['invoice_id'], ['notice' => $r['result'] === 'same' ? 'same'
            : ($r['rematch_error'] !== null ? 'stored_later' : 'stored')]));
    }

    /** GET {id}: one bill, its tie-out and its lines of a filter (?show=other|matched|collection|before_start|all, ?page=n). */
    public function bill(Context $ctx): HtmlResponse
    {
        $n = (string) ($ctx->req->param('notice') ?? '');
        return $this->billPage($ctx, $ctx->id(), 200, null, in_array($n, self::NOTICES, true) ? $n : null);
    }

    /** POST {id}/replace: a corrected file of this live bill (DeliveryBills::replace; a Royal Mail bill: RoyalMailBills::replace, page or text). */
    public function replace(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        try {
            if (self::pooled($ctx, $id)) {
                // a Royal Mail bill's corrected file: checked first, then its Store (DL79)
                return $this->checkPage($ctx, (new RoyalMailBills($ctx->db))->check($ctx->caller(), null, self::pageOrText($ctx), $id));
            } else {
                $file = InvoicesController::upload($ctx);
                $r = (new DeliveryBills($ctx->db))->replace($ctx->caller(), $id, (string) file_get_contents($file['path']));
            }
        } catch (CwException $e) {
            return $this->billPage($ctx, $id, $e->httpStatus, $e, null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $r['invoice_id'], ['notice' => $r['result'] === 'same' ? 'same'
            : ($r['rematch_error'] !== null ? 'replaced_later' : 'replaced')]));
    }

    /**
     * POST store: a Royal Mail bill checked on this screen stored (RoyalMailBills::storeChecked, DL79): the bill its check READ, by who checked it,
     * once, within its time; a check that is not open stores nothing and says so.
     */
    public function store(Context $ctx): HtmlResponse
    {
        $token = (string) ($ctx->req->field('check') ?? '');
        $sha = (string) ($ctx->req->field('bill_sha256') ?? '');
        try {
            $r = (new RoyalMailBills($ctx->db))->storeChecked($ctx->caller(), $token, $sha);
        } catch (CwException $e) {
            // busy (the bills' lock or an order held elsewhere): nothing stored, the check given back; its Store offered again (the Codex check r4,
            // D3 LOW 5). Any other refusal: the list with the reason.
            $retry = null;
            $said = null;
            if ($e->errorCode === 'busy' && preg_match('/^[0-9a-f]{64}$/D', $sha) === 1) {
                // only a check still open is offered again, with the minutes it has left (the Codex check r5, LOW 10)
                $left = (new RoyalMailBills($ctx->db))->checkSecondsLeft($ctx->caller(), $token);
                $retry = $left > 0 ? ['check' => $token, 'bill_sha256' => $sha, 'minutes' => max(1, intdiv($left, 60))] : null;
                $said = $left > 0 ? null : Words::DELIVERY['rm_check_retry_late'];
            }
            return $this->listPage($ctx, $e->httpStatus, $e, ['carrier' => 'royal_mail'], $said, $retry);
        }
        // the mode comes with the result: the bill is not read again (the Codex check r4, r3 8 PARTLY)
        $notice = $r['result'] === 'same' ? 'same' : ($r['mode'] === 'replace' ? 'replaced' : 'stored') . ($r['rematch_error'] !== null ? '_later' : '');
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $r['invoice_id'], ['notice' => $notice]));
    }

    /**
     * A Royal Mail bill checked, nothing stored (DL79; the plan, section 6 step 3): what CW read (its number, account, date, days, that its lines add
     * up to its totals, its rows and totals), where its money would go, the bill it replaces, and the Store button (or "in the profit already").
     *
     * @param array<string, mixed> $c RoyalMailBills::check()
     */
    private function checkPage(Context $ctx, array $c): HtmlResponse
    {
        $p = $c['preview'];
        $views = new RoyalMailViews($ctx->db);
        return $ctx->page('profit_delivery_rm_check', [
            'check' => $c,
            'bill' => $p,
            'where' => $views->checkWhere($p['where']),
            'others' => $views->checkOthers($p['others']),
            'gone' => $views->checkGone($p['gone'] ?? []),
            'days' => $p['days'],
            'waitingDays' => $p['waiting'] ?? [],
            'look' => $p['look'] ?? [],
            'lookPct' => RoyalMailLook::pctText(RoyalMailLook::alarmBp($ctx->db)),
            'rows' => $p['bill_rows'],
            'minutes' => RoyalMailBills::CHECK_MINUTES,
        ], 200, ['title' => Words::say('DELIVERY', 'rm_check_title', (string) $p['invoice_no'])]);
    }

    /** POST {id}/undo: "Undo this upload" (DeliveryBills::undo), only with its box ticked. */
    public function undo(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        try {
            if ($ctx->req->field('sure') !== '1') {
                throw new CwException('not_sure', Words::DELIVERY['undo_tick'], 422, ['field' => 'sure']);
            }
            if (self::pooled($ctx, $id)) {
                (new RoyalMailBills($ctx->db))->undo($ctx->caller(), $id);
            } else {
                (new DeliveryBills($ctx->db))->undo($ctx->caller(), $id);
            }
        } catch (CwException $e) {
            return $this->billPage($ctx, $id, $e->httpStatus, $e, null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => 'undone']));
    }

    /**
     * POST labels: Click & Drop's "Manifested Orders" report of an account with the From and To days it was run for (RoyalMailBills::importReport,
     * DL48, DL84): its labels read (never a name, address or the file), those days covered, the bills' days planned again; the page says how many.
     */
    public function labels(Context $ctx): HtmlResponse
    {
        $account = UiRequest::id($ctx->req->field('account'));
        // the days the report was run for in Click & Drop (DL84): they are what the import covers
        $from = substr(trim((string) ($ctx->req->field('from') ?? '')), 0, 10);
        $to = substr(trim((string) ($ctx->req->field('to') ?? '')), 0, 10);
        try {
            if ($account === null) {
                throw new CwException('no_account', Words::DELIVERY['rm_no_account'], 422, ['field' => 'account']);
            }
            $file = InvoicesController::upload($ctx);
            $r = (new RoyalMailBills($ctx->db))->importReport($ctx->caller(), $account, (string) file_get_contents($file['path']), $from, $to);
        } catch (CwException $e) {
            return $this->listPage($ctx, $e->httpStatus, $e, ['account' => $account, 'carrier' => 'royal_mail', 'from' => $from, 'to' => $to]);
        }
        if ($r['already'] ?? false) {
            // the same report for the same days again (DL86 10): nothing written
            return $this->listPage($ctx, 200, null, [], Words::say('DELIVERY', 'rm_labels_already', $from, $to)
                . ($r['replan_error'] !== null ? ' ' . $r['replan_error'] : ''));
        }
        $said = Words::say('DELIVERY', 'rm_labels_read', number_format($r['labels']), number_format($r['added']), number_format($r['filled']), number_format($r['skipped']),
            $from, $to)
            . ($r['differs'] > 0 ? ' ' . Words::say('DELIVERY', 'rm_labels_differ', number_format($r['differs'])) : '')
            . ($r['replan_error'] !== null ? ' ' . $r['replan_error'] : '');
        return $this->listPage($ctx, 200, null, [], $said);
    }

    /** Whether a bill is a pooled carrier's (Royal Mail): its own service and page. */
    private static function pooled(Context $ctx, int $id): bool
    {
        return $ctx->db->value("SELECT 1 FROM carrier_invoice i JOIN carrier c ON c.code = i.carrier AND c.family = 'pooled' WHERE i.id = ?", [$id]) !== null;
    }

    /** A Royal Mail bill's bytes: the text pasted in the box when there is some, else the saved page's file. */
    private static function pageOrText(Context $ctx): string
    {
        $text = (string) ($ctx->req->field('text') ?? '');
        if (trim($text) !== '') {
            if (strlen($text) > self::MAX_TEXT_BYTES) {
                throw new CwException('too_large', Words::DELIVERY['rm_text_too_long'], 413, ['field' => 'text']);
            }
            return $text;
        }
        $file = InvoicesController::upload($ctx);
        return (string) file_get_contents($file['path']);
    }

    /** @param array<string, mixed> $typed what the refused form said (carrier, shop, account) */
    /** @param array{check: string, bill_sha256: string, minutes: int}|null $retry a busy Store's check, still open, offered again */
    private function listPage(Context $ctx, int $status, ?CwException $error, array $typed, ?string $said = null, ?array $retry = null): HtmlResponse
    {
        $bills = array_map(static fn (array $b): array => $b + ['href' => self::PATH . '/' . (int) $b['id']], (new DeliveryViews($ctx->db))->bills());
        $stores = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $ctx->db->all('SELECT id, name FROM channel ORDER BY id'));
        $accounts = (new RoyalMailViews($ctx->db))->accounts();
        return $ctx->page('profit_delivery', [
            'bills' => $bills,
            'canUpload' => $ctx->me()->can(self::PERM_UPLOAD),
            'carriers' => self::UPLOAD_CARRIERS,
            'stores' => $stores,
            'accounts' => $accounts,
            'typed' => $typed,
            'error' => $error?->getMessage(),
            'said' => $said,
            'retry' => $retry,
            'maxMiB' => intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576),
            'limit' => DeliveryViews::LIST_LIMIT,
        ], $status, ['title' => Words::MENU['profit_delivery']]);
    }

    private function billPage(Context $ctx, int $id, int $status, ?CwException $error, ?string $notice): HtmlResponse
    {
        if (self::pooled($ctx, $id)) {
            return $this->rmPage($ctx, $id, $status, $error, $notice);
        }
        $views = new DeliveryViews($ctx->db);
        $b = $views->bill($id);
        if ($b === null) {
            return $ctx->error(404, 'not_found', 'there is no such bill', [self::PATH, Words::MENU['profit_delivery']]);
        }
        $show = (string) ($ctx->req->param('show') ?? 'other');
        $show = isset(DeliveryViews::FILTERS[$show]) ? $show : 'other';
        $page = UiRequest::id($ctx->req->param('page')) ?? 1;
        $lines = $views->lines($id, $show, $page);
        $bill = $b['bill'];
        $canOrders = $ctx->me()->can(ProfitController::PERM);
        $lines['rows'] = array_map(static fn (array $l): array => $l + ['order_href' => $canOrders && $l['order_ref'] !== null
            ? Html::url(ProfitController::PATH . '/orders/view', ['store' => (int) $l['channel_id'], 'ref' => (string) $l['order_ref']]) : null], $lines['rows']);
        $filters = [];
        foreach (array_keys(DeliveryViews::FILTERS) as $f) {
            $filters[] = ['key' => $f, 'href' => Html::url(self::PATH . '/' . $id, ['show' => $f]), 'current' => $f === $show];
        }
        $title = Words::say('DELIVERY', 'bill_title', strtoupper((string) $bill['carrier']), (string) $bill['invoice_no']);
        return $ctx->page('profit_delivery_bill', $b + [
            'lines' => $lines,
            'show' => $show,
            'filters' => $filters,
            'canUpload' => $ctx->me()->can(self::PERM_UPLOAD) && $bill['status'] === 'live',
            'error' => $error?->getMessage(),
            'page_href' => static fn (int $p): string => Html::url(self::PATH . '/' . $id, ['show' => $show, 'page' => $p]),
            'headline' => self::headline($b),
            'maxMiB' => intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576),
        ], $status, ['title' => $title, 'notice' => $notice === null ? null : Words::DELIVERY['notice_' . $notice]]);
    }

    /** A Royal Mail bill's page (DL69): where its money went, the day tie-out, its manifests, its rows, Replace and Undo (page or text). */
    private function rmPage(Context $ctx, int $id, int $status, ?CwException $error, ?string $notice): HtmlResponse
    {
        $b = (new RoyalMailViews($ctx->db))->bill($id);
        if ($b === null) {
            return $ctx->error(404, 'not_found', 'there is no such bill', [self::PATH, Words::MENU['profit_delivery']]);
        }
        $bill = $b['bill'];
        return $ctx->page('profit_delivery_rm', $b + [
            'lookPct' => RoyalMailLook::pctText(RoyalMailLook::alarmBp($ctx->db)),
            'canUpload' => $ctx->me()->can(self::PERM_UPLOAD) && $bill['status'] === 'live',
            'error' => $error?->getMessage(),
            'headline' => self::rmHeadline($b),
            'maxMiB' => intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576),
        ], $status, ['title' => Words::say('DELIVERY', 'bill_title', Words::DELIVERY['carrier_royal_mail'], (string) $bill['invoice_no']),
            'notice' => $notice === null ? null : Words::DELIVERY['notice_' . $notice]]);
    }

    /**
     * A Royal Mail bill in one sentence (the plan's words, section 6): "Royal Mail bill 9077300608 (4 Nov - 12 Nov 2026): £18,859.74 - on orders
     * £18,3xx, not on an order £n, in no shop's profit £z (Hayati Vapes, held), before CW start £s".
     *
     * @param array<string, mixed> $b RoyalMailViews::bill()
     */
    public static function rmHeadline(array $b): string
    {
        $bill = $b['bill'];
        $from = new \DateTimeImmutable((string) $bill['period_from']);
        $to = new \DateTimeImmutable((string) $bill['period_to']);
        $days = $from->format('Y') === $to->format('Y') ? $from->format('j M') . ' - ' . $to->format('j M Y') : $from->format('j M Y') . ' - ' . $to->format('j M Y');
        $t = $b['totals'];
        return Words::say('DELIVERY', 'rm_headline', (string) $bill['invoice_no'], $days, DpdInvoiceFile::gbp((int) $bill['net_pence']), DpdInvoiceFile::gbp($t['order']),
            DpdInvoiceFile::gbp($t['shop'] + $t['day_line']), DpdInvoiceFile::gbp($t['party'] + $t['held']), DpdInvoiceFile::gbp($t['before_start']));
    }

    /**
     * The bill in one sentence (the lead's words, decision 8): "DPD bill 62368793 (5 Nov - 14 Nov 2026): £8,790.28 - on orders £8,638.29 (2,364 lines),
     * not on an order £151.99 (15 lines)", with "before CW start £x (n lines)" when it has some.
     *
     * @param array<string, mixed> $b DeliveryViews::bill()
     */
    public static function headline(array $b): string
    {
        $bill = $b['bill'];
        $from = new \DateTimeImmutable((string) $bill['period_from']);
        $to = new \DateTimeImmutable((string) $bill['period_to']);
        $days = $from->format('Y') === $to->format('Y') ? $from->format('j M') . ' - ' . $to->format('j M Y') : $from->format('j M Y') . ' - ' . $to->format('j M Y');
        $text = Words::say('DELIVERY', 'headline', strtoupper((string) $bill['carrier']), (string) $bill['invoice_no'], $days, DpdInvoiceFile::gbp((int) $bill['net_pence']),
            DpdInvoiceFile::gbp($b['on_orders']['pence']), number_format($b['on_orders']['lines']), DpdInvoiceFile::gbp($b['other']['pence']),
            number_format($b['other']['lines']));
        if ($b['states']['before_start']['lines'] > 0) {
            $text .= Words::say('DELIVERY', 'headline_before', DpdInvoiceFile::gbp($b['states']['before_start']['pence']),
                number_format($b['states']['before_start']['lines']));
        }
        return $text;
    }
}
