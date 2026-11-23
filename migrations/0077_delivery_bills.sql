-- 0077_delivery_bills.sql — delivery cost in CW profit, step 2 part 1: the carriers' bills, DPD first (docs/decisions.md DL1-DL24; the
-- owner, 23 Nov 2026: build DPD first, take it live, Royal Mail and the rest later). It only ADDS: no existing row changes.
--
--  * carrier_invoice: one row per carrier bill read from its file (DPD's weekly CSV): the carrier, its account and number as printed, the
--    days its lines cover, the shop the account bills, the net / VAT / gross as printed (the lines add up to them exactly, or the file was
--    refused), the file's sha256 (the file itself is NOT kept: it holds customer addresses; the owner keeps DPD's original), who uploaded it
--    and when, and its status: live (counts), superseded (a corrected file of the same bill replaced it) or undone ("Undo this upload").
--    A bill is never deleted (Grants: SELECT, INSERT, and UPDATE of its status columns only). At most one LIVE bill per carrier, account
--    and number (uq_carrier_invoice_live on a generated key that is NULL for the others).
--  * carrier_invoice_line: its lines, only the fields the matching and the screens need (date, consignment, parcel numbers, product,
--    service, weight, items, the order number the label carried when it is digits, VAT code, DPD's # and * marks, the money columns that
--    are not 0, the cost ex VAT signed): no name, address, postcode or free-text reference is ever kept. Each line is in exactly one state
--    (matched to a CW parcel, a collection on an order, before CW start, not matched, ambiguous) with the parcel or order it points at;
--    a line is never deleted and its content never changes (Grants: UPDATE of the match columns only: a line not matched yet is matched
--    later, when its parcel reaches CW).
--  * profit_day gets two figures (ADD COLUMN, ALGORITHM = INSTANT: no copy, no lock beyond a moment's metadata lock): delivery_cost (the
--    live bill lines on the paid orders counted that day, by the order's paid day) and delivery_other (the live bill lines on no order:
--    not matched or ambiguous, by their own date, in the shop the account bills).
--  * staff_role.role gets `delivery_bills` at the END of its list (the stored number of every grant stays what it was); the two new
--    permissions delivery.view (Finance and the new job) and delivery.upload (the new job) are rows of the stored map, each with its
--    baseline; nobody holds the new job until the owner says who (bin/grant_role.php or Settings > Users).
--
-- HOW IT IS APPLIED: two CREATE TABLE IF NOT EXISTS (new, empty), two columns added to profit_day only while they are not there (a prepared
-- statement, as 0074 added its index), the two permission rows ON DUPLICATE KEY with their baselines only when missing, and ONE ALTER of
-- staff_role (tiny; ALGORITHM = COPY, LOCK = SHARED, as 0069 and 0074). Every statement can run again and changes nothing the second time.
-- Rollback: the release before (b54163a) with this file added byte for byte (RollbackReleaseTest); the new tables, the two columns and the
-- rows stay, unused (the release before never reads them; its rebuild of a day writes the columns it knows and leaves these at 0).

