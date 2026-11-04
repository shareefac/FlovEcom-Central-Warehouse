-- 0061_reorder_buy_for.sql — "Buy for N days" on Purchasing › Reorder › Suggestions (the owner, 4 Nov 2026: "based on previous data sales how
-- many will we need to buy for custom days, weeks etc."; docs/decisions.md BF1-BF12). A buyer chooses how many days to buy for (1 week, 2
-- weeks, 4 weeks or a typed number); every line then uses that number in place of its order cycle: target = ⌈d·(L+N+S)⌉. The reorder point,
-- "urgent" and the safety stock do not change. The usual list (each supplier's order cycle) and its stored lines stay as they were.
--
--  * reorder_line gets the few inputs of a line's need (BF3): sales a day × the factor (d_e6), delivery days, spare days, stock + on order,
--    the smallest and largest stock, the pack, its smallest order and steps, the rounding, the pack price. With them a page works out the
--    need for N for every listed item in ONE read of the compact rows (no JSON, no ledger, no order lines). NULL: not stored yet (an
--    unlisted marker row, or a row the refresh has not written since this file); the refresh writes them with every line.
--  * The rows already there get their inputs from their own stored line (reorder_line_detail), only while they have none (BF4).
--  * Three settings (Settings › Rules and numbers › Reorder), each with its baseline, provisional as every new one: the choices offered
--    (reorder.buy_for_presets, "7,14,28"), and the smallest and largest number of days a person may choose (reorder.buy_for_min_days 1,
--    reorder.buy_for_max_days 180).
--
-- HOW IT IS APPLIED: the three settings and their baselines (each INSERT guarded by NOT EXISTS); then the columns at the end of reorder_line,
-- ALGORITHM = INSTANT (as 0052, 0060: no table copy, no row changed), run only while none of them is there (a prepared statement, as 0056 and
-- 0060); then the inputs of the rows already there, read from their stored lines, only for the rows that have none (UPDATE ... WHERE d_e6
-- IS NULL: one statement, about 20,000 small rows; a statement stopped half way is undone whole and runs again). Nothing is deleted, no
-- existing column changes. A run stopped anywhere (the file is not recorded yet) runs again cleanly: no setting twice, the columns not
-- added twice, a row with inputs never read again. The old code runs on this schema (its writes of reorder_line name their columns; the new
-- ones keep what they had, and the new code's refresh writes again any row whose inputs differ from its line: BF4).

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description)
  SELECT k.setting_key, k.value_type, k.value_json, 1, 'BF5', k.description FROM (
    SELECT 'reorder.buy_for_presets' AS setting_key, 'string' AS value_type, '"7,14,28"' AS value_json,
      'The numbers of days Reorder offers under "Buy for" (comma-separated; 7 = 1 week); a person may also type a number' AS description
    UNION ALL SELECT 'reorder.buy_for_min_days', 'int', '1', 'The fewest days a person may choose under "Buy for" on Reorder'
    UNION ALL SELECT 'reorder.buy_for_max_days', 'int', '180', 'The most days a person may choose under "Buy for" on Reorder'
  ) k WHERE NOT EXISTS (SELECT 1 FROM app_setting s WHERE s.setting_key = k.setting_key);

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key IN ('reorder.buy_for_presets', 'reorder.buy_for_min_days', 'reorder.buy_for_max_days')
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);

