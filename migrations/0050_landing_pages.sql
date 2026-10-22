-- 0050_landing_pages.sql — a website's landing pages are not products (the owner, 21-22 Oct 2026: "Landing pages are not real
-- products. Even if you see any sale on it, that only means something is broken and somehow customers were able to add the landing
-- pages to cart. So a proper fix is needed"; docs/decisions.md LP1-LP12). A landing page is the row of a product page where the
-- customer picks an option (10mg or 20mg); the shops keep it as one more option of the product (prodt_islanding), so the stores'
-- connector sends it as a website product like any other. This file adds where the warehouse keeps that kind and the orders that
-- name one. It marks NO row: existing website products are classified by bin/landing_pages.php (a dry run first, then --apply, run
-- by the owner after the deploy), new ones on arrival (CW\Mapping\LandingPages). The old code runs on this schema (it names none of
-- the new values, and every one of its statements still holds).
--
--  * channel_listing.status gains `landing`: a landing page, not a product. No link (the CHECK of 0001 holds: only mapped and
--    quarantined have an item). It leaves every matching list, count and progress line (they read unmapped and suggested), and
--    shows on its own (Store Products > Landing pages, with why). Not `ignored`: the reports and people tell the two apart.
--  * match_decision gains the two decisions of DecisionService (still the only writer of channel_listing):
--      landing      the website product is a landing page: by the system (on arrival, or the classifying tool) with why in
--                   `detail` (the website's own flag, or the rule and its version), or by a person;
--      not_landing  a matching lead says it is a real product: back to its list, and never marked again by the system.
--    prev_status gains `landing` (the status a decision found). The CHECK that only a system `suggest` has no person becomes
--    "a system suggest or a system landing mark": every other decision still names its person.
--  * landing_order: the WEBSITE BUG list (Approvals > From the stores > Website bugs). One row per order and landing page, written
--    by the reservations service in the transaction that records the order (a hold, a payment, an opening order): the warehouse
--    moves no stock for it (the line is unlinked, as before) and a person fixes the order by hand; settled once (open -> resolved or
--    dismissed, with who, when and a note), never removed (Grants::NO_DELETE). bin/health_alert.php alerts while one is open.
--  * One setting (Settings > Matching products): whether the rule (not only the website's own flag) marks newly arrived products.
--
-- HOW IT IS APPLIED (measured on the test server, MySQL 8.4.8, 22 Oct 2026; tests/Integration/Migration0050Test.php pins it). Both
-- tables are copied, ONCE each, said out loud (ALGORITHM = COPY, LOCK = SHARED: reads go on, a write waits until the copy ends):
--  * channel_listing: adding a value at the end of an ENUM is a change of the definition only in MySQL's book, but this server refuses
--    it in place here ("ALGORITHM=INPLACE is not supported for this operation. Try ALGORITHM=COPY": the column is named by the CHECK
--    of 0001). Its rows are narrow (an id, a store, a variant number, an item, a status): a few seconds for the stores' 50,000. A
--    listing push of a NEW variant, an order naming a variant CW does not know yet, and a matching decision wait for it.
--  * match_decision: a CHECK constraint is replaced, and MySQL checks every row against the new one (19,487 rows on staging).
-- So apply it in a quiet minute (docs/ops.md "Landing pages").
-- The ALTERs come first: if the server refuses one, nothing after it has been applied.

ALTER TABLE channel_listing
  MODIFY COLUMN status ENUM('unmapped','suggested','mapped','quarantined','ignored','landing') NOT NULL DEFAULT 'unmapped',
  ALGORITHM = COPY, LOCK = SHARED;

ALTER TABLE match_decision
  MODIFY COLUMN action ENUM('link','unlink','new_item','ignore','reject','suggest','merge_skus','split','unignore','send_back','landing','not_landing') NOT NULL,
  MODIFY COLUMN prev_status ENUM('unmapped','suggested','mapped','quarantined','ignored','landing') NULL,
  DROP CHECK ck_match_decision_decider,
  ADD CONSTRAINT ck_match_decision_decider CHECK (action IN ('suggest', 'landing') OR decided_by IS NOT NULL),
  ALGORITHM = COPY, LOCK = SHARED;

CREATE TABLE landing_order (
  id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  channel_id      SMALLINT UNSIGNED NOT NULL,
  order_ref       VARCHAR(64)       NOT NULL COMMENT 'the site''s order number (reservation.order_ref)',
  listing_id      INT UNSIGNED      NOT NULL COMMENT 'the landing page the order''s line named',
  reservation_id  BIGINT UNSIGNED   NULL,
  seen_in         ENUM('reserve','commit','opening') NOT NULL COMMENT 'the call that first carried the line',
  units           INT UNSIGNED      NOT NULL COMMENT 'the units the line asked for, as the website counts them',
  paid            TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1 once a payment (a commit) carried the line',
  status          ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  resolution      VARCHAR(32)       NULL COMMENT 'how it was sorted out: fixed_by_hand, refunded, no_action',
  resolved_by     INT UNSIGNED      NULL,
  resolved_at     DATETIME(6)       NULL,
  note            VARCHAR(500)      NULL,
  created_at      DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at      DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_landing_order (channel_id, order_ref, listing_id),
  KEY ix_landing_order_status (status, created_at),
  KEY ix_landing_order_listing (listing_id),
  KEY ix_landing_order_reservation (reservation_id),
  KEY ix_landing_order_resolver (resolved_by),
  CONSTRAINT fk_landing_order_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT fk_landing_order_listing FOREIGN KEY (listing_id) REFERENCES channel_listing (id),
  CONSTRAINT fk_landing_order_reservation FOREIGN KEY (reservation_id) REFERENCES reservation (id),
  CONSTRAINT fk_landing_order_resolver FOREIGN KEY (resolved_by) REFERENCES staff_user (id),
  CONSTRAINT ck_landing_order_paid CHECK (paid IN (0, 1)),
  CONSTRAINT ck_landing_order_units CHECK (units >= 1),
  CONSTRAINT ck_landing_order_settled CHECK ((status = 'open') = (resolved_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='orders a website took for a landing page (a website bug): no stock moves; settled by a person, never removed (LP8)';

INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description) VALUES
 ('mapping.landing_rule_on_arrival','bool','true',1,'LP4','The rule (not only the website''s own flag) marks a newly arrived website product as a landing page when it is certain');

INSERT INTO config_change (subject_type, subject_key, version, action, state, actor)
  SELECT 'setting', setting_key, 1, 'baseline', JSON_OBJECT('value', CAST(value_json AS CHAR), 'provisional', provisional), 'system:migrate'
  FROM app_setting WHERE setting_key IN ('mapping.landing_rule_on_arrival');
