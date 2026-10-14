-- 0033_sales_dispatch_views.sql — Stock › Sales: each store's orders, what waits to be sent, and what came back or was cancelled
-- (the owner's module list of 8 Oct 2026, "Sales & Delivery" and "Returns Management"; docs/decisions.md PD1-PD14). Read only:
-- no data changes, no column changes, nothing in the reservation engine (src/Reservations.php) reads or needs these indexes. The
-- integrator may renumber this file at merge.
--
--  * app_setting, five settings (the owner's rule of 8 Oct 2026: nothing hard-coded), each set on Settings › System › Settings with a
--    reason, with version 1 (baseline) in config_change (docs/dev.md rule 5):
--      sales.page_size      rows on a page of Orders, Dispatch and Returns, and sold units on an order's page (25, 50, 100 or 200; 50)
--      sales.default_days   the days back Orders looks when it opens (1 to 365; 7)
--      sales.late_hours     a paid order not sent after this many hours is late on Dispatch (1 to 720; 24)
--      sales.csv_max_rows   the most rows one CSV of these pages holds (100 to 50,000; 5,000)
--      sales.scan_max_rows  the most rows one page reads to fill itself where a filter is not in an index (500 to 50,000; 5,000)
--  * reservation (channel_id, status, origin, created_at, id): "one store's orders of one state and one way in, placed in a period,
--    newest first". 0023's (channel_id, status, id) has no time and no origin. Orders reads one range of this index per (store,
--    state, way in) asked for, each cut at a page, and merges them: never the table, never a sort of more than those pages.
--  * reservation_unit (channel_id, state, reservation_id, unit_id): "one store's sold units in one state, by order". The unit table's
--    indexes start with the order or the item (0001), so a store's units waiting to ship (Dispatch), its returned and cancelled units
--    (Returns) and its orders with a unit sent, cancelled or returned (Orders) could only be found by reading every unit the store
--    ever sold (docs/decisions.md RS7 left the per-store figure out for that reason). unit_id is the primary key's (InnoDB stores it
--    in the entry anyway); it is named so that (order, unit) is an exact page boundary MySQL reads in the index's order: left to the
--    hidden primary key part, the Returns page was sorted (EXPLAIN: Using filesort, 14 Oct 2026).
--  * reservation_unit (channel_id, state, dispatched_at, reservation_id): "one store's units sent in a period" (sent today, sent this
--    week, with the order they belong to: the read never leaves the index). A unit keeps its dispatch time when it is returned.
--  * stock_ledger (channel_id, movement_type, id): "one store's stock changes of one kind, newest first". Sending undone (a dispatch
--    reset, `unship`) is kept nowhere else: the unit goes back to waiting and its dispatch time is cleared. The time window is turned
--    into ledger ids through ix_stock_ledger_created (one look-up), so the entry needs no time. About 25 bytes a ledger row.
-- Every index is non-unique (no new duplicate-key or gap locks for the engine under READ COMMITTED) and is added online
-- (ALGORITHM = INPLACE, LOCK = NONE: reads and writes go on while it builds; 0020, 0023 and 0026 did the same). Cost for the
-- engine: one more entry per order, two more per sold unit and one more per ledger row, moved when the indexed state changes (the
-- engine's UPDATEs already move 0001's and 0023's entries). The code names each index in an optimizer hint, which MySQL ignores
-- while the index is not there, so the code may run a moment before this migration (slower, not wrong); the old code runs on this
-- schema (it never names the new settings or indexes).

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('sales.page_size','int','50',1,'PD9','How many rows a page of Stock > Sales shows (Orders, Dispatch, Returns; the sold units of one order): 25, 50, 100 or 200'),
 ('sales.default_days','int','7',1,'PD9','How many days back Stock > Sales > Orders looks when it opens (orders placed in the last N days)'),
 ('sales.late_hours','int','24',1,'PD9','A paid order not sent after this many hours is shown as late on Stock > Sales > Dispatch'),
 ('sales.csv_max_rows','int','5000',1,'PD9','The most rows one spreadsheet (CSV) of Stock > Sales holds'),
 ('sales.scan_max_rows','int','5000',1,'PD9','The most rows one page of Stock > Sales reads to fill itself when a filter is not in an index; the page then says it stopped');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('sales.page_size', 'sales.default_days', 'sales.late_hours', 'sales.csv_max_rows', 'sales.scan_max_rows');

ALTER TABLE reservation
  ADD INDEX ix_reservation_store_placed (channel_id, status, origin, created_at, id),
  ALGORITHM = INPLACE, LOCK = NONE;

ALTER TABLE reservation_unit
  ADD INDEX ix_reservation_unit_store_state (channel_id, state, reservation_id, unit_id),
  ADD INDEX ix_reservation_unit_store_sent (channel_id, state, dispatched_at, reservation_id),
  ALGORITHM = INPLACE, LOCK = NONE;

ALTER TABLE stock_ledger
  ADD INDEX ix_stock_ledger_store_type (channel_id, movement_type, id),
  ALGORITHM = INPLACE, LOCK = NONE;
