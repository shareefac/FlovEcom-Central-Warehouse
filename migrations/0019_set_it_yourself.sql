-- 0019_set_it_yourself.sql — the "set it yourself" pack (the owner's rule of 8 Oct 2026: no hard-coding; every rule, number,
-- limit, list and choice is changed on a CW screen, with a reason and an audit trail; docs/decisions.md Y1-Y40, gaps G01-G07, G36,
-- G37 of the inventory coverage matrix). Nothing here books stock.
--
--  * config_change: the append-only history of every configuration row a screen can change (settings, reason codes, document
--    rules, warehouses, warehouse places): one row per version with the whole tracked row after it (`state`), the row before it,
--    the reason, who and when. Version 1 of every row that exists today is a `baseline` written below. The app login may now
--    UPDATE some columns of app_setting, document_type, reason_code and warehouse (column grants): the nightly check compares
--    each row that has a history with its latest version (Admin\ConfigInvariants K1-K3), so a change made without one is found.
--  * app_setting: the approval switches (decision 11 / Q8: the existing two-person rules ON, the new staff-grant rule OFF), the
--    spot-check size, the staff set-up window and how many reviewers the warnings ask for.
--  * warehouse: switched off (never deleted, Q9), whose stock it is (own / another account's: the owner's VPG 2 room, Q6; never
--    sellable, a CHECK), the three system warehouses (MAIN, VERIFY, UNSTAMPED: never switched off or made (un)sellable on a
--    screen), a note and an order.
--  * warehouse_location: OPTIONAL places inside a warehouse (a shelf, the overflow room, Q2/Q6). Nothing requires one;
--    document_line.location_id is prepared for the count and adjustment screens and nothing writes it yet.
--  * staff_user: the sign-in set-up (docs/decisions.md Y20-Y22 as amended by Y40-Y44; they replace I13 and I35). An admin never
--    holds both sign-in factors of anybody: totp_state says whose the current authenticator secret is (`own`: the person's own,
--    made on their own page or by the server tool; `signup`: a sign-up sheet's, which an admin saw: it works only at /ui/enrol,
--    with the one-time set-up code; `reset`: a "new sign-in code" sheet's, which an admin saw: it works only at /ui/login with
--    the person's CURRENT password). Either sheet's secret dies at its first use: the person's own page then shows a FRESH
--    secret (totp_next_*: the encrypted secret, the hash of the browser's step token, until when, which route, wrong tries),
--    confirmed with one code before any session exists. setup_until / setup_code_hash / setup_fails: the set-up window, its
--    one-time code (sha256 only) and its wrong tries (staff.setup_max_fails closes it: setup_closed_at, a Home card).
--    ck_staff_user_one_factor: a "new sign-in code" never coexists with a set-up window.
--  * staff_role_request: a grant of Admin or Reviewer waiting for a reviewer's OK, while approvals.staff_grant is on (default off);
--    since Y44 also a reset of an Admin's or a Reviewer's sign-in while approvals.staff_reset is on (default off): `kind`, and
--    `used_at` once the admin carried out the approved reset.
--  * supplier.approved_alone / route_alone / alone_change_id: a supplier activated (or its import route approved) by one person
--    while approvals.supplier_activation was off, with the config_change version of the switch that allowed it (S2, S3);
--    alone_checked_by / _at: a reviewer's later OK of such a supplier (clears the list's "approved alone" mark, Y49).
--  * integrity_run: one row per run of bin/invariants.php (append-only): the Safety checks page and the Home card read it.
--  * key_sample: the spot-check size in force when a sample was drawn (required_size, backfilled with each sample's own size) and
--    a size CHECK that follows the setting's range (5 or more), Y46-Y47.
--  * reason_code.applies_to: where a reason is offered on the purchase-order screens (po_cancel, po_draft_cancel, po_amend; the
--    reasons the code listed before are seeded with them, as version 2 of their history), Y51.
--  * document_type: Not OK on a purchase order only records it (a PO with deliveries cannot be cancelled), Y50.
-- Applied together with the code of the same commit (install_cron.sh --migrate): the old code still runs on the new schema
-- (new columns have defaults), but the new code needs the new tables. Pre-flight (docs/ops.md): no warehouse has owner_entity set,
-- and none of the new setting keys exists yet.

CREATE TABLE config_change (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject_type   ENUM('setting','reason','document_rule','warehouse','location') NOT NULL,
  subject_key    VARCHAR(64)  NOT NULL COMMENT 'setting key, reason code, document type code, warehouse code, WAREHOUSE/PLACE',
  version        INT UNSIGNED NOT NULL,
  action         VARCHAR(32)  NOT NULL COMMENT 'baseline, add, change, agree, rename, switch_off, switch_on, sellable, owner',
  state          JSON         NOT NULL COMMENT 'the tracked row after this version (Admin\\ConfigHistory::TRACKED)',
  before_state   JSON         NULL COMMENT 'the tracked row before (NULL for a baseline and an add)',
  reason         VARCHAR(500) NULL,
  actor          VARCHAR(64)  NOT NULL,
  staff_user_id  INT UNSIGNED NULL,
  created_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_config_change_version (subject_type, subject_key, version),
  KEY ix_config_change_staff (staff_user_id),
  KEY ix_config_change_created (created_at),
  CONSTRAINT fk_config_change_staff FOREIGN KEY (staff_user_id) REFERENCES staff_user (id),
  CONSTRAINT ck_config_change_version CHECK (version >= 1),
  CONSTRAINT ck_config_change_state CHECK (JSON_TYPE(state) = 'OBJECT' AND (before_state IS NULL OR JSON_TYPE(before_state) = 'OBJECT')),
  CONSTRAINT ck_config_change_reason CHECK (action = 'baseline' OR (reason IS NOT NULL AND CHAR_LENGTH(reason) >= 3)),
  CONSTRAINT ck_config_change_first CHECK ((version = 1) = (action IN ('baseline', 'add')) AND ((version = 1) = (before_state IS NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='history of every configuration row the screens change (append-only for cw_app; Y2)';

-- The approval switches and the other new settings (provisional: the owner confirms each on the screen).
INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('approvals.supplier_activation','bool','true',1,'11','A new supplier, a supplier switched back on and an overseas supplier''s import route wait for a second person''s approval; off: the buyer''s request activates it at once (recorded as approved alone)'),
 ('approvals.match_multiple','bool','true',1,'11','A match where 1 sale is not 1 product (units per item other than 1) waits for a second matching lead'),
 ('approvals.match_counted','bool','true',1,'11','A merge or split touching an item whose stock was counted waits for a second matching lead'),
 ('approvals.company_own_change','bool','true',1,'11','A reviewer who confirms their own change of the legal name, numbers, purchasing e-mail or delivery address gets another reviewer''s check'),
 ('approvals.staff_grant','bool','false',1,'Q8','Giving someone Admin or Reviewer waits for a reviewer''s OK; off: the admin''s change applies at once'),
 ('approvals.staff_reset','bool','false',1,'Q8','A new sign-in code, a new password or a new sign-up sheet for someone with Admin or Reviewer waits for a reviewer''s OK; off: the admin does it at once'),
 ('approvals.spot_check_size','int','20',1,'2','Matches in a spot check; a smaller spot check cannot unlock a bulk confirm (a sample is judged by the size in force when it was drawn)'),
 ('staff.setup_hours','int','48',1,NULL,'Hours a new person, or one told to choose a new password, has to set up their sign-in'),
 ('staff.setup_max_fails','int','5',1,NULL,'Wrong tries at setting up a sign-in before the set-up window closes (the admin then makes a new sheet)'),
 ('staff.sign_in_address','string','""',1,NULL,'The address staff open to sign in (https://...): printed on the sign-up sheets'),
 ('staff.min_reviewers','int','2',1,'3','Fewest people who can approve work before the staff list and Home warn');

ALTER TABLE warehouse
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = switched off (only when empty; never deleted, Q9)' AFTER is_sellable,
  ADD COLUMN stock_owner ENUM('own','other') NOT NULL DEFAULT 'own'
    COMMENT 'other: stock owned by another account (the VPG 2 room, Q6), never sellable; owner_entity names the owner' AFTER is_active,
  ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = MAIN, VERIFY, UNSTAMPED: the code names them (D11); never switched off or (un)made sellable on a screen' AFTER owner_entity,
  ADD COLUMN note VARCHAR(255) NULL AFTER is_system,
  ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER note,
  ADD CONSTRAINT ck_warehouse_flags CHECK (is_active IN (0, 1) AND is_system IN (0, 1)),
  ADD CONSTRAINT ck_warehouse_owner CHECK ((stock_owner = 'own' AND owner_entity IS NULL)
      OR (stock_owner = 'other' AND is_sellable = 0 AND owner_entity IS NOT NULL AND CHAR_LENGTH(owner_entity) >= 2)),
  ADD CONSTRAINT ck_warehouse_system CHECK (is_system = 0 OR is_active = 1);
UPDATE warehouse SET is_system = 1, sort_order = CASE code WHEN 'MAIN' THEN 10 WHEN 'VERIFY' THEN 20 ELSE 30 END
  WHERE code IN ('MAIN', 'VERIFY', 'UNSTAMPED');

CREATE TABLE warehouse_location (
  id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  warehouse_id  SMALLINT UNSIGNED NOT NULL,
  code          VARCHAR(31)       NOT NULL COMMENT 'A-01, OVERFLOW ...: upper case, digits, _ and -',
  name          VARCHAR(100)      NOT NULL,
  is_active     TINYINT(1)        NOT NULL DEFAULT 1,
  note          VARCHAR(255)      NULL,
  created_at    DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_warehouse_location_code (warehouse_id, code),
  CONSTRAINT fk_warehouse_location_wh FOREIGN KEY (warehouse_id) REFERENCES warehouse (id),
  CONSTRAINT ck_warehouse_location_code CHECK (REGEXP_LIKE(code, '^[A-Z0-9][A-Z0-9_-]{0,30}$', 'c')),
  CONSTRAINT ck_warehouse_location_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='optional places inside a warehouse (shelf, overflow room); nothing requires one (Q2); switched off, never deleted';

ALTER TABLE document_line
  ADD COLUMN location_id INT UNSIGNED NULL COMMENT 'optional place inside the line''s warehouse (prepared: nothing writes it yet; Q2)' AFTER warehouse_id,
  ADD KEY ix_document_line_location (location_id),
  ADD CONSTRAINT fk_document_line_location FOREIGN KEY (location_id) REFERENCES warehouse_location (id);

ALTER TABLE staff_user
  ADD COLUMN setup_until DATETIME(6) NULL
    COMMENT 'until then the person may start /ui/enrol with their e-mail, the one-time set-up code and the code of their phone (added on the screen, or told to choose a new password)'
    AFTER password_must_change,
  ADD COLUMN setup_code_hash CHAR(64) NULL COMMENT 'sha256 of the one-time set-up code printed on the sheet (never the code); used once' AFTER setup_until,
  ADD COLUMN setup_fails SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'wrong tries in this set-up window (staff.setup_max_fails closes it)' AFTER setup_code_hash,
  ADD COLUMN setup_closed_at DATETIME(6) NULL COMMENT 'the set-up window was closed after too many wrong tries (Home card until a new sheet)' AFTER setup_fails,
  ADD COLUMN totp_state ENUM('own','signup','reset') NOT NULL DEFAULT 'own'
    COMMENT 'own: the person''s own secret; signup / reset: a sheet''s secret an admin saw (works once: at /ui/enrol / at /ui/login with the current password)'
    AFTER totp_last_step,
  ADD COLUMN totp_next_enc VARBINARY(255) NULL COMMENT 'the FRESH secret shown on the person''s own page, until they confirm it with one code (SecretBox)' AFTER totp_state,
  ADD COLUMN totp_next_token CHAR(64) NULL COMMENT 'sha256 of the step token in that browser''s cookie (never the token)' AFTER totp_next_enc,
  ADD COLUMN totp_next_until DATETIME(6) NULL AFTER totp_next_token,
  ADD COLUMN totp_next_route ENUM('enrol','login') NULL COMMENT 'enrol: the person also chooses their password' AFTER totp_next_until,
  ADD COLUMN totp_next_fails SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER totp_next_route,
  ADD UNIQUE KEY uq_staff_user_next_token (totp_next_token),
  ADD CONSTRAINT ck_staff_user_setup_code CHECK (setup_code_hash IS NULL OR (setup_until IS NOT NULL AND REGEXP_LIKE(setup_code_hash, '^[0-9a-f]{64}$', 'c'))),
  ADD CONSTRAINT ck_staff_user_one_factor CHECK (totp_state <> 'reset' OR setup_until IS NULL),
  ADD CONSTRAINT ck_staff_user_next CHECK ((totp_next_token IS NULL) = (totp_next_enc IS NULL) AND (totp_next_token IS NULL) = (totp_next_until IS NULL)
      AND (totp_next_token IS NULL) = (totp_next_route IS NULL) AND (totp_next_token IS NULL OR REGEXP_LIKE(totp_next_token, '^[0-9a-f]{64}$', 'c')));

CREATE TABLE staff_role_request (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_user_id       INT UNSIGNED NOT NULL,
  kind                ENUM('roles','reset_code','reset_password') NOT NULL DEFAULT 'roles'
                      COMMENT 'roles: a grant of Admin or Reviewer (staff_grant); reset_*: a reset of an Admin''s or a Reviewer''s sign-in (staff_reset)',
  roles_before        JSON         NOT NULL COMMENT 'the live jobs when asked (the OK applies only while they are still these)',
  roles_after         JSON         NOT NULL,
  guarded             JSON         NOT NULL COMMENT 'the jobs that need the OK (admin, reviewer) among those added',
  state               ENUM('open','approved','rejected','withdrawn') NOT NULL DEFAULT 'open',
  requested_by        INT UNSIGNED NULL,
  requested_actor     VARCHAR(64)  NOT NULL,
  requested_at        DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  decided_by          INT UNSIGNED NULL,
  decided_at          DATETIME(6)  NULL,
  decision_note       VARCHAR(500) NULL,
  used_at             DATETIME(6)  NULL COMMENT 'an approved reset carried out by an admin (once)',
  open_staff_user_id  INT UNSIGNED GENERATED ALWAYS AS (IF(state = 'open', staff_user_id, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_staff_role_request_open (open_staff_user_id),
  KEY ix_staff_role_request_user (staff_user_id),
  KEY ix_staff_role_request_by (requested_by),
  KEY ix_staff_role_request_decided (decided_by),
  CONSTRAINT fk_staff_role_request_user FOREIGN KEY (staff_user_id) REFERENCES staff_user (id),
  CONSTRAINT fk_staff_role_request_by FOREIGN KEY (requested_by) REFERENCES staff_user (id),
  CONSTRAINT fk_staff_role_request_decided FOREIGN KEY (decided_by) REFERENCES staff_user (id),
  CONSTRAINT ck_staff_role_request_json CHECK (JSON_TYPE(roles_before) = 'ARRAY' AND JSON_TYPE(roles_after) = 'ARRAY' AND JSON_TYPE(guarded) = 'ARRAY'),
  CONSTRAINT ck_staff_role_request_decided CHECK ((state = 'open') = (decided_at IS NULL)),
  CONSTRAINT ck_staff_role_request_decider CHECK (state NOT IN ('approved', 'rejected') OR decided_by IS NOT NULL),
  CONSTRAINT ck_staff_role_request_not_own CHECK (decided_by IS NULL OR ((requested_by IS NULL OR decided_by <> requested_by) AND decided_by <> staff_user_id)),
  CONSTRAINT ck_staff_role_request_used CHECK (used_at IS NULL OR (kind <> 'roles' AND state = 'approved'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a grant of Admin or Reviewer (approvals.staff_grant) or a reset of their sign-in (approvals.staff_reset) waiting for a reviewer''s OK; both off by default (Y25, Y44)';

ALTER TABLE supplier
  ADD COLUMN approved_alone TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1: activated by one person while approvals.supplier_activation was off' AFTER approved_at,
  ADD COLUMN route_alone TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1: the import route approved by one person while approvals.supplier_activation was off' AFTER approved_alone,
  ADD COLUMN alone_change_id BIGINT UNSIGNED NULL COMMENT 'the config_change version (switch off) under which the last one-person approval was made' AFTER route_alone,
  ADD COLUMN alone_checked_by INT UNSIGNED NULL COMMENT 'a reviewer who gave the OK afterwards (clears the list''s "approved alone" mark; the marks stay as evidence)' AFTER alone_change_id,
  ADD COLUMN alone_checked_at DATETIME(6) NULL AFTER alone_checked_by,
  ADD KEY ix_supplier_alone_checked_by (alone_checked_by),
  ADD CONSTRAINT fk_supplier_alone_change FOREIGN KEY (alone_change_id) REFERENCES config_change (id),
  ADD CONSTRAINT fk_supplier_alone_checked_by FOREIGN KEY (alone_checked_by) REFERENCES staff_user (id),
  ADD CONSTRAINT ck_supplier_alone CHECK (approved_alone IN (0, 1) AND route_alone IN (0, 1)
      AND (approved_alone + route_alone = 0 OR alone_change_id IS NOT NULL)),
  ADD CONSTRAINT ck_supplier_alone_checked CHECK ((alone_checked_by IS NULL) = (alone_checked_at IS NULL)
      AND (alone_checked_by IS NULL OR approved_alone + route_alone > 0));

-- The spot-check size in force when each sample was drawn (Y47): a sample is judged against it, never against today's setting,
-- so raising the setting does not disqualify a sample in progress. Existing samples (owner-1: 20 of 20) get their own size.
-- The size CHECK follows the setting's range (5 to 200; Y46).
ALTER TABLE key_sample
  ADD COLUMN required_size SMALLINT UNSIGNED NULL COMMENT 'approvals.spot_check_size when the sample was drawn: the fewest members it needs' AFTER sample_size,
  DROP CHECK ck_key_sample_size;
UPDATE key_sample SET required_size = sample_size;
ALTER TABLE key_sample
  MODIFY COLUMN required_size SMALLINT UNSIGNED NOT NULL COMMENT 'approvals.spot_check_size when the sample was drawn: the fewest members it needs',
  ADD CONSTRAINT ck_key_sample_size CHECK (sample_size >= 5 AND sample_size <= population),
  ADD CONSTRAINT ck_key_sample_required CHECK (required_size >= 5 AND sample_size >= required_size);

-- Not OK on a purchase order only records it (Y50): cancelling an order a delivery was booked against is refused (po_has_receipts),
-- so "cancel it" would fail exactly when it matters. 0010 seeded 'record'.
ALTER TABLE document_type
  ADD CONSTRAINT ck_document_type_po_reject CHECK (code <> 'PO' OR reject_action = 'record');

-- Where a reason is offered on the purchase-order screens (Y51): cancelling a confirmed order (po_cancel), a draft or an order
-- waiting for its OK (po_draft_cancel), and correcting a confirmed order (po_amend). New members at the end of the SET keep every
-- stored value.
ALTER TABLE reason_code
  MODIFY COLUMN applies_to SET('adjustment','write_off','count','return','supplier_return','reversal','po_cancel','po_draft_cancel','po_amend') NOT NULL;

CREATE TABLE integrity_run (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  started_at   DATETIME(6)  NOT NULL,
  finished_at  DATETIME(6)  NOT NULL,
  ok           TINYINT(1)   NOT NULL,
  problems     INT UNSIGNED NOT NULL,
  details      JSON         NOT NULL COMMENT 'the first problems as text (a JSON array; Ops\\IntegrityRuns::KEEP)',
  stats        JSON         NULL,
  run_by       VARCHAR(64)  NOT NULL,
  PRIMARY KEY (id),
  KEY ix_integrity_run_finished (finished_at),
  CONSTRAINT ck_integrity_run CHECK (ok IN (0, 1) AND (ok = 1) = (problems = 0) AND JSON_TYPE(details) = 'ARRAY' AND finished_at >= started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='one row per run of bin/invariants.php (append-only for cw_app; the Safety checks page, Y33)';

-- Home's card for reviewers reads the staff and rule changes of the last days by action (Ui\HomeCounts::watch, Y45); the audit log
-- page's "what was done" search within a window uses it too.
ALTER TABLE audit_log ADD KEY ix_audit_action (action, created_at);

-- Version 1 (baseline) of every configuration row there is now. The state's keys are Admin\ConfigHistory::TRACKED.
INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting;
INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'reason', code, 1, 'baseline', JSON_OBJECT('label', label, 'applies_to', CAST(applies_to AS CHAR), 'direction', CAST(direction AS CHAR),
      'needs_note', needs_note, 'is_gift', is_gift, 'system_only', system_only, 'is_active', is_active, 'sort_order', sort_order), 'system:migrate'
  FROM reason_code;
INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'document_rule', code, 1, 'baseline', JSON_OBJECT('review_rule', CAST(review_rule AS CHAR), 'review_limit_units', review_limit_units,
      'review_due_days', review_due_days, 'approval_rule', CAST(approval_rule AS CHAR), 'approval_limit_units', approval_limit_units,
      'reject_action', CAST(reject_action AS CHAR)), 'system:migrate'
  FROM document_type;
INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'warehouse', code, 1, 'baseline', JSON_OBJECT('code', code, 'name', name, 'is_sellable', is_sellable, 'is_active', is_active,
      'stock_owner', CAST(stock_owner AS CHAR), 'owner_entity', owner_entity, 'is_system', is_system, 'note', note), 'system:migrate'
  FROM warehouse;

-- The purchase-order screens' reason lists come from the Reasons page (Y51): the reasons the code listed before are seeded with
-- their new uses (cancelling a confirmed order, a draft, correcting an order), and each such change is version 2 of the reason's
-- history (docs/dev.md rule 5: a migration that changes a configuration row records the next version, actor system:migrate).
UPDATE reason_code SET applies_to = CONCAT_WS(',', CAST(applies_to AS CHAR), CASE code
    WHEN 'not_needed' THEN 'po_cancel,po_draft_cancel'
    WHEN 'duplicate' THEN 'po_cancel,po_draft_cancel'
    WHEN 'supplier_cannot_supply' THEN 'po_cancel,po_draft_cancel,po_amend'
    WHEN 'entered_in_error' THEN 'po_cancel,po_draft_cancel,po_amend'
    WHEN 'other' THEN 'po_cancel,po_draft_cancel,po_amend'
    ELSE 'po_amend' END)
  WHERE code IN ('not_needed', 'duplicate', 'supplier_cannot_supply', 'entered_in_error', 'other', 'po_amended');
INSERT INTO config_change (subject_type, subject_key, version, action, state, before_state, reason, actor)
  SELECT 'reason', r.code, 2, 'change',
      JSON_OBJECT('label', r.label, 'applies_to', CAST(r.applies_to AS CHAR), 'direction', CAST(r.direction AS CHAR), 'needs_note', r.needs_note,
          'is_gift', r.is_gift, 'system_only', r.system_only, 'is_active', r.is_active, 'sort_order', r.sort_order),
      c.state,
      'Where it is offered on the purchase-order screens, as the code listed it before 0019 (the lists now come from the Reasons page)',
      'system:migrate'
  FROM reason_code r JOIN config_change c ON c.subject_type = 'reason' AND c.subject_key = r.code AND c.version = 1
  WHERE r.code IN ('not_needed', 'duplicate', 'supplier_cannot_supply', 'entered_in_error', 'other', 'po_amended');
