-- 0056_stock_ledger_doc_ref_index.sql — the fix lookup of Confirm all ready (rule f: "a one-time stock fix was booked for the item after
-- the correction"; docs/decisions.md CA20 proposal (a), built as CW-913): stock_ledger gets an index on doc_ref, so the lookup of the fixes
-- (doc_ref LIKE 'listening-fix:%') reads only the fixes' own rows (about 1,600 a fix) instead of each item's whole ledger at its place.
--
--  * ix_stock_ledger_doc_ref (doc_ref, warehouse_id, sku_id, created_at): Corrections::FIX_SQL reads it by FORCE INDEX (one range read).
--
-- HOW IT IS APPLIED: ALGORITHM = INPLACE, LOCK = NONE (bookings go on while it builds). Safe to run again: the ALTER runs only while the
-- index is not there (a run stopped after the ALTER and before the migration's own record does nothing the second time). No row changed.

SET @cw_0056 = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'stock_ledger'
    AND index_name = 'ix_stock_ledger_doc_ref') = 0,
  'ALTER TABLE stock_ledger ADD INDEX ix_stock_ledger_doc_ref (doc_ref, warehouse_id, sku_id, created_at), ALGORITHM = INPLACE, LOCK = NONE',
  'DO 0');
PREPARE cw_0056 FROM @cw_0056;
EXECUTE cw_0056;
DEALLOCATE PREPARE cw_0056;
