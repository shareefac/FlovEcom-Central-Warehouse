-- 0083_own_items.sql — "Vape Big's own items" (tracker CW-1153; the owner, CW chat 26 Nov 2026 ~07:45 UK: give it a Vape Big mark;
-- docs/decisions.md VB1-VB16). Some items are the selling shop's OWN goods, bought with its own money, not Vape and Go's: Hayati 4000 and
-- the Elux Legend / FireRose ranges sold only on Vape Big. Their stock stays in MAIN and sells exactly as today (no warehouse, no order or
-- stock change); CW only MARKS them, so that our figures leave them out and Vape Big's page shows them on their own line.
--
--  * item_owner: one row per PERIOD an item is a shop's own (from_at .. to_at, to_at NULL while it still is). Re-adding an item after a
--    removal is a new row; periods never overlap (ItemOwners, under the item's row lock; at most one open row per item: uq_item_owner_open).
--    Every figure uses the mark as it was at the event's time (an order's paid time), never today's list. History is never rewritten: the
--    app login may only close a period (to_at and who closed it, why).
--  * sale_line.item_owner: the shop whose own goods the line sold (NULL: ours), fixed from item_owner at the order's paid time.
--  * sale_order / profit_day: own_units, own_sale_ex, own_refund_ex, own_cost, own_no_cost; profit_item_day: own_units, own_sale_ex,
--    own_cost, own_no_cost: the shop's own goods' part of the figures beside them. The figures themselves stay WHOLE as before (every
--    reader that shows OUR figures takes the own part off: ours = total - own), so code before this file reads correct whole-shop totals.
--  * supplier_invoice.item_owner: a bill whose goods lines are all one shop's own items when it is made final (NULL: ours).
--  * The setting stock.own_items_shop ("vapebig"): whose own items Settings > Own items adds; changed only while no item of another shop is
--    marked. With its version 1 in config_change.
--
-- HOW IT IS APPLIED: one CREATE TABLE IF NOT EXISTS (new, empty), six column additions each only while it is not there (a prepared statement,
-- ALGORITHM = INSTANT: no table is copied; if the server cannot do it instantly the statement fails instead of copying), one setting with its
-- baseline. No row is changed, nothing is deleted. A second run does nothing.

