-- 0037_reorder_engine.sql — Pack G: the full reorder engine (IM9) and the buying data around it, replacing ERPNext's E4 (the PO
-- "Fetch Item" suggestion and "Download Items"), E10 (pack sizes), E11 (the monthly average-sales and safety-stock job), E12 ("Items
-- Below Safety Stock", the Item Management page), E22 (enable / disable -> discontinued) and E23 (brand as a filter). docs/decisions.md
-- PG1-PG16. Nothing here books stock or changes Stock.php's locks or buckets. The integrator may renumber this file at merge.
--
--  * reorder_sales_day     CW's own record of each store's sales, per website variant and UK day, in the variant's own units:
--                          folded from the stock ledger by CW\Reorder\SalesFold (a sale's commit, cancel and uncancel; never an
--                          opening order), one UK day at a time. The demand reads it for the stores named in reorder.own_sales_stores,
--                          from the first whole day after the store's connect moment (T0); before it, the imported history.
--  * reorder_fold_day      one row per UK day folded (when, the ledger ids read, rows, units): what the fold has done.
--  * reorder_supplier      a supplier's spare days of stock (safety days), between the brand's and the normal setting.
--  * item_reorder.low_stock_level   the low-stock figure CW keeps for an item (single items); NULL = each website keeps its own.
--  * reorder_line          every listed item's reorder line in the compact columns the list filters and sorts by (brand, main
--                          supplier, to buy, urgent, below safety stock; an index in list order for "to buy", "every product" and "below
--                          the safety stock"), written by CW\Reorder\ReorderRefresh in chunks, only when a
--                          line changed; an item that leaves the list is unlisted (listed = 0), never deleted.
--  * reorder_line_detail   the whole line (JSON) the screen and the download print, read for one page (or one chunk) at a time.
--  * reorder_job           the last run of each scheduled step (fold, demand, lines): when, how long, what it did, the last error.
--  * nine settings, each with its version 1 (baseline) in config_change (docs/dev.md rule 5); all provisional, owner to confirm.
-- item_reorder is small (one row per item a buyer changed): its column is added by a table copy (ALGORITHM = COPY: the CHECK needs it;
-- a few hundred rows, a moment's shared lock). Every other statement creates a new table or adds rows.

CREATE TABLE reorder_sales_day (
  channel_id           SMALLINT UNSIGNED NOT NULL,
  external_variant_id  VARCHAR(64) NOT NULL COMMENT 'the store''s variant id (= channel_listing.external_variant_id); mapped to items at read time',
  sale_date            DATE NOT NULL COMMENT 'UK calendar date of the ledger rows (their created_at in Europe/London)',
  units                INT NOT NULL DEFAULT 0 COMMENT 'variant units sold that day: committed - cancelled + uncancelled (below 0 on a day of late cancellations; read as 0)',
  folded_at            DATETIME(6) NOT NULL,
  PRIMARY KEY (channel_id, external_variant_id, sale_date),
  KEY ix_reorder_sales_day_date (sale_date),
  CONSTRAINT fk_reorder_sales_day_channel FOREIGN KEY (channel_id) REFERENCES channel (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='CW''s own record of each store''s daily sales (SalesFold)';

CREATE TABLE reorder_fold_day (
  fold_date    DATE NOT NULL COMMENT 'the UK day folded',
  folded_at    DATETIME(6) NOT NULL,
  times        SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'how many times this day was folded (the last days are folded again)',
  ledger_from  BIGINT UNSIGNED NULL,
  ledger_to    BIGINT UNSIGNED NULL,
  rows_read    INT UNSIGNED NOT NULL DEFAULT 0,
  variants     INT UNSIGNED NOT NULL DEFAULT 0,
  units        BIGINT NOT NULL DEFAULT 0,
  ms           INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (fold_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='the UK days SalesFold has folded';

CREATE TABLE reorder_supplier (
  supplier_id    INT UNSIGNED NOT NULL,
  safety_days    TINYINT UNSIGNED NULL COMMENT 'spare days of stock for the items this supplier is the main supplier of; NULL = the normal setting',
  note           VARCHAR(500) NULL,
  version        INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by     INT UNSIGNED NULL,
  updated_actor  VARCHAR(64) NOT NULL,
  updated_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (supplier_id),
  KEY ix_reorder_supplier_by (updated_by),
  CONSTRAINT fk_reorder_supplier_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id),
  CONSTRAINT fk_reorder_supplier_by FOREIGN KEY (updated_by) REFERENCES staff_user (id),
  CONSTRAINT ck_reorder_supplier_safety CHECK (safety_days IS NULL OR safety_days <= 90)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='per-supplier reorder settings (safety days)';

ALTER TABLE item_reorder
  ADD COLUMN low_stock_level INT UNSIGNED NULL
    COMMENT 'the low-stock figure CW keeps (single items): the site writer writes floor(level / units per sale) for each linked variant; NULL = each website keeps its own'
    AFTER max_stock,
  ADD CONSTRAINT ck_item_reorder_low_stock CHECK (low_stock_level IS NULL OR low_stock_level <= 100000),
  ALGORITHM = COPY, LOCK = SHARED;

CREATE TABLE reorder_line (
  sku_id           INT UNSIGNED NOT NULL,
  listed           TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = the item left the list (no demand, no minimum, no low-stock figure any more)',
  need             TINYINT(1) NOT NULL COMMENT 'something to buy: packs > 0',
  urgent           TINYINT(1) NOT NULL,
  below_safety     TINYINT(1) NOT NULL COMMENT 'available now < safety stock',
  never_suggested  TINYINT(1) NOT NULL,
  no_demand        TINYINT(1) NOT NULL COMMENT 'no sales: days left unknown (sorts after every number)',
  cover_now_e1     BIGINT NOT NULL DEFAULT 0 COMMENT 'days left in tenths (0 when no_demand)',
  value_e4         BIGINT NOT NULL DEFAULT 0,
  brand            VARCHAR(128) NULL,
  supplier_id      INT UNSIGNED NULL COMMENT 'the main (preferred) supplier',
  safety_stock     INT NOT NULL,
  available        INT NOT NULL,
  low_stock_level  INT UNSIGNED NULL,
  line_hash        CHAR(40) NOT NULL,
  read_at          DATETIME(6) NOT NULL COMMENT 'when the figures of this line were read (a later read never loses to an earlier one)',
  changed_at       DATETIME(6) NOT NULL,
  PRIMARY KEY (sku_id),
  KEY ix_reorder_line_need (listed, need, urgent DESC, no_demand, cover_now_e1, value_e4 DESC, sku_id),
  KEY ix_reorder_line_all (listed, urgent DESC, no_demand, cover_now_e1, value_e4 DESC, sku_id),
  KEY ix_reorder_line_below (listed, below_safety, urgent DESC, no_demand, cover_now_e1, value_e4 DESC, sku_id),
  KEY ix_reorder_line_brand (brand, listed),
  KEY ix_reorder_line_supplier (supplier_id, listed),
  CONSTRAINT fk_reorder_line_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT ck_reorder_line_flags CHECK (listed IN (0, 1) AND need IN (0, 1) AND urgent IN (0, 1) AND below_safety IN (0, 1) AND never_suggested IN (0, 1)
    AND no_demand IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='the reorder list, worked out by CW\\Reorder\\ReorderRefresh';

CREATE TABLE reorder_line_detail (
  sku_id   INT UNSIGNED NOT NULL,
  line     JSON NOT NULL COMMENT 'ReorderList::lines() for the item (stock from CW), as the screen and the download print it',
  read_at  DATETIME(6) NOT NULL,
  PRIMARY KEY (sku_id),
  CONSTRAINT fk_reorder_line_detail_sku FOREIGN KEY (sku_id) REFERENCES sku (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='the whole reorder line of each listed item';

CREATE TABLE reorder_job (
  job          VARCHAR(16) NOT NULL COMMENT 'fold | demand | lines',
  started_at   DATETIME(6) NULL,
  finished_at  DATETIME(6) NULL,
  ok_at        DATETIME(6) NULL COMMENT 'the last run that finished without an error',
  ms           INT UNSIGNED NULL,
  runs         INT UNSIGNED NOT NULL DEFAULT 0,
  result       JSON NULL,
  error        VARCHAR(500) NULL,
  PRIMARY KEY (job),
  CONSTRAINT ck_reorder_job_name CHECK (job IN ('fold', 'demand', 'lines'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='the last run of each reorder job (bin/reorder_refresh.php)';

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('reorder.own_sales_stores','string','""',1,'PG2','The stores (channel codes, comma-separated; empty = none) whose sales the reorder engine takes from CW''s own record, from the first whole day after the store connected; before that day, and for every other store, the imported sales history'),
 ('reorder.refresh_minutes','int','15',1,'PG4','The reorder list''s figures (stock, orders, suggestions) are worked out again at most this many minutes apart'),
 ('reorder.refresh_chunk_items','int','500',1,'PG4','How many items one step of the reorder list''s refresh works out together (each step is one short write)'),
 ('reorder.refresh_inline_max_items','int','2000',1,'PG4','Up to this many items the reorder list is worked out again at once after a settings change or a click; above it, the next scheduled run does it'),
 ('reorder.demand_hour','int','4',1,'PG4','The UK hour from which the nightly job works the sales out again (once a day, after the day''s sales are folded)'),
 ('reorder.fold_refold_days','int','3',1,'PG1','The last days of CW''s own sales record that are folded again each night (a late cancellation lands on its own day)'),
 ('reorder.fold_days_per_run','int','31',1,'PG1','The most days of CW''s own sales record one run folds (the oldest first; the next run goes on)'),
 ('reorder.low_stock_bulk_max','int','200',1,'PG7','The most products one "set the low-stock figure" click changes'),
 ('reorder.draft_skip_no_price','bool','true',1,'PG8','"Create draft orders" leaves out (and lists) a ticked product whose supplier item has no price yet');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('reorder.own_sales_stores', 'reorder.refresh_minutes', 'reorder.refresh_chunk_items', 'reorder.refresh_inline_max_items',
    'reorder.demand_hour', 'reorder.fold_refold_days', 'reorder.fold_days_per_run', 'reorder.low_stock_bulk_max', 'reorder.draft_skip_no_price');
