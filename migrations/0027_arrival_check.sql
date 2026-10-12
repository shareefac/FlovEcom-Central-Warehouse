-- 0027_arrival_check.sql — the check on arrival, build 1: matches by barcode and copied address (the owner's "do the check on arrival
-- next", 12 Oct 2026; docs/decisions.md M64, M65, U144, U145). A variant nobody has looked at gets a rules-only suggestion when its
-- barcode, or a web address copied from the main store, points at an item (CW\Mapping\ArrivalCheck, bin/check_arrivals.php). Nothing
-- here makes a suggestion: this file adds where the check keeps its "to check" mark, the two reads it needs, and its settings (the
-- check is switched off until someone names a store). No row's data changes. The old code runs on this schema (it names every column
-- it writes, and none of the new ones).
--
--  * listing_profile gains the mark, written by the listing intake (ListingIngestService, in the statements it already runs) and by
--    the check:
--      arrival_state       NULL = was there before the check (only a hand run with --backfill looks at it); queued = to check;
--                          done = looked at. `names` is kept for build 2 (waiting for the name search) and is written by nothing yet.
--      arrival_seq         +1 on every queueing: the check writes its answer only while the number is the one it read.
--      arrival_channel_id  the variant's store (channel.id is SMALLINT UNSIGNED), so the queue of one store is read from the index
--                          alone. No foreign key: it copies channel_listing.channel_id of the same listing, which has one.
--      arrival_checked_at  when the check last looked.
--      arrival_outcome     what it found (a code: barcode, copy, no_clue, has_history, ...; the words are Ui\Words::ARRIVAL_LEFT).
--      perma_key           the web address as two stores' copies of one page share it: surrounding spaces and the closing "/" gone
--                          (tools/first_match/run.php permaKey(); CW\Matching\ArrivalRules::permaKey() is the same in PHP). A VIRTUAL
--                          generated column: nothing is stored in the row. Its collation is the table's, which does not tell letter
--                          case apart, so the index finds a superset and the PHP comparison decides.
--  * Indexes: (arrival_state, arrival_channel_id): the queue, per store, and its counts, without reading a profile (InnoDB adds the
--    listing id, so "the next 200 of this store" is one range). (perma_key): "which variants have this address".
--
-- HOW IT IS APPLIED, AND WHY NOT "INSTANT" (measured on scratch schemas of the staging database server, MySQL 8.4.8, 12 Oct 2026;
-- /root/cw_work/runs/arrival_probe_ddl*.log; tests/Integration/Migration0027Test.php pins it):
--  * This server accepts ALGORITHM = INSTANT for ADD COLUMN and then REBUILDS the table all the same (a new table id and a new
--    tablespace, no row version): the word is no promise here. So it is not written.
--  * listing_profile has the multi-valued barcode index of 0005. With it, MySQL refuses to add a column in place ("INPLACE ADD or
--    DROP of virtual columns cannot be combined with other ALTER TABLE actions"), a plain column as much as a generated one, and
--    when asked for INSTANT it copies the table without saying so (rows affected = every row).
--  * So the table is copied ONCE, on purpose and said out loud: ONE statement, ALGORITHM = COPY, LOCK = SHARED. While it runs
--    every read of listing_profile goes on (the screens, the barcode look-ups); a write to it (a listing push, an import) waits
--    until it ends. On a scratch table of staging's size (38,000 profiles, 120 MB) it took 9 seconds, and 17 seconds while another
--    connection read and another wrote without a pause (2,716 reads, the slowest 0.6 s; the one write that met it waited for the
--    17 s and then went through). Splitting it into several statements would copy the table once per statement: the columns, the
--    generated column and both indexes go in one pass.
--  * The same will be true of every later column on this table while the 0005 index is there (build 2's website state).
-- The ALTER comes first: if the server refuses it, nothing of this file has been applied.
--
--  * match_proposal (match_run_id, status, band, lane, listing_id): "the open suggestions of these runs, of this strength (and
--    found this way)". The check writes a run's clean matches under one match_run row and everything else under another, so the
--    lists tell "found by the check on arrival" (and, of those, "with a warning") by a handful of run ids: the count of the Show
--    filter's choice, the list of that choice, and "has this run an open suggestion left" are answered from this index alone,
--    without a suggestion's row (its evidence) or its flags (CW\Mapping\OneAtATime::arrivalRuns(), Ui\Queries::queueShows()). The
--    unique key (match_run_id, listing_id) holds neither the status nor the strength, so every suggestion a run ever made would
--    be read for them. This one IS added in place and online (INPLACE, LOCK = NONE: match_proposal has no multi-valued index; 0020,
--    0021 and 0024 added theirs the same way), without rebuilding the table: the test holds the table's id to it.

ALTER TABLE listing_profile
  ADD COLUMN arrival_state ENUM('queued','names','done') NULL
    COMMENT 'the check on arrival: NULL = was there before the check; queued = to check; names = kept for build 2; done = looked at',
  ADD COLUMN arrival_seq INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '+1 on every queueing; the check writes its answer only for the number it read',
  ADD COLUMN arrival_channel_id SMALLINT UNSIGNED NULL COMMENT 'the listing''s channel (channel_listing.channel_id), set when queued',
  ADD COLUMN arrival_checked_at DATETIME(6) NULL COMMENT 'when the check on arrival last looked at it',
  ADD COLUMN arrival_outcome VARCHAR(32) NULL COMMENT 'what the check found (a code; the words are Ui\\Words::ARRIVAL_LEFT)',
  ADD COLUMN perma_key VARCHAR(512) GENERATED ALWAYS AS (NULLIF(TRIM(TRAILING '/' FROM TRIM(perma_link)), '')) VIRTUAL
    COMMENT 'perma_link without surrounding spaces and the closing slash: what a copy of a main-store page shares with it',
  ADD INDEX ix_listing_profile_arrival (arrival_state, arrival_channel_id),
  ADD INDEX ix_listing_profile_perma_key (perma_key),
  ALGORITHM = COPY, LOCK = SHARED;

ALTER TABLE match_proposal
  ADD INDEX ix_match_proposal_run_open (match_run_id, status, band, lane, listing_id),
  ALGORITHM = INPLACE, LOCK = NONE;

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('mapping.arrival_stores','string','""',1,'U145','The stores (channel codes, comma-separated; empty = the check is switched off) whose newly arrived variants the check on arrival looks at'),
 ('mapping.arrival_main_store','string','"vapeandgo"',1,'U145','The main store (one channel code; empty = none): the store the items were made from. A web address copied from one of its variants points at that variant''s item'),
 ('mapping.arrival_bulk_confirm','bool','false',1,'U145','Matches found by the check on arrival may be confirmed from a ticked list; off: each is opened and decided on its own page. One with a warning is always decided on its own page'),
 ('mapping.arrival_max_per_run','int','200',1,'U145','The most variants one run of the check on arrival looks at'),
 ('mapping.arrival_max_seconds','int','45',1,'U145','The longest one run of the check on arrival may take, in seconds'),
 ('mapping.arrival_queue_alarm','int','300',1,'U145','The most waiting variants of one store the timer takes by itself; above it the store waits for a person to run the check by hand');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('mapping.arrival_stores', 'mapping.arrival_main_store', 'mapping.arrival_bulk_confirm',
    'mapping.arrival_max_per_run', 'mapping.arrival_max_seconds', 'mapping.arrival_queue_alarm');
