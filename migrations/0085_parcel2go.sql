-- 0085_parcel2go.sql — CW-1160: Parcel2Go's labels in CW's delivery cost (docs/decisions.md PGL1-; design: cw_work/runs/P2G_STATE.md, PHASE A).
-- The owner, 26 Nov 2026: "start parcel2go"; Q1-Q3 answered (staff pay by card too: a refund to a card is not seen; a label with no CW order
-- goes on its account's main shop "not on an order" line; he creates the two API keys himself and pastes them into CW's write-only key page).
-- It only ADDS rows and tables, and widens two small CHECKs / one ENUM: no existing row changes.
--
--  * carrier gets family 'per_charge' (at the END of its ENUM: a bill per charge, each row's money put on a parcel by its tracking number)
--    and the row parcel2go. Not 'pooled' (Royal Mail's day book plans pooled accounts) and not 'per_parcel' (DPD's lines).
--  * carrier_account's format CHECK takes 'p2g_api' as a bill format and a label source (3 rows: ALGORITHM = COPY, LOCK = SHARED as 0078);
--    two rows, both OFF: Parcel2Go (Vape and Go), main party vapeandgo, key name p2g_vapeandgo; Parcel2Go (Vape Big), main party vapebig,
--    key name p2g_vapebig. Each serves Vape and Go, Vape Big and Electrofag from 2020-01-01 (24 Nov 2026: Vape Big's account shipped 85
--    Vape and Go parcels and 1 Electrofag parcel in November). A label's cost follows its tracking number to its own shop's order.
--  * p2g_line: one row per Parcel2Go order line ("P2G number") read from the API's recent list or the "My Orders" export: only what the
--    matching and the money need (never a name, an address, a postcode, an e-mail, a phone, the contents or any free text).
--  * p2g_parcel: the parcels of a line, their courier tracking numbers and the CW parcel a price was put on (kept: never moved after).
--  * p2g_charge: each row of a Parcel2Go bill (carrier_bill_row) and the line it is a charge of. A bill is ONE invoice of a checkout basket
--    (TX-<transaction id>: the owner's real sample of 26 Nov 2026), a row per label of it; an "Extra Payment" row is a later charge of an
--    EARLIER label, put where that label is.
--  * p2g_run: each run of bin/p2g_pull.php (the health alert reads them); p2g_import: each "My Orders" export read (never the file).
--  * The settings: each account's first day (empty: switched off), how long a priced label waits for its tracking number, how long a paid
--    label may wait for its price before the health alert says so.
-- The money: one Parcel2Go invoice = one carrier_invoice (carrier parcel2go) with its rows (carrier_bill_row) and every penny of them as
-- allocations (carrier_allocation): DeliveryBills::orderCost / dayFigures and ProfitDays::compute read them as they read Royal Mail's.
--
-- HOW IT IS APPLIED: CREATE TABLE IF NOT EXISTS (new, empty); the ENUM and the CHECK changed only while they are the old ones (prepared
-- statements, as 0078); seed rows ON DUPLICATE KEY / NOT EXISTS (never changing a row that is there). Every statement can run again and
-- changes nothing the second time. Rollback: the release before (15e32fa) with this file added byte for byte (RollbackReleaseTest); the new
-- tables and rows stay, unused (the release before never reads them; its code never writes a parcel2go bill).

SET @cw_0085_family = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carrier'
    AND COLUMN_NAME = 'family' AND COLUMN_TYPE = 'enum(''per_parcel'',''pooled'')') = 1,
  'ALTER TABLE carrier MODIFY COLUMN family ENUM(''per_parcel'',''pooled'',''per_charge'') NOT NULL COMMENT ''per_parcel: a bill line per parcel (its tracking number); pooled: lines per day and service; per_charge: a bill per charge, its rows put on a parcel by its tracking number (Parcel2Go, 0085)'', ALGORITHM = COPY, LOCK = SHARED',
  'DO 0');
PREPARE cw_0085_family FROM @cw_0085_family;
EXECUTE cw_0085_family;
DEALLOCATE PREPARE cw_0085_family;

INSERT INTO carrier (code, name, family) VALUES ('parcel2go', 'Parcel2Go', 'per_charge') ON DUPLICATE KEY UPDATE code = code;

SET @cw_0085_format = IF((SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'ck_carrier_account_format' AND CHECK_CLAUSE NOT LIKE '%p2g_api%') = 1,
  'ALTER TABLE carrier_account DROP CHECK ck_carrier_account_format, ADD CONSTRAINT ck_carrier_account_format CHECK (bill_format IN (''dpd_csv'', ''rm_oba_page'', ''p2g_api'') AND label_source IN (''connector'', ''click_and_drop'', ''p2g_api'')), ALGORITHM = COPY, LOCK = SHARED',
  'DO 0');
