-- 0078_royal_mail_bills.sql — delivery cost in CW profit, phase 1 of the shipping plan v3 (cw_work/delivery_cost_20261123/SHIPPING_PLAN_v3.md;
-- docs/decisions.md DL43-DL70): Royal Mail's weekly bills, account-generic (0706703000 = Vape and Go + Electrofag + the Hayati Vapes line;
-- Vape Big's account a settings row, off until its first bill ties). It only ADDS: no existing row changes; the one existing table it alters
-- (carrier_invoice, a few rows: one per carrier bill) swaps its carrier CHECK for a link to the new carrier list and gets a status "loading".
--
-- Settings (rows, not code: a new account, shop or carrier is a row; section 2 of the plan):
--  * carrier: the carriers CW knows (dpd, royal_mail) and how their bills are matched: per_parcel (a line per parcel: DPD) or pooled (a line
--    per day and service: Royal Mail). carrier_invoice.carrier links to it (its 0077 CHECK "dpd or royal_mail" is replaced).
--  * delivery_party: who a parcel's cost belongs to: one row per CW shop, plus parties with no shop in CW (hayativapes: its costs are on
--    their own line, in no shop's profit; when it joins CW its row gets its shop and a FROM day).
--  * carrier_account: a carrier account (number as printed), its bill format and label source, a key name for phase 2 (NULL in phase 1:
--    report-only, DL84), its main party (takes the charges of no parcel) and its state (active / off until its first bill ties).
--  * carrier_account_party: the parties an account serves, each from a day.
--  * carrier_channel: an account's Click & Drop channels by their exact names, each with a short code (a label keeps the code only).
--  * carrier_party_rule: how a label NOT found in CW is given its party, first rule that fits wins: by channel, by the reference's form
--    (# and digits, or N digits); a rule with no party names nobody (the label is held: shop not known).
--  * carrier_product / carrier_band: a carrier's product codes on its bills and what each is (flat price per label, price by weight band,
--    wrong-weight surcharge, surcharge of no parcel, return), and the weight bands (grams, the edge inclusive).
--  * app_setting delivery.rm_billed_more_pct (with its baseline version): a day or a Special Delivery manifest billed more units than its
--    labels by more than this percent of them is red on "needs a look" and in the health alert (DL86 7: the owner's 1%, 24 Nov 2026).
-- Data:
--  * carrier_label: one row per label a pooled account made (account x tracking number): the Click & Drop fields the matching needs, from
--    the "Manifested Orders" report (phase 1 is report-only: DL84); never a name, address, postcode, email or phone. carrier_report_import:
--    each report read (its From..To as it was run in Click & Drop); carrier_day_cover: the days each import covers WHOLE (a day after the
--    main shop's CW start moves money only when covered and checked: DL84).
--  * carrier_bill_row: a pooled bill's rows as printed (day, product, quantity, net, gross, VAT code, the reference only in a known form).
--  * carrier_allocation: where every penny of a bill row went (an order's parcel, a shop's "not on an order", a party with no shop, before
--    CW start, held), adding up to the row's net; ended (never deleted) when it no longer counts.
--  * carrier_bill_check: a bill checked on the upload screen, waiting for its Store (DL79): the bill AS READ (its top and rows, what CW
--    stores of it: never the file), bound to who checked it, the file's sha256 and the split it showed, single use, gone a day after it expires.
--  * carrier_pin_due: a bill whose labels' CW parcels are to be pinned from what its write made live (written by that write, done right
--    after it, or by the next write or the nightly: a crash in between never loses a pin, DL80).
-- profit_day: no new column (the allocations count in delivery_cost and delivery_other, as DPD's lines).
--
-- HOW IT IS APPLIED: CREATE TABLE IF NOT EXISTS (new, empty), seed rows ON DUPLICATE KEY (never changing a row that is there), and ONE ALTER of
-- carrier_invoice (a few rows: ALGORITHM = COPY, LOCK = SHARED, as 0069 and 0074 altered small tables) made only while the old CHECK is there
-- (a prepared statement, as 0077 added its columns). Every statement can run again and changes nothing the second time.
-- Rollback: the release before (14c6e31) with this file added byte for byte (RollbackReleaseTest); the new tables and rows stay, unused; the
-- link of carrier_invoice.carrier stays (the release before writes only dpd, a row of carrier).

CREATE TABLE IF NOT EXISTS carrier (
  code        VARCHAR(16)  NOT NULL,
  name        VARCHAR(64)  NOT NULL,
  family      ENUM('per_parcel','pooled') NOT NULL COMMENT 'per_parcel: a bill line per parcel (its tracking number); pooled: lines per day and service',
  PRIMARY KEY (code),
  CONSTRAINT ck_carrier_code CHECK (REGEXP_LIKE(code, '^[a-z][a-z0-9_]{1,15}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the carriers whose bills CW reads (docs/decisions.md DL43): a new carrier is a row (and its bill reader)';

INSERT INTO carrier (code, name, family) VALUES ('dpd', 'DPD', 'per_parcel'), ('royal_mail', 'Royal Mail', 'pooled')
  ON DUPLICATE KEY UPDATE code = code;

-- carrier_invoice (one ALTER, only while the 0077 carrier CHECK is there: a run again does nothing): the carrier CHECK becomes a link to
-- carrier; a fourth status "loading" (a pooled bill whose allocations are being written: it counts nowhere until the one guarded write that
-- makes it live, DL68), with the ended CHECK reading it as not ended.
SET @cw_0078_carrier = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carrier_invoice'
    AND CONSTRAINT_NAME = 'ck_carrier_invoice_carrier') = 1,
  'ALTER TABLE carrier_invoice DROP CHECK ck_carrier_invoice_carrier, ADD CONSTRAINT fk_carrier_invoice_carrier FOREIGN KEY (carrier) REFERENCES carrier (code), MODIFY COLUMN status ENUM(''live'',''superseded'',''undone'',''loading'') NOT NULL DEFAULT ''live'' COMMENT ''live counts; superseded: a corrected file replaced it; undone: Undo this upload; loading: a pooled bill being stored (counts nowhere yet)'', DROP CHECK ck_carrier_invoice_ended, ADD CONSTRAINT ck_carrier_invoice_ended CHECK ((status IN (''live'', ''loading'')) = (ended_at IS NULL) AND (ended_at IS NULL) = (ended_actor IS NULL) AND (ended_at IS NOT NULL OR ended_by IS NULL)), ALGORITHM = COPY, LOCK = SHARED',
  'DO 0');
PREPARE cw_0078_carrier FROM @cw_0078_carrier;
EXECUTE cw_0078_carrier;
DEALLOCATE PREPARE cw_0078_carrier;

CREATE TABLE IF NOT EXISTS delivery_party (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(32)       NOT NULL,
  name          VARCHAR(64)       NOT NULL,
  channel_id    SMALLINT UNSIGNED NULL COMMENT 'its CW shop; NULL: a party with no shop in CW (its costs on its own line, in no shop''s profit)',
  channel_from  DATE              NULL COMMENT 'a party that joined CW later: its costs go on its shop''s orders from this day; older ones stay on its own line',
  first_order_no INT UNSIGNED     NULL COMMENT 'its shop''s first order number in CW, as its labels'' references carry it: a label on no CW parcel that a before-start party rule fits (a reference below it) is the shop''s before CW start (DL78); set here or by bin/carrier_account.php (audited), never worked out from data',
  created_at    DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_delivery_party_code (code),
  UNIQUE KEY uq_delivery_party_channel (channel_id),
  CONSTRAINT fk_delivery_party_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT ck_delivery_party_code CHECK (REGEXP_LIKE(code, '^[a-z][a-z0-9_]{1,31}$', 'c')),
  CONSTRAINT ck_delivery_party_from CHECK (channel_from IS NULL OR channel_id IS NOT NULL),
  CONSTRAINT ck_delivery_party_first CHECK (first_order_no IS NULL OR (channel_id IS NOT NULL AND first_order_no > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='who a parcel''s delivery cost belongs to (docs/decisions.md DL44): a CW shop, or a party with no shop in CW';

-- One party per CW shop (its code and name), and Hayati Vapes with no shop (the owner, 23 Nov 2026, Q1). A party that is there is left as it is.
-- Electrofag's first order in CW: reference 5457 (its order 6521 of 17 Nov 2026 00:27, the first Electrofag parcel CW holds; the coordinator's
-- read of Electrofag's database, 24 Nov 2026: references 4767-5456 are its orders of 20 Oct - 16 Nov); a number set later by the tool stays (DL78).
INSERT INTO delivery_party (code, name, channel_id, first_order_no) SELECT c.code, LEFT(c.name, 64), c.id, IF(c.code = 'electrofag', 5457, NULL) FROM channel c
  WHERE REGEXP_LIKE(c.code, '^[a-z][a-z0-9_]{1,31}$', 'c') AND NOT EXISTS (SELECT 1 FROM delivery_party p WHERE p.channel_id = c.id OR p.code = c.code);
INSERT INTO delivery_party (code, name, channel_id) VALUES ('hayativapes', 'Hayati Vapes', NULL) ON DUPLICATE KEY UPDATE code = code;

CREATE TABLE IF NOT EXISTS carrier_account (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  carrier        VARCHAR(16)       NOT NULL,
  account_no     VARCHAR(32)       NOT NULL COMMENT 'as the carrier prints it on its bills',
  name           VARCHAR(64)       NOT NULL,
  bill_format    VARCHAR(16)       NOT NULL COMMENT 'dpd_csv, rm_oba_page',
  label_source   VARCHAR(16)       NOT NULL COMMENT 'connector (the shops'' own parcels), click_and_drop (the Manifested Orders report upload; its API pull is phase 2: DL84)',
  key_name       VARCHAR(64)       NULL COMMENT 'phase 2 only (the Click & Drop API pull, DL84): the NAME of its key in CW''s secret file, never the key; NULL in phase 1',
  main_party_id  SMALLINT UNSIGNED NOT NULL COMMENT 'takes the charges that belong to no parcel; a party with a shop',
  state          ENUM('active','off') NOT NULL COMMENT 'active: its bills and labels count; off: refused (Vape Big''s until its first bill ties)',
  created_at     DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_carrier_account (carrier, account_no),
  UNIQUE KEY uq_carrier_account_key (key_name) COMMENT 'a key is one account''s: no account reads through another''s (DL82)',
  KEY ix_carrier_account_party (main_party_id),
  CONSTRAINT fk_carrier_account_carrier FOREIGN KEY (carrier) REFERENCES carrier (code),
  CONSTRAINT fk_carrier_account_party FOREIGN KEY (main_party_id) REFERENCES delivery_party (id),
  CONSTRAINT ck_carrier_account_no CHECK (REGEXP_LIKE(account_no, '^[A-Za-z0-9-]{1,32}$', 'c')),
  CONSTRAINT ck_carrier_account_format CHECK (bill_format IN ('dpd_csv', 'rm_oba_page') AND label_source IN ('connector', 'click_and_drop')),
  CONSTRAINT ck_carrier_account_key CHECK (key_name IS NULL OR REGEXP_LIKE(key_name, '^[a-z][a-z0-9_]{1,63}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a carrier account (docs/decisions.md DL45): its bills, its labels, its key''s name, its main party';

INSERT INTO carrier_account (carrier, account_no, name, bill_format, label_source, key_name, main_party_id, state)
  SELECT 'dpd', '3012002', 'DPD (Vape and Go)', 'dpd_csv', 'connector', NULL, p.id, 'active' FROM delivery_party p WHERE p.code = 'vapeandgo'
  ON DUPLICATE KEY UPDATE carrier = carrier_account.carrier;
INSERT INTO carrier_account (carrier, account_no, name, bill_format, label_source, key_name, main_party_id, state)
  SELECT 'royal_mail', '0706703000', 'Royal Mail OBA (Vape and Go, Electrofag, Hayati Vapes)', 'rm_oba_page', 'click_and_drop', NULL, p.id, 'active'
  FROM delivery_party p WHERE p.code = 'vapeandgo'
  ON DUPLICATE KEY UPDATE carrier = carrier_account.carrier;
-- Vape Big's own Royal Mail account: its number is not known yet (the owner sends its first bill); off until that bill ties by the day rule.
-- Only while Vape Big has no Royal Mail account (its number set later by bin/carrier_account.php: a run again never makes a second one). Read
-- by report only, as every account in phase 1 (DL84).
INSERT INTO carrier_account (carrier, account_no, name, bill_format, label_source, key_name, main_party_id, state)
  SELECT 'royal_mail', 'vapebig-pending', 'Royal Mail OBA (Vape Big)', 'rm_oba_page', 'click_and_drop', NULL, p.id, 'off'
  FROM delivery_party p WHERE p.code = 'vapebig'
    AND NOT EXISTS (SELECT 1 FROM carrier_account a WHERE a.carrier = 'royal_mail' AND a.main_party_id = p.id)
  ON DUPLICATE KEY UPDATE carrier = carrier_account.carrier;

CREATE TABLE IF NOT EXISTS carrier_account_party (
  account_id  SMALLINT UNSIGNED NOT NULL,
  party_id    SMALLINT UNSIGNED NOT NULL,
  from_day    DATE              NOT NULL COMMENT 'the account serves the party from this day',
  PRIMARY KEY (account_id, party_id, from_day),
  KEY ix_carrier_account_party_party (party_id),
  CONSTRAINT fk_cap_account FOREIGN KEY (account_id) REFERENCES carrier_account (id),
  CONSTRAINT fk_cap_party FOREIGN KEY (party_id) REFERENCES delivery_party (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the parties an account serves, each from a day (docs/decisions.md DL45): a parcel of another shop never takes the account''s money';

INSERT INTO carrier_account_party (account_id, party_id, from_day)
  SELECT a.id, p.id, '2020-01-01' FROM carrier_account a JOIN delivery_party p
  WHERE (a.carrier = 'royal_mail' AND a.account_no = '0706703000' AND p.code IN ('vapeandgo', 'electrofag', 'hayativapes'))
     OR (a.carrier = 'royal_mail' AND a.account_no = 'vapebig-pending' AND p.code = 'vapebig')
     OR (a.carrier = 'dpd' AND a.account_no = '3012002' AND p.code = 'vapeandgo')
  ON DUPLICATE KEY UPDATE account_id = carrier_account_party.account_id;

CREATE TABLE IF NOT EXISTS carrier_channel (
  account_id  SMALLINT UNSIGNED NOT NULL,
  code        VARCHAR(16)       NOT NULL,
  name        VARCHAR(128)      NOT NULL COMMENT 'the channel''s exact name in Click & Drop',
  PRIMARY KEY (account_id, code),
  UNIQUE KEY uq_carrier_channel_name (name) COMMENT 'a channel name is one account''s only: a report of it is that account''s (DL75)',
  CONSTRAINT fk_carrier_channel_account FOREIGN KEY (account_id) REFERENCES carrier_account (id),
  CONSTRAINT ck_carrier_channel_code CHECK (REGEXP_LIKE(code, '^[a-z][a-z0-9_]{1,15}$', 'c') AND code <> 'other')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='an account''s Click & Drop channels (docs/decisions.md DL46): a label keeps the code of a known one, else "other"';

INSERT INTO carrier_channel (account_id, code, name)
  SELECT a.id, x.code, x.name FROM carrier_account a JOIN (
    SELECT 'api' AS code, 'Vape and Go NW Limited - API (Vape and Go NW Limited)' AS name
    UNION ALL SELECT 'woocommerce', 'Vape and Go NW Limited - WooCommerce (Vape and Go NW Limited)'
    UNION ALL SELECT 'manual', 'Manual Order Entry (Vape and Go NW Limited)') x
  WHERE a.carrier = 'royal_mail' AND a.account_no = '0706703000'
  ON DUPLICATE KEY UPDATE account_id = carrier_channel.account_id;

CREATE TABLE IF NOT EXISTS carrier_party_rule (
  account_id    SMALLINT UNSIGNED NOT NULL,
  priority      TINYINT UNSIGNED  NOT NULL COMMENT 'the first rule that fits a label wins',
  channel_code  VARCHAR(16)       NULL COMMENT 'the label''s channel (carrier_channel.code); NULL: any',
  ref_form      VARCHAR(16)       NULL COMMENT 'hash_digits (# and digits) or digits_N (exactly N digits); NULL: any',
  party_id      SMALLINT UNSIGNED NULL COMMENT 'NULL: the rule names nobody (the label is held: shop not known)',
  before_first  TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1: fits only a reference whose number is below its party''s first CW order number (delivery_party.first_order_no; none set: never fits); the label is that shop''s before CW start, whatever its day (DL78)',
  PRIMARY KEY (account_id, priority),
  KEY ix_carrier_party_rule_party (party_id),
  CONSTRAINT fk_cpr_account FOREIGN KEY (account_id) REFERENCES carrier_account (id),
  CONSTRAINT fk_cpr_party FOREIGN KEY (party_id) REFERENCES delivery_party (id),
  CONSTRAINT ck_cpr_form CHECK (ref_form IS NULL OR REGEXP_LIKE(ref_form, '^(hash_digits|digits_[1-9][0-9]?)$', 'c')),
  CONSTRAINT ck_cpr_some CHECK (channel_code IS NOT NULL OR ref_form IS NOT NULL),
  CONSTRAINT ck_cpr_before CHECK (before_first IN (0, 1) AND (before_first = 0 OR (party_id IS NOT NULL AND ref_form IS NOT NULL AND REGEXP_LIKE(ref_form, '^digits_[1-9][0-9]?$', 'c'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='how a label not found in CW is given its party (docs/decisions.md DL46); never a tracking prefix';

-- 0706703000 (the plan, section 2; phase 0 P0-6): "Manual Order Entry" names nobody; the WooCommerce channel and "#" + digits are Hayati
-- Vapes; 7 digits Vape and Go; the API channel with 4 digits below Electrofag's first CW order (5457) is Electrofag's before CW start (the
-- coordinator, 24 Nov 2026, D2; DL78); any other bare 4-digit reference names nobody (Hayati's 2833-3103 are also old Electrofag numbers:
-- they come by WooCommerce or "#", rule 2 or 3, first).
INSERT INTO carrier_party_rule (account_id, priority, channel_code, ref_form, party_id, before_first)
  SELECT a.id, x.priority, x.channel_code, x.ref_form, (SELECT p.id FROM delivery_party p WHERE p.code = x.party), x.before_first FROM carrier_account a JOIN (
    SELECT 1 AS priority, 'manual' AS channel_code, NULL AS ref_form, NULL AS party, 0 AS before_first
    UNION ALL SELECT 2, 'woocommerce', NULL, 'hayativapes', 0
    UNION ALL SELECT 3, NULL, 'hash_digits', 'hayativapes', 0
    UNION ALL SELECT 4, NULL, 'digits_7', 'vapeandgo', 0
    UNION ALL SELECT 5, 'api', 'digits_4', 'electrofag', 1) x
  WHERE a.carrier = 'royal_mail' AND a.account_no = '0706703000' AND (x.party IS NULL OR EXISTS (SELECT 1 FROM delivery_party p WHERE p.code = x.party))
  ON DUPLICATE KEY UPDATE account_id = carrier_party_rule.account_id;
INSERT INTO carrier_party_rule (account_id, priority, channel_code, ref_form, party_id)
  SELECT a.id, 1, NULL, 'digits_5', p.id FROM carrier_account a JOIN delivery_party p ON p.code = 'vapebig'
  WHERE a.carrier = 'royal_mail' AND a.account_no = 'vapebig-pending'
  ON DUPLICATE KEY UPDATE account_id = carrier_party_rule.account_id;

CREATE TABLE IF NOT EXISTS carrier_product (
  carrier       VARCHAR(16)  NOT NULL,
  product_code  VARCHAR(8)   NOT NULL COMMENT 'as printed on the bill',
  kind          ENUM('flat','band','wrong_weight','surcharge','return') NOT NULL
                COMMENT 'flat: the day''s price on every label of the service; band: by declared weight per manifest; wrong_weight: shared over a manifest; surcharge, return: no parcel named (the main shop''s "not on an order")',
  service_code  VARCHAR(16)  NULL COMMENT 'the labels'' service code (Click & Drop) of a flat or band product',
  name          VARCHAR(64)  NOT NULL,
  PRIMARY KEY (carrier, product_code),
  CONSTRAINT fk_carrier_product_carrier FOREIGN KEY (carrier) REFERENCES carrier (code),
  CONSTRAINT ck_carrier_product_code CHECK (REGEXP_LIKE(product_code, '^[A-Z0-9]{2,8}$', 'c')),
  CONSTRAINT ck_carrier_product_service CHECK ((kind IN ('flat', 'band')) = (service_code IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a carrier''s product codes on its bills (docs/decisions.md DL47): a code not listed refuses the bill';

INSERT INTO carrier_product (carrier, product_code, kind, service_code, name) VALUES
  ('royal_mail', 'TPL', 'flat', 'TPL48HV', 'Tracked 48'),
  ('royal_mail', 'TPM', 'flat', 'TPM24HV', 'Tracked 24'),
  ('royal_mail', 'TRN', 'flat', 'TRN24', 'Tracked 24 letterbox'),
  ('royal_mail', 'SD1', 'band', 'SD1', 'Special Delivery by 1pm'),
  ('royal_mail', 'RX1', 'wrong_weight', NULL, 'Wrong weight declared'),
  ('royal_mail', 'RXA', 'wrong_weight', NULL, 'Wrong weight admin charge'),
  ('royal_mail', 'RW4', 'surcharge', NULL, 'Unreadable barcode'),
  ('royal_mail', 'TSS', 'return', NULL, 'Tracked returns')
  ON DUPLICATE KEY UPDATE carrier = carrier;

CREATE TABLE IF NOT EXISTS carrier_band (
  carrier       VARCHAR(16)       NOT NULL,
  product_code  VARCHAR(8)        NOT NULL,
  band_no       TINYINT UNSIGNED  NOT NULL,
  max_grams     INT UNSIGNED      NOT NULL COMMENT 'a label of at most this declared weight (inclusive) and more than the band before is in this band',
  PRIMARY KEY (carrier, product_code, band_no),
  UNIQUE KEY uq_carrier_band_max (carrier, product_code, max_grams),
  CONSTRAINT fk_carrier_band_product FOREIGN KEY (carrier, product_code) REFERENCES carrier_product (carrier, product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='weight bands of a band product (docs/decisions.md DL47; phase 0 P0-3: edges inclusive)';

INSERT INTO carrier_band (carrier, product_code, band_no, max_grams) VALUES
  ('royal_mail', 'SD1', 1, 100), ('royal_mail', 'SD1', 2, 500), ('royal_mail', 'SD1', 3, 1000), ('royal_mail', 'SD1', 4, 2000),
  ('royal_mail', 'SD1', 5, 10000), ('royal_mail', 'SD1', 6, 20000)
  ON DUPLICATE KEY UPDATE carrier = carrier;

CREATE TABLE IF NOT EXISTS carrier_label (
  id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  account_id     SMALLINT UNSIGNED NOT NULL,
  tracking_no    VARCHAR(32)       NOT NULL,
  source         ENUM('api','report') NOT NULL COMMENT 'what brought it first; the other only fills empty fields (phase 1: report only, DL84; api is phase 2)',
  source_id      BIGINT UNSIGNED   NULL COMMENT 'Click & Drop''s order identifier',
  reference      VARCHAR(12)       NULL COMMENT 'the shop order number the label carried: up to 9 digits, or # and digits; anything else (a phone number) not kept',
  ref_unusable   TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1: a reference was given in no known shape (its text never kept): the label is never put on an order by its tracking number alone (F6)',
  channel_code   VARCHAR(16)       NULL COMMENT 'its Click & Drop channel as the code of one of the account''s (carrier_channel), else other',
  service_code   VARCHAR(16)       NULL,
  package        VARCHAR(16)       NULL COMMENT 'letter, large_letter, parcel, other',
  weight_g       INT UNSIGNED      NULL COMMENT 'declared weight, grams',
  created_on     DATETIME(6)       NULL COMMENT 'when Click & Drop took the order (its own clock: UK time)',
  manifested_at  DATETIME(6)       NULL COMMENT 'its manifest''s time (UK time)',
  manifest_no    INT UNSIGNED      NULL,
  manifest_day   DATE              NULL COMMENT 'the UK day of its manifest: the bill''s service day for a flat product',
  sales_order    VARCHAR(10)       NULL COMMENT 'the Royal Mail sales order of a Special Delivery manifest (10 digits)',
  cw_channel_id  SMALLINT UNSIGNED NULL COMMENT 'the CW parcel a plan first put its price on (checks a-e): it stays on it (DL74)',
  cw_order_ref   VARCHAR(64)       NULL,
  cw_parcel_ref  VARCHAR(64)       NULL,
  first_at       DATETIME(6)       NOT NULL,
  updated_at     DATETIME(6)       NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_carrier_label (account_id, tracking_no),
  KEY ix_carrier_label_day (account_id, manifest_day, service_code),
  KEY ix_carrier_label_manifest (account_id, manifest_no),
  KEY ix_carrier_label_sales (account_id, sales_order),
  KEY ix_carrier_label_tracking (tracking_no),
  KEY ix_carrier_label_source (account_id, source_id),
  CONSTRAINT fk_carrier_label_account FOREIGN KEY (account_id) REFERENCES carrier_account (id),
  CONSTRAINT fk_carrier_label_parcel FOREIGN KEY (cw_channel_id, cw_order_ref, cw_parcel_ref) REFERENCES sale_parcel (channel_id, order_ref, parcel_ref),
  CONSTRAINT ck_carrier_label_parcel CHECK ((cw_channel_id IS NULL) = (cw_order_ref IS NULL) AND (cw_order_ref IS NULL) = (cw_parcel_ref IS NULL)),
  CONSTRAINT ck_carrier_label_shapes CHECK (REGEXP_LIKE(tracking_no, '^[A-Z0-9]{8,32}$', 'c')
    AND (reference IS NULL OR REGEXP_LIKE(reference, '^#?[0-9]{1,9}$', 'c'))
    AND (channel_code IS NULL OR REGEXP_LIKE(channel_code, '^[a-z][a-z0-9_]{1,15}$', 'c'))
    AND (service_code IS NULL OR REGEXP_LIKE(service_code, '^[A-Z0-9]{1,16}$', 'c'))
    AND (package IS NULL OR package IN ('letter', 'large_letter', 'parcel', 'other'))
    AND (sales_order IS NULL OR REGEXP_LIKE(sales_order, '^[0-9]{10}$', 'c'))
    AND (manifest_no IS NULL OR manifest_day IS NOT NULL)
    AND ref_unusable IN (0, 1) AND (ref_unusable = 0 OR reference IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='one row per label of a pooled carrier account (docs/decisions.md DL48): never a name, address, postcode, email or phone';

CREATE TABLE IF NOT EXISTS carrier_report_import (
  id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  account_id     SMALLINT UNSIGNED NOT NULL,
  from_day       DATE              NOT NULL COMMENT 'the first day the report was run for in Click & Drop',
  to_day         DATE              NOT NULL COMMENT 'the last day (before the day of the import: a day not over is never covered)',
  file_sha256    CHAR(64)          NOT NULL COMMENT 'of the file read (never the file: it holds names and addresses)',
  rows_read      INT UNSIGNED      NOT NULL,
  labels         INT UNSIGNED      NOT NULL,
  staff_user_id  INT UNSIGNED      NULL,
  actor          VARCHAR(64)       NOT NULL,
  imported_at    DATETIME(6)       NOT NULL,
  PRIMARY KEY (id),
  KEY ix_carrier_report_import_account (account_id, to_day),
  CONSTRAINT fk_carrier_report_import_account FOREIGN KEY (account_id) REFERENCES carrier_account (id),
  CONSTRAINT ck_carrier_report_import CHECK (from_day <= to_day AND REGEXP_LIKE(file_sha256, '^[0-9a-f]{64}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='each Click & Drop "Manifested Orders" report read (docs/decisions.md DL84): its days, never the file';

CREATE TABLE IF NOT EXISTS carrier_day_cover (
  account_id     SMALLINT UNSIGNED NOT NULL,
  day            DATE              NOT NULL,
  import_id      BIGINT UNSIGNED   NOT NULL COMMENT 'the report import that covers it (written in the same transaction as its labels)',
  PRIMARY KEY (account_id, day, import_id),
  KEY ix_carrier_day_cover_import (import_id),
  CONSTRAINT fk_carrier_day_cover_account FOREIGN KEY (account_id) REFERENCES carrier_account (id),
  CONSTRAINT fk_carrier_day_cover_import FOREIGN KEY (import_id) REFERENCES carrier_report_import (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the days a report import covers whole (docs/decisions.md DL84): only these may move money, once checked';

CREATE TABLE IF NOT EXISTS carrier_bill_row (
  invoice_id    BIGINT UNSIGNED   NOT NULL,
  row_no        INT UNSIGNED      NOT NULL COMMENT 'its place among the bill''s rows',
  service_date  DATE              NOT NULL,
  product_code  VARCHAR(8)        NOT NULL,
  qty           INT               NOT NULL,
  net_pence     BIGINT            NOT NULL,
  gross_pence   BIGINT            NOT NULL,
  vat_code      CHAR(1)           NOT NULL,
  ref_kind      ENUM('none','preadvice','manifest','sales_order','week') NOT NULL
                COMMENT 'the reference in a known form only: a pre-advice id, CLICK AND DROP M<n>, ADJ<sales order>RCN, <word> Week <n>; any other text is not kept',
  ref_no        VARCHAR(16)       NULL COMMENT 'its number (digits)',
  sales_order   VARCHAR(10)       NULL COMMENT 'the Royal Mail sales order printed with a manifest row (10 digits)',
  PRIMARY KEY (invoice_id, row_no),
  KEY ix_carrier_bill_row_day (product_code, service_date),
  KEY ix_carrier_bill_row_ref (ref_kind, ref_no),
  CONSTRAINT fk_carrier_bill_row_invoice FOREIGN KEY (invoice_id) REFERENCES carrier_invoice (id),
  CONSTRAINT ck_carrier_bill_row_shapes CHECK (REGEXP_LIKE(product_code, '^[A-Z0-9]{2,8}$', 'c') AND REGEXP_LIKE(vat_code, '^[A-Z]$', 'c')
    AND (ref_kind = 'none') = (ref_no IS NULL) AND (ref_no IS NULL OR REGEXP_LIKE(ref_no, '^[0-9]{1,16}$', 'c'))
    AND (sales_order IS NULL OR REGEXP_LIKE(sales_order, '^[0-9]{10}$', 'c')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a pooled bill''s rows as printed (docs/decisions.md DL50): never changed, never deleted';

CREATE TABLE IF NOT EXISTS carrier_allocation (
  id           BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  invoice_id   BIGINT UNSIGNED   NOT NULL,
  row_no       INT UNSIGNED      NOT NULL,
  kind         ENUM('label','wrong_weight','day_line','row') NOT NULL
               COMMENT 'label: a label''s price; wrong_weight: a label''s share of a wrong-weight row; day_line: the day''s labels not charged / billed with no label; row: a row on no label (surcharge, return, no batch, pence left over)',
  how          VARCHAR(16)       NOT NULL COMMENT 'flat, band, average, shared, left_over, no_label, no_batch, surcharge, return, tail, day; waiting: held whole until its day or group is covered and checked (DL84)',
  label_id     BIGINT UNSIGNED   NULL,
  dest         ENUM('order','shop','party','before_start','held') NOT NULL
               COMMENT 'order: on an order''s parcel (in its shop''s profit, its paid day); shop: a shop''s "not on an order" on the day; party: a party with no shop (in no profit); before_start: before CW start (in no profit); held: shop not known or a conflict (in no profit)',
  channel_id   SMALLINT UNSIGNED NULL,
  order_ref    VARCHAR(64)       NULL,
  parcel_ref   VARCHAR(64)       NULL,
  party_id     SMALLINT UNSIGNED NULL,
  held_reason  VARCHAR(16)       NULL,
  day          DATE              NOT NULL COMMENT 'the bill row''s service day: the day "not on an order" counts on',
  pence        BIGINT            NOT NULL COMMENT 'ex VAT, signed',
  made_at      DATETIME(6)       NOT NULL,
  ended_at     DATETIME(6)       NULL COMMENT 'when it stopped counting (its bill undone or replaced, or the day planned again): kept, never deleted',
  live_label   VARCHAR(80) GENERATED ALWAYS AS (IF(ended_at IS NULL AND label_id IS NOT NULL, CONCAT(label_id, ':', invoice_id, ':', row_no, ':', kind), NULL)) STORED
               COMMENT 'a label holds at most one live allocation per bill row and kind',
  PRIMARY KEY (id),
  UNIQUE KEY uq_carrier_allocation_label (live_label),
  KEY ix_carrier_allocation_row (invoice_id, row_no),
  KEY ix_carrier_allocation_order (channel_id, order_ref),
  KEY ix_carrier_allocation_day (dest, day, channel_id),
  KEY ix_carrier_allocation_labelid (label_id),
  CONSTRAINT fk_carrier_allocation_row FOREIGN KEY (invoice_id, row_no) REFERENCES carrier_bill_row (invoice_id, row_no),
  CONSTRAINT fk_carrier_allocation_label FOREIGN KEY (label_id) REFERENCES carrier_label (id),
  CONSTRAINT fk_carrier_allocation_order FOREIGN KEY (channel_id, order_ref) REFERENCES sale_order (channel_id, order_ref),
  CONSTRAINT fk_carrier_allocation_parcel FOREIGN KEY (channel_id, order_ref, parcel_ref) REFERENCES sale_parcel (channel_id, order_ref, parcel_ref),
  CONSTRAINT fk_carrier_allocation_party FOREIGN KEY (party_id) REFERENCES delivery_party (id),
  CONSTRAINT fk_carrier_allocation_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT ck_carrier_allocation_dest CHECK (
    (dest = 'order' AND channel_id IS NOT NULL AND order_ref IS NOT NULL AND parcel_ref IS NOT NULL AND party_id IS NULL AND held_reason IS NULL)
    OR (dest IN ('shop', 'before_start') AND channel_id IS NOT NULL AND order_ref IS NULL AND parcel_ref IS NULL AND party_id IS NULL AND held_reason IS NULL)
    OR (dest = 'party' AND channel_id IS NULL AND order_ref IS NULL AND parcel_ref IS NULL AND party_id IS NOT NULL AND held_reason IS NULL)
    OR (dest = 'held' AND channel_id IS NULL AND order_ref IS NULL AND parcel_ref IS NULL AND party_id IS NULL AND REGEXP_LIKE(held_reason, '^[a-z_]{2,16}$', 'c'))),
  CONSTRAINT ck_carrier_allocation_label CHECK ((kind IN ('label', 'wrong_weight')) = (label_id IS NOT NULL)),
  CONSTRAINT ck_carrier_allocation_how CHECK (REGEXP_LIKE(how, '^[a-z_]{2,16}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='where every penny of a pooled bill row went (docs/decisions.md DL51): a row''s live allocations add up to its net; ended, never deleted';

CREATE TABLE IF NOT EXISTS carrier_bill_check (
  id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  token_sha256   CHAR(64)          NOT NULL COMMENT 'the sha256 of the one-time token the check page carries (never the token)',
  staff_user_id  INT UNSIGNED      NOT NULL COMMENT 'who checked it: only they can store it',
  account_id     SMALLINT UNSIGNED NOT NULL,
  replace_id     BIGINT UNSIGNED   NULL COMMENT 'the live bill a corrected file replaces; NULL: a new bill',
  file_sha256    CHAR(64)          NOT NULL COMMENT 'the sha256 of the file or the pasted text the check read',
  bill_sha256    CHAR(64)          NOT NULL COMMENT 'the fingerprint of the bill as read (its carrier_invoice.file_sha256 once stored)',
  plan_sha256    CHAR(64)          NOT NULL COMMENT 'the fingerprint of the split the check showed (the bill''s and what moved on the bills live): Store stores only that (DL80)',
  bill           MEDIUMTEXT        NOT NULL COMMENT 'the bill as read (JSON): its top and its rows as CW stores them; never the file',
  created_at     DATETIME(6)       NOT NULL,
  expires_at     DATETIME(6)       NOT NULL,
  used_at        DATETIME(6)       NULL COMMENT 'when its Store took it (once)',
  PRIMARY KEY (id),
  UNIQUE KEY uq_carrier_bill_check_token (token_sha256),
  KEY ix_carrier_bill_check_expires (expires_at),
  KEY ix_carrier_bill_check_user (staff_user_id),
  KEY ix_carrier_bill_check_account (account_id),
  KEY ix_carrier_bill_check_replace (replace_id),
  CONSTRAINT fk_carrier_bill_check_user FOREIGN KEY (staff_user_id) REFERENCES staff_user (id),
  CONSTRAINT fk_carrier_bill_check_account FOREIGN KEY (account_id) REFERENCES carrier_account (id),
  CONSTRAINT fk_carrier_bill_check_replace FOREIGN KEY (replace_id) REFERENCES carrier_invoice (id),
  CONSTRAINT ck_carrier_bill_check_shapes CHECK (REGEXP_LIKE(token_sha256, '^[0-9a-f]{64}$', 'c') AND REGEXP_LIKE(file_sha256, '^[0-9a-f]{64}$', 'c')
    AND REGEXP_LIKE(bill_sha256, '^[0-9a-f]{64}$', 'c') AND REGEXP_LIKE(plan_sha256, '^[0-9a-f]{64}$', 'c') AND JSON_VALID(bill) AND expires_at > created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a pooled bill checked on the upload screen, waiting for its Store (docs/decisions.md DL79): the bill as read, never the file';

CREATE TABLE IF NOT EXISTS carrier_pin_due (
  invoice_id     BIGINT UNSIGNED   NOT NULL,
  live_at        DATETIME(6)       NOT NULL COMMENT 'when its write made it live: the allocations on orders live then are what its labels are pinned to',
  PRIMARY KEY (invoice_id),
  CONSTRAINT fk_carrier_pin_due_invoice FOREIGN KEY (invoice_id) REFERENCES carrier_invoice (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a bill whose labels are still to be pinned to the CW parcels its write put their price on (docs/decisions.md DL80)';

CREATE TABLE IF NOT EXISTS carrier_replan_due (
  account_id     SMALLINT UNSIGNED NOT NULL,
  day            DATE              NOT NULL COMMENT 'a manifest day whose labels changed: every group of it is to be planned again',
  since          DATETIME(6)       NOT NULL,
  PRIMARY KEY (account_id, day),
  CONSTRAINT fk_carrier_replan_due_account FOREIGN KEY (account_id) REFERENCES carrier_account (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='days whose labels changed and whose groups a plan has not taken yet (docs/decisions.md DL83): written with the labels, done by the next plan';

-- The alarm of DL86 7 (the owner, 24 Nov 2026: "your 1% rule is fine"): a setting like every other (Settings › its page: settings.manage, a
-- reason, its versions in config_change and the audit), with its baseline version as 0074 gave its own. Run again: the row as it is then.
INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('delivery.rm_writes_held','string','""',0,'DL90','Royal Mail: empty, or the run id of the go-live kit''s undo while it holds every Royal Mail write (web, report import, tools, the nightly). Set and cleared by the kit only'),
 ('delivery.rm_billed_more_pct','decimal','"1.0"',0,'DL86','Royal Mail: a day of a Tracked service, or a Special Delivery manifest, billed more units than its labels by more than this percent of its labels is red on "Needs a look" (the bill''s page, the check, Profit › Delivery bills) and in the health alert. The extra units'' money is on the main shop''s "not on an order" whatever this is')
 ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key IN ('delivery.rm_billed_more_pct', 'delivery.rm_writes_held')
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);
