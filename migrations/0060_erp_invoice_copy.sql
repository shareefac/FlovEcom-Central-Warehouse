-- 0060_erp_invoice_copy.sql — copies of ERPNext purchase invoices (the owner, 4 Nov 2026: "917 do it"; tracker CW-917; docs/decisions.md
-- EC1-EC16; plan §15.4 "Parallel run": the nightly read-only import of ERPNext purchase invoices). Every Purchase Invoice staff submit in
-- ERPNext is also kept in CW as a posted supplier invoice (a debit note as a credit note), so CW holds the suppliers and their prices on the
-- switch-over day. A copy NEVER adds stock: the stock already reached CW through the shop's relay (goods_in / supplier_return lines), and a
-- copy never feeds the average cost or the stock value.
--
--  * supplier_invoice.origin: 'cw' (every record before this file, and every record a person keys) or 'erpnext' (a copy made by the job
--    system:erp_copy). Kept in the posting anchor when it is 'erpnext' (SupplierInvoiceHandler::content), so a copy can never be turned
--    into a record of CW's own unseen.
--  * erp_pi: one row per ERPNext Purchase Invoice the job read (its name is the key): the header as read, the hash of what is copied, the
--    state (waiting: not copied yet, `problem` says why; copied: `document_id` is the copy; reversed: the copy was cancelled because the
--    ERPNext invoice was; skipped: never copied, `problem` says why), the CW supplier and the copy. Never deleted (NO_DELETE).
--  * erp_pi_line: its lines as read (every row of the ERPNext invoice, stock or not), and for a copied stock row the CW item, its units, how
--    it was found and what the relay booked for it (the match of the copy reads that). Replaced when a re-read brings other content.
--  * erp_copy_run: one row per real run of bin/copy_erp_invoices.php (a dry run writes nothing): what it read, from when, the cursor it
--    reached (the next run reads ERPNext from there), what it did. Written once and finished once (UPDATE_COLUMNS), never removed.
--  * reason_code erp_cancelled / erp_changed: why the job cancels a copy (the ERPNext invoice was cancelled or amended / was changed after
--    it was copied). CW's own (system_only): never offered on a form. Each with its baseline (docs/dev.md rule 5).
--  * Settings (Settings > Rules and numbers > Invoices from ERPNext), each with its baseline: the main switch erp_copy.on (OFF: the job
--    does nothing), the ERPNext company it reads (VAPE AND GO), the first posting date it copies (2026-09-01), whether a supplier CW does
--    not know is made (on), whether a copied price becomes the supplier's last price (on). Provisional ("Not agreed yet") as every new one.
--
-- HOW IT IS APPLIED: one column at the end of supplier_invoice, ALGORITHM = INSTANT (as 0052; no row changed); three new tables; two
-- reasons, five settings and their seven baselines. Nothing existing is rewritten or deleted. Every INSERT is guarded (NOT EXISTS): a run
-- stopped between two statements, or a second run, neither fails nor duplicates a row. The old code runs on this schema (its writes of
-- supplier_invoice name their columns and take the default 'cw').

ALTER TABLE supplier_invoice ADD COLUMN origin ENUM('cw','erpnext') NOT NULL DEFAULT 'cw'
  COMMENT 'erpnext: a copy of an ERPNext purchase invoice made by the job (CW-917): it never moves stock and never feeds the cost', ALGORITHM = INSTANT;