-- The columns, only while none of them is there; all of them there: nothing to do; some of them only: the migration stops, naming it.
SET @cw_0061 = (SELECT CASE WHEN COUNT(*) = 0 THEN CONCAT('ALTER TABLE reorder_line ',
    'ADD COLUMN d_e6 BIGINT NULL COMMENT ''sales a day x the factor, e6 (ReorderMath d); NULL: the inputs of Buy for N days are not stored yet'', ',
    'ADD COLUMN lead_days SMALLINT NULL COMMENT ''delivery days (L)'', ',
    'ADD COLUMN safety_days SMALLINT NULL COMMENT ''spare days (S)'', ',
    'ADD COLUMN stock_position BIGINT NULL COMMENT ''available + on order (P; open orders may add up past an INT)'', ',
    'ADD COLUMN min_stock INT NULL COMMENT ''the smallest stock set for the item'', ',
    'ADD COLUMN max_stock INT NULL COMMENT ''the largest stock set for the item'', ',
    'ADD COLUMN units_per_pack INT NULL COMMENT ''units in a pack of the main supplier item (1 without one)'', ',
    'ADD COLUMN moq_packs INT NULL COMMENT ''its smallest order, in packs'', ',
    'ADD COLUMN multiple_packs INT NULL COMMENT ''its steps, in packs'', ',
    'ADD COLUMN round_nearest TINYINT(1) NULL COMMENT ''1: packs rounded to the nearest, else up'', ',
    'ADD COLUMN pack_price_e4 BIGINT NULL COMMENT ''the last pack price, e4 (NULL: none)'', ',
    'ALGORITHM = INSTANT')
  WHEN COUNT(*) = 11 THEN 'DO 0'
  ELSE 'SELECT 1 FROM `0061 reorder_line has some of the Buy for columns only`' END
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reorder_line'
    AND COLUMN_NAME IN ('d_e6', 'lead_days', 'safety_days', 'stock_position', 'min_stock', 'max_stock', 'units_per_pack', 'moq_packs', 'multiple_packs',
      'round_nearest', 'pack_price_e4'));
PREPARE cw_0061 FROM @cw_0061;
EXECUTE cw_0061;
DEALLOCATE PREPARE cw_0061;

-- The inputs of the rows already there, from their own stored line (ReorderList::lines() for the item: d_e6, lead, safety, position,
-- min_stock, max_stock, upp, moq, mult, rounding, pack_price), only for the rows that have none and whose stored line has them.
UPDATE reorder_line rl JOIN reorder_line_detail d ON d.sku_id = rl.sku_id
  SET rl.d_e6 = CAST(JSON_EXTRACT(d.line, '$.d_e6') AS SIGNED),
      rl.lead_days = CAST(JSON_EXTRACT(d.line, '$.lead') AS SIGNED),
      rl.safety_days = CAST(JSON_EXTRACT(d.line, '$.safety') AS SIGNED),
      rl.stock_position = CAST(JSON_EXTRACT(d.line, '$.position') AS SIGNED),
      rl.min_stock = IF(JSON_TYPE(JSON_EXTRACT(d.line, '$.min_stock')) = 'INTEGER', CAST(JSON_EXTRACT(d.line, '$.min_stock') AS SIGNED), NULL),
      rl.max_stock = IF(JSON_TYPE(JSON_EXTRACT(d.line, '$.max_stock')) = 'INTEGER', CAST(JSON_EXTRACT(d.line, '$.max_stock') AS SIGNED), NULL),
      rl.units_per_pack = CAST(JSON_EXTRACT(d.line, '$.upp') AS SIGNED),
      rl.moq_packs = CAST(JSON_EXTRACT(d.line, '$.moq') AS SIGNED),
      rl.multiple_packs = CAST(JSON_EXTRACT(d.line, '$.mult') AS SIGNED),
      rl.round_nearest = IF(JSON_UNQUOTE(JSON_EXTRACT(d.line, '$.rounding')) = 'nearest', 1, 0),
      rl.pack_price_e4 = IF(JSON_TYPE(JSON_EXTRACT(d.line, '$.pack_price')) = 'STRING',
        CAST(CAST(JSON_UNQUOTE(JSON_EXTRACT(d.line, '$.pack_price')) AS DECIMAL(20,4)) * 10000 AS SIGNED), NULL)
  WHERE rl.d_e6 IS NULL
    AND JSON_TYPE(JSON_EXTRACT(d.line, '$.d_e6')) = 'INTEGER' AND JSON_TYPE(JSON_EXTRACT(d.line, '$.lead')) = 'INTEGER'
    AND JSON_TYPE(JSON_EXTRACT(d.line, '$.safety')) = 'INTEGER' AND JSON_TYPE(JSON_EXTRACT(d.line, '$.position')) = 'INTEGER'
    AND JSON_TYPE(JSON_EXTRACT(d.line, '$.upp')) = 'INTEGER' AND JSON_TYPE(JSON_EXTRACT(d.line, '$.moq')) = 'INTEGER'
    AND JSON_TYPE(JSON_EXTRACT(d.line, '$.mult')) = 'INTEGER';
