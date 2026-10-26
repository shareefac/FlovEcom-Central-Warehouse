-- 0051_hide_unsold.sql — the matching lists leave out what does not matter (the owner's "Yes, build it" of 26 Oct 2026; docs/decisions.md
-- HU1-HU9): a website product not matched yet, with no units sold in the last year, and first seen by the warehouse system more than a
-- year ago OR already on its store when the warehouse system first loaded that store (HU9, the owner's answer "b", "Hide them now"), is
-- left out of Store Products, Mapping > To review, New Variants and Simple matching's choices (its exact store ID or barcode still finds
-- it; Store Products says how many with "Show them"); and a warehouse product that is not sold any more (its product card) or joined into
-- another one is not offered to match to (its exact CW number still shows it, with no box). NOTHING in the data is deleted or changed:
-- the lists read one more condition; this file writes only each store's first load (below).
--
--  * channel.first_load_at: when the store's first list load ended (HU9). A website product first seen at or before it was already on
--    the store then, so it counts as old; one first seen after it is new until it is a year old. Recorded ONCE here, from the stores'
--    own rows: the end of the first run of website products made one after another (no gap of more than 30 minutes between two: the
--    import's batches, or a connector's first full push), at most a day after the store's first product; NULL for a store with no
--    product yet, which bin/import_listings.php records at the end of its first run that makes products (never moved after). The lists
--    read it once per request (CW\Ui\Queries::unsoldBefore), never per row. A store whose rows came in some other way (a few products
--    made days before the real load) gets the end of that first run: what came after it then counts as new until a year old, the safe
--    side; a migration can set the right time.
--  * channel_listing (channel_id, status, created_at): "a store's website products still to match, first seen before a date" is one
--    range of it (the two waiting statuses, then the date), read from the index alone (the id is in every entry). It is what Store
--    Products' count of the hidden ones, and New Variants' when it looks at any time, read (CW\Ui\Queries::UNSOLD_HIDDEN_SQL): a few
--    rows today (every website product was first seen when its store's list was loaded, in October 2026), the old ones of a store
--    later, and never every product of the store. The index of 0001 (channel_id, status) is a part of it and stays (the code names it
--    in hints). About 20 bytes a website product (well under 2 MB for the stores' 90,000), written once when a product arrives and
--    when its status changes. Not unique: no new duplicate-key or gap locks for a listing push under READ COMMITTED.
--  * item_card (discontinued): "the products not sold any more" (a handful) is one range of it, for Simple matching's count of the
--    products it lists (CW\Ui\Queries::STOPPED_SQL) and its anti-join, without reading the cards' rows. The key (sku_id) is in every
--    entry.
--  * app_setting `mapping.hide_unsold_days` (365; 0 = off), set on the Settings page with a reason, with its version 1 (baseline) in
--    config_change (docs/dev.md rule 5). An on/off switch in days: the stores send the units of the last 30 days and of the last 365 days
--    only (listing_profile.units_30d / units_365d), so "no sale in N days" can be known for a year and for nothing else; Settings::RULES
--    allows 0 and 365.
--
-- HOW IT IS APPLIED: the two indexes ONLINE, each ALGORITHM = INPLACE, LOCK = NONE (no table copy; reads and writes go on while they
-- run), as 0026 added one to channel_listing; a second or two. The column of `channel` (a handful of rows) in place too. The ALTERs come
-- first: if the server refuses one, nothing after it has been applied. Then one UPDATE of `channel` (one pass over the stores' listings,
-- by window functions, once). The code reads the setting with a default of 0 (off) and names the indexes in optimizer hints, which are ignored while an
-- index is not there, so it may run a moment before this migration (nothing is hidden until then); the old code runs on this schema (it
-- names neither index nor the setting).

ALTER TABLE channel_listing ADD INDEX ix_channel_listing_unsold (channel_id, status, created_at), ALGORITHM = INPLACE, LOCK = NONE;

ALTER TABLE item_card ADD INDEX ix_item_card_discontinued (discontinued), ALGORITHM = INPLACE, LOCK = NONE;

ALTER TABLE channel ADD COLUMN first_load_at DATETIME(6) NULL
    COMMENT 'when the store''s first list load ended: a website product first seen at or before it was already on the store (HU9)',
  ALGORITHM = INPLACE, LOCK = NONE;

-- Each store's first load: the end of its first run of products made one after another (no gap of more than 30 minutes), at most a day
-- after its first product (HU9).
UPDATE channel c JOIN (
  SELECT b.channel_id, MAX(b.created_at) AS loaded FROM (
    SELECT g.channel_id, g.created_at, g.first_at,
      SUM(g.gap) OVER (PARTITION BY g.channel_id ORDER BY g.created_at, g.id ROWS UNBOUNDED PRECEDING) AS gaps
    FROM (
      SELECT cl.channel_id, cl.id, cl.created_at, MIN(cl.created_at) OVER (PARTITION BY cl.channel_id) AS first_at,
        CASE WHEN LAG(cl.created_at) OVER (PARTITION BY cl.channel_id ORDER BY cl.created_at, cl.id) < cl.created_at - INTERVAL 30 MINUTE THEN 1 ELSE 0 END AS gap
      FROM channel_listing cl
    ) g
  ) b
  WHERE b.gaps = 0 AND b.created_at <= b.first_at + INTERVAL 1 DAY
  GROUP BY b.channel_id
) f ON f.channel_id = c.id
SET c.first_load_at = f.loaded;

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('mapping.hide_unsold_days','int','365',1,'HU1','The matching lists leave out a website product not matched yet that sold nothing in the last 365 days and was already on its store at the first load, or first seen more than 365 days ago (its exact ID or barcode still finds it); 0 = off');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key = 'mapping.hide_unsold_days';
