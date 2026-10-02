-- 0010_purchase_orders.sql — Phase I-2 task 2 (pos): IM5 purchase orders, the first real document type (docs/decisions.md I48-I59).
-- The PO document_type row becomes: post first, a second person reviews every PO within 7 days (review_rule 'all'); a PO whose
-- net total (excl. VAT) is above GBP 10,000 waits for a blocking approval (approval_rule 'over_value', whole GBP); rejecting a
-- PO's review RECORDS the rejection and cancels nothing (reject_action 'record': the order may already be with the supplier,
-- the buyer cancels or amends it). All provisional (owner decision 11, owner to confirm; bin/document_rules.php changes them).
-- Three reversal reasons for PO cancellations and amendments; the po.* settings; the PO tables:
--   purchase_order  the PO header extension of `document` (supplier, state after approval, totals, the snapshots of the
--                   company and the supplier at approval, sent / closed); a reversal PO document has none;
--   po_line         the PO line extension of `document_line` (packs, pack price, VAT code), removed with its draft line
--                   (ON DELETE CASCADE: Documents::setLines replaces draft lines);
--   po_posting      the write-once anchor of a PO's module content at approval (append-only for the app login; P2, like I33).
-- No backfill: no PO exists before this migration. Nothing here touches stock.
-- Spec DDL corrected (safer, I58): ck_purchase_order_sent / ck_purchase_order_closed compare the state NULL-safely (<=>): with
-- the spec's `state = 'closed'` an unposted row (state NULL) carrying closed_at made the CHECK NULL, which MySQL accepts.

ALTER TABLE document_type
  MODIFY approval_rule ENUM('none','positive_without_supplier_doc','over_value') NOT NULL DEFAULT 'none',
  ADD COLUMN reject_action ENUM('reverse','record') NOT NULL DEFAULT 'reverse'
    COMMENT 'rejecting a posted document''s review: reverse it (I19) or only record the rejection (PO: the order may be with the supplier)' AFTER review_due_days;
-- over_value: approval_limit_units holds WHOLE GBP; handler->approvalUnits() = ceil(net total excl. VAT) (provisional, decision 11)
UPDATE document_type SET review_rule = 'all', review_limit_units = NULL, approval_rule = 'over_value', approval_limit_units = 10000,
  review_due_days = 7, reject_action = 'record' WHERE code = 'PO';

INSERT INTO reason_code (sort_order, code, label, applies_to, direction, needs_note, is_gift, system_only) VALUES
 (182,'po_amended','Replaced by an amended order','reversal','either',0,0,0),
 (184,'supplier_cannot_supply','Supplier cannot supply','reversal','either',0,0,0),
 (186,'not_needed','No longer needed','reversal','either',0,0,0);

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('po.terms','text','"Please quote our order number on your delivery note and invoice. Deliver the quantities and pack sizes shown. Every duty-liable vaping liquid must carry a UK duty stamp on its retail packaging; unstamped goods are refused."',1,NULL,'Terms printed under every PO'),
 ('po.default_vat_code','string','"S"',1,NULL,'VAT code of a new PO line when the supplier has none'),
 ('po.over_delivery_tolerance_pct','int','10',1,'11','Over-delivery accepted at goods-in (used from Phase I-3)');

