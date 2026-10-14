-- 0038_handovers_company_duty.sql — pack H: the ERPNext functions E26 and E47 (hand-overs: the staff sales invoices that move stock to
-- Electrofag, Vape Big, the shop units and trade customers; module IM11), E34 (the company, its VAT code and currency on CW's orders and
-- invoices) and E45 (the vaping duty register built from the receipts; module IM13). docs/decisions.md PH1-PH20. Numbered 0038 (packs B-G
-- hold 0030-0037). Nothing here books stock and the stock core is unchanged: a hand-over is a TRD document (0008) whose handler books
-- `trade_sale` (0006, I8) through Movements::bookForDocument; the duty register reads the receipts (0017) and adds no table.
--
--  * company_profile (0013) gains two fields, kept in every version like the other nine: vat_code (the VAT code our own invoices charge;
--    NULL = not said yet, which every existing version keeps: nobody confirmed a code) and currency (pounds only, the owner's answer Q13; a
--    CHECK, as purchase_order.currency has). A small table (one row per saved version): ALGORITHM = COPY, one statement.
--  * counterparty: who we hand stock to: a sister shop (another company: Electrofag, Vape Big), one of our shop units, or a trade
--    customer. Name, address, company number, VAT number, an optional VAT code of their own (NULL = our company's), contact, note. Switched
--    off, never deleted; a version for the forms (409 when stale). No rows are seeded: the names and details are the owner's to type.
--  * counterparty_price: the price a customer last paid for an item ("prices remembered from past lines", E47): written when a hand-over is
--    made final, or loaded from the past ERPNext trade-invoice lines (a CSV on the Customers page). A new line's price defaults to it. No FK
--    to sku (it is written inside the posting, before the stock locks: an FK would S-lock the item before its balance, the order
--    Stock::setPolicy reverses; incident (0017) says the same); the nightly H5 checks the ids.
--  * handover: the header extension of a TRD document (as stock_op is of the stock records): the customer, the VAT code chosen, and what
--    the posting fixed for the invoice (the VAT code and rate used, net, VAT and gross in pounds, our company's details and the customer's
--    as printed). A reversal carries the same header with the amounts negated.
--  * counterparty_entry: what each customer owes us (append-only; the pattern of other_account_entry, 0022): a hand-over adds its gross
--    amount, its reversal takes it off, a payment received lowers it, a payment's reversal puts it back.
--  * document_type TRD (0008, "Trade / inter-site issue", never live: no handler until now): the reviewer check 'all' that 0008 seeded is
--    switched OFF (the owner's rule: extra approvals start off; 0029 did the same for CNT), and the sizes of the "OK first for a big
--    record" are set for when it is switched on (500 units / 2,000 pounds, as TRF and REL; still off). ONE change, version N+1 of the
--    rule's history (docs/dev.md rule 5), written only when the rule was not already like that.
--  * Ten settings (provisional: build decisions put to the owner; each changed on Settings > System > Settings with a reason), each with its
--    baseline version in config_change:
--      handovers.pdf_needs_confirmed_company  true  the invoice PDF is refused until the company details are confirmed (off: it prints
--                                                   with the "do not send" line, as a purchase order does)
--      handovers.invoice_terms                ""    words printed under every hand-over invoice (empty: none; the owner or the accountant
--                                                   types them: CW never invents legal wording)
--      handovers.price_fallback               none  a new line's price when the customer has no remembered price: none (typed by the
--                                                   person) or cost (the last supplier price, else the average cost so far)
--      handovers.allow_overpayment            false a payment received that is more than the customer owes is refused (true: recorded,
--                                                   the balance goes into credit)
--      handovers.price_import_max_rows        5000  the most rows one file of past prices may have
--      duty.register_lines                    stamp the duty register lists the receipt lines that needed the stamp check (all: every
--                                                   receipt line)
--      duty.unstamped_warehouses              UNSTAMPED  the warehouses whose stock is unstamped (codes, separated by commas)
--      duty.unstamped_zero_from               2027-04-01 from this day any unstamped stock is shown as an alarm (the plan, section 6:
--                                                   holding unstamped vaping liquid is an offence from 1 Apr 2027)
--      duty.page_rows                         200   lines of the duty register on one page
--      duty.export_max_rows                   20000 the most lines one download of the duty register holds
--  * grn_line (0017) gains one index for the duty register's "taken in unstamped on a supplier's declaration" (EXPLAIN, PH17): the few such
--    lines are read through it instead of every receipt line. Added in place and online (ALGORITHM = INPLACE, LOCK = NONE: not a rebuild).
--  * role_permission (0034, pack E): the three new permissions get their rows, as the code's map gives them (handovers.customers and
--    handovers.pay: the purchasing desk and manager; duty.view: goods in, the purchasing desk and manager, the stock controller, the
--    reviewer, the accountant, the auditor, the stock manager), in Permissions::ROLES order, each with its baseline version, so the
--    "Roles and permissions" screen shows and changes them like the others.
-- Old code keeps working on the new schema: four new tables it does not know, two new columns with defaults, an index, ten setting rows,
-- one rule, three permission rows.
-- Applied together with the code of the same commit (install_cron.sh --migrate): the company details' code reads the two new columns.

