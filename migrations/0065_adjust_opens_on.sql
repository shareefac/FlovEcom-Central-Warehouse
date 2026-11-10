-- 0065_adjust_opens_on.sql — the reason a new adjustment opens on is a setting (tracker CW-920 part 2, step 2; the owner's "Yes, step 2" of
-- 10 Nov 2026; docs/decisions.md AJ9-AJ13). Until now Stock > Adjustments opened with "Data correction" chosen for every line, a constant in
-- the code (AG2, AJ8). The owner's rule: no hard-coded value, so it is a setting, and he picks the reason.
--
--  * app_setting `stock.adjust_opens_on` ("data_correction": the page opens as it did before), set on the Settings page (under "Stock
--    records") as a choice of the reasons offered on stock corrections, with a reason, with its version 1 (baseline) in config_change.
--    Empty: no reason is chosen and the person chooses each time. A reason switched off later, or no longer offered on stock corrections,
--    is not used: the page opens on Data correction again while that is offered. The code reads the setting with that same default, so it
--    may run a moment before this file.
--
-- No reason is touched: offering "Count difference" on stock corrections (Settings > Reasons) and choosing it here are the owner's own steps.
--
-- HOW IT IS APPLIED: two rows; no table changes, no row changed, nothing deleted.

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('stock.adjust_opens_on','string','"data_correction"',1,'AJ9','The reason a new adjustment opens with, chosen for every line: one of the reasons offered on stock corrections, or empty (the person chooses each time)');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key = 'stock.adjust_opens_on';
