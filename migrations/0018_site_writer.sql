-- 0018_site_writer.sql — IM10 Selling-mode switch and site stock writer, Vape and Go proto first (docs/inventory-modules-plan.md
-- IM10, §7 phase I-3; docs/decisions.md I148-I166). What the change feed carries so a site can write its own quantity, selling mode
-- and low-stock threshold for the listings CW looks after, and CW's half of the switches that turn that on:
--
--  * channel.site_writer: the per-site "writer enabled" switch, OFF by default. While it is off CW tells the site to write nothing
--    (every listing view says writer false); I-Day turns it on with bin/channel_set.php --writer=on (audited). A change is
--    channel-wide on the feed (one stock_change row for the channel: the site re-snapshots).
--  * item_channel_mode / item_channel_mode_log: the selling mode CW writes on ONE site for a legacy (not yet counted) item, with its
--    low-stock threshold (IM10: "mode is per site for legacy items"). Written by the selling-mode switch on the item page (the ticked
--    sites, or all of them) and by a posted receipt for the sites in site_writer.receipt_mode_sites (I136: Vape and Go only until the
--    owner says otherwise). Counted (protected) items take their mode from the policy instead, one for every site (plan §7.4). Every
--    write is logged (append-only); the row is checked against its last log row nightly (W1). No FK to sku or channel (written inside
--    receipt postings, before their stock locks: an FK would S-lock the item's sku row first, D15, I21, I127); W2 checks the ids.
--  * site_writer.receipt_mode_sites: the sites a receipt's selling mode lands on (comma-separated channel codes; provisional).
-- No backfill. The ALTER is recorded only after every statement succeeded (the migrator), the rest is IF NOT EXISTS / INSERT IGNORE.

ALTER TABLE channel
  ADD COLUMN site_writer TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'IM10: 1 = CW writes this site''s stock, selling mode and low-stock threshold for its linked listings (I-Day); 0 = the site writes nothing from CW'
    AFTER movement_types,
  ADD CONSTRAINT ck_channel_site_writer CHECK (site_writer IN (0, 1));

CREATE TABLE IF NOT EXISTS item_channel_mode (
  sku_id               INT UNSIGNED      NOT NULL COMMENT 'no FK (written inside receipt postings; W2 checks it)',
  channel_id           SMALLINT UNSIGNED NOT NULL COMMENT 'no FK (W2 checks it)',
  mode                 ENUM('In-Stock','From-Warehouse','Out-Of-Stock') NOT NULL,
  previous_mode        ENUM('In-Stock','From-Warehouse') NULL COMMENT 'the mode before the item went Out-Of-Stock on this site (kept while it is)',
  low_stock_threshold  INT UNSIGNED      NULL COMMENT 'the site''s prodt_low_stock_threshold CW writes; NULL = leave the site''s own',
  version              INT UNSIGNED      NOT NULL DEFAULT 1,
  source               ENUM('switch','receipt') NOT NULL COMMENT 'what wrote it last: the selling-mode switch, or a posted receipt',
  document_id          BIGINT UNSIGNED   NULL COMMENT 'the receipt that wrote it last (source receipt)',
  updated_by           INT UNSIGNED      NULL,
  updated_actor        VARCHAR(64)       NOT NULL,
  updated_at           DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (sku_id, channel_id),
  KEY ix_item_channel_mode_channel (channel_id, sku_id),
  KEY ix_item_channel_mode_document (document_id),
  KEY ix_item_channel_mode_by (updated_by),
  CONSTRAINT fk_item_channel_mode_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_item_channel_mode_by FOREIGN KEY (updated_by) REFERENCES staff_user (id),
  CONSTRAINT ck_item_channel_mode_previous CHECK (previous_mode IS NULL OR mode = 'Out-Of-Stock'),
  CONSTRAINT ck_item_channel_mode_version CHECK (version >= 1),
  CONSTRAINT ck_item_channel_mode_threshold CHECK (low_stock_threshold IS NULL OR low_stock_threshold <= 100000),
  CONSTRAINT ck_item_channel_mode_source CHECK ((source = 'receipt') = (document_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the selling mode and low-stock threshold CW writes on one site for a legacy item (IM10); NO_DELETE for cw_app; W1, W2';

CREATE TABLE IF NOT EXISTS item_channel_mode_log (
  id                   BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  sku_id               INT UNSIGNED      NOT NULL,
  channel_id           SMALLINT UNSIGNED NOT NULL,
  version              INT UNSIGNED      NOT NULL COMMENT 'item_channel_mode.version after this write (1, 2, 3 ...)',
  mode_before          ENUM('In-Stock','From-Warehouse','Out-Of-Stock') NULL COMMENT 'NULL: CW had set no mode on this site before',
  mode_after           ENUM('In-Stock','From-Warehouse','Out-Of-Stock') NOT NULL,
  previous_mode        ENUM('In-Stock','From-Warehouse') NULL COMMENT 'item_channel_mode.previous_mode after this write',
  threshold_before     INT UNSIGNED      NULL,
  threshold_after      INT UNSIGNED      NULL,
  source               ENUM('switch','receipt') NOT NULL,
  reason               VARCHAR(500)      NULL COMMENT 'the switch: why, as the person typed it',
  document_id          BIGINT UNSIGNED   NULL,
  line_no              INT UNSIGNED      NULL,
  actor                VARCHAR(64)       NOT NULL,
  staff_user_id        INT UNSIGNED      NULL,
  created_at           DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_channel_mode_log_version (sku_id, channel_id, version),
  KEY ix_item_channel_mode_log_document (document_id),
  KEY ix_item_channel_mode_log_staff (staff_user_id),
  CONSTRAINT fk_item_channel_mode_log_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_item_channel_mode_log_staff FOREIGN KEY (staff_user_id) REFERENCES staff_user (id),
  CONSTRAINT ck_item_channel_mode_log_previous CHECK (previous_mode IS NULL OR mode_after = 'Out-Of-Stock'),
  CONSTRAINT ck_item_channel_mode_log_source CHECK ((source = 'receipt') = (document_id IS NOT NULL) AND (source = 'switch') = (reason IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='every write of item_channel_mode (append-only; W1)';

INSERT IGNORE INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('site_writer.receipt_mode_sites','string','"vapeandgo"',1,NULL,'The sites (channel codes, comma-separated; empty = none) on which a posted receipt''s selling mode becomes the item''s mode for the site stock writer (legacy items; counted items follow their policy)');
