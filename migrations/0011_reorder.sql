-- 0011_reorder.sql — Phase I-2 task 3 (reorder): the sales history imported from the sites and the IM9 basic reorder list
-- (docs/decisions.md I60-I71).
--   sales_import_batch    one row per imported export (tools/sales_history/export.php -> bin/import_sales_history.php): its
--                         files and their sha256, the range it covers, the mapping report (unknown / unlinked units);
--                         never deleted by the app login (a later batch replaces its date range, the row stays as history);
--   sales_history_day     daily sales per site variant (listing units, online and office), keyed by the site's variant id:
--                         mapped to items at read time, so a listing linked later makes its history count;
--   channel_snapshot_day  the days the site's stock snapshot covered (an out-of-stock day is knowable only on those);
--   listing_stock_day     the UNSELLABLE days of the variants that sold in the export;
--   listing_stock_latest  the site's own stock on its last snapshot day (a reference column while the sites are not live);
--   item_reorder / reorder_brand   per-item and per-brand reorder settings (safety, lead, min / max, factor, rounding);
--   demand_anomaly        days whose sales are not trusted as demand; seeded with the 14-22 Sep 2026 pre-duty stockpiling;
--   reorder_demand        demand per item, rebuilt in one transaction by CW\Reorder\DemandBuilder.
-- The reorder.* settings are provisional defaults (owner to confirm). Nothing here touches stock. DDL as the I-2 spec §4.3.

