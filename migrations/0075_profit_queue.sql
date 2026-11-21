-- 0075_profit_queue.sql — profit step 1, the final review of 6d59eda (docs/decisions.md PR95): the shops' order snapshots are STORED by the
-- API in one short transaction and WORKED OUT by a job (bin/apply_order_snapshots.php, every minute), so a store's checkout, whose stock calls
-- share the PHP workers with PUT /v1/orders/{ref}/snapshot, never waits behind the money work (a day summary's lock, the cost reads).
--
--  * order_snapshot_queue: per store order, the newest snapshot the store sent that is not worked out yet (its body, version and hash). The API
--    makes the row, or replaces its snapshot and moves its seq on, in its one short transaction; the job works the row's snapshot out and, in
--    the same transaction, writes it to order_snapshot (which from now holds the latest snapshot WORKED OUT: the one sale_order, sale_line and
--    sale_parcel say) and takes the row away, only while its seq is the one it read (a snapshot stored meanwhile keeps the row for the next
--    run). A snapshot the job refuses (a line another order stored at the same moment) is marked failed with its reason and kept until a newer
--    snapshot of the order replaces it; it never reaches order_snapshot. No foreign key: the API's transaction locks this row and nothing else.
--
-- HOW IT IS APPLIED: one CREATE TABLE IF NOT EXISTS (new, empty): nothing is copied or locked; it can run again and changes nothing the second
-- time. The app login gets every right on it (Grants: not in any restricted list; the job deletes a row once it is worked out).

CREATE TABLE IF NOT EXISTS order_snapshot_queue (
  channel_id  SMALLINT UNSIGNED NOT NULL,
  order_ref   VARCHAR(64)     NOT NULL,
  seq         BIGINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'moves on with every snapshot stored: the job takes the row away only when it worked out the one it read',
  version     BIGINT UNSIGNED NOT NULL COMMENT 'the shop''s version of the snapshot waiting',
  body_hash   CHAR(64)        NOT NULL COMMENT 'sha256 of its normalised content without the version',
  body        JSON            NOT NULL COMMENT 'the normalised snapshot waiting (only the contract''s fields, no free text)',
  source      ENUM('api','bulk') NOT NULL,
  first_at    DATETIME(6)     NOT NULL COMMENT 'when CW first stored a snapshot of the order (order_snapshot.first_at when it is new there)',
  queued_at   DATETIME(6)     NOT NULL COMMENT 'when the snapshot waiting was stored',
  failed      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1: the job refused this snapshot (error says why); a newer snapshot clears it',
  tries       INT UNSIGNED    NOT NULL DEFAULT 0,
  error       VARCHAR(255)    NULL,
  PRIMARY KEY (channel_id, order_ref),
  KEY ix_order_snapshot_queue_due (failed, queued_at),
  CONSTRAINT ck_order_snapshot_queue_failed CHECK (failed IN (0, 1)),
  CONSTRAINT ck_order_snapshot_queue_hash CHECK (REGEXP_LIKE(body_hash, '^[0-9a-f]{64}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the newest snapshot of each store order that the money job has not worked out yet (profit step 1); a row goes once it is worked out';
