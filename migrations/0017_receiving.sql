-- 0017_receiving.sql — IM6 Receive (+ invoice) with duty-stamp checks (docs/inventory-modules-plan.md IM6; docs/decisions.md
-- I125-I147). The GRN document type (seeded in 0008: post first, a second person reviews every receipt within 3 days, a rejected
-- review reverses it) gets its tables:
--
--  * goods_receipt: the GRN header extension of `document` (keyed document_id; a reversal GRN document has none). The supplier,
--    the purchase order the delivery is against (optional: only 472 of 2,218 ERPNext invoices named one), when the goods arrived
--    (received_at: backdated with a reason for a delivery keyed later from a paper receiving sheet), the supplier's invoice date and
--    delivery note, and the goods-in bench check of the delivery (supplier and paperwork credible, a note, who checked when). The
--    supplier invoice number is document.external_ref; invoice_key is it upper-cased without spaces while the receipt is live
--    (draft, waiting, posted) and NULL once it is cancelled or reversed, so UNIQUE (supplier_id, invoice_key) books one supplier
--    invoice once (decision: "the pair (supplier, invoice number) must be unique").
--  * grn_line: the GRN line extension of `document_line` (removed with its draft line, ON DELETE CASCADE like po_line): the pack
--    (units = packs x units per pack, never "boxes for units"), the provisional cost per pack, the PO line it receives, how it was
--    keyed, the selling mode the desk asks for ("default": the item's last mode, an Out-Of-Stock item its previous mode); the bench
--    findings (the duty stamp on the outer retail pack, its type, a scanned stamp code; short, over, damaged, wrong-item and
--    unstamped units, and what happens to unstamped units; when the bench counted the line); and, written once at posting, what
--    the posting decided (stamp required, the expected duty for information, the selling mode and why (or "kept": nothing of the
--    item accepted, I169), the units accepted into MAIN, put in VERIFY, quarantined in UNSTAMPED (an unstamped delivery's damaged
--    and over units with its unstamped ones, I167), refused, and applied to the PO line).
--  * grn_posting: the write-once anchor of a posted receipt's module content (goods_receipt + grn_line at posting), append-only
--    for the app login, checked nightly (G3; like po_posting P2 and document_posting I33).
--  * incident: the incident register (IM6 line exceptions now; IM2 / IM13 later): one row per exception of a posted receipt line
--    (unstamped, damaged, wrong item, short, over), where its units went (VERIFY, UNSTAMPED quarantine, refused at the door, not
--    received), open until a person resolves or dismisses it. Append-only for the app login except the resolution columns.
--  * item_selling_mode / item_selling_mode_log: the item's selling mode as CW last set it (In-Stock / From-Warehouse /
--    Out-Of-Stock, with the mode before it went Out-Of-Stock), written by receipt postings (for the items they accept something of
--    into MAIN, I169) for the site stock writer (IM10) to
--    send with the quantity; every write logged (append-only), the row checked against its last log row (G6). No FK to sku on
--    either (written inside postings: an FK would take the item's sku row S-lock before the stock locks, D15, I21).
--  * four receiving.* settings: the date from which an unstamped duty-liable line is refused or quarantined (decision 8: 1 Jan
--    2027), the duty rate behind the expected duty shown for information (22p a ml = GBP 2.20 per 10 ml), how far back a paper
--    receiving sheet may be dated, and the mode an Out-Of-Stock item gets on receipt when its previous mode is not known.
-- No backfill: no receipt exists before this migration. Re-runnable (the migrator records a file only after every statement
-- succeeded): IF NOT EXISTS and INSERT IGNORE.

INSERT IGNORE INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('receiving.unstamped_refusal_from','date','"2027-01-01"',0,'8','From this date (the delivery''s received date, UK) an unstamped duty-liable line is refused or quarantined with an incident; before it, unstamped stock is accepted only with the supplier''s evidence that it was made or imported before 1 Oct 2026'),
 ('receiving.duty_pence_per_ml','int','22',0,NULL,'Vaping Products Duty in pence per ml (GBP 2.20 per 10 ml from 1 Oct 2026): the expected duty a receipt shows for information, rounded down to the penny per unit'),
 ('receiving.backdate_max_days','int','30',1,'11','How many days back a receipt may say the goods arrived (a delivery keyed later from a paper receiving sheet while CW was down; a reason is compulsory)'),
 ('receiving.mode_after_out_of_stock','string','"From-Warehouse"',1,NULL,'The selling mode a receipt gives an Out-Of-Stock item (or an item CW has no mode for) when its previous mode is not known: In-Stock or From-Warehouse');

CREATE TABLE IF NOT EXISTS goods_receipt (
  document_id      BIGINT UNSIGNED NOT NULL,
  supplier_id      INT UNSIGNED    NOT NULL,
  po_document_id   BIGINT UNSIGNED NULL COMMENT 'the purchase order the delivery is against (optional)',
  invoice_key      VARCHAR(64)     NULL COMMENT 'document.external_ref (the supplier invoice number) upper-cased without spaces while live; NULL once cancelled or reversed',
  invoice_date     DATE            NULL COMMENT 'the date on the supplier''s invoice',
  delivery_note    VARCHAR(64)     NULL COMMENT 'the supplier''s delivery note number',
  received_at      DATETIME(6)     NOT NULL COMMENT 'when the goods arrived (UTC); before the posting day only with backdate_reason',
  paper_sheet      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1: keyed later from a paper receiving sheet (CW was down)',
  backdate_reason  VARCHAR(500)    NULL,
  paperwork_ok     TINYINT(1)      NULL COMMENT 'bench: supplier and paperwork credible (1 yes, 0 no, NULL not checked)',
  bench_note       VARCHAR(500)    NULL,
  checked_by       INT UNSIGNED    NULL COMMENT 'who did the goods-in bench check (never reviews the receipt, G8)',
  checked_actor    VARCHAR(64)     NULL,
  checked_at       DATETIME(6)     NULL,
  created_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (document_id),
  UNIQUE KEY uq_goods_receipt_invoice (supplier_id, invoice_key),
  KEY ix_goods_receipt_po (po_document_id),
  KEY ix_goods_receipt_received (received_at),
  KEY ix_goods_receipt_checked_by (checked_by),
  CONSTRAINT fk_goods_receipt_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_goods_receipt_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id),
  CONSTRAINT fk_goods_receipt_po FOREIGN KEY (po_document_id) REFERENCES document (id),
  CONSTRAINT fk_goods_receipt_checked_by FOREIGN KEY (checked_by) REFERENCES staff_user (id),
  CONSTRAINT ck_goods_receipt_flags CHECK (paper_sheet IN (0, 1) AND COALESCE(paperwork_ok, 0) IN (0, 1)),
  CONSTRAINT ck_goods_receipt_checked CHECK ((checked_at IS NULL) = (checked_actor IS NULL) AND (checked_at IS NOT NULL OR checked_by IS NULL)
    AND (checked_at IS NULL OR paperwork_ok IS NOT NULL)),
  CONSTRAINT ck_goods_receipt_key CHECK (invoice_key IS NULL OR (invoice_key <> '' AND NOT REGEXP_LIKE(invoice_key, '[[:space:]]'))),
  CONSTRAINT ck_goods_receipt_paper CHECK (paper_sheet = 0 OR backdate_reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='GRN header extension of document (IM6); a reversal GRN document has none';

CREATE TABLE IF NOT EXISTS grn_line (
  document_id           BIGINT UNSIGNED NOT NULL,
  line_no               INT UNSIGNED    NOT NULL,
  supplier_item_id      INT UNSIGNED    NULL,
  supplier_code         VARCHAR(64)     NULL,
  purchase_unit         VARCHAR(32)     NOT NULL DEFAULT 'each',
  units_per_pack        INT UNSIGNED    NOT NULL DEFAULT 1,
  packs                 INT UNSIGNED    NOT NULL COMMENT 'packs on the paperwork (invoice / delivery note); units = packs x units_per_pack',
  pack_price            DECIMAL(14,4)   NOT NULL COMMENT 'provisional cost: GBP excl. VAT per pack (the invoice, the PO or the last price; IM7/IM8 settle it)',
  po_line_no            INT UNSIGNED    NULL COMMENT 'the line of goods_receipt.po_document_id this line receives',
  entry                 ENUM('manual','scan','po','file') NOT NULL DEFAULT 'manual',
  mode_choice           ENUM('default','In-Stock','From-Warehouse','Out-Of-Stock') NOT NULL DEFAULT 'default',
  checked_at            DATETIME(6)     NULL COMMENT 'when the bench last recorded this line',
  stamp_on_pack         TINYINT(1)      NULL COMMENT 'the UK duty stamp is on the outer retail pack and seals it: 1 yes, 0 no, NULL not checked',
  stamp_type            ENUM('digital','transitional') NULL,
  stamp_code            VARCHAR(128)    NULL COMMENT 'a scanned stamp code (ready for HMRC''s retailer scanning service)',
  short_units           INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'on the paperwork, not delivered',
  over_units            INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'delivered beyond the paperwork (to VERIFY)',
  damaged_units         INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'to VERIFY',
  wrong_item_units      INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'something else delivered for this line (to VERIFY under this item)',
  unstamped_units       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'duty-liable units without a valid stamp',
  unstamped_action      ENUM('quarantine','refuse','accept_pre_october') NULL,
  pre_october_evidence  VARCHAR(500)    NULL COMMENT 'the supplier''s evidence that unstamped stock was made or imported before 1 Oct 2026 (before receiving.unstamped_refusal_from only)',
  stamp_required        TINYINT(1)      NULL COMMENT 'at posting: duty-liable, or not answered (ItemCompliance::receiving, I119)',
  duty_ml               DECIMAL(6,1)    NULL COMMENT 'at posting: ml per unit the expected duty used',
  expected_duty         DECIMAL(14,2)   NULL COMMENT 'at posting, for information: units x per-unit duty (ml x the rate, rounded down to the penny)',
  selling_mode          ENUM('In-Stock','From-Warehouse','Out-Of-Stock') NULL COMMENT 'at posting: the mode the receipt gave the item',
  mode_source           ENUM('last','previous','fallback','chosen','kept') NULL COMMENT 'kept: nothing of the item was accepted into MAIN, its mode was left (selling_mode = the mode it kept, NULL when none known)',
  accepted_units        INT UNSIGNED    NULL COMMENT 'at posting: booked into MAIN',
  verify_units          INT UNSIGNED    NULL COMMENT 'at posting: booked into VERIFY (wrong item; damaged and over unless the delivery is unstamped)',
  quarantine_units      INT UNSIGNED    NULL COMMENT 'at posting: booked into UNSTAMPED (unstamped, quarantined; with an unstamped delivery''s damaged and over units)',
  refused_units         INT UNSIGNED    NULL COMMENT 'at posting: unstamped units refused at the door (not booked; with an unstamped delivery''s damaged and over units)',
  po_units              INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'at posting: units applied to the PO line (the accepted units)',
  PRIMARY KEY (document_id, line_no),
  KEY ix_grn_line_supplier_item (supplier_item_id),
  CONSTRAINT fk_grn_line_line FOREIGN KEY (document_id, line_no) REFERENCES document_line (document_id, line_no) ON DELETE CASCADE,
  CONSTRAINT fk_grn_line_supplier_item FOREIGN KEY (supplier_item_id) REFERENCES supplier_item (id),
  CONSTRAINT ck_grn_line_pack CHECK (units_per_pack BETWEEN 1 AND 100000 AND packs BETWEEN 1 AND 1000000),
  CONSTRAINT ck_grn_line_price CHECK (pack_price >= 0),
  CONSTRAINT ck_grn_line_exceptions CHECK (short_units + damaged_units + wrong_item_units + unstamped_units <= packs * units_per_pack
    AND over_units <= 10000000),
  CONSTRAINT ck_grn_line_unstamped CHECK ((unstamped_units > 0) = (unstamped_action IS NOT NULL)
    AND (unstamped_action <=> 'accept_pre_october') = (pre_october_evidence IS NOT NULL)),
  CONSTRAINT ck_grn_line_stamp CHECK (COALESCE(stamp_on_pack, 0) IN (0, 1) AND (stamp_type IS NULL OR stamp_on_pack <=> 1)),
  CONSTRAINT ck_grn_line_posted CHECK ((mode_source IS NULL) = (accepted_units IS NULL) AND (selling_mode IS NULL OR mode_source IS NOT NULL)
    AND (selling_mode IS NOT NULL OR mode_source IS NULL OR mode_source = 'kept')
    AND (accepted_units IS NULL) = (verify_units IS NULL) AND (accepted_units IS NULL) = (quarantine_units IS NULL)
    AND (accepted_units IS NULL) = (refused_units IS NULL) AND (accepted_units IS NULL) = (stamp_required IS NULL)
    AND (expected_duty IS NULL) = (duty_ml IS NULL) AND (accepted_units IS NOT NULL OR (po_units = 0 AND expected_duty IS NULL))
    AND po_units <= COALESCE(accepted_units, 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='GRN line extension of document_line (cascade with the draft line)';

CREATE TABLE IF NOT EXISTS grn_posting (
  document_id   BIGINT UNSIGNED NOT NULL,
  content_hash  CHAR(64)    NOT NULL,
  content       MEDIUMTEXT  NOT NULL COMMENT 'canonical JSON of goods_receipt (all but invoice_key and the timestamps) + every grn_line field at posting',
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (document_id),
  CONSTRAINT fk_grn_posting_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT ck_grn_posting_hash CHECK (REGEXP_LIKE(content_hash, '^[0-9a-f]{64}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='write-once anchor of a posted receipt''s module content (append-only; G3)';

CREATE TABLE IF NOT EXISTS incident (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source          ENUM('goods_receipt') NOT NULL DEFAULT 'goods_receipt',
  kind            ENUM('unstamped','damaged','wrong_item','short','over') NOT NULL,
  disposition     ENUM('verify','quarantine','refused','not_received') NOT NULL COMMENT 'where the units went',
  document_id     BIGINT UNSIGNED NOT NULL,
  line_no         INT UNSIGNED    NOT NULL,
  sku_id          INT UNSIGNED    NOT NULL COMMENT 'no FK (written inside the posting; checked nightly, G5)',
  supplier_id     INT UNSIGNED    NULL,
  warehouse_id    SMALLINT UNSIGNED NULL COMMENT 'VERIFY or UNSTAMPED; NULL when nothing was booked (short, refused)',
  units           INT UNSIGNED    NOT NULL,
  detail          JSON            NULL,
  status          ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  resolution      VARCHAR(500)    NULL,
  resolved_by     INT UNSIGNED    NULL,
  resolved_actor  VARCHAR(64)     NULL,
  resolved_at     DATETIME(6)     NULL,
  opened_by       INT UNSIGNED    NULL,
  opened_actor    VARCHAR(64)     NOT NULL,
  opened_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  dedupe_key      VARCHAR(191)    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_incident_dedupe (dedupe_key),
  KEY ix_incident_status (status, opened_at),
  KEY ix_incident_document (document_id, line_no),
  KEY ix_incident_sku (sku_id),
  KEY ix_incident_supplier (supplier_id),
  KEY ix_incident_warehouse (warehouse_id),
  KEY ix_incident_resolved_by (resolved_by),
  KEY ix_incident_opened_by (opened_by),
  CONSTRAINT fk_incident_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_incident_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id),
  CONSTRAINT fk_incident_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouse (id),
  CONSTRAINT fk_incident_resolved_by FOREIGN KEY (resolved_by) REFERENCES staff_user (id),
  CONSTRAINT fk_incident_opened_by FOREIGN KEY (opened_by) REFERENCES staff_user (id),
  CONSTRAINT ck_incident_units CHECK (units > 0),
  CONSTRAINT ck_incident_closed CHECK ((status = 'open') = (resolved_at IS NULL) AND (resolved_at IS NULL) = (resolved_actor IS NULL)
    AND (status = 'open' OR resolution IS NOT NULL)),
  CONSTRAINT ck_incident_where CHECK ((disposition IN ('verify', 'quarantine')) = (warehouse_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='incident register: receipt line exceptions (IM6); append-only for cw_app but for the resolution columns';

CREATE TABLE IF NOT EXISTS item_selling_mode (
  sku_id         INT UNSIGNED NOT NULL COMMENT 'no FK (written inside postings; G6 checks it)',
  mode           ENUM('In-Stock','From-Warehouse','Out-Of-Stock') NOT NULL,
  previous_mode  ENUM('In-Stock','From-Warehouse') NULL COMMENT 'the mode before the item went Out-Of-Stock (kept while it is)',
  version        INT UNSIGNED NOT NULL DEFAULT 1,
  document_id    BIGINT UNSIGNED NULL COMMENT 'the receipt that set it last',
  updated_by     INT UNSIGNED NULL,
  updated_actor  VARCHAR(64)  NOT NULL,
  updated_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (sku_id),
  KEY ix_item_selling_mode_document (document_id),
  KEY ix_item_selling_mode_by (updated_by),
  CONSTRAINT fk_item_selling_mode_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_item_selling_mode_by FOREIGN KEY (updated_by) REFERENCES staff_user (id),
  CONSTRAINT ck_item_selling_mode_previous CHECK (previous_mode IS NULL OR mode = 'Out-Of-Stock'),
  CONSTRAINT ck_item_selling_mode_version CHECK (version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the item''s selling mode as CW last set it (receipts, IM6), for the site stock writer (IM10)';

CREATE TABLE IF NOT EXISTS item_selling_mode_log (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku_id         INT UNSIGNED NOT NULL,
  version        INT UNSIGNED NOT NULL COMMENT 'item_selling_mode.version after this write (1, 2, 3 ...)',
  mode_before    ENUM('In-Stock','From-Warehouse','Out-Of-Stock') NULL COMMENT 'the mode the receipt found (CW''s, else the sites'' last snapshot; NULL: none known)',
  mode_after     ENUM('In-Stock','From-Warehouse','Out-Of-Stock') NOT NULL,
  previous_mode  ENUM('In-Stock','From-Warehouse') NULL COMMENT 'item_selling_mode.previous_mode after this write',
  mode_source    ENUM('last','previous','fallback','chosen') NOT NULL,
  reason         ENUM('receipt') NOT NULL DEFAULT 'receipt',
  document_id    BIGINT UNSIGNED NOT NULL,
  line_no        INT UNSIGNED NULL,
  actor          VARCHAR(64)  NOT NULL,
  staff_user_id  INT UNSIGNED NULL,
  created_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_selling_mode_log_version (sku_id, version),
  KEY ix_item_selling_mode_log_document (document_id),
  KEY ix_item_selling_mode_log_staff (staff_user_id),
  CONSTRAINT fk_item_selling_mode_log_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_item_selling_mode_log_staff FOREIGN KEY (staff_user_id) REFERENCES staff_user (id),
  CONSTRAINT ck_item_selling_mode_log_previous CHECK (previous_mode IS NULL OR mode_after = 'Out-Of-Stock')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='every write of item_selling_mode (append-only; G6)';
