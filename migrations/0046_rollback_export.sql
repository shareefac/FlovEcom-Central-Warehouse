-- Pack RB (docs/decisions.md RB1-RB12): the rollback file, the smallest switch-over tool (IM15). bin/rollback_export.php only reads;
-- these settings say which CW warehouses ERPNext's stock figure stands for, and the ERPNext names the file's entries carry. The ERPNext
-- names start as the ones the opening import reads (0039: erp_opening.warehouses, erp_opening.companies). No table changes.

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('rollback.cw_warehouses','string','"MAIN,VERIFY"',1,'RB2','The CW warehouses (codes, comma-separated) whose stock ERPNext''s figure stands for: the rollback file carries what CW booked there'),
 ('rollback.erp_warehouse','string','"Stores - VPG"',1,'RB4','The ERPNext warehouse the rollback file''s entries take stock into and out of (its name as in ERPNext)'),
 ('rollback.erp_company','string','"VAPE AND GO"',1,'RB4','The ERPNext company of the rollback file''s entries (its name as in ERPNext)'),
 ('rollback.max_rows','int','200000',1,'RB28','The most stock rows one rollback file reads: a bigger window stops before it is read, with how to go on (about 5 KB of memory a carried row)');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('rollback.cw_warehouses', 'rollback.erp_warehouse', 'rollback.erp_company', 'rollback.max_rows');
