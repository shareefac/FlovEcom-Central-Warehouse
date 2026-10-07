-- 0015_duplicates.sql — Vape and Go's duplicate listings handled by CW (docs/decisions.md M31-M36; the owner's decision of 6 Oct 2026).
--
--  * match_decision.action gains `split`: the undo of a wrong merge of uncounted items (DecisionService, M33, M40). A split
--    back to the former item undoes the merge whole: every listing the merge moved goes back to the item it merged away (revived:
--    sku.merged_into_sku_id set back to NULL), with the stock that came with it; a split to a new item takes the listing named
--    alone. Its row (on the listing named) names the item it goes to (sku_id; NULL while a split to a new
--    item waits for a second person, as for new_item) and, in `detail`, the merge it undoes (`undoes_decision_id`) and where
--    the listing goes (`split_to`: former | new); prev_sku_id is the item it leaves. A split row always has its detail (CHECK).
--    (The comments of listing_id and sku_id, which carry foreign keys, are left as 0004 wrote them.)
--  * No new table and no new grant: the stock a merge moves to the kept item (and a split moves back) is ordinary stock_ledger
--    rows written by CW\Stock (movement types merge_out / merge_in / split_out / split_in under doc_ref `merge:<merge decision>`),
--    and the "keep separate" answer is a match_reject row (M8, M22), which the review screens and the merge already read.

ALTER TABLE match_decision
  MODIFY COLUMN action ENUM('link','unlink','new_item','ignore','reject','suggest','merge_skus','split') NOT NULL,
  MODIFY COLUMN needs_second JSON NULL COMMENT 'why two people are needed: protected_sku, units_per_item, merge (a merge by a mapper), counted_item, previously_rejected',
  MODIFY COLUMN detail JSON NULL COMMENT 'new_item: the identity card to mint; split: undoes_decision_id, split_to, card; approvals re-use it',
  ADD CONSTRAINT ck_match_decision_split CHECK (action <> 'split' OR detail IS NOT NULL);
