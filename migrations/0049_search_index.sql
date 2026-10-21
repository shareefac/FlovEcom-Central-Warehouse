-- 0049_search_index.sql — the website products' search by an index (docs/decisions.md SI1-SI6; the owner's yes of 21 Oct 2026 to the
-- review's NEEDS-OWNER item 1, /root/cw_work/SEARCH_MATCH_UX_REVIEW.md). Every word search of website products (Find, Store Products,
-- New Variants, the match-strength lists, By Item's quick match and picker) read and compared the text of EVERY profile it might find:
-- 120 MB for 38,000 profiles, 250-500 ms a search on the test server. This file stores that text once, made normal as CW\Ui\SearchText
-- makes a typed word (spaces and hyphens left out, "&" read as "and"; case and accents are the column's collation's, ai_ci), in narrow
-- columns with their own indexes, so a search reads one narrow index (5-13 MB) instead of the profiles.
--
--  * search_names: the product's title, the option's title and the brand (what the lists, Store Products, New Variants and Find search).
--  * search_all: the same, then the website's product number, the web address, the barcodes, the attributes (every name and value of
--    their JSON, without its keys: what JSON_SEARCH read) and the flavour, range and number features (everything By Item's quick match
--    and its picker searched: Queries::waitingListings, the owner's "search all attribute" of 20 Oct 2026).
--  * search_long: 1 when that text is longer than the 760 characters an index column holds (InnoDB's 3072 bytes of utf8mb4). Such a
--    profile (none in the test data; a handful at most) is still searched as before, on its row, by this flag's index: nothing a search
--    found is lost.
--
-- They are STORED GENERATED columns: MySQL writes them with the row, on every INSERT and UPDATE by anyone (the listing intake and
-- import, CW\Mapping\ListingIngestService; the tools that write features; a test's fixture), so nothing can leave them behind, and no
-- code writes them. The copy of the table below computes them for every profile that is there: that is the backfill (no bin/ tool).
-- No row's data changes, nothing matching saves changes, and the old code runs on this schema (it names every column it writes, and
-- none of these: a generated column may not be written).
--
-- HOW IT IS APPLIED: as 0027 and 0028, for their reasons (Migration0027Test: this server copies listing_profile for any new column;
-- with the multi-valued barcode index of 0005 nothing else adds one): ONE statement, ALGORITHM = COPY, LOCK = SHARED, the three
-- columns and their indexes in one pass, first in the file. Reads of listing_profile go on while it runs; a listing push, an import
-- or the check on arrival's mark waits until it ends. Measured on the test server (MySQL 8.4.8) with 38,000 profiles of 120 MB:
-- 14 seconds (runs/searchux_index_probe2.txt). It runs inside install_cron.sh --migrate, with the public vhost off.

ALTER TABLE listing_profile
  ADD COLUMN search_names VARCHAR(760) GENERATED ALWAYS AS (LEFT(REPLACE(REPLACE(REPLACE(CONCAT_WS(' ', product_title, variant_title, brand), ' ', ''), '-', ''), '&', 'and'), 760)) STORED
    COMMENT 'the product title, option title and brand made normal for the search (SearchText::norm); written by MySQL',
  ADD COLUMN search_all VARCHAR(760) GENERATED ALWAYS AS (LEFT(REPLACE(REPLACE(REPLACE(CONCAT_WS(' ', product_title, variant_title, brand, site_product_id, perma_link,
    CAST(barcodes AS CHAR), REGEXP_REPLACE(CAST(attributes AS CHAR), '"(?:[^"\\\\]|\\\\.)*"\\s*:', ' '), CAST(JSON_EXTRACT(features, '$.flavour_tokens', '$.line_tokens', '$.line_modifiers', '$.product_id', '$.strength_mg',
    '$.volume_ml', '$.line_numbers') AS CHAR)), ' ', ''), '-', ''), '&', 'and'), 760)) STORED
    COMMENT 'search_names, then the product number, web address, barcodes, attributes and features, made normal for the quick match; written by MySQL',
  ADD COLUMN search_long TINYINT(1) GENERATED ALWAYS AS (CHAR_LENGTH(REPLACE(REPLACE(REPLACE(CONCAT_WS(' ', product_title, variant_title, brand, site_product_id, perma_link,
    CAST(barcodes AS CHAR), REGEXP_REPLACE(CAST(attributes AS CHAR), '"(?:[^"\\\\]|\\\\.)*"\\s*:', ' '), CAST(JSON_EXTRACT(features, '$.flavour_tokens', '$.line_tokens', '$.line_modifiers', '$.product_id', '$.strength_mg',
    '$.volume_ml', '$.line_numbers') AS CHAR)), ' ', ''), '-', ''), '&', 'and')) > 760) STORED
    COMMENT '1 = search_all (and maybe search_names) was cut at 760 characters: this profile is searched on its row; written by MySQL',
  ADD INDEX ix_listing_profile_search_names (search_names),
  ADD INDEX ix_listing_profile_search_all (search_all),
  ADD INDEX ix_listing_profile_search_long (search_long),
  ALGORITHM = COPY, LOCK = SHARED;
