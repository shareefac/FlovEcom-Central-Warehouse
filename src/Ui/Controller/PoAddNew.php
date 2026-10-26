<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\PurchaseOrders\PoLinesFile;
use CW\Ui\Context;
use CW\Ui\FormOnce;
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
