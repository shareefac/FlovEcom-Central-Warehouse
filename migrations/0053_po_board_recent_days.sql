-- 0053_po_board_recent_days.sql — the purchase orders board (the owner's "yes" to the design canvas, 29 Oct 2026: "monday.com design
-- style and color follow"; docs/decisions.md PV18): the open orders board shows the orders delivered or closed lately in a green group of
-- their own; how lately is a setting (the owner's rule: no hard-coded number).
--
--  * app_setting `po.board_recent_days` (14; 1-365), set on the Settings page with a reason, with its version 1 (baseline) in config_change.
--    An order delivered or closed (purchase_order.state received or closed) whose row last changed within that many days shows under
--    "Received or closed"; older ones under "All orders" and "Closed and cancelled". The code reads it with a default of 14, so it may run a
--    moment before this file.
--
-- HOW IT IS APPLIED: two rows; no table changes, no row changed, nothing deleted.

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('po.board_recent_days','int','14',1,'PV18','The purchase orders board shows an order delivered or closed in the last 14 days in its "Received or closed" group; older ones under All orders');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key = 'po.board_recent_days';
