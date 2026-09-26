-- 0002_stock_core.sql — what the stock core (src/Stock.php, Reservations.php, Movements.php,
-- Availability.php) needs beyond 0001. See docs/decisions.md D27-D45.

-- ---------------------------------------------------------------------------------------------
-- Idempotency scope = (channel OR source) (D29). Staff screens and system jobs have no channel,
-- so channel_id becomes NULLable and `source` names the non-channel caller ('staff',
-- 'system:<job>'). The key is unique per scope: (IFNULL(channel_id, 0), source, idem_key).
-- ---------------------------------------------------------------------------------------------
ALTER TABLE idempotency DROP FOREIGN KEY fk_idempotency_channel;

ALTER TABLE idempotency
  DROP PRIMARY KEY,
  MODIFY channel_id SMALLINT UNSIGNED NULL COMMENT 'calling site; NULL for staff/system callers',
  ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'empty for a channel; staff or system:<job> otherwise' AFTER channel_id,
  ADD COLUMN scope_channel_id SMALLINT UNSIGNED AS (IFNULL(channel_id, 0)) STORED NOT NULL AFTER source,
  ADD PRIMARY KEY (scope_channel_id, source, idem_key),
  ADD KEY ix_idempotency_channel (channel_id),
  ADD CONSTRAINT ck_idempotency_scope CHECK ((channel_id IS NULL) = (source <> ''));

ALTER TABLE idempotency
  ADD CONSTRAINT fk_idempotency_channel FOREIGN KEY (channel_id) REFERENCES channel (id);

-- ---------------------------------------------------------------------------------------------
-- Ledger lookups the count rule needs (§8.2): ship/unship on_hand rows of one balance by
-- effective_at, and a unit's rows (invariants, unship).
-- ---------------------------------------------------------------------------------------------
ALTER TABLE stock_ledger
  ADD KEY ix_stock_ledger_effective (warehouse_id, sku_id, bucket, effective_at),
  ADD KEY ix_stock_ledger_unit (channel_id, unit_id);

-- ---------------------------------------------------------------------------------------------
-- Change feed: per-listing version = MAX(seq) of the rows that affect the listing (D38).
-- ---------------------------------------------------------------------------------------------
ALTER TABLE stock_change
  ADD KEY ix_stock_change_sku (sku_id, seq),
  ADD KEY ix_stock_change_listing (listing_id, seq),
  ADD KEY ix_stock_change_scope (channel_id, sku_id, listing_id, seq);

-- ---------------------------------------------------------------------------------------------
-- Feed clock (D39): every transaction that inserts stock_change rows first takes this one row's
-- X lock (INSERT ... ON DUPLICATE KEY UPDATE) and holds it until commit, so stock_change.seq is
-- allocated in commit order and a listing's version (MAX seq) can never go backwards.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE feed_clock (
  id          TINYINT UNSIGNED NOT NULL,
  ticks       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'feed transactions so far (informational)',
  updated_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  CONSTRAINT ck_feed_clock_single CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='one row; its lock serialises stock_change inserts with commit order (last in the lock order)';

INSERT INTO feed_clock (id, ticks) VALUES (1, 0);
