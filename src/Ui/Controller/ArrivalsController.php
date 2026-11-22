<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Arrivals\Arrivals;
use CW\Arrivals\UkTime;
use CW\CwException;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Invoices\SupplierInvoices;
use CW\Invoices\SupplierReturns;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Router;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Pack J, the owner's day-one purchasing workflow (docs/decisions.md PJ14): the screens around goods on their way.
 *
 *  Goods In › Arriving        every final invoice whose goods are on their way ("count on arrival"), per supplier and invoice, the units,
 *                             what the websites sell of them, how long since it was made final (late after arrivals.late_hours), and
 *                             "Receive": the receipt of everything still on its way, started from the invoice (receiving.view looks;
 *                             doc.GRN.post receives).
 *  A receipt's "Claim the missing units"  (doc.DN.post) the claim of what a receipt made from an invoice did not bring.
 *  Purchasing › Invoices › Timed  the draft invoices waiting for their time, those whose attempt failed first (invoices.view).
 *  A supplier's "Goods on their way"  (suppliers.manage) sell before it arrives, and how its invoices come in.
 *
 * Every service checks the person again; every POST carries the CSRF token (the kernel checks it).
 */
final class ArrivalsController
{
    public const ARRIVING = '/ui/receiving/arriving';
    /** Arriving's board (docs/decisions.md W9.8): its groups in their order, their colours and words. */
    private const BOARD = ['late' => ['needs', Words::ARRIVALS['group_late']], 'receiving' => ['info', Words::ARRIVALS['group_receiving']],
        'on_way' => ['waiting', Words::ARRIVALS['group_on_way']]];
    public const TIMED = '/ui/purchasing/invoices/timed';

    public static function routes(Router $r): void
    {
        $c = new self();
        $r->add('GET', self::ARRIVING, 'receiving.view', $c->arriving(...));
        $r->add('POST', self::ARRIVING . '/{id}/receive', 'doc.GRN.post', $c->receive(...));
        $r->add('POST', '/ui/receiving/{id}/claim', 'doc.DN.post', $c->claim(...));
        $r->add('GET', self::TIMED, 'invoices.view', $c->timed(...));
        $r->add('POST', '/ui/purchasing/suppliers/{id}/arrivals', 'suppliers.manage', $c->supplier(...));
    }

    public function arriving(Context $ctx, int $status = 200, ?CwException $error = null): HtmlResponse
    {
        $rows = array_map(static fn (array $r): array => $r + ['href' => SupplierInvoices::PATHS['invoice'] . '/' . $r['invoice_id'],
            'receipt_href' => $r['receipt_id'] === null ? null : '/ui/receiving/' . $r['receipt_id'],
            // The board (docs/decisions.md W9.8): late, being received (its receipt started), on the way.
            'group' => $r['receipt_id'] !== null ? 'receiving' : ($r['late'] ? 'late' : 'on_way')], self::service($ctx)->arriving(UiRequest::id($ctx->req->param('supplier'))));
        return $ctx->page('arrivals', [
            'rows' => $rows,
            'groups' => Html::groups($rows, self::BOARD),
            'limit' => Arrivals::ARRIVING_LIMIT,
            'canReceive' => Documents::mayPost($ctx->me()->roles, 'GRN'),
            'error' => $error === null ? null : InvoicesController::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::SEGMENT['arriving']]);
    }

