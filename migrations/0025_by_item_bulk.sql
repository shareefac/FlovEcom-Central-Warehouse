-- 0025_by_item_bulk.sql — bulk action with a preview on Products › By Item (the owner's "by item here bulk preview and update option
-- easy table need more item want to handle based on selection also all here possible options need", 10 Oct 2026; docs/decisions.md
-- M55-M59, U123-U130).
--
--  * mapping_batch.source gains `item`: a bulk action started on By Item (the suggested variants of the ticked items). Its rows,
--    decisions and result page are the existing ones (mapping_batch_row, `screen:<batch id>` on every decision); only the way back
--    from the result page differs. The new value goes at the end of the list: no row changes.
--    The rule for this list from here on: 0025 adds `item`. A later migration that adds a value must restate EVERY value the
--    column has by then ('review','store','item' and its own), because MODIFY COLUMN replaces the whole list. An applied migration
--    is never edited.
--  * app_setting: mapping.by_item_page_size, how many items a page of By Item shows unless the person picks another number on the page
--    (50, 100 or 200; 50 as before), set on Settings > System > Settings with a reason, with version 1 (baseline) in config_change
--    (docs/dev.md rule 5).
-- No index: the page reads the same indexes for 200 items as for 50 (the EXPLAIN of 10 Oct 2026, docs/decisions.md U129).
-- The old code runs on this schema (it never names the new value or the new setting).

ALTER TABLE mapping_batch
  MODIFY COLUMN source ENUM('review','store','item') NOT NULL COMMENT 'review: a match-strength list; store: Store Products; item: By Item';

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('mapping.by_item_page_size','int','50',1,'U123','How many items a page of Products > By Item shows (50, 100 or 200); the page can show another number for a while');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('mapping.by_item_page_size');
