-- 0070_live_presence.sql — who is here on a draft order (the live drafts plan, step 3; the owner, 18 Nov 2026: "we need to show each other if
-- each others are in the same window as like in google sheets where we know which line they are pressed in"; docs/decisions.md LV1-LV12).
-- The server half: each open page of a draft order polls a short POST (CW\Live\Presence, Ui\Controller\LiveController) that says where its
-- person is and answers who else is here; a line is marked by its own id, which stays the same when other lines are added or removed.
--
--  * document_line.uid: a line's own id inside its document (never given twice in one document: UNIQUE (document_id, uid)). NULL: a line
--    written before this file or by a posting that copies lines (a reversal, a system job's copy), which is never a draft again.
--    Documents::setLines() carries the id a line comes with and gives a line without one the document's next free id.
--  * document.next_line_uid: the next free line id of the document (1 for a document without lines). The app login moves it
--    (Schema\Grants::UPDATE_COLUMNS['document']). Neither column is in the posting fingerprint (Documents::lineRow, posted_hash).
--  * The lines of every draft and of every document waiting for its OK (it may come back as a draft) get uid = line_no, and the document
--    next_line_uid = its last line number + 1. A final, reversed or cancelled document's lines keep none: posted content is never rewritten.
--  * draft_presence: one row per open page (tab) of a draft: who, their session (staff_session.id, the sha256 of the cookie token, never the
--    token: a row is shown only while its session has not ended, and signing out removes the session's rows), the line and box they are
--    in, active or away, typing or not, whether they may change it, and when the database last wrote the row. No foreign keys (a heartbeat
--    never fails on one); the app login's full rights (rows are written in place and removed: a closed page, a sign-out, the nightly
--    clean-up of rows older than a day, Ops\Housekeeping). A poll reads a draft's recent rows by (kind, record_id, seen_at), newest first,
--    at most a hundred.
--  * Four settings (Settings > Working together), provisional as every new one, each with its baseline: live.mode "off" (nothing is shown
--    and nothing polls on the day of the deploy: the owner switches it to "presence"), live.poll_seconds 3, live.alone_seconds 10,
--    live.gone_seconds 25. The code reads them with the same defaults, so it may run a moment before this file.
-- The old code runs on this schema: it names the columns it writes (a line without an id, a document with next_line_uid 1).
--
-- HOW IT IS APPLIED: the two ALTERs first (if the server refuses one, nothing of this file is applied), each in place and online
-- (ALGORITHM = INPLACE, LOCK = NONE: reads and writes of lines and documents go on while they run; one rebuild of each table, no copy),
-- each only while its column is not there (a prepared statement, as 0056, 0061, 0067), so a run stopped anywhere (the file is not recorded
-- until its last statement) runs again cleanly. Then the table (IF NOT EXISTS); then the backfill, only for documents whose lines have no
-- id at all, never moving an id or the next free id backwards (one statement each; a statement stopped half way is undone whole); then the
-- settings (a second run changes none) and their baselines (guarded by NOT EXISTS). Nothing is deleted.

SET @cw_0070_line = (SELECT CASE
  WHEN NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_line' AND COLUMN_NAME = 'uid') THEN
    'ALTER TABLE document_line ADD COLUMN uid INT UNSIGNED NULL COMMENT ''the line''''s own id in its document, never given twice (0070, Documents::setLines); NULL: written before 0070 or a copied posted line'', ADD UNIQUE KEY uq_document_line_uid (document_id, uid), ALGORITHM = INPLACE, LOCK = NONE'
  ELSE 'DO 0' END);
PREPARE cw_0070 FROM @cw_0070_line;
EXECUTE cw_0070;
DEALLOCATE PREPARE cw_0070;

SET @cw_0070_doc = (SELECT CASE
  WHEN NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document' AND COLUMN_NAME = 'next_line_uid') THEN
    'ALTER TABLE document ADD COLUMN next_line_uid INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''the next free line id (document_line.uid) of the document (0070)'', ALGORITHM = INPLACE, LOCK = NONE'
  ELSE 'DO 0' END);
PREPARE cw_0070 FROM @cw_0070_doc;
EXECUTE cw_0070;
DEALLOCATE PREPARE cw_0070;

CREATE TABLE IF NOT EXISTS draft_presence (
  kind            VARCHAR(8)       NOT NULL COMMENT 'the page: po (a purchase order) now (Live\\Presence::KINDS)',
  record_id       BIGINT UNSIGNED  NOT NULL COMMENT 'the document',
  tab             CHAR(16)         NOT NULL COMMENT 'one open page: random per page load',
  staff_user_id   INT UNSIGNED     NOT NULL,
  session_id      CHAR(64)         NOT NULL COMMENT 'staff_session.id (never the token): shown only while that session has not ended; signing out removes them',
  focus_uid       INT UNSIGNED     NULL COMMENT 'the line (document_line.uid) the person is in; NULL: none',
  focus_field     VARCHAR(24)      NULL COMMENT 'the box: qty, price, ... or a header box h:note, ...; NULL: none',
  state           ENUM('active','away') NOT NULL,
  typing          TINYINT(1)       NOT NULL DEFAULT 0 COMMENT '1: the box holds typing not saved yet',
  edits           TINYINT(1)       NOT NULL DEFAULT 0 COMMENT '1: the person may change the draft (doc.<TYPE>.post, not admin) as of their last poll',
  seen_at         DATETIME(6)      NOT NULL COMMENT 'the database clock when the row was last written',
  PRIMARY KEY (kind, record_id, tab),
  KEY ix_draft_presence_record_seen (kind, record_id, seen_at),
  KEY ix_draft_presence_seen (seen_at),
  KEY ix_draft_presence_session (session_id),
  CONSTRAINT ck_draft_presence_tab CHECK (REGEXP_LIKE(tab, '^[0-9a-f]{16}$', 'c') AND REGEXP_LIKE(session_id, '^[0-9a-f]{64}$', 'c')),
  CONSTRAINT ck_draft_presence_flags CHECK (typing IN (0, 1) AND edits IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='who has a draft open, and where (0070, Live\\Presence); no foreign keys: a heartbeat never fails on one';

-- The lines of drafts and of documents waiting for their OK, only where no line of the document has an id yet.
UPDATE document_line l
  JOIN (SELECT l2.document_id FROM document_line l2 JOIN document d2 ON d2.id = l2.document_id
         WHERE d2.status IN ('draft', 'awaiting_approval') GROUP BY l2.document_id HAVING COUNT(l2.uid) = 0) z ON z.document_id = l.document_id
  SET l.uid = l.line_no;

-- Their next free id: past the highest id, never backwards.
UPDATE document d
  JOIN (SELECT document_id, MAX(uid) AS top FROM document_line WHERE uid IS NOT NULL GROUP BY document_id) x ON x.document_id = d.id
  SET d.next_line_uid = x.top + 1
  WHERE d.status IN ('draft', 'awaiting_approval') AND d.next_line_uid <= x.top;

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('live.mode','string','"off"',1,'LV9','Who is here on a draft order: off (nothing shown, no page polls), presence (people see who else has the draft open and where), on (the same; saving each box by itself comes later)'),
 ('live.poll_seconds','int','3',1,'LV9','Seconds between two polls of an open draft while someone else has it open too (2 to 10)'),
 ('live.alone_seconds','int','10',1,'LV9','Seconds between two polls of an open draft while nobody else has it open (5 to 60)'),
 ('live.gone_seconds','int','25',1,'LV9','Seconds after which a person whose page stopped polling is no longer shown (25 to 300)')
 ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key IN ('live.mode', 'live.poll_seconds', 'live.alone_seconds', 'live.gone_seconds')
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);