PREPARE cw_0085_format FROM @cw_0085_format;
EXECUTE cw_0085_format;
DEALLOCATE PREPARE cw_0085_format;

-- The two accounts, OFF (nothing is read or counted until the go-live kit's switch step, after the owner's keys and the read-only probe).
INSERT INTO carrier_account (carrier, account_no, name, bill_format, label_source, key_name, main_party_id, state)
  SELECT 'parcel2go', x.account_no, x.name, 'p2g_api', 'p2g_api', x.key_name, p.id, 'off'
  FROM (SELECT 'p2g-vapeandgo' AS account_no, 'Parcel2Go (Vape and Go)' AS name, 'p2g_vapeandgo' AS key_name, 'vapeandgo' AS party
        UNION ALL SELECT 'p2g-vapebig', 'Parcel2Go (Vape Big)', 'p2g_vapebig', 'vapebig') x
  JOIN delivery_party p ON p.code = x.party
  WHERE NOT EXISTS (SELECT 1 FROM carrier_account a WHERE a.key_name = x.key_name)
  ON DUPLICATE KEY UPDATE carrier = carrier_account.carrier;

INSERT INTO carrier_account_party (account_id, party_id, from_day)
  SELECT a.id, p.id, '2020-01-01' FROM carrier_account a JOIN delivery_party p ON p.code IN ('vapeandgo', 'vapebig', 'electrofag')
  WHERE a.carrier = 'parcel2go' AND a.key_name IN ('p2g_vapeandgo', 'p2g_vapebig')
  ON DUPLICATE KEY UPDATE account_id = carrier_account_party.account_id;

