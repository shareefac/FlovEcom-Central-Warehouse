-- 0072_live_save.sql — each box of a draft order saves by itself (the live drafts plan, step 4; the owner, 19 Nov 2026: "each box saves by
-- itself when you leave it. The other person sees the change in about 3 seconds, with no Save button. If you both change the same line, the
-- second person picks which to keep"; docs/decisions.md LS1-LS14). The page sends one line (or one head box) at a time with the line's own
-- version it saw; a different line of someone else's is saved too, the same line changed meanwhile is refused for that line only and the page
-- asks which to keep.
--
--  * document_line.rev: the line's own version (1 for a new line), moved by one when the line's content changes (Documents::setLines keeps it
--    when a whole save leaves the line as it was; the in-place paths move it). changed_by / changed_at: who changed the line's content last
--    and when (the line's colour flash on the other screens; "Fazil just changed this line"). None of the three is in the posting fingerprint
--    (Documents::lineRow, posted_hash), as uid is not (0070). Existing lines: rev 1, nobody, no time.
--  * draft_change: what each person changed on a draft, line by line (set, add, remove, restore, a head box, the supplier, the lines replaced,
--    and the refused "same line at the same moment": op conflict, the measure of how often two people meet). The page's History card groups
--    them ("18 Nov, 14:02 · Fazil changed 3 lines", with each change in a fold); the other open pages read the newest ones in words. An add
--    and an undo carry the page's key (op_key): one sent twice (a retry after a lost answer) is done once. No foreign keys (a draft's own log;
--    the nightly clean-up removes the rows of a document that is no longer a draft after housekeeping.draft_change_keep_days); the app login's
--    full rights. The audit log stays the record of who changed the draft: one po.live_edit row per person per draft per hour (the detail is
--    here, prunable, not in the audit log that is kept seven years).
--  * One setting (Settings > Clean-up), provisional as every new one, with its baseline: housekeeping.draft_change_keep_days 90. The code reads
--    90 while the row is not there. live.mode's description says what "on" does now.
-- The old code runs on this schema: it names the columns it writes (a line without rev gets 1; nothing writes draft_change).
--
-- HOW IT IS APPLIED: the ALTER first (if the server refuses it, nothing of this file is applied), in place and online (ALGORITHM = INPLACE,
-- LOCK = NONE: reads and writes of lines go on while it runs; one rebuild of the table, no copy), only while its column is not there (a
-- prepared statement, as 0056, 0061, 0067, 0070), so a run stopped anywhere (the file is not recorded until its last statement) runs again
-- cleanly. Then the table (IF NOT EXISTS); then the setting (a second run changes none) and its baseline (guarded by NOT EXISTS); then
-- live.mode's words (only while they are the old ones). Nothing is deleted.

SET @cw_0072_line = (SELECT CASE
  WHEN NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_line' AND COLUMN_NAME = 'rev') THEN
    'ALTER TABLE document_line ADD COLUMN rev INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''the line''''s own version: moved when its content changes (0072, Documents::setLines, the in-place paths)'', ADD COLUMN changed_by INT UNSIGNED NULL COMMENT ''who changed the line''''s content last (0072); NULL: before 0072'', ADD COLUMN changed_at DATETIME(6) NULL COMMENT ''when the line''''s content changed last (0072)'', ALGORITHM = INPLACE, LOCK = NONE'
  ELSE 'DO 0' END);
PREPARE cw_0072 FROM @cw_0072_line;
EXECUTE cw_0072;
DEALLOCATE PREPARE cw_0072;

CREATE TABLE IF NOT EXISTS draft_change (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  kind            VARCHAR(8)       NOT NULL COMMENT 'the page: po (a purchase order) now (Live\\Presence::KINDS)',
  record_id       BIGINT UNSIGNED  NOT NULL COMMENT 'the document',
  staff_user_id   INT UNSIGNED     NOT NULL COMMENT 'who changed it',
  tab             CHAR(16)         NULL COMMENT 'the open page it came from (draft_presence.tab); NULL: a whole save',
  op              ENUM('set','add','remove','restore','header','supplier','replace','conflict') NOT NULL,
  line_uid        INT UNSIGNED     NULL COMMENT 'the line (document_line.uid)',
  field           VARCHAR(24)      NULL COMMENT 'a head box (expected_date, external_ref, note)',
  before_json     JSON             NULL COMMENT 'what it was: packs, price, items per pack, the product (a removed line: enough to put it back)',
  after_json      JSON             NULL COMMENT 'what it became',
  op_key          CHAR(16)         NULL COMMENT 'the page''s key of an add or an undo: sent twice, done once',
  doc_version     INT UNSIGNED     NOT NULL COMMENT 'the document''s version after the change',
  created_at      DATETIME(6)      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_draft_change_key (kind, record_id, op_key),
  KEY ix_draft_change_record (kind, record_id, id),
  KEY ix_draft_change_created (created_at),
  CONSTRAINT ck_draft_change_keys CHECK ((tab IS NULL OR REGEXP_LIKE(tab, '^[0-9a-f]{16}$', 'c')) AND (op_key IS NULL OR REGEXP_LIKE(op_key, '^[0-9a-f]{16}$', 'c')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='who changed what on a draft, line by line (0072, Live\\LiveChanges); no foreign keys; pruned after the draft is final';

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('housekeeping.draft_change_keep_days','int','90',1,'LS11','The line-by-line changes of a draft order are kept this many days after it is confirmed or cancelled (7 at the least)')
 ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key = 'housekeeping.draft_change_keep_days'
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);

UPDATE app_setting SET description = 'Working together on a draft order: off (nothing shown, no page polls), presence (people see who else has the draft open and where), on (the same, and each box saves by itself)'
  WHERE setting_key = 'live.mode'
    AND description = 'Who is here on a draft order: off (nothing shown, no page polls), presence (people see who else has the draft open and where), on (the same; saving each box by itself comes later)';
