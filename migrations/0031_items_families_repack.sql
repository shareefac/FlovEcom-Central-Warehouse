-- 0031_items_families_repack.sql — pack B, Item Management (the owner's 16-module list of 8 Oct 2026; owner answers Q10 "create a
-- product by hand: yes", Q4 "product families: yes", Q3 "multipacks / repack: yes, suggest more than 1 unit only when the single is
-- sold separately"; docs/decisions.md PB1-PB17). The integrator may renumber this file at merge (0030 is reserved for A2b).
--
--  * item_family: a family (the parent: every flavour or strength of one product) groups items. Created, renamed and switched off
--    by a person; never deleted. A name is unique among the families switched on (active_name; the collation ignores case).
--  * item_family_member: which family an item is in, one row per item that was ever put in one (sku had no parent column, and
--    sku is not altered here). Taking an item out sets family_id NULL: the row stays (never deleted); every change is audited.
--  * item_pack: a pre-packed multipack item (its own stock is counted in packs, e.g. a sealed "10 x 10ml" box) and the single item
--    one pack holds, with how many. One definition switched on per pack item (active_pack). It is what a Repack reads, and what
--    the Multipacks screens compare with the existing pack links (a variant matched to an item with more than 1 unit a sale):
--    nothing here changes a link, a unit count or any stock.
--  * item_by_hand: the items made by hand on Products › Add item (sku.origin 'manual', which 0004 already allows), who made them,
--    and the check afterwards by a second person while approvals.item_by_hand_check is on (off by default: it never blocks).
--  * item_name_guard: one row per name an item was made by hand with, keyed by the name as the collation compares it. Making an
--    item by hand writes its row first (insert, or update when it exists): two makings of one name at once wait for each other,
--    so the second reads the first before it decides "the same item exists" (the review of b62c69f, finding 1).
--  * repack: the header extension of a Repack record (document type RPK): which pack, which way (break packs into singles, or make
--    packs from singles), how many packs. Its lines are ordinary document lines; it books through the document base and
--    Movements::bookForDocument (Stock.php stays the only writer of the stock tables, unchanged).
--  * document_type RPK (number series RPK-000001): no reviewer check after it is final, no blocking approval, and the OK first for
--    a big record OFF (the owner's rule: extra approvals start off), each switchable on the Approval rules page; version 1 of its
--    rule in config_change.
--  * Four settings (with their baselines): items.pack_suggest_single_sold (Q3: suggest selling a pack as N singles only when the
--    single is sold separately; on), items.family_bulk_max_rows (the most items one bulk change of a family takes; 200),
--    items.repack_carry_cost (a repack carries the cost of what it takes apart to what it makes; on) and
--    approvals.item_by_hand_check (a second person checks an item made by hand afterwards; off).
--  * channel_listing (units_per_item, id): the Multipacks › Pack links list ("every variant matched with more than 1 unit a
--    sale") reads its range instead of every variant. Online (INPLACE, LOCK = NONE), as 0020, 0024, 0026 did on this table: a second
--    or two on staging's 38,000 variants; written when a variant arrives or its units change.
-- No existing row changes. The old code runs on this schema: it never names the new tables, the new type or the index.

CREATE TABLE item_family (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name            VARCHAR(255)  NOT NULL COMMENT 'what people call the product: all its flavours or strengths together',
  is_active       TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '0 = switched off: it keeps its items, takes no new ones, never deleted',
  version         INT UNSIGNED  NOT NULL DEFAULT 1 COMMENT 'moves with every change (the forms'' optimistic lock)',
  note            VARCHAR(500)  NULL,
  created_by      INT UNSIGNED  NULL,
  created_actor   VARCHAR(64)   NOT NULL,
  created_at      DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_by      INT UNSIGNED  NULL,
  updated_actor   VARCHAR(64)   NOT NULL,
  updated_at      DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  active_name     VARCHAR(255)  GENERATED ALWAYS AS (IF(is_active = 1, name, NULL)) STORED COMMENT 'one family of a name switched on at a time',
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_family_active_name (active_name),
  KEY ix_item_family_list (is_active, name, id),
  KEY ix_item_family_created_by (created_by),
  KEY ix_item_family_updated_by (updated_by),
  CONSTRAINT fk_item_family_created_by FOREIGN KEY (created_by) REFERENCES staff_user (id),
  CONSTRAINT fk_item_family_updated_by FOREIGN KEY (updated_by) REFERENCES staff_user (id),
  CONSTRAINT ck_item_family_name CHECK (CHAR_LENGTH(TRIM(name)) >= 2),
  CONSTRAINT ck_item_family_flags CHECK (is_active IN (0, 1) AND version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='product families (pack B, Q4): a family groups items; switched off, never deleted';

CREATE TABLE item_family_member (
  sku_id          INT UNSIGNED  NOT NULL,
  family_id       INT UNSIGNED  NULL COMMENT 'NULL = taken out of its family (the row stays)',
  version         INT UNSIGNED  NOT NULL DEFAULT 1 COMMENT 'moves with every change of the item''s family',
  set_by          INT UNSIGNED  NULL,
  set_actor       VARCHAR(64)   NOT NULL,
  set_at          DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (sku_id),
  KEY ix_item_family_member_family (family_id, sku_id),
  KEY ix_item_family_member_set_by (set_by),
  CONSTRAINT fk_item_family_member_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_item_family_member_family FOREIGN KEY (family_id) REFERENCES item_family (id),
  CONSTRAINT fk_item_family_member_set_by FOREIGN KEY (set_by) REFERENCES staff_user (id),
  CONSTRAINT ck_item_family_member_version CHECK (version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the family of an item (pack B): one row per item ever put in a family; taken out = NULL, never deleted';

CREATE TABLE item_pack (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  pack_sku_id     INT UNSIGNED  NOT NULL COMMENT 'the pre-packed multipack item: its stock is counted in packs',
  single_sku_id   INT UNSIGNED  NOT NULL COMMENT 'the single item one pack holds',
  units           SMALLINT UNSIGNED NOT NULL COMMENT 'singles in one pack; never changed (switch off and define again)',
  is_active       TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '0 = switched off: kept for the records that name it, never deleted',
  note            VARCHAR(500)  NULL,
  created_by      INT UNSIGNED  NULL,
  created_actor   VARCHAR(64)   NOT NULL,
  created_at      DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  switched_by     INT UNSIGNED  NULL,
  switched_actor  VARCHAR(64)   NULL,
  switched_at     DATETIME(6)   NULL COMMENT 'the last switch off or on',
  active_pack     INT UNSIGNED  GENERATED ALWAYS AS (IF(is_active = 1, pack_sku_id, NULL)) STORED COMMENT 'one definition switched on per pack item',
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_pack_active (active_pack),
  KEY ix_item_pack_pack (pack_sku_id, id),
  KEY ix_item_pack_single (single_sku_id, is_active, id),
  KEY ix_item_pack_list (is_active, id),
  KEY ix_item_pack_created_by (created_by),
  KEY ix_item_pack_switched_by (switched_by),
  CONSTRAINT fk_item_pack_pack FOREIGN KEY (pack_sku_id) REFERENCES sku (id),
  CONSTRAINT fk_item_pack_single FOREIGN KEY (single_sku_id) REFERENCES sku (id),
  CONSTRAINT fk_item_pack_created_by FOREIGN KEY (created_by) REFERENCES staff_user (id),
  CONSTRAINT fk_item_pack_switched_by FOREIGN KEY (switched_by) REFERENCES staff_user (id),
  CONSTRAINT ck_item_pack_items CHECK (pack_sku_id <> single_sku_id),
  CONSTRAINT ck_item_pack_units CHECK (units BETWEEN 2 AND 1000),
  CONSTRAINT ck_item_pack_flags CHECK (is_active IN (0, 1) AND (switched_at IS NULL) = (switched_actor IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='pre-packed multipack items (pack B, Q3): pack item = units x single item; switched off, never deleted';

CREATE TABLE item_by_hand (
  sku_id          INT UNSIGNED  NOT NULL,
  check_state     ENUM('not_asked','waiting','checked') NOT NULL COMMENT 'waiting: approvals.item_by_hand_check was on when it was made',
  created_by      INT UNSIGNED  NOT NULL,
  created_actor   VARCHAR(64)   NOT NULL,
  created_at      DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  checked_by      INT UNSIGNED  NULL,
  checked_actor   VARCHAR(64)   NULL,
  checked_at      DATETIME(6)   NULL,
  check_note      VARCHAR(500)  NULL,
  PRIMARY KEY (sku_id),
  KEY ix_item_by_hand_created (created_at, sku_id),
  KEY ix_item_by_hand_check (check_state, created_at, sku_id),
  KEY ix_item_by_hand_created_by (created_by),
  KEY ix_item_by_hand_checked_by (checked_by),
  CONSTRAINT fk_item_by_hand_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_item_by_hand_created_by FOREIGN KEY (created_by) REFERENCES staff_user (id),
  CONSTRAINT fk_item_by_hand_checked_by FOREIGN KEY (checked_by) REFERENCES staff_user (id),
  CONSTRAINT ck_item_by_hand_checked CHECK ((check_state = 'checked') = (checked_at IS NOT NULL) AND (checked_at IS NULL) = (checked_actor IS NULL)
    AND (checked_by IS NULL OR checked_by <> created_by))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='items made by hand (pack B, Q10) and their check afterwards; append-only for cw_app except the check columns';

CREATE TABLE item_name_guard (
  name_key        CHAR(64)      NOT NULL COMMENT 'SHA2 of the name as the collation compares it (WEIGHT_STRING, utf8mb4_0900_ai_ci)',
  last_at         DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT 'the last time an item of this name was made by hand',
  PRIMARY KEY (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='one row per name an item was made by hand with (pack B): its row lock serialises two makings of one name, so the second reads the first';

CREATE TABLE repack (
  document_id     BIGINT UNSIGNED NOT NULL,
  item_pack_id    INT UNSIGNED  NOT NULL,
  direction       ENUM('break','make') NOT NULL COMMENT 'break: packs into singles; make: packs from singles',
  packs           INT UNSIGNED  NOT NULL COMMENT 'how many packs are broken or made',
  units_per_pack  SMALLINT UNSIGNED NOT NULL COMMENT 'item_pack.units (a definition never changes its units)',
  pack_sku_id     INT UNSIGNED  NOT NULL,
  single_sku_id   INT UNSIGNED  NOT NULL,
  created_at      DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (document_id),
  KEY ix_repack_pack (item_pack_id, document_id),
  KEY ix_repack_pack_sku (pack_sku_id),
  KEY ix_repack_single_sku (single_sku_id),
  CONSTRAINT fk_repack_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_repack_item_pack FOREIGN KEY (item_pack_id) REFERENCES item_pack (id),
  CONSTRAINT fk_repack_pack_sku FOREIGN KEY (pack_sku_id) REFERENCES sku (id),
  CONSTRAINT fk_repack_single_sku FOREIGN KEY (single_sku_id) REFERENCES sku (id),
  CONSTRAINT ck_repack_numbers CHECK (packs >= 1 AND units_per_pack >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='header extension of a Repack record (RPK, pack B): append-only for cw_app; documents are never deleted';

-- ------------------------------------------------------------------------------------------------------------------------------
-- The kind of record: Repack, with its number series and version 1 of its rule.

INSERT INTO document_type (code, prefix, name, phase, review_rule, review_limit_units, approval_rule, approval_limit_units, review_due_days, reject_action,
    size_approval, size_units, size_value) VALUES
 ('RPK',  'RPK',  'Repack',                       'B',  'none', NULL, 'none', NULL, 3, 'reverse', 0, 500, 2000);
INSERT INTO number_series (prefix) VALUES ('RPK');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'document_rule', code, 1, 'baseline', JSON_OBJECT('review_rule', CAST(review_rule AS CHAR), 'review_limit_units', review_limit_units,
      'review_due_days', review_due_days, 'approval_rule', CAST(approval_rule AS CHAR), 'approval_limit_units', approval_limit_units,
      'reject_action', CAST(reject_action AS CHAR), 'size_approval', size_approval, 'size_units', size_units, 'size_value', size_value), 'system:migrate'
  FROM document_type WHERE code IN ('RPK');

-- ------------------------------------------------------------------------------------------------------------------------------
-- The settings (Settings › Items, and Approval rules › Items), each with its version 1.

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('items.pack_suggest_single_sold','bool','true',1,'PB7','A pack item is suggested to be sold as that many singles only when the single is sold separately (owner answer Q3)'),
 ('items.family_bulk_max_rows','int','200',1,'PB5','The most items one bulk change of a family takes'),
 ('items.repack_carry_cost','bool','true',1,'PB10','A repack carries the average cost of what it takes apart to what it makes'),
 ('approvals.item_by_hand_check','bool','false',1,'PB3','An item made by hand is checked afterwards by a second person (it is usable at once)');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('items.pack_suggest_single_sold', 'items.family_bulk_max_rows', 'items.repack_carry_cost',
    'approvals.item_by_hand_check');

-- ------------------------------------------------------------------------------------------------------------------------------
-- The pack links list's index (online).

ALTER TABLE channel_listing
  ADD INDEX ix_channel_listing_units (units_per_item, id),
  ALGORITHM = INPLACE, LOCK = NONE;
