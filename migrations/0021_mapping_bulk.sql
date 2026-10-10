-- 0021_mapping_bulk.sql — bulk action and store-wise review on the matching screens (the owner's "product mapping improve, we
-- need bulk action and review option each store wise", 8 Oct 2026; docs/decisions.md M46-M53, U106-U112).
--
--  * match_decision.action gains two decisions of one listing that DecisionService (still the only writer of a link) makes:
--      unignore   an ignored website product goes back to its list (suggested when a suggestion is open, else not matched yet);
--      send_back  a website product's open suggestion is closed and the product waits for the next computer check (not matched).
--    Neither touches a link: an ignored or suggested product has none.
--  * mapping_batch / mapping_batch_row: one batch per bulk action on the screens (Products > Mapping lists and Store Products):
--    what was asked, by whom, for which store and list, and one row per ticked website product with what became of it (done,
--    sent to Second approval, or skipped with why). Every decision of a batch carries bulk_batch_id `screen:<batch id>`, so the
--    batch page lists the decisions as they are now. Append-only for the app login (Grants::APPEND_ONLY).
--  * app_setting: the bulk rules, set on the screens with a reason (Settings > System > Settings; the switch on Approval Rules):
--      mapping.bulk_confirm_bands        the match strengths whose ticked rows may be confirmed together (Strong, Likely)
--      mapping.bulk_max_rows             the most rows one bulk action takes (100)
--      approvals.mapping_bulk_second_ok  matches confirmed (or new products created) from a ticked list wait for a second matching
--                                        lead (off: the owner's "extra approvals off" choice; switching it off needs a Reviewer)
--    with version 1 (baseline) of each in config_change (docs/dev.md rule 5).
--  * match_proposal (listing_id, lane, band): the "By store" overview of Mapping > To review counts each store's website products by
--    the strength of their latest suggestion (merge suggestions left out) from this narrow index instead of the proposals' rows
--    (their evidence JSON); measured on cw_staging, 8 Oct 2026: a table scan of 3,234 proposals took 150-1,100 ms on the small cluster.
-- No data changes. The old code runs on this schema (it never names the new actions or tables).

ALTER TABLE match_decision
  MODIFY COLUMN action ENUM('link','unlink','new_item','ignore','reject','suggest','merge_skus','split','unignore','send_back') NOT NULL;

CREATE TABLE mapping_batch (
  id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  action         ENUM('link','new_item','reject','ignore','unignore','send_back') NOT NULL,
  source         ENUM('review','store') NOT NULL COMMENT 'review: a match-strength list; store: Store Products',
  channel_id     SMALLINT UNSIGNED NULL COMMENT 'the store the list showed; NULL = all stores',
  band           ENUM('Key','Check','New item','Can''t tell','Conflict','Manual') NULL COMMENT 'the list the rows were ticked on',
  rows_asked     SMALLINT UNSIGNED NOT NULL,
  reason         VARCHAR(500)      NULL COMMENT 'the note given for every row (ignore needs one)',
  staff_user_id  INT UNSIGNED      NOT NULL,
  actor          VARCHAR(64)       NOT NULL,
  created_at     DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY ix_mapping_batch_staff (staff_user_id, created_at),
  CONSTRAINT fk_mapping_batch_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT fk_mapping_batch_staff FOREIGN KEY (staff_user_id) REFERENCES staff_user (id),
  CONSTRAINT ck_mapping_batch_rows CHECK (rows_asked >= 1),
  CONSTRAINT ck_mapping_batch_reason CHECK (reason IS NULL OR CHAR_LENGTH(reason) >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='one bulk action of the matching screens (append-only for cw_app; M47)';

CREATE TABLE mapping_batch_row (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id          BIGINT UNSIGNED NOT NULL,
  seq               SMALLINT UNSIGNED NOT NULL COMMENT 'the order the rows were done in (listing id order)',
  listing_id        INT UNSIGNED    NOT NULL COMMENT 'no foreign key: a ticked id that names no listing is recorded as skipped',
  proposal_id       BIGINT UNSIGNED NULL COMMENT 'the suggestion the page showed',
  seen_map_version  INT UNSIGNED    NULL COMMENT 'the map_version the page showed',
  outcome           ENUM('done','pending_second','skipped') NOT NULL,
  skip_code         VARCHAR(64)     NULL COMMENT 'why a row was skipped (the words: BULK_SKIP)',
  detail            JSON            NULL COMMENT 'the vetoes, the reasons for a second OK, the state found',
  decision_id       BIGINT UNSIGNED NULL,
  created_at        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_mapping_batch_row_listing (batch_id, listing_id),
  UNIQUE KEY uq_mapping_batch_row_seq (batch_id, seq),
  KEY ix_mapping_batch_row_listing (listing_id),
  KEY ix_mapping_batch_row_decision (decision_id),
  CONSTRAINT fk_mapping_batch_row_batch FOREIGN KEY (batch_id) REFERENCES mapping_batch (id),
  CONSTRAINT fk_mapping_batch_row_decision FOREIGN KEY (decision_id) REFERENCES match_decision (id),
  CONSTRAINT ck_mapping_batch_row_outcome CHECK ((outcome = 'skipped') = (skip_code IS NOT NULL) AND (outcome = 'skipped') = (decision_id IS NULL)),
  CONSTRAINT ck_mapping_batch_row_detail CHECK (detail IS NULL OR JSON_TYPE(detail) = 'OBJECT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='what became of each ticked row of a bulk action (append-only for cw_app; written with its decision, M47)';

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('mapping.bulk_confirm_bands','string','"Key,Check"',1,'M46','Match strengths whose ticked rows may be confirmed together (Key = Strong, Check = Likely); the others stay one at a time'),
 ('mapping.bulk_max_rows','int','100',1,'M46','The most website products one bulk action on the matching screens takes'),
 ('approvals.mapping_bulk_second_ok','bool','false',1,'Q8','Matches confirmed, and new products created, from a ticked list wait for a second matching lead; off: they apply at once (the other two-person rules still apply)');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('mapping.bulk_confirm_bands', 'mapping.bulk_max_rows', 'approvals.mapping_bulk_second_ok');

ALTER TABLE match_proposal
  ADD INDEX ix_match_proposal_listing_lane (listing_id, lane, band),
  ALGORITHM = INPLACE, LOCK = NONE;
