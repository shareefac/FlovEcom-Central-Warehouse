<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Items\HandMade;
use CW\OpResult;
use CW\PurchaseOrders\PoLinesFile;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\Suppliers;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * "Select or add new" on the purchase order screens (the owner, 15 Oct 2026: "In purchase order view: select or add new option";
 * pack PO, docs/decisions.md PO1-PO6). Wherever an order chooses something, the person picks one that is there or adds a new one
 * without leaving the order:
 *
 *  - the supplier (the list's "Start a new order", and a draft that has no products yet): "Add a new supplier", a small form (name,
 *    e-mail, phone). The supplier is made as the supplier screens make one (Suppliers::create: a DRAFT supplier, its approval rules
 *    unchanged) and put on the order at once (PurchaseOrders::createWithNewSupplier / setNewSupplier). The order is saved as a draft;
 *    confirming it waits for the supplier's approval, and the editor says what is still needed.
 *  - the product of a line: the scan box as before, and "Add a new item", a step that saves the table and opens
 *    /ui/purchasing/orders/{id}/new-item: Pack B's item made by hand (HandMade::create: a barcode on another item refused, the same
 *    name and identity answered with the items that are there and a "different item" box), this supplier's supplier item when a
 *    code, a pack or a price is given, and the line, in ONE transaction (PurchaseOrders::addNewItem); then back to the order.
 *
 * Who: the routes ask suppliers.manage (supplier) and items.create (item); the services check them again with doc.PO.post. People
 * without them never see the "add" options: the screens name who can add one (Words::PO_ADD ask_*). Every form works without the
 * script (CSP: app.js only adds the list's filter box). Creating forms carry a FormOnce key; the draft's form carries its version.
 */
final class PoSelectOrAddController
{
    /** The fields of "Add a new supplier" (Suppliers::FIELDS keys). */
    public const SUPPLIER_FIELDS = ['name', 'email', 'phone'];
    /** The fields of the step "Add a new item". */
    public const ITEM_FIELDS = ['name', 'brand', 'barcode', 'supplier_code', 'units_per_pack', 'pack_price', 'packs', 'anyway'];

    // ------------------------------------------------------------------------------------------
    // The supplier
    // ------------------------------------------------------------------------------------------

    /** POST /ui/purchasing/orders/new-supplier: a new supplier and a draft order for it (FormOnce: one of each however often it is sent). */
    public function newSupplier(Context $ctx): HtmlResponse
    {
        $typed = self::supplierTyped($ctx->req);
        try {
            $r = FormOnce::run($ctx, 'ui.po.new_supplier', $typed, function (Db $db) use ($ctx, $typed): OpResult {
                $res = $this->orders($ctx)->createWithNewSupplier($ctx->caller(), new Suppliers($db, $ctx->settings()), self::supplierFields($typed));
                return OpResult::of(303, ['result' => 'created', 'document_id' => $res['document']->id, 'supplier_id' => (int) $res['supplier']['id'],
                    'redirect' => Html::url('/ui/purchasing/orders/' . $res['document']->id, ['notice' => 'supplier_added'])]);
            });
        } catch (CwException $e) {
            return (new PurchaseOrdersController())->listPage($ctx, $e->httpStatus, null, null, ['newSupplier' => $typed, 'newSupplierError' => SuppliersController::plain($e),
                'supplierKey' => self::keyAgain($ctx, $e)]);
        }
        return FormOnce::redirect($r);
    }

    /** POST /ui/purchasing/orders/{id}/new-supplier: a new supplier put on this draft (no products yet), at the draft's version. */
    public function newSupplierOnDraft(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $version = UiRequest::id($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', Words::ERROR['bad_version']);
        }
        $typed = self::supplierTyped($ctx->req);
        $orders = new PurchaseOrdersController();
        try {
            $this->orders($ctx)->setNewSupplier($ctx->caller(), new Suppliers($ctx->db, $ctx->settings()), $id, $version, self::supplierFields($typed));
        } catch (CwException $e) {
            if ($e->errorCode === 'version_conflict') {
                return $orders->page($ctx, $id, 409, new CwException('version_conflict', Words::BUY_ERROR['version_conflict'], 409), null, ['newSupplier' => $typed]);
            }
            return $orders->page($ctx, $id, $e->httpStatus, null, null, ['newSupplier' => $typed, 'newSupplierError' => in_array($e->errorCode,
                ['supplier_has_lines', 'not_draft', 'not_creator'], true) ? PurchaseOrdersController::plain($e) : SuppliersController::plain($e)]);
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/orders/' . $id, ['notice' => 'supplier_added']));
    }

    /** @return array<string, string> what the supplier form sent */
    private static function supplierTyped(UiRequest $req): array
    {
        $out = [];
        foreach (self::SUPPLIER_FIELDS as $f) {
            $out[$f] = trim($req->field('new_supplier_' . $f) ?? '');
        }
        return $out;
    }

    /** @param array<string, string> $typed @return array<string, string> Suppliers::create's fields (an empty field is not sent) */
    private static function supplierFields(array $typed): array
    {
        return array_filter($typed, static fn (string $v): bool => $v !== '');
    }

    // ------------------------------------------------------------------------------------------
    // The item of a line
    // ------------------------------------------------------------------------------------------

    /** GET /ui/purchasing/orders/{id}/new-item?q=&packs=: the step, filled from the scan box (digits: the barcode; else the name). */
    public function newItemForm(Context $ctx): HtmlResponse
    {
        $q = mb_substr(trim($ctx->req->param('q') ?? ''), 0, 100);
        $digits = preg_match('/^[0-9 ]{6,64}$/D', $q) === 1;
        $packs = PoLinesFile::wholeNumber($ctx->req->param('packs') ?? '1') ?? 1;
        $typed = self::blank() + [];
        $typed['name'] = $digits ? '' : $q;
        $typed['barcode'] = $digits ? str_replace(' ', '', $q) : '';
        $typed['packs'] = (string) max(1, $packs);
        return $this->itemPage($ctx, $ctx->id(), 200, null, $typed, null, FormOnce::newKey());
    }

    /** POST /ui/purchasing/orders/{id}/new-item: the item, its supplier item (when given) and the line (FormOnce). */
    public function newItem(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $typed = self::itemTyped($ctx->req);
        $version = UiRequest::id($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', Words::ERROR['bad_version']);
        }
        try {
            $upp = self::count($typed['units_per_pack'], Words::PO_ADD['item_upp']);
            $packs = self::count($typed['packs'], Words::PO_ADD['item_packs']);
            $item = ['card' => ['name' => $typed['name'], 'brand' => $typed['brand'] === '' ? null : $typed['brand']], 'barcode' => $typed['barcode'],
                'anyway' => $typed['anyway'] === '1'];
            $supply = ['supplier_code' => $typed['supplier_code'], 'units_per_pack' => $upp, 'pack_price' => $typed['pack_price']];
            $r = FormOnce::run($ctx, 'ui.po.new_item', ['id' => $id, 'version' => $version] + $typed,
                function (Db $db) use ($ctx, $id, $version, $item, $supply, $packs): OpResult {
                    $res = $this->orders($ctx)->addNewItem($ctx->caller(), $id, $version, new HandMade($db, $ctx->decisions()), $item, $supply, $packs);
                    return OpResult::of(303, ['result' => $res['status'], 'document_id' => $id, 'sku_id' => $res['sku_id'],
                        'redirect' => Html::url('/ui/purchasing/orders/' . $id, ['notice' => 'item_added']) . '#scan']);
                });
        } catch (CwException $e) {
            $same = $e->errorCode === 'same_item_exists' && is_array($e->detail['items'] ?? null) ? $e->detail['items'] : null;
            return $this->itemPage($ctx, $id, $e->httpStatus, $same === null ? $e : null, $typed, $same, self::keyAgain($ctx, $e));
        }
        return FormOnce::redirect($r);
    }

    /**
     * The step's page. A person who is not the draft's buyer (or a draft that is not one any more) goes back to the order's page.
     *
     * @param array<string, string> $typed
     * @param list<array<string, mixed>>|null $same the items of the same name and identity (HandMade), or null
     */
    private function itemPage(Context $ctx, int $id, int $status, ?CwException $error, array $typed, ?array $same, string $formKey): HtmlResponse
    {
        $doc = $ctx->documents()->find($id);
        if ($doc === null || $doc->docType !== 'PO' || $doc->reversesId !== null) {
            return $ctx->error(404, 'unknown_purchase_order', Words::BUY_ERROR['unknown_purchase_order'], ['/ui/purchasing/orders', Words::MENU['orders']]);
        }
        $me = $ctx->me();
        if ($doc->status !== 'draft' || $doc->createdBy !== $me->id || !\CW\Documents\Documents::mayPost($me->roles, 'PO')) {
            return HtmlResponse::redirect('/ui/purchasing/orders/' . $id);
        }
        $supplier = $ctx->db->one('SELECT s.id, s.name FROM purchase_order p JOIN supplier s ON s.id = p.supplier_id WHERE p.document_id = ?', [$id]) ?? [];
        $other = null;
        if ($error?->errorCode === 'barcode_on_other_item' && isset($error->detail['sku_id'])) {
            $other = $ctx->db->one('SELECT id, code, name FROM sku WHERE id = ?', [(int) $error->detail['sku_id']]);
        }
        return $ctx->page('purchase_order_new_item', [
            'doc' => $doc,
            'supplierName' => (string) ($supplier['name'] ?? Words::ORDER['some_supplier']),
            'v' => $typed + self::blank(),
            'formKey' => $formKey,
            'error' => $error === null ? null : self::plain($error),
            'errorField' => is_string($error?->detail['field'] ?? null) ? (string) $error->detail['field'] : null,
            'same' => $same === null ? null : array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'code' => (string) $s['code'], 'name' => (string) $s['name']], $same),
            'other' => $other === null ? null : ['id' => (int) $other['id'], 'code' => (string) $other['code'], 'name' => (string) $other['name']],
            'packsToAdd' => (string) max(1, PoLinesFile::wholeNumber($typed['packs'] ?? '1') ?? 1),
        ], $status, ['title' => Words::say('PO_ADD', 'item_title', (string) ($supplier['name'] ?? Words::ORDER['some_supplier'])), 'active' => 'orders']);
    }

    /** A refusal of the step in words: the item's (Pack B), the supplier item's, else the order's. */
    public static function plain(CwException $e): string
    {
        $code = $e->errorCode;
        return match (true) {
            $code === 'bad_number' => $e->getMessage(), // made in words here
            isset(Words::ITEMS_ERROR[$code]) || isset(Words::CARD_ERROR[$code]) => FamiliesController::plain($e),
            in_array($code, ['duplicate_pack', 'duplicate_supplier_code', 'unknown_sku', 'bad_price'], true) => SupplierItemsController::plain($e),
            default => PurchaseOrdersController::plain($e),
        };
    }

    /** "3" -> 3; anything but a whole number of at least 1: 422 bad_number in words. */
    private static function count(string $typed, string $label): int
    {
        $n = PoLinesFile::wholeNumber($typed === '' ? '1' : $typed);
        if ($n === null || $n < 1 || $n > 1_000_000) {
            throw new CwException('bad_number', Words::say('PO_ADD', 'bad_number', $label), 422);
        }
        return $n;
    }

    /** @return array<string, string> every field of the step, empty (packs and pack 1) */
    private static function blank(): array
    {
        return ['name' => '', 'brand' => '', 'barcode' => '', 'supplier_code' => '', 'units_per_pack' => '1', 'pack_price' => '', 'packs' => '1', 'anyway' => ''];
    }

    /** @return array<string, string> what the step sent */
    private static function itemTyped(UiRequest $req): array
    {
        $out = [];
        foreach (self::ITEM_FIELDS as $f) {
            $out[$f] = trim($req->field($f) ?? '');
        }
        $out['anyway'] = $out['anyway'] === '1' ? '1' : '';
        return $out;
    }

    /** The form key a refused creating form is drawn with again: the same one, unless the key itself was the problem. */
    private static function keyAgain(Context $ctx, CwException $e): string
    {
        $key = $ctx->req->field(FormOnce::FIELD) ?? '';
        return in_array($e->errorCode, ['bad_form_key', 'idempotency_key_reused'], true) || preg_match('/^[0-9a-f]{32}$/D', $key) !== 1 ? FormOnce::newKey() : $key;
    }

    private function orders(Context $ctx): PurchaseOrders
    {
        return new PurchaseOrders($ctx->db, $ctx->documents(), $ctx->settings());
    }
}