CREATE TABLE purchase_order (
  document_id         BIGINT UNSIGNED NOT NULL,
  supplier_id         INT UNSIGNED NOT NULL,
  state               ENUM('approved','sent','part_received','received','closed','cancelled') NULL
                      COMMENT 'NULL until posted (document.status says draft / awaiting_approval / cancelled draft)',
  source              ENUM('manual','reorder','copy','amend','import_file','erp_seed') NOT NULL DEFAULT 'manual',
  expected_date       DATE          NULL,
  amends_document_id  BIGINT UNSIGNED NULL COMMENT 'the PO this one replaces (reversed with reason po_amended)',
  currency            CHAR(3)       NOT NULL DEFAULT 'GBP',
  net_total           DECIMAL(14,2) NOT NULL DEFAULT 0,
  vat_total           DECIMAL(14,2) NOT NULL DEFAULT 0,
  gross_total         DECIMAL(14,2) NOT NULL DEFAULT 0,
  company_snapshot    JSON          NULL COMMENT 'Settings::company() at approval (decision 9)',
  supplier_snapshot   JSON          NULL COMMENT 'code, name, legal_name, address, vat_number, email at approval',
  sent_at             DATETIME(6)   NULL,
  sent_by             INT UNSIGNED  NULL,
  sent_via            ENUM('email','portal','phone','in_person','imported','other') NULL,
  sent_to             VARCHAR(191)  NULL,
  sent_file_id        BIGINT UNSIGNED NULL COMMENT 'the PDF as sent (file store, when configured)',
  closed_at           DATETIME(6)   NULL,
  closed_by           INT UNSIGNED  NULL,
  close_reason        VARCHAR(500)  NULL,
  created_at          DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at          DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (document_id),
  KEY ix_purchase_order_supplier (supplier_id, state),
  KEY ix_purchase_order_state (state),
  KEY ix_purchase_order_amends (amends_document_id),
  KEY ix_purchase_order_sent_by (sent_by), KEY ix_purchase_order_closed_by (closed_by), KEY ix_purchase_order_file (sent_file_id),
  CONSTRAINT fk_purchase_order_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_purchase_order_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id),
  CONSTRAINT fk_purchase_order_amends FOREIGN KEY (amends_document_id) REFERENCES document (id),
  CONSTRAINT fk_purchase_order_sent_by FOREIGN KEY (sent_by) REFERENCES staff_user (id),
  CONSTRAINT fk_purchase_order_closed_by FOREIGN KEY (closed_by) REFERENCES staff_user (id),
  CONSTRAINT fk_purchase_order_file FOREIGN KEY (sent_file_id) REFERENCES stored_file (id),
  CONSTRAINT ck_purchase_order_currency CHECK (currency = 'GBP'),
  CONSTRAINT ck_purchase_order_totals CHECK (net_total >= 0 AND vat_total >= 0 AND gross_total = net_total + vat_total),
  CONSTRAINT ck_purchase_order_snapshots CHECK (state IS NULL OR (company_snapshot IS NOT NULL AND supplier_snapshot IS NOT NULL)),
  CONSTRAINT ck_purchase_order_sent CHECK ((sent_at IS NULL) = (sent_via IS NULL) AND (NOT (state <=> 'sent') OR sent_at IS NOT NULL)),
  CONSTRAINT ck_purchase_order_closed CHECK ((state <=> 'closed') = (closed_at IS NOT NULL) AND (closed_at IS NULL OR close_reason IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='PO header extension of document (IM5); a reversal PO document has none';

CREATE TABLE po_line (
  document_id       BIGINT UNSIGNED NOT NULL,
  line_no           INT UNSIGNED  NOT NULL,
  kind              ENUM('item','charge') NOT NULL DEFAULT 'item',
  supplier_item_id  INT UNSIGNED  NULL,
  supplier_code     VARCHAR(64)   NULL,
  purchase_unit     VARCHAR(32)   NOT NULL DEFAULT 'each',
  units_per_pack    INT UNSIGNED  NOT NULL DEFAULT 1,
  packs             INT UNSIGNED  NOT NULL DEFAULT 1,
  pack_price        DECIMAL(14,4) NOT NULL COMMENT 'GBP excl. VAT per purchase unit (charge line: the amount)',
  vat_code          VARCHAR(4)    NOT NULL,
  vat_rate          DECIMAL(5,2)  NOT NULL,
  suggested_units   INT UNSIGNED  NULL COMMENT 'reorder suggestion when the line was made (analysis only)',
  received_units    INT           NOT NULL DEFAULT 0 COMMENT 'central units received (GRN postings, Phase I-3); never in the posting hash',
  PRIMARY KEY (document_id, line_no),
  KEY ix_po_line_supplier_item (supplier_item_id),
  KEY ix_po_line_vat (vat_code),
  CONSTRAINT fk_po_line_line FOREIGN KEY (document_id, line_no) REFERENCES document_line (document_id, line_no) ON DELETE CASCADE,
  CONSTRAINT fk_po_line_supplier_item FOREIGN KEY (supplier_item_id) REFERENCES supplier_item (id),
  CONSTRAINT fk_po_line_vat FOREIGN KEY (vat_code) REFERENCES vat_code (code),
  CONSTRAINT ck_po_line_pack CHECK (units_per_pack BETWEEN 1 AND 100000 AND packs BETWEEN 1 AND 1000000),
  CONSTRAINT ck_po_line_price CHECK (pack_price >= 0),
  CONSTRAINT ck_po_line_charge CHECK (kind = 'item' OR (supplier_item_id IS NULL AND units_per_pack = 1 AND packs = 1 AND received_units = 0)),
  CONSTRAINT ck_po_line_received CHECK (received_units >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='PO line extension of document_line (cascade with the draft line)';

CREATE TABLE po_posting (
  document_id   BIGINT UNSIGNED NOT NULL,
  content_hash  CHAR(64)    NOT NULL,
  content       MEDIUMTEXT  NOT NULL COMMENT 'canonical JSON of purchase_order (immutable fields) + po_line (all but received_units) at approval',
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (document_id),
  CONSTRAINT fk_po_posting_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT ck_po_posting_hash CHECK (REGEXP_LIKE(content_hash, '^[0-9a-f]{64}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='write-once anchor of a PO''s module content (append-only; P2, like I33)';
