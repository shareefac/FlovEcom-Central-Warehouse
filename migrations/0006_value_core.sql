-- 0006_value_core.sql — C0 (inventory plan §3): a cost and a document link on stock movements, the
-- per-item value sequence written inside Stock's transaction, and the (empty) value ledger.
-- docs/decisions.md I1-I9. Applied to cw_staging together with the code (install_cron.sh --migrate).

ALTER TABLE stock_ledger
  ADD COLUMN unit_cost DECIMAL(14,6) NULL COMMENT 'GBP per central unit as the caller or document gave it; NULL = valued at average (IM8)' AFTER note,
  ADD COLUMN cost_currency CHAR(3) NULL COMMENT 'GBP whenever unit_cost is set' AFTER unit_cost,
  ADD COLUMN cost_source ENUM('document','manual','estimate') NULL COMMENT 'document: a posted document line; manual: typed by staff; estimate: opening loads' AFTER cost_currency,
  ADD COLUMN document_id BIGINT UNSIGNED NULL COMMENT 'document whose posting booked this row (0008 document.id); no FK (D15)' AFTER cost_source,
  ADD COLUMN document_line INT UNSIGNED NULL COMMENT 'line_no on that document; NULL when summed from several lines (counts)' AFTER document_id,
  ADD KEY ix_stock_ledger_document (document_id),
  ADD CONSTRAINT ck_stock_ledger_cost CHECK (unit_cost IS NULL OR (unit_cost >= 0 AND bucket = 'on_hand'
      AND movement_type IN ('goods_in','supplier_return','adjustment','count','write_off'))),
  ADD CONSTRAINT ck_stock_ledger_cost_meta CHECK ((unit_cost IS NULL) = (cost_currency IS NULL)
      AND (unit_cost IS NULL) = (cost_source IS NULL) AND (cost_currency IS NULL OR cost_currency = 'GBP')),
  ADD CONSTRAINT ck_stock_ledger_document CHECK ((document_line IS NULL OR document_id IS NOT NULL)
      AND (cost_source IS NULL OR cost_source <> 'document' OR document_id IS NOT NULL));

CREATE TABLE stock_value_clock (
  sku_id    INT UNSIGNED NOT NULL,
  last_seq  BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'highest stock_value_seq.seq of the item: rows 1..last_seq exist',
  PRIMARY KEY (sku_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='per-item value clock: its row lock (Stock::flush, sku_id order, before the feed clock) gives each item''s on_hand changes a gap-free seq in commit order';

CREATE TABLE stock_value_seq (
  sku_id           INT UNSIGNED NOT NULL,
  seq              BIGINT UNSIGNED NOT NULL COMMENT '1, 2, 3 ... per item, in commit order, no gaps',
  stock_ledger_id  BIGINT UNSIGNED NOT NULL,
  created_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (sku_id, seq),
  UNIQUE KEY uq_stock_value_seq_ledger (stock_ledger_id),
  CONSTRAINT ck_stock_value_seq_positive CHECK (seq >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='one row per on_hand stock_ledger row: the order IM8 values an item''s movements in (append-only; no FKs, D15)';

CREATE TABLE stock_value_ledger (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  valuation_pool   VARCHAR(64) NOT NULL DEFAULT 'default' COMMENT 'owning company once decision 9 is made: one moving average per (pool, item)',
  sku_id           INT UNSIGNED NOT NULL,
  warehouse_id     SMALLINT UNSIGNED NULL COMMENT 'location of the movement valued; NULL for item-level entries',
  value_seq        BIGINT UNSIGNED NULL COMMENT 'stock_value_seq.seq of the movement valued (kind movement)',
  stock_ledger_id  BIGINT UNSIGNED NULL,
  kind             ENUM('movement','cost_adjust','landed','price_credit','trueup','nrv_reclass','opening','count_reclass') NOT NULL,
  qty_delta        INT NOT NULL DEFAULT 0 COMMENT 'central units; 0 for value-only entries',
  unit_cost        DECIMAL(14,6) NULL COMMENT 'GBP per central unit applied',
  value_delta      DECIMAL(18,6) NOT NULL COMMENT 'GBP',
  qty_after        INT NOT NULL COMMENT 'pool quantity of the item after this entry',
  value_after      DECIMAL(18,6) NOT NULL COMMENT 'pool value of the item after this entry, GBP',
  cost_source      VARCHAR(32) NOT NULL COMMENT 'document, manual, estimate, average, original_cost, allocation, ...',
  document_id      BIGINT UNSIGNED NULL,
  document_line    INT UNSIGNED NULL,
  effective_at     DATETIME(6) NOT NULL COMMENT 'valuation date',
  actor            VARCHAR(64) NOT NULL,
  note             VARCHAR(255) NULL,
  created_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  movement_ledger_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(kind = 'movement', stock_ledger_id, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_value_ledger_movement (movement_ledger_id) COMMENT 'one movement entry per ledger row',
  KEY ix_stock_value_ledger_item (valuation_pool, sku_id, id),
  KEY ix_stock_value_ledger_ledger (stock_ledger_id),
  KEY ix_stock_value_ledger_document (document_id),
  KEY ix_stock_value_ledger_effective (effective_at),
  CONSTRAINT ck_stock_value_ledger_movement CHECK (kind <> 'movement' OR (stock_ledger_id IS NOT NULL AND value_seq IS NOT NULL)),
  CONSTRAINT ck_stock_value_ledger_value_only CHECK (kind = 'movement' OR qty_delta = 0),
  CONSTRAINT ck_stock_value_ledger_cost CHECK (unit_cost IS NULL OR unit_cost >= 0),
  CONSTRAINT ck_stock_value_ledger_document CHECK (document_line IS NULL OR document_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='stock value journal; written ONLY by Valuation.php (IM8); created empty by C0; append-only';

-- Backfill (I4): every on_hand row already booked (8,199 opening adjustments on cw_staging) gets its
-- seq in ledger id order per item, and every item gets a clock row (0 when it has no on_hand rows),
-- so the hot path rarely inserts a clock row. The clock is taken from the seq rows just written, never
-- from a second count of stock_ledger (I28): the migrator runs each statement on its own, so a row booked
-- by old code between the two statements must show up as a row without a seq (invariant 7, which a
-- repair can append) and never as a clock ahead of its seqs (a gap no later booking can fill).
INSERT INTO stock_value_seq (sku_id, seq, stock_ledger_id)
  SELECT sku_id, ROW_NUMBER() OVER (PARTITION BY sku_id ORDER BY id), id FROM stock_ledger WHERE bucket = 'on_hand';
INSERT INTO stock_value_clock (sku_id, last_seq)
  SELECT s.id, COALESCE(MAX(v.seq), 0) FROM sku s LEFT JOIN stock_value_seq v ON v.sku_id = s.id GROUP BY s.id;
