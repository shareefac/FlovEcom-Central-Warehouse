-- 0022_stock_ops.sql — pack A1: Stock In, Stock Out, Adjustments and Transfers, with the release of another account's stock
-- (the owner's VPG 2 room) and the running balance owed to that account (the owner's 16-module list of 8 Oct 2026: "when added skip,
-- otherwise create"; owner answers Q2, Q5, Q6/Q7, Q9, Q13; docs/decisions.md SO1-SO16). Every new stock operation is a document type on
-- the document base (draft -> post -> reverse, never deleted); Stock.php stays the only writer of the stock tables.
--
--  * document_type: four new kinds of record, each with its own number series (SIN stock in, SOUT stock out, TRF transfer, REL release
--    from another account); the existing ADJ (0008) is the adjustment, write-offs included. The reviewer check after posting is
--    'none' for the new kinds and the blocking approvals are off (the owner's rule: extra approvals off by default; every rule is
--    switched on the Approval Rules page). A stock-in has the same "stock put back without a supplier document" approval as ADJ,
--    off. NEW: an OK first for a big record (size_approval, size_units, size_value in whole pounds; off by default) for ADJ and the
--    four new kinds: a record moving more than size_units units, or worth more than size_value pounds, waits for a reviewer.
--  * reason_code: where a reason is offered gains stock_in and stock_out; two new rules a reason carries, changed on the Reasons page:
--    needs_given_to (a stock out with it names the person it was given to: samples and staff use, Q5) and below_zero (a stock out or
--    a write-down with it may take a protected product below zero). New reasons: opening stock, returned by a trade customer, staff
--    use, trade sale, sent for repair or return, written off. found / sample / other gain their new uses (version N+1 of each).
--  * stock_op: the header extension of the five kinds (the place inside the warehouse, the warehouse and place a transfer goes to,
--    who a stock out was given to). Optional places (Q2): nothing requires one.
--  * other_account_entry: the running balance owed to another account (append-only): a release adds its amount, its reversal takes it
--    off, a payment to the account lowers it, a payment's reversal puts it back. Pounds only (Q13).
-- Pre-flight on cw_staging (read only, 8 Oct 2026): no document of any type yet; no warehouse owned by another account yet; the
-- number series of every prefix at 0. Applied together with the code of the same commit (install_cron.sh --migrate).

-- ------------------------------------------------------------------------------------------------------------------------------
-- Reasons: two new uses, two new rules.

ALTER TABLE reason_code
  MODIFY COLUMN applies_to SET('adjustment','write_off','count','return','supplier_return','reversal','po_cancel','po_draft_cancel','po_amend',
      'stock_in','stock_out') NOT NULL,
  ADD COLUMN needs_given_to TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'a stock out with this reason names who it was given to (samples, staff use: owner answer Q5)' AFTER is_gift,
  ADD COLUMN below_zero TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'a stock out or a write-down with this reason may take a protected product (strict / stopped) below zero' AFTER needs_given_to,
  ADD CONSTRAINT ck_reason_code_stock_rules CHECK (needs_given_to IN (0, 1) AND below_zero IN (0, 1));

INSERT INTO reason_code (sort_order, code, label, applies_to, direction, needs_note, is_gift, needs_given_to, below_zero, system_only) VALUES
 (212, 'opening_stock',  'Opening stock (first time it is recorded)', 'stock_in',              'increase', 0, 0, 0, 0, 0),
 (214, 'trade_return',   'Returned by a trade customer',              'stock_in',              'increase', 0, 0, 0, 0, 0),
 (216, 'staff_use',      'Staff use',                                 'stock_out',             'decrease', 0, 0, 1, 0, 0),
 (218, 'trade_sale',     'Trade sale',                                'stock_out',             'decrease', 0, 0, 0, 0, 0),
 (220, 'repair_return',  'Sent for repair or return',                 'stock_out',             'decrease', 0, 0, 0, 0, 0),
 (222, 'written_off',    'Written off (no longer usable)',            'adjustment,write_off',  'decrease', 0, 0, 0, 0, 0);

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'reason', code, 1, 'baseline', JSON_OBJECT('label', label, 'applies_to', CAST(applies_to AS CHAR), 'direction', CAST(direction AS CHAR),
      'needs_note', needs_note, 'is_gift', is_gift, 'system_only', system_only, 'is_active', is_active, 'sort_order', sort_order,
      'needs_given_to', needs_given_to, 'below_zero', below_zero), 'system:migrate'
  FROM reason_code WHERE code IN ('opening_stock', 'trade_return', 'staff_use', 'trade_sale', 'repair_return', 'written_off');

-- found is offered on a stock in too; a sample is a stock out that names who it was given to (Q5); other is offered on both. Each
-- change is the reason's next version (docs/dev.md rule 5: the tracked row before it and after it, actor system:migrate).
UPDATE reason_code SET applies_to = CONCAT_WS(',', CAST(applies_to AS CHAR), 'stock_in') WHERE code = 'found';
UPDATE reason_code SET applies_to = CONCAT_WS(',', CAST(applies_to AS CHAR), 'stock_out'), needs_given_to = 1 WHERE code = 'sample';
UPDATE reason_code SET applies_to = CONCAT_WS(',', CAST(applies_to AS CHAR), 'stock_in,stock_out') WHERE code = 'other';
INSERT INTO config_change (subject_type, subject_key, version, action, state, before_state, reason, actor)
  SELECT 'reason', r.code, c.version + 1, 'change',
      JSON_OBJECT('label', r.label, 'applies_to', CAST(r.applies_to AS CHAR), 'direction', CAST(r.direction AS CHAR), 'needs_note', r.needs_note,
          'is_gift', r.is_gift, 'system_only', r.system_only, 'is_active', r.is_active, 'sort_order', r.sort_order,
          'needs_given_to', r.needs_given_to, 'below_zero', r.below_zero),
      c.state,
      'Offered on the new Stock In and Stock Out records (0022); a sample names who it was given to (owner answer Q5)',
      'system:migrate'
  FROM reason_code r
  JOIN (SELECT subject_key, MAX(version) AS v FROM config_change WHERE subject_type = 'reason' GROUP BY subject_key) m ON m.subject_key = r.code
  JOIN config_change c ON c.subject_type = 'reason' AND c.subject_key = r.code AND c.version = m.v
  WHERE r.code IN ('found', 'sample', 'other');

-- ------------------------------------------------------------------------------------------------------------------------------
-- Kinds of record: the OK first for a big record, and the four new kinds.

ALTER TABLE document_type
  ADD COLUMN size_approval TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1: a record moving more than size_units units or worth more than size_value pounds waits for a reviewer''s OK first' AFTER reject_action,
  ADD COLUMN size_units INT UNSIGNED NULL COMMENT 'units (NULL: units are not checked)' AFTER size_approval,
  ADD COLUMN size_value INT UNSIGNED NULL COMMENT 'whole pounds (NULL: the value is not checked)' AFTER size_units,
  ADD CONSTRAINT ck_document_type_size CHECK (size_approval IN (0, 1) AND (size_approval = 0 OR size_units IS NOT NULL OR size_value IS NOT NULL));

INSERT INTO document_type (code, prefix, name, phase, review_rule, review_limit_units, approval_rule, approval_limit_units, review_due_days, reject_action,
    size_approval, size_units, size_value) VALUES
 ('SIN',  'SIN',  'Stock in',                     'A1', 'none', NULL, 'none', 10,   3, 'reverse', 0, 100, 500),
 ('SOUT', 'SOUT', 'Stock out',                    'A1', 'none', NULL, 'none', NULL, 3, 'reverse', 0, 100, 500),
 ('TRF',  'TRF',  'Transfer',                     'A1', 'none', NULL, 'none', NULL, 3, 'reverse', 0, 500, 2000),
 ('REL',  'REL',  'Release from another account', 'A1', 'none', NULL, 'none', NULL, 3, 'reverse', 0, 500, 2000);
INSERT INTO number_series (prefix) VALUES ('SIN'), ('SOUT'), ('TRF'), ('REL');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'document_rule', code, 1, 'baseline', JSON_OBJECT('review_rule', CAST(review_rule AS CHAR), 'review_limit_units', review_limit_units,
      'review_due_days', review_due_days, 'approval_rule', CAST(approval_rule AS CHAR), 'approval_limit_units', approval_limit_units,
      'reject_action', CAST(reject_action AS CHAR), 'size_approval', size_approval, 'size_units', size_units, 'size_value', size_value), 'system:migrate'
  FROM document_type WHERE code IN ('SIN', 'SOUT', 'TRF', 'REL');

-- ADJ gets the same sizes for its OK first (still off): its version 2.
UPDATE document_type SET size_units = 100, size_value = 500 WHERE code = 'ADJ';
INSERT INTO config_change (subject_type, subject_key, version, action, state, before_state, reason, actor)
  SELECT 'document_rule', t.code, c.version + 1, 'change',
      JSON_OBJECT('review_rule', CAST(t.review_rule AS CHAR), 'review_limit_units', t.review_limit_units, 'review_due_days', t.review_due_days,
          'approval_rule', CAST(t.approval_rule AS CHAR), 'approval_limit_units', t.approval_limit_units, 'reject_action', CAST(t.reject_action AS CHAR),
          'size_approval', t.size_approval, 'size_units', t.size_units, 'size_value', t.size_value),
      c.state,
      'The OK first for a big adjustment (0022): its sizes, kept for when it is switched on (off by default)',
      'system:migrate'
  FROM document_type t
  JOIN (SELECT subject_key, MAX(version) AS v FROM config_change WHERE subject_type = 'document_rule' GROUP BY subject_key) m ON m.subject_key = t.code
  JOIN config_change c ON c.subject_type = 'document_rule' AND c.subject_key = t.code AND c.version = m.v
  WHERE t.code = 'ADJ';

-- ------------------------------------------------------------------------------------------------------------------------------
-- The header extension of a stock record.

CREATE TABLE stock_op (
  document_id       BIGINT UNSIGNED   NOT NULL,
  kind              ENUM('in','out','adjust','transfer','release') NOT NULL,
  location_id       INT UNSIGNED      NULL COMMENT 'optional place inside document.warehouse_id (a transfer: the place it leaves)',
  to_warehouse_id   SMALLINT UNSIGNED NULL COMMENT 'a transfer or a release: where the stock goes (the same warehouse for a move between places)',
  to_location_id    INT UNSIGNED      NULL COMMENT 'a transfer or a release: the optional place it goes to',
  given_to          VARCHAR(100)      NULL COMMENT 'a stock out: the person it was given to (Q5: required by a reason with needs_given_to)',
  account_name      VARCHAR(100)      NULL COMMENT 'a release: the other account''s name when it was posted (warehouse.owner_entity)',
  created_at        DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at        DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (document_id),
  KEY ix_stock_op_kind (kind, document_id),
  KEY ix_stock_op_location (location_id),
  KEY ix_stock_op_to_warehouse (to_warehouse_id),
  KEY ix_stock_op_to_location (to_location_id),
  CONSTRAINT fk_stock_op_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_stock_op_location FOREIGN KEY (location_id) REFERENCES warehouse_location (id),
  CONSTRAINT fk_stock_op_to_warehouse FOREIGN KEY (to_warehouse_id) REFERENCES warehouse (id),
  CONSTRAINT fk_stock_op_to_location FOREIGN KEY (to_location_id) REFERENCES warehouse_location (id),
  CONSTRAINT ck_stock_op_to CHECK (kind IN ('transfer', 'release') OR (to_warehouse_id IS NULL AND to_location_id IS NULL)),
  CONSTRAINT ck_stock_op_given CHECK (given_to IS NULL OR CHAR_LENGTH(given_to) >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='header extension of the stock records SIN, SOUT, ADJ, TRF, REL (pack A1); never deleted';

-- ------------------------------------------------------------------------------------------------------------------------------
-- The balance owed to another account.

CREATE TABLE other_account_entry (
  id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  warehouse_id    SMALLINT UNSIGNED NOT NULL COMMENT 'the other account''s warehouse (stock_owner other when the entry was made)',
  account_name    VARCHAR(100)      NOT NULL COMMENT 'warehouse.owner_entity when the entry was made',
  kind            ENUM('release','release_reversal','payment','payment_reversal') NOT NULL,
  amount          DECIMAL(14,2)     NOT NULL COMMENT 'pounds, signed as it moves the balance owed: + a release or a payment''s reversal, - a payment or a release''s reversal',
  document_id     BIGINT UNSIGNED   NULL COMMENT 'the release (REL) posted, or its reversal',
  paid_on         DATE              NULL COMMENT 'a payment: the day it was paid',
  reference       VARCHAR(100)      NULL COMMENT 'a payment: the bank or remittance reference',
  note            VARCHAR(500)      NULL,
  reverses_id     BIGINT UNSIGNED   NULL COMMENT 'a payment''s reversal: the payment it cancels',
  created_by      INT UNSIGNED      NULL,
  created_actor   VARCHAR(64)       NOT NULL,
  created_at      DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_other_account_document (document_id),
  UNIQUE KEY uq_other_account_reverses (reverses_id),
  KEY ix_other_account_warehouse (warehouse_id, created_at),
  KEY ix_other_account_by (created_by),
  CONSTRAINT fk_other_account_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouse (id),
  CONSTRAINT fk_other_account_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_other_account_reverses FOREIGN KEY (reverses_id) REFERENCES other_account_entry (id),
  CONSTRAINT fk_other_account_by FOREIGN KEY (created_by) REFERENCES staff_user (id),
  CONSTRAINT ck_other_account_sign CHECK ((kind IN ('release', 'payment_reversal') AND amount >= 0) OR (kind IN ('release_reversal', 'payment') AND amount <= 0)),
  CONSTRAINT ck_other_account_links CHECK ((kind IN ('release', 'release_reversal')) = (document_id IS NOT NULL)
      AND (kind = 'payment_reversal') = (reverses_id IS NOT NULL)),
  CONSTRAINT ck_other_account_payment CHECK (kind <> 'payment' OR (amount < 0 AND paid_on IS NOT NULL AND reference IS NOT NULL AND CHAR_LENGTH(reference) >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the running balance owed to another account (releases from its warehouse, payments to it); append-only for cw_app (pack A1)';
