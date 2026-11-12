-- 0066_stock_doubt.sql — Doubtful stock (the owner, 12 Nov 2026 ~05:45 UK, CW chat: "create a module in central warehouse for doubt and
-- clear ... only there need doubted product only"; docs/decisions.md DS1-). Today CW listens to the Vape and Go shop; tomorrow it writes the
-- shop's stock. Some items have a doubtful figure (CW and the shop differ, or CW holds them below zero). Staff check each one on the shelf and
-- clear it, one item at a time, on Inventory > Doubtful stock, a page that shows ONLY those items.
--
--  * stock_doubt: one row per store and item to check, put there by bin/doubt_load.php from a list (a CSV) the owner's team made: why
--    (CW and the shop differ, CW below zero, other), the shop's figure and CW's figure when the list was made, the units sold in 30 days, the
--    list's name (batch) and its note. A row is open until a person answers it, once:
--      cleared    "Save count": the shelf figure is booked as a stock count of that ONE item at the store's selling warehouse through the
--                 counts engine (a one-line count sheet, posted), and the row keeps what was counted, what it booked and the count's record;
--      dismissed  "Not needed": nothing booked, with the person's note.
--    At most ONE open row per store and item: the loader refreshes an open row (never a second one), and the database refuses a second
--    (UNIQUE on the store and `open_sku_id`, the item while the row is open: the pattern of 0004's open proposal per listing). Answered rows
--    are never removed (Grants::NO_DELETE, like the stores' queues).
--
-- HOW IT IS APPLIED: one new table; nothing else is changed, no row is written. The code before this file never names it.

CREATE TABLE stock_doubt (
  id                 BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  channel_id         SMALLINT UNSIGNED NOT NULL COMMENT 'the store whose figure is in doubt',
  sku_id             INT UNSIGNED      NOT NULL,
  reason             ENUM('differs','below_zero','other') NOT NULL COMMENT 'why: CW and the shop differ, CW below zero, another reason (the note says)',
  shop_qty           INT               NULL COMMENT 'the shop''s figure when the list was made (none: not known)',
  cw_qty             INT               NOT NULL COMMENT 'CW''s figure when the list was made',
  sold_30d           INT               NULL COMMENT 'units sold in the 30 days before the list',
  batch              VARCHAR(64)       NOT NULL COMMENT 'the name of the list it came from (the last load that refreshed it)',
  note               VARCHAR(500)      NULL COMMENT 'the list''s note',
  status             ENUM('open','cleared','dismissed') NOT NULL DEFAULT 'open',
  counted_qty        INT               NULL COMMENT 'cleared: the units counted on the shelf',
  booked_delta       INT               NULL COMMENT 'cleared: what the count changed (counted minus what CW held)',
  count_document_id  BIGINT UNSIGNED   NULL COMMENT 'cleared: the count record (CNT) that booked it',
  resolved_by        INT UNSIGNED      NULL,
  resolved_at        DATETIME(6)       NULL,
  resolved_note      VARCHAR(500)      NULL COMMENT 'dismissed: why it is not needed',
  created_at         DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at         DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  open_sku_id        INT UNSIGNED      GENERATED ALWAYS AS (IF(status = 'open', sku_id, NULL)) STORED COMMENT 'at most one open row per store and item',
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_doubt_open (channel_id, open_sku_id),
  KEY ix_stock_doubt_list (channel_id, status, resolved_at),
  KEY ix_stock_doubt_status (status),
  KEY ix_stock_doubt_sku (sku_id),
  KEY ix_stock_doubt_document (count_document_id),
  KEY ix_stock_doubt_resolver (resolved_by),
  CONSTRAINT fk_stock_doubt_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT fk_stock_doubt_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_stock_doubt_document FOREIGN KEY (count_document_id) REFERENCES document (id),
  CONSTRAINT fk_stock_doubt_resolver FOREIGN KEY (resolved_by) REFERENCES staff_user (id),
  CONSTRAINT ck_stock_doubt_settled CHECK ((status = 'open') = (resolved_at IS NULL) AND (status = 'open') = (resolved_by IS NULL)),
  CONSTRAINT ck_stock_doubt_cleared CHECK ((status = 'cleared' AND counted_qty IS NOT NULL AND count_document_id IS NOT NULL)
    OR (status <> 'cleared' AND counted_qty IS NULL AND booked_delta IS NULL AND count_document_id IS NULL)),
  CONSTRAINT ck_stock_doubt_counted CHECK (counted_qty IS NULL OR counted_qty >= 0),
  CONSTRAINT ck_stock_doubt_sold CHECK (sold_30d IS NULL OR sold_30d >= 0),
  CONSTRAINT ck_stock_doubt_batch CHECK (CHAR_LENGTH(batch) >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='items whose stock figure is in doubt, to check on the shelf one by one (DS1): cleared by a count or dismissed, never removed';
