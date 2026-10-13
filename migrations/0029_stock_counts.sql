-- 0029_stock_counts.sql — pack A2a: stock counting, the Stock › Counts tab (the owner's list of 8 Oct 2026: "count plan every 4 months
-- (a setting), count sheets with scan / upload, variance review with a tolerance setting, counted_at rules"; the module plan IM2 and
-- its decision 6 "warn first"; docs/decisions.md CT1-CT18). Numbered 0029: 0028 is another branch's. Nothing here books stock, and the
-- count engine (src/Movements.php, src/Stock.php) is unchanged: a count sheet is a CNT document (0008) whose handler books through it.
--
--  * count_sheet: the header extension of a CNT document (as stock_op is of the stock records, 0022). Three kinds: a count SHEET; the
--    REVERSAL of a whole sheet (made by the document base when a posted sheet is cancelled); and a LINE_REVERSAL, the record that puts
--    back the count of ONE line of a sheet when its difference is rejected (it names the sheet and the line; one per line, a UNIQUE
--    key, so a line is never put back twice). The optional place inside the warehouse (0019: nothing requires one).
--  * count_sheet_line: one row per item on a sheet (an item is on a sheet once, a UNIQUE key: the scan box goes to "its line"). What
--    the system held when the line was put on the sheet; the quantity counted (NULL = not counted yet: nothing is booked for it; 0 is
--    a count of nothing), when it was counted and by whom; the "post it as counted" mark of a line whose stock moved after it was
--    counted (decision 6: the newest stock movement the person had seen); what the posting booked (the figure just before, the
--    difference, or "left out: a later count of the item exists"); and the check of a difference above the tolerance (open, accepted,
--    rejected, by whom, when, the note, the record that put the count back).
--    WHY the check is kept here and not in count_review: count_review has a foreign key to sku. A row with one may not be written
--    inside a posting before the stock locks (it would lock the item before its balance, the order Stock::setPolicy reverses: 0017's
--    incident table says the same), nor after them (nothing is locked after the feed clock, I21). A sheet's lines are locked by its
--    posting before the stock and changed in place; they exist before the posting, so the posting adds no row at all.
--  * Four settings (provisional: each is a build decision put to the owner, changed on Settings › System › Settings with a reason):
--    counts.plan_days 120 (the owner's "every 4 months"; 1 to 730: Settings::RULES gives it its own range, a day count is 0 to 120
--    otherwise); counts.sheet_shows_stock true (the module plan: each line shows stock now; off = a blind count);
--    counts.review_over_units 5 (0 = every difference) and counts.review_over_percent 10 (0 = the percent is not used): a posted line
--    whose difference is above either is checked by a person. Limits in UNITS until costs exist (IM2).
--  * document_type CNT: the reviewer check "only over 10 items" that 0008 seeded is switched OFF (review_rule none; the limit of 10 is
--    kept for when it is switched on). A build decision, put to the owner: no count could be posted before this pack (the type had no
--    handler), so no record loses a check it had; the owner's rule is that extra approvals start off; and today one person works on
--    staging, where a second-person rule would wait for ever. It is switched on again on Settings › Approval Rules, where it now
--    means: a count difference above that many units is cleared by a second person (docs/decisions.md CT8). The change is version N+1
--    of the rule's history (docs/dev.md rule 5), written only when the rule was not already off.
--  * Two indexes for the lists (staging: 14,856 items, a 32 MB buffer pool; every list read keeps to an index, docs/decisions.md CT13):
--    stock_balance (warehouse_id, counted_at, sku_id): "never counted" and "counted longest ago" of ONE warehouse, and how many items
--    are inside the plan, read from the index alone. A count is the only thing that moves an entry (Stock::setCountedAt); a sale, a
--    delivery or a reservation changes other columns and leaves it alone.
--    count_review (status, source, id): the open reviews of the two kinds the count engine opens (count_after_movements,
--    count_near_ship), newest first, without reading the open reviews of every other kind.
--    Both are added in place and online (ALGORITHM = INPLACE, LOCK = NONE: reads and writes go on while they build; not a rebuild:
--    tests/Integration/Migration0029Test.php holds the file to it, as 0027's test does).
-- Old code keeps working on the new schema: two new tables it does not know, two indexes, four setting rows, one rule switched off.
-- Applied together with the code of the same commit (install_cron.sh --migrate).

CREATE TABLE count_sheet (
  document_id           BIGINT UNSIGNED NOT NULL,
  kind                  ENUM('sheet','reversal','line_reversal') NOT NULL DEFAULT 'sheet'
                        COMMENT 'sheet: a count sheet; reversal: the cancellation of a whole sheet; line_reversal: the record that puts back the count of one line',
  location_id           INT UNSIGNED    NULL COMMENT 'optional place inside document.warehouse_id (nothing requires one)',
  reverses_document_id  BIGINT UNSIGNED NULL COMMENT 'a line_reversal: the sheet whose line it puts back',
  reverses_line_no      INT UNSIGNED    NULL COMMENT 'a line_reversal: that line (count_sheet_line.line_no)',
  created_at            DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at            DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (document_id),
  UNIQUE KEY uq_count_sheet_line_reversal (reverses_document_id, reverses_line_no),
  KEY ix_count_sheet_location (location_id),
  CONSTRAINT fk_count_sheet_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_count_sheet_location FOREIGN KEY (location_id) REFERENCES warehouse_location (id),
  CONSTRAINT fk_count_sheet_reverses FOREIGN KEY (reverses_document_id) REFERENCES document (id),
  CONSTRAINT ck_count_sheet_reverses CHECK ((kind = 'line_reversal') = (reverses_document_id IS NOT NULL)
      AND (reverses_document_id IS NULL) = (reverses_line_no IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='header extension of a stock count record CNT (pack A2a); never deleted';

CREATE TABLE count_sheet_line (
  document_id           BIGINT UNSIGNED NOT NULL,
  line_no               INT UNSIGNED    NOT NULL,
  sku_id                INT UNSIGNED    NOT NULL,
  expected_qty          INT             NOT NULL COMMENT 'on hand at the sheet''s warehouse when the line was put on the sheet',
  added_at              DATETIME(6)     NOT NULL,
  counted_qty           INT             NULL COMMENT 'NULL = not counted yet (nothing is booked for it); 0 = a count of nothing',
  counted_at            DATETIME(6)     NULL COMMENT 'when it was counted: the moment the quantity was entered, or the time an upload said',
  counted_by            INT UNSIGNED    NULL,
  entered_at            DATETIME(6)     NULL COMMENT 'when the quantity was saved',
  entered_how           ENUM('typed','upload') NULL,
  ack_ledger_id         BIGINT UNSIGNED NULL COMMENT 'warn first: the newest stock movement the person had seen when they chose to post the line as counted',
  ack_at                DATETIME(6)     NULL,
  doc_line_no           INT UNSIGNED    NULL COMMENT 'posting: its line of the document (document_line.line_no: only counted lines are posted)',
  system_qty            INT             NULL COMMENT 'posting: on hand just before the count was booked',
  booked_delta          INT             NULL COMMENT 'posting: what the count changed on hand by (NULL: left out)',
  outcome               ENUM('counted','stale') NULL COMMENT 'posting: counted = booked; stale = left out by the engine (a later count of the item exists)',
  review                ENUM('none','open','accepted','rejected') NOT NULL DEFAULT 'none' COMMENT 'the check of a difference above the tolerance',
  review_units          INT UNSIGNED    NULL COMMENT 'counts.review_over_units when the check was opened',
  review_percent        INT UNSIGNED    NULL COMMENT 'counts.review_over_percent when the check was opened',
  reviewed_by           INT UNSIGNED    NULL,
  reviewed_at           DATETIME(6)     NULL,
  review_note           VARCHAR(500)    NULL,
  reversal_document_id  BIGINT UNSIGNED NULL COMMENT 'the record that put this line''s count back (a rejected difference, or the cancellation of the whole sheet)',
  PRIMARY KEY (document_id, line_no),
  UNIQUE KEY uq_count_sheet_line_item (document_id, sku_id),
  KEY ix_count_sheet_line_review (review, document_id, line_no),
  KEY ix_count_sheet_line_sku (sku_id),
  KEY ix_count_sheet_line_counted_by (counted_by),
  KEY ix_count_sheet_line_reviewed_by (reviewed_by),
  KEY ix_count_sheet_line_reversal (reversal_document_id),
  CONSTRAINT fk_count_sheet_line_sheet FOREIGN KEY (document_id) REFERENCES count_sheet (document_id),
  CONSTRAINT fk_count_sheet_line_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_count_sheet_line_counted_by FOREIGN KEY (counted_by) REFERENCES staff_user (id),
  CONSTRAINT fk_count_sheet_line_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES staff_user (id),
  CONSTRAINT fk_count_sheet_line_reversal FOREIGN KEY (reversal_document_id) REFERENCES document (id),
  CONSTRAINT ck_count_sheet_line_no CHECK (line_no >= 1),
  CONSTRAINT ck_count_sheet_line_counted CHECK ((counted_qty IS NULL) = (counted_at IS NULL) AND (counted_qty IS NULL) = (entered_at IS NULL)
      AND (counted_qty IS NULL OR (counted_qty >= 0 AND counted_qty <= 10000000))),
  CONSTRAINT ck_count_sheet_line_ack CHECK ((ack_ledger_id IS NULL) = (ack_at IS NULL) AND (ack_ledger_id IS NULL OR counted_qty IS NOT NULL)),
  CONSTRAINT ck_count_sheet_line_outcome CHECK ((outcome IS NULL) = (doc_line_no IS NULL) AND (outcome IS NULL) = (system_qty IS NULL)
      AND (outcome IS NOT NULL AND outcome = 'counted') = (booked_delta IS NOT NULL) AND (outcome IS NULL OR counted_qty IS NOT NULL)),
  CONSTRAINT ck_count_sheet_line_review CHECK ((review IN ('accepted', 'rejected')) = (reviewed_at IS NOT NULL)
      AND (review = 'none' OR (outcome IS NOT NULL AND outcome = 'counted')) AND (review <> 'rejected' OR reversal_document_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the lines of a count sheet: counted quantities, what the posting booked, the check of a difference (pack A2a); never deleted';

ALTER TABLE stock_balance
  ADD INDEX ix_stock_balance_counted (warehouse_id, counted_at, sku_id),
  ALGORITHM = INPLACE, LOCK = NONE;

ALTER TABLE count_review
  ADD INDEX ix_count_review_open (status, source, id),
  ALGORITHM = INPLACE, LOCK = NONE;

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('counts.plan_days','int','120',1,'CT10','Every item should be counted at least once in this many days (the count plan); an item counted longer ago, or never, is overdue'),
 ('counts.sheet_shows_stock','bool','true',1,'CT4','A count sheet shows what the system holds beside each item; off: the counter does not see it (a blind count)'),
 ('counts.review_over_units','int','5',1,'CT6','A count difference of more than this many units is checked by a person after the count is made final (0: every difference)'),
 ('counts.review_over_percent','int','10',1,'CT6','A count difference of more than this percent of what the system held is checked by a person after the count is made final (0: the percent is not used)');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('counts.plan_days', 'counts.sheet_shows_stock', 'counts.review_over_units', 'counts.review_over_percent');

-- The reviewer check of a stock count: off (see the head of this file). The next version of the rule's history first, from the row as
-- it is (the tracked fields, as 0022 wrote ADJ's), only when the rule is not already off; then the row.
INSERT INTO config_change (subject_type, subject_key, version, action, state, before_state, reason, actor)
  SELECT 'document_rule', t.code, c.version + 1, 'change',
      JSON_OBJECT('review_rule', 'none', 'review_limit_units', t.review_limit_units, 'review_due_days', t.review_due_days,
          'approval_rule', CAST(t.approval_rule AS CHAR), 'approval_limit_units', t.approval_limit_units, 'reject_action', CAST(t.reject_action AS CHAR),
          'size_approval', t.size_approval, 'size_units', t.size_units, 'size_value', t.size_value),
      c.state,
      'Stock counts arrive (0029): the second-person check of a count starts switched off, as every extra approval does; its limit is kept',
      'system:migrate'
  FROM document_type t
  JOIN (SELECT subject_key, MAX(version) AS v FROM config_change WHERE subject_type = 'document_rule' GROUP BY subject_key) m ON m.subject_key = t.code
  JOIN config_change c ON c.subject_type = 'document_rule' AND c.subject_key = t.code AND c.version = m.v
  WHERE t.code = 'CNT' AND t.review_rule <> 'none';
UPDATE document_type SET review_rule = 'none' WHERE code = 'CNT' AND review_rule <> 'none';
