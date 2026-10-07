-- 0016_item_cards.sql — IM3 Item card: the legal and buying fields of an item, the TRPR rules, outer-case barcodes, the barcode
-- sync from the sites and its review queue (docs/inventory-modules-plan.md IM3; docs/decisions.md I100-I112, I113-I124).
--
--  * item_card: one row per item (1:1 with sku, made on the first change; no row = nothing entered yet). Every value is typed by
--    a person on the item page, accepted by a person from a proposal (the matcher's identity card, the linked listings'
--    normaliser features), or imported by a person from a CSV file: nothing is ever written by a job. NO_DELETE for cw_app (a
--    card is changed, never removed). `version` moves with every write (the forms' optimistic lock). A change of a legal field
--    clears the confirmation (confirmed_by/actor/at); `first_confirmed_at` is never cleared. `confirmed_breaches` holds the rules
--    the LAST confirmation acknowledged: they BLOCK the item until a person confirms the card again, whatever is edited
--    meanwhile; a rule broken by an edit since is a warning until then (I103, I113). `liquid_ml` 0 = "no tank", a device / kit
--    only (I115). `single_use` is set by a person only (never from a "disposable" form). `flavour_status`: proposed (from a CSV
--    file) or confirmed (typed or accepted on the item page, or confirmed with the card), so a flavour report can read confirmed
--    values only (memory note "flavour is not a field").
--  * item_card_change: one row per write of a card (change, accept, import, confirm), append-only for cw_app: the version it
--    made, what changed (before/after) and the whole card after it (`card`), so the nightly invariants IC1-IC3 find a card
--    rewritten outside CW\Catalogue\ItemCards (the app login can UPDATE item_card; it cannot rewrite this history).
--  * barcode_review: the barcode review queue (I106-I108). A barcode a linked listing carries that is already on ANOTHER item
--    (`on_another_item`), or a new barcode of a listing linked with units per item <> 1 (`multipack_listing`: is it the pack's
--    barcode or the single unit's?), opens a row; a person decides (keep it on the item that has it, move it, unusable on both,
--    add with N units per scan, do not add). A barcode removed from an item by a person is recorded as a decided row
--    (`removed`), so the sync never adds it back. Append-only for cw_app except the decision columns; at most one open row per
--    (barcode, claiming item): open_key. A `move` also records a decided `moved_away` row for the item the barcode left (its own
--    listing may still carry it), so the next sync does not take it back (I116).
--  * import_run.kind gains `item_cards` (the CSV import's runs, dry runs included).
-- No change to sku or sku_barcode: units_per_scan (outer cases, "this barcode = 10 units") and is_usable exist since 0001.
-- Re-runnable (the migrator records a file only after every statement succeeded): IF NOT EXISTS, and an idempotent MODIFY.

CREATE TABLE IF NOT EXISTS item_card (
  sku_id              INT UNSIGNED  NOT NULL,
  version             INT UNSIGNED  NOT NULL DEFAULT 1 COMMENT 'moves with every write (optimistic lock of the forms and the CSV card_version)',
  liquid_ml           DECIMAL(6,1)  NULL COMMENT 'ml of liquid in a bottle, or the capacity of a tank, pod or device; to 0.1 ml; 0 = no tank (device / kit only)',
  nicotine_mg         DECIMAL(5,2)  NULL COMMENT 'nicotine in mg/ml; 0 = nicotine-free',
  product_type        ENUM('e_liquid','shortfill','nic_shot','prefilled_pod','device_kit','single_use','coil','tank','accessory') NULL,
  duty_liable         TINYINT(1)    NULL COMMENT 'Vaping Products Duty: 1 yes, 0 no, NULL not said yet',
  ecid                VARCHAR(32)   NULL COMMENT 'ECID / GB-ID of the notified product (the producer''s duty; due-diligence evidence for us)',
  manufacturer        VARCHAR(128)  NULL,
  brand               VARCHAR(128)  NULL COMMENT 'the brand on the box (sku.brand stays the catalogue brand used for linking and reorder groups)',
  flavour             VARCHAR(255)  NULL,
  flavour_status      ENUM('proposed','confirmed') NULL COMMENT 'NULL iff no flavour',
  single_use          TINYINT(1)    NULL COMMENT 'set by a person only, never inferred: 1 single-use, 0 not, NULL not said yet',
  discontinued        TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1: discontinued / do not reorder (the reorder list never suggests it)',
  confirmed_by        INT UNSIGNED  NULL,
  confirmed_actor     VARCHAR(64)   NULL,
  confirmed_at        DATETIME(6)   NULL COMMENT 'a person confirmed the fields as they are now; cleared when a legal field changes',
  confirmed_breaches  JSON          NULL COMMENT 'the rules the LAST confirmation acknowledged the item breaks: blocked until the next confirmation',
  first_confirmed_at  DATETIME(6)   NULL COMMENT 'never cleared: from then on the rules block instead of warning',
  updated_by          INT UNSIGNED  NULL,
  updated_actor       VARCHAR(64)   NOT NULL,
  updated_at          DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  created_at          DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (sku_id),
  KEY ix_item_card_confirmed (confirmed_at),
  KEY ix_item_card_type (product_type),
  KEY ix_item_card_confirmed_by (confirmed_by),
  KEY ix_item_card_updated_by (updated_by),
  CONSTRAINT fk_item_card_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_item_card_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES staff_user (id),
  CONSTRAINT fk_item_card_updated_by FOREIGN KEY (updated_by) REFERENCES staff_user (id),
  CONSTRAINT ck_item_card_version CHECK (version >= 1),
  CONSTRAINT ck_item_card_ml CHECK (liquid_ml IS NULL OR (liquid_ml > 0 AND liquid_ml <= 5000) OR (liquid_ml = 0 AND product_type <=> 'device_kit')),
  CONSTRAINT ck_item_card_mg CHECK (nicotine_mg IS NULL OR (nicotine_mg >= 0 AND nicotine_mg <= 100)),
  CONSTRAINT ck_item_card_flags CHECK (COALESCE(duty_liable, 0) IN (0, 1) AND COALESCE(single_use, 0) IN (0, 1) AND discontinued IN (0, 1)),
  CONSTRAINT ck_item_card_flavour CHECK ((flavour IS NULL) = (flavour_status IS NULL)),
  CONSTRAINT ck_item_card_ecid CHECK (ecid IS NULL OR REGEXP_LIKE(ecid, '^[A-Z0-9]+(-[A-Z0-9]+)*$', 'c')),
  CONSTRAINT ck_item_card_confirmed CHECK ((confirmed_at IS NULL) = (confirmed_actor IS NULL) AND (confirmed_at IS NOT NULL OR confirmed_by IS NULL)
    AND (first_confirmed_at IS NOT NULL OR confirmed_breaches IS NULL) AND (confirmed_at IS NULL OR first_confirmed_at IS NOT NULL)
    AND (confirmed_breaches IS NULL OR (JSON_TYPE(confirmed_breaches) = 'ARRAY' AND JSON_LENGTH(confirmed_breaches) > 0))),
  CONSTRAINT ck_item_card_single_use_type CHECK (product_type IS NULL OR product_type <> 'single_use' OR single_use <=> 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='IM3 item card: legal and buying fields, one row per item, written only by CW Catalogue ItemCards (history in item_card_change)';

CREATE TABLE IF NOT EXISTS item_card_change (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku_id          INT UNSIGNED  NOT NULL,
  version         INT UNSIGNED  NOT NULL COMMENT 'the card version this write made (1, 2, 3 ...)',
  kind            ENUM('change','accept','import','confirm') NOT NULL,
  changes         JSON          NULL COMMENT '{field: {before, after}}; NULL for a confirm',
  card            JSON          NOT NULL COMMENT 'every field of the card after this write (the invariants compare it with item_card)',
  detail          JSON          NULL COMMENT 'accept: the proposal source; import: the run; confirm: the rules acknowledged',
  staff_user_id   INT UNSIGNED  NULL,
  actor           VARCHAR(64)   NOT NULL,
  created_at      DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_card_change_version (sku_id, version),
  KEY ix_item_card_change_staff (staff_user_id),
  CONSTRAINT fk_item_card_change_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_item_card_change_staff FOREIGN KEY (staff_user_id) REFERENCES staff_user (id),
  CONSTRAINT ck_item_card_change_version CHECK (version >= 1),
  CONSTRAINT ck_item_card_change_kind CHECK ((kind = 'confirm') = (changes IS NULL)),
  CONSTRAINT ck_item_card_change_card CHECK (JSON_TYPE(card) = 'OBJECT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='append-only history of item_card (cw_app: SELECT, INSERT)';

CREATE TABLE IF NOT EXISTS barcode_review (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  barcode          VARCHAR(64)   NOT NULL COMMENT 'GTIN key (Gtin::key: digits, no leading zeros)',
  reason           ENUM('on_another_item','multipack_listing','removed') NOT NULL,
  claimant_sku_id  INT UNSIGNED  NOT NULL COMMENT 'the item a linked listing of which carries the barcode (removed: the item it was removed from)',
  holder_sku_id    INT UNSIGNED  NULL COMMENT 'the item that held it in sku_barcode when the row was opened (NULL: nobody)',
  listing_id       INT UNSIGNED  NULL COMMENT 'the listing that carries it',
  units_per_item   SMALLINT UNSIGNED NULL COMMENT 'that listing''s units per item when the row was opened',
  status           ENUM('open','decided') NOT NULL DEFAULT 'open',
  decision         ENUM('keep_holder','move','unusable','add','dismiss','removed','moved_away') NULL COMMENT 'moved_away: written by a move, for the item the barcode left',
  decided_units    SMALLINT UNSIGNED NULL COMMENT 'add / move: the units per scan given to the barcode',
  decided_by       INT UNSIGNED  NULL,
  decided_actor    VARCHAR(64)   NULL,
  decided_at       DATETIME(6)   NULL,
  note             VARCHAR(500)  NULL,
  opened_by        INT UNSIGNED  NULL,
  opened_actor     VARCHAR(64)   NOT NULL COMMENT 'system:sync_barcodes, or the person who removed a barcode',
  created_at       DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  open_key         VARCHAR(80) GENERATED ALWAYS AS (IF(status = 'open', CONCAT(barcode, ':', claimant_sku_id), NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_barcode_review_open (open_key),
  KEY ix_barcode_review_pair (barcode, claimant_sku_id, id),
  KEY ix_barcode_review_status (status, id),
  KEY ix_barcode_review_claimant (claimant_sku_id),
  KEY ix_barcode_review_holder (holder_sku_id),
  KEY ix_barcode_review_decided_by (decided_by),
  KEY ix_barcode_review_opened_by (opened_by),
  CONSTRAINT fk_barcode_review_claimant FOREIGN KEY (claimant_sku_id) REFERENCES sku (id),
  CONSTRAINT fk_barcode_review_holder FOREIGN KEY (holder_sku_id) REFERENCES sku (id),
  CONSTRAINT fk_barcode_review_decided_by FOREIGN KEY (decided_by) REFERENCES staff_user (id),
  CONSTRAINT fk_barcode_review_opened_by FOREIGN KEY (opened_by) REFERENCES staff_user (id),
  CONSTRAINT ck_barcode_review_barcode CHECK (REGEXP_LIKE(barcode, '^[1-9][0-9]{7,13}$', 'c')),
  CONSTRAINT ck_barcode_review_decided CHECK ((status = 'decided') = (decision IS NOT NULL) AND (status = 'decided') = (decided_at IS NOT NULL)
    AND (status = 'decided') = (decided_actor IS NOT NULL) AND (status = 'decided' OR decided_by IS NULL)),
  CONSTRAINT ck_barcode_review_units CHECK ((decided_units IS NULL OR decided_units >= 1) AND (units_per_item IS NULL OR units_per_item >= 1)),
  CONSTRAINT ck_barcode_review_removed CHECK ((reason = 'removed') = (decision <=> 'removed')),
  CONSTRAINT ck_barcode_review_moved_away CHECK (decision IS NULL OR decision <> 'moved_away' OR (reason = 'on_another_item' AND status = 'decided'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='barcode review queue (IM3): append-only for cw_app except the decision columns';

ALTER TABLE import_run MODIFY COLUMN kind ENUM('erp_suppliers','erp_supplier_items','erp_open_pos','item_cards') NOT NULL;
