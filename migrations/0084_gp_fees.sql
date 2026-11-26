-- 0084_gp_fees.sql — CW-1174: Global Payments' card fees in CW's profit (docs/decisions.md GPF1-; the owner, 26 Nov 2026 ~14:10 UK: "Yes, do
-- that, estimate is fine"). Every day CW ESTIMATES each card order's fee (its amount x the account's rate, marked "estimated"); when a
-- month's invoice from Global Payments is entered, its real net charge REPLACES that month's estimates, shared over the month's card orders
-- by amount. It only ADDS: no existing row changes. (The migration number 0084 is taken here: CW-1145's WooCommerce design moves on.)
--
--  * gp_invoice: one row per invoice entered (an account and a CW calendar month): the merchant number, invoice date, sales, net charge, VAT
--    and charge applied as printed, the rate they make (net / sales), whether its spread over the month's orders is due or done, and the part
--    that went on no order (a month with no card order in CW: on the account's main shop, the month's last day; other_* are written only
--    under that day's guard, with the day's figure). One live invoice per account and month (live_key); deleted by a mark with a reason,
--    never removed.
--  * gp_invoice_history: every add, change, delete and spread of an invoice, before and after (append-only).
--  * gp_fee_order: one row per card order of an account switched on (gateway globalpay, a shop the account takes, paid on a day from the
--    account's first day to its last): its base (what the card was charged: grand_inc), its state (estimate at the rate in rate_e4, actual
--    from an invoice, off: counts nothing now), its fee. Written only under the order's day guard (ProfitDays::guard) with the day's figures,
--    so the days always add up to the rows (docs/decisions.md GPF4). Never deleted.
--  * gp_due: a month of an account whose invoice must be spread again (an order of it changed after the spread): taken by bin/gp_fees.php.
--  * gp_run: each run of bin/gp_fees.php and each spread (what it did, ok or failed in CW's words). Never deleted.
--  * profit_day gets three figures (ADD COLUMN, ALGORITHM = INSTANT: no copy): gp_fee (the card fees on the day's orders), gp_fee_est (the
--    part of gp_fee still estimated) and gp_other (an invoice's charge on no order).
--  * The settings (Settings > Profit, each change kept with its history): per account (vpg_online: Vape and Go Online, which also takes Vape
--    Big's card payments; ef_online: Electrofag Online) its first and last day (a first day is the 1st of a month; empty: not switched on;
--    the go-live kit sets the first days), the shops it takes, the estimate's rate (October's invoices: Vape and Go 1.01%, Electrofag 2.28%),
--    its merchant number (set by the kit: not in git); the expense supplier names that are Global Payments (the double-count guard) and how far
--    an invoice's sales may differ from CW's card takings of the month before the page asks for a tick.
--
-- HOW IT IS APPLIED: five CREATE TABLE IF NOT EXISTS (new, empty), three columns added to profit_day only while they are not there (a prepared
-- statement, as 0077 and 0080 added theirs), the settings ON DUPLICATE KEY with their baselines only when missing. No role, no permission (the
-- card fees page uses expenses.view and expenses.edit, the real profit profit.view). Every statement can run again and changes nothing the
-- second time. No undo release (CW is fixed forward: docs/decisions.md EX41); the release before never reads these tables, and its rebuild of
-- a day writes the columns it knows and leaves the three new figures at 0 on that day.

CREATE TABLE IF NOT EXISTS gp_invoice (
  id               BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  account          VARCHAR(32)       NOT NULL COMMENT 'the Global Payments account (vpg_online, ef_online)',
  month            CHAR(7)           NOT NULL COMMENT 'YYYY-MM: the CW calendar month whose estimates it replaces',
  merchant_no      VARCHAR(16)       NOT NULL COMMENT 'the merchant number as printed (digits; the account''s setting must be the same)',
  invoice_date     DATE              NOT NULL COMMENT 'the invoice''s DATE',
  sales_pence      BIGINT            NOT NULL COMMENT 'SALES: the card takings the invoice is for',
  net_pence        BIGINT            NOT NULL COMMENT 'TOTAL NET CHARGE: what counts in the profit (its VAT is reclaimable)',
  vat_pence        BIGINT            NOT NULL COMMENT 'VAT as printed',
  total_pence      BIGINT            NOT NULL COMMENT 'CHARGE APPLIED TO ACCOUNT = net + VAT',
  rate_e4          INT UNSIGNED      NOT NULL COMMENT 'net / sales in millionths of the amount (10,099 = 1.0099%)',
  note             VARCHAR(500)      NULL,
  spread_state     ENUM('due','done') NOT NULL DEFAULT 'due' COMMENT 'due: its spread over the month''s orders is not done yet (durable before acting)',
  spread_version   INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'how many spreads were done',
  spread_at        DATETIME(6)       NULL,
  spread_orders    INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'the card orders the last spread shared it over',
  spread_pence     BIGINT            NOT NULL DEFAULT 0 COMMENT 'what the last spread put on orders',
  cw_takings_pence BIGINT            NULL COMMENT 'CW''s card takings of the month (the orders'' bases) at the last spread',
  other_channel    SMALLINT UNSIGNED NULL COMMENT 'a charge on no order: the shop it counts in (the account''s main shop)',
  other_day        DATE              NULL COMMENT 'a charge on no order: the day it counts on (the month''s last day)',
  other_pence      BIGINT            NOT NULL DEFAULT 0 COMMENT 'a charge on no order (no card order in the month): written only under that day''s guard',
  version          INT UNSIGNED      NOT NULL DEFAULT 1 COMMENT 'its latest gp_invoice_history version of add, change or delete',
  created_by       INT UNSIGNED      NULL,
  created_actor    VARCHAR(64)       NOT NULL,
  created_at       DATETIME(6)       NOT NULL,
  updated_by       INT UNSIGNED      NULL,
  updated_actor    VARCHAR(64)       NOT NULL,
  updated_at       DATETIME(6)       NOT NULL,
  deleted_at       DATETIME(6)       NULL COMMENT 'deleted by a mark: the month goes back to estimates; kept with its history',
  deleted_by       INT UNSIGNED      NULL,
  deleted_actor    VARCHAR(64)       NULL,
  deleted_reason   VARCHAR(500)      NULL,
  live_key         VARCHAR(40)       GENERATED ALWAYS AS (IF(deleted_at IS NULL, CONCAT(account, '|', month), NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gp_invoice_live (live_key) COMMENT 'one live invoice per account and month',
  KEY ix_gp_invoice_account (account, month),
  KEY ix_gp_invoice_other (other_channel, other_day),
  KEY ix_gp_invoice_due (spread_state),
  KEY ix_gp_invoice_created_by (created_by),
  KEY ix_gp_invoice_updated_by (updated_by),
  KEY ix_gp_invoice_deleted_by (deleted_by),
  CONSTRAINT fk_gp_invoice_other_channel FOREIGN KEY (other_channel) REFERENCES channel (id),
  CONSTRAINT fk_gp_invoice_created_by FOREIGN KEY (created_by) REFERENCES staff_user (id),
  CONSTRAINT fk_gp_invoice_updated_by FOREIGN KEY (updated_by) REFERENCES staff_user (id),
  CONSTRAINT fk_gp_invoice_deleted_by FOREIGN KEY (deleted_by) REFERENCES staff_user (id),
  CONSTRAINT ck_gp_invoice_row CHECK (REGEXP_LIKE(account, '^[a-z][a-z0-9_]{0,31}$', 'c') AND REGEXP_LIKE(month, '^20[0-9]{2}-(0[1-9]|1[0-2])$', 'c')
    AND REGEXP_LIKE(merchant_no, '^[0-9]{6,16}$', 'c') AND sales_pence BETWEEN 0 AND 99999999999 AND net_pence BETWEEN 0 AND 9999999999
    AND vat_pence BETWEEN 0 AND 9999999999 AND total_pence = net_pence + vat_pence AND version >= 1
    AND (other_pence = 0 OR (other_channel IS NOT NULL AND other_day IS NOT NULL))
    AND (deleted_at IS NULL) = (deleted_actor IS NULL) AND (deleted_at IS NULL) = (deleted_reason IS NULL) AND (deleted_at IS NOT NULL OR deleted_by IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a Global Payments invoice entered for an account and month (docs/decisions.md GPF): changed with its history, deleted by a mark, never removed';

CREATE TABLE IF NOT EXISTS gp_invoice_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id  BIGINT UNSIGNED NOT NULL,
  version     INT UNSIGNED    NULL COMMENT 'the invoice''s version it made (add, change, delete); NULL: a spread',
  action      ENUM('add','change','delete','spread') NOT NULL,
  before_json JSON            NULL,
  after_json  JSON            NOT NULL,
  reason      VARCHAR(500)    NULL,
  actor       VARCHAR(64)     NOT NULL,
  at          DATETIME(6)     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_gp_invoice_history_invoice (invoice_id, id),
  CONSTRAINT fk_gp_invoice_history_invoice FOREIGN KEY (invoice_id) REFERENCES gp_invoice (id),
  CONSTRAINT ck_gp_invoice_history_json CHECK (JSON_TYPE(after_json) = 'OBJECT' AND (before_json IS NULL OR JSON_TYPE(before_json) = 'OBJECT'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='every add, change, delete and spread of a Global Payments invoice (docs/decisions.md GPF): append-only';

CREATE TABLE IF NOT EXISTS gp_fee_order (
  channel_id      SMALLINT UNSIGNED NOT NULL,
  order_ref       VARCHAR(64)       NOT NULL,
  account         VARCHAR(32)       NOT NULL COMMENT 'the Global Payments account that took its payment',
  day             DATE              NOT NULL COMMENT 'the order''s paid UK day when last decided (an off row: the day it counted on last)',
  base_pence      BIGINT            NOT NULL COMMENT 'what the card was charged (sale_order.grand_inc)',
  state           ENUM('estimate','actual','off') NOT NULL COMMENT 'estimate: base x rate_e4; actual: its share of an invoice; off: counts nothing now',
  fee_pence       BIGINT            NOT NULL DEFAULT 0 COMMENT 'what counts on the order now (in profit_day.gp_fee on its paid day)',
  rate_e4         INT UNSIGNED      NULL COMMENT 'estimate: the rate it was worked out at (millionths of the amount)',
  invoice_id      BIGINT UNSIGNED   NULL COMMENT 'actual: the invoice it is a share of',
  spread_version  INT UNSIGNED      NULL COMMENT 'actual: the invoice''s spread it came from',
  updated_at      DATETIME(6)       NOT NULL,
  PRIMARY KEY (channel_id, order_ref),
  KEY ix_gp_fee_order_day (account, day, state),
  KEY ix_gp_fee_order_rate (account, state, rate_e4, day) COMMENT 'the estimates at an old rate, by day (the job)',
  KEY ix_gp_fee_order_invoice (invoice_id),
  CONSTRAINT fk_gp_fee_order_order FOREIGN KEY (channel_id, order_ref) REFERENCES sale_order (channel_id, order_ref),
  CONSTRAINT fk_gp_fee_order_invoice FOREIGN KEY (invoice_id) REFERENCES gp_invoice (id),
  CONSTRAINT ck_gp_fee_order_row CHECK (REGEXP_LIKE(account, '^[a-z][a-z0-9_]{0,31}$', 'c') AND base_pence >= 0 AND fee_pence >= 0
    AND (state = 'estimate') = (rate_e4 IS NOT NULL) AND (state = 'actual') = (invoice_id IS NOT NULL) AND (state = 'actual') = (spread_version IS NOT NULL)
    AND (state <> 'off' OR fee_pence = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the Global Payments fee of each card order (docs/decisions.md GPF): written under the day guard with the day''s figures, never deleted';

CREATE TABLE IF NOT EXISTS gp_due (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account    VARCHAR(32)     NOT NULL,
  month      CHAR(7)         NOT NULL,
  queued_at  DATETIME(6)     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_gp_due_month (account, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a month whose invoice must be spread again (an order of it changed after the spread; docs/decisions.md GPF): taken by bin/gp_fees.php';

CREATE TABLE IF NOT EXISTS gp_run (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account       VARCHAR(32)     NOT NULL,
  kind          ENUM('job','nightly','backfill','spread','off') NOT NULL,
  from_day      DATE            NULL,
  to_day        DATE            NULL,
  state         ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
  sig           CHAR(16)        NULL COMMENT 'the account''s first day, last day and shops it ran with (another later: its days are gone over again)',
  orders_read   INT UNSIGNED    NOT NULL DEFAULT 0,
  rows_written  INT UNSIGNED    NOT NULL DEFAULT 0,
  pence_before  BIGINT          NOT NULL DEFAULT 0,
  pence_after   BIGINT          NOT NULL DEFAULT 0,
  error         VARCHAR(255)    NULL COMMENT 'why it failed, in CW''s own words',
  actor         VARCHAR(64)     NOT NULL,
  started_at    DATETIME(6)     NOT NULL,
  finished_at   DATETIME(6)     NULL,
  PRIMARY KEY (id),
  KEY ix_gp_run_account (account, kind, state, id),
  CONSTRAINT ck_gp_run_row CHECK ((state = 'running') = (finished_at IS NULL) AND (state = 'failed' OR error IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='a run of bin/gp_fees.php or a spread of an invoice (docs/decisions.md GPF): never deleted';

-- profit_day's three card fee figures, added only while they are not there (INSTANT: the table is not copied; a run again does nothing).
SET @cw_0084_profit_day = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'profit_day'
    AND column_name = 'gp_fee') = 0,
  'ALTER TABLE profit_day ADD COLUMN gp_fee BIGINT NOT NULL DEFAULT 0 COMMENT ''pence: Global Payments'''' fees on the day''''s paid orders (estimated or from an invoice)'', ADD COLUMN gp_fee_est BIGINT NOT NULL DEFAULT 0 COMMENT ''pence: the part of gp_fee still estimated'', ADD COLUMN gp_other BIGINT NOT NULL DEFAULT 0 COMMENT ''pence: an invoice''''s charge on no order (no card order in its month), on the month''''s last day'', ALGORITHM = INSTANT',
  'DO 0');
PREPARE cw_0084_profit_day FROM @cw_0084_profit_day;
EXECUTE cw_0084_profit_day;
DEALLOCATE PREPARE cw_0084_profit_day;

-- The settings (docs/decisions.md GPF2): each account's first day (empty: not switched on; the go-live kit sets 2026-11-01 for Vape and Go
-- Online and 2026-12-01 for Electrofag Online) and last day (Electrofag's contract ends 24 Dec 2026), its shops (Vape Big's card payments go
-- through Vape and Go Online: the lead's read of 26 Nov 2026), its rate (October's invoices), its merchant number (set by the kit), the expense
-- supplier names that are Global Payments, and the takings gap. Run again: the rows as they are.
INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('profit.gp_from_vpg_online','date','""',0,'GPF2','The first day Vape and Go Online''s card fees count (the 1st of a month); not set: not switched on'),
 ('profit.gp_until_vpg_online','date','""',0,'GPF2','The last day Vape and Go Online''s card fees count; not set: no end'),
 ('profit.gp_shops_vpg_online','string','"vapeandgo,vapebig"',0,'GPF2','The shops whose card payments go through Vape and Go Online, the main shop first'),
 ('profit.gp_rate_vpg_online','decimal','"1.01"',0,'GPF3','The percent of a card order Vape and Go Online''s fee is estimated at (proposed by each invoice entered)'),
 ('profit.gp_merchant_vpg_online','string','""',0,'GPF2','Vape and Go Online''s merchant number: an invoice entered for it must print it (the go-live kit sets it)'),
 ('profit.gp_from_ef_online','date','""',0,'GPF2','The first day Electrofag Online''s card fees count (the 1st of a month); not set: not switched on'),
 ('profit.gp_until_ef_online','date','"2026-12-24"',0,'GPF2','The last day Electrofag Online''s card fees count (its contract ends 24 Dec 2026); not set: no end'),
 ('profit.gp_shops_ef_online','string','"electrofag"',0,'GPF2','The shops whose card payments go through Electrofag Online, the main shop first'),
 ('profit.gp_rate_ef_online','decimal','"2.28"',0,'GPF3','The percent of a card order Electrofag Online''s fee is estimated at (proposed by each invoice entered)'),
 ('profit.gp_merchant_ef_online','string','""',0,'GPF2','Electrofag Online''s merchant number: an invoice entered for it must print it (the go-live kit sets it)'),
 ('profit.gp_expense_suppliers','string','"Global Payments,GlobalPay,Global Pay"',0,'GPF9','The expense supplier names that are Global Payments (an expense of theirs is checked against the card fee invoices)'),
 ('profit.gp_takings_gap_pct','decimal','"10"',0,'GPF6','An invoice whose sales differ from CW''s card takings of its month by more than this percent asks for a tick')
 ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', s.setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(s.value_json AS CHAR), 'provisional', s.provisional), 'system:migrate'
  FROM app_setting s WHERE s.setting_key IN ('profit.gp_from_vpg_online', 'profit.gp_until_vpg_online', 'profit.gp_shops_vpg_online', 'profit.gp_rate_vpg_online',
    'profit.gp_merchant_vpg_online', 'profit.gp_from_ef_online', 'profit.gp_until_ef_online', 'profit.gp_shops_ef_online', 'profit.gp_rate_ef_online',
    'profit.gp_merchant_ef_online', 'profit.gp_expense_suppliers', 'profit.gp_takings_gap_pct')
    AND NOT EXISTS (SELECT 1 FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key);