CREATE TABLE sales_import_batch (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel_id      SMALLINT UNSIGNED NOT NULL,
  source          ENUM('cps','orders') NOT NULL COMMENT 'cps = consolidate_product_sale (Vape and Go); orders = order lines (Electrofag)',
  date_from       DATE NOT NULL,
  date_to         DATE NOT NULL COMMENT 'inclusive',
  sales_file      VARCHAR(255) NOT NULL,
  sales_sha256    CHAR(64) NOT NULL,
  stock_file      VARCHAR(255) NULL,
  stock_sha256    CHAR(64) NULL,
  latest_file     VARCHAR(255) NULL,
  latest_sha256   CHAR(64) NULL,
  manifest        JSON NOT NULL,
  exported_at     DATETIME(6) NOT NULL,
  status          ENUM('loading','loaded','failed') NOT NULL DEFAULT 'loading',
  rows_read       INT UNSIGNED NOT NULL DEFAULT 0,
  rows_loaded     INT UNSIGNED NOT NULL DEFAULT 0,
  units_loaded    BIGINT NOT NULL DEFAULT 0,
  unknown_rows    INT UNSIGNED NOT NULL DEFAULT 0, unknown_units  BIGINT NOT NULL DEFAULT 0,
  unlinked_rows   INT UNSIGNED NOT NULL DEFAULT 0, unlinked_units BIGINT NOT NULL DEFAULT 0,
  stock_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  actor           VARCHAR(64) NOT NULL,
  started_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  finished_at     DATETIME(6) NULL,
  error           VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_import_file (channel_id, sales_sha256),
  KEY ix_sales_import_channel (channel_id, status, date_to),
  CONSTRAINT fk_sales_import_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT ck_sales_import_range CHECK (date_from <= date_to),
  CONSTRAINT ck_sales_import_sha CHECK (REGEXP_LIKE(sales_sha256, '^[0-9a-f]{64}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE sales_history_day (
  channel_id           SMALLINT UNSIGNED NOT NULL,
  external_variant_id  VARCHAR(64)  NOT NULL COMMENT 'the site''s prodt_id (= channel_listing.external_variant_id)',
  sale_date            DATE         NOT NULL COMMENT 'UK calendar date (DATE(ord_date) on the site)',
  units_online         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'listing units',
  orders_online        INT UNSIGNED NOT NULL DEFAULT 0,
  net_online           DECIMAL(14,2) NOT NULL DEFAULT 0,
  gross_online         DECIMAL(14,2) NOT NULL DEFAULT 0,
  units_office         INT UNSIGNED NOT NULL DEFAULT 0,
  orders_office        INT UNSIGNED NOT NULL DEFAULT 0,
  batch_id             INT UNSIGNED NOT NULL,
  PRIMARY KEY (channel_id, external_variant_id, sale_date),
  KEY ix_sales_history_date (channel_id, sale_date),
  KEY ix_sales_history_batch (batch_id),
  CONSTRAINT fk_sales_history_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT fk_sales_history_batch FOREIGN KEY (batch_id) REFERENCES sales_import_batch (id),
  CONSTRAINT ck_sales_history_units CHECK (units_online + units_office > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='imported daily sales per site variant; mapped to items at read time';

CREATE TABLE channel_snapshot_day (
  channel_id     SMALLINT UNSIGNED NOT NULL,
  snapshot_date  DATE NOT NULL,
  variants       INT UNSIGNED NOT NULL,
  unsellable     INT UNSIGNED NOT NULL,
  batch_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (channel_id, snapshot_date),
  KEY ix_channel_snapshot_batch (batch_id),
  CONSTRAINT fk_channel_snapshot_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT fk_channel_snapshot_batch FOREIGN KEY (batch_id) REFERENCES sales_import_batch (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='days the site snapshot covered (an out-of-stock day is knowable only on these)';

CREATE TABLE listing_stock_day (
  channel_id           SMALLINT UNSIGNED NOT NULL,
  external_variant_id  VARCHAR(64) NOT NULL,
  stock_date           DATE NOT NULL,
  stock                INT NULL,
  stock_mode           VARCHAR(16) NULL,
  allow_backorders     TINYINT NULL,
  batch_id             INT UNSIGNED NOT NULL,
  PRIMARY KEY (channel_id, external_variant_id, stock_date),
  KEY ix_listing_stock_day_date (channel_id, stock_date),
  KEY ix_listing_stock_day_batch (batch_id),
  CONSTRAINT fk_listing_stock_day_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT fk_listing_stock_day_batch FOREIGN KEY (batch_id) REFERENCES sales_import_batch (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='UNSELLABLE days only (pss_sellable = 0), of variants that sold in the export';

CREATE TABLE listing_stock_latest (
  channel_id           SMALLINT UNSIGNED NOT NULL,
  external_variant_id  VARCHAR(64) NOT NULL,
  snapshot_date        DATE NOT NULL,
  stock                INT NOT NULL,
  stock_mode           VARCHAR(16) NULL,
  sellable             TINYINT NOT NULL,
  batch_id             INT UNSIGNED NOT NULL,
  PRIMARY KEY (channel_id, external_variant_id),
  KEY ix_listing_stock_latest_batch (batch_id),
  CONSTRAINT fk_listing_stock_latest_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT fk_listing_stock_latest_batch FOREIGN KEY (batch_id) REFERENCES sales_import_batch (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='the site''s own stock on the last snapshot day (reference column while sites are not live)';

CREATE TABLE item_reorder (
  sku_id              INT UNSIGNED NOT NULL,
  safety_days         TINYINT UNSIGNED NULL,
  lead_days_override  TINYINT UNSIGNED NULL,
  min_stock           INT UNSIGNED NULL,
  max_stock           INT UNSIGNED NULL,
  demand_factor       DECIMAL(4,2) NULL,
  pack_rounding       ENUM('up','nearest') NULL,
  do_not_reorder      TINYINT(1) NOT NULL DEFAULT 0,
  note                VARCHAR(500) NULL,
  version             INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by          INT UNSIGNED NULL,
  updated_actor       VARCHAR(64) NOT NULL,
  updated_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (sku_id),
  KEY ix_item_reorder_by (updated_by),
  CONSTRAINT fk_item_reorder_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_item_reorder_by FOREIGN KEY (updated_by) REFERENCES staff_user (id),
  CONSTRAINT ck_item_reorder_days CHECK ((safety_days IS NULL OR safety_days <= 90) AND (lead_days_override IS NULL OR lead_days_override <= 120)),
  CONSTRAINT ck_item_reorder_minmax CHECK (max_stock IS NULL OR min_stock IS NULL OR max_stock >= min_stock),
  CONSTRAINT ck_item_reorder_factor CHECK (demand_factor IS NULL OR (demand_factor >= 0 AND demand_factor <= 5)),
  CONSTRAINT ck_item_reorder_flags CHECK (do_not_reorder IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE reorder_brand (
  brand          VARCHAR(128) NOT NULL,
  demand_factor  DECIMAL(4,2) NULL,
  safety_days    TINYINT UNSIGNED NULL,
  note           VARCHAR(500) NULL,
  version        INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by     INT UNSIGNED NULL,
  updated_actor  VARCHAR(64) NOT NULL,
  updated_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (brand),
  KEY ix_reorder_brand_by (updated_by),
  CONSTRAINT fk_reorder_brand_by FOREIGN KEY (updated_by) REFERENCES staff_user (id),
  CONSTRAINT ck_reorder_brand_values CHECK ((demand_factor IS NULL OR (demand_factor >= 0 AND demand_factor <= 5)) AND (safety_days IS NULL OR safety_days <= 90))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='per-brand demand factor (e.g. the post-duty drop) and safety days';

CREATE TABLE demand_anomaly (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  date_from      DATE NOT NULL,
  date_to        DATE NOT NULL,
  channel_id     SMALLINT UNSIGNED NULL COMMENT 'NULL = every site',
  brand          VARCHAR(128) NULL COMMENT 'NULL = every brand',
  label          VARCHAR(200) NOT NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_by     INT UNSIGNED NULL,
  created_actor  VARCHAR(64) NOT NULL,
  created_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  ended_by       INT UNSIGNED NULL,
  ended_at       DATETIME(6) NULL,
  PRIMARY KEY (id),
  KEY ix_demand_anomaly_dates (is_active, date_from, date_to),
  KEY ix_demand_anomaly_channel (channel_id), KEY ix_demand_anomaly_created_by (created_by), KEY ix_demand_anomaly_ended_by (ended_by),
  CONSTRAINT fk_demand_anomaly_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT fk_demand_anomaly_created_by FOREIGN KEY (created_by) REFERENCES staff_user (id),
  CONSTRAINT fk_demand_anomaly_ended_by FOREIGN KEY (ended_by) REFERENCES staff_user (id),
  CONSTRAINT ck_demand_anomaly_range CHECK (date_from <= date_to AND DATEDIFF(date_to, date_from) <= 92),
  CONSTRAINT ck_demand_anomaly_active CHECK ((is_active = 1) = (ended_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='days whose sales are not trusted as demand (excluded)';
INSERT INTO demand_anomaly (date_from, date_to, channel_id, brand, label, created_actor) VALUES
 ('2026-09-14','2026-09-22',NULL,NULL,'Pre-duty stockpiling before the 1 Oct 2026 vaping products duty (+43% units/day on Vape and Go vs Jul-Aug; bigger baskets, same customers)','system:migrate');

CREATE TABLE reorder_demand (
  sku_id            INT UNSIGNED NOT NULL,
  computed_at       DATETIME(6) NOT NULL,
  rate              DECIMAL(12,4) NOT NULL COMMENT 'central units/day before factors (§7.3)',
  rate_short        DECIMAL(12,4) NULL,
  rate_long         DECIMAL(12,4) NULL,
  rate_raw_30       DECIMAL(12,4) NOT NULL COMMENT 'plain 30-day average, no exclusions (ERPNext-like, for comparison)',
  valid_days_short  SMALLINT UNSIGNED NOT NULL,
  valid_days_long   SMALLINT UNSIGNED NOT NULL,
  excluded_anomaly  SMALLINT UNSIGNED NOT NULL,
  excluded_promo    SMALLINT UNSIGNED NOT NULL,
  excluded_oos      SMALLINT UNSIGNED NOT NULL,
  excluded_other    SMALLINT UNSIGNED NOT NULL COMMENT 'no data / before first sale',
  capped_days       SMALLINT UNSIGNED NOT NULL,
  units_365         INT UNSIGNED NOT NULL,
  first_sale_date   DATE NULL,
  last_sale_date    DATE NULL,
  monthly           JSON NOT NULL COMMENT '{"YYYY-MM": central units} for 12 months',
  detail            JSON NOT NULL COMMENT 'per listing breakdown (§7.3)',
  PRIMARY KEY (sku_id),
  KEY ix_reorder_demand_rate (rate),
  CONSTRAINT fk_reorder_demand_sku FOREIGN KEY (sku_id) REFERENCES sku (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='demand per item, rebuilt by CW\\Reorder\\DemandBuilder';

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('reorder.default_lead_days','int','2',1,NULL,'Lead days when neither the item, the supplier item nor the supplier says'),
 ('reorder.default_review_days','int','7',1,NULL,'Order cycle (days until the next order to the same supplier)'),
 ('reorder.default_safety_days','int','5',1,NULL,'Safety days (plan IM9: 5, as today)'),
 ('reorder.short_window_days','int','28',1,NULL,'Short demand window'),
 ('reorder.long_window_days','int','91',1,NULL,'Long demand window'),
 ('reorder.short_weight','decimal','0.50',1,NULL,'Weight of the short window in the blended rate'),
 ('reorder.min_valid_days_short','int','7',1,NULL,'Fewest valid days for a short-window rate'),
 ('reorder.min_valid_days_long','int','21',1,NULL,'Fewest valid days for a long-window rate'),
 ('reorder.spike_cap_multiple','int','4',1,NULL,'A day is capped at this many times the listing''s mean'),
 ('reorder.spike_cap_floor','int','5',1,NULL,'... but never below this many units'),
 ('reorder.promo_price_drop','decimal','0.10',1,NULL,'Brand-day revenue/unit this far below its 28-day reference = promotion'),
 ('reorder.promo_units_uplift','decimal','1.50',1,NULL,'... and units at least this multiple of the reference'),
 ('reorder.promo_min_units','int','20',1,NULL,'... and at least this many brand units that day'),
 ('reorder.stale_history_days','int','3',0,NULL,'Warn when a site''s loaded sales history ends more than this many days ago');
