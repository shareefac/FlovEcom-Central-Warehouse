-- 0086_goods_in_without_invoice.sql — goods in without the supplier invoice, PER SUPPLIER (tracker CW-1152; the owner, CW chat 21 Nov 2026:
-- "sometimes we will not have the invoice in hand, and it just stuck there ... It shouldn't be a mandatory", and 26 Nov 2026 ~21:25 UK:
-- option "A) Per supplier"; docs/decisions.md NI1-NI6).
--
--  * supplier.goods_in_without_invoice: 1 = "Can book goods in without the invoice" (ticked on the supplier's form by whoever may change a
--    supplier's details, audited as every supplier field). A delivery of such a supplier is booked in without the supplier invoice number
--    and/or its copy ("Invoice still to come"); every other check of a delivery stays. 0 (every supplier at install) = as before.
--
-- HOW IT IS APPLIED: one column added to supplier only while it is not there (a prepared statement, ALGORITHM = INSTANT: no table is copied;
-- if the server cannot do it instantly the statement fails instead of copying). No row is changed, nothing is deleted. A second run does
-- nothing. It does not need 0085 (Parcel2Go, another branch): the two touch nothing of each other and may be applied in either order.

SET @cw_0086_supplier = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supplier'
    AND column_name = 'goods_in_without_invoice') = 0,
  'ALTER TABLE supplier ADD COLUMN goods_in_without_invoice TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1: its deliveries may be booked in without the supplier invoice number or copy (CW-1152)'', ALGORITHM = INSTANT',
  'DO 0');
PREPARE cw_0086_supplier FROM @cw_0086_supplier;
EXECUTE cw_0086_supplier;
DEALLOCATE PREPARE cw_0086_supplier;
