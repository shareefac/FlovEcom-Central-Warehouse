-- 0003_review_fixes.sql — schema changes from the stock-core review (docs/decisions.md R1, R12, R17).

-- ---------------------------------------------------------------------------------------------
-- R1 / R12: the opening-orders marker and the T0 watermarks (§8.1) move off the channel row.
-- Every site transaction holds an S lock on its own channel row from its first statement (the FK
-- checks of the idempotency claim and of reservation inserts). A transaction that X-locks the
-- channel row after other locks (the final opening batch did, after the feed clock) therefore
-- deadlocks with the site's own stock writes. channel_opening is referenced by nothing and locked
-- only by POST /v1/opening_orders of that channel, first thing after the idempotency claim.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE channel_opening (
  channel_id            SMALLINT UNSIGNED NOT NULL,
  t0_at                 DATETIME(6) NULL COMMENT 'moment the site entered shadow (§8.1)',
  t0_last_order_id      BIGINT UNSIGNED NULL COMMENT 'highest completed ord_id at T0',
  t0_last_stock_log_id  BIGINT UNSIGNED NULL COMMENT 'highest api_stock_update_logs id at T0; NULL = the site has no ERP stock feed',
  opening_orders_at     DATETIME(6) NULL COMMENT 'when the final POST /v1/opening_orders was accepted',
  created_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (channel_id),
  CONSTRAINT fk_channel_opening_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT ck_channel_opening_t0 CHECK ((t0_at IS NULL) = (t0_last_order_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='per-site T0 watermarks + opening-orders marker; locked only by opening_orders (R1)';

INSERT INTO channel_opening (channel_id, t0_at, t0_last_order_id, t0_last_stock_log_id, opening_orders_at)
  SELECT id, IF(t0_last_order_id IS NULL, NULL, t0_at), IF(t0_at IS NULL, NULL, t0_last_order_id),
         IF(t0_at IS NULL OR t0_last_order_id IS NULL, NULL, t0_last_stock_log_id), opening_orders_at
  FROM channel
  WHERE (t0_at IS NOT NULL AND t0_last_order_id IS NOT NULL) OR opening_orders_at IS NOT NULL;

ALTER TABLE channel
  DROP COLUMN t0_at,
  DROP COLUMN t0_last_order_id,
  DROP COLUMN t0_last_stock_log_id,
  DROP COLUMN opening_orders_at;

-- ---------------------------------------------------------------------------------------------
-- R17: least privilege for POST /v1/movements. A site may send only the ERP-relay movement types
-- listed here (a subset of goods_in, supplier_return, erp_sale). The default is none: fail closed.
-- Counts, adjustments, write-offs and transfers are staff-only.
-- ---------------------------------------------------------------------------------------------
ALTER TABLE channel
  ADD COLUMN movement_types JSON NOT NULL DEFAULT (JSON_ARRAY())
    COMMENT 'movement types this site may send (ERP relay only); empty = none' AFTER reserve_ttl_sec,
  ADD CONSTRAINT ck_channel_movement_types CHECK (JSON_TYPE(movement_types) = 'ARRAY');
