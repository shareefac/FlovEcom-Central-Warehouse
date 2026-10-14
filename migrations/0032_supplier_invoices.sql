-- 0032_supplier_invoices.sql — pack C (plan IM7): supplier invoices with the 3-way match, the supplier's credit notes, and returns to
-- the supplier with their debit notes (docs/decisions.md PC1-PC18). Every one is a document on the document base (draft -> post ->
-- reverse; never deleted); Stock.php stays the only writer of the stock tables (a return books `supplier_return` through
-- Movements::bookForDocument).
--
--  * document_type: the supplier invoice (SINV) and the supplier return / debit note (DN) came with 0008 without screens; the
--    supplier's credit note is new (SCN, its own number series). The reviewer check after posting is 'none' for the three (the
--    owner's rule: extra approvals off by default; the "To match" list is where a person looks at an invoice that does not match),
--    each switchable on the Approval Rules page with the OK first for a big record (size_approval, off) and, for an invoice, a NEW
--    blocking OK first: an invoice outside the tolerances by more than a sum of pounds waits for a reviewer (approval_rule
--    'over_difference', off; its limit in whole pounds, 0).
--  * supplier_invoice: the header extension of SINV and SCN (the supplier; the invoice number's key: one live invoice per supplier
--    and number, and one live credit note per supplier and number; the totals exactly as the paper states them, VAT included; a
--    credit note's invoice and debit note; the stored 3-way match and a person's acceptance of its differences).
--  * sinv_line: the line extension of SINV and SCN (goods, freight, duty, service or other; a goods line's packs and pack price as on
--    the invoice and the delivery line and order line it is for; a credit note line's invoice line).
--  * supplier_return / dn_line: the header and line extension of DN (the supplier, the delivery and the invoice it is about, what is
--    claimed back; a line's delivery line and the duty claimed with it).
--  * purchase_doc_posting: the write-once anchor of a posted SINV, SCN or DN's module content (like grn_posting; append-only).
--  * invoice_value_event: the value-only changes an invoice or a credit note makes to stock already booked (a price that differs from
--    the delivery's provisional cost, freight and duty spread over the invoice's goods, a credit). The value engine (IM8's
--    Valuation.php, the only writer of stock_value_ledger) is not built: these rows wait for it (append-only; nothing reads them into
--    the value ledger yet).
--  * grn_invoice_left: what a delivery still has to be invoiced, kept by those postings, for the "Not invoiced yet" list (PC18).
--  * seven settings: the tolerances of the match (the plan's IM7 numbers: price 2 % or GBP 5 a line, the total GBP 0.05 a line; the
--    plan gives none for the quantity: exact), how freight and duty are spread, and the switch "accepting an invoice's differences
--    needs a second person" (off).
-- No backfill: no SINV, SCN or DN document exists before this migration (they had no handler). Applied with the code of the same
-- commit (install_cron.sh --migrate).

-- ------------------------------------------------------------------------------------------------------------------------------
-- Kinds of record.

ALTER TABLE document_type
  MODIFY COLUMN approval_rule ENUM('none','positive_without_supplier_doc','over_value','over_difference') NOT NULL DEFAULT 'none';

INSERT INTO document_type (code, prefix, name, phase, review_rule, review_limit_units, approval_rule, approval_limit_units, review_due_days, reject_action,
    size_approval, size_units, size_value) VALUES
 ('SCN', 'SCN', 'Supplier credit note', 'C', 'none', NULL, 'none', NULL, 3, 'reverse', 0, NULL, 1000);
INSERT INTO number_series (prefix) VALUES ('SCN');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'document_rule', code, 1, 'baseline', JSON_OBJECT('review_rule', CAST(review_rule AS CHAR), 'review_limit_units', review_limit_units,
      'review_due_days', review_due_days, 'approval_rule', CAST(approval_rule AS CHAR), 'approval_limit_units', approval_limit_units,
      'reject_action', CAST(reject_action AS CHAR), 'size_approval', size_approval, 'size_units', size_units, 'size_value', size_value), 'system:migrate'
  FROM document_type WHERE code = 'SCN';

-- The invoice and the return: no reviewer check after posting (off, like the stock records of pack A1), the sizes kept for the OK
-- first for a big record (off), and the invoice's limit for its new OK first (off). Each change is the type's next version
-- (docs/dev.md rule 5).
UPDATE document_type SET review_rule = 'none', review_limit_units = NULL, approval_limit_units = 0, size_units = NULL, size_value = 5000 WHERE code = 'SINV';
UPDATE document_type SET review_rule = 'none', review_limit_units = NULL, size_units = 100, size_value = 500 WHERE code = 'DN';
INSERT INTO config_change (subject_type, subject_key, version, action, state, before_state, reason, actor)
  SELECT 'document_rule', t.code, c.version + 1, 'change',
      JSON_OBJECT('review_rule', CAST(t.review_rule AS CHAR), 'review_limit_units', t.review_limit_units, 'review_due_days', t.review_due_days,
          'approval_rule', CAST(t.approval_rule AS CHAR), 'approval_limit_units', t.approval_limit_units, 'reject_action', CAST(t.reject_action AS CHAR),
          'size_approval', t.size_approval, 'size_units', t.size_units, 'size_value', t.size_value),
      c.state,
      'Pack C (0032): invoices and returns are in use; no reviewer check after posting (extra approvals off by default), the OK firsts kept off',
      'system:migrate'
  FROM document_type t
  JOIN (SELECT subject_key, MAX(version) AS v FROM config_change WHERE subject_type = 'document_rule' GROUP BY subject_key) m ON m.subject_key = t.code
  JOIN config_change c ON c.subject_type = 'document_rule' AND c.subject_key = t.code AND c.version = m.v
  WHERE t.code IN ('SINV', 'DN');

-- ------------------------------------------------------------------------------------------------------------------------------
-- Settings (Settings > Invoices; the switch on the Approval Rules page).

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('invoices.price_tolerance_pct','decimal','"2"',1,'PC3','An invoice line whose price differs from the order (else the delivery) by at most this percentage is within the tolerance'),
 ('invoices.price_tolerance_gbp','decimal','"5.00"',1,'PC3','... or by at most this many pounds on the line (either is enough; 0 switches that test off)'),
 ('invoices.qty_tolerance_pct','decimal','"0"',1,'PC3','An invoice line may bill this percentage more units than were received and not yet invoiced and still be within the tolerance (0: exact)'),
 ('invoices.total_tolerance_per_line','decimal','"0.05"',1,'PC3','The lines of an invoice may differ from its stated total (excl. VAT) by at most this many pounds a line (rounding)'),
 ('invoices.freight_spread','string','"value"',1,'PC9','How an invoice''s freight is spread over its goods for the stock value: value or ml'),
 ('invoices.duty_spread','string','"ml"',1,'PC9','How an invoice''s duty is spread over its goods for the stock value: ml (of liquid) or value'),
 ('approvals.invoice_accept_other','bool','false',1,'PC6','Accepting the differences of an invoice that does not match is done by someone other than the person who posted it; off: the poster may accept them');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('invoices.price_tolerance_pct', 'invoices.price_tolerance_gbp', 'invoices.qty_tolerance_pct',
      'invoices.total_tolerance_per_line', 'invoices.freight_spread', 'invoices.duty_spread', 'approvals.invoice_accept_other');

-- ------------------------------------------------------------------------------------------------------------------------------
-- Supplier invoices and credit notes.

CREATE TABLE supplier_invoice (
  document_id             BIGINT UNSIGNED NOT NULL,
  kind                    ENUM('invoice','credit_note') NOT NULL COMMENT 'SINV: invoice; SCN: credit_note',
  supplier_id             INT UNSIGNED    NOT NULL,
  invoice_key             VARCHAR(64)     NULL COMMENT 'document.external_ref (the supplier''s number) upper-cased without spaces while live (draft, waiting, posted); NULL once cancelled or reversed',
  net_total               DECIMAL(14,2)   NULL COMMENT 'as the paper states it, excl. VAT (GBP); a credit note: what it credits',
  vat_total               DECIMAL(14,2)   NULL COMMENT 'VAT exactly as the paper states it (CW never works it out)',
  gross_total             DECIMAL(14,2)   NULL COMMENT 'net + VAT: the total to pay (a credit note: the total credited)',
  credits_document_id     BIGINT UNSIGNED NULL COMMENT 'a credit note: the supplier invoice it reduces (NULL: a credit on account)',
  debit_note_document_id  BIGINT UNSIGNED NULL COMMENT 'a credit note: our return / debit note (DN) it answers',
  match_state             ENUM('matched','within_tolerance','needs_review','accepted') NULL COMMENT 'a posted invoice''s 3-way match (InvoiceMatch); NULL otherwise',
  match_lines_off         INT UNSIGNED    NULL COMMENT 'goods lines outside the tolerance',
  match_difference        DECIMAL(14,2)   NULL COMMENT 'GBP charged above what was ordered and received, on the lines outside the tolerance',
  match_rules             JSON            NULL COMMENT 'the tolerances the stored match was worked out with',
  match_checked_at        DATETIME(6)     NULL,
  accepted_by             INT UNSIGNED    NULL COMMENT 'who accepted the differences of an invoice that needs review',
  accepted_actor          VARCHAR(64)     NULL,
  accepted_at             DATETIME(6)     NULL,
  accept_note             VARCHAR(500)    NULL,
  accepted_lines_off      INT UNSIGNED    NULL COMMENT 'the match as it was accepted (shown with the acceptance)',
  accepted_difference     DECIMAL(14,2)   NULL,
  accepted_fingerprint    CHAR(64)        NULL COMMENT 'sha256 of the per-line facts accepted (InvoiceMatch::fingerprint): it stays accepted while the match is exactly this; any other match drops the acceptance',
  created_at              DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at              DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (document_id),
  UNIQUE KEY uq_supplier_invoice_key (supplier_id, kind, invoice_key),
  KEY ix_supplier_invoice_match (match_state, document_id),
  KEY ix_supplier_invoice_credits (credits_document_id),
  KEY ix_supplier_invoice_debit_note (debit_note_document_id),
  KEY ix_supplier_invoice_accepted_by (accepted_by),
  CONSTRAINT fk_supplier_invoice_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_supplier_invoice_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id),
  CONSTRAINT fk_supplier_invoice_credits FOREIGN KEY (credits_document_id) REFERENCES document (id),
  CONSTRAINT fk_supplier_invoice_debit_note FOREIGN KEY (debit_note_document_id) REFERENCES document (id),
  CONSTRAINT fk_supplier_invoice_accepted_by FOREIGN KEY (accepted_by) REFERENCES staff_user (id),
  CONSTRAINT ck_supplier_invoice_key CHECK (invoice_key IS NULL OR (invoice_key <> '' AND NOT REGEXP_LIKE(invoice_key, '[[:space:]]'))),
  CONSTRAINT ck_supplier_invoice_totals CHECK ((net_total IS NULL OR net_total >= 0) AND (vat_total IS NULL OR vat_total >= 0)
    AND (gross_total IS NULL OR (net_total IS NOT NULL AND vat_total IS NOT NULL AND gross_total = net_total + vat_total))),
  CONSTRAINT ck_supplier_invoice_links CHECK (kind = 'credit_note' OR (credits_document_id IS NULL AND debit_note_document_id IS NULL)),
  CONSTRAINT ck_supplier_invoice_match CHECK ((match_state IS NULL OR kind = 'invoice') AND (match_state IS NULL) = (match_checked_at IS NULL)
    AND (match_state IS NULL) = (match_lines_off IS NULL) AND (match_state IS NULL) = (match_difference IS NULL) AND (match_state IS NULL) = (match_rules IS NULL)),
  CONSTRAINT ck_supplier_invoice_accepted CHECK ((accepted_at IS NULL) = (accepted_actor IS NULL) AND (accepted_at IS NULL) = (accept_note IS NULL)
    AND (accepted_at IS NULL) = (accepted_lines_off IS NULL) AND (accepted_at IS NULL) = (accepted_difference IS NULL)
    AND (accepted_at IS NULL) = (accepted_fingerprint IS NULL) AND (accepted_fingerprint IS NULL OR REGEXP_LIKE(accepted_fingerprint, '^[0-9a-f]{64}$', 'c'))
    AND (accepted_at IS NOT NULL OR accepted_by IS NULL) AND (NOT (match_state <=> 'accepted') OR accepted_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='header extension of a supplier invoice (SINV) or credit note (SCN), pack C; a reversal document has none; never deleted';

CREATE TABLE sinv_line (
  document_id        BIGINT UNSIGNED NOT NULL,
  line_no            INT UNSIGNED    NOT NULL,
  kind               ENUM('goods','freight','duty','service','other') NOT NULL,
  grn_document_id    BIGINT UNSIGNED NULL COMMENT 'goods: the delivery (GRN) the line bills (a credit note line: its invoice line''s)',
  grn_line_no        INT UNSIGNED    NULL,
  po_document_id     BIGINT UNSIGNED NULL COMMENT 'goods: the purchase order line it bills, when there is one',
  po_line_no         INT UNSIGNED    NULL,
  credit_of_line_no  INT UNSIGNED    NULL COMMENT 'a credit note''s goods line: the line of supplier_invoice.credits_document_id it credits',
  supplier_code      VARCHAR(64)     NULL,
  purchase_unit      VARCHAR(32)     NOT NULL DEFAULT 'each',
  units_per_pack     INT UNSIGNED    NOT NULL DEFAULT 1,
  packs              INT UNSIGNED    NULL COMMENT 'goods: packs on the paper; document_line.qty = packs x units_per_pack',
  pack_price         DECIMAL(14,4)   NULL COMMENT 'goods: GBP excl. VAT per pack as on the paper; document_line.amount = half-up(packs x pack_price, 2)',
  entry              ENUM('receipt','order','manual','credit') NOT NULL DEFAULT 'manual' COMMENT 'how the line was made',
  PRIMARY KEY (document_id, line_no),
  KEY ix_sinv_line_grn (grn_document_id, grn_line_no),
  KEY ix_sinv_line_po (po_document_id, po_line_no),
  CONSTRAINT fk_sinv_line_line FOREIGN KEY (document_id, line_no) REFERENCES document_line (document_id, line_no) ON DELETE CASCADE,
  CONSTRAINT fk_sinv_line_grn FOREIGN KEY (grn_document_id) REFERENCES document (id),
  CONSTRAINT fk_sinv_line_po FOREIGN KEY (po_document_id) REFERENCES document (id),
  CONSTRAINT ck_sinv_line_links CHECK ((grn_document_id IS NULL) = (grn_line_no IS NULL) AND (po_document_id IS NULL) = (po_line_no IS NULL)),
  CONSTRAINT ck_sinv_line_goods CHECK ((kind = 'goods') = (packs IS NOT NULL) AND (packs IS NULL) = (pack_price IS NULL)
    AND (kind = 'goods' OR (grn_document_id IS NULL AND po_document_id IS NULL AND credit_of_line_no IS NULL))),
  CONSTRAINT ck_sinv_line_pack CHECK (units_per_pack BETWEEN 1 AND 100000 AND (packs IS NULL OR packs BETWEEN 1 AND 1000000) AND (pack_price IS NULL OR pack_price >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='line extension of SINV and SCN (cascade with the draft line)';

-- ------------------------------------------------------------------------------------------------------------------------------
-- Returns to the supplier (the debit note).

CREATE TABLE supplier_return (
  document_id          BIGINT UNSIGNED NOT NULL,
  supplier_id          INT UNSIGNED    NOT NULL,
  grn_document_id      BIGINT UNSIGNED NULL COMMENT 'the delivery the goods came with (optional)',
  invoice_document_id  BIGINT UNSIGNED NULL COMMENT 'the supplier invoice the claim is against (optional)',
  claim_total          DECIMAL(14,2)   NULL COMMENT 'at posting: what is claimed back, excl. VAT (the goods at the claimed price, the duty, other claims)',
  created_at           DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at           DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (document_id),
  KEY ix_supplier_return_supplier (supplier_id, document_id),
  KEY ix_supplier_return_grn (grn_document_id),
  KEY ix_supplier_return_invoice (invoice_document_id),
  CONSTRAINT fk_supplier_return_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_supplier_return_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id),
  CONSTRAINT fk_supplier_return_grn FOREIGN KEY (grn_document_id) REFERENCES document (id),
  CONSTRAINT fk_supplier_return_invoice FOREIGN KEY (invoice_document_id) REFERENCES document (id),
  CONSTRAINT ck_supplier_return_claim CHECK (claim_total IS NULL OR claim_total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='header extension of a return to the supplier / debit note (DN), pack C; a reversal document has none; never deleted';

CREATE TABLE dn_line (
  document_id      BIGINT UNSIGNED NOT NULL,
  line_no          INT UNSIGNED    NOT NULL,
  grn_document_id  BIGINT UNSIGNED NULL COMMENT 'the delivery line the units came with: they no longer wait to be invoiced',
  grn_line_no      INT UNSIGNED    NULL,
  duty_amount      DECIMAL(14,2)   NOT NULL DEFAULT 0 COMMENT 'the duty claimed back with the line (part of document_line.amount): a retailer cannot reclaim duty on damaged stock',
  PRIMARY KEY (document_id, line_no),
  KEY ix_dn_line_grn (grn_document_id, grn_line_no),
  CONSTRAINT fk_dn_line_line FOREIGN KEY (document_id, line_no) REFERENCES document_line (document_id, line_no) ON DELETE CASCADE,
  CONSTRAINT fk_dn_line_grn FOREIGN KEY (grn_document_id) REFERENCES document (id),
  CONSTRAINT ck_dn_line_links CHECK ((grn_document_id IS NULL) = (grn_line_no IS NULL) AND duty_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='line extension of DN (cascade with the draft line)';

-- ------------------------------------------------------------------------------------------------------------------------------
-- The posting anchor and the value-only events.

CREATE TABLE purchase_doc_posting (
  document_id   BIGINT UNSIGNED NOT NULL,
  content_hash  CHAR(64)    NOT NULL,
  content       MEDIUMTEXT  NOT NULL COMMENT 'canonical JSON of the header extension (its fixed fields) and every line extension at posting',
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (document_id),
  CONSTRAINT fk_purchase_doc_posting_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT ck_purchase_doc_posting_hash CHECK (REGEXP_LIKE(content_hash, '^[0-9a-f]{64}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='write-once anchor of a posted SINV, SCN or DN''s module content (append-only)';

CREATE TABLE invoice_value_event (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id      BIGINT UNSIGNED NOT NULL COMMENT 'the invoice or credit note that recorded it, or its reversal (which records the negation)',
  line_no          INT UNSIGNED    NOT NULL COMMENT 'the line it comes from (the freight or duty line of a spread)',
  kind             ENUM('price_difference','freight','duty','credit') NOT NULL,
  sku_id           INT UNSIGNED    NOT NULL COMMENT 'no FK (D15)',
  grn_document_id  BIGINT UNSIGNED NULL COMMENT 'the delivery line whose stock it values',
  grn_line_no      INT UNSIGNED    NULL,
  units            INT             NOT NULL COMMENT 'the units it is for',
  amount           DECIMAL(14,2)   NOT NULL COMMENT 'GBP, signed: + raises the cost of those units, - lowers it',
  basis            ENUM('delivery_cost','value','ml','line') NOT NULL COMMENT 'how it was worked out',
  effective_on     DATE            NOT NULL COMMENT 'the date on the invoice (the valuation date IM8 will use)',
  created_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY ix_invoice_value_event_document (document_id, line_no),
  KEY ix_invoice_value_event_item (sku_id, id),
  KEY ix_invoice_value_event_grn (grn_document_id, grn_line_no),
  CONSTRAINT fk_invoice_value_event_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT ck_invoice_value_event_links CHECK ((grn_document_id IS NULL) = (grn_line_no IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='value-only changes of invoices and credit notes to stock already booked, waiting for the value engine (IM8 Valuation.php is not built); append-only';

-- What each delivery still has to be invoiced (PC18): written by every posting and reversal of an invoice, a credit note or a return
-- on it, so "Not invoiced yet" is one indexed read instead of adding every delivery line up (EXPLAIN on a year of purchasing:
-- 1.4-3.4 s). A delivery no invoice, credit note or return has named has no row: everything it accepted is left. Checked by V7.
CREATE TABLE grn_invoice_left (
  grn_document_id  BIGINT UNSIGNED NOT NULL,
  units_left       INT UNSIGNED    NOT NULL COMMENT 'units accepted less units returned beyond the exceptions less units billed by posted invoices net of credit notes, per line, at least 0',
  value_left       DECIMAL(14,2)   NOT NULL COMMENT 'those units at the delivery''s provisional cost, per line to the penny (GBP excl. VAT)',
  updated_at       DATETIME(6)     NOT NULL,
  PRIMARY KEY (grn_document_id),
  CONSTRAINT fk_grn_invoice_left_grn FOREIGN KEY (grn_document_id) REFERENCES document (id),
  CONSTRAINT ck_grn_invoice_left_value CHECK (value_left >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='what a posted delivery has left to invoice, kept by the invoice, credit note and return postings (never deleted)';
