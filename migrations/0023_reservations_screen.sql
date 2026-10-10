-- 0023_reservations_screen.sql — one narrow index for Stock › Reservations (docs/decisions.md RS1–RS12). No data changes; no column
-- changes; nothing in the reservation engine (src/Reservations.php) reads or needs it.
--
-- Why it is needed: the screen lists the reservations of ONE store, or of one state, newest first, 50 at a time. `reservation` had
-- three indexes: the primary key (id), (channel_id, order_ref) and (status, expires_at). The first serves "every store, newest
-- first". The second finds a store's rows, but in the order of the order reference (a text: "999" sorts after "1000"), never by time;
-- the third finds a state's rows in the order of their expiry and cannot serve a store. So "this store, newest first" had only two
-- plans: read the whole table backwards until 50 rows of the store turn up, or read every row of the store and sort it. The table gets
-- one row per order of every store, for ever, and the staging cluster's InnoDB buffer pool is 32 MB (see 0020).
--  * reservation (channel_id, status, id): a store's rows of one state, by id. The list reads one index range per (store, state) pair
--    it was asked for, newest first, 51 ids each, and merges them (at most 51 x pairs ids, never the table): CW\Ui\ReservationViews.
--    The store selector's "reserved now" count per store is read from the index alone. About 15 bytes a row.
-- MySQL may prefer the primary key for an ORDER BY id ... LIMIT, so CW\Ui\ReservationViews names the index in an optimizer hint
-- (/*+ INDEX(r ix_reservation_channel_status) */; a hint naming an index that does not exist yet is ignored, so the code may run a
-- moment before this migration, as 0020's).
-- Cost for the engine: one more index entry per order, moved when its status changes (the engine already moves the
-- (status, expires_at) entry in the same UPDATE). Not unique, so no new duplicate-key or gap locks under READ COMMITTED.
-- Online (INPLACE, LOCK=NONE: reads and writes go on while it builds).

ALTER TABLE reservation
  ADD INDEX ix_reservation_channel_status (channel_id, status, id),
  ALGORITHM = INPLACE, LOCK = NONE;
