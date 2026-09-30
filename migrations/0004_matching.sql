-- 0004_matching.sql — linking backend: matching tables, the link history, staff roles and sessions
-- (docs/plan.md §2.2, §7, §11; matching design record A.1, A.4, A.9 adapted to v1; docs/decisions.md M1-M16).
--
--  * Only src/Mapping/DecisionService.php changes channel_listing.sku_id / units_per_item / status
--    (no DB triggers: D25 has no DELIMITER; the grant model and the code are the guard).
--  * match_run, match_reject: append-only (app login SELECT, INSERT).
--  * match_proposal, match_decision, listing_map_history: append-only except the columns a state
--    change needs (column-level UPDATE grants, CW\Schema\Grants::UPDATE_COLUMNS).

-- ---------------------------------------------------------------------------------------------
-- Staff: one role per person (design A.9), password + encrypted TOTP secret, lockout (§11)
-- ---------------------------------------------------------------------------------------------
ALTER TABLE staff_user DROP CHECK ck_staff_user_roles;

ALTER TABLE staff_user
  DROP COLUMN roles,
  ADD COLUMN role ENUM('viewer','mapper','mapping_lead','warehouse','manager','admin') NOT NULL DEFAULT 'viewer'
    COMMENT 'viewer: read; mapper: link/new item/ignore/reject; mapping_lead: + second approvals, Conflict band, merges, bulk; warehouse: count gate; manager: sell policies; admin: users' AFTER email,
  ADD COLUMN password_must_change TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 after bin/create_staff.php: the one-time password must be replaced at first login' AFTER password_hash,
  CHANGE COLUMN totp_secret totp_secret_enc VARBINARY(255) NULL
    COMMENT 'TOTP secret encrypted with app.env ui_secret_key (sodium secretbox: nonce || box); NULL = not enrolled, login refused',
  ADD CONSTRAINT ck_staff_user_must_change CHECK (password_must_change IN (0, 1));

