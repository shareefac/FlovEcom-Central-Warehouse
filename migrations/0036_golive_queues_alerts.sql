-- 0036_golive_queues_alerts.sql — pack F: the CW-side pieces going live needs (go-live plan part 6: "The relay", "Staff queue screens
-- and their actions", "Alerts to a person", "Housekeeping on CW"; docs/decisions.md PF1-PF14). Numbered 0036: 0030-0035 are other
-- packs'. Online: three new tables and settings rows; no existing table is altered, nothing existing is rewritten.
--
--  * relay_line: one row per line of an ERPNext document a store relays to CW (plan §9: "key = document name + line index, never per
--    variant"), for the three stock kinds of POST /v1/movements (goods_in, supplier_return, erp_sale) and the two queue kinds
--    (erp_reconciliation: a "set to this number" correction; erp_mode: a selling-mode change). The UNIQUE key (channel, kind,
--    document, line) is claimed inside the call's transaction BEFORE any stock lock, so the same line sent again under another
--    Idempotency-Key (the store's queue re-sent after a restore, a worker that lost its answer) books nothing a second time and
--    answers what the first one did; two copies at the same moment queue on the key. line_hash is the canonical line: the same line
--    with other content answers `conflict` and books nothing. What became of the line (outcome, the item, the units, the queue row) is
--    written in the same transaction; the rest of a row never changes (Grants: append-only plus those columns). The idempotency rows
--    are pruned after 30 days or more; these are not: a document line is booked once, however late it is sent again.
--  * queue_note: a note a person adds to a row of one of the four queues (an oversold order: "customer phoned, sending Friday").
--    Append-only: a note is never changed or removed, so what was said and by whom stays readable.
--  * alert_state: one row per thing bin/health_alert.php reported (a store's late heartbeat, a deep queue, ...): when it was first and
--    last seen, when it was last sent to a person and how often, when it cleared, and when its "back to normal" was sent (a cleared
--    alert that was sent waits until its "back to normal" is taken: review finding 8 of 14 Oct 2026). An alert is sent once per window
--    (alerts.repeat_minutes), and again when it comes back after it cleared.
--  * Settings (provisional build decisions, each changed on Settings › System › Settings with a reason): where alerts go (a web
--    address and an e-mail address, both EMPTY: nothing is sent until someone fills one in), the window, one limit per check, the
--    answer-time probe, the retention of the pruned tables (idempotency 30 days, the plan's minimum, D14; heartbeats 30 days, D18;
--    the audit log 7 years: the plan keeps records "year end + 6 years", so 7 is the floor the setting allows), the size of a
--    pruning chunk and the time a pruning run may take; and the relay's rules: whether ERPNext's corrected figure is the figure for
--    sale (the units kept for paid orders are added to get the shelf), and the plan's map of a selling mode to a stock rule (§9).
--  * No new index: the four queue lists read (status, created_at) and count_review (status, source, id) (0001, 0029); the latest
--    heartbeat per store reads (channel_id, id); pruning reads ix_idempotency_created, ix_channel_health_latest and ix_audit_created.

CREATE TABLE relay_line (
  id           BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  channel_id   SMALLINT UNSIGNED NOT NULL,
  kind         VARCHAR(32)       NOT NULL COMMENT 'goods_in, supplier_return, erp_sale, erp_reconciliation, erp_mode',
  doc_ref      VARCHAR(191)      NOT NULL COMMENT 'the ERPNext document name (or the store''s message id for a mode change)',
  line_index   INT UNSIGNED      NOT NULL,
  line_hash    CHAR(64)          NOT NULL COMMENT 'sha256 of the canonical line: the same line with other content is a conflict',
  outcome      ENUM('claimed','booked','suspense','queued','no_change','skipped') NOT NULL DEFAULT 'claimed',
  sku_id       INT UNSIGNED      NULL,
  units        INT               NULL COMMENT 'central units booked (signed), or proposed for a correction',
  queue_id     BIGINT UNSIGNED   NULL COMMENT 'the goods_in_suspense, count_review or policy_review row',
  reason       VARCHAR(64)       NULL,
  idem_key     VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  created_at   DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_relay_line (channel_id, kind, doc_ref, line_index),
  KEY ix_relay_line_created (created_at),
  CONSTRAINT fk_relay_line_channel FOREIGN KEY (channel_id) REFERENCES channel (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='one row per relayed ERPNext document line: booked or queued once (pack F, PF1); never deleted';

CREATE TABLE queue_note (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue_name     ENUM('goods_in_suspense','count_review','policy_review','oversell_event') NOT NULL,
  row_id         BIGINT UNSIGNED NOT NULL,
  staff_user_id  INT UNSIGNED    NULL,
  actor          VARCHAR(64)     NOT NULL,
  note           VARCHAR(500)    NOT NULL,
  created_at     DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY ix_queue_note_row (queue_name, row_id, id),
  KEY ix_queue_note_staff (staff_user_id),
  CONSTRAINT fk_queue_note_staff FOREIGN KEY (staff_user_id) REFERENCES staff_user (id),
  CONSTRAINT ck_queue_note_text CHECK (CHAR_LENGTH(note) >= 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a person''s note on a row of the stores'' queues (pack F, PF9); append-only';

CREATE TABLE alert_state (
  alert_key      VARCHAR(191)      NOT NULL COMMENT '<check>:<store code>, or <check> for the whole system',
  kind           VARCHAR(32)       NOT NULL,
  channel_id     SMALLINT UNSIGNED NULL,
  detail         VARCHAR(1000)     NOT NULL,
  first_seen_at  DATETIME(6)       NOT NULL,
  last_seen_at   DATETIME(6)       NOT NULL,
  last_sent_at   DATETIME(6)       NULL,
  sends          INT UNSIGNED      NOT NULL DEFAULT 0,
  cleared_at     DATETIME(6)       NULL,
  clear_sent_at  DATETIME(6)       NULL COMMENT 'when its "back to normal" was sent; NULL while one is owed (cleared after it was sent)',
  PRIMARY KEY (alert_key),
  KEY ix_alert_state_open (cleared_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='what bin/health_alert.php reported and when it was sent to a person (pack F, PF10)';

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('alerts.webhook_url','string','""',1,'PF10','The web address the alerts are posted to (a chat channel''s incoming address); empty: no alert is posted'),
 ('alerts.email_to','string','""',1,'PF10','The e-mail address the alerts are sent to; empty: no alert is e-mailed'),
 ('alerts.repeat_minutes','int','60',1,'PF10','The same alert is sent again at most once in this many minutes while it lasts'),
 ('alerts.heartbeat_late_seconds','int','180',1,'PF10','A store in Watching only or Live that has not reported for this many seconds is late (it reports every minute)'),
 ('alerts.outbox_max_depth','int','500',1,'PF10','A store''s queue of messages waiting for CW is too deep above this many'),
 ('alerts.outbox_max_age_seconds','int','300',1,'PF10','A store''s oldest message waiting for CW is too old above this many seconds'),
 ('alerts.dead_letters_max','int','0',1,'PF10','A store''s messages that failed for good: an alert above this many'),
 ('alerts.feed_lag_max_seconds','int','60',1,'PF10','A store that is this many seconds or more behind CW''s stock changes is late with them'),
 ('alerts.answer_max_ms','int','1500',1,'PF10','CW''s own answer time (a connection and a read, measured on CW''s server): an alert above this many milliseconds'),
 ('alerts.answer_probes','int','3',1,'PF10','How many times the answer time is measured in one run (the middle value counts)'),
 ('alerts.invariants_max_age_hours','int','36',1,'PF10','The nightly safety check: an alert when the newest one failed, or when none finished for this many hours'),
 ('alerts.send_timeout_seconds','int','5',1,'PF10','How long sending one alert to the web address may take'),
 ('housekeeping.idempotency_keep_days','int','30',1,'PF11','The stored answers of the stores'' calls are kept this many days (30 at the least: a store keeps its messages 14 days)'),
 ('housekeeping.channel_health_keep_days','int','30',1,'PF11','The stores'' heartbeats are kept this many days (each store''s newest one is always kept)'),
 ('housekeeping.audit_keep_years','int','7',1,'PF11','The audit log is kept this many years (7 at the least)'),
 ('housekeeping.chunk_rows','int','1000',1,'PF11','Old rows are removed in chunks of this many'),
 ('housekeeping.max_seconds','int','300',1,'PF11','One clean-up run stops after this many seconds and goes on the next night'),
 ('relay.correction_is_for_sale','bool','true',1,'PF7','An ERPNext correction sets the figure the website may sell; on: the units kept for paid orders not sent yet are added to get the shelf figure'),
 ('relay.mode_in_stock','string','"backorder"',1,'PF3','The stock rule an ERPNext change to In-Stock proposes for a protected item'),
 ('relay.mode_from_warehouse','string','"strict"',1,'PF3','The stock rule an ERPNext change to From-Warehouse proposes for a protected item'),
 ('relay.mode_out_of_stock','string','"stopped"',1,'PF3','The stock rule an ERPNext change to Out-Of-Stock proposes for a protected item');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('alerts.webhook_url', 'alerts.email_to', 'alerts.repeat_minutes', 'alerts.heartbeat_late_seconds',
    'alerts.outbox_max_depth', 'alerts.outbox_max_age_seconds', 'alerts.dead_letters_max', 'alerts.feed_lag_max_seconds', 'alerts.answer_max_ms',
    'alerts.answer_probes', 'alerts.invariants_max_age_hours', 'alerts.send_timeout_seconds', 'housekeeping.idempotency_keep_days',
    'housekeeping.channel_health_keep_days', 'housekeeping.audit_keep_years', 'housekeeping.chunk_rows', 'housekeeping.max_seconds',
    'relay.correction_is_for_sale', 'relay.mode_in_stock', 'relay.mode_from_warehouse', 'relay.mode_out_of_stock');
