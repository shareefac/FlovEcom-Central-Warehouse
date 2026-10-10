-- 0024_by_product.sql — narrow indexes for Products › By Product (docs/decisions.md U113-U121): one row per warehouse product, one
-- column per store. No data changes; no column changes. (Numbered 0024: another branch may take 0023.)
--
-- Why (the same reason as 0020: the staging cluster's InnoDB buffer pool is 32 MB, and a query must not read wide rows to learn an
-- id, a store or a status):
--  * channel_listing (sku_id, channel_id) replaces (sku_id): "which stores is this product matched on" is answered from the index
--    alone. The page's coverage filters ("On every store", "Missing on <store>": an EXISTS / NOT EXISTS per product) and the counts
--    of its filter entries (one grouped read of the matched listings) no longer read a listing row per product. The new index
--    starts with sku_id, so it also serves the foreign key and every read the old one served.
--  * match_proposal (status, proposed_sku_id, listing_id, band): the open suggestions that propose given products (the page's
--    "Suggested" cells, its filter "With a suggestion waiting" and that entry's count) are found without reading a proposal's row,
--    which carries the evidence JSON (the table was 17 MB on 8 Oct 2026). The old (proposed_sku_id) index stays for the foreign key.
-- Online (INPLACE, LOCK=NONE: reads and writes go on while they build); a few seconds at most on staging's 38,000 listings and
-- 3,300 proposals.

ALTER TABLE channel_listing
  ADD INDEX ix_channel_listing_sku_channel (sku_id, channel_id),
  DROP INDEX ix_channel_listing_sku,
  ALGORITHM = INPLACE, LOCK = NONE;

ALTER TABLE match_proposal
  ADD INDEX ix_match_proposal_open_sku (status, proposed_sku_id, listing_id, band),
  ALGORITHM = INPLACE, LOCK = NONE;
