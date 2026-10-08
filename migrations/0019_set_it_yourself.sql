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
--  * staff_user.setup_until: a person set up on the screen (or told to choose a new password) sets their own password at
--    /ui/enrol with their e-mail and the code of their phone until then. The secret of the code app is shown once, as a QR code.
--  * staff_role_request: a grant of Admin or Reviewer waiting for a reviewer's OK, while approvals.staff_grant is on (default off).
--  * supplier.approved_alone / route_alone / alone_change_id: a supplier activated (or its import route approved) by one person
--    while approvals.supplier_activation was off, with the config_change version of the switch that allowed it (S2, S3).
--  * integrity_run: one row per run of bin/invariants.php (append-only): the Safety checks page and the Home card read it.
-- Applied together with the code of the same commit (install_cron.sh --migrate): the old code still runs on the new schema
-- (new columns have defaults), but the new code needs the new tables.

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
 ('approvals.spot_check_size','int','20',1,'2','Matches in a spot check; a smaller spot check cannot unlock a bulk confirm'),
 ('staff.setup_hours','int','48',1,NULL,'Hours a new person, or one told to choose a new password, has to set up their sign-in'),
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
    COMMENT 'until then the person may set their own password at /ui/enrol with the code of their phone (set up on the screen, or told to choose a new password)'
    AFTER password_must_change;

CREATE TABLE staff_role_request (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_user_id       INT UNSIGNED NOT NULL,
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
  CONSTRAINT ck_staff_role_request_not_own CHECK (decided_by IS NULL OR ((requested_by IS NULL OR decided_by <> requested_by) AND decided_by <> staff_user_id))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a grant of Admin or Reviewer waiting for a reviewer''s OK (approvals.staff_grant, off by default; Y25)';

ALTER TABLE supplier
  ADD COLUMN approved_alone TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1: activated by one person while approvals.supplier_activation was off' AFTER approved_at,
  ADD COLUMN route_alone TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1: the import route approved by one person while approvals.supplier_activation was off' AFTER approved_alone,
  ADD COLUMN alone_change_id BIGINT UNSIGNED NULL COMMENT 'the config_change version (switch off) under which the last one-person approval was made' AFTER route_alone,
  ADD CONSTRAINT fk_supplier_alone_change FOREIGN KEY (alone_change_id) REFERENCES config_change (id),
  ADD CONSTRAINT ck_supplier_alone CHECK (approved_alone IN (0, 1) AND route_alone IN (0, 1)
      AND (approved_alone + route_alone = 0 OR alone_change_id IS NOT NULL));

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
