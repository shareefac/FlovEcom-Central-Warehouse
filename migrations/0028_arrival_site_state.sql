-- 0028_arrival_site_state.sql — the check on arrival, build 2a: the website state (docs/decisions.md M66, U146). Whether a variant is
-- on sale on its website (published, a draft, in the bin, a page heading) was known only for the variants a one-off matching run had
-- looked at, inside listing_profile.features; a variant that arrives by itself has none, and "Create as new items" could not tell.
-- This file adds where the state is kept, for every variant, as the website gives it. Nothing here writes a state: the listing
-- intake does when its caller supplies one (CW\Mapping\ListingIngestService: a push with the optional `website` object, an import of
-- an export file), and the state-only load does for the variants that are already there (bin/import_listings.php --state-only).
-- No row's data changes. The old code runs on this schema (it names every column it writes, and none of the new ones).
--
--  * listing_profile gains the raw inputs, each NULL until known ("not known" is a state: nothing reads NULL as "on sale" or as
--    "not a page heading"; CW\Mapping\SiteState is the one reader):
--      site_variant_status  the variant's own status on its website, as the website says it (Published, Draft, Bin, ...).
--      site_product_status  the status of its product (the parent page). A published variant under an unpublished product is not on sale.
--      site_product_type    the product's type (simple, variable): with the next three, what the Normalizer's page-heading rule reads.
--      site_product_id      the website's own number of the product: its variants are the ones that share it on one store.
--      site_is_landing      1 = the website's landing row of a product (a page heading by itself).
--      site_is_default      1 = the product's default variant.
--      site_product_link    the product's web address (stored, read by nothing yet).
--      site_state_at        as of when the seven above were true (the export's time, or the push's): the state has ONE date, and a
--                           state older than the setting below reads as not known.
--    Not in `features` (an identity change wipes those, and a half-filled object breaks the item-card reader), not in the
--    identity and in neither hash: a state that changes never makes a push look changed, and never moves a map_version.
--  * Index (site_product_id): "the variants of this product", for the count of published siblings the page-heading rule needs.
--    The number is the website's own, so two stores use the same numbers for different products: the read joins the listing for
--    its store (a handful of rows a number).
--
-- HOW IT IS APPLIED: as 0027 was, and for 0027's reasons (measured on this database server, MySQL 8.4.8, 12 Oct 2026:
-- /root/cw_work/runs/arrival_probe_ddl*.log; tests/Integration/Migration0027Test.php pins them, Migration0028Test holds this file
-- to the same). The server accepts ALGORITHM = INSTANT and rebuilds the table all the same, and with the multi-valued barcode
-- index of 0005 nothing but a copy adds a column to listing_profile. So the table is copied ONCE, on purpose and said out loud:
-- ONE statement, ALGORITHM = COPY, LOCK = SHARED, every column and the index in one pass. While it runs every read of
-- listing_profile goes on (the screens, the barcode look-ups, the check on arrival's reads); a write to it (a listing push, an
-- import, the check's mark) waits until it ends. Applying 0027 to staging (38,000 profiles) took 17 seconds in all.
-- The ALTER comes first: if the server refuses it, nothing of this file has been applied.
--
--  * One setting (Settings > Matching products): how old a stored state may be before it reads as not known.

ALTER TABLE listing_profile
  ADD COLUMN site_variant_status VARCHAR(32) NULL COMMENT 'the variant''s status on its website, as the website says it (Published, Draft, Bin); NULL = not known',
  ADD COLUMN site_product_status VARCHAR(32) NULL COMMENT 'the status of the variant''s product (parent page) on the website; NULL = not known',
  ADD COLUMN site_product_type VARCHAR(32) NULL COMMENT 'the product''s type on the website (simple, variable); NULL = not known',
  ADD COLUMN site_product_id VARCHAR(64) NULL COMMENT 'the website''s own number of the product: its variants share it on one store; NULL = not known',
  ADD COLUMN site_is_landing TINYINT(1) NULL COMMENT '1 = the website''s landing row of a product (a page heading); NULL = not known',
  ADD COLUMN site_is_default TINYINT(1) NULL COMMENT '1 = the product''s default variant on the website; NULL = not known',
  ADD COLUMN site_product_link VARCHAR(512) NULL COMMENT 'the product''s web address on the website (stored; read by nothing yet)',
  ADD COLUMN site_state_at DATETIME(6) NULL COMMENT 'as of when the site_ columns were true (the export''s or the push''s time); NULL = no state was ever stored',
  ADD INDEX ix_listing_profile_site_product (site_product_id),
  ALGORITHM = COPY, LOCK = SHARED;

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('mapping.arrival_state_max_days','int','30',1,'M66','How many days old the stored website state of a variant (on sale or not, a page heading or not) may be; an older one reads as not known');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('mapping.arrival_state_max_days');
