-- 0076_receive_rest.sql — CW-1155 "Receive the rest" (docs/decisions.md RR1-RR20; the owner, 22 Nov 2026: "when the rest actually comes we
-- should be able to add it on the same invoice and on booking it give the shops back that number to sell again"). It only ADDS: no existing
-- table, row or screen number changes.
--
--  * invoice_arrival_rest: what became, later, of the units an invoice line counted on arrival did not bring on its first receipt (the
--    receipt that closed the invoice's goods on their way, invoice_arrival.grn_document_id): per invoice line, one row per fact, with its
--    units: `rest` = a later receipt line of the same item brought them (written by that receipt's posting; it counts while that receipt is
--    posted), `claim` = they were claimed from the supplier ("Claim what did not come"; it counts while the claim is live: draft, waiting or
--    posted), `waive` = a person accepted the invoice's difference while they were still waited for (we stopped waiting; it counts for
--    good). What is STILL OWED is never stored: line units - credited - what the first receipt brought - these (Arrivals::owedOf), so an
--    invoice short before 0076 (SINV-000632) shows as waiting the moment the code is live, with no data fix. Every row names the first
--    receipt (and its line) it is about: a row of a first receipt that was taken back counts no more. Append-only (Grants): a reversal or a
--    cancellation removes nothing, the readers count the documents' status.
--  * The setting arrivals.rest_late_days (14, provisional): an invoice still waiting for the rest that many days after its first receipt is
--    marked late.
--
-- HOW IT IS APPLIED: one CREATE TABLE IF NOT EXISTS (new, empty: nothing copied or locked, no stock table touched), the setting ON DUPLICATE
-- KEY, its baseline only when missing. Every statement can run again and changes nothing the second time. Rollback: the release symlink
-- back; the empty table and the setting stay, unused (see docs/decisions.md RR20 before a rollback once a rest receipt was posted).

CREATE TABLE IF NOT EXISTS invoice_arrival_rest (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sinv_document_id       BIGINT UNSIGNED NOT NULL COMMENT 'the supplier invoice',
  line_no                INT UNSIGNED    NOT NULL COMMENT 'its line (an invoice_arrival row) whose units came short',
  first_grn_document_id  BIGINT UNSIGNED NOT NULL COMMENT 'the first receipt: the one that received the arrival row (no FK: a receipt of the rest is posted without its lock, checked nightly, J5)',
  first_grn_line_no      INT UNSIGNED    NULL COMMENT 'its line of the item (NULL: the item had no line on it)',
  kind                   ENUM('rest','claim','waive') NOT NULL COMMENT 'rest: a later receipt brought them; claim: claimed from the supplier; waive: we stopped waiting',
  document_id            BIGINT UNSIGNED NULL COMMENT 'rest: the later receipt; claim: the return (debit note); waive: NULL',
  document_line_no       INT UNSIGNED    NULL COMMENT 'rest: that receipt''s line; claim: the return''s line; waive: NULL',
  units                  INT UNSIGNED    NOT NULL COMMENT 'the units of the invoice line this row settles',
  created_by             INT UNSIGNED    NULL,
  created_actor          VARCHAR(64)     NOT NULL,
  created_at             DATETIME(6)     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoice_arrival_rest_fact (kind, document_id, document_line_no, sinv_document_id, line_no),
  KEY ix_invoice_arrival_rest_line (sinv_document_id, line_no),
  KEY ix_invoice_arrival_rest_doc (document_id, document_line_no),
  KEY ix_invoice_arrival_rest_first (first_grn_document_id),
  KEY ix_invoice_arrival_rest_by (created_by),
  CONSTRAINT fk_invoice_arrival_rest_line FOREIGN KEY (sinv_document_id, line_no) REFERENCES invoice_arrival (sinv_document_id, line_no),
  CONSTRAINT fk_invoice_arrival_rest_doc FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_invoice_arrival_rest_by FOREIGN KEY (created_by) REFERENCES staff_user (id),
  CONSTRAINT ck_invoice_arrival_rest_units CHECK (units > 0),
  CONSTRAINT ck_invoice_arrival_rest_kind CHECK ((kind = 'waive') = (document_id IS NULL) AND (document_id IS NULL) = (document_line_no IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='what became later of the units an invoice line counted on arrival did not bring (CW-1155): the rest received, claimed or waived; append-only';

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('arrivals.rest_late_days','int','14',1,'RR16','An invoice still waiting for the rest of its goods this many days after its first receipt is marked late')
 ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key = 'arrivals.rest_late_days'
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);
