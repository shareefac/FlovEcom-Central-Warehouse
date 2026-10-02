-- 0008_documents.sql — IM1: document base, reviews, number series, reason codes, file store (docs/decisions.md I17-I27).
-- No backfill: every table starts empty except the seeded reference lists (reason_code, document_type, number_series at 0).
-- Review fixes (I31-I36, before 0008 was applied anywhere): document.live_reverses_id (one live reversal, a cancelled
-- reversal request frees the slot), document_posting (the write-once anchor of posted_hash), stored_file.storage_key
-- indexed, document_file.retain_until (retention per attachment).
-- The format CHECKs use REGEXP_LIKE(..., 'c'): a plain REGEXP follows the column's case-insensitive collation
-- (utf8mb4_0900_ai_ci) and would let an upper-case hash or a lower-case type code through (I17).
-- Applied to cw_staging together with the code (install_cron.sh --migrate), after 0006 and 0007.

CREATE TABLE reason_code (
  code         VARCHAR(32)  NOT NULL,
  label        VARCHAR(100) NOT NULL,
  applies_to   SET('adjustment','write_off','count','return','supplier_return','reversal') NOT NULL,
  direction    ENUM('increase','decrease','either') NOT NULL DEFAULT 'either',
  needs_note   TINYINT(1) NOT NULL DEFAULT 0,
  is_gift      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'free gift to the public: reported apart (IM13); vaping/nicotine gifts are an offence from 29 Oct 2026',
  system_only  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'set by CW itself; never offered on a form',
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (code),
  CONSTRAINT ck_reason_code_code CHECK (REGEXP_LIKE(code, '^[a-z][a-z0-9_]{1,31}$', 'c')),
  CONSTRAINT ck_reason_code_flags CHECK (needs_note IN (0,1) AND is_gift IN (0,1) AND system_only IN (0,1) AND is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='reasons for adjustments, write-offs, counts, returns, reversals (provisional list, I22)';

-- I22: provisional until the I-0 reconciliation analysis (the +644k / -128k units of ERPNext reconciliations).
INSERT INTO reason_code (sort_order, code, label, applies_to, direction, needs_note, is_gift, system_only) VALUES
 (10,  'damaged',                    'Damaged',                                                   'adjustment,write_off,return,supplier_return', 'decrease', 0, 0, 0),
 (20,  'faulty',                     'Faulty',                                                    'write_off,return,supplier_return',            'decrease', 0, 0, 0),
 (30,  'expired',                    'Expired / out of date',                                     'write_off,supplier_return',                   'decrease', 0, 0, 0),
 (40,  'lost_theft',                 'Lost or stolen',                                            'adjustment,write_off',                        'decrease', 0, 0, 0),
 (50,  'found',                      'Found (no supplier document)',                              'adjustment',                                  'increase', 0, 0, 0),
 (60,  'wrong_item_booked',          'Wrong item booked',                                         'adjustment,count',                            'either',   0, 0, 0),
 (70,  'supplier_error',             'Supplier error (short, over, wrong item)',                  'adjustment,supplier_return',                  'either',   0, 0, 0),
 (80,  'supplier_collection',        'Collected by the supplier',                                 'write_off,supplier_return',                   'decrease', 0, 0, 0),
 (90,  'destroyed',                  'Destroyed (evidence kept)',                                 'write_off',                                   'decrease', 1, 0, 0),
 (100, 'free_gift',                  'Free gift (not vaping/nicotine products from 29 Oct 2026)', 'write_off',                                   'decrease', 0, 1, 0),
 (110, 'sample',                     'Sample (trade or staff)',                                   'write_off',                                   'decrease', 0, 0, 0),
 (120, 'unstamped_found',            'Unstamped stock found (quarantine)',                        'adjustment,count',                            'either',   1, 0, 0),
 (130, 'count_difference',           'Count difference',                                          'count',                                       'either',   0, 0, 0),
 (140, 'recount',                    'Recount after review',                                      'count',                                       'either',   0, 0, 0),
 (150, 'data_correction',            'Data correction',                                           'adjustment',                                  'either',   1, 0, 0),
 (160, 'customer_return_resaleable', 'Customer return, back on sale',                             'return',                                      'increase', 0, 0, 0),
 (170, 'customer_return_damaged',    'Customer return, damaged',                                  'return,write_off',                            'either',   0, 0, 0),
 (180, 'entered_in_error',           'Entered in error',                                          'reversal',                                    'either',   0, 0, 0),
 (190, 'duplicate',                  'Duplicate document',                                        'reversal',                                    'either',   0, 0, 0),
 (200, 'opening_rebase',             'Opening figure rebase (T0)',                                'adjustment',                                  'either',   0, 0, 1),
 (210, 'review_rejected',            'Rejected at review',                                        'reversal',                                    'either',   0, 0, 1),
 (999, 'other',                      'Other (note required)',      'adjustment,write_off,count,return,supplier_return,reversal',            'either',   1, 0, 0);

CREATE TABLE document_type (
  code                  VARCHAR(8)  NOT NULL,
  prefix                VARCHAR(8)  NOT NULL,
  name                  VARCHAR(64) NOT NULL,
  phase                 VARCHAR(8)  NOT NULL COMMENT 'build phase of its screens',
  review_rule           ENUM('none','all','over_limit') NOT NULL DEFAULT 'all',
  review_limit_units    INT UNSIGNED NULL,
  approval_rule         ENUM('none','positive_without_supplier_doc') NOT NULL DEFAULT 'none',
  approval_limit_units  INT UNSIGNED NULL,
  review_due_days       TINYINT UNSIGNED NOT NULL DEFAULT 3,
  PRIMARY KEY (code),
  UNIQUE KEY uq_document_type_prefix (prefix),
  CONSTRAINT ck_document_type_code CHECK (REGEXP_LIKE(code, '^[A-Z]{2,8}$', 'c') AND REGEXP_LIKE(prefix, '^[A-Z]{2,8}$', 'c')),
  CONSTRAINT ck_document_type_review CHECK (review_rule <> 'over_limit' OR review_limit_units IS NOT NULL),
  CONSTRAINT ck_document_type_approval CHECK (approval_rule = 'none' OR approval_limit_units IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='document kinds and their review rules (limits: placeholders until decision 11)';
INSERT INTO document_type (code, prefix, name, phase, review_rule, review_limit_units, approval_rule, approval_limit_units, review_due_days) VALUES
 ('PO','PO','Purchase order','I-2','none',NULL,'none',NULL,7),
 ('GRN','GRN','Goods received','I-3','all',NULL,'none',NULL,3),
 ('SINV','SINV','Supplier invoice','I-4','all',NULL,'none',NULL,3),
 ('DN','DN','Supplier return / debit note','I-4','all',NULL,'none',NULL,3),
 ('CNT','CNT','Stock count','I-4','over_limit',10,'none',NULL,3),
 ('ADJ','ADJ','Stock adjustment','I-4','all',NULL,'positive_without_supplier_doc',10,3),
 ('WO','WO','Write-off','I-4','over_limit',10,'none',NULL,3),
 ('TRD','TRD','Trade / inter-site issue','I-6','all',NULL,'none',NULL,3);

CREATE TABLE number_series (
  prefix   VARCHAR(8) NOT NULL,
  last_no  BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'last number issued; 1..last_no are all on posted documents (gapless)',
  pad      TINYINT UNSIGNED NOT NULL DEFAULT 6,
  PRIMARY KEY (prefix),
  CONSTRAINT fk_number_series_prefix FOREIGN KEY (prefix) REFERENCES document_type (prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='continuous per prefix; allocated inside the posting transaction (I20)';
INSERT INTO number_series (prefix) SELECT prefix FROM document_type;

CREATE TABLE document (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  doc_type       VARCHAR(8)   NOT NULL,
  number         VARCHAR(24)  NULL COMMENT 'PREFIX-000123, given at posting',
  status         ENUM('draft','awaiting_approval','posted','reversed','cancelled') NOT NULL DEFAULT 'draft',
  version        INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'moves on every change; forms carry it (409 when stale)',
  external_ref   VARCHAR(191) NULL COMMENT 'the other side''s reference: supplier invoice number, ERPNext number, ...',
  doc_date       DATE NULL,
  warehouse_id   SMALLINT UNSIGNED NULL,
  reason_code    VARCHAR(32)  NULL,
  note           VARCHAR(1000) NULL,
  created_by     INT UNSIGNED NULL,
  created_actor  VARCHAR(64)  NOT NULL,
  created_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT 'set by the code (no ON UPDATE: column grants)',
  submitted_by   INT UNSIGNED NULL,
  submitted_at   DATETIME(6)  NULL,
  posted_by      INT UNSIGNED NULL,
  posted_actor   VARCHAR(64)  NULL,
  posted_at      DATETIME(6)  NULL,
  posted_hash    CHAR(64)     NULL COMMENT 'sha256 of the canonical header + lines at posting (I17)',
  reverses_id    BIGINT UNSIGNED NULL,
  live_reverses_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'cancelled', NULL, reverses_id)) STORED
                 COMMENT 'reverses_id of a reversal that is not cancelled: one live reversal per document (I32)',
  cancelled_by   INT UNSIGNED NULL,
  cancelled_at   DATETIME(6)  NULL,
  cancel_reason  VARCHAR(500) NULL,
  review_state   ENUM('not_required','pending','approved','rejected') NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_document_number (number),
  UNIQUE KEY uq_document_reverses (live_reverses_id),
  KEY ix_document_reverses_id (reverses_id),
  KEY ix_document_type_status (doc_type, status, id),
  KEY ix_document_review (review_state, posted_at),
  KEY ix_document_external (external_ref),
  KEY ix_document_created_by (created_by), KEY ix_document_posted_by (posted_by), KEY ix_document_submitted_by (submitted_by),
  KEY ix_document_cancelled_by (cancelled_by), KEY ix_document_warehouse (warehouse_id), KEY ix_document_reason (reason_code),
  CONSTRAINT fk_document_type FOREIGN KEY (doc_type) REFERENCES document_type (code),
  CONSTRAINT fk_document_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouse (id),
  CONSTRAINT fk_document_reason FOREIGN KEY (reason_code) REFERENCES reason_code (code),
  CONSTRAINT fk_document_created_by FOREIGN KEY (created_by) REFERENCES staff_user (id),
  CONSTRAINT fk_document_submitted_by FOREIGN KEY (submitted_by) REFERENCES staff_user (id),
  CONSTRAINT fk_document_posted_by FOREIGN KEY (posted_by) REFERENCES staff_user (id),
  CONSTRAINT fk_document_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES staff_user (id),
  CONSTRAINT fk_document_reverses FOREIGN KEY (reverses_id) REFERENCES document (id),
  CONSTRAINT ck_document_number CHECK ((status IN ('posted','reversed')) = (number IS NOT NULL)),
  CONSTRAINT ck_document_posted CHECK (status NOT IN ('posted','reversed') OR (posted_at IS NOT NULL AND posted_actor IS NOT NULL
      AND posted_hash IS NOT NULL AND review_state IS NOT NULL)),
  CONSTRAINT ck_document_unposted CHECK (status IN ('posted','reversed') OR (posted_at IS NULL AND review_state IS NULL)),
  CONSTRAINT ck_document_cancelled CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL)),
  CONSTRAINT ck_document_submitted CHECK (status <> 'awaiting_approval' OR submitted_at IS NOT NULL),
  CONSTRAINT ck_document_hash CHECK (posted_hash IS NULL OR REGEXP_LIKE(posted_hash, '^[0-9a-f]{64}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='every document header (PO, GRN, SINV, DN, CNT, ADJ, WO, TRD); immutable once posted, corrected only by a reversal';

CREATE TABLE document_line (
  document_id   BIGINT UNSIGNED NOT NULL,
  line_no       INT UNSIGNED NOT NULL,
  sku_id        INT UNSIGNED NULL COMMENT 'NULL for lines without an item (freight, duty); no FK: reversals copy lines after the document lock (D15, I21)',
  warehouse_id  SMALLINT UNSIGNED NULL,
  qty           INT NULL COMMENT 'central units, signed as the document type means it',
  unit_cost     DECIMAL(14,6) NULL COMMENT 'GBP per central unit',
  amount        DECIMAL(18,6) NULL COMMENT 'GBP: non-item lines and totals',
  reason_code   VARCHAR(32)  NULL,
  description   VARCHAR(255) NULL,
  PRIMARY KEY (document_id, line_no),
  KEY ix_document_line_sku (sku_id),
  KEY ix_document_line_wh (warehouse_id),
  KEY ix_document_line_reason (reason_code),
  CONSTRAINT fk_document_line_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_document_line_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouse (id),
  CONSTRAINT fk_document_line_reason FOREIGN KEY (reason_code) REFERENCES reason_code (code),
  CONSTRAINT ck_document_line_no CHECK (line_no >= 1),
  CONSTRAINT ck_document_line_cost CHECK (unit_cost IS NULL OR unit_cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='generic lines; modules add extension tables keyed (document_id, line_no); edited only while the document is draft';

CREATE TABLE document_posting (
  document_id   BIGINT UNSIGNED NOT NULL,
  number        VARCHAR(24)  NOT NULL,
  posted_hash   CHAR(64)     NOT NULL,
  posted_by     INT UNSIGNED NULL,
  posted_actor  VARCHAR(64)  NOT NULL,
  posted_at     DATETIME(6)  NOT NULL,
  content       MEDIUMTEXT   NOT NULL COMMENT 'the canonical JSON posted_hash is the sha256 of (header fields and lines at posting): the posted content, kept',
  created_at    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (document_id),
  UNIQUE KEY uq_document_posting_number (number),
  CONSTRAINT fk_document_posting_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT ck_document_posting_hash CHECK (REGEXP_LIKE(posted_hash, '^[0-9a-f]{64}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='write-once record of every posting (append-only for the app login): D7 checks each posted document against it (I33)';

CREATE TABLE review_task (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject_type   ENUM('document','supplier') NOT NULL,
  subject_id     BIGINT UNSIGNED NOT NULL,
  kind           ENUM('review','approval') NOT NULL COMMENT 'review: after posting (post first); approval: blocking, before posting',
  reason         VARCHAR(64) NOT NULL COMMENT 'all_documents, over_limit, positive_without_supplier_doc, new_supplier',
  units          INT NULL COMMENT 'the figure compared with the limit',
  state          ENUM('open','approved','rejected','withdrawn') NOT NULL DEFAULT 'open',
  opened_by      INT UNSIGNED NULL COMMENT 'poster / requester; no FK: written after the stock locks (D15, I21)',
  opened_actor   VARCHAR(64) NOT NULL,
  opened_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  due_at         DATETIME(6) NOT NULL,
  decided_by     INT UNSIGNED NULL,
  decided_at     DATETIME(6) NULL,
  decision_note  VARCHAR(500) NULL,
  open_key       VARCHAR(48) GENERATED ALWAYS AS (IF(state = 'open', CONCAT(subject_type, ':', subject_id, ':', kind), NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_review_task_open (open_key),
  KEY ix_review_task_subject (subject_type, subject_id),
  KEY ix_review_task_state (state, kind, due_at),
  CONSTRAINT ck_review_task_decided CHECK ((state = 'open') = (decided_at IS NULL)),
  CONSTRAINT ck_review_task_decider CHECK (state IN ('open','withdrawn') OR decided_by IS NOT NULL),
  CONSTRAINT ck_review_task_not_own CHECK (decided_by IS NULL OR opened_by IS NULL OR decided_by <> opened_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='second-person reviews and blocking approvals';

CREATE TABLE stored_file (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sha256         CHAR(64)     NOT NULL,
  size_bytes     INT UNSIGNED NOT NULL,
  mime           VARCHAR(100) NOT NULL COMMENT 'sniffed from the content (finfo), never the client''s claim',
  original_name  VARCHAR(255) NOT NULL,
  kind           VARCHAR(32)  NOT NULL COMMENT 'supplier_invoice, delivery_note, packing_list, photo, duty_evidence, generated_pdf, other',
  note           VARCHAR(255) NULL,
  backend        VARCHAR(16)  NOT NULL COMMENT 'local (staging) | s3_object_lock (before live)',
  storage_key    VARCHAR(255) NOT NULL,
  retain_until   DATE         NOT NULL,
  stored_by      INT UNSIGNED NULL,
  stored_actor   VARCHAR(64)  NOT NULL,
  created_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_stored_file_sha256 (sha256),
  KEY ix_stored_file_kind (kind, created_at),
  KEY ix_stored_file_by (stored_by),
  KEY ix_stored_file_storage_key (storage_key),
  CONSTRAINT fk_stored_file_by FOREIGN KEY (stored_by) REFERENCES staff_user (id),
  CONSTRAINT ck_stored_file_sha CHECK (REGEXP_LIKE(sha256, '^[0-9a-f]{64}$', 'c')),
  CONSTRAINT ck_stored_file_size CHECK (size_bytes > 0),
  CONSTRAINT ck_stored_file_retain CHECK (retain_until >= DATE_ADD(DATE(created_at), INTERVAL 7 YEAR))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='content-addressed file store: one row per distinct content (append-only)';

CREATE TABLE document_file (
  document_id     BIGINT UNSIGNED NOT NULL,
  file_id         BIGINT UNSIGNED NOT NULL,
  role            VARCHAR(32)  NOT NULL COMMENT 'supplier_invoice, delivery_note, photo, evidence, generated_pdf',
  attached_by     INT UNSIGNED NULL,
  attached_actor  VARCHAR(64)  NOT NULL,
  attached_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  retain_until    DATE         NOT NULL COMMENT 'kept at least this long for this document: a later attachment extends the file''s retention (I36)',
  PRIMARY KEY (document_id, file_id, role),
  KEY ix_document_file_file (file_id),
  KEY ix_document_file_by (attached_by),
  CONSTRAINT fk_document_file_document FOREIGN KEY (document_id) REFERENCES document (id),
  CONSTRAINT fk_document_file_file FOREIGN KEY (file_id) REFERENCES stored_file (id),
  CONSTRAINT fk_document_file_by FOREIGN KEY (attached_by) REFERENCES staff_user (id),
  CONSTRAINT ck_document_file_retain CHECK (retain_until >= DATE_ADD(DATE(attached_at), INTERVAL 7 YEAR))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='files attached to documents: added, never removed (append-only)';
