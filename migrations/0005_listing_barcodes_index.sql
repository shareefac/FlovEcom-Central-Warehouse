-- 0005_listing_barcodes_index.sql — look listings up by barcode (review fix round 2, docs/decisions.md M18-M27).
--
-- The review screen shows, next to a listing, where else its barcodes are: other listings on any site
-- (e.g. a Vape and Go variant the site binned, never minted, whose barcode a "New item" would duplicate)
-- and items (sku_barcode). Without an index that is a JSON scan of every profile (~2.3 s on staging's
-- 38,119 profiles). A multi-valued index serves MEMBER OF / JSON_OVERLAPS / JSON_CONTAINS on the list.
-- Barcodes are strings of at most 64 printable characters (ListingIngestService checks every push).

ALTER TABLE listing_profile
  ADD INDEX ix_listing_profile_barcodes ((CAST(barcodes AS CHAR(64) ARRAY)));
