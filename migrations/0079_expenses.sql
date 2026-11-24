-- 0079_expenses.sql — CW-1150: expenses (the invoices the owner adds, each for ONE shop, with its PDF or photo), regular costs (the weekly,
-- monthly and yearly costs with no invoice, counted per day) and the "real profit" lines of the Profit pages (docs/decisions.md EX1-; the
-- owner, 21-24 Nov 2026: "no Sage at all", "vape and go expense vapebig expense electrofag expense like that", "Yes, per day", "Yes, build
-- it"). It only ADDS: no existing row changes.
--
--  * expense_type: the settable list of an expense's types (Stationery and packaging, Software and AI, Developer, Fees, Other) and of a
--    regular cost's groups (Rent, Running the business, Marketing, website and people, Other): renamed or switched off on Settings, never
--    deleted (Grants: UPDATE of name, is_active, sort_order, version only).
--  * expense_supplier: the companies expenses are paid to: their OWN list, not the stock suppliers' (supplier) table; renamed or
--    switched off, never deleted.
--  * expense: one row per invoice added: its shop, supplier, type, invoice date and number, the amount without VAT and the VAT as printed
--    (CW never works VAT out), the total (net + VAT), a note, its PDF or photo (a stored_file of the document store: kept 7 years, never
--    in a web directory). Changed in place (version + 1) or deleted by a mark with a reason; never removed (Grants: no DELETE; the
--    creation columns frozen).
--  * regular_cost: a cost with no invoice that goes out every week, month or year: its shop, group, how often, the amount as given, from
--    and until (its last day; none: no end). Its amount, shop, how often and first day are frozen: a new amount ends the line and starts
--    another (replaces_id), so the old days keep the old amount; a mistake is deleted by a mark with a reason.
--  * expense_history: every add, change, end and delete of the four, with the row before and after, the reason, who and when (append-only).
--  * staff_role.role gets `expenses` at the END of its list (the stored number of every grant stays what it was); the two new permissions
--    expenses.view (Finance and the new job) and expenses.edit (the new job) are rows of the stored map, each with its baseline; nobody
--    holds the new job until the owner says who (the go-live kit gives it to staff 1 and staff 2 together; bin/grant_role.php).
--
-- HOW IT IS APPLIED: five CREATE TABLE IF NOT EXISTS (new, empty), the start lists of expense_type with their history only while the list
-- has none of them, the two permission rows ON DUPLICATE KEY with their baselines only when missing, and ONE ALTER of staff_role (tiny;
-- ALGORITHM = COPY, LOCK = SHARED, as 0069, 0074 and 0077). Every statement can run again and changes nothing the second time.
-- Rollback: the release before (14c6e31) with this file added byte for byte (RollbackReleaseTest); the new tables and rows stay, unused.

CREATE TABLE IF NOT EXISTS expense_type (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind          ENUM('expense','regular') NOT NULL COMMENT 'expense: the type of an invoice (Profit > Expenses); regular: the group of a regular cost',
  name          VARCHAR(64)       NOT NULL,
  is_active     TINYINT(1)        NOT NULL DEFAULT 1 COMMENT '0: switched off: not offered for a new one; the rows that name it keep it',
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  version       INT UNSIGNED      NOT NULL DEFAULT 1 COMMENT 'its latest expense_history version',
  created_actor VARCHAR(64)       NOT NULL,
  created_at    DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_expense_type_name (kind, name),
  CONSTRAINT ck_expense_type_row CHECK (CHAR_LENGTH(TRIM(name)) >= 2 AND is_active IN (0, 1) AND version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the settable list of expense types and regular cost groups (docs/decisions.md EX): renamed or switched off, never deleted';

CREATE TABLE IF NOT EXISTS expense_supplier (
  id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  name          VARCHAR(100)      NOT NULL,
  is_active     TINYINT(1)        NOT NULL DEFAULT 1 COMMENT '0: switched off: not offered for a new expense; its expenses keep it',
  version       INT UNSIGNED      NOT NULL DEFAULT 1 COMMENT 'its latest expense_history version',
  created_by    INT UNSIGNED      NULL,
  created_actor VARCHAR(64)       NOT NULL,
  created_at    DATETIME(6)       NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expense_supplier_name (name),
  KEY ix_expense_supplier_by (created_by),
  CONSTRAINT fk_expense_supplier_by FOREIGN KEY (created_by) REFERENCES staff_user (id),
  CONSTRAINT ck_expense_supplier_row CHECK (CHAR_LENGTH(TRIM(name)) >= 2 AND is_active IN (0, 1) AND version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the companies expenses are paid to (their own list, not the stock suppliers; docs/decisions.md EX): never deleted';

CREATE TABLE IF NOT EXISTS expense (
  id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  channel_id     SMALLINT UNSIGNED NOT NULL COMMENT 'the ONE shop it is for, as the person picked it (never split between shops)',
  supplier_id    INT UNSIGNED      NOT NULL,
  type_id        SMALLINT UNSIGNED NOT NULL,
  invoice_date   DATE              NOT NULL COMMENT 'the invoice''s date: the day it counts in the profit',
  invoice_no     VARCHAR(64)       NOT NULL DEFAULT '' COMMENT 'as printed; empty when the bill has none',
  net_pence      BIGINT            NOT NULL COMMENT 'the amount without VAT as printed (a bill in dollars: the pounds the card was charged)',
  vat_pence      BIGINT            NOT NULL COMMENT 'the VAT as printed, 0 when none: CW never works VAT out',
  total_pence    BIGINT            NOT NULL COMMENT 'net + VAT',
  note           VARCHAR(500)      NULL,
  file_id        BIGINT UNSIGNED   NULL COMMENT 'its PDF or photo in the document store (stored_file: kept 7 years, never in a web directory)',
  version        INT UNSIGNED      NOT NULL DEFAULT 1 COMMENT 'its latest expense_history version',
  created_by     INT UNSIGNED      NULL,
  created_actor  VARCHAR(64)       NOT NULL,
  created_at     DATETIME(6)       NOT NULL,
  updated_by     INT UNSIGNED      NULL,
  updated_actor  VARCHAR(64)       NOT NULL,
  updated_at     DATETIME(6)       NOT NULL,
  deleted_at     DATETIME(6)       NULL COMMENT 'deleted by a mark: out of every list and of the profit, kept with its history',
  deleted_by     INT UNSIGNED      NULL,
  deleted_actor  VARCHAR(64)       NULL,
  deleted_reason VARCHAR(500)      NULL,
  PRIMARY KEY (id),
  KEY ix_expense_day (invoice_date, channel_id),
  KEY ix_expense_channel_day (channel_id, invoice_date),
  KEY ix_expense_same (supplier_id, channel_id, invoice_no),
  KEY ix_expense_type (type_id),
  KEY ix_expense_file (file_id),
  KEY ix_expense_created_by (created_by),
  KEY ix_expense_updated_by (updated_by),
  KEY ix_expense_deleted_by (deleted_by),
  CONSTRAINT fk_expense_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT fk_expense_supplier FOREIGN KEY (supplier_id) REFERENCES expense_supplier (id),
  CONSTRAINT fk_expense_type FOREIGN KEY (type_id) REFERENCES expense_type (id),
  CONSTRAINT fk_expense_file FOREIGN KEY (file_id) REFERENCES stored_file (id),
  CONSTRAINT fk_expense_created_by FOREIGN KEY (created_by) REFERENCES staff_user (id),
  CONSTRAINT fk_expense_updated_by FOREIGN KEY (updated_by) REFERENCES staff_user (id),
  CONSTRAINT fk_expense_deleted_by FOREIGN KEY (deleted_by) REFERENCES staff_user (id),
  CONSTRAINT ck_expense_money CHECK (net_pence BETWEEN 1 AND 999999999 AND vat_pence BETWEEN 0 AND 999999999 AND total_pence = net_pence + vat_pence),
  CONSTRAINT ck_expense_deleted CHECK ((deleted_at IS NULL) = (deleted_actor IS NULL) AND (deleted_at IS NULL) = (deleted_reason IS NULL)
    AND (deleted_at IS NOT NULL OR deleted_by IS NULL)),
  CONSTRAINT ck_expense_version CHECK (version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='an expense invoice of one shop (docs/decisions.md EX): changed in place with its history, deleted by a mark, never removed';

CREATE TABLE IF NOT EXISTS regular_cost (
  id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  name           VARCHAR(100)      NOT NULL,
  channel_id     SMALLINT UNSIGNED NOT NULL COMMENT 'the ONE shop it is for',
  type_id        SMALLINT UNSIGNED NOT NULL COMMENT 'its group (expense_type of kind regular)',
  every          ENUM('week','month','year') NOT NULL,
  amount_pence   BIGINT            NOT NULL COMMENT 'a week''s, a calendar month''s or a calendar year''s amount, counted as given',
  from_day       DATE              NOT NULL COMMENT 'its first day',
  until_day      DATE              NULL COMMENT 'its last day; NULL: no end',
  note           VARCHAR(500)      NULL,
  replaces_id    INT UNSIGNED      NULL COMMENT 'the line this one continues with a new amount (that one ends the day before this one starts)',
  version        INT UNSIGNED      NOT NULL DEFAULT 1 COMMENT 'its latest expense_history version',
  created_by     INT UNSIGNED      NULL,
  created_actor  VARCHAR(64)       NOT NULL,
  created_at     DATETIME(6)       NOT NULL,
  updated_by     INT UNSIGNED      NULL,
  updated_actor  VARCHAR(64)       NOT NULL,
  updated_at     DATETIME(6)       NOT NULL,
  deleted_at     DATETIME(6)       NULL COMMENT 'deleted by a mark (a mistake): out of the profit on every day, kept with its history',
  deleted_by     INT UNSIGNED      NULL,
  deleted_actor  VARCHAR(64)       NULL,
  deleted_reason VARCHAR(500)      NULL,
  PRIMARY KEY (id),
  KEY ix_regular_cost_channel (channel_id, from_day),
  KEY ix_regular_cost_type (type_id),
  KEY ix_regular_cost_replaces (replaces_id),
  KEY ix_regular_cost_created_by (created_by),
  KEY ix_regular_cost_updated_by (updated_by),
  KEY ix_regular_cost_deleted_by (deleted_by),
  CONSTRAINT fk_regular_cost_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT fk_regular_cost_type FOREIGN KEY (type_id) REFERENCES expense_type (id),
  CONSTRAINT fk_regular_cost_replaces FOREIGN KEY (replaces_id) REFERENCES regular_cost (id),
  CONSTRAINT fk_regular_cost_created_by FOREIGN KEY (created_by) REFERENCES staff_user (id),
  CONSTRAINT fk_regular_cost_updated_by FOREIGN KEY (updated_by) REFERENCES staff_user (id),
  CONSTRAINT fk_regular_cost_deleted_by FOREIGN KEY (deleted_by) REFERENCES staff_user (id),
  CONSTRAINT ck_regular_cost_amount CHECK (amount_pence BETWEEN 1 AND 999999999),
  CONSTRAINT ck_regular_cost_days CHECK (until_day IS NULL OR until_day >= from_day),
  CONSTRAINT ck_regular_cost_deleted CHECK ((deleted_at IS NULL) = (deleted_actor IS NULL) AND (deleted_at IS NULL) = (deleted_reason IS NULL)
    AND (deleted_at IS NOT NULL OR deleted_by IS NULL)),
  CONSTRAINT ck_regular_cost_row CHECK (CHAR_LENGTH(TRIM(name)) >= 2 AND version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a cost with no invoice, every week, month or year, counted per day (docs/decisions.md EX): never removed';

CREATE TABLE IF NOT EXISTS expense_history (
  id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  subject        ENUM('expense','regular_cost','supplier','type') NOT NULL,
  subject_id     BIGINT UNSIGNED   NOT NULL,
  version        INT UNSIGNED      NOT NULL,
  action         VARCHAR(16)       NOT NULL COMMENT 'add, change, end, delete, switch_off, switch_on',
  state          JSON              NOT NULL COMMENT 'the row after',
  before_state   JSON              NULL COMMENT 'the row before (NULL for an add)',
  reason         VARCHAR(500)      NULL,
  actor          VARCHAR(64)       NOT NULL,
  staff_user_id  INT UNSIGNED      NULL,
  created_at     DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_expense_history_version (subject, subject_id, version),
  KEY ix_expense_history_staff (staff_user_id),
  CONSTRAINT fk_expense_history_staff FOREIGN KEY (staff_user_id) REFERENCES staff_user (id),
  CONSTRAINT ck_expense_history_first CHECK (version >= 1 AND (version = 1) = (action = 'add') AND (version = 1) = (before_state IS NULL)),
  CONSTRAINT ck_expense_history_state CHECK (JSON_TYPE(state) = 'OBJECT' AND (before_state IS NULL OR JSON_TYPE(before_state) = 'OBJECT'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='who added, changed, ended or deleted an expense, a regular cost, a supplier or a type, when, why, before and after (append-only)';

-- The start lists (the lead's brief, 24 Nov 2026): the expense types, and the regular costs' groups as the approved drawing groups them.
-- Only those not there yet (a run again adds nothing; a name the owner changed later is not his start name, and is not added again while
-- the list has its history of the start).
INSERT INTO expense_type (kind, name, sort_order, created_actor)
  SELECT s.kind, s.name, s.sort_order, 'system:migrate' FROM (
    SELECT 'expense' AS kind, 'Stationery and packaging' AS name, 10 AS sort_order UNION ALL
    SELECT 'expense', 'Software and AI', 20 UNION ALL
    SELECT 'expense', 'Developer', 30 UNION ALL
    SELECT 'expense', 'Fees', 40 UNION ALL
    SELECT 'expense', 'Other', 90 UNION ALL
    SELECT 'regular', 'Rent', 10 UNION ALL
    SELECT 'regular', 'Running the business', 20 UNION ALL
    SELECT 'regular', 'Marketing, website and people', 30 UNION ALL
    SELECT 'regular', 'Other', 90) s
  WHERE NOT EXISTS (SELECT 1 FROM expense_history h WHERE h.subject = 'type' AND h.actor = 'system:migrate')
    AND NOT EXISTS (SELECT 1 FROM expense_type t WHERE t.kind = s.kind AND t.name = s.name);

INSERT INTO expense_history (subject, subject_id, version, action, state, actor)
  SELECT 'type', t.id, 1, 'add', JSON_OBJECT('kind', t.kind, 'name', t.name, 'is_active', t.is_active, 'sort_order', t.sort_order), 'system:migrate'
  FROM expense_type t WHERE t.created_actor = 'system:migrate'
    AND NOT EXISTS (SELECT 1 FROM expense_history h WHERE h.subject = 'type' AND h.subject_id = t.id);

-- The two new permissions in the stored map with their baselines (as 0077 added the delivery bills'): expenses.view for Finance and the new
-- job, expenses.edit for the new job. Run again: the rows as they are then, no second baseline.
INSERT INTO role_permission (permission, roles, updated_actor) VALUES ('expenses.view', 'finance,expenses', 'system:migrate'),
  ('expenses.edit', 'expenses', 'system:migrate')
  ON DUPLICATE KEY UPDATE permission = permission;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'permission', p.permission, 1, 'baseline', JSON_OBJECT('roles', p.roles), 'system:migrate'
  FROM role_permission p WHERE p.permission IN ('expenses.view', 'expenses.edit')
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'permission' AND c.subject_key = p.permission);

ALTER TABLE staff_role MODIFY COLUMN role ENUM('viewer','mapper','mapping_lead','warehouse','manager','admin','buyer','purchasing_manager',
    'goods_in','purchasing_desk','stock_controller','reviewer','accountant','auditor','people_manager','finance','delivery_bills','expenses') NOT NULL,
  ALGORITHM = COPY, LOCK = SHARED;
