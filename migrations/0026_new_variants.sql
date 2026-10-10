-- 0026_new_variants.sql — Products › New Variants (the owner's "new item add in any store how to here show also seed option any new
-- each site with", 10 Oct 2026; docs/decisions.md M60-M62, U131-U138): the variants one store added lately, newest first, and the
-- "seed" on every store (tick them, "Create as new items": BulkDecisions' existing new_item, row by row through DecisionService).
-- No data changes. It comes after 0025_by_item_bulk.sql (the bulk action of By Item), which changes the same column of
-- mapping_batch: the list below holds its value too.
--
--  * app_setting `mapping.new_variants_days` (30, 1 to 365): the period the page opens with ("first seen in the last N days"), set on
--    the Settings page with a reason, with version 1 (baseline) in config_change (docs/dev.md rule 5).
--  * mapping_batch.source gains `new`: a bulk action ticked on New Variants is its own kind of batch, so its result page leads back to
--    New Variants. The column's whole list is written out, in this order: review, store, item (0025: By Item), new. A value is only
--    ever added at the end of the list: in place, no table copy.
--  * channel_listing (channel_id, created_at, id): "one store's variants, newest first, first seen since a date". No index served it:
--    the store's indexes were (channel_id, external_variant_id) (the site's variant ids, a text: no time order) and (channel_id,
--    status) (in id order within a status, not by time; 0024's (sku_id, channel_id) starts with the item). Without it the page reads
--    every variant row of the store and sorts them (29,000 rows for the biggest store). With it the page reads the period's
--    entries backwards from now and stops after a page; the store's oldest and newest "first seen" are the index's two ends. The
--    entry is about 15 bytes a variant (38,000 on staging: well under 1 MB), written once when a variant arrives (created_at never
--    changes). Not unique: no new duplicate-key or gap locks for the stores' listing pushes under READ COMMITTED.
--  * sku (name): the name guard of a bulk "create as new" (BulkDecisions::namesTaken, M61): "is there an item of exactly this name
--    that is not joined into another?", one look-up per ticked row that would be made. sku had no index on its name (code, brand,
--    sell policy, origin listing, joined-into): without one each look-up reads every item (15,000 on staging). The entry is the
--    name (at most 255 characters) and the id; it is written when an item is made or renamed. Not unique: two items MAY have one
--    name (the first seed made such pairs, and a person may still make one on a variant's own page); the guard only stops a bulk
--    action from adding to them silently.
-- Online (INPLACE, LOCK=NONE: reads and writes go on while they run); a second or two on staging's 38,000 variants and 15,000
-- items. The code names each index in an optimizer hint, which is ignored while the index is not there, so it may run a moment
-- before this migration (the page, and a bulk "create as new", are then slower, not wrong); the old code runs on this schema (it
-- never names the new source or the indexes).

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('mapping.new_variants_days','int','30',1,'U132','How many days back Products > New Variants looks when it opens (a variant is new from the day the warehouse system first saw it)');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('mapping.new_variants_days');

ALTER TABLE mapping_batch
  MODIFY COLUMN source ENUM('review','store','item','new') NOT NULL
    COMMENT 'review: a match-strength list; store: Store Products; item: By Item; new: New Variants',
  ALGORITHM = INPLACE, LOCK = NONE;

ALTER TABLE channel_listing
  ADD INDEX ix_channel_listing_seen (channel_id, created_at, id),
  ALGORITHM = INPLACE, LOCK = NONE;

ALTER TABLE sku
  ADD INDEX ix_sku_name (name),
  ALGORITHM = INPLACE, LOCK = NONE;
