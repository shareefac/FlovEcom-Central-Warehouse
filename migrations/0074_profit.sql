-- 0074_profit.sql — profit on every order, step 1 (CW side; docs/decisions.md PR1-PR30; the owner, 19 Nov 2026: "on each order, I just
-- want to know how much is the percentage of profit actually that we are getting ... you know how much we bought each for, and the rest
-- have an average valuation"). It only ADDS: no existing table, row or screen number changes.
--
--  * order_snapshot: per store and order, the LATEST money-and-parcels snapshot the store sent (PUT /v1/orders/{ref}/snapshot or the bulk
--    files of bin/import_order_snapshots.php), its version and the hash of its content. Only the fields the agreed contract names are kept
--    (CW\Profit\SnapshotBody normalises the body; an unknown field is never stored, so no personal data can ride in on one).
--  * sale_order / sale_line / sale_parcel: what CW works out from the snapshot: per order its money (pence, inc and ex VAT), per shop line
--    (key: store + the shop's line id = the CW unit id) its sale ex VAT and its buying cost fixed at the sale (unit_cost_e4, cost_source,
--    cost_at), and the parcels (a parcel gone from a newer snapshot gets removed_at, never deleted). None of them needs a reservation row:
--    the history before CW has none.
--  * item_cost / item_cost_event: the buying price of each CW item, a moving average that follows the supplier invoices ("invoice price
--    wins", the owner, 15 Oct 2026). Every move is one event row (when, why, the document, old and new), so the cost on a past day can be
--    read. It is separate from report_item, the stock ledger and every stock screen (none of them changes).
--  * profit_day / profit_item_day: the day summaries the Profit screens read (per store and UK day; per item too), kept by the same
--    transaction that stores a snapshot, and rebuilt from sale_line by the batch tools.
--  * profit_job: where the cost job (bin/refresh_item_cost.php) has read up to.
--  * staff_role.role gets `finance` at the END of its list (the stored number of every grant stays what it was); the new permission
--    profit.view is a row of the stored map (role_permission) held by it, with its baseline; nobody holds the job until the owner says who
--    (bin/grant_role.php or Settings > Users).
--  * Five settings (provisional, the owner may change them), each with its version 1 in config_change.
--
-- HOW IT IS APPLIED: CREATE TABLE IF NOT EXISTS (new, empty tables: nothing is copied or locked), the setting rows and the permission's row
-- ON DUPLICATE KEY, their baselines only when missing, one index added to erp_pi_line while it is not there (INPLACE, no lock), and ONE ALTER of staff_role (tiny: a few dozen rows; ALGORITHM = COPY, LOCK = SHARED, as 0069). Every
-- statement can run again and changes nothing the second time, so a run stopped half way runs again cleanly.

CREATE TABLE IF NOT EXISTS order_snapshot (
  channel_id    SMALLINT UNSIGNED NOT NULL,
  order_ref     VARCHAR(64)     NOT NULL COMMENT 'the shop''s order id (ord_id), the same order_ref the reservations use',
  version       BIGINT UNSIGNED NOT NULL COMMENT 'the shop''s version of this snapshot: an older one is ignored',
  body_hash     CHAR(64)        NOT NULL COMMENT 'sha256 of the normalised content without the version: the same content again is "same"',
  body          JSON            NOT NULL COMMENT 'the normalised snapshot (only the contract''s fields; never personal data)',
  source        ENUM('api','bulk') NOT NULL COMMENT 'api: PUT /v1/orders/{ref}/snapshot; bulk: bin/import_order_snapshots.php',
  first_at      DATETIME(6)     NOT NULL COMMENT 'when CW first stored a snapshot of this order',
  stored_at     DATETIME(6)     NOT NULL COMMENT 'when the content in force was stored',
  PRIMARY KEY (channel_id, order_ref),
  KEY ix_order_snapshot_stored (stored_at),
  CONSTRAINT fk_order_snapshot_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT ck_order_snapshot_hash CHECK (REGEXP_LIKE(body_hash, '^[0-9a-f]{64}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the latest money-and-parcels snapshot of each shop order (profit step 1); replaced by a newer one, never deleted';

CREATE TABLE IF NOT EXISTS sale_order (
  channel_id            SMALLINT UNSIGNED NOT NULL,
  order_ref             VARCHAR(64)  NOT NULL,
  order_no              VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'the reference the customer sees (ord_refrence_no)',
  status                VARCHAR(32)  NOT NULL DEFAULT '' COMMENT 'the shop''s order status, lower case, as sent',
  placed_at             DATETIME(6)  NULL,
  paid_at               DATETIME(6)  NULL COMMENT 'NULL: not paid: the order counts nothing yet',
  day                   DATE         NULL COMMENT 'UK day of paid_at: the day the order counts on',
  gateway               VARCHAR(32)  NOT NULL DEFAULT '',
  currency              CHAR(3)      NOT NULL DEFAULT 'GBP',
  parent_ref            VARCHAR(64)  NULL COMMENT 'a re-send: the order it is a re-send of (its cost belongs to that order)',
  sub_of                VARCHAR(64)  NULL COMMENT 'an office sub order: the order it was added to (its own sale; shown as a link)',
  vat_bp                SMALLINT UNSIGNED NOT NULL COMMENT 'VAT rate in hundredths of a percent (2000 = 20%), fixed when the order was first stored',
  line_count            INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'shop lines in the snapshot',
  units                 BIGINT       NOT NULL DEFAULT 0 COMMENT 'CW units that count (not cancelled)',
  goods_inc             BIGINT       NOT NULL DEFAULT 0 COMMENT 'pence inc VAT: every line''s price before discount',
  discount_inc          BIGINT       NOT NULL DEFAULT 0,
  line_refund_inc       BIGINT       NOT NULL DEFAULT 0,
  order_refund_inc      BIGINT       NOT NULL DEFAULT 0 COMMENT 'refunds the shop holds at order level, not on a line',
  sale_inc              BIGINT       NOT NULL DEFAULT 0 COMMENT 'pence inc VAT the goods count for (the lines less the order-level refund)',
  sale_ex               BIGINT       NOT NULL DEFAULT 0 COMMENT 'pence ex VAT: goods sold',
  order_refund_ex       BIGINT       NOT NULL DEFAULT 0,
  cost                  BIGINT       NOT NULL DEFAULT 0 COMMENT 'pence: the buying cost of the units that count',
  units_no_cost         BIGINT       NOT NULL DEFAULT 0 COMMENT 'units that count and have no buying price',
  delivery_method_id    VARCHAR(64)  NULL,
  delivery_method       VARCHAR(128) NULL,
  delivery_charged_inc  BIGINT       NOT NULL DEFAULT 0,
  delivery_refund_inc   BIGINT       NOT NULL DEFAULT 0,
  delivery_ex           BIGINT       NOT NULL DEFAULT 0 COMMENT 'pence ex VAT: delivery charged less refunded (income shown on its own line)',
  grand_inc             BIGINT       NOT NULL DEFAULT 0 COMMENT 'what the customer was asked to pay',
  resends               INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'paid re-sends of this order',
  resend_cost           BIGINT       NOT NULL DEFAULT 0 COMMENT 'pence: their cost, which belongs to this order',
  margin_bp             BIGINT       NULL COMMENT '(sale_ex - cost - resend_cost) / sale_ex in hundredths of a percent; NULL: no sale',
  snapshot_version      BIGINT UNSIGNED NOT NULL,
  first_at              DATETIME(6)  NOT NULL,
  updated_at            DATETIME(6)  NOT NULL,
  PRIMARY KEY (channel_id, order_ref),
  KEY ix_sale_order_day (day, channel_id),
  KEY ix_sale_order_no (order_no),
  KEY ix_sale_order_parent (channel_id, parent_ref),
  KEY ix_sale_order_paid (paid_at, channel_id, order_ref) COMMENT 'Profit by order, newest first (keyset pages)',
  KEY ix_sale_order_store_paid (channel_id, paid_at, order_ref) COMMENT 'the same for one store',
  KEY ix_sale_order_margin (margin_bp, channel_id, order_ref) COMMENT 'Profit by order, the lowest margin first (keyset pages)',
  CONSTRAINT fk_sale_order_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT ck_sale_order_paid CHECK ((paid_at IS NULL) = (day IS NULL)),
  CONSTRAINT ck_sale_order_vat CHECK (vat_bp <= 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='per shop order: its money ex VAT and its buying cost (profit step 1); worked out from order_snapshot; never deleted';

CREATE TABLE IF NOT EXISTS sale_line (
  channel_id      SMALLINT UNSIGNED NOT NULL,
  line_id         VARCHAR(64)  NOT NULL COMMENT 'the shop''s line id (ordi_id) = the CW unit id',
  order_ref       VARCHAR(64)  NOT NULL,
  variant_id      VARCHAR(64)  NOT NULL,
  listing_id      INT UNSIGNED NULL COMMENT 'channel_listing.id when known (no FK)',
  sku_id          INT UNSIGNED NULL COMMENT 'the CW item: the unit''s own at the sale (reservation_unit), else the listing''s link; NULL: none',
  units_per_item  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  qty             INT          NOT NULL,
  units           BIGINT       NOT NULL DEFAULT 0 COMMENT 'CW units that count: qty x units_per_item, 0 when the line counts nothing',
  price_inc       BIGINT       NOT NULL COMMENT 'pence inc VAT before discount (all qty)',
  discount_inc    BIGINT       NOT NULL DEFAULT 0,
  refund_inc      BIGINT       NOT NULL DEFAULT 0,
  cancelled       TINYINT(1)   NOT NULL DEFAULT 0,
  is_free         TINYINT(1)   NOT NULL DEFAULT 0,
  restocked       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1: the unit came back to stock in CW (a return): no cost, and no sale when refunded',
  sale_inc        BIGINT       NOT NULL DEFAULT 0,
  sale_ex         BIGINT       NOT NULL DEFAULT 0,
  unit_cost_e4    BIGINT       NULL COMMENT 'buying price of one CW unit, GBP x 10,000, fixed at the sale',
  cost            BIGINT       NOT NULL DEFAULT 0 COMMENT 'pence: units x unit_cost_e4 (0 when it counts nothing)',
  cost_source     ENUM('erp_at_sale','cw_cost','estimate','none') NULL COMMENT 'where the cost came from; NULL: not fixed yet (not paid, or the cost job has not reached its paid time)',
  costed_by       ENUM('sale','erp_import','recost') NULL COMMENT 'what fixed it: the sale, the ERPNext history import, a deliberate re-cost',
  cost_at         DATETIME(6)  NULL COMMENT 'when the cost was fixed',
  day             DATE         NULL COMMENT 'the order''s day (sale_order.day)',
  removed_at      DATETIME(6)  NULL COMMENT 'gone from a newer snapshot: counts nothing, kept',
  updated_at      DATETIME(6)  NOT NULL,
  PRIMARY KEY (channel_id, line_id),
  KEY ix_sale_line_order (channel_id, order_ref),
  KEY ix_sale_line_item (sku_id, day),
  KEY ix_sale_line_cost (cost_source, day) COMMENT 'the No buying price list; the lines waiting for the cost job (NULL, a day)',
  CONSTRAINT fk_sale_line_order FOREIGN KEY (channel_id, order_ref) REFERENCES sale_order (channel_id, order_ref),
  CONSTRAINT ck_sale_line_flags CHECK (cancelled IN (0, 1) AND is_free IN (0, 1) AND restocked IN (0, 1)),
  CONSTRAINT ck_sale_line_qty CHECK (qty >= 1 AND units >= 0 AND units_per_item >= 1),
  CONSTRAINT ck_sale_line_cost CHECK ((cost_source IS NULL) = (cost_at IS NULL) AND (cost_source IS NULL) = (costed_by IS NULL)
    AND (unit_cost_e4 IS NULL OR unit_cost_e4 >= 0) AND (cost_source IS NULL OR cost_source = 'none' OR unit_cost_e4 IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='per shop order line: sale ex VAT and the buying cost fixed at the sale (profit step 1); never deleted';

CREATE TABLE IF NOT EXISTS sale_parcel (
  channel_id    SMALLINT UNSIGNED NOT NULL,
  order_ref     VARCHAR(64)  NOT NULL,
  parcel_ref    VARCHAR(64)  NOT NULL COMMENT 'the shop''s own key of the label (odl:<id>, ...)',
  tracking_no   VARCHAR(64)  NULL,
  carrier       VARCHAR(32)  NOT NULL DEFAULT '',
  service_code  VARCHAR(64)  NOT NULL DEFAULT '',
  service       VARCHAR(128) NOT NULL DEFAULT '',
  status        VARCHAR(32)  NOT NULL DEFAULT '',
  label_at      DATETIME(6)  NULL,
  shipped_at    DATETIME(6)  NULL,
  is_manual     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'a label made by hand',
  is_partial    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'part of the order',
  first_at      DATETIME(6)  NOT NULL,
  updated_at    DATETIME(6)  NOT NULL,
  removed_at    DATETIME(6)  NULL COMMENT 'gone from a newer snapshot: kept, never deleted',
  PRIMARY KEY (channel_id, order_ref, parcel_ref),
  KEY ix_sale_parcel_tracking (tracking_no),
  KEY ix_sale_parcel_label (label_at),
  CONSTRAINT fk_sale_parcel_order FOREIGN KEY (channel_id, order_ref) REFERENCES sale_order (channel_id, order_ref),
  CONSTRAINT ck_sale_parcel_flags CHECK (is_manual IN (0, 1) AND is_partial IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the parcels (labels) of each shop order, for the delivery cost of step 2; never deleted';

CREATE TABLE IF NOT EXISTS item_cost (
  sku_id         INT UNSIGNED NOT NULL,
  unit_cost_e4   BIGINT       NULL COMMENT 'the buying price of one unit now, GBP x 10,000; NULL: none known',
  start_source   ENUM('erp_bin','cw_average','supplier_price','none') NOT NULL COMMENT 'where its starting price came from',
  started_at     DATETIME(6)  NOT NULL,
  last_event_id  BIGINT UNSIGNED NULL,
  updated_at     DATETIME(6)  NOT NULL,
  PRIMARY KEY (sku_id),
  CONSTRAINT ck_item_cost_value CHECK (unit_cost_e4 IS NULL OR unit_cost_e4 >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the buying price of each CW item (moving average, invoice price wins); written only by the profit cost job';

CREATE TABLE IF NOT EXISTS item_cost_event (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku_id           INT UNSIGNED    NOT NULL,
  at               DATETIME(6)     NOT NULL COMMENT 'when the price became this (never before the item''s previous event)',
  source_at        DATETIME(6)     NOT NULL COMMENT 'the time of what it was worked out from (the booking, the posting): the order a replay folds in',
  kind             ENUM('start','receipt','receipt_reversal','invoice','invoice_reversal','credit_note','credit_note_reversal','replay') NOT NULL
                   COMMENT 'replay: the item''s history folded again in source order after an event that came late',
  source_key       VARCHAR(96)     NOT NULL COMMENT 'what it was worked out from (start:<item>, ledger:<id>, doc:<id>:<line>:<item>): each once',
  document_id      BIGINT UNSIGNED NULL COMMENT 'the delivery, invoice or credit note (or its reversal)',
  document_line    INT UNSIGNED    NULL,
  doc_ref          VARCHAR(64)     NULL COMMENT 'its number, as shown',
  ref_document_id  BIGINT UNSIGNED NULL COMMENT 'the delivery an invoice line bills, the invoice whose price a delivery took, or the document a reversal undoes',
  ref_line         INT UNSIGNED    NULL,
  units            INT             NOT NULL DEFAULT 0 COMMENT 'the units it moved the price of',
  on_hand          INT             NULL COMMENT 'units on hand just before (every warehouse of ours)',
  price_e4         BIGINT          NULL COMMENT 'the unit price it applied (a delivery''s, an invoice''s landed price, a credit''s)',
  booked_e4        BIGINT          NULL COMMENT 'the unit price those units had before it (an invoice); a credit: what it takes off a unit',
  deferred         TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1: a credit of goods still on their way: it moves nothing now, their receipt takes it in',
  undoes_event_id  BIGINT UNSIGNED NULL COMMENT 'a cancellation: the event it undoes (its effect is read from the folded history each time)',
  late             TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1: written after an event of a later source time (it came late): the prices stored with those events are out of date from it on',
  old_cost_e4      BIGINT          NULL,
  new_cost_e4      BIGINT          NULL,
  note             VARCHAR(255)    NULL,
  created_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_cost_event_source (source_key),
  KEY ix_item_cost_event_item (sku_id, at, id),
  KEY ix_item_cost_event_source (sku_id, source_at, id),
  KEY ix_item_cost_event_document (document_id, document_line),
  KEY ix_item_cost_event_ref (ref_document_id, ref_line),
  KEY ix_item_cost_event_late (sku_id, late, source_at, id) COMMENT 'the price at a moment: whether a late event changed it, from the late events only',
  CONSTRAINT ck_item_cost_event_deferred CHECK (deferred IN (0, 1) AND late IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='every move of an item''s buying price (profit step 1): the cost on a past day is read from here; append-only';

CREATE TABLE IF NOT EXISTS item_cost_document (
  document_id  BIGINT UNSIGNED NOT NULL COMMENT 'a goods receipt, supplier invoice or credit note (or a cancellation of one) the cost job has read whole',
  read_at      DATETIME(6)     NOT NULL,
  PRIMARY KEY (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the documents the cost job has read whole (also those that move no price): a booking that committed late is found by what is not here';

CREATE TABLE IF NOT EXISTS profit_day (
  channel_id       SMALLINT UNSIGNED NOT NULL,
  day              DATE     NOT NULL COMMENT 'UK day the orders were paid',
  orders           INT      NOT NULL DEFAULT 0 COMMENT 'paid orders that are not re-sends',
  resends          INT      NOT NULL DEFAULT 0,
  units            BIGINT   NOT NULL DEFAULT 0,
  sale_ex          BIGINT   NOT NULL DEFAULT 0 COMMENT 'pence ex VAT, the lines (before the order-level refunds)',
  order_refund_ex  BIGINT   NOT NULL DEFAULT 0,
  cost             BIGINT   NOT NULL DEFAULT 0,
  units_no_cost    BIGINT   NOT NULL DEFAULT 0,
  delivery_ex      BIGINT   NOT NULL DEFAULT 0,
  PRIMARY KEY (day, channel_id),
  KEY ix_profit_day_channel (channel_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='per store and UK day: the Profit screens'' summary (kept with each snapshot; rebuilt from sale_line by the batch tools)';

CREATE TABLE IF NOT EXISTS profit_item_day (
  channel_id     SMALLINT UNSIGNED NOT NULL,
  day            DATE         NOT NULL,
  sku_id         INT UNSIGNED NOT NULL COMMENT '0: lines with no CW item',
  units          BIGINT       NOT NULL DEFAULT 0,
  sale_ex        BIGINT       NOT NULL DEFAULT 0,
  cost           BIGINT       NOT NULL DEFAULT 0,
  units_no_cost  BIGINT       NOT NULL DEFAULT 0,
  PRIMARY KEY (day, channel_id, sku_id),
  KEY ix_profit_item_day_item (sku_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='per store, UK day and item: what the by-product, by-brand and by-supplier lists add up';

CREATE TABLE IF NOT EXISTS profit_job (
  job         VARCHAR(32)  NOT NULL,
  mark_at     DATETIME(6)  NULL COMMENT 'everything up to this time is done',
  floor_at    DATETIME(6)  NULL COMMENT 'the cost job never reads before this (the start of the buying prices)',
  ran_at      DATETIME(6)  NULL,
  summary     JSON         NULL,
  PRIMARY KEY (job)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='how far the profit jobs have read (the cost job, the returns sweep)';

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('profit.vat_rate_pct','decimal','"20"',1,'PR4','VAT in the shops'' prices, in percent: goods and delivery ex VAT = price / (1 + rate). Kept on each order when it is first stored'),
 ('profit.cost_start','date','"2026-11-16"',1,'PR12','The day CW''s own buying price starts (ERPNext''s valuation on that day, then CW''s deliveries and invoices); sales before it take ERPNext''s cost at the sale'),
 ('profit.erp_history_from','date','"2025-12-15"',1,'PR20','The first day ERPNext holds the cost at each sale; before it no cost history exists'),
 ('profit.before_history','string','"estimate"',1,'PR21','Sales before ERPNext''s history: estimate (today''s buying price, marked as an estimate) or none (no buying price)'),
 ('profit.cost_refresh_on','bool','true',1,'PR15','The cost job (every 10 minutes) follows new deliveries, invoices and credit notes into the buying price')
 ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key IN ('profit.vat_rate_pct', 'profit.cost_start', 'profit.erp_history_from', 'profit.before_history',
    'profit.cost_refresh_on')
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);

-- The new permission in the stored map (pack E, 0034), with its baseline, as 0038 added its own (docs/decisions.md PR64): held by Finance only.
-- The two LOCKED permissions every job has (catalogue.view, reference.view) keep 0034's rows: the code gives them to Finance whatever a row
-- says (as 0069 left them for the People manager). Run again: the row as it is then (a holder given on the roles screen stays), no second
-- baseline.
INSERT INTO role_permission (permission, roles, updated_actor) VALUES ('profit.view', 'finance', 'system:migrate')
  ON DUPLICATE KEY UPDATE permission = permission;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'permission', p.permission, 1, 'baseline', JSON_OBJECT('roles', p.roles), 'system:migrate'
  FROM role_permission p WHERE p.permission = 'profit.view'
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'permission' AND c.subject_key = p.permission);

-- The supplier's last price of the items asked for (CostEngine::supplierPrices: the start, and each item made after it) reads their own ERPNext
-- purchase lines, never the whole copy (the Codex re-check of 6b8a7ee, N10; docs/decisions.md PR71): one index on erp_pi_line, ALGORITHM =
-- INPLACE, LOCK = NONE (the copy job goes on while it builds), added only while it is not there (a prepared statement, as 0056). No row changes.
SET @cw_0074_erp_pi_line_sku = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'erp_pi_line'
    AND index_name = 'ix_erp_pi_line_sku') = 0,
  'ALTER TABLE erp_pi_line ADD INDEX ix_erp_pi_line_sku (sku_id, erp_pi_id, idx), ALGORITHM = INPLACE, LOCK = NONE',
  'DO 0');
PREPARE cw_0074_erp_pi_line_sku FROM @cw_0074_erp_pi_line_sku;
EXECUTE cw_0074_erp_pi_line_sku;
DEALLOCATE PREPARE cw_0074_erp_pi_line_sku;

ALTER TABLE staff_role MODIFY COLUMN role ENUM('viewer','mapper','mapping_lead','warehouse','manager','admin','buyer','purchasing_manager',
    'goods_in','purchasing_desk','stock_controller','reviewer','accountant','auditor','people_manager','finance') NOT NULL,
  ALGORITHM = COPY, LOCK = SHARED;