CREATE TABLE IF NOT EXISTS p2g_run (
  id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  account_id      SMALLINT UNSIGNED NOT NULL,
  kind            ENUM('pull','import','switch','undo') NOT NULL,
  state           ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
  calls           INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'requests sent to Parcel2Go',
  listed          INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'lines in the recent list (or the export)',
  lines_new       INT UNSIGNED      NOT NULL DEFAULT 0,
  tracking_found  INT UNSIGNED      NOT NULL DEFAULT 0,
  priced          INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'invoices read and stored',
  refused         INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'invoices refused (each line keeps its reason)',
  placed          INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'lines whose money was put somewhere new',
  gap_seen        TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1: every line of a full recent list was new: lines may have been missed (the export fills them); written WITH the labels',
  gap_from        DATE              NULL COMMENT 'the gap: from the newest paid day known before the list ...',
  gap_to          DATE              NULL COMMENT '... to the oldest paid day of the new list: an export covering these days clears it',
  replan_after    BIGINT UNSIGNED   NULL COMMENT 'the last bill this run placed again: the next run goes on after it (NULL: from the start)',
  error           VARCHAR(255)      NULL COMMENT 'why it failed, in CW''s own words (never Parcel2Go''s text, never the key)',
  actor           VARCHAR(64)       NOT NULL,
  started_at      DATETIME(6)       NOT NULL,
  finished_at     DATETIME(6)       NULL,
  PRIMARY KEY (id),
  KEY ix_p2g_run_account (account_id, state, started_at),
  CONSTRAINT fk_p2g_run_account FOREIGN KEY (account_id) REFERENCES carrier_account (id),
  CONSTRAINT ck_p2g_run CHECK (gap_seen IN (0, 1) AND (state = 'running') = (finished_at IS NULL) AND (gap_seen = 1 OR (gap_from IS NULL AND gap_to IS NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='each run of bin/p2g_pull.php (docs/decisions.md PGL9): never deleted';

CREATE TABLE IF NOT EXISTS p2g_line (
  account_id      SMALLINT UNSIGNED NOT NULL,
  order_line_id   BIGINT UNSIGNED   NOT NULL COMMENT 'the P2G number (P2G150169740 is 150169740)',
  transaction_id  BIGINT UNSIGNED   NULL COMMENT 'the Parcel2Go order (checkout basket) it was paid in, from the API',
  source          ENUM('api','report','invoice') NOT NULL COMMENT 'what brought it first (invoice: a label named on a basket''s invoice CW had not seen yet); the others only fill empty fields',
  kind            ENUM('parcel','topup','other') NOT NULL COMMENT 'topup: a PrePay top-up, never a delivery cost (PGL7)',
  p2g_source      VARCHAR(16)       NULL COMMENT 'Parcel2Go''s Source word from a fixed list (OrderProcess, Return ...), else other',
  service_slug    VARCHAR(64)       NULL,
  courier_slug    VARCHAR(40)       NULL,
  country_iso3    CHAR(3)           NULL,
  parcels         TINYINT UNSIGNED  NOT NULL DEFAULT 1,
  paid_at         DATETIME(6)       NULL COMMENT 'UTC; the export''s Created, or the invoice''s date (00:00 UK), when the API has not given it',
  paid_day        DATE              NULL COMMENT 'its UK day: the day a charge on no order counts on',
  doc_hash        VARCHAR(128)      NULL COMMENT 'Parcel2Go''s hash of the line (its invoice''s key): never shown, never logged',
  price_state     ENUM('unpaid','due','priced','refused','not_needed') NOT NULL
                  COMMENT 'due: its invoice to read (written before any request: a run that stops leaves it due); refused: its invoice could not be read (refuse_code); not_needed: before the account''s first day, or a top-up',
  price_tries     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_try_at     DATETIME(6)       NULL,
  refuse_code     VARCHAR(32)       NULL COMMENT 'from a fixed list (P2gInvoice::REFUSALS, P2gApi): never Parcel2Go''s text',
  invoice_id      BIGINT UNSIGNED   NULL COMMENT 'the live bill (its basket''s invoice) that holds its price row',
  track_next_at   DATETIME(6)       NULL COMMENT 'when its tracking number is asked for again',
  first_at        DATETIME(6)       NOT NULL,
  updated_at      DATETIME(6)       NOT NULL,
  PRIMARY KEY (account_id, order_line_id),
  KEY ix_p2g_line_due (account_id, price_state, next_try_at),
  KEY ix_p2g_line_day (account_id, paid_day),
  KEY ix_p2g_line_invoice (invoice_id),
  KEY ix_p2g_line_no (order_line_id) COMMENT 'a P2G number is one account''s: an invoice or an export naming it is checked against every account',
  CONSTRAINT fk_p2g_line_account FOREIGN KEY (account_id) REFERENCES carrier_account (id),
  CONSTRAINT fk_p2g_line_invoice FOREIGN KEY (invoice_id) REFERENCES carrier_invoice (id),
  CONSTRAINT ck_p2g_line_shapes CHECK (order_line_id > 0 AND (p2g_source IS NULL OR REGEXP_LIKE(p2g_source, '^[A-Za-z]{2,16}$', 'c'))
    AND (service_slug IS NULL OR REGEXP_LIKE(service_slug, '^[a-z0-9][a-z0-9-]{0,63}$', 'c'))
    AND (courier_slug IS NULL OR REGEXP_LIKE(courier_slug, '^[a-z0-9][a-z0-9-]{0,39}$', 'c'))
    AND (country_iso3 IS NULL OR REGEXP_LIKE(country_iso3, '^[A-Z]{3}$', 'c'))
    AND parcels BETWEEN 1 AND 50 AND (paid_at IS NULL) = (paid_day IS NULL)
    AND (doc_hash IS NULL OR REGEXP_LIKE(doc_hash, '^[A-Za-z0-9+/=_-]{8,128}$', 'c'))
    AND (refuse_code IS NULL OR REGEXP_LIKE(refuse_code, '^[a-z][a-z0-9_]{1,31}$', 'c'))
    AND (price_state = 'priced') = (invoice_id IS NOT NULL) AND (price_state <> 'refused' OR refuse_code IS NOT NULL)
    AND (price_state <> 'unpaid' OR paid_at IS NULL) AND (kind <> 'topup' OR price_state IN ('unpaid', 'not_needed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a Parcel2Go order line (docs/decisions.md PGL3): never a name, address, postcode, e-mail, phone or contents; never deleted';

CREATE TABLE IF NOT EXISTS p2g_parcel (
  account_id      SMALLINT UNSIGNED NOT NULL,
  order_line_id   BIGINT UNSIGNED   NOT NULL,
  parcel_no       TINYINT UNSIGNED  NOT NULL COMMENT '1 .. the line''s parcels',
  tracking_no     VARCHAR(32)       NULL COMMENT 'the courier''s tracking number as CW''s parcels keep it (upper case, no spaces or hyphens)',
  tracking_source ENUM('tracking_api','report') NULL,
  cw_channel_id   SMALLINT UNSIGNED NULL COMMENT 'the CW parcel its price was put on (checks PGL4): it stays on it',
  cw_order_ref    VARCHAR(64)       NULL,
  cw_parcel_ref   VARCHAR(64)       NULL,
  pinned_at       DATETIME(6)       NULL,
  PRIMARY KEY (account_id, order_line_id, parcel_no),
  KEY ix_p2g_parcel_tracking (tracking_no),
  KEY ix_p2g_parcel_cw (cw_channel_id, cw_order_ref, cw_parcel_ref),
  CONSTRAINT fk_p2g_parcel_line FOREIGN KEY (account_id, order_line_id) REFERENCES p2g_line (account_id, order_line_id),
  CONSTRAINT fk_p2g_parcel_cw FOREIGN KEY (cw_channel_id, cw_order_ref, cw_parcel_ref) REFERENCES sale_parcel (channel_id, order_ref, parcel_ref),
  CONSTRAINT ck_p2g_parcel CHECK (parcel_no BETWEEN 1 AND 50 AND (tracking_no IS NULL OR REGEXP_LIKE(tracking_no, '^[A-Z0-9]{8,32}$', 'c'))
    AND (tracking_no IS NULL) = (tracking_source IS NULL)
    AND (cw_channel_id IS NULL) = (cw_order_ref IS NULL) AND (cw_order_ref IS NULL) = (cw_parcel_ref IS NULL) AND (cw_parcel_ref IS NULL) = (pinned_at IS NULL)
    AND (cw_parcel_ref IS NULL OR tracking_no IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the parcels of a Parcel2Go line and the CW parcel its price is on (docs/decisions.md PGL4): never deleted';

CREATE TABLE IF NOT EXISTS p2g_charge (
  invoice_id      BIGINT UNSIGNED   NOT NULL,
  row_no          INT UNSIGNED      NOT NULL,
  account_id      SMALLINT UNSIGNED NOT NULL,
  order_line_id   BIGINT UNSIGNED   NOT NULL,
  kind            ENUM('price','surcharge') NOT NULL
                  COMMENT 'price: the label''s service, paid in this basket; surcharge: an Extra Payment of the label (paid later, in this basket)',
  PRIMARY KEY (invoice_id, row_no),
  KEY ix_p2g_charge_line (account_id, order_line_id),
  CONSTRAINT fk_p2g_charge_row FOREIGN KEY (invoice_id, row_no) REFERENCES carrier_bill_row (invoice_id, row_no),
  CONSTRAINT fk_p2g_charge_line FOREIGN KEY (account_id, order_line_id) REFERENCES p2g_line (account_id, order_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='each row of a Parcel2Go bill and the line it charges (docs/decisions.md PGL5): written once with its row';

CREATE TABLE IF NOT EXISTS p2g_import (
  id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  account_id      SMALLINT UNSIGNED NOT NULL,
  file_sha256     CHAR(64)          NOT NULL COMMENT 'of the file read (never the file: it holds names and postcodes)',
  rows_read       INT UNSIGNED      NOT NULL,
  line_count      INT UNSIGNED      NOT NULL,
  lines_new       INT UNSIGNED      NOT NULL,
  tracking_filled INT UNSIGNED      NOT NULL,
  first_day       DATE              NULL,
  last_day        DATE              NULL,
  actor           VARCHAR(64)       NOT NULL,
  imported_at     DATETIME(6)       NOT NULL,
  PRIMARY KEY (id),
  KEY ix_p2g_import_account (account_id, imported_at),
  CONSTRAINT fk_p2g_import_account FOREIGN KEY (account_id) REFERENCES carrier_account (id),
  CONSTRAINT ck_p2g_import CHECK (REGEXP_LIKE(file_sha256, '^[0-9a-f]{64}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='each Parcel2Go "My Orders" export read (docs/decisions.md PGL8): never the file';

-- The settings (Settings > Profit, each change kept): the accounts' first days (empty: switched off; set only by the go-live kit's switch step,
-- bin/p2g_pull.php --switch-on), the wait for a tracking number, the price wait the health alert allows.
INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('delivery.p2g_from_vapeandgo','string','""',0,'PGL2','Parcel2Go (Vape and Go''s account): the first day its labels count (empty: switched off). Set by the go-live kit''s switch step; emptied, it stops the account'),
 ('delivery.p2g_from_vapebig','string','""',0,'PGL2','Parcel2Go (Vape Big''s account): the first day its labels count (empty: switched off). Set by the go-live kit''s switch step; emptied, it stops the account'),
 ('delivery.p2g_tracking_wait_days','int','3',0,'PGL4','Parcel2Go: a priced label whose tracking number is not known yet waits this many days (in no profit), then goes on its account''s main shop "not on an order" until the number comes'),
 ('delivery.p2g_price_wait_hours','int','6',0,'PGL9','Parcel2Go: the health alert says a paid label still without its price after this many hours')
 ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key IN ('delivery.p2g_from_vapeandgo', 'delivery.p2g_from_vapebig', 'delivery.p2g_tracking_wait_days',
    'delivery.p2g_price_wait_hours')
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);