    /** POST arriving/{id}/receive: the receipt of what is on its way on invoice {id}; the person lands on it. */
    public function receive(Context $ctx): HtmlResponse
    {
        try {
            $grn = self::service($ctx)->receive($ctx->caller(), $ctx->id());
        } catch (CwException $e) {
            return $this->arriving($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url('/ui/receiving/' . $grn->id, ['notice' => 'from_invoice']));
    }

    /** POST /ui/receiving/{id}/claim: the claim of what receipt {id} did not bring; the person lands on the draft return. */
    public function claim(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        try {
            $dn = self::service($ctx)->claimMissing($ctx->caller(), $id);
        } catch (CwException $e) {
            return (new ReceivingController())->page($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(SupplierReturns::PATH . '/' . $dn->id, ['notice' => 'claim_started']));
    }

    public function timed(Context $ctx): HtmlResponse
    {
        $rows = array_map(static fn (array $r): array => $r + ['href' => SupplierInvoices::PATHS['invoice'] . '/' . (int) $r['id'],
            'at' => self::when((string) $r['submit_at']), 'failed' => $r['submit_failed_at'] !== null, 'group' => $r['submit_failed_at'] !== null ? 'failed' : 'waiting'],
            $ctx->supplierInvoices()->scheduled());
        return $ctx->page('invoices_timed', [
            'rows' => $rows,
            // The board (docs/decisions.md W9.8): the ones that could not be made final first, then the ones waiting for their time.
            'groups' => Html::groups($rows, ['failed' => ['blocked', Words::ARRIVALS['could_not']], 'waiting' => ['waiting', Words::ARRIVALS['waiting']]]),
            'limit' => SupplierInvoices::LIST_LIMIT,
        ], 200, ['title' => Words::SEGMENT['timed']]);
    }

    /** POST suppliers/{id}/arrivals: the supplier's own arrival choices. */
    public function supplier(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $sell = $ctx->req->field('sell_before') === '1';
        $mode = (string) ($ctx->req->field('default_mode') ?? '');
        try {
            self::service($ctx)->setPrefs($ctx->caller(), $id, $sell, $mode);
        } catch (CwException $e) {
            if ($e->errorCode === 'supplier_pending') {
                return (new SuppliersController())->card($ctx, $id, 409, new CwException('supplier_pending', Words::BUY_ERROR['supplier_pending'], 409));
            }
            return HtmlResponse::redirect(Html::url('/ui/purchasing/suppliers/' . $id, ['notice' => 'arrivals_refused', 'why' => $e->errorCode]) . '#arrivals');
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/suppliers/' . $id, ['notice' => 'arrivals_saved']) . '#arrivals');
    }

    /**
     * The supplier page's box (SuppliersController::show): its choices and whether this person may change them.
     *
     * @return array{sell_before: bool, mode: string, own: bool, canEdit: bool}
     */
    public static function supplierBox(Context $ctx, int $supplierId, string $status = 'active'): array
    {
        // Not changed while the supplier waits for a reviewer's OK (as its other details).
        return self::service($ctx)->prefsOf($supplierId) + ['canEdit' => $ctx->me()->can('suppliers.manage') && $status !== 'pending_approval'];
    }

    /**
     * The receipt page's box (ReceivingController): the invoice a receipt was made from, what it did not bring, and the claim.
     *
     * @return array{invoice: array{id: int, number: string}, missing: list<array<string, mixed>>, claim: ?int, canClaim: bool}|null
     */
    public static function receiptBox(Context $ctx, int $grnId, string $status): ?array
    {
        $inv = $ctx->db->one('SELECT d.id, d.number, d.external_ref FROM goods_receipt_invoice g JOIN document d ON d.id = g.sinv_document_id WHERE g.grn_document_id = ?',
            [$grnId]);
        if ($inv === null) {
            return null;
        }
        $missing = $status === 'posted' ? self::service($ctx)->missingOf($grnId) : [];
        $claim = $ctx->db->value("SELECT MIN(r.document_id) FROM supplier_return r JOIN document d ON d.id = r.document_id AND d.status IN ('draft', 'awaiting_approval', 'posted') "
            . 'AND d.reverses_id IS NULL WHERE r.grn_document_id = ? AND NOT EXISTS (SELECT 1 FROM document_line l WHERE l.document_id = r.document_id AND l.sku_id IS NOT NULL)',
            [$grnId]);
        return ['invoice' => ['id' => (int) $inv['id'], 'number' => (string) ($inv['number'] ?? $inv['external_ref'] ?? ('#' . $inv['id']))],
            'missing' => array_map(static fn (array $m): array => $m + ['amount_text' => Html::money($m['amount'])], $missing),
            'claim' => $claim === null ? null : (int) $claim, 'canClaim' => $missing !== [] && Documents::mayPost($ctx->me()->roles, 'DN')];
    }

    /**
     * The invoice page's boxes (InvoicesController): how its goods come in (a draft's form: the way, and the duty stamp of each line
     * "book as received" takes), when it becomes final (its time, who set it, why it failed), and once final its goods on their way or
     * the receipt "book as received" booked. Null for a credit note or a reversal.
     *
     * @param array<string, mixed> $rec SupplierInvoices::record()
     * @param list<array<string, mixed>> $lines SupplierInvoices::lines()
     * @return array<string, mixed>|null
     */
    public static function invoiceBox(Context $ctx, array $rec, \CW\Documents\Document $doc, bool $editable, array $lines): ?array
    {
        if (($rec['kind'] ?? null) !== 'invoice' || $doc->isReversal()) {
            return null;
        }
        $open = array_values(array_filter($lines, static fn (array $l): bool => $l['kind'] === 'goods' && $l['grn_document_id'] === null && $l['sku_id'] !== null));
        $required = $open === [] ? [] : (new \CW\Catalogue\ItemCompliance($ctx->db))->receiving(array_values(array_unique(array_map(static fn (array $l): int => (int) $l['sku_id'], $open))));
        $atValue = '';
        if ($rec['submit_at'] !== null) {
            $atValue = (new \DateTimeImmutable((string) $rec['submit_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d\TH:i');
        }
        $rows = [];
        if ($doc->status !== 'draft') {
            foreach ($ctx->db->all('SELECT a.line_no, a.units, a.sellable, a.state, a.grn_document_id, s.code, s.name, g.number AS grn_number FROM invoice_arrival a '
                . 'JOIN sku s ON s.id = a.sku_id LEFT JOIN document g ON g.id = a.grn_document_id WHERE a.sinv_document_id = ? ORDER BY a.line_no', [$doc->id]) as $r) {
                $rows[] = ['line_no' => (int) $r['line_no'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'units' => (int) $r['units'],
                    'sellable' => (int) $r['sellable'] === 1, 'state' => (string) $r['state'],
                    'receipt' => $r['grn_document_id'] === null ? null : ['href' => '/ui/receiving/' . (int) $r['grn_document_id'], 'number' => (string) ($r['grn_number'] ?? '')]];
            }
        }
        $receipt = null;
        if ($rec['submit_receipt_id'] !== null) {
            $receipt = ['href' => '/ui/receiving/' . (int) $rec['submit_receipt_id'],
                'number' => (string) ($ctx->db->value('SELECT number FROM document WHERE id = ?', [(int) $rec['submit_receipt_id']]) ?? '')];
        }
        return [
            'mode' => $rec['receive_mode'] === null ? null : (string) $rec['receive_mode'],
            'editable' => $editable && $doc->status === 'draft',
            'sellsBefore' => self::service($ctx)->prefsOf((int) $rec['supplier_id'])['sell_before'],
            'open' => count($open),
            'stampLines' => array_map(static fn (array $l): array => ['line_no' => (int) $l['line_no'], 'code' => (string) ($l['code'] ?? ''), 'name' => (string) ($l['name'] ?? ''),
                'required' => (bool) ($required[(int) $l['sku_id']]['stamp_required'] ?? true), 'answer' => $l['stamp_answer'] ?? null], $open),
            'at' => $rec['submit_at'] === null ? null : self::when((string) $rec['submit_at']),
            'atValue' => $atValue,
            'by' => (string) ($rec['submit_by_name'] ?? ''),
            'failed' => $rec['submit_failed_at'] === null ? null : (string) $rec['submit_error'],
            'rows' => $rows,
            'receipt' => $receipt,
        ];
    }

    /** A time to be made final, in UK time; with its clock (BST or GMT) when that UK time comes twice (review finding 6). */
    public static function when(string $utc): string
    {
        $at = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        return Html::when($utc) . (UkTime::happensTwice($at) ? ' ' . UkTime::clock($at) : '');
    }

    private static function service(Context $ctx): Arrivals
    {
        return new Arrivals($ctx->db, $ctx->documents(), static fn (): FileStore => $ctx->files());
    }
}