CREATE TABLE erp_pi (
  id                    BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  name                  VARCHAR(140)      NOT NULL COMMENT 'the ERPNext Purchase Invoice name (ACC-PINV-2026-00123; an amendment ends in -1, -2)',
  company               VARCHAR(140)      NOT NULL,
  supplier              VARCHAR(140)      NOT NULL COMMENT 'the ERPNext Supplier name (supplier.erp_name in CW)',
  supplier_name         VARCHAR(140)      NULL,
  bill_no               VARCHAR(140)      NULL COMMENT 'the supplier''s invoice number as typed in ERPNext',
  bill_date             DATE              NULL,
  posting_date          DATE              NOT NULL,
  due_date              DATE              NULL,
  is_return             TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1: a debit note (a return); copied as a CW credit note',
  return_against        VARCHAR(140)      NULL COMMENT 'a debit note: the ERPNext invoice it returns against',
  amended_from          VARCHAR(140)      NULL COMMENT 'an amendment: the cancelled ERPNext invoice it replaces',
  docstatus             TINYINT UNSIGNED  NOT NULL COMMENT '1 submitted, 2 cancelled',
  erp_modified          DATETIME(6)       NOT NULL COMMENT 'ERPNext''s modified time (the server''s own clock, as read)',
  net_total             DECIMAL(14,2)     NOT NULL COMMENT 'base_net_total (GBP, without VAT; negative on a debit note)',
  vat_total             DECIMAL(14,2)     NOT NULL COMMENT 'the VAT rows of its taxes (GBP)',
  grand_total           DECIMAL(14,2)     NOT NULL COMMENT 'base_grand_total (GBP), as read',
  charges               JSON              NULL COMMENT 'its taxes and charges rows that are not VAT (they become lines of the copy)',
  content_hash          CHAR(64)          NOT NULL COMMENT 'sha256 of what a copy is made from (header, lines, charges; not its status)',
  state                 ENUM('waiting','copied','reversed','skipped') NOT NULL DEFAULT 'waiting',
  problem               VARCHAR(48)       NULL COMMENT 'why it waits or was skipped (ErpInvoiceCopy::PROBLEMS; the words are Ui\\Words::ERP_COPY_WHY)',
  problem_line          INT UNSIGNED      NULL COMMENT 'the ERPNext row (idx) the problem is on',
  problem_detail        VARCHAR(255)      NULL,
  supplier_id           INT UNSIGNED      NULL COMMENT 'the CW supplier',
  document_id           BIGINT UNSIGNED   NULL COMMENT 'the copy (a posted SINV, or SCN for a debit note) while state = copied or reversed',
  copied_hash           CHAR(64)          NULL COMMENT 'content_hash of what the copy was made from',
  reversal_document_id  BIGINT UNSIGNED   NULL COMMENT 'the last cancellation of a copy of it',
  first_read_at         DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  read_at               DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  state_at              DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_erp_pi_name (name),
  UNIQUE KEY uq_erp_pi_document (document_id),
  KEY ix_erp_pi_state (state, posting_date, id),
  KEY ix_erp_pi_return_against (return_against),
  KEY ix_erp_pi_amended_from (amended_from),
  KEY ix_erp_pi_supplier (supplier_id),
  KEY ix_erp_pi_reversal (reversal_document_id),
  CONSTRAINT fk_erp_pi_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id),
  CONSTRAINT fk_erp_pi_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_erp_pi_reversal FOREIGN KEY (reversal_document_id) REFERENCES document (id),
  CONSTRAINT ck_erp_pi_status CHECK (docstatus IN (1, 2) AND is_return IN (0, 1)),
  CONSTRAINT ck_erp_pi_copied CHECK ((state IN ('copied', 'reversed')) = (document_id IS NOT NULL) AND (state <> 'copied' OR copied_hash IS NOT NULL)),
  CONSTRAINT ck_erp_pi_hash CHECK (REGEXP_LIKE(content_hash, '^[0-9a-f]{64}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='ERPNext purchase invoices read by the copy job (CW-917): what was read, its state and its copy; never deleted';

CREATE TABLE erp_pi_line (
  erp_pi_id          BIGINT UNSIGNED  NOT NULL,
  idx                INT UNSIGNED     NOT NULL COMMENT 'the ERPNext row number (1, 2, ...)',
  row_name           VARCHAR(140)     NULL COMMENT 'the ERPNext Purchase Invoice Item name',
  item_code          VARCHAR(140)     NOT NULL,
  item_name          VARCHAR(255)     NULL,
  is_stock           TINYINT(1)       NOT NULL COMMENT 'ERPNext Item.is_stock_item: only these rows reach the relay',
  stock_index        INT UNSIGNED     NULL COMMENT 'a stock row: its place among the stock rows (0, 1, ...): the relay''s line_index',
  variant_id         VARCHAR(64)      NULL COMMENT 'ERPNext Item.custom_variant_id (the Vape and Go variant)',
  qty                DECIMAL(18,6)    NOT NULL COMMENT 'in the purchase unit (negative on a debit note)',
  stock_qty          DECIMAL(18,6)    NOT NULL,
  uom                VARCHAR(140)     NULL,
  conversion_factor  DECIMAL(18,9)    NOT NULL DEFAULT 1,
  net_rate           DECIMAL(18,6)    NOT NULL COMMENT 'base_net_rate: GBP per purchase unit without VAT, after the invoice''s discount',
  net_amount         DECIMAL(14,2)    NOT NULL COMMENT 'base_net_amount',
  return_of          VARCHAR(140)     NULL COMMENT 'a debit note''s row: the ERPNext row of the invoice it returns (purchase_invoice_item)',
  sku_id             INT UNSIGNED     NULL COMMENT 'copied: the CW item (no FK, D15)',
  u                  INT UNSIGNED     NULL COMMENT 'copied: CW units in one ERPNext stock unit',
  units              INT UNSIGNED     NULL COMMENT 'copied: CW units the row is for (|stock_qty| x u)',
  via                ENUM('relay','erp_item','listing') NULL COMMENT 'how the item was found',
  relay_line_id      BIGINT UNSIGNED  NULL COMMENT 'the relay line that booked this row''s stock (Vape and Go)',
  relay_units        INT UNSIGNED     NULL COMMENT 'the units the relay booked for this row (NULL: not booked in CW): the copy''s match',
  line_no            INT UNSIGNED     NULL COMMENT 'copied: the line of the copy',
  PRIMARY KEY (erp_pi_id, idx),
  KEY ix_erp_pi_line_relay (relay_line_id),
  CONSTRAINT fk_erp_pi_line_pi FOREIGN KEY (erp_pi_id) REFERENCES erp_pi (id),
  CONSTRAINT ck_erp_pi_line_flags CHECK (is_stock IN (0, 1) AND (is_stock = 1 OR stock_index IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='the rows of an ERPNext purchase invoice the copy job read (CW-917)';

CREATE TABLE erp_copy_run (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status       ENUM('running','done','failed') NOT NULL DEFAULT 'running',
  actor        VARCHAR(64)     NOT NULL,
  company      VARCHAR(140)    NOT NULL,
  from_date    DATE            NOT NULL COMMENT 'the first posting date read',
  cursor_from  DATETIME(6)     NULL COMMENT 'ERPNext''s modified time it read from (NULL: everything since from_date)',
  cursor_to    DATETIME(6)     NULL COMMENT 'the newest modified time it read completely: the next run reads from there',
  summary      JSON            NULL,
  started_at   DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  finished_at  DATETIME(6)     NULL,
  PRIMARY KEY (id),
  KEY ix_erp_copy_run_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='the real runs of the ERPNext invoice copy job (CW-917); written once, finished once';

INSERT INTO reason_code (sort_order, code, label, applies_to, direction, needs_note, is_gift, needs_given_to, below_zero, system_only)
  SELECT r.sort_order, r.code, r.label, 'reversal', 'either', 0, 0, 0, 0, 1 FROM (
    SELECT 230 AS sort_order, 'erp_cancelled' AS code, 'Cancelled or amended in ERPNext' AS label
    UNION ALL SELECT 232, 'erp_changed', 'Changed in ERPNext after it was copied'
  ) r WHERE NOT EXISTS (SELECT 1 FROM reason_code c WHERE c.code = r.code);

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'reason', r.code, 1, 'baseline', JSON_OBJECT('label', r.label, 'applies_to', CAST(r.applies_to AS CHAR), 'direction', CAST(r.direction AS CHAR),
      'needs_note', r.needs_note, 'is_gift', r.is_gift, 'system_only', r.system_only, 'is_active', r.is_active, 'sort_order', r.sort_order,
      'needs_given_to', r.needs_given_to, 'below_zero', r.below_zero), 'system:migrate'
  FROM reason_code r WHERE r.code IN ('erp_cancelled', 'erp_changed')
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'reason' AND c.subject_key = r.code);

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description)
  SELECT k.setting_key, k.value_type, k.value_json, 1, 'EC2', k.description FROM (
    SELECT 'erp_copy.on' AS setting_key, 'bool' AS value_type, 'false' AS value_json,
      'On: every half hour CW reads the purchase invoices submitted in ERPNext and keeps a copy of each; it never adds stock' AS description
    UNION ALL SELECT 'erp_copy.company', 'string', '"VAPE AND GO"', 'The ERPNext company whose purchase invoices are copied'
    UNION ALL SELECT 'erp_copy.from_date', 'date', '"2026-09-01"', 'ERPNext purchase invoices posted on or after this date are copied'
    UNION ALL SELECT 'erp_copy.create_suppliers', 'bool', 'true', 'On: a supplier CW does not know yet is made (a draft, named from ERPNext); off: its invoices wait'
    UNION ALL SELECT 'erp_copy.update_prices', 'bool', 'true', 'On: a copied price becomes the supplier''s last price of that item and pack'
  ) k WHERE NOT EXISTS (SELECT 1 FROM app_setting s WHERE s.setting_key = k.setting_key);

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key IN ('erp_copy.on', 'erp_copy.company', 'erp_copy.from_date', 'erp_copy.create_suppliers', 'erp_copy.update_prices')
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);