CREATE TABLE staff_session (
  id               CHAR(64)     NOT NULL COMMENT 'sha256 hex of the session token; the token itself lives only in the cookie',
  staff_user_id    INT UNSIGNED NOT NULL,
  created_at       DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_seen_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  mfa_at           DATETIME(6)  NULL COMMENT 'when the TOTP step succeeded; NULL = password step only (no access yet)',
  ip               VARCHAR(45)  NULL,
  user_agent_hash  CHAR(64)     NULL COMMENT 'sha256 hex of the User-Agent (binds the session loosely; never the raw string)',
  revoked          TINYINT(1)   NOT NULL DEFAULT 0,
  revoked_at       DATETIME(6)  NULL,
  PRIMARY KEY (id),
  KEY ix_staff_session_user (staff_user_id, revoked),
  KEY ix_staff_session_seen (last_seen_at),
  CONSTRAINT fk_staff_session_user FOREIGN KEY (staff_user_id) REFERENCES staff_user (id),
  CONSTRAINT ck_staff_session_id CHECK (id REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT ck_staff_session_revoked CHECK (revoked IN (0, 1) AND (revoked = 0) = (revoked_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='staff screen sessions (plan §2 /ui/*: session + TOTP)';

CREATE TABLE login_attempt (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  login          VARCHAR(191) NULL COMMENT 'the typed login, lower-cased; never a password',
  staff_user_id  INT UNSIGNED NULL,
  ip             VARCHAR(45)  NOT NULL,
  step           ENUM('password','totp') NOT NULL,
  success        TINYINT(1)   NOT NULL,
  created_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY ix_login_attempt_ip (ip, created_at),
  KEY ix_login_attempt_login (login, created_at),
  KEY ix_login_attempt_created (created_at),
  CONSTRAINT ck_login_attempt_success CHECK (success IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='rate limiting of the staff login (per ip and per login); no FKs so a write never fails';

-- ---------------------------------------------------------------------------------------------
-- Central items: provenance and merges; listing features (matching inputs)
-- ---------------------------------------------------------------------------------------------
ALTER TABLE sku
  ADD COLUMN origin ENUM('vpg_mint','new_item','manual') NULL COMMENT 'how the item was created (NULL: before 0004)' AFTER counted_at,
  ADD COLUMN origin_listing_id INT UNSIGNED NULL COMMENT 'the listing the item was minted from' AFTER origin,
  ADD COLUMN merged_into_sku_id INT UNSIGNED NULL COMMENT 'set by an applied merge_skus decision; a merged item is never linked again' AFTER origin_listing_id,
  ADD KEY ix_sku_origin_listing (origin_listing_id),
  ADD KEY ix_sku_merged_into (merged_into_sku_id),
  ADD CONSTRAINT fk_sku_origin_listing FOREIGN KEY (origin_listing_id) REFERENCES channel_listing (id),
  ADD CONSTRAINT fk_sku_merged_into FOREIGN KEY (merged_into_sku_id) REFERENCES sku (id);

ALTER TABLE listing_profile
  ADD COLUMN features JSON NULL COMMENT 'rules-only identity features (CW\\Matching\\Normalizer); cleared when identity_hash changes' AFTER identity_hash,
  ADD COLUMN features_version VARCHAR(32) NULL COMMENT 'normalizer version of features' AFTER features,
  ADD CONSTRAINT ck_listing_profile_features CHECK (features IS NULL OR JSON_TYPE(features) = 'OBJECT');

-- ---------------------------------------------------------------------------------------------
-- Matching runs and proposals (append-only; a proposal's status is its only mutable column)
-- ---------------------------------------------------------------------------------------------
CREATE TABLE match_run (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id          VARCHAR(64)  NOT NULL COMMENT 'e.g. run3-sold',
  source          VARCHAR(32)  NOT NULL COMMENT 'first_match | vpg_duplicates | ai_batch | manual',
  prompt_sha      CHAR(64)     NULL COMMENT 'sha256 of the judge prompt',
  engine_version  VARCHAR(128) NULL COMMENT 'CW\\Matching versions (normalizer/candidates/veto/band/...)',
  model_summary   JSON NULL COMMENT 'models that answered, e.g. {"claude-opus-5-5": {"chunks": 25}}',
  detail          JSON NULL COMMENT 'input files and their sha256',
  created_at      DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_match_run (run_id, source),
  CONSTRAINT ck_match_run_prompt CHECK (prompt_sha IS NULL OR prompt_sha REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='append-only: one row per matching run whose proposals were imported';

CREATE TABLE match_proposal (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  listing_id         INT UNSIGNED NOT NULL,
  match_run_id       INT UNSIGNED NOT NULL,
  proposed_sku_id    INT UNSIGNED NULL COMMENT 'the item the run proposes; NULL = none (new item, can''t tell, conflict)',
  proposed_new_item  TINYINT(1)   NOT NULL DEFAULT 0,
  band               ENUM('Key','Check','New item','Can''t tell','Conflict','Manual') NOT NULL,
  lane               VARCHAR(32)  NULL COMMENT 'barcode | transfer | candidates | vpg_duplicate',
  ai_outcome         VARCHAR(32)  NULL COMMENT 'match | no_match_in_list | cannot_tell | multiple_plausible',
  ai_confidence      TINYINT UNSIGNED NULL,
  ai_units_per_item  SMALLINT UNSIGNED NULL,
  ai_model           VARCHAR(64)  NULL,
  closest_sku_id     INT UNSIGNED NULL COMMENT 'the judge''s closest item when it did not match',
  evidence           JSON NULL COMMENT 'lane target, AI answer and reason, candidates (sku ids, prescores, vetoes)',
  flags              JSON NULL COMMENT 'sorted list of flag codes',
  status             ENUM('open','decided','superseded') NOT NULL DEFAULT 'open',
  open_listing_id    INT UNSIGNED GENERATED ALWAYS AS (IF(status = 'open', listing_id, NULL)) STORED COMMENT 'at most one open proposal per listing',
  created_at         DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_match_proposal_run_listing (match_run_id, listing_id),
  UNIQUE KEY uq_match_proposal_open (open_listing_id),
  KEY ix_match_proposal_listing (listing_id, status),
  KEY ix_match_proposal_queue (status, band),
  KEY ix_match_proposal_sku (proposed_sku_id),
  KEY ix_match_proposal_closest (closest_sku_id),
  CONSTRAINT fk_match_proposal_listing FOREIGN KEY (listing_id) REFERENCES channel_listing (id),
  CONSTRAINT fk_match_proposal_run FOREIGN KEY (match_run_id) REFERENCES match_run (id),
  CONSTRAINT fk_match_proposal_sku FOREIGN KEY (proposed_sku_id) REFERENCES sku (id),
  CONSTRAINT fk_match_proposal_closest FOREIGN KEY (closest_sku_id) REFERENCES sku (id),
  CONSTRAINT ck_match_proposal_new_item CHECK (proposed_new_item IN (0, 1) AND NOT (proposed_new_item = 1 AND proposed_sku_id IS NOT NULL)),
  CONSTRAINT ck_match_proposal_confidence CHECK (ai_confidence IS NULL OR ai_confidence <= 100),
  CONSTRAINT ck_match_proposal_units CHECK (ai_units_per_item IS NULL OR ai_units_per_item >= 1),
  CONSTRAINT ck_match_proposal_flags CHECK (flags IS NULL OR JSON_TYPE(flags) = 'ARRAY')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='immutable except status (open -> decided | superseded); written under the listing''s row lock';

-- ---------------------------------------------------------------------------------------------
-- Decisions (append-only; state, applied_at and second_by are the only mutable columns)
-- ---------------------------------------------------------------------------------------------
CREATE TABLE match_decision (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  listing_id            INT UNSIGNED NOT NULL COMMENT 'merge_skus: a listing of the item merged away (the anchor)',
  proposal_id           BIGINT UNSIGNED NULL,
  action                ENUM('link','unlink','new_item','ignore','reject','suggest','merge_skus') NOT NULL,
  sku_id                INT UNSIGNED NULL COMMENT 'link: target; new_item: the minted item (NULL while pending, see listing_map_history); reject: the item rejected; merge_skus: the item kept',
  units_per_item        SMALLINT UNSIGNED NULL COMMENT 'link / new_item',
  merge_from_sku_id     INT UNSIGNED NULL COMMENT 'merge_skus: the item folded into sku_id',
  prev_sku_id           INT UNSIGNED NULL COMMENT 'the listing''s link when the decision was made',
  prev_units_per_item   SMALLINT UNSIGNED NULL,
  prev_status           ENUM('unmapped','suggested','mapped','quarantined','ignored') NULL,
  decided_by            INT UNSIGNED NULL COMMENT 'NULL only for a system suggest',
  actor                 VARCHAR(64)  NOT NULL COMMENT 'staff:<id> | system:<job>',
  second_by             INT UNSIGNED NULL COMMENT 'who approved (applied) or withdrew (withdrawn) a pending_second decision',
  needs_second          JSON NULL COMMENT 'why two people are needed: protected_sku, units_per_item, merge, previously_rejected',
  state                 ENUM('pending_second','applied','withdrawn') NOT NULL,
  reason                VARCHAR(500) NULL,
  expected_map_version  INT UNSIGNED NOT NULL COMMENT 'the channel_listing.map_version the decider saw (409 on mismatch)',
  bulk_batch_id         VARCHAR(64)  NULL,
  detail                JSON NULL COMMENT 'new_item: the identity card to mint; approvals re-use it',
  pending_listing_id    INT UNSIGNED GENERATED ALWAYS AS (IF(state = 'pending_second', listing_id, NULL)) STORED COMMENT 'at most one pending decision per listing',
  created_at            DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  applied_at            DATETIME(6)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_match_decision_pending (pending_listing_id),
  KEY ix_match_decision_listing (listing_id, id),
  KEY ix_match_decision_state (state, created_at),
  KEY ix_match_decision_proposal (proposal_id),
  KEY ix_match_decision_sku (sku_id),
  KEY ix_match_decision_merge_from (merge_from_sku_id),
  KEY ix_match_decision_bulk (bulk_batch_id),
  KEY ix_match_decision_decided_by (decided_by, created_at),
  KEY ix_match_decision_second_by (second_by),
  CONSTRAINT fk_match_decision_listing FOREIGN KEY (listing_id) REFERENCES channel_listing (id),
  CONSTRAINT fk_match_decision_proposal FOREIGN KEY (proposal_id) REFERENCES match_proposal (id),
  CONSTRAINT fk_match_decision_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_match_decision_merge_from FOREIGN KEY (merge_from_sku_id) REFERENCES sku (id),
  CONSTRAINT fk_match_decision_decided_by FOREIGN KEY (decided_by) REFERENCES staff_user (id),
  CONSTRAINT fk_match_decision_second_by FOREIGN KEY (second_by) REFERENCES staff_user (id),
  CONSTRAINT ck_match_decision_decider CHECK (action = 'suggest' OR decided_by IS NOT NULL),
  CONSTRAINT ck_match_decision_applied CHECK ((state = 'applied') = (applied_at IS NOT NULL)),
  CONSTRAINT ck_match_decision_second CHECK (state <> 'applied' OR second_by IS NULL OR second_by <> decided_by),
  CONSTRAINT ck_match_decision_pending CHECK (state <> 'pending_second' OR second_by IS NULL),
  CONSTRAINT ck_match_decision_link CHECK (action <> 'link' OR (sku_id IS NOT NULL AND units_per_item IS NOT NULL)),
  CONSTRAINT ck_match_decision_reject CHECK (action <> 'reject' OR sku_id IS NOT NULL),
  CONSTRAINT ck_match_decision_new_item CHECK (action <> 'new_item' OR units_per_item IS NOT NULL),
  CONSTRAINT ck_match_decision_merge CHECK ((action = 'merge_skus') = (merge_from_sku_id IS NOT NULL)
    AND (action <> 'merge_skus' OR (sku_id IS NOT NULL AND sku_id <> merge_from_sku_id))),
  CONSTRAINT ck_match_decision_units CHECK ((units_per_item IS NULL OR units_per_item >= 1) AND (prev_units_per_item IS NULL OR prev_units_per_item >= 1)),
  CONSTRAINT ck_match_decision_bulk CHECK (bulk_batch_id IS NULL OR bulk_batch_id REGEXP '^[A-Za-z0-9._:-]{1,64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='append-only (app: SELECT, INSERT, UPDATE of state/applied_at/second_by); written only by DecisionService';

CREATE TABLE listing_map_history (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  listing_id             INT UNSIGNED NOT NULL,
  sku_id                 INT UNSIGNED NOT NULL,
  units_per_item         SMALLINT UNSIGNED NOT NULL,
  valid_from             DATETIME(6)  NOT NULL,
  valid_to               DATETIME(6)  NULL COMMENT 'NULL = the current link',
  decision_id            BIGINT UNSIGNED NOT NULL COMMENT 'the decision that opened the period',
  closed_by_decision_id  BIGINT UNSIGNED NULL,
  open_listing_id        INT UNSIGNED GENERATED ALWAYS AS (IF(valid_to IS NULL, listing_id, NULL)) STORED COMMENT 'at most one open period per listing',
  PRIMARY KEY (id),
  UNIQUE KEY uq_listing_map_history_open (open_listing_id),
  KEY ix_listing_map_history_listing (listing_id, valid_from),
  KEY ix_listing_map_history_sku (sku_id, valid_from),
  KEY ix_listing_map_history_decision (decision_id),
  KEY ix_listing_map_history_closed_by (closed_by_decision_id),
  CONSTRAINT fk_listing_map_history_listing FOREIGN KEY (listing_id) REFERENCES channel_listing (id),
  CONSTRAINT fk_listing_map_history_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_listing_map_history_decision FOREIGN KEY (decision_id) REFERENCES match_decision (id),
  CONSTRAINT fk_listing_map_history_closed_by FOREIGN KEY (closed_by_decision_id) REFERENCES match_decision (id),
  CONSTRAINT ck_listing_map_history_units CHECK (units_per_item >= 1),
  CONSTRAINT ck_listing_map_history_period CHECK (valid_to IS NULL OR valid_to >= valid_from),
  CONSTRAINT ck_listing_map_history_closed CHECK ((valid_to IS NULL) = (closed_by_decision_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='link periods of each listing (app: SELECT, INSERT, UPDATE of valid_to/closed_by_decision_id)';

CREATE TABLE match_reject (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  listing_id   INT UNSIGNED NOT NULL,
  sku_id       INT UNSIGNED NOT NULL,
  decided_by   INT UNSIGNED NOT NULL,
  decision_id  BIGINT UNSIGNED NULL,
  `at`         DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_match_reject (listing_id, sku_id),
  KEY ix_match_reject_sku (sku_id),
  KEY ix_match_reject_decided_by (decided_by),
  KEY ix_match_reject_decision (decision_id),
  CONSTRAINT fk_match_reject_listing FOREIGN KEY (listing_id) REFERENCES channel_listing (id),
  CONSTRAINT fk_match_reject_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_match_reject_decided_by FOREIGN KEY (decided_by) REFERENCES staff_user (id),
  CONSTRAINT fk_match_reject_decision FOREIGN KEY (decision_id) REFERENCES match_decision (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='append-only blacklist: this listing is not this item (a later link to it needs a second person)';

CREATE TABLE alias (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind         ENUM('brand','line','flavour') NOT NULL,
  term         VARCHAR(128) NOT NULL,
  canonical    VARCHAR(128) NOT NULL,
  form_scope   VARCHAR(32)  NOT NULL DEFAULT '' COMMENT 'form class the alias holds for (design A.4); empty = any',
  status       ENUM('proposed','confirmed','rejected') NOT NULL DEFAULT 'proposed',
  evidence     JSON NULL COMMENT 'e.g. the GTIN pairs behind a line alias',
  proposed_by  VARCHAR(64)  NOT NULL COMMENT 'actor',
  decided_by   INT UNSIGNED NULL,
  decided_at   DATETIME(6)  NULL,
  created_at   DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at   DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_alias (kind, term, canonical, form_scope),
  KEY ix_alias_status (status, kind),
  KEY ix_alias_decided_by (decided_by),
  CONSTRAINT fk_alias_decided_by FOREIGN KEY (decided_by) REFERENCES staff_user (id),
  CONSTRAINT ck_alias_decided CHECK ((status = 'proposed') = (decided_by IS NULL) AND (decided_by IS NULL) = (decided_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='brand / line / flavour synonyms; only confirmed ones are used by the matcher (a mapping_lead confirms)';
