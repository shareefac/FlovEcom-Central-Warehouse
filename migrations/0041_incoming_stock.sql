-- 0041_incoming_stock.sql — pack J, the owner's day-one purchasing workflow (docs/decisions.md PJ1-PJ16; the owner, 15 Oct 2026: a
-- purchase placed today usually arrives the next day, and they want it on the websites the same day, so customers can order overnight
-- and the orders go out when the stock lands; the purchase invoice is booked now and made final at a time they choose; something
-- missing on arrival is claimed; when they are sure of the quantity they skip the count).
--
--  * stock_balance.incoming: a fourth bucket, the units of final supplier invoices still on their way that the websites may sell
--    (Stock.php stays the only writer; available = on_hand + incoming - allocated - held; journalled in stock_ledger like every
--    bucket, never below zero). stock_balance is copied ONCE, on purpose and said out loud (0027's lesson: this server rebuilds the
--    table for an "instant" ADD COLUMN all the same): ONE statement, ALGORITHM = COPY, LOCK = SHARED, the column and its CHECK in
--    one pass. While it runs every read of stock_balance goes on; a stock write (a sale, a movement) waits until it ends.
--  * stock_ledger.bucket gains 'incoming' at the END of its list. This server will not change it in place: the column is named by two
--    CHECKs (ck_stock_ledger_nonneg, ck_stock_ledger_cost), and MySQL 8.4 refuses INPLACE / INSTANT for a column a CHECK names, and
--    re-adding an enforced CHECK copies the table too (probed on the test server, 15 Oct 2026: /root/cw_work/runs/packj_scratch/).
--    So the ledger is copied ONCE, on purpose and said out loud: ALGORITHM = COPY, LOCK = SHARED (reads go on, a stock write waits).
--    Measured on a ledger-shaped table of the test server: 7.6 s for 200,000 rows, 41.5 s for 1,000,000 (about 40 microseconds a
--    row). Applied before go-live, while the ledger is small (the opening and the test traffic), it is a few seconds; applied later,
--    it is a pause of the shops' stock writes of that length: apply it out of trading hours (docs/decisions.md PJ1).
--  * supplier_invoice: how an invoice's goods not received yet come in when it is made final (receive_mode: count = "count on
--    arrival", they wait in Incoming for Goods In; received = "book as received", a receipt is booked at once), and when it is made
--    final (submit_at, set by a person, or "now"; submit_by: whose posting it is; a failed attempt is kept with its reason and not
--    tried again until the invoice or its time is changed; submit_receipt_id: the receipt "book as received" made, so a retry never
--    books it twice).
--  * sinv_line.stamp_answer: "book as received" asks the duty stamp on the invoice itself (the receipt's bench is skipped).
--  * invoice_arrival: one row per goods line of a final invoice that came in "count on arrival": what is on its way, whether its units
--    were put in Incoming (the supplier's "sell before it arrives"), and the receipt that took them in (or cancelled).
--  * goods_receipt_invoice: the receipt made from an invoice (Goods In's "Receive", or "book as received").
--  * supplier_arrival_pref: a supplier's own choices (sell before it arrives; the default way its invoices come in), else the settings.
--  * Three settings (provisional, build decisions put to the owner): when an arriving invoice is late (hours), and the two defaults of
--    a supplier without its own choice. Each with its version 1 in config_change (docs/dev.md rule 5).

ALTER TABLE stock_balance
  ADD COLUMN incoming INT NOT NULL DEFAULT 0 COMMENT 'units of final supplier invoices on their way that the sites may sell (pack J); never negative' AFTER held,
  ADD CONSTRAINT ck_stock_balance_incoming CHECK (incoming >= 0),
  COMMENT = 'cached bucket sums; available = on_hand + incoming - allocated - held',
  ALGORITHM = COPY, LOCK = SHARED;

ALTER TABLE stock_ledger
  MODIFY COLUMN bucket ENUM('on_hand','allocated','held','incoming') NOT NULL,
  ALGORITHM = COPY, LOCK = SHARED;

ALTER TABLE supplier_invoice
  ADD COLUMN receive_mode ENUM('count','received') NULL COMMENT 'an invoice: how its goods not received yet come in when it is made final (pack J); NULL: before 0041' AFTER debit_note_document_id,
  ADD COLUMN submit_at DATETIME(6) NULL COMMENT 'UTC: when the job makes this draft final (set by a person); NULL once final or stopped' AFTER receive_mode,
  ADD COLUMN submit_by INT UNSIGNED NULL COMMENT 'whose posting the timed one is (they set the time)' AFTER submit_at,
  ADD COLUMN submit_actor VARCHAR(64) NULL AFTER submit_by,
  ADD COLUMN submit_failed_at DATETIME(6) NULL COMMENT 'the timed posting failed (not tried again until the invoice or its time changes)' AFTER submit_actor,
  ADD COLUMN submit_error VARCHAR(500) NULL AFTER submit_failed_at,
  ADD COLUMN submit_receipt_id BIGINT UNSIGNED NULL COMMENT '"book as received": the receipt it booked (a retry posts the invoice, never a second receipt)' AFTER submit_error,
  ADD KEY ix_supplier_invoice_submit (submit_at),
  ADD KEY ix_supplier_invoice_submit_by (submit_by),
  ADD KEY ix_supplier_invoice_submit_receipt (submit_receipt_id),
  ADD CONSTRAINT fk_supplier_invoice_submit_by FOREIGN KEY (submit_by) REFERENCES staff_user (id),
  ADD CONSTRAINT fk_supplier_invoice_submit_receipt FOREIGN KEY (submit_receipt_id) REFERENCES document (id),
  ADD CONSTRAINT ck_supplier_invoice_submit CHECK ((submit_at IS NULL) = (submit_by IS NULL) AND (submit_by IS NULL) = (submit_actor IS NULL)
    AND (submit_failed_at IS NULL) = (submit_error IS NULL) AND (submit_failed_at IS NULL OR submit_at IS NOT NULL)
    AND (receive_mode IS NULL OR kind = 'invoice') AND (submit_receipt_id IS NULL OR receive_mode = 'received'));

ALTER TABLE sinv_line
  ADD COLUMN stamp_answer ENUM('digital','transitional') NULL COMMENT '"book as received": the duty stamp on the outer retail pack of a duty-liable line' AFTER entry;

CREATE TABLE invoice_arrival (
  sinv_document_id  BIGINT UNSIGNED NOT NULL,
  line_no           INT UNSIGNED    NOT NULL,
  sku_id            INT UNSIGNED    NOT NULL,
  warehouse_id      SMALLINT UNSIGNED NOT NULL COMMENT 'where it will be received (MAIN)',
  units             INT UNSIGNED    NOT NULL COMMENT 'the line''s units (central) on their way',
  sellable          TINYINT(1)      NOT NULL COMMENT '1: put in Incoming (the supplier sells before it arrives); 0: shown here only',
  state             ENUM('open','received','cancelled') NOT NULL DEFAULT 'open',
  grn_document_id   BIGINT UNSIGNED NULL COMMENT 'received: the receipt that took it in',
  grn_line_no       INT UNSIGNED    NULL COMMENT 'received: that receipt''s line of the same item (NULL: the item did not arrive at all)',
  created_at        DATETIME(6)     NOT NULL,
  closed_at         DATETIME(6)     NULL,
  PRIMARY KEY (sinv_document_id, line_no),
  KEY ix_invoice_arrival_open (state, sinv_document_id),
  KEY ix_invoice_arrival_item (sku_id, state),
  KEY ix_invoice_arrival_grn (grn_document_id, grn_line_no),
  KEY ix_invoice_arrival_warehouse (warehouse_id),
  CONSTRAINT fk_invoice_arrival_line FOREIGN KEY (sinv_document_id, line_no) REFERENCES sinv_line (document_id, line_no),
  CONSTRAINT fk_invoice_arrival_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_invoice_arrival_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouse (id),
  CONSTRAINT fk_invoice_arrival_grn FOREIGN KEY (grn_document_id) REFERENCES document (id),
  CONSTRAINT ck_invoice_arrival_state CHECK (units > 0 AND sellable IN (0, 1) AND (state = 'open') = (closed_at IS NULL)
    AND (grn_document_id IS NULL OR state = 'received') AND (grn_line_no IS NULL OR grn_document_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the goods of a final supplier invoice on their way ("count on arrival", pack J); never deleted';

CREATE TABLE goods_receipt_invoice (
  grn_document_id   BIGINT UNSIGNED NOT NULL,
  sinv_document_id  BIGINT UNSIGNED NOT NULL,
  auto              TINYINT(1)      NOT NULL COMMENT '1: booked by "book as received" (no count); 0: Goods In received it',
  created_at        DATETIME(6)     NOT NULL,
  PRIMARY KEY (grn_document_id),
  KEY ix_goods_receipt_invoice_sinv (sinv_document_id),
  CONSTRAINT fk_goods_receipt_invoice_grn FOREIGN KEY (grn_document_id) REFERENCES document (id),
  CONSTRAINT fk_goods_receipt_invoice_sinv FOREIGN KEY (sinv_document_id) REFERENCES document (id),
  CONSTRAINT ck_goods_receipt_invoice_auto CHECK (auto IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a receipt made from a supplier invoice (pack J); append-only';

CREATE TABLE supplier_arrival_pref (
  supplier_id          INT UNSIGNED  NOT NULL,
  sell_before_arrival  TINYINT(1)    NOT NULL COMMENT '1: its goods on a final invoice are sold on the websites before they arrive',
  default_mode         ENUM('count','received') NOT NULL COMMENT 'how its invoices come in unless the person chooses otherwise',
  updated_by           INT UNSIGNED  NULL,
  updated_actor        VARCHAR(64)   NOT NULL,
  updated_at           DATETIME(6)   NOT NULL,
  PRIMARY KEY (supplier_id),
  KEY ix_supplier_arrival_pref_by (updated_by),
  CONSTRAINT fk_supplier_arrival_pref_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id),
  CONSTRAINT fk_supplier_arrival_pref_by FOREIGN KEY (updated_by) REFERENCES staff_user (id),
  CONSTRAINT ck_supplier_arrival_pref_flag CHECK (sell_before_arrival IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a supplier''s own arrival choices (pack J); changed with audit, never deleted';

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('arrivals.late_hours','int','24',1,'PJ12','An invoice''s goods still on their way this many hours after it was made final are late on Goods In > Arriving'),
 ('arrivals.sell_before_default','bool','true',1,'PJ5','A supplier without its own choice: its goods on a final invoice are sold on the websites before they arrive'),
 ('arrivals.default_mode','string','"count"',1,'PJ6','A supplier without its own choice: how its invoices come in: count (count on arrival) or received (book as received)');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('arrivals.late_hours', 'arrivals.sell_before_default', 'arrivals.default_mode');