ALTER TABLE company_profile
  ADD COLUMN vat_code VARCHAR(4) NULL COMMENT 'the VAT code our own invoices (hand-overs) charge; NULL = not said yet' AFTER vat_number,
  ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'GBP' COMMENT 'pounds only (owner answer Q13)' AFTER vat_code,
  ADD KEY ix_company_profile_vat_code (vat_code),
  ADD CONSTRAINT fk_company_profile_vat_code FOREIGN KEY (vat_code) REFERENCES vat_code (code),
  ADD CONSTRAINT ck_company_profile_currency CHECK (currency = 'GBP'),
  ALGORITHM = COPY;

ALTER TABLE grn_line
  ADD INDEX ix_grn_line_unstamped (unstamped_action, document_id),
  ALGORITHM = INPLACE, LOCK = NONE;

CREATE TABLE counterparty (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  kind            ENUM('sister','unit','trade') NOT NULL COMMENT 'sister: a sister shop (another company); unit: one of our shop units; trade: a trade customer',
  name            VARCHAR(160)  NOT NULL COMMENT 'the name printed on the invoice (unique, case does not matter)',
  address         VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'one line per line (LF)',
  company_number  VARCHAR(16)   NOT NULL DEFAULT '',
  vat_number      VARCHAR(16)   NOT NULL DEFAULT '' COMMENT 'GB or XI + 9 or 12 digits, no spaces; empty: none given',
  vat_code        VARCHAR(4)    NULL COMMENT 'the VAT code of their invoices; NULL = our company''s',
  email           VARCHAR(191)  NOT NULL DEFAULT '',
  phone           VARCHAR(32)   NOT NULL DEFAULT '',
  note            VARCHAR(500)  NULL,
  is_active       TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '0: switched off (no new hand-over names it); never deleted',
  version         INT UNSIGNED  NOT NULL DEFAULT 1 COMMENT 'moves on every change; forms carry it (409 when stale)',
  created_by      INT UNSIGNED  NULL,
  created_actor   VARCHAR(64)   NOT NULL,
  created_at      DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_by      INT UNSIGNED  NULL,
  updated_actor   VARCHAR(64)   NULL,
  updated_at      DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT 'set by the code',
  PRIMARY KEY (id),
  UNIQUE KEY uq_counterparty_name (name),
  KEY ix_counterparty_list (is_active, kind, name),
  KEY ix_counterparty_vat_code (vat_code),
  KEY ix_counterparty_created_by (created_by),
  KEY ix_counterparty_updated_by (updated_by),
  CONSTRAINT fk_counterparty_vat_code FOREIGN KEY (vat_code) REFERENCES vat_code (code),
  CONSTRAINT fk_counterparty_created_by FOREIGN KEY (created_by) REFERENCES staff_user (id),
  CONSTRAINT fk_counterparty_updated_by FOREIGN KEY (updated_by) REFERENCES staff_user (id),
  CONSTRAINT ck_counterparty_name CHECK (CHAR_LENGTH(name) >= 2),
  CONSTRAINT ck_counterparty_flags CHECK (is_active IN (0, 1) AND version >= 1),
  CONSTRAINT ck_counterparty_numbers CHECK ((company_number = '' OR REGEXP_LIKE(company_number, '^([0-9]{8}|[A-Z]{2}[0-9]{6}|R[0-9]{7}|(IP|SP|NP)[0-9]{5}R)$', 'c'))
    AND (vat_number = '' OR REGEXP_LIKE(vat_number, '^(GB|XI)([0-9]{9}|[0-9]{12})$', 'c')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='who we hand stock to: sister shops, our shop units, trade customers (pack H, IM11); switched off, never deleted';

CREATE TABLE counterparty_price (
  counterparty_id  INT UNSIGNED    NOT NULL,
  sku_id           INT UNSIGNED    NOT NULL COMMENT 'no FK (written inside the posting, before the stock locks); checked nightly (H5)',
  unit_price       DECIMAL(14,6)   NOT NULL COMMENT 'pounds per unit, before VAT',
  priced_on        DATE            NOT NULL COMMENT 'the day of the hand-over or of the old invoice it came from',
  source           ENUM('handover','import') NOT NULL,
  document_id      BIGINT UNSIGNED NULL COMMENT 'source handover: the hand-over whose line set it',
  source_ref       VARCHAR(100)    NULL COMMENT 'source import: the old invoice number, as the file said',
  updated_by       INT UNSIGNED    NULL,
  updated_actor    VARCHAR(64)     NOT NULL,
  updated_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (counterparty_id, sku_id),
  KEY ix_counterparty_price_sku (sku_id),
  KEY ix_counterparty_price_document (document_id),
  KEY ix_counterparty_price_by (updated_by),
  CONSTRAINT fk_counterparty_price_counterparty FOREIGN KEY (counterparty_id) REFERENCES counterparty (id),
  CONSTRAINT fk_counterparty_price_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_counterparty_price_by FOREIGN KEY (updated_by) REFERENCES staff_user (id),
  CONSTRAINT ck_counterparty_price CHECK (unit_price >= 0),
  CONSTRAINT ck_counterparty_price_source CHECK ((source = 'handover') = (document_id IS NOT NULL) AND (source = 'import' OR source_ref IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the price a customer last paid for an item (pack H, E47): the default of a new hand-over line; never deleted';

CREATE TABLE handover (
  document_id       BIGINT UNSIGNED NOT NULL,
  counterparty_id   INT UNSIGNED    NOT NULL,
  vat_code          VARCHAR(4)      NULL COMMENT 'draft: the code chosen (NULL = the customer''s, else our company''s); posted: the code used (NULL = no VAT: we are not VAT registered)',
  vat_rate          DECIMAL(5,2)    NULL COMMENT 'posted: the rate of vat_code then',
  currency          CHAR(3)         NOT NULL DEFAULT 'GBP',
  net_amount        DECIMAL(14,2)   NULL COMMENT 'posted: the sum of each line''s units x price, each to the penny',
  vat_amount        DECIMAL(14,2)   NULL COMMENT 'posted: net x rate, to the penny (half up)',
  gross_amount      DECIMAL(14,2)   NULL COMMENT 'posted: net + VAT: what the customer owes for it',
  company_version   INT UNSIGNED    NULL COMMENT 'posted: the version of our company details printed (0: none yet)',
  company_confirmed TINYINT(1)      NULL COMMENT 'posted: whether those details were confirmed then (the invoice PDF waits for it)',
  company_snapshot  JSON            NULL COMMENT 'posted: our company details as printed',
  buyer_snapshot    JSON            NULL COMMENT 'posted: the customer''s details as printed',
  created_at        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (document_id),
  KEY ix_handover_counterparty (counterparty_id, document_id),
  KEY ix_handover_vat_code (vat_code),
  CONSTRAINT fk_handover_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_handover_counterparty FOREIGN KEY (counterparty_id) REFERENCES counterparty (id),
  CONSTRAINT fk_handover_vat_code FOREIGN KEY (vat_code) REFERENCES vat_code (code),
  CONSTRAINT ck_handover_currency CHECK (currency = 'GBP'),
  CONSTRAINT ck_handover_posted CHECK ((net_amount IS NULL) = (gross_amount IS NULL) AND (net_amount IS NULL) = (vat_amount IS NULL)
    AND (net_amount IS NULL) = (company_version IS NULL) AND (net_amount IS NULL) = (company_confirmed IS NULL)
    AND (vat_rate IS NULL OR vat_code IS NOT NULL) AND COALESCE(company_confirmed, 0) IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='header extension of a hand-over TRD (pack H, IM11): the customer and what the posting fixed for the invoice; never deleted';

CREATE TABLE counterparty_entry (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  counterparty_id  INT UNSIGNED    NOT NULL,
  kind             ENUM('handover','handover_reversal','payment','payment_reversal') NOT NULL,
  amount           DECIMAL(14,2)   NOT NULL COMMENT 'pounds, signed as it moves what the customer owes us: + a hand-over or a payment''s reversal, - a payment received or a hand-over''s reversal',
  document_id      BIGINT UNSIGNED NULL COMMENT 'the hand-over made final, or its reversal',
  paid_on          DATE            NULL COMMENT 'a payment: the day it was received',
  reference        VARCHAR(100)    NULL COMMENT 'a payment: the bank or remittance reference',
  note             VARCHAR(500)    NULL,
  reverses_id      BIGINT UNSIGNED NULL COMMENT 'a payment''s reversal: the payment it cancels',
  created_by       INT UNSIGNED    NULL,
  created_actor    VARCHAR(64)     NOT NULL,
  created_at       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_counterparty_entry_document (document_id),
  UNIQUE KEY uq_counterparty_entry_reverses (reverses_id),
  KEY ix_counterparty_entry_counterparty (counterparty_id, id),
  KEY ix_counterparty_entry_by (created_by),
  CONSTRAINT fk_counterparty_entry_counterparty FOREIGN KEY (counterparty_id) REFERENCES counterparty (id),
  CONSTRAINT fk_counterparty_entry_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_counterparty_entry_reverses FOREIGN KEY (reverses_id) REFERENCES counterparty_entry (id),
  CONSTRAINT fk_counterparty_entry_by FOREIGN KEY (created_by) REFERENCES staff_user (id),
  CONSTRAINT ck_counterparty_entry_sign CHECK ((kind IN ('handover', 'payment_reversal') AND amount >= 0) OR (kind IN ('handover_reversal', 'payment') AND amount <= 0)),
  CONSTRAINT ck_counterparty_entry_links CHECK ((kind IN ('handover', 'handover_reversal')) = (document_id IS NOT NULL)
    AND (kind = 'payment_reversal') = (reverses_id IS NOT NULL)),
  CONSTRAINT ck_counterparty_entry_payment CHECK (kind <> 'payment' OR (amount < 0 AND paid_on IS NOT NULL AND reference IS NOT NULL AND CHAR_LENGTH(reference) >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='what each customer owes us: hand-overs, payments received and their reversals (pack H); append-only for cw_app';

-- ------------------------------------------------------------------------------------------------------------------------------
-- The settings, each with its baseline (docs/dev.md rule 5).

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('handovers.pdf_needs_confirmed_company','bool','true',1,'PH8','A hand-over invoice PDF is refused until the company details are confirmed; off: it prints with the do-not-send line, as a purchase order does'),
 ('handovers.invoice_terms','text','""',1,'PH9','Words printed under every hand-over invoice (empty: none). The owner or the accountant types them'),
 ('handovers.price_fallback','string','"none"',1,'PH6','A new hand-over line''s price when the customer has no remembered price: none (typed by the person) or cost (the last supplier price, else the average cost so far)'),
 ('handovers.allow_overpayment','bool','false',1,'PH12','Off: a payment received that is more than the customer owes is refused. On: it is recorded and the balance goes into credit'),
 ('handovers.price_import_max_rows','int','5000',1,'PH7','The most rows one file of past trade-invoice prices may have'),
 ('duty.register_lines','string','"stamp"',1,'PH15','Which receipt lines the duty register lists: stamp (the lines that needed the duty-stamp check) or all (every line)'),
 ('duty.unstamped_warehouses','string','"UNSTAMPED"',1,'PH16','The warehouses whose stock is unstamped, as warehouse codes separated by commas'),
 ('duty.unstamped_zero_from','date','"2027-04-01"',1,'PH16','From this day any unstamped stock is shown as an alarm on the duty register (holding unstamped vaping liquid is an offence from 1 Apr 2027)'),
 ('duty.page_rows','int','200',1,'PH17','Lines of the duty register on one page'),
 ('duty.export_max_rows','int','20000',1,'PH17','The most lines one download of the duty register holds');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('handovers.pdf_needs_confirmed_company', 'handovers.invoice_terms', 'handovers.price_fallback',
    'handovers.allow_overpayment', 'handovers.price_import_max_rows', 'duty.register_lines', 'duty.unstamped_warehouses', 'duty.unstamped_zero_from',
    'duty.page_rows', 'duty.export_max_rows');

-- ------------------------------------------------------------------------------------------------------------------------------
-- The new permissions in the stored map (pack E, 0034), each with its baseline.

INSERT INTO role_permission (permission, roles, updated_actor) VALUES
  ('handovers.customers','purchasing_manager,purchasing_desk','system:migrate'),
  ('handovers.pay','purchasing_manager,purchasing_desk','system:migrate'),
  ('duty.view','manager,purchasing_manager,goods_in,purchasing_desk,stock_controller,reviewer,accountant,auditor','system:migrate');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'permission', permission, 1, 'baseline', JSON_OBJECT('roles', roles), 'system:migrate'
  FROM role_permission WHERE permission IN ('handovers.customers', 'handovers.pay', 'duty.view');

-- ------------------------------------------------------------------------------------------------------------------------------
-- The hand-over's rule: the reviewer check off (extra approvals start off), the sizes of the OK first for a big record kept for when it is
-- switched on. The next version of the rule's history first, from the row as it is, only when the rule is not already like that; then
-- the row.

INSERT INTO config_change (subject_type, subject_key, version, action, state, before_state, reason, actor)
  SELECT 'document_rule', t.code, c.version + 1, 'change',
      JSON_OBJECT('review_rule', 'none', 'review_limit_units', t.review_limit_units, 'review_due_days', t.review_due_days,
          'approval_rule', CAST(t.approval_rule AS CHAR), 'approval_limit_units', t.approval_limit_units, 'reject_action', CAST(t.reject_action AS CHAR),
          'size_approval', t.size_approval, 'size_units', 500, 'size_value', 2000),
      c.state,
      'Hand-overs arrive (0038): the second-person check of a hand-over starts switched off, as every extra approval does; the sizes of the OK first for a big hand-over are kept for when it is switched on',
      'system:migrate'
  FROM document_type t
  JOIN (SELECT subject_key, MAX(version) AS v FROM config_change WHERE subject_type = 'document_rule' GROUP BY subject_key) m ON m.subject_key = t.code
  JOIN config_change c ON c.subject_type = 'document_rule' AND c.subject_key = t.code AND c.version = m.v
  WHERE t.code = 'TRD' AND NOT (t.review_rule = 'none' AND t.size_units <=> 500 AND t.size_value <=> 2000);
UPDATE document_type SET review_rule = 'none', size_units = 500, size_value = 2000
  WHERE code = 'TRD' AND NOT (review_rule = 'none' AND size_units <=> 500 AND size_value <=> 2000);