CREATE TABLE IF NOT EXISTS carrier_invoice (
  id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  carrier         VARCHAR(16)       NOT NULL COMMENT 'dpd (royal_mail later)',
  account         VARCHAR(32)       NOT NULL COMMENT 'the carrier account the bill is for, as printed (DPD: Account No)',
  invoice_no      VARCHAR(32)       NOT NULL COMMENT 'the bill''s number, as printed (DPD: Invoice No)',
  invoice_date    DATE              NULL COMMENT 'the bill''s date when the file prints one',
  period_from     DATE              NOT NULL COMMENT 'the first line date',
  period_to       DATE              NOT NULL COMMENT 'the last line date',
  channel_id      SMALLINT UNSIGNED NOT NULL COMMENT 'the shop the account bills: its lines on no order count in this shop, and its start is this shop''s first reservation',
  net_pence       BIGINT            NOT NULL COMMENT 'as printed (ex VAT): the lines add up to it exactly',
  vat_pence       BIGINT            NOT NULL COMMENT 'as printed',
  gross_pence     BIGINT            NOT NULL COMMENT 'as printed: net + VAT',
  line_count      INT UNSIGNED      NOT NULL,
  file_sha256     CHAR(64)          NOT NULL COMMENT 'the fingerprint of the file read (the file itself is not kept)',
  status          ENUM('live','superseded','undone') NOT NULL DEFAULT 'live' COMMENT 'live counts; superseded: a corrected file replaced it; undone: Undo this upload',
  supersedes_id   BIGINT UNSIGNED   NULL COMMENT 'the bill this corrected file replaced',
  uploaded_by     INT UNSIGNED      NULL,
  uploaded_actor  VARCHAR(64)       NOT NULL,
  uploaded_at     DATETIME(6)       NOT NULL,
  ended_by        INT UNSIGNED      NULL COMMENT 'who replaced or undid it',
  ended_actor     VARCHAR(64)       NULL,
  ended_at        DATETIME(6)       NULL COMMENT 'when it stopped counting (replaced or undone)',
  live_key        VARCHAR(96) GENERATED ALWAYS AS (IF(status = 'live', CONCAT(carrier, ':', account, ':', invoice_no), NULL)) STORED
                  COMMENT 'carrier:account:number while live, else NULL: one live bill per key',
  PRIMARY KEY (id),
  UNIQUE KEY uq_carrier_invoice_live (live_key),
  KEY ix_carrier_invoice_key (carrier, account, invoice_no),
  KEY ix_carrier_invoice_list (uploaded_at, id),
  KEY ix_carrier_invoice_sha (file_sha256),
  KEY ix_carrier_invoice_channel (channel_id, status),
  KEY ix_carrier_invoice_supersedes (supersedes_id),
  KEY ix_carrier_invoice_by (uploaded_by),
  KEY ix_carrier_invoice_ended_by (ended_by),
  CONSTRAINT fk_carrier_invoice_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT fk_carrier_invoice_supersedes FOREIGN KEY (supersedes_id) REFERENCES carrier_invoice (id),
  CONSTRAINT fk_carrier_invoice_by FOREIGN KEY (uploaded_by) REFERENCES staff_user (id),
  CONSTRAINT fk_carrier_invoice_ended_by FOREIGN KEY (ended_by) REFERENCES staff_user (id),
  CONSTRAINT ck_carrier_invoice_carrier CHECK (carrier IN ('dpd', 'royal_mail')),
  CONSTRAINT ck_carrier_invoice_key CHECK (REGEXP_LIKE(account, '^[A-Za-z0-9-]{1,32}$', 'c') AND REGEXP_LIKE(invoice_no, '^[A-Za-z0-9-]{1,32}$', 'c')),
  CONSTRAINT ck_carrier_invoice_sha CHECK (REGEXP_LIKE(file_sha256, '^[0-9a-f]{64}$', 'c')),
  CONSTRAINT ck_carrier_invoice_period CHECK (period_from <= period_to),
  CONSTRAINT ck_carrier_invoice_gross CHECK (gross_pence = net_pence + vat_pence),
  CONSTRAINT ck_carrier_invoice_ended CHECK ((status = 'live') = (ended_at IS NULL) AND (ended_at IS NULL) = (ended_actor IS NULL)
    AND (ended_at IS NOT NULL OR ended_by IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a carrier bill read from its file (DPD first; docs/decisions.md DL1): never deleted, replaced or undone by its status';

CREATE TABLE IF NOT EXISTS carrier_invoice_line (
  invoice_id     BIGINT UNSIGNED   NOT NULL,
  line_no        INT UNSIGNED      NOT NULL COMMENT 'its place among the bill''s lines (1 = the first line after the column names)',
  line_date      DATE              NOT NULL,
  consignment    VARCHAR(20)       NULL COMMENT 'digits only (DPD''s /0 and its marks taken off): the tracking number of the parcel; NULL: none',
  parcel_no      VARCHAR(20)       NULL COMMENT 'digits only',
  header_parcel  VARCHAR(20)       NULL COMMENT 'the first parcel of a consignment of several: digits only',
  product_code   VARCHAR(8)        NOT NULL DEFAULT '',
  product        VARCHAR(64)       NOT NULL DEFAULT '' COMMENT 'DPD''s product name (EXPRESSPAK 1, PARCEL)',
  service_code   VARCHAR(8)        NOT NULL DEFAULT '',
  service        VARCHAR(64)       NOT NULL DEFAULT '' COMMENT 'DPD''s service name (NEXT DAY, SHIP2SHOP)',
  weight         DECIMAL(9,3)      NULL COMMENT 'DPD''s weight in kg',
  items          SMALLINT UNSIGNED NULL,
  senders_ref    VARCHAR(12)       NULL COMMENT 'the order number the label carried: digits only, anything else not kept',
  vat_code       CHAR(1)           NOT NULL COMMENT 'S standard, Z zero',
  marks          VARCHAR(2)        NOT NULL DEFAULT '' COMMENT '# and * DPD prints after the consignment (meaning not known): kept as flags',
  money          JSON              NOT NULL COMMENT 'the named money columns that are not 0, in pence: {"Revenue": 349, ...}',
  cost           BIGINT            NOT NULL COMMENT 'pence ex VAT, signed (a credit is below 0): the sum of money',
  match_state    ENUM('matched','collection','before_start','not_matched','ambiguous') NOT NULL
                 COMMENT 'matched: a CW parcel; collection: a return collected for an order; before_start: before the shop''s first order in CW (not in the profit); not_matched, ambiguous: in the profit as charges not on an order',
  channel_id     SMALLINT UNSIGNED NULL COMMENT 'matched, collection: the order''s shop',
  order_ref      VARCHAR(64)       NULL COMMENT 'matched: the parcel''s order; collection: the order',
  parcel_ref     VARCHAR(64)       NULL COMMENT 'matched: the parcel',
  candidates     TINYINT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'ambiguous: how many parcels or orders fit (2 or more)',
  matched_at     DATETIME(6)       NULL COMMENT 'when it was put on its parcel or order (never moved to another one after)',
  PRIMARY KEY (invoice_id, line_no),
  KEY ix_carrier_invoice_line_order (channel_id, order_ref, parcel_ref),
  KEY ix_carrier_invoice_line_state (match_state, line_date),
  KEY ix_carrier_invoice_line_consignment (consignment),
  CONSTRAINT fk_carrier_invoice_line_invoice FOREIGN KEY (invoice_id) REFERENCES carrier_invoice (id),
  CONSTRAINT fk_carrier_invoice_line_order FOREIGN KEY (channel_id, order_ref) REFERENCES sale_order (channel_id, order_ref),
  CONSTRAINT fk_carrier_invoice_line_parcel FOREIGN KEY (channel_id, order_ref, parcel_ref) REFERENCES sale_parcel (channel_id, order_ref, parcel_ref),
  CONSTRAINT ck_carrier_invoice_line_state CHECK (
    (match_state = 'matched' AND channel_id IS NOT NULL AND order_ref IS NOT NULL AND parcel_ref IS NOT NULL AND matched_at IS NOT NULL AND candidates = 0)
    OR (match_state = 'collection' AND channel_id IS NOT NULL AND order_ref IS NOT NULL AND parcel_ref IS NULL AND matched_at IS NOT NULL AND candidates = 0)
    OR (match_state = 'ambiguous' AND channel_id IS NULL AND order_ref IS NULL AND parcel_ref IS NULL AND matched_at IS NULL AND candidates >= 2)
    OR (match_state IN ('before_start', 'not_matched') AND channel_id IS NULL AND order_ref IS NULL AND parcel_ref IS NULL AND matched_at IS NULL AND candidates = 0)),
  CONSTRAINT ck_carrier_invoice_line_marks CHECK (marks IN ('', '#', '*', '#*')),
  CONSTRAINT ck_carrier_invoice_line_shapes CHECK ((consignment IS NULL OR REGEXP_LIKE(consignment, '^[0-9]{1,20}$', 'c'))
    AND (parcel_no IS NULL OR REGEXP_LIKE(parcel_no, '^[0-9]{1,20}$', 'c')) AND (header_parcel IS NULL OR REGEXP_LIKE(header_parcel, '^[0-9]{1,20}$', 'c'))
    AND (senders_ref IS NULL OR REGEXP_LIKE(senders_ref, '^[0-9]{1,12}$', 'c')) AND REGEXP_LIKE(vat_code, '^[A-Z]$', 'c')
    AND JSON_TYPE(money) = 'OBJECT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the lines of a carrier bill (docs/decisions.md DL2): only the fields the matching needs, never an address; never deleted';

-- profit_day's two delivery figures, added only while they are not there (INSTANT: the table is not copied; a run again does nothing).
SET @cw_0077_profit_day = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'profit_day'
    AND column_name = 'delivery_cost') = 0,
  'ALTER TABLE profit_day ADD COLUMN delivery_cost BIGINT NOT NULL DEFAULT 0 COMMENT ''pence ex VAT: the live bill lines on the paid orders of the day (by the paid day of the order)'', ADD COLUMN delivery_other BIGINT NOT NULL DEFAULT 0 COMMENT ''pence ex VAT: the live bill lines on no order dated that day (not matched or ambiguous), in the shop the account bills'', ALGORITHM = INSTANT',
  'DO 0');
PREPARE cw_0077_profit_day FROM @cw_0077_profit_day;
EXECUTE cw_0077_profit_day;
DEALLOCATE PREPARE cw_0077_profit_day;

-- The two new permissions in the stored map with their baselines (as 0074 added profit.view): delivery.view for Finance and the new job,
-- delivery.upload for the new job. Run again: the rows as they are then (a holder given on the roles screen stays), no second baseline.
INSERT INTO role_permission (permission, roles, updated_actor) VALUES ('delivery.view', 'finance,delivery_bills', 'system:migrate'),
  ('delivery.upload', 'delivery_bills', 'system:migrate')
  ON DUPLICATE KEY UPDATE permission = permission;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'permission', p.permission, 1, 'baseline', JSON_OBJECT('roles', p.roles), 'system:migrate'
  FROM role_permission p WHERE p.permission IN ('delivery.view', 'delivery.upload')
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'permission' AND c.subject_key = p.permission);

ALTER TABLE staff_role MODIFY COLUMN role ENUM('viewer','mapper','mapping_lead','warehouse','manager','admin','buyer','purchasing_manager',
    'goods_in','purchasing_desk','stock_controller','reviewer','accountant','auditor','people_manager','finance','delivery_bills') NOT NULL,
  ALGORITHM = COPY, LOCK = SHARED;
