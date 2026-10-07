<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Catalogue\BarcodeReviews;
use CW\Catalogue\ItemBarcodes;
use CW\CwException;
use CW\Db;
use CW\Matching\Gtin;
use CW\OpResult;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;

/**
 * Barcodes on the screens (IM3; docs/decisions.md I106, I107): the item page's forms (add a barcode with its units per scan,
 * remove one, change units per scan) and Items > Barcode review (/ui/items/barcodes): what the barcode sync could not decide
 * alone. catalogue.edit (checked again by CW\Catalogue\ItemBarcodes / BarcodeReviews, never admin); one effect per form (FormOnce).
 */
final class BarcodesController
{
    public const NOTICES = [
        'decided' => 'Decided. The barcode sync will not ask about this barcode and item again unless something changes.',
    ];

    public function add(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $barcode = $ctx->req->field('barcode') ?? '';
        $units = $ctx->req->field('units') ?? '1';
        try {
            $r = FormOnce::run($ctx, 'ui.barcode.add', ['barcode' => $barcode, 'units' => $units], static function (Db $db) use ($ctx, $id, $barcode, $units): OpResult {
                $a = (new ItemBarcodes($db))->add($ctx->caller(), $id, $barcode, self::unitsOf($units));
                return OpResult::of(303, ['barcode' => $a['barcode'], 'redirect' => Html::url('/ui/items/' . $id, ['notice' => $a['usable'] ? 'barcode_added' : 'barcode_added_unusable'])]);
            });
        } catch (CwException $e) {
            $key = in_array($e->errorCode, ['bad_form_key', 'idempotency_key_reused'], true) ? FormOnce::newKey() : ($ctx->req->field(FormOnce::FIELD) ?? FormOnce::newKey());
            return (new ItemController())->page($ctx, $e->httpStatus, $e, null, ['barcode' => $barcode, 'units' => $units, 'form_key' => $key]);
        }
        return FormOnce::redirect($r);
    }

    public function remove(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $barcode = $ctx->req->field('barcode') ?? '';
        $reason = $ctx->req->field('reason') ?? '';
        try {
            $r = FormOnce::run($ctx, 'ui.barcode.remove', ['barcode' => $barcode, 'reason' => $reason], static function (Db $db) use ($ctx, $id, $barcode, $reason): OpResult {
                (new ItemBarcodes($db))->remove($ctx->caller(), $id, $barcode, $reason);
                return OpResult::of(303, ['redirect' => Html::url('/ui/items/' . $id, ['notice' => 'barcode_removed'])]);
            });
        } catch (CwException $e) {
            return (new ItemController())->page($ctx, $e->httpStatus, $e);
        }
        return FormOnce::redirect($r);
    }

    public function setUnits(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $barcode = $ctx->req->field('barcode') ?? '';
        $was = $ctx->req->field('units_was') ?? '';
        $units = $ctx->req->field('units') ?? '';
        try {
            $r = FormOnce::run($ctx, 'ui.barcode.units', ['barcode' => $barcode, 'units_was' => $was, 'units' => $units],
                static function (Db $db) use ($ctx, $id, $barcode, $was, $units): OpResult {
                    $s = (new ItemBarcodes($db))->setUnits($ctx->caller(), $id, $barcode, self::unitsOf($was), self::unitsOf($units));
                    return OpResult::of(303, ['redirect' => Html::url('/ui/items/' . $id, ['notice' => $s['result'] === 'unchanged' ? 'units_unchanged' : 'units_saved'])]);
                });
        } catch (CwException $e) {
            return (new ItemController())->page($ctx, $e->httpStatus, $e);
        }
        return FormOnce::redirect($r);
    }

    // ------------------------------------------------------------------------------------------
    // The review queue
    // ------------------------------------------------------------------------------------------

    public function queue(Context $ctx): HtmlResponse
    {
        return $this->queuePage($ctx, 200, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
    }

    public function decide(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $decision = $ctx->req->field('decision') ?? '';
        $unitsRaw = trim($ctx->req->field('units') ?? '');
        $note = $ctx->req->field('note') ?? '';
        try {
            $r = FormOnce::run($ctx, 'ui.barcode_review.decide', ['decision' => $decision, 'units' => $unitsRaw, 'note' => $note],
                static function (Db $db) use ($ctx, $id, $decision, $unitsRaw, $note): OpResult {
                    (new BarcodeReviews($db))->decide($ctx->caller(), $id, $decision, $unitsRaw === '' ? null : self::unitsOf($unitsRaw), $note);
                    return OpResult::of(303, ['redirect' => Html::url('/ui/items/barcodes', ['notice' => 'decided'])]);
                });
        } catch (CwException $e) {
            return $this->queuePage($ctx, $e->httpStatus, $e, null, $id);
        }
        return FormOnce::redirect($r);
    }

    private function queuePage(Context $ctx, int $status, ?CwException $error, ?string $notice, ?int $errorId = null): HtmlResponse
    {
        $show = $ctx->req->param('show') === 'decided' ? 'decided' : 'open';
        $q = trim($ctx->req->param('barcode') ?? '');
        $barcode = $q === '' ? null : Gtin::key($q);
        $svc = new BarcodeReviews($ctx->db);
        $rows = [];
        foreach ($svc->rows($show, BarcodeReviews::LIST_LIMIT, $barcode) as $r) {
            $rows[] = $r + ['formKey' => FormOnce::newKey(), 'reasonLabel' => BarcodeReviews::REASONS[(string) $r['reason']] ?? (string) $r['reason'],
                'decisionLabel' => $r['decision'] === null ? null : (BarcodeReviews::DECISIONS[(string) $r['reason']][(string) $r['decision']]
                    ?? BarcodeReviews::RECORDED[(string) $r['decision']] ?? (string) $r['decision'])];
        }
        return $ctx->page('barcode_reviews', [
            'rows' => $rows,
            'show' => $show,
            'barcode' => $q,
            'open' => $svc->openCount(),
            'error' => $error?->getMessage(),
            'errorId' => $errorId,
            'limit' => BarcodeReviews::LIST_LIMIT,
            'maxUnits' => ItemBarcodes::UNITS_MAX,
        ], $status, ['title' => 'Barcode review', 'active' => 'barcodes', 'notice' => $notice]);
    }

    /** A whole number of units from a form (422 bad_units otherwise). */
    private static function unitsOf(string $v): int
    {
        $v = trim($v);
        if (preg_match('/^[1-9][0-9]{0,5}$/D', $v) !== 1) {
            throw new CwException('bad_units', 'Units per scan is a whole number from 1 (one item) to ' . number_format(ItemBarcodes::UNITS_MAX)
                . ' (for example 10 for an outer case of 10).', 422);
        }
        return (int) $v;
    }
}
