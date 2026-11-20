-- 0075_profit_queue.sql — profit step 1, the final review of 6d59eda (docs/decisions.md PR95): the shops' order snapshots are STORED by the
-- API in one short transaction and WORKED OUT by a job (bin/apply_order_snapshots.php, every minute), so a store's checkout, whose stock calls
-- share the PHP workers with PUT /v1/orders/{ref}/snapshot, never waits behind the money work (a day summary's lock, the cost reads).
--
--  * order_snapshot_queue: one row per store order whose latest snapshot is not worked out yet. The API makes it (or moves its seq on) in the
--    transaction that stores the snapshot; the job takes it away in the transaction that worked that snapshot out, only while its seq is the
--    one it read (a snapshot stored meanwhile keeps the row for the next run). A snapshot the job refuses (a line another order stored at the
--    same moment) is marked failed with its reason and left until a newer snapshot of the order comes. No foreign key: the API's transaction
--    locks nothing but this row and the order's order_snapshot row.
--
-- HOW IT IS APPLIED: one CREATE TABLE IF NOT EXISTS (new, empty): nothing is copied or locked; it can run again and changes nothing the second
-- time. The app login gets every right on it (Grants: not in any restricted list; the job deletes a row once it is worked out).

CREATE TABLE IF NOT EXISTS order_snapshot_queue (
  channel_id  SMALLINT UNSIGNED NOT NULL,
  order_ref   VARCHAR(64)     NOT NULL,
  seq         BIGINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'moves on with every snapshot stored: the job takes the row away only when it worked out the one it read',
  queued_at   DATETIME(6)     NOT NULL COMMENT 'when the latest snapshot was stored',
  failed      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1: the job refused this snapshot (error says why); a newer snapshot clears it',
  tries       INT UNSIGNED    NOT NULL DEFAULT 0,
  error       VARCHAR(255)    NULL,
  PRIMARY KEY (channel_id, order_ref),
  KEY ix_order_snapshot_queue_due (failed, queued_at),
  CONSTRAINT ck_order_snapshot_queue_failed CHECK (failed IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the store orders whose latest snapshot the money job has not worked out yet (profit step 1); a row goes once it is worked out';