CREATE TABLE IF NOT EXISTS item_owner (
  id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  sku_id         INT UNSIGNED      NOT NULL,
  channel_id     SMALLINT UNSIGNED NOT NULL COMMENT 'the shop whose own goods the item is',
  from_at        DATETIME(6)       NOT NULL COMMENT 'UTC: from this moment (sales paid at or after it are the shop''s own)',
  to_at          DATETIME(6)       NULL COMMENT 'UTC: until this moment (paid before it); NULL: still the shop''s own',
  open_sku       INT UNSIGNED      GENERATED ALWAYS AS (IF(to_at IS NULL, sku_id, NULL)) STORED,
  add_reason     VARCHAR(500)      NOT NULL,
  added_by       INT UNSIGNED      NULL,
  added_actor    VARCHAR(64)       NOT NULL,
  added_at       DATETIME(6)       NOT NULL,
  remove_reason  VARCHAR(500)      NULL,
  removed_by     INT UNSIGNED      NULL,
  removed_actor  VARCHAR(64)       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_owner_open (open_sku) COMMENT 'at most one open period per item',
  KEY ix_item_owner_item (sku_id, from_at),
  KEY ix_item_owner_shop (channel_id, to_at),
  CONSTRAINT fk_item_owner_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_item_owner_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT ck_item_owner_period CHECK (to_at IS NULL OR to_at > from_at),
  CONSTRAINT ck_item_owner_removed CHECK ((to_at IS NULL) = (removed_actor IS NULL) AND (to_at IS NULL) = (remove_reason IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the periods an item is a shop''s own goods (CW-1153, VB1): figures use the period of the event''s time';

SET @cw_0083_sale_line = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sale_line'
    AND column_name = 'item_owner') = 0,
  'ALTER TABLE sale_line ADD COLUMN item_owner SMALLINT UNSIGNED NULL COMMENT ''the shop whose own goods the line sold (item_owner at the paid time); NULL: ours'', ALGORITHM = INSTANT',
  'DO 0');
PREPARE cw_0083_sale_line FROM @cw_0083_sale_line;
EXECUTE cw_0083_sale_line;
DEALLOCATE PREPARE cw_0083_sale_line;

SET @cw_0083_sale_order = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sale_order'
    AND column_name = 'own_units') = 0,
  'ALTER TABLE sale_order ADD COLUMN own_units BIGINT NOT NULL DEFAULT 0 COMMENT ''units of the shop''''s own goods (inside units)'', ADD COLUMN own_sale_ex BIGINT NOT NULL DEFAULT 0 COMMENT ''pence ex VAT: their lines (before the order-level refund)'', ADD COLUMN own_refund_ex BIGINT NOT NULL DEFAULT 0 COMMENT ''pence ex VAT: their part of the order-level refund (inside order_refund_ex)'', ADD COLUMN own_cost BIGINT NOT NULL DEFAULT 0 COMMENT ''pence: their buying cost (inside cost)'', ADD COLUMN own_no_cost BIGINT NOT NULL DEFAULT 0 COMMENT ''their units with no buying price (inside units_no_cost)'', ALGORITHM = INSTANT',
  'DO 0');
PREPARE cw_0083_sale_order FROM @cw_0083_sale_order;
EXECUTE cw_0083_sale_order;
DEALLOCATE PREPARE cw_0083_sale_order;

SET @cw_0083_profit_day = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'profit_day'
    AND column_name = 'own_units') = 0,
  'ALTER TABLE profit_day ADD COLUMN own_units BIGINT NOT NULL DEFAULT 0 COMMENT ''the shop''''s own goods (inside units)'', ADD COLUMN own_sale_ex BIGINT NOT NULL DEFAULT 0 COMMENT ''inside sale_ex'', ADD COLUMN own_refund_ex BIGINT NOT NULL DEFAULT 0 COMMENT ''inside order_refund_ex'', ADD COLUMN own_cost BIGINT NOT NULL DEFAULT 0 COMMENT ''inside cost'', ADD COLUMN own_no_cost BIGINT NOT NULL DEFAULT 0 COMMENT ''inside units_no_cost'', ALGORITHM = INSTANT',
  'DO 0');
PREPARE cw_0083_profit_day FROM @cw_0083_profit_day;
EXECUTE cw_0083_profit_day;
DEALLOCATE PREPARE cw_0083_profit_day;

SET @cw_0083_profit_item_day = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'profit_item_day'
    AND column_name = 'own_units') = 0,
  'ALTER TABLE profit_item_day ADD COLUMN own_units BIGINT NOT NULL DEFAULT 0 COMMENT ''inside units'', ADD COLUMN own_sale_ex BIGINT NOT NULL DEFAULT 0 COMMENT ''inside sale_ex'', ADD COLUMN own_cost BIGINT NOT NULL DEFAULT 0 COMMENT ''inside cost'', ADD COLUMN own_no_cost BIGINT NOT NULL DEFAULT 0 COMMENT ''inside units_no_cost'', ALGORITHM = INSTANT',
  'DO 0');
PREPARE cw_0083_profit_item_day FROM @cw_0083_profit_item_day;
EXECUTE cw_0083_profit_item_day;
DEALLOCATE PREPARE cw_0083_profit_item_day;

SET @cw_0083_sinv = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supplier_invoice'
    AND column_name = 'item_owner') = 0,
  'ALTER TABLE supplier_invoice ADD COLUMN item_owner SMALLINT UNSIGNED NULL COMMENT ''the shop whose own bill this is (all its goods lines its own items when made final: never owed by us); NULL: ours'', ALGORITHM = INSTANT',
  'DO 0');
PREPARE cw_0083_sinv FROM @cw_0083_sinv;
EXECUTE cw_0083_sinv;
DEALLOCATE PREPARE cw_0083_sinv;

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('stock.own_items_shop','string','"vapebig"',1,'VB3','The shop whose own items (its own stock, not ours) Settings > Own items adds: our figures leave them out')
 ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key = 'stock.own_items_shop'
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);
