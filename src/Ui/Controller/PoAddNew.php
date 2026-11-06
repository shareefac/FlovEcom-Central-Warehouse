<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Items\HandMade;
use CW\OpResult;
use CW\PurchaseOrders\PoLinesFile;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\HtmlResponse;
use CW\Ui\Kernel;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * "+ Add new" on the purchase order's page (pack PO, docs/decisions.md PO1-PO6; PO on one page, PX5): the supplier's dropdown ends with
 * "+ Add new supplier" (name, e-mail, phone), the product's with "+ Add new item" (Pack B's item made by hand, this supplier's supply of it
 * when a code, a pack or a price is given, the line). Both small forms are fields of the order's ONE form (PurchaseOrdersController::create
 * for a new order, ::lines for a draft): the table typed with them is saved with them, in one transaction, and without the script they are
 * a fold of that form with their own button. This class reads what they sent and says their refusals in words; the services do the work
 * (PurchaseOrders::newSupplier, ::setNewSupplier, ::addNewItem, with Suppliers::create and HandMade::create, which check suppliers.manage
 * and items.create again).
 */
final class PoAddNew
{
    /** The supplier form's fields: new_supplier_<name> (Suppliers::FIELDS keys). */
    public const SUPPLIER_FIELDS = ['name', 'email', 'phone'];
    /** The item form's fields: item_<name>. */
    public const ITEM_FIELDS = ['name', 'brand', 'barcode', 'code', 'upp', 'price', 'anyway'];

    /** @return array<string, string> what the supplier form sent (every field, trimmed) */
    public static function supplierTyped(UiRequest $req): array
    {
        $out = [];
        foreach (self::SUPPLIER_FIELDS as $f) {
            $out[$f] = trim($req->field('new_supplier_' . $f) ?? '');
        }
        return $out;
    }

    /** @param array<string, string> $typed @return array<string, string> Suppliers::create's fields (an empty field is not sent) */
    public static function supplierFields(array $typed): array
    {
        return array_filter($typed, static fn (string $v): bool => $v !== '');
    }

    /** @return array<string, string> what the item form sent (every field, trimmed; `anyway` is '1' or '') */
    public static function itemTyped(UiRequest $req): array
    {
        $out = [];
        foreach (self::ITEM_FIELDS as $f) {
            $out[$f] = trim($req->field('item_' . $f) ?? '');
        }
        $out['anyway'] = $out['anyway'] === '1' ? '1' : '';
        return $out;
    }

    /**
     * HandMade::create's input and the supply (PurchaseOrders::addNewItem) from what the item form sent; 422 bad_number in words for items
     * per pack that is not a whole number of at least 1.
     *
     * @param array<string, string> $typed itemTyped()
     * @return array{0: array<string, mixed>, 1: array{supplier_code: string, units_per_pack: int, pack_price: string}}
     */
    public static function itemInput(array $typed): array
    {
        $upp = self::count($typed['upp'] ?? '', Words::PO_ADD['item_upp']);
        return [
            ['card' => ['name' => $typed['name'] ?? '', 'brand' => ($typed['brand'] ?? '') === '' ? null : $typed['brand']], 'barcode' => $typed['barcode'] ?? '',
                'anyway' => ($typed['anyway'] ?? '') === '1'],
            ['supplier_code' => $typed['code'] ?? '', 'units_per_pack' => $upp, 'pack_price' => $typed['price'] ?? ''],
        ];
    }

    /** A refusal of "+ Add new item" in words: the item's (Pack B), the supplier item's, else the order's. */
    public static function plainItem(CwException $e): string
    {
        $code = $e->errorCode;
        return match (true) {
            $code === 'bad_number' => $e->getMessage(), // made in words here
            isset(Words::ITEMS_ERROR[$code]) || isset(Words::CARD_ERROR[$code]) => FamiliesController::plain($e),
            in_array($code, ['duplicate_pack', 'duplicate_supplier_code', 'unknown_sku', 'bad_price'], true) => SupplierItemsController::plain($e),
            default => PurchaseOrdersController::plain($e),
        };
    }

    /** A refusal of "+ Add new supplier" in words: the order's own (the draft's rules), else the supplier's. */
    public static function plainSupplier(CwException $e): string
    {
        return in_array($e->errorCode, ['supplier_has_lines', 'not_draft', 'not_creator', 'version_conflict', 'role_not_allowed', 'admin_cannot_post'], true)
            ? PurchaseOrdersController::plain($e) : SuppliersController::plain($e);
    }

    /** The form key a refused form is drawn with again: the same one (nothing was stored), unless the key itself was the problem. */
    public static function keyAgain(Context $ctx, ?CwException $e = null): string
    {
        $key = $ctx->req->field(FormOnce::FIELD) ?? '';
        return in_array($e?->errorCode, ['bad_form_key', 'idempotency_key_reused'], true) || preg_match('/^[0-9a-f]{32}$/D', $key) !== 1 ? FormOnce::newKey() : $key;
    }

    /** The lines' grid's "+ Add new item" by the script (docs/decisions.md KG21): the order's and the invoice's route. */
    public const NEW_ITEM = ['po' => '/ui/purchasing/orders/new-item', 'pi' => '/ui/purchasing/invoices/new-item'];

