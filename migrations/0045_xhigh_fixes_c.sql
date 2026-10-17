-- XC6-XC7: payment membership survives cancellations; non-store sale dates survive recomputation of store sales.
-- Both column changes explicitly copy their tables under a shared lock (no claim of instant DDL on managed MySQL 8.4).
ALTER TABLE reservation_unit
  ADD COLUMN was_paid TINYINT(1) NULL DEFAULT 0
    COMMENT '1 = included in a payment; 0 = never paid; NULL = cancelled legacy unit whose payment membership is unknown',
  ADD CONSTRAINT ck_reservation_unit_paid CHECK (was_paid IS NULL OR was_paid IN (0, 1)),
  ALGORITHM = COPY, LOCK = SHARED;

-- Existing live units are paid; released/held units are not. For cancelled units the ledger proves paid allocations or unpaid holds.
-- A cancelled unlinked legacy unit may have neither: retain its previous reporting behaviour as unknown, rather than guess.
UPDATE reservation_unit u JOIN reservation r ON r.id = u.reservation_id
SET u.was_paid = CASE
  WHEN r.status <> 'committed' OR u.state IN ('held', 'released') THEN 0
  WHEN u.state <> 'cancelled' THEN 1
  WHEN EXISTS (
    SELECT 1 FROM stock_ledger l
    WHERE l.channel_id = u.channel_id AND l.unit_id = u.unit_id AND l.order_ref = r.order_ref
      AND l.bucket = 'allocated' AND l.movement_type IN ('commit', 'adopt', 'cancel')
  ) THEN 1
  WHEN EXISTS (
    SELECT 1 FROM stock_ledger l
    WHERE l.channel_id = u.channel_id AND l.unit_id = u.unit_id AND l.order_ref = r.order_ref
      AND l.bucket = 'held' AND l.movement_type = 'cancel'
  ) THEN 0
  ELSE NULL
END, u.updated_at = u.updated_at;

ALTER TABLE report_item
  ADD COLUMN last_other_sale_on DATE NULL
    COMMENT 'UK day of the last ERP/trade/imported sale, independent of cancellable store sales',
  ALGORITHM = COPY, LOCK = SHARED;

-- These are rebuildable summaries, not source records. Replay their sources to separate previously combined sale dates.
-- No source row or report row is deleted. Until the bounded report job catches up, these figures are incomplete.
UPDATE report_item
SET seq_done = 0, in_units = 0, in_value = 0, first_in_on = NULL, last_sale_on = NULL, last_other_sale_on = NULL;
UPDATE report_refresh SET history_batch_id = 0, sales_through = NULL, ran_at = NULL, note = NULL;
