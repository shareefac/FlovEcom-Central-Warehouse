-- 0039_erp_opening_import.sql — pack I: CW's opening stock and opening average cost migrated FROM ERPNext (the owner's decision of
-- 14 Oct 2026, which replaces the opening from the stores' own figures of D40a; the opening-cost half of IM8 / E16; docs/decisions.md
-- PI1-PI16). Numbered 0039: 0030-0038 are other packs' (built at the same time). Nothing here books stock, and the stock engine
-- (src/Stock.php) is unchanged: bin/import_erp_opening.php books through Movements::recordOpening (an `adjustment` per item, cost
-- source `estimate`, the source 0006 reserved for the opening cost load).
--
--  * erp_opening_run: one row per --apply of the import (a dry run writes NOTHING, PI12): the opening's doc_ref, the as-of moment, the
--    files' names and sha256, who ran it, and the run's whole summary (lines, resolved, unresolved, below zero, placeholder costs,
--    pending sales, units, value). Written once and finished once (status, summary, finished_at: the app login may UPDATE only those);
--    never removed. The optional Stock screen and the hand-over read the last one.
--  * Eight settings (provisional: each is a build decision put to the owner, changed on Settings with a reason; docs/decisions.md
--    PI1-PI6): which ERPNext warehouses and companies are read (the main ones), the names that are never read ("VPG 2": another
--    account's stock), what a figure below zero opens at (zero, or carry the minus), which valuation rates are placeholders, the store
--    whose variant numbers ERPNext's items carry, the CW warehouse the opening is booked at, and whether an --apply needs the file of
--    the sales still on their way to ERPNext. Each with its version 1 in config_change (docs/dev.md rule 5).

CREATE TABLE erp_opening_run (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  doc_ref               VARCHAR(160) NOT NULL COMMENT 'the opening this run booked (stock_ledger.doc_ref of its rows)',
  as_of                 DATETIME(6)  NOT NULL COMMENT 'UTC: the moment the ERPNext figures describe',
  file_name             VARCHAR(255) NOT NULL COMMENT 'the stock file (Stock Balance export) as given',
  file_sha256           CHAR(64)     NOT NULL,
  item_list_sha256      CHAR(64)     NULL COMMENT 'the ERPNext item list (Product ID), when given',
  pending_sales_sha256  CHAR(64)     NULL COMMENT 'the sales not yet in ERPNext, when given (NULL: that correction was not applied)',
  status                ENUM('running','done','failed') NOT NULL DEFAULT 'running',
  summary               JSON         NULL,
  actor                 VARCHAR(64)  NOT NULL,
  started_at            DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  finished_at           DATETIME(6)  NULL,
  PRIMARY KEY (id),
  KEY ix_erp_opening_run_ref (doc_ref, id),
  CONSTRAINT ck_erp_opening_run_sha CHECK (REGEXP_LIKE(file_sha256, '^[0-9a-f]{64}$', 'c')
      AND (item_list_sha256 IS NULL OR REGEXP_LIKE(item_list_sha256, '^[0-9a-f]{64}$', 'c'))
      AND (pending_sales_sha256 IS NULL OR REGEXP_LIKE(pending_sales_sha256, '^[0-9a-f]{64}$', 'c'))),
  CONSTRAINT ck_erp_opening_run_done CHECK ((status = 'running') = (finished_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='pack I: each --apply of bin/import_erp_opening.php and its summary; written once, finished once, never removed';

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('erp_opening.warehouses','text','"Stores - VPG"',1,'PI2','The ERPNext warehouses (one per line) whose stock is CW''s opening stock; rows of any other warehouse are left out and counted'),
 ('erp_opening.companies','text','"VAPE AND GO"',1,'PI2','The ERPNext companies (one per line) whose rows are read when the stock file has a Company column; empty = the warehouse list alone decides'),
 ('erp_opening.blocked_names','text','"VPG 2"',1,'PI2','Names (one per line) never read whatever the lists say: a company or warehouse whose name contains one (spaces and case ignored) is left out; listing one above is refused'),
 ('erp_opening.below_zero','string','"zero"',1,'PI4','What an item whose ERPNext figure (after the sales not yet in ERPNext) is below zero opens at: zero, or carry (the minus is carried into CW)'),
 ('erp_opening.placeholder_costs','string','"0,1"',1,'PI5','ERPNext valuation rates (pounds, comma-separated) that are only placeholders: never used as a cost; a blank rate always is one; the item is listed for re-costing'),
 ('erp_opening.listing_store','string','"vapeandgo"',1,'PI3','The store (channel code) whose variant numbers ERPNext''s items carry in their Product ID: an ERPNext item reaches a CW item through that store''s match'),
 ('erp_opening.cw_warehouse','string','"MAIN"',1,'PI1','The CW warehouse (code) the opening stock from ERPNext is booked at'),
 ('erp_opening.require_pending_sales','bool','true',1,'PI6','The import books only with the file of the units sold but not yet in ERPNext (an empty file says none); off: it books without and says the correction is still open');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('erp_opening.warehouses', 'erp_opening.companies', 'erp_opening.blocked_names', 'erp_opening.below_zero',
    'erp_opening.placeholder_costs', 'erp_opening.listing_store', 'erp_opening.cw_warehouse', 'erp_opening.require_pending_sales');
