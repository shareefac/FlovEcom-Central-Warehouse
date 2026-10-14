-- 0034_reports_roles.sql — pack E: the Reports tabs, and who may do what changed on the screen (docs/decisions.md PE1-PE14).
-- Nothing a person or a store does today works differently after this file: the map of permissions is copied into the database
-- exactly as the code had it (one row per permission), the reports read what is there and write only their own tables, and the two
-- new indexes are added in place. The old code runs on this schema (it reads none of the new tables).
--
--  * role_permission: who holds each permission (CW\Auth\Permissions::MAP until now), one row per permission, the jobs in
--    Permissions::ROLES order, comma-separated. Read on every request (CW\Auth\RoleMap) and changed only by CW\Admin\RolePermissions
--    (Settings › Users › Roles and permissions), which keeps the guards (admin and the look-only jobs never act, the guarded jobs
--    keep their own permissions, the locked ones never change, a person never gives a job they hold a permission, only a Reviewer
--    gives one) and writes every change to config_change (subject `permission`, a version per change) and the audit log. The seed
--    below is MAP as the code has it in this commit (tests/Unit/RoleMapTest.php holds the two together); a permission the code adds
--    later and that has no row reads as the code says until someone changes it on the screen. The app login may read, add and
--    change rows, never delete them (CW\Schema\Grants::NO_DELETE).
--  * config_change.subject_type gains `permission` (an ENUM member added at the end: no table copy, in place).
--  * The reports' own tables, written only by the report job (CW\Reports\ReportRefresh, bin/refresh_reports.php, every 15 minutes
--    while the setting reports.refresh_on is on) and read by the Reports tabs; they can be emptied and rebuilt from the ledger at any
--    time (nothing else reads them):
--      report_item       per item: the units that came in with a cost and their cost (the average cost of CW\StockOps\CostHints,
--                        kept up to date from each item's value sequence, never by scanning the ledger), the first day stock came in,
--                        the last day it sold (a paid store order, a sale booked through ERPNext, a day of the imported sales history).
--      report_sales_day  per UK day and store: orders paid, units sold, sent, cancelled and returned.
--      report_refresh    one row: how far the job has read (the store sales, the imported sales history).
--  * reservation gains (status, committed_at, channel_id): "the orders a store was paid for on a day" read from the index alone (the
--    report job's day count). In place (INPLACE, LOCK=NONE: reads and writes go on while it builds); not unique, so no new locks.
--  * Settings (Settings › System › Settings, topic Reports), each with its baseline version (docs/dev.md rule 5).

CREATE TABLE role_permission (
  permission     VARCHAR(64)  NOT NULL COMMENT 'a key of CW\\Auth\\Permissions::MAP',
  roles          VARCHAR(400) NOT NULL COMMENT 'the jobs that hold it, in Permissions::ROLES order, comma-separated',
  updated_actor  VARCHAR(64)  NOT NULL,
  updated_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (permission),
  CONSTRAINT ck_role_permission_key CHECK (REGEXP_LIKE(permission, '^[a-z]+([.][A-Za-z]+)+$', 'c')),
  CONSTRAINT ck_role_permission_roles CHECK (REGEXP_LIKE(roles, '^([a-z_]+(,[a-z_]+)*)?$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='who holds each permission (pack E): changed only by Admin RolePermissions, history in config_change';

INSERT INTO role_permission (permission, roles, updated_actor) VALUES
  ('catalogue.view','viewer,mapper,mapping_lead,warehouse,manager,admin,buyer,purchasing_manager,goods_in,purchasing_desk,stock_controller,reviewer,accountant,auditor','system:migrate'),
  ('linking.view','viewer,mapper,mapping_lead,warehouse,manager,admin,auditor','system:migrate'),
  ('mapping.decide','mapper,mapping_lead','system:migrate'),
  ('mapping.approve','mapping_lead','system:migrate'),
  ('staff.view','admin,auditor','system:migrate'),
  ('staff.manage','admin','system:migrate'),
  ('reference.view','viewer,mapper,mapping_lead,warehouse,manager,admin,buyer,purchasing_manager,goods_in,purchasing_desk,stock_controller,reviewer,accountant,auditor','system:migrate'),
  ('documents.view','warehouse,manager,buyer,purchasing_manager,goods_in,purchasing_desk,stock_controller,reviewer,accountant,auditor','system:migrate'),
  ('documents.review','reviewer','system:migrate'),
  ('documents.approve','reviewer','system:migrate'),
  ('accounts.view','accountant,auditor','system:migrate'),
  ('doc.PO.post','buyer,purchasing_manager','system:migrate'),
  ('doc.GRN.post','purchasing_manager,goods_in,purchasing_desk','system:migrate'),
  ('doc.SINV.post','purchasing_manager,purchasing_desk','system:migrate'),
  ('doc.DN.post','purchasing_manager,purchasing_desk','system:migrate'),
  ('doc.CNT.post','warehouse,stock_controller','system:migrate'),
  ('doc.ADJ.post','stock_controller','system:migrate'),
  ('doc.WO.post','stock_controller','system:migrate'),
  ('doc.TRD.post','purchasing_manager,purchasing_desk','system:migrate'),
  ('doc.SIN.post','purchasing_desk,stock_controller','system:migrate'),
  ('doc.SOUT.post','purchasing_desk,stock_controller','system:migrate'),
  ('doc.TRF.post','warehouse,stock_controller','system:migrate'),
  ('doc.REL.post','purchasing_manager,purchasing_desk,stock_controller','system:migrate'),
  ('accounts.pay','purchasing_manager,purchasing_desk','system:migrate'),
  ('suppliers.view','manager,buyer,purchasing_manager,goods_in,purchasing_desk,stock_controller,reviewer,accountant,auditor','system:migrate'),
  ('suppliers.manage','buyer,purchasing_manager','system:migrate'),
  ('suppliers.approve','reviewer','system:migrate'),
  ('purchasing.view','manager,buyer,purchasing_manager,goods_in,purchasing_desk,reviewer,accountant,auditor','system:migrate'),
  ('reorder.view','manager,buyer,purchasing_manager,reviewer,auditor','system:migrate'),
  ('reorder.manage','buyer,purchasing_manager','system:migrate'),
  ('company.edit','reviewer','system:migrate'),
  ('company.confirm','reviewer','system:migrate'),
  ('catalogue.edit','mapping_lead,purchasing_manager,stock_controller','system:migrate'),
  ('receiving.view','manager,purchasing_manager,goods_in,purchasing_desk,stock_controller,reviewer,accountant,auditor','system:migrate'),
  ('incidents.view','manager,purchasing_manager,goods_in,purchasing_desk,stock_controller,reviewer,auditor','system:migrate'),
  ('incidents.resolve','purchasing_manager,purchasing_desk,stock_controller','system:migrate'),
  ('modes.set','manager,purchasing_manager,purchasing_desk,stock_controller','system:migrate'),
  ('settings.manage','admin,reviewer','system:migrate'),
  ('audit.view','admin,reviewer,accountant,auditor','system:migrate'),
  ('system.view','manager,admin,reviewer,auditor','system:migrate'),
  ('staff.approve','reviewer','system:migrate'),
  ('sales.view','viewer,warehouse,manager,buyer,purchasing_manager,goods_in,purchasing_desk,stock_controller,reviewer,accountant,auditor','system:migrate'),
  ('items.create','mapping_lead,purchasing_manager,stock_controller','system:migrate'),
  ('families.manage','mapping_lead,buyer,purchasing_manager,stock_controller','system:migrate'),
  ('doc.RPK.post','warehouse,stock_controller','system:migrate'),
  ('doc.SCN.post','purchasing_manager,purchasing_desk','system:migrate'),
  ('invoices.view','manager,buyer,purchasing_manager,purchasing_desk,reviewer,accountant,auditor','system:migrate'),
  ('invoices.accept','purchasing_manager,purchasing_desk,reviewer','system:migrate'),
  ('returns.view','manager,buyer,purchasing_manager,goods_in,purchasing_desk,stock_controller,reviewer,accountant,auditor','system:migrate'),
  ('queues.view','manager,buyer,purchasing_manager,goods_in,purchasing_desk,stock_controller,reviewer,accountant,auditor','system:migrate'),
  ('queues.suspense','purchasing_manager,purchasing_desk,stock_controller','system:migrate'),
  ('queues.corrections','stock_controller','system:migrate'),
  ('queues.modes','manager,purchasing_manager,purchasing_desk,stock_controller','system:migrate'),
  ('queues.oversell','manager,purchasing_manager,purchasing_desk,stock_controller','system:migrate'),
  ('reports.view','warehouse,manager,buyer,purchasing_manager,goods_in,purchasing_desk,stock_controller,reviewer,accountant,auditor','system:migrate'),
  ('values.view','purchasing_manager,reviewer,accountant,auditor','system:migrate');

ALTER TABLE config_change
  MODIFY subject_type ENUM('setting','reason','document_rule','warehouse','location','permission') NOT NULL,
  ALGORITHM = INPLACE, LOCK = NONE;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'permission', permission, 1, 'baseline', JSON_OBJECT('roles', roles), 'system:migrate' FROM role_permission;

CREATE TABLE report_item (
  sku_id        INT UNSIGNED  NOT NULL,
  seq_done      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'the item''s value sequence (stock_value_seq.seq) is read up to here',
  in_units      BIGINT        NOT NULL DEFAULT 0 COMMENT 'units that came in with a cost (an on-hand rise with unit_cost)',
  in_value      DECIMAL(24,6) NOT NULL DEFAULT 0 COMMENT 'GBP: their quantity times their unit cost; in_value / in_units = the average cost',
  first_in_on   DATE          NULL COMMENT 'UK day of the first rise of its on-hand figure (any warehouse)',
  last_sale_on  DATE          NULL COMMENT 'UK day of the last sale CW knows (paid store order, ERPNext sale, imported sales history)',
  updated_at    DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (sku_id),
  KEY ix_report_item_sale (last_sale_on),
  CONSTRAINT ck_report_item_in CHECK (in_units >= 0 AND in_value >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='derived per item by the report job (pack E); can be emptied and rebuilt';

CREATE TABLE report_sales_day (
  sale_date        DATE              NOT NULL COMMENT 'a UK calendar day',
  channel_id       SMALLINT UNSIGNED NOT NULL,
  orders           INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'orders paid that day (reservation.committed_at)',
  units_sold       INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'warehouse units on those orders (reservation_unit.units_per_item)',
  units_sent       INT               NOT NULL DEFAULT 0 COMMENT 'units sent that day, less the sends undone',
  units_cancelled  INT               NOT NULL DEFAULT 0 COMMENT 'paid units cancelled that day, less the cancels undone',
  units_returned   INT               NOT NULL DEFAULT 0 COMMENT 'units the customers sent back that day',
  refreshed_at     DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (sale_date, channel_id),
  KEY ix_report_sales_channel (channel_id, sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='derived per UK day and store by the report job (pack E); can be emptied and rebuilt';

CREATE TABLE report_refresh (
  id                TINYINT UNSIGNED NOT NULL,
  sales_through     DATETIME(6)  NULL COMMENT 'store sales are worked out up to this moment (UTC); NULL = not started',
  history_batch_id  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'the imported sales history is read up to this batch (sales_import_batch.id)',
  ran_at            DATETIME(6)  NULL COMMENT 'when the job last finished a run',
  note              VARCHAR(255) NULL COMMENT 'what that run did',
  PRIMARY KEY (id),
  CONSTRAINT ck_report_refresh_one CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='how far the report job has read (pack E): one row';

ALTER TABLE reservation
  ADD INDEX ix_reservation_committed (status, committed_at, channel_id),
  ALGORITHM = INPLACE, LOCK = NONE;

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('reports.low_stock_default','int','5',1,'PE9','Low Stock: an item is low when a store can sell this many units or fewer, unless its own "least stock to keep" (its reorder settings) says otherwise'),
 ('reports.slow_after_days','int','60',1,'PE11','Slow stock: an item in stock that has not sold for this many days'),
 ('reports.dead_after_days','int','180',1,'PE11','Dead stock: an item in stock that has not sold for this many days'),
 ('reports.sales_max_days','int','366',1,'PE8','The longest period Sales by Store shows at once, in days'),
 ('reports.refresh_on','bool','true',1,'PE12','The report job keeps the reports up to date every 15 minutes; off: the reports keep the figures of its last run'),
 ('reports.refresh_max_rows','int','50000',1,'PE12','The most stock changes one run of the report job reads'),
 ('reports.refresh_max_days','int','31',1,'PE12','The most days of store sales one run of the report job works out (a first run catches up over several runs)');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('reports.low_stock_default', 'reports.slow_after_days', 'reports.dead_after_days', 'reports.sales_max_days',
    'reports.refresh_on', 'reports.refresh_max_rows', 'reports.refresh_max_days');
