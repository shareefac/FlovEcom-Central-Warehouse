-- 0020_screen_indexes.sql — narrow indexes for the staff screens' most frequent reads (the owner's "every click is slow",
-- 8 Oct 2026; tests/perf/profile_pages.php measured them on a copy of cw_staging). No data changes; no column changes.
--
-- Why narrow: the staging cluster's InnoDB buffer pool is 32 MB, while match_proposal (its evidence JSON) is 17 MB and
-- listing_profile (attributes, features, barcodes JSON) is 111 MB. A query that reads those rows only to find an id, a status
-- or the units sold pulls megabytes from disk on every page. These indexes hold just those columns (well under 2 MB each), so
-- the reads stay in memory:
--  * match_proposal (status, band, listing_id) replaces (status, band): the lists' counts and their page of 50, the band counts
--    of Home and of the Matches page, find a listing without reading the proposal's evidence.
--  * match_proposal (status, lane): the open merge suggestions (Possible duplicates' badge on every page of a matching lead,
--    and its list) are found without reading the other 2,600 open proposals.
--  * listing_profile (listing_id, units_365d, units_30d): the units sold, which order the lists (best sellers first) and add up
--    Home's matching progress, without reading the profile's JSON. MySQL looks a listing up by the primary key even when this
--    index covers the query, so CW\Ui\Queries names it in an optimizer hint (/*+ INDEX(lp ix_listing_profile_units) */; a hint
--    naming an index that does not exist yet is ignored, so the code may run a moment before this migration).
-- Cost on a copy of cw_staging (3,234 proposals, 34,617 profiles, 8 Oct 2026): 1.4 s, online (INPLACE, LOCK=NONE: reads and writes
-- go on while it builds).

ALTER TABLE match_proposal
  ADD INDEX ix_match_proposal_open_band (status, band, listing_id),
  ADD INDEX ix_match_proposal_open_lane (status, lane),
  DROP INDEX ix_match_proposal_queue,
  ALGORITHM = INPLACE, LOCK = NONE;

ALTER TABLE listing_profile
  ADD INDEX ix_listing_profile_units (listing_id, units_365d, units_30d),
  ALGORITHM = INPLACE, LOCK = NONE;
