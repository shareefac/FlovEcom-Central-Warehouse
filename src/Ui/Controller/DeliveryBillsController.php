<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Profit\DeliveryBills;
use CW\Profit\DeliveryViews;
use CW\Profit\DpdInvoiceFile;
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
        try {
            $file = InvoicesController::upload($ctx);
            $bytes = (string) file_get_contents($file['path']);
            $r = (new DeliveryBills($ctx->db))->upload($ctx->caller(), $carrier, $bytes, $shop);
        } catch (CwException $e) {
            return $this->listPage($ctx, $e->httpStatus, $e, ['carrier' => $carrier, 'shop' => $shop]);
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

    /** POST {id}/replace: a corrected file of this live bill (DeliveryBills::replace). */
    public function replace(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        try {
            $file = InvoicesController::upload($ctx);
            $r = (new DeliveryBills($ctx->db))->replace($ctx->caller(), $id, (string) file_get_contents($file['path']));
        } catch (CwException $e) {
            return $this->billPage($ctx, $id, $e->httpStatus, $e, null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $r['invoice_id'], ['notice' => $r['result'] === 'same' ? 'same'
            : ($r['rematch_error'] !== null ? 'replaced_later' : 'replaced')]));
    }

    /** POST {id}/undo: "Undo this upload" (DeliveryBills::undo), only with its box ticked. */
    public function undo(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        try {
            if ($ctx->req->field('sure') !== '1') {
                throw new CwException('not_sure', Words::DELIVERY['undo_tick'], 422, ['field' => 'sure']);
            }
            (new DeliveryBills($ctx->db))->undo($ctx->caller(), $id);
        } catch (CwException $e) {
            return $this->billPage($ctx, $id, $e->httpStatus, $e, null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => 'undone']));
    }

    /** @param array<string, mixed> $typed what the refused form said (carrier, shop) */
    private function listPage(Context $ctx, int $status, ?CwException $error, array $typed): HtmlResponse
    {
        $bills = array_map(static fn (array $b): array => $b + ['href' => self::PATH . '/' . (int) $b['id']], (new DeliveryViews($ctx->db))->bills());
        $stores = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $ctx->db->all('SELECT id, name FROM channel ORDER BY id'));
        return $ctx->page('profit_delivery', [
            'bills' => $bills,
            'canUpload' => $ctx->me()->can(self::PERM_UPLOAD),
            'carriers' => DeliveryBills::CARRIERS,
            'stores' => $stores,
            'typed' => $typed,
            'error' => $error?->getMessage(),
            'maxMiB' => intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576),
            'limit' => DeliveryViews::LIST_LIMIT,
        ], $status, ['title' => Words::MENU['profit_delivery']]);
    }

    private function billPage(Context $ctx, int $id, int $status, ?CwException $error, ?string $notice): HtmlResponse
    {
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
