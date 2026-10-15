-- 0042_po_select_or_add.sql — pack PO (docs/decisions.md PO1-PO12): the purchase order's "select or add new" (the owner, 15 Oct 2026)
-- and the fixes of the Codex review of the PO module (runs/po_module_codex_review.txt). Two small tables altered, nothing seeded.
--
--  * purchase_order.terms_snapshot (PO9, review finding 7): the "Terms printed on orders" (setting po.terms) as they were when the order
--    was approved, written once by PurchaseOrderHandler::post and kept in the order's posting anchor (P2) when it is there. NULL for a
--    draft and for an order approved before this migration: its PDF prints the terms that were in force at its approval, read from the
--    setting's history (config_change), else the setting itself (PurchaseOrders::pdfData). Nothing is backfilled: the anchors of the
--    orders approved before stay as they were.
--  * goods_receipt.invoice_part (PO10, review finding 6): one supplier invoice that covers more than one delivery of the same order (a
--    split delivery). Part 1 is every receipt as before; a later delivery booked on the same invoice number is part 2, 3, ... (the
--    person says so: GoodsReceipts::setInvoice `invoice_split`), and only while every live receipt of that invoice is against the same
--    order and none has the same delivery note. The UNIQUE key of the live invoice number becomes (supplier, invoice key, part): two
--    receipts of one invoice are still refused unless the second is a declared later part.
--
-- purchase_order and goods_receipt are small tables (a few hundred rows on staging): each is altered in ONE statement. purchase_order in
-- place (a column added, reads and writes go on); goods_receipt by a copy (a CHECK is added with the key: reads go on, a receipt written
-- at that moment waits a second).

ALTER TABLE purchase_order
  ADD COLUMN terms_snapshot TEXT NULL
    COMMENT 'the po.terms printed on the order, as at its approval (NULL: a draft, or approved before 0042: the setting history is read)'
    AFTER supplier_snapshot,
  ALGORITHM = INPLACE, LOCK = NONE;

ALTER TABLE goods_receipt
  ADD COLUMN invoice_part SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT '1, or the later delivery N of one supplier invoice that covers several deliveries of the same order (a split delivery)'
    AFTER invoice_key,
  DROP INDEX uq_goods_receipt_invoice,
  ADD UNIQUE KEY uq_goods_receipt_invoice (supplier_id, invoice_key, invoice_part),
  ADD CONSTRAINT ck_goods_receipt_invoice_part CHECK (invoice_part BETWEEN 1 AND 999),
  ALGORITHM = COPY, LOCK = SHARED;
