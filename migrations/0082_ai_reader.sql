-- 0082_ai_reader.sql — "Claude reads the upload", phase 1 (tracker CW-1182; the owner, CW chat 25 Nov 2026 ~17:25 UK: "you should focus on the
-- claude api connected on all upload places as soon as posssible"; docs/decisions.md AI1-AI17). Staff send a PDF or a photo where they upload
-- today (Profit > Delivery bills: a Royal Mail bill; Profit > Expenses: an invoice); CW sends it to the Claude API and fills the same check
-- page or form; CW's own checks still decide and a person always stores.
--
--  * ai_read: one row per document being read (a job): who, where, the kind, what the page needs back (context), the file's type, size and
--    sha256, its name in the spool folder while it is kept (NULL once deleted: a Royal Mail bill's right after it is read; an invoice's when
--    its expense's Save attaches it, AI15, or an hour after its page took the reading), when Save attached it (file_used_at: once), the
--    state (waiting, reading, done, failed, taken, expired), why it failed (a code and the field it names, never what a field holds), the
--    answer in CW's form until its person's page takes it (and never more than an hour). Rows go a day after they finish (the runner).
--  * ai_call: the log of every call to the API, WITHOUT content, its row written BEFORE the request (AI16: result `started`, counted at the
--    most the call may cost, max_millipence) and finished once after it: when, who, place, kind, the model asked and the one that answered,
--    the file's type and size, the tokens in and out, the cost in thousandths of a penny, the result (ok / refused / failed; crashed: the
--    reader stopped mid-call, counted at its most) and its reason code, the refusal's category, the API's request id, seconds; and what CW's
--    own checks made of it when its person took it (checked). Kept.
--  * ai_reader: ONE row, the reader's runs (AI17): when the newest began and ended, the last that ended well, the last that failed and why
--    (an error's class, or "did not end"), for bin/health_alert.php.
--  * 7 settings with their version 1 (baseline) in config_change (the Settings page, "Claude reads uploads"): ai.read_uploads (false: OFF
--    until a person switches it on), ai.anthropic_api_key ("": a SECRET, never shown back; the owner pastes it on the screen), ai.model
--    ("claude-opus-5-5"), ai.effort ("medium"), ai.monthly_limit_gbp ("30"), ai.price_input_gbp_per_mtok ("3.20") and
--    ai.price_output_gbp_per_mtok ("16.00") (the model's price list, $4 / $20 per million tokens, at $1 = £0.80).
--
-- HOW IT IS APPLIED: three new tables and fourteen rows; no existing table changes, no row changed, nothing deleted. A second run adds nothing
-- (the same tables, the same keys, the baselines once). It does not need 0080 (PayPal fees) or 0081 (CW-1180): the files touch nothing of each other
-- and may be applied in any order (bin/migrate.php applies whatever is pending, in name order).

CREATE TABLE IF NOT EXISTS ai_read (
  id            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  staff_user_id INT UNSIGNED      NOT NULL COMMENT 'the person it is read for: only they see it',
  place         VARCHAR(32)       NOT NULL COMMENT 'delivery_upload, delivery_replace, expense_new, expense_change',
  kind          VARCHAR(32)       NOT NULL COMMENT 'royal_mail_bill, expense_invoice',
  context       JSON              NOT NULL COMMENT 'what the page needs back: the account, the bill replaced, the expense and its version, what was typed',
  media_type    VARCHAR(32)       NOT NULL COMMENT 'sniffed from the bytes',
  file_bytes    INT UNSIGNED      NOT NULL,
  file_sha256   CHAR(64)          NOT NULL,
  spool_name    CHAR(32)          NULL COMMENT 'the file in the spool folder while it is kept; NULL: deleted',
  file_used_at  DATETIME(6)       NULL COMMENT 'when its expense''s Save attached the invoice Claude read (AI15: once)',
  state         ENUM('waiting','reading','done','failed','taken','expired') NOT NULL DEFAULT 'waiting',
  reason        VARCHAR(32)       NULL COMMENT 'ok, or why it failed (a code)',
  detail        VARCHAR(255)      NULL COMMENT 'the field a refusal names (never what it holds)',
  answer        MEDIUMTEXT        NULL COMMENT 'the answer in CW''s form until its person takes it (at most an hour)',
  created_at    DATETIME(6)       NOT NULL,
  started_at    DATETIME(6)       NULL,
  finished_at   DATETIME(6)       NULL,
  taken_at      DATETIME(6)       NULL,
  PRIMARY KEY (id),
  KEY ix_ai_read_state (state, id),
  KEY ix_ai_read_staff (staff_user_id),
  UNIQUE KEY uq_ai_read_spool (spool_name),
  CONSTRAINT fk_ai_read_staff FOREIGN KEY (staff_user_id) REFERENCES staff_user (id),
  CONSTRAINT ck_ai_read_row CHECK (file_bytes > 0 AND CHAR_LENGTH(file_sha256) = 64 AND (state <> 'done' OR answer IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='documents being read by Claude for a person (CW-1182, docs/decisions.md AI3, AI15): the file and the answer kept only until used';

CREATE TABLE IF NOT EXISTS ai_call (
  id               BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  read_id          BIGINT UNSIGNED   NULL COMMENT 'the ai_read job (its row goes after a day: no foreign key)',
  created_at       DATETIME(6)       NOT NULL,
  staff_user_id    INT UNSIGNED      NOT NULL,
  place            VARCHAR(32)       NOT NULL,
  kind             VARCHAR(32)       NOT NULL,
  model            VARCHAR(64)       NOT NULL COMMENT 'the model asked (setting ai.model)',
  served_model     VARCHAR(64)       NULL COMMENT 'the model that answered (another after a declined request: the server-side fallback)',
  media_type       VARCHAR(32)       NOT NULL,
  file_bytes       INT UNSIGNED      NOT NULL,
  input_tokens     INT UNSIGNED      NOT NULL DEFAULT 0,
  output_tokens    INT UNSIGNED      NOT NULL DEFAULT 0,
  cost_millipence  INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'thousandths of a penny, at the settings'' prices, rounded up (while started, and when not known: its most)',
  max_millipence   INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'the most the call may cost, counted before it was sent (AI16)',
  result           ENUM('started','ok','refused','failed','crashed') NOT NULL,
  reason           VARCHAR(32)       NOT NULL,
  refusal_category VARCHAR(32)       NULL,
  request_id       VARCHAR(64)       NULL COMMENT 'the API''s id of the request or message (for Anthropic''s support)',
  fallback         TINYINT(1)        NOT NULL DEFAULT 0,
  seconds          DECIMAL(7,2)      NOT NULL DEFAULT 0,
  checked          ENUM('passed','refused') NULL COMMENT 'what CW''s own checks made of the reading when its person took it',
  check_code       VARCHAR(32)       NULL,
  checked_at       DATETIME(6)       NULL,
  PRIMARY KEY (id),
  KEY ix_ai_call_at (created_at),
  KEY ix_ai_call_read (read_id),
  CONSTRAINT fk_ai_call_staff FOREIGN KEY (staff_user_id) REFERENCES staff_user (id),
  CONSTRAINT ck_ai_call_row CHECK (fallback IN (0, 1) AND seconds >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='every call to the Claude API, without content (CW-1182, docs/decisions.md AI9, AI16): written before the request, finished once';

CREATE TABLE IF NOT EXISTS ai_reader (
  id         TINYINT UNSIGNED  NOT NULL,
  started_at DATETIME(6)       NULL COMMENT 'when the newest run began',
  ended_at   DATETIME(6)       NULL COMMENT 'when it ended (NULL while it runs, or when it stopped without ending)',
  ok_at      DATETIME(6)       NULL COMMENT 'when a run last ended well',
  failed_at  DATETIME(6)       NULL COMMENT 'when a run last failed (an error, or found by the next run not to have ended)',
  error      VARCHAR(255)      NULL COMMENT 'that failure: the error''s class, or did not end (never a message)',
  PRIMARY KEY (id),
  CONSTRAINT ck_ai_reader_one CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the runs of bin/ai_read.php, one row (CW-1182, docs/decisions.md AI17): what bin/health_alert.php reads';

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('ai.read_uploads','bool','false',1,'AI7','Claude reads a PDF or a photo sent where staff upload (a Royal Mail bill, an expense''s invoice) and fills the same check page; off until switched on'),
 ('ai.anthropic_api_key','string','""',1,'AI7','The Anthropic API key CW uses for Claude (a secret: never shown back). Empty: Claude reads nothing'),
 ('ai.model','string','"claude-opus-5-5"',1,'AI7','The Claude model that reads the documents'),
 ('ai.effort','string','"medium"',1,'AI7','How hard Claude thinks while reading: low, medium, high, xhigh or max'),
 ('ai.monthly_limit_gbp','decimal','"30"',1,'AI8','The most Claude''s reading may cost in a UK month, in pounds; at it, no new reading starts that month'),
 ('ai.price_input_gbp_per_mtok','decimal','"3.20"',1,'AI8','The model''s price for a million tokens sent to it, in pounds'),
 ('ai.price_output_gbp_per_mtok','decimal','"16.00"',1,'AI8','The model''s price for a million tokens it writes (its thinking included), in pounds')
 ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key IN ('ai.read_uploads', 'ai.anthropic_api_key', 'ai.model', 'ai.effort', 'ai.monthly_limit_gbp',
    'ai.price_input_gbp_per_mtok', 'ai.price_output_gbp_per_mtok')
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);
