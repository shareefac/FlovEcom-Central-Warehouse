-- 0001_core.sql — Central Warehouse Part 1 core schema (docs/plan.md §2.2, §3, §8, §11, §12).
--
-- Conventions (see docs/decisions.md):
--  * InnoDB, utf8mb4 / utf8mb4_0900_ai_ci; idempotency keys use utf8mb4_0900_bin (exact match).
--  * Every timestamp is DATETIME(6) in UTC (Db.php pins the session time_zone to '+00:00').
--  * All stock quantities are in central (SKU) units; a listing unit converts with units_per_item.
--  * stock_balance.allocated / held can never be negative (CHECK); on_hand may be negative.
--  * stock_ledger and audit_log are append-only: the app login has SELECT/INSERT only there.
--  * Only src/Stock.php writes stock_balance / stock_ledger / stock_change;
--    only the DecisionService changes channel_listing.sku_id / status.

-- ---------------------------------------------------------------------------------------------
-- Locations
-- ---------------------------------------------------------------------------------------------
CREATE TABLE warehouse (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(32)  NOT NULL,
  name          VARCHAR(100) NOT NULL,
  is_sellable   TINYINT(1)   NOT NULL DEFAULT 0,
  owner_entity  VARCHAR(64)  NULL COMMENT 'Part 2: owning legal entity',
  created_at    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_warehouse_code (code),
  UNIQUE KEY uq_warehouse_id_sellable (id, is_sellable) COMMENT 'target of channel_warehouse composite FK',
  CONSTRAINT ck_warehouse_sellable CHECK (is_sellable IN (0, 1)),
  CONSTRAINT ck_warehouse_code CHECK (code REGEXP '^[A-Z][A-Z0-9_]*$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO warehouse (id, code, name, is_sellable) VALUES
  (1, 'MAIN',      'Main warehouse',                         1),
  (2, 'VERIFY',    'Doubtful cancels awaiting recount',      0),
  (3, 'UNSTAMPED', 'Unstamped stock (duty sweep, not for sale)', 0);

-- ---------------------------------------------------------------------------------------------
-- Central product record (§2.1)
-- ---------------------------------------------------------------------------------------------
CREATE TABLE sku (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(16)  NULL COMMENT 'CW-000123 = sprintf(CW-%06d, id), set in the creating transaction; never reused',
  name          VARCHAR(255) NOT NULL,
  brand         VARCHAR(128) NULL,
  sell_policy   ENUM('legacy','strict','backorder','stopped') NOT NULL DEFAULT 'legacy',
  counted_at    DATETIME(6)  NULL COMMENT 'first/last identity count (count gate, §7.4)',
  -- identity card: NULL = unknown
  strength_mg   DECIMAL(6,2) NULL,
  nic_type      VARCHAR(32)  NULL,
  line          VARCHAR(128) NULL,
  form          VARCHAR(32)  NULL,
  flavour       VARCHAR(255) NULL,
  volume_ml     DECIMAL(8,2) NULL,
  puffs         INT UNSIGNED NULL,
  pack_units    SMALLINT UNSIGNED NULL,
  created_at    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sku_code (code),
  KEY ix_sku_brand (brand),
  KEY ix_sku_policy (sell_policy),
  CONSTRAINT ck_sku_code CHECK (code IS NULL OR code REGEXP '^CW-[0-9]{6,}$'),
  CONSTRAINT ck_sku_strength CHECK (strength_mg IS NULL OR strength_mg >= 0),
  CONSTRAINT ck_sku_volume CHECK (volume_ml IS NULL OR volume_ml > 0),
  CONSTRAINT ck_sku_puffs CHECK (puffs IS NULL OR puffs > 0),
  CONSTRAINT ck_sku_pack CHECK (pack_units IS NULL OR pack_units >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE sku_barcode (
  barcode         VARCHAR(64)  NOT NULL COMMENT 'normalised (digits, GTIN check digit verified by the writer)',
  sku_id          INT UNSIGNED NOT NULL,
  is_usable       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 while the barcode is found on 2 items',
  units_per_scan  SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'e.g. an outer case = 10 units',
  source          VARCHAR(32)  NULL,
  note            VARCHAR(255) NULL,
  created_at      DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at      DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (barcode),
  KEY ix_sku_barcode_sku (sku_id),
  CONSTRAINT fk_sku_barcode_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT ck_sku_barcode_usable CHECK (is_usable IN (0, 1)),
  CONSTRAINT ck_sku_barcode_units CHECK (units_per_scan >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE sku_erp_item (
  item_code       VARCHAR(140) NOT NULL COMMENT 'ERPNext Item name',
  sku_id          INT UNSIGNED NOT NULL,
  units_per_item  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  note            VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL COMMENT 'staff_user.id',
  created_at      DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (item_code),
  KEY ix_sku_erp_item_sku (sku_id),
  CONSTRAINT fk_sku_erp_item_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT ck_sku_erp_item_units CHECK (units_per_item >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='override only: ERP goods-in normally resolves via the Vape and Go listing link (§9)';

-- ---------------------------------------------------------------------------------------------
-- Stock: balances, append-only ledger, change feed (§2.2, §2.3, §8.2)
-- ---------------------------------------------------------------------------------------------
CREATE TABLE stock_balance (
  warehouse_id  SMALLINT UNSIGNED NOT NULL,
  sku_id        INT UNSIGNED NOT NULL,
  on_hand       INT NOT NULL DEFAULT 0 COMMENT 'may be negative: physical events are never refused',
  allocated     INT NOT NULL DEFAULT 0,
  held          INT NOT NULL DEFAULT 0,
  counted_at    DATETIME(6) NULL COMMENT 'as-of time of the last count at this location (§8.2)',
  updated_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (warehouse_id, sku_id),
  KEY ix_stock_balance_sku (sku_id),
  CONSTRAINT fk_stock_balance_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouse (id),
  CONSTRAINT fk_stock_balance_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT ck_stock_balance_allocated CHECK (allocated >= 0),
  CONSTRAINT ck_stock_balance_held CHECK (held >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='cached bucket sums; available = on_hand - allocated - held';

CREATE TABLE stock_ledger (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  warehouse_id   SMALLINT UNSIGNED NOT NULL,
  sku_id         INT UNSIGNED NOT NULL,
  bucket         ENUM('on_hand','allocated','held') NOT NULL,
  qty_delta      INT NOT NULL,
  balance_after  INT NOT NULL COMMENT 'value of this bucket after the change',
  movement_type  VARCHAR(32) NOT NULL COMMENT 'reserve, release, expire, commit, cancel, ship, unship, return, goods_in, supplier_return, erp_sale, adjustment, count, write_off, transfer_out, transfer_in, opening, ...',
  channel_id     SMALLINT UNSIGNED NULL,
  order_ref      VARCHAR(64) NULL,
  unit_id        VARCHAR(64) NULL,
  doc_ref        VARCHAR(191) NULL COMMENT 'movement document (e.g. ERP document name + line index)',
  idem_key       VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NULL,
  effective_at   DATETIME(6) NULL COMMENT 'when it physically happened: dispatched_at for ship, counted_at for count',
  actor          VARCHAR(64) NOT NULL COMMENT 'channel:<code> | staff:<id> | system:<job>',
  note           VARCHAR(255) NULL,
  created_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY ix_stock_ledger_balance (warehouse_id, sku_id, id),
  KEY ix_stock_ledger_order (channel_id, order_ref),
  KEY ix_stock_ledger_created (created_at),
  CONSTRAINT fk_stock_ledger_balance FOREIGN KEY (warehouse_id, sku_id) REFERENCES stock_balance (warehouse_id, sku_id),
  CONSTRAINT ck_stock_ledger_nonneg CHECK (bucket = 'on_hand' OR balance_after >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='append-only journal of every bucket change (app login: SELECT, INSERT only)';

CREATE TABLE stock_change (
  seq           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'global version of every listing (§3)',
  sku_id        INT UNSIGNED NULL,
  listing_id    INT UNSIGNED NULL,
  channel_id    SMALLINT UNSIGNED NULL,
  reason        VARCHAR(32) NOT NULL COMMENT 'stock, link, policy, warehouse, quarantine, mode, ...',
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (seq),
  KEY ix_stock_change_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='change feed: which listings to re-read; all-NULL row = everything';

-- ---------------------------------------------------------------------------------------------
-- Websites (channels) and their warehouse assignment (§2.2, §8.1, §11, §12)
-- ---------------------------------------------------------------------------------------------
CREATE TABLE channel (
  id                     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                   VARCHAR(32)  NOT NULL,
  name                   VARCHAR(100) NOT NULL,
  mode                   ENUM('off','shadow','live') NOT NULL DEFAULT 'off',
  api_key_hash           CHAR(64) NULL COMMENT 'sha256 hex of the site key; NULL = unconfigured, every call refused',
  allowed_ips            JSON NOT NULL DEFAULT (JSON_ARRAY()) COMMENT 'JSON array of IPs/CIDRs; empty = every call refused',
  reserve_ttl_sec        INT UNSIGNED NOT NULL DEFAULT 2400 COMMENT 'hold TTL, default 40 min (§4)',
  t0_at                  DATETIME(6) NULL COMMENT 'moment the site entered shadow',
  t0_last_order_id       BIGINT UNSIGNED NULL COMMENT 'highest completed ord_id at T0',
  t0_last_stock_log_id   BIGINT UNSIGNED NULL COMMENT 'highest api_stock_update_logs id at T0',
  opening_orders_at      DATETIME(6) NULL COMMENT 'when POST /v1/opening_orders was accepted',
  created_at             DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at             DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_channel_code (code),
  UNIQUE KEY uq_channel_key (api_key_hash),
  CONSTRAINT ck_channel_code CHECK (code REGEXP '^[a-z][a-z0-9_]*$'),
  CONSTRAINT ck_channel_key CHECK (api_key_hash IS NULL OR api_key_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT ck_channel_ips CHECK (JSON_TYPE(allowed_ips) = 'ARRAY'),
  CONSTRAINT ck_channel_ttl CHECK (reserve_ttl_sec BETWEEN 60 AND 86400)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE channel_warehouse (
  channel_id           SMALLINT UNSIGNED NOT NULL,
  warehouse_id         SMALLINT UNSIGNED NOT NULL,
  is_sellable          TINYINT(1) NOT NULL COMMENT 'copy of warehouse.is_sellable, pinned by the composite FK',
  priority             SMALLINT NOT NULL DEFAULT 0,
  sellable_channel_id  SMALLINT UNSIGNED GENERATED ALWAYS AS (IF(is_sellable = 1, channel_id, NULL)) STORED,
  created_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (channel_id, warehouse_id),
  UNIQUE KEY uq_channel_one_sellable (sellable_channel_id) COMMENT 'v1: at most one sellable warehouse per channel',
  KEY ix_channel_warehouse_wh (warehouse_id, is_sellable),
  CONSTRAINT fk_channel_warehouse_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT fk_channel_warehouse_wh FOREIGN KEY (warehouse_id, is_sellable) REFERENCES warehouse (id, is_sellable),
  CONSTRAINT ck_channel_warehouse_sellable CHECK (is_sellable IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE channel_listing (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel_id           SMALLINT UNSIGNED NOT NULL,
  external_variant_id  VARCHAR(64) NOT NULL COMMENT 'the site''s variant id',
  sku_id               INT UNSIGNED NULL COMMENT 'the link; NULL = not linked',
  units_per_item       SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'u: central units per listing unit',
  status               ENUM('unmapped','suggested','mapped','quarantined','ignored') NOT NULL DEFAULT 'unmapped',
  map_version          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '+1 on every DecisionService change',
  created_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_channel_listing_variant (channel_id, external_variant_id),
  KEY ix_channel_listing_sku (sku_id),
  KEY ix_channel_listing_status (channel_id, status),
  CONSTRAINT fk_channel_listing_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT fk_channel_listing_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT ck_channel_listing_units CHECK (units_per_item >= 1),
  CONSTRAINT ck_channel_listing_link CHECK ((status IN ('mapped', 'quarantined')) = (sku_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the link between a site listing and a central item; changed only by DecisionService';

CREATE TABLE listing_profile (
  listing_id      INT UNSIGNED NOT NULL,
  product_title   VARCHAR(512) NULL,
  variant_title   VARCHAR(512) NULL,
  brand           VARCHAR(128) NULL,
  attributes      JSON NULL,
  barcodes        JSON NULL COMMENT 'every barcode the site holds for the variant',
  price           DECIMAL(10,2) NULL,
  perma_link      VARCHAR(512) NULL,
  units_30d       INT UNSIGNED NULL,
  units_365d      INT UNSIGNED NULL,
  profile_hash    CHAR(64) NULL COMMENT 'hash of the whole pushed profile (15-min delta)',
  identity_hash   CHAR(64) NULL COMMENT 'hash of the matching-relevant fields (edit flag)',
  pushed_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (listing_id),
  CONSTRAINT fk_listing_profile_listing FOREIGN KEY (listing_id) REFERENCES channel_listing (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='matching inputs, written by PUT /v1/listings';

-- ---------------------------------------------------------------------------------------------
-- Reservations (§3, §4, §8.1)
-- ---------------------------------------------------------------------------------------------
CREATE TABLE reservation (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel_id    SMALLINT UNSIGNED NOT NULL,
  order_ref     VARCHAR(64) NOT NULL COMMENT 'the site''s ord_id',
  status        ENUM('held','committed','released','expired') NOT NULL,
  attempt       INT UNSIGNED NOT NULL DEFAULT 1,
  lines_hash    CHAR(64) NULL COMMENT 'sha256 of the canonical lines of the current attempt',
  origin        ENUM('reserved','unreserved','opening') NOT NULL DEFAULT 'reserved',
  is_tombstone  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = created by a release of an unknown ref',
  expires_at    DATETIME(6) NULL COMMENT 'set while held',
  committed_at  DATETIME(6) NULL,
  released_at   DATETIME(6) NULL,
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_reservation_order (channel_id, order_ref),
  KEY ix_reservation_expiry (status, expires_at),
  CONSTRAINT fk_reservation_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT ck_reservation_attempt CHECK (attempt >= 1),
  CONSTRAINT ck_reservation_tombstone CHECK (is_tombstone IN (0, 1)),
  CONSTRAINT ck_reservation_held_expiry CHECK (status <> 'held' OR expires_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE reservation_unit (
  channel_id      SMALLINT UNSIGNED NOT NULL,
  unit_id         VARCHAR(64) NOT NULL COMMENT 'the site''s ordi_id: one row per sold unit',
  reservation_id  BIGINT UNSIGNED NOT NULL,
  listing_id      INT UNSIGNED NOT NULL COMMENT 'channel_listing.id at sale time',
  sku_id          INT UNSIGNED NULL COMMENT 'link snapshot at sale time; NULL = unlinked (holding ledger only)',
  units_per_item  SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'u snapshot at sale time',
  warehouse_id    SMALLINT UNSIGNED NOT NULL COMMENT 'the channel''s sellable warehouse at sale time',
  state           ENUM('held','allocated','shipped','cancelled','released','returned') NOT NULL,
  dispatched_at   DATETIME(6) NULL,
  created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (channel_id, unit_id),
  KEY ix_reservation_unit_reservation (reservation_id),
  KEY ix_reservation_unit_sku_state (sku_id, warehouse_id, state),
  KEY ix_reservation_unit_listing (listing_id),
  CONSTRAINT fk_reservation_unit_reservation FOREIGN KEY (reservation_id) REFERENCES reservation (id),
  CONSTRAINT ck_reservation_unit_u CHECK (units_per_item >= 1),
  CONSTRAINT ck_reservation_unit_shipped CHECK (state <> 'shipped' OR dispatched_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------------------------------
-- Staff queues and site health (§2.2, §4, §8.2, §9)
-- ---------------------------------------------------------------------------------------------
CREATE TABLE staff_user (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username        VARCHAR(64)  NOT NULL,
  display_name    VARCHAR(128) NOT NULL,
  email           VARCHAR(191) NULL,
  password_hash   VARCHAR(255) NOT NULL COMMENT 'password_hash() output',
  totp_secret     VARBINARY(255) NULL COMMENT 'encrypted TOTP secret; NULL = TOTP not enrolled (login refused)',
  totp_last_step  BIGINT UNSIGNED NULL COMMENT 'last accepted TOTP time step (replay guard)',
  roles           JSON NOT NULL DEFAULT (JSON_ARRAY()),
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  failed_logins   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME(6) NULL,
  last_login_at   DATETIME(6) NULL,
  created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_staff_user_username (username),
  UNIQUE KEY uq_staff_user_email (email),
  CONSTRAINT ck_staff_user_roles CHECK (JSON_TYPE(roles) = 'ARRAY'),
  CONSTRAINT ck_staff_user_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE oversell_event (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  warehouse_id     SMALLINT UNSIGNED NOT NULL,
  sku_id           INT UNSIGNED NOT NULL,
  channel_id       SMALLINT UNSIGNED NULL,
  reservation_id   BIGINT UNSIGNED NULL,
  order_ref        VARCHAR(64) NULL,
  kind             VARCHAR(32) NOT NULL COMMENT 'commit_short, commit_after_expiry, outage_order, ...',
  shortfall        INT NOT NULL COMMENT 'central units short (> 0)',
  available_after  INT NOT NULL,
  detail           JSON NULL,
  dedupe_key       VARCHAR(191) NULL,
  status           ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  resolution       VARCHAR(32) NULL COMMENT 'backordered, refunded, no_action, ...',
  resolved_by      INT UNSIGNED NULL,
  resolved_at      DATETIME(6) NULL,
  note             VARCHAR(500) NULL,
  created_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_oversell_dedupe (dedupe_key),
  KEY ix_oversell_status (status, created_at),
  KEY ix_oversell_balance (warehouse_id, sku_id),
  KEY ix_oversell_order (channel_id, order_ref),
  KEY ix_oversell_resolver (resolved_by),
  CONSTRAINT fk_oversell_balance FOREIGN KEY (warehouse_id, sku_id) REFERENCES stock_balance (warehouse_id, sku_id),
  CONSTRAINT fk_oversell_resolver FOREIGN KEY (resolved_by) REFERENCES staff_user (id),
  CONSTRAINT ck_oversell_shortfall CHECK (shortfall > 0),
  CONSTRAINT ck_oversell_resolved CHECK (status = 'open' OR resolved_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE goods_in_suspense (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source               VARCHAR(32)  NOT NULL COMMENT 'erp_relay, cw_screen',
  channel_id           SMALLINT UNSIGNED NULL COMMENT 'relaying site',
  doc_type             VARCHAR(32)  NOT NULL COMMENT 'purchase_invoice, debit_note, sales_invoice, ...',
  doc_ref              VARCHAR(191) NOT NULL COMMENT 'document name',
  line_index           INT UNSIGNED NOT NULL,
  movement_type        VARCHAR(32)  NOT NULL COMMENT 'goods_in, supplier_return, erp_sale',
  qty                  INT NOT NULL COMMENT 'signed, in the document''s units',
  external_variant_id  VARCHAR(64)  NULL,
  erp_item_code        VARCHAR(140) NULL,
  barcode              VARCHAR(64)  NULL,
  description          VARCHAR(512) NULL,
  reason               VARCHAR(64)  NOT NULL COMMENT 'unknown_listing, unlinked_listing, ...',
  payload              JSON NULL,
  dedupe_key           VARCHAR(191) NOT NULL COMMENT 'document name + line index',
  status               ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  resolved_sku_id      INT UNSIGNED NULL,
  resolved_by          INT UNSIGNED NULL,
  resolved_at          DATETIME(6) NULL,
  note                 VARCHAR(500) NULL,
  created_at           DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_goods_in_suspense_dedupe (dedupe_key),
  KEY ix_goods_in_suspense_status (status, created_at),
  KEY ix_goods_in_suspense_sku (resolved_sku_id),
  KEY ix_goods_in_suspense_resolver (resolved_by),
  CONSTRAINT fk_goods_in_suspense_sku FOREIGN KEY (resolved_sku_id) REFERENCES sku (id),
  CONSTRAINT fk_goods_in_suspense_resolver FOREIGN KEY (resolved_by) REFERENCES staff_user (id),
  CONSTRAINT ck_goods_in_suspense_resolved CHECK (status = 'open' OR resolved_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE count_review (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  warehouse_id   SMALLINT UNSIGNED NOT NULL,
  sku_id         INT UNSIGNED NOT NULL,
  source         VARCHAR(32) NOT NULL COMMENT 'erp_reconciliation, ship_near_count, negative_on_hand, verify_recount, ...',
  proposed_qty   INT NULL COMMENT 'e.g. ERPNext reconciliation target quantity',
  ref            VARCHAR(191) NULL COMMENT 'document / order / unit reference',
  detail         JSON NULL,
  dedupe_key     VARCHAR(191) NULL,
  status         ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  resolution     VARCHAR(32) NULL,
  resolved_by    INT UNSIGNED NULL,
  resolved_at    DATETIME(6) NULL,
  note           VARCHAR(500) NULL,
  created_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_count_review_dedupe (dedupe_key),
  KEY ix_count_review_status (status, created_at),
  KEY ix_count_review_sku (sku_id, warehouse_id),
  KEY ix_count_review_wh (warehouse_id),
  KEY ix_count_review_resolver (resolved_by),
  CONSTRAINT fk_count_review_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouse (id),
  CONSTRAINT fk_count_review_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_count_review_resolver FOREIGN KEY (resolved_by) REFERENCES staff_user (id),
  CONSTRAINT ck_count_review_resolved CHECK (status = 'open' OR resolved_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE policy_review (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku_id           INT UNSIGNED NULL,
  listing_id       INT UNSIGNED NULL,
  source           VARCHAR(32) NOT NULL COMMENT 'erp_update_product, count_gate, ...',
  site_mode        VARCHAR(32) NULL COMMENT 'mode as received, e.g. In-Stock / From-Warehouse / Out-Of-Stock',
  current_policy   ENUM('legacy','strict','backorder','stopped') NULL,
  proposed_policy  ENUM('legacy','strict','backorder','stopped') NOT NULL,
  detail           JSON NULL,
  dedupe_key       VARCHAR(191) NULL,
  status           ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  resolution       VARCHAR(32) NULL COMMENT 'approved, rejected, superseded, ...',
  resolved_by      INT UNSIGNED NULL,
  resolved_at      DATETIME(6) NULL,
  note             VARCHAR(500) NULL,
  created_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_policy_review_dedupe (dedupe_key),
  KEY ix_policy_review_status (status, created_at),
  KEY ix_policy_review_sku (sku_id),
  KEY ix_policy_review_listing (listing_id),
  KEY ix_policy_review_resolver (resolved_by),
  CONSTRAINT fk_policy_review_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_policy_review_listing FOREIGN KEY (listing_id) REFERENCES channel_listing (id),
  CONSTRAINT fk_policy_review_resolver FOREIGN KEY (resolved_by) REFERENCES staff_user (id),
  CONSTRAINT ck_policy_review_target CHECK (sku_id IS NOT NULL OR listing_id IS NOT NULL),
  CONSTRAINT ck_policy_review_resolved CHECK (status = 'open' OR resolved_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE channel_health (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel_id             SMALLINT UNSIGNED NOT NULL,
  received_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  site_mode              ENUM('off','shadow','live') NULL,
  outbox_depth           INT UNSIGNED NULL,
  outbox_oldest_age_sec  INT UNSIGNED NULL,
  dead_letters           INT UNSIGNED NULL,
  last_seq               BIGINT UNSIGNED NULL COMMENT 'last change-feed seq the site applied',
  connector_version      VARCHAR(32) NULL,
  remote_ip              VARCHAR(45) NULL,
  payload                JSON NULL,
  PRIMARY KEY (id),
  KEY ix_channel_health_latest (channel_id, id),
  KEY ix_channel_health_received (received_at),
  CONSTRAINT fk_channel_health_channel FOREIGN KEY (channel_id) REFERENCES channel (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='one row per POST /v1/heartbeat (pruned)';

-- ---------------------------------------------------------------------------------------------
-- API idempotency and audit (§3, §11)
-- ---------------------------------------------------------------------------------------------
CREATE TABLE idempotency (
  channel_id       SMALLINT UNSIGNED NOT NULL,
  idem_key         VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  method           VARCHAR(8)   NOT NULL,
  path             VARCHAR(255) NOT NULL,
  request_hash     CHAR(64)     NOT NULL COMMENT 'sha256 of the request body: same key + other body = 422',
  response_status  SMALLINT UNSIGNED NOT NULL,
  response_body    JSON NOT NULL,
  created_at       DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (channel_id, idem_key),
  KEY ix_idempotency_created (created_at),
  CONSTRAINT fk_idempotency_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT ck_idempotency_status CHECK (response_status BETWEEN 100 AND 599)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='stored response per (channel, Idempotency-Key); written in the same transaction as the effect';

CREATE TABLE audit_log (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor          VARCHAR(64)  NOT NULL COMMENT 'channel:<code> | staff:<id> | system:<job>',
  staff_user_id  INT UNSIGNED NULL,
  channel_id     SMALLINT UNSIGNED NULL,
  action         VARCHAR(64)  NOT NULL COMMENT 'e.g. reservation.reserve, listing.map, sku.policy, login.ok',
  entity_type    VARCHAR(32)  NULL,
  entity_id      VARCHAR(64)  NULL,
  idem_key       VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NULL,
  ip             VARCHAR(45)  NULL,
  detail         JSON NULL COMMENT 'before/after or request summary; never secrets',
  created_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY ix_audit_entity (entity_type, entity_id),
  KEY ix_audit_staff (staff_user_id, created_at),
  KEY ix_audit_channel (channel_id, created_at),
  KEY ix_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='append-only (app login: SELECT, INSERT only); no FKs so an audit write never fails';
