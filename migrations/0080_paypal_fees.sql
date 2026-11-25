-- 0080_paypal_fees.sql — CW-1172: PayPal's payment fees in CW's profit, read every night from PayPal's own API (Transaction Search,
-- read only; docs/decisions.md PPF1-; the owner, 24 Nov 2026 23:58: "the PayPal fee of each sale goes on THAT order", a refund's fee back on
-- the same order, a chargeback's money and fees a cost on the order, anything with no CW order on the shop's "not on an order" line, holds,
-- releases, bank moves and payments sent or received ignored). It only ADDS: no existing row changes.
--
--  * paypal_run: one row per run of bin/paypal_fees.php (the nightly re-read, a backfill): which PayPal account and shop, the days read,
--    how many pages and rows, how many were new or changed, how many allocations were decided, and whether it ended ok or failed (in CW's own
--    words: never a PayPal answer's text, never the key). The health alert reads the newest ok run (PPF14). Never deleted.
--  * paypal_txn: one row per PayPal transaction read, by account, PayPal's transaction id AND its event code (PayPal gives a dispute hold
--    T1110 and its release T1111 the same id: PPF2). Only what the money and the matching need: never a name, an e-mail, an address, the
--    item text or a note; of the invoice and custom fields only what ONE shop's order-number form captured is kept (the shop and the number:
--    never the text itself, PPF36; a capture kept before stays when a later read's forms capture nothing). The shop a row belongs to is the shop whose order-number form its
--    invoice matches (a setting per shop: Vape and Go <7 digits>-<10 digits>, Electrofag <4-6 digits>-<10 digits>, Hayati Vapes
--    faaeeb-<digits>; the owner, 25 Nov 2026: ONE PayPal account takes the three shops' payments; PPF21). A re-read that brings another status, amount or fee changes the row in place (version + 1, its history
--    keeps before and after), never a second row. What it is (kind, by its event code: PPF3), what it costs (PPF4), whether it counts now
--    (count_state: PPF5), the order it is on (match_state: PPF6; an order of ANY shop the account serves: PPF21) and where its money is counted
--    (alloc_*: PPF7): on an order (on the order's paid
--    day for a sale's fee; on its own UK day for a refund, a chargeback and a fee) or on the shop's "not on an order" line on its own day.
--    `due` = 1 marks a row read or changed whose allocation is not decided yet (durable before acting: a run that stops half-way leaves it
--    due, the next run decides it: PPF9). Never deleted (Grants: no DELETE).
--  * paypal_txn_history: every change of a row (what PayPal says, or where its money is counted), before and after (append-only).
--  * profit_day gets two figures (ADD COLUMN, ALGORITHM = INSTANT: no copy): fee_cost (the PayPal money on the orders: a sale's fee on the
--    order's paid day, a refund's fee back, a chargeback's money and fees on their own day) and fee_other (the PayPal money on no CW order,
--    on its own day, in the shop the account is for).
--  * The settings (Settings > Profit, each change kept with its history): the first day each shop's PayPal fees count (all three not set:
--    not switched on; the go-live kit sets Vape and Go's to 2026-11-01 once its key file is placed, right before the backfill, so no alert
--    fires in between; Electrofag and Vape Big later), the shops each PayPal account takes payments for (the first its main
--    shop: the "not on an order" line of its money on no CW order; the owner, 25 Nov 2026: Electrofag and Hayati Vapes share one account,
--    Vape and Go has its own: PPF21), how many days back the nightly reads again, and the "not on an order" alarm (pounds a day). PPF13.
--
-- HOW IT IS APPLIED: three CREATE TABLE IF NOT EXISTS (new, empty), two columns added to profit_day only while they are not there (a prepared
-- statement, as 0077 added its two), the settings ON DUPLICATE KEY with their baselines only when missing. No role, no permission (the fees
-- show on Profit > Real profit and an order's page, under profit.view). Every statement can run again and changes nothing the second time.
-- No undo release (the owner, 24 Nov 2026: CW is fixed forward; docs/decisions.md EX41, PPF20); RollbackReleaseTest keeps the old recipe's
-- check (the release before, 04dbab6 since 25 Nov 13:31 (27dea71, f044e2f, 0e3db2a before it), with this file added byte for byte): the release before never reads these tables, and its rebuild of a day writes the
-- columns it knows and leaves fee_cost and fee_other at 0 on that day.

CREATE TABLE IF NOT EXISTS paypal_run (
  id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  account        VARCHAR(32)       NOT NULL COMMENT 'the PayPal account (its name in the key file)',
  channel_id     SMALLINT UNSIGNED NOT NULL COMMENT 'the shop the account is for',
  kind           ENUM('nightly','backfill') NOT NULL,
  from_day       DATE              NOT NULL COMMENT 'the first UK day read',
  to_day         DATE              NOT NULL COMMENT 'the last UK day read',
  state          ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
  windows        INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'the windows read whole (at most 7 days each)',
  pages          INT UNSIGNED      NOT NULL DEFAULT 0,
  rows_read      INT UNSIGNED      NOT NULL DEFAULT 0,
  rows_new       INT UNSIGNED      NOT NULL DEFAULT 0,
  rows_changed   INT UNSIGNED      NOT NULL DEFAULT 0,
  allocated      INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'rows whose allocation was decided (new, or moved)',
  refreshed_to   DATETIME(6)       NULL COMMENT 'PayPal''s last_refreshed_datetime of the last window read (UTC): its data is complete up to it',
  covered_to     DATE              NULL COMMENT 'the last UK day this run PROVED read from from_day (each window stored and decided, its data fresh past the day; PPF38)',
  error          VARCHAR(255)      NULL COMMENT 'why it failed, in CW''s own words (never PayPal''s text, never the key)',
  actor          VARCHAR(64)       NOT NULL,
  started_at     DATETIME(6)       NOT NULL,
  finished_at    DATETIME(6)       NULL,
  PRIMARY KEY (id),
  KEY ix_paypal_run_account (account, state, finished_at),
  KEY ix_paypal_run_channel (channel_id),
  CONSTRAINT fk_paypal_run_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT ck_paypal_run_row CHECK (from_day <= to_day AND REGEXP_LIKE(account, '^[a-z][a-z0-9_]{0,31}$', 'c')
    AND (state = 'running') = (finished_at IS NULL) AND (state = 'failed' OR error IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a run of bin/paypal_fees.php (docs/decisions.md PPF9): never deleted';

CREATE TABLE IF NOT EXISTS paypal_txn (
  id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  account         VARCHAR(32)       NOT NULL COMMENT 'the PayPal account (its name in the key file)',
  channel_id      SMALLINT UNSIGNED NOT NULL COMMENT 'the account''s main shop (profit.paypal_shops_<account>, the first): its "not on an order" line',
  txn_id          VARCHAR(32)       NOT NULL COMMENT 'PayPal''s transaction_id',
  event_code      CHAR(5)           NOT NULL COMMENT 'PayPal''s transaction_event_code (T0006 ...)',
  ref_txn_id      VARCHAR(32)       NULL COMMENT 'PayPal''s paypal_reference_id (the sale a refund or a chargeback is of)',
  ref_type        VARCHAR(8)        NULL COMMENT 'PayPal''s paypal_reference_id_type (TXN ...)',
  status          CHAR(1)           NOT NULL COMMENT 'PayPal''s transaction_status: S done, P pending, D denied, V reversed',
  initiated_at    DATETIME(6)       NOT NULL COMMENT 'transaction_initiation_date (UTC)',
  pp_updated_at   DATETIME(6)       NULL COMMENT 'transaction_updated_date (UTC)',
  day             DATE              NOT NULL COMMENT 'the UK day of initiated_at: the day a refund, a chargeback or a fee counts on',
  currency        CHAR(3)           NOT NULL COMMENT 'transaction_amount.currency_code',
  amount_pence    BIGINT            NOT NULL COMMENT 'transaction_amount in pence, signed as PayPal gives it',
  fee_currency    CHAR(3)           NULL COMMENT 'fee_amount.currency_code; NULL: PayPal gave no fee',
  fee_pence       BIGINT            NOT NULL DEFAULT 0 COMMENT 'fee_amount in pence, signed as PayPal gives it (a fee taken is below 0)',
  invoice_state   ENUM('none','order','dropped','overlap') NOT NULL COMMENT 'the invoice_id: none given; an order number of ONE shop''s form (kept below); none of the forms (nothing of it kept); two shops'' forms',
  invoice_shop    VARCHAR(32)       NULL COMMENT 'order: the shop whose form captured it',
  invoice_no      VARCHAR(12)       NULL COMMENT 'order: the order number the form captured (the only part of the invoice kept: PPF36)',
  custom_state    ENUM('none','order','dropped','overlap') NOT NULL COMMENT 'the custom_field, the same way',
  custom_shop     VARCHAR(32)       NULL,
  custom_no       VARCHAR(12)       NULL,
  read_sig        CHAR(16)          NULL COMMENT 'the account''s shops and forms it was last read with (another now: read again, PPF36)',
  pp_conflict     TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1: PayPal gave other facts with the same updated time: a look until a newer version (PPF37)',
  form_shop       VARCHAR(32)       NULL COMMENT 'the shop whose order-number form its invoice (else custom field) matches (profit.paypal_form_<shop>); NULL: none or two',
  order_no        VARCHAR(12)       NULL COMMENT 'the order number that form carries: digits only',
  kind            ENUM('sale','refund','chargeback','fee','ignore','unknown') NOT NULL COMMENT 'what it is, by its event code (PPF3)',
  cost_pence      BIGINT            NOT NULL DEFAULT 0 COMMENT 'what it costs the shop (PPF4): sale and refund: the fee; chargeback and fee: the money and the fee; money back below 0',
  count_state     ENUM('counts','pending','not_counted','before_start','outside','look') NOT NULL
                  COMMENT 'counts; pending (status P, read again); not_counted (ignored kind, denied); before_start (before its shop''s first fee day, or its shop not switched on); outside (a shop CW does not hold: Hayati Vapes; kept, never counted); look (needs a look: never counted)',
  look_reason     VARCHAR(32)       NULL COMMENT 'look: why (unknown_code, status, pp_conflict, fee_on_ignored, currency, sign, form_unread, field_conflict, ref_conflict, form_overlap, ref_unresolved, ref_missing, form_lost; a chargeback whose money is held, its fee counted: refund_not_on_paypal, order_ambiguous)',
  match_state     ENUM('order','no_order','ambiguous','no_number') NULL COMMENT 'counts (or a chargeback whose money is held): on a CW order, a number no CW order has (yet), a number of two or more, or no number',
  matched_by      ENUM('reference','invoice','custom','none') NULL COMMENT 'how its shop and number were found: PayPal''s reference to its sale first, then its invoice, then its custom field (PPF24)',
  match_order_ref VARCHAR(64)       NULL COMMENT 'order: the order of its shop with its number (also when nothing of it counts: a chargeback all refunded)',
  refund_offset_pence BIGINT        NOT NULL DEFAULT 0 COMMENT 'always 0: no offset is ever made (PPF43; a chargeback CW cannot place surely is held for a look instead)',
  refund_seen_pence BIGINT          NULL COMMENT 'a chargeback that counts on an order: the order''s refund budget its decision was made with (the refunds CW counts in profit, less PayPal''s own refunds of it: PPF34; another later: decided again)',
  decided_sig     CHAR(16)          NULL COMMENT 'the account''s shops and forms its decision was made by (another now: decided again, PPF26)',
  candidates      TINYINT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'ambiguous: how many orders have its number',
  shop_channel    SMALLINT UNSIGNED NULL COMMENT 'counts, pending, before_start (and a chargeback whose money is held): the CW shop it belongs to (its form''s, else the account''s main shop; PPF21)',
  alloc_dest      ENUM('order','shop') NULL COMMENT 'where its cost counts: on an order, on the shop''s "not on an order" line; NULL: nowhere',
  alloc_channel   SMALLINT UNSIGNED NULL COMMENT 'the shop it counts in',
  alloc_order_ref VARCHAR(64)       NULL COMMENT 'order: the order',
  alloc_day       DATE              NULL COMMENT 'the day it counts on; NULL on an order: the order''s paid day (a sale''s fee)',
  alloc_pence     BIGINT            NOT NULL DEFAULT 0 COMMENT 'what counts there (= cost_pence once decided)',
  due             TINYINT(1)        NOT NULL DEFAULT 1 COMMENT '1: read or changed, where it counts not decided yet (the run decides it; PPF9)',
  due_since       DATETIME(6)       NULL COMMENT 'due: since when (PP4 times a due row from it)',
  version         INT UNSIGNED      NOT NULL DEFAULT 1 COMMENT 'its latest paypal_txn_history version',
  first_run       BIGINT UNSIGNED   NOT NULL,
  last_run        BIGINT UNSIGNED   NOT NULL COMMENT 'the last run that read it',
  read_at         DATETIME(6)       NOT NULL COMMENT 'first read',
  changed_at      DATETIME(6)       NOT NULL COMMENT 'last changed (what PayPal says, or where it counts)',
  PRIMARY KEY (id),
  UNIQUE KEY uq_paypal_txn (account, txn_id, event_code),
  KEY ix_paypal_txn_due (due, id),
  KEY ix_paypal_txn_order (alloc_channel, alloc_order_ref),
  KEY ix_paypal_txn_day (alloc_channel, alloc_day, alloc_dest),
  KEY ix_paypal_txn_rematch (account, match_state, order_no),
  KEY ix_paypal_txn_state (account, count_state, day),
  KEY ix_paypal_txn_account_day (account, day),
  KEY ix_paypal_txn_channel (channel_id),
  KEY ix_paypal_txn_shop (account, shop_channel, count_state, day),
  KEY ix_paypal_txn_shop_no (shop_channel, order_no, kind),
  KEY ix_paypal_txn_first_run (first_run),
  KEY ix_paypal_txn_last_run (last_run),
  KEY ix_paypal_txn_sig (account, decided_sig),
  KEY ix_paypal_txn_kind (account, kind, matched_by),
  KEY ix_paypal_txn_no (account, order_no, kind),
  KEY ix_paypal_txn_ref (account, ref_txn_id),
  KEY ix_paypal_txn_read (account, read_sig, day),
  CONSTRAINT fk_paypal_txn_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT fk_paypal_txn_alloc_channel FOREIGN KEY (alloc_channel) REFERENCES channel (id),
  CONSTRAINT fk_paypal_txn_shop_channel FOREIGN KEY (shop_channel) REFERENCES channel (id),
  CONSTRAINT fk_paypal_txn_order FOREIGN KEY (alloc_channel, alloc_order_ref) REFERENCES sale_order (channel_id, order_ref),
  CONSTRAINT fk_paypal_txn_first_run FOREIGN KEY (first_run) REFERENCES paypal_run (id),
  CONSTRAINT fk_paypal_txn_last_run FOREIGN KEY (last_run) REFERENCES paypal_run (id),
  CONSTRAINT ck_paypal_txn_shapes CHECK (REGEXP_LIKE(account, '^[a-z][a-z0-9_]{0,31}$', 'c') AND REGEXP_LIKE(txn_id, '^[A-Za-z0-9]{1,32}$', 'c')
    AND REGEXP_LIKE(event_code, '^T[0-9]{4}$', 'c') AND REGEXP_LIKE(status, '^[A-Z]$', 'c') AND REGEXP_LIKE(currency, '^[A-Z]{3}$', 'c')
    AND (fee_currency IS NULL OR REGEXP_LIKE(fee_currency, '^[A-Z]{3}$', 'c')) AND (fee_currency IS NOT NULL OR fee_pence = 0)
    AND (ref_txn_id IS NULL OR REGEXP_LIKE(ref_txn_id, '^[A-Za-z0-9][A-Za-z0-9-]{0,31}$', 'c')) AND (ref_type IS NULL OR REGEXP_LIKE(ref_type, '^[A-Z]{1,8}$', 'c'))
    AND (order_no IS NULL OR REGEXP_LIKE(order_no, '^[0-9]{1,12}$', 'c')) AND (order_no IS NULL OR form_shop IS NOT NULL)
    AND (form_shop IS NULL OR REGEXP_LIKE(form_shop, '^[a-z][a-z0-9_]{0,31}$', 'c'))
    AND (invoice_state = 'order') = (invoice_no IS NOT NULL) AND (invoice_shop IS NULL) = (invoice_no IS NULL)
    AND (custom_state = 'order') = (custom_no IS NOT NULL) AND (custom_shop IS NULL) = (custom_no IS NULL)
    AND (invoice_no IS NULL OR REGEXP_LIKE(invoice_no, '^[0-9]{1,12}$', 'c')) AND (custom_no IS NULL OR REGEXP_LIKE(custom_no, '^[0-9]{1,12}$', 'c'))
    AND pp_conflict IN (0, 1)),
  CONSTRAINT ck_paypal_txn_alloc CHECK (
    (alloc_dest IS NULL AND alloc_channel IS NULL AND alloc_order_ref IS NULL AND alloc_day IS NULL AND alloc_pence = 0)
    OR (alloc_dest = 'order' AND alloc_channel IS NOT NULL AND alloc_order_ref IS NOT NULL)
    OR (alloc_dest = 'shop' AND alloc_channel IS NOT NULL AND alloc_order_ref IS NULL AND alloc_day IS NOT NULL)),
  CONSTRAINT ck_paypal_txn_state CHECK ((count_state = 'look') = (look_reason IS NOT NULL)
    AND (count_state = 'counts' OR (kind = 'chargeback' AND IFNULL(look_reason, '') IN ('refund_not_on_paypal', 'order_ambiguous')) OR match_state IS NULL)
    AND (match_state = 'ambiguous') = (candidates >= 2) AND due IN (0, 1) AND version >= 1
    AND (count_state IN ('counts', 'pending', 'before_start') OR (kind = 'chargeback' AND IFNULL(look_reason, '') IN ('refund_not_on_paypal', 'order_ambiguous'))
      OR shop_channel IS NULL)
    AND (alloc_dest IS NULL OR count_state = 'counts' OR (kind = 'chargeback' AND IFNULL(look_reason, '') IN ('refund_not_on_paypal', 'order_ambiguous')))
    AND (alloc_channel IS NULL OR alloc_channel = shop_channel)
    AND (match_state = 'order') = (match_order_ref IS NOT NULL) AND (alloc_order_ref IS NULL OR alloc_order_ref = match_order_ref)
    AND refund_offset_pence = 0 AND (due = 1 OR due_since IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a PayPal transaction read from the API (docs/decisions.md PPF1-PPF9): nothing personal; changed in place with history, never deleted';

CREATE TABLE IF NOT EXISTS paypal_txn_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  txn_row     BIGINT UNSIGNED NOT NULL COMMENT 'paypal_txn.id',
  version     INT UNSIGNED    NOT NULL,
  what        ENUM('changed','allocated') NOT NULL COMMENT 'changed: PayPal says something else now; allocated: where it counts was decided or moved',
  before_json JSON            NULL,
  after_json  JSON            NOT NULL,
  run_id      BIGINT UNSIGNED NULL,
  actor       VARCHAR(64)     NOT NULL,
  at          DATETIME(6)     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_paypal_txn_history (txn_row, version),
  KEY ix_paypal_txn_history_run (run_id),
  CONSTRAINT fk_paypal_txn_history_txn FOREIGN KEY (txn_row) REFERENCES paypal_txn (id),
  CONSTRAINT fk_paypal_txn_history_run FOREIGN KEY (run_id) REFERENCES paypal_run (id),
  CONSTRAINT ck_paypal_txn_history_json CHECK (JSON_TYPE(after_json) = 'OBJECT' AND (before_json IS NULL OR JSON_TYPE(before_json) = 'OBJECT'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='every change of a PayPal transaction row, before and after (docs/decisions.md PPF8): append-only';

-- profit_day's two PayPal figures, added only while they are not there (INSTANT: the table is not copied; a run again does nothing).
SET @cw_0080_profit_day = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'profit_day'
    AND column_name = 'fee_cost') = 0,
  'ALTER TABLE profit_day ADD COLUMN fee_cost BIGINT NOT NULL DEFAULT 0 COMMENT ''pence: the PayPal money on the orders (a sale''''s fee on the order''''s paid day; a refund''''s fee back, a chargeback''''s money and fees on their own day)'', ADD COLUMN fee_other BIGINT NOT NULL DEFAULT 0 COMMENT ''pence: the PayPal money on no CW order, on its own day, in the shop the account is for'', ALGORITHM = INSTANT',
  'DO 0');
PREPARE cw_0080_profit_day FROM @cw_0080_profit_day;
EXECUTE cw_0080_profit_day;
DEALLOCATE PREPARE cw_0080_profit_day;

-- The settings (docs/decisions.md PPF13): each shop's first PayPal fee day (the owner, 24 Nov 2026: Vape and Go now, from 1 Nov, set by the
-- go-live kit; Electrofag and Vape Big later: not set = not switched on), the days the nightly reads again, the "not on an order" alarm. Run again: the rows as they are.
INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('profit.paypal_from_vapeandgo','date','""',0,'PPF13','The first day Vape and Go''s PayPal fees count (read from PayPal every night); not set: not switched on (the go-live kit sets 2026-11-01 once the key is placed)'),
 ('profit.paypal_from_electrofag','date','""',0,'PPF13','The first day Electrofag''s PayPal fees count (read from PayPal every night); not set: not switched on'),
 ('profit.paypal_from_vapebig','date','""',0,'PPF13','The first day Vape Big''s PayPal fees count (read from PayPal every night); not set: not switched on'),
 ('profit.paypal_shops_vapeandgo','string','"vapeandgo,electrofag,hayati"',0,'PPF21','The shops the PayPal account vapeandgo takes payments for, the main shop first (a row whose invoice matches no shop''s form goes on its "not on an order" line); a code CW has no shop of (hayati) is kept, never counted'),
 ('profit.paypal_form_vapeandgo','string','"^([0-9]{7})-[0-9]{10}$"',0,'PPF21','Vape and Go''s order number in a PayPal invoice: a pattern whose (part) is the order number'),
 ('profit.paypal_form_electrofag','string','"^([0-9]{4,6})-[0-9]{10}$"',0,'PPF21','Electrofag''s order number in a PayPal invoice: a pattern whose (part) is the order number'),
 ('profit.paypal_form_hayati','string','"^faaeeb-([0-9]{1,6})$"',0,'PPF21','Hayati Vapes'' order number in a PayPal invoice (a WooCommerce shop CW does not hold: kept, never counted)'),
 ('profit.paypal_main_vapeandgo','string','"vapeandgo"',0,'PPF35','The main shop of the PayPal account vapeandgo, pinned: the account''s shops list must start with it, and its key must name it'),
 ('profit.paypal_merchant_vapeandgo','string','""',0,'PPF39','PayPal''s account number of the merchant of the account vapeandgo: its key must name it and every page PayPal gives must be of it (the go-live kit sets it)'),
 ('profit.paypal_live_vapeandgo','bool','true',0,'PPF39','The account vapeandgo''s key must be for PayPal''s live host (production)'),
 ('profit.paypal_reread_days','int','7',0,'PPF13','How many days back the nightly PayPal read goes again (statuses change, rows arrive late, orders reach CW late)'),
 ('profit.paypal_not_on_order_alarm','decimal','"50"',0,'PPF13','A day whose PayPal money on no CW order is above this many pounds is in the health alert')
 ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key IN ('profit.paypal_from_vapeandgo', 'profit.paypal_from_electrofag', 'profit.paypal_from_vapebig',
    'profit.paypal_shops_vapeandgo', 'profit.paypal_main_vapeandgo', 'profit.paypal_merchant_vapeandgo', 'profit.paypal_live_vapeandgo', 'profit.paypal_form_vapeandgo', 'profit.paypal_form_electrofag', 'profit.paypal_form_hayati', 'profit.paypal_reread_days',
    'profit.paypal_not_on_order_alarm')
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);