    /**
     * POST /ui/purchasing/orders/new-item and /ui/purchasing/invoices/new-item (docs/decisions.md KG21; the owner, 6 Nov 2026: "add new item add
     * option add"): the lines' grid's "+ Add new item", sent by the script with no page reload. It makes the item and the supplier's pack of
     * it as the form's "+ Add new item" makes them (PurchaseOrders::makeItem: Pack B's item, a barcode on another item 409, the same name 409
     * with the items there, the pack when a code, a pack or a price is typed) and NEVER the document: the lines typed on the page are not
     * saved, nor needed. Who may: the page's route (doc.PO.post / doc.SINV.post) and items.create (403 before anything is read, as the form's
     * action); the CSRF token (the kernel); FormOnce with the panel's own key (sent twice: one item, the same answer); the supplier chosen on
     * the page (an order's: not switched off, as an order's supplier; an invoice's: any). Answers a fragment (po_new_item, data-ni-fragment):
     * the item as one choice of the product box (the grid takes it as a line: qty 1, the price typed), or the refusal in words with the items
     * to use instead ("Use this one": the item that has the barcode, the items of the same name, as this supplier's choices) and, for the
     * same name, the words of "it is a different item".
     */
    public static function newItemLine(Context $ctx, string $page): HtmlResponse
    {
        if (!$ctx->me()->can('items.create')) {
            return $ctx->error(403, 'role_not_allowed', Kernel::refusal('items.create', $ctx->me(), 'POST'));
        }
        $req = $ctx->req;
        $sid = UiRequest::id($req->field('supplier_id'));
        $pos = new PurchaseOrders($ctx->db, $ctx->documents(), $ctx->settings());
        $fragment = static fn (array $vars): HtmlResponse => $ctx->fragment('po_new_item', $vars + ['choice' => null, 'error' => null, 'others' => [], 'anyway' => '',
            'nextKey' => FormOnce::newKey()]);
        try {
            $status = $sid === null ? null : $ctx->db->value('SELECT status FROM supplier WHERE id = ?', [$sid]);
            if ($status === null || ($page === 'po' && $status === 'inactive')) {
                throw new CwException('need_supplier', $page === 'po' ? Words::PO_ONE['need_supplier'] : Words::INVOICE_ERROR['need_supplier'], 422, ['field' => 'supplier_id']);
            }
            [$item, $supply] = self::itemInput(self::itemTyped($req));
            $r = FormOnce::run($ctx, 'ui.' . $page . '.new_item_line', array_diff_key($req->post, ['csrf' => 1, FormOnce::FIELD => 1]),
                function (Db $db) use ($ctx, $pos, $sid, $item, $supply): OpResult {
                    $x = $pos->makeItem($ctx->caller(), (int) $sid, new HandMade($ctx->db, $ctx->decisions()), $item, $supply);
                    return OpResult::of(200, ['sku_id' => (int) $x['made']['sku_id'], 'supplier_item_id' => $x['supplier_item'] === null ? null : (int) $x['supplier_item']['id']]);
                });
            $made = (int) $r->body['sku_id'];
            $si = isset($r->body['supplier_item_id']) ? (int) $r->body['supplier_item_id'] : null;
            return $fragment(['choice' => self::choiceOf($ctx, (int) $sid, $made, $si)]);
        } catch (CwException $e) {
            $others = [];
            if ($e->errorCode === 'same_item_exists' && is_array($e->detail['items'] ?? null)) {
                foreach ($e->detail['items'] as $o) {
                    $others[] = self::choiceOf($ctx, (int) $sid, (int) $o['id'], null);
                }
            } elseif ($e->errorCode === 'barcode_on_other_item' && isset($e->detail['sku_id'])) {
                $others[] = self::choiceOf($ctx, (int) $sid, (int) $e->detail['sku_id'], null);
            }
            return $fragment(['error' => $e->errorCode === 'same_item_exists' ? Words::PO_ADD['same_title'] . ' ' . Words::PO_ADD['same_text'] : self::plainItem($e),
                'others' => $others, 'anyway' => $e->errorCode === 'same_item_exists' ? Words::PO_ADD['same_anyway'] : '', 'nextKey' => self::keyAgain($ctx, $e)]);
        }
    }

    /**
     * An item as the product box's choice for this supplier (PurchaseOrdersController::productChoice): its supplier item when $si names one,
     * else this supplier's preferred pack of it when there is one, else the item alone.
     *
     * @return array<string, string>
     */
    private static function choiceOf(Context $ctx, int $supplierId, int $sku, ?int $si): array
    {
        $row = $ctx->db->one('SELECT * FROM supplier_item WHERE ' . ($si !== null ? 'id = ?' : 'supplier_id = ? AND sku_id = ? AND is_active = 1 ORDER BY is_preferred DESC, id LIMIT 1'),
            $si !== null ? [$si] : [$supplierId, $sku]);
        $item = PurchaseOrdersController::itemChoiceRow($ctx, $sku);
        if ($row !== null) {
            $item = ['supplier_item_id' => (int) $row['id'], 'units_per_pack' => (int) $row['units_per_pack'], 'supplier_code' => $row['supplier_code'],
                'purchase_unit' => (string) $row['purchase_unit'], 'last_pack_price' => $row['last_pack_price']] + $item;
        }
        return PurchaseOrdersController::withStock($ctx->db, [$item], [PurchaseOrdersController::productChoice($item)])[0]; // its stock too (KG34)
    }

    /** "3" -> 3 ('' is 1); anything but a whole number of at least 1: 422 bad_number in words. */
    private static function count(string $typed, string $label): int
    {
        $n = PoLinesFile::wholeNumber($typed === '' ? '1' : $typed);
        if ($n === null || $n < 1 || $n > 1_000_000) {
            throw new CwException('bad_number', Words::say('PO_ADD', 'bad_number', $label), 422, ['field' => 'item_upp']);
        }
        return $n;
    }
}
