-- 0068_listing_selling.sql — each website product's OWN selling mode, as its website says it (T-556 step 2, option A: the owner,
-- 16 Nov 2026). View only: the item page shows it beside the mode CW writes ("Selling mode it gets"), per store and website product,
-- so staff see what each store does now ("not known yet" until the store's connector sends it). Nothing in CW decides by it.
--
--  * listing_selling: one row per website product (channel_listing), written only by the listing intake (CW\Mapping\ListingIngestService,
--    in its transaction) from the optional object `selling` of a pushed listing (CW\Mapping\SiteSelling::parse: read leniently, never a
--    refusal; the shops' connector sends it from 0.4.13): the website's selling mode as it says it, whether it takes back-orders, its
--    low-stock warning, as of when (the push's time) and since when the three are what they are (as far as CW heard). Outside both
--    profile hashes and outside listing_profile (whose every new column is a copy of a large table, docs/dev.md): a push without it
--    (an older connector, an import) leaves the row as it is.
--    No FK to channel_listing: the intake writes it for up to a thousand listings a call, and an FK would take a shared lock on each
--    listing row in that hot path (the same reason as 0018's tables); the listing ids are the intake's own (it found or made each
--    listing in the same transaction) and a listing is never deleted (NO_DELETE). The app login never deletes a row either.
-- No other table changes; no row is written here. The old code runs on this schema (it names no column of the new table).
--
-- HOW IT IS APPLIED: one CREATE TABLE IF NOT EXISTS (a new, empty table: nothing is copied or locked).

CREATE TABLE IF NOT EXISTS listing_selling (
  listing_id           INT UNSIGNED  NOT NULL COMMENT 'channel_listing.id (no FK: the listing intake writes it; listings are never deleted)',
  stock_mode           VARCHAR(32)   NULL COMMENT 'the product''s selling mode on its website, as the website says it (In-Stock, From-Warehouse, Out-Of-Stock); NULL = none set there',
  allow_backorders     TINYINT(1)    NULL COMMENT '1 = the website takes back-orders for it, 0 = it does not; NULL = not set there',
  low_stock_threshold  INT           NULL COMMENT 'the website''s low-stock warning for it; NULL = not set there',
  as_of                DATETIME(6)   NOT NULL COMMENT 'as of when the website said so (the push''s time)',
  changed_at           DATETIME(6)   NOT NULL COMMENT 'since when the three values are what they are (the as_of of the push that brought them)',
  PRIMARY KEY (listing_id),
  CONSTRAINT ck_listing_selling_backorders CHECK (allow_backorders IS NULL OR allow_backorders IN (0, 1)),
  CONSTRAINT ck_listing_selling_times CHECK (changed_at <= as_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='each website product''s own selling mode as its website last sent it (T-556 option A; view only; NO_DELETE for cw_app)';
