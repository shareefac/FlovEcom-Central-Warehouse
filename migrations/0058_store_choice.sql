-- 0058_store_choice.sql — the stores' choice at Add product (the owner's drawing of 3 Nov 2026, "Add product to CW"; tracker CW-217;
-- docs/decisions.md SC1-SC12): when staff add a product or a variant on a store, they say what it is for the warehouse: a new item (the
-- default), a pack of an item the warehouse has (which one, how many in one pack), or not stock (a page that holds none). The store's
-- connector sends the choice (PUT /v1/store-choices); bin/apply_store_choices.php follows it once the variant itself has arrived.
--
--  * store_choice: one row per store variant (store, variant number): the first choice the store sent (a later one never replaces it,
--    SC2). Its state: waiting (the variant has not arrived, or the job has not looked yet), done (followed, or a person answered:
--    `outcome` says which), to_check (the warehouse found something like it: a person answers on Approvals > New products) and refused
--    (the choice cannot be followed: `outcome` says why; a person answers the same way). Settled rows are never removed
--    (Grants::NO_DELETE).
--  * match_decision: the CHECK that names who decides gains the stores' choice: a new item or a link made by the job `store_choice`
--    (actor system:store_choice) names no warehouse person; the person is the store's staff member, recorded in the decision's detail
--    (SC4). Every other decision still names its person, as before.
--
-- HOW IT IS APPLIED: a new table, and match_decision copied ONCE (ALGORITHM = COPY, LOCK = SHARED: reads go on, a write waits until the
-- copy ends), as 0050 did for the same CHECK: MySQL checks every row against the new one. Apply it in a quiet minute (docs/ops.md).
-- The ALTER comes first: if the server refuses it, nothing has been applied.

ALTER TABLE match_decision
  DROP CHECK ck_match_decision_decider,
  ADD CONSTRAINT ck_match_decision_decider CHECK (action IN ('suggest', 'landing') OR decided_by IS NOT NULL
    OR (actor = 'system:store_choice' AND action IN ('new_item', 'link'))),
  ALGORITHM = COPY, LOCK = SHARED;

CREATE TABLE store_choice (
  id                   BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  channel_id           SMALLINT UNSIGNED NOT NULL,
  external_variant_id  VARCHAR(64)       NOT NULL COMMENT 'the store''s variant number (channel_listing.external_variant_id)',
  choice               ENUM('new','pack','none') NOT NULL,
  pack_of_code         VARCHAR(32)       NULL COMMENT 'pack: the CW number of the item one pack holds',
  units                SMALLINT UNSIGNED NULL COMMENT 'pack: how many of that item one pack holds',
  staff_ref            VARCHAR(64)       NOT NULL COMMENT 'who chose it, as the store knows them (its own staff number)',
  staff_name           VARCHAR(128)      NOT NULL,
  chosen_at            DATETIME(6)       NOT NULL COMMENT 'when, on the store (UTC)',
  received_at          DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  state                ENUM('waiting','done','to_check','refused') NOT NULL DEFAULT 'waiting',
  outcome              VARCHAR(32)       NULL COMMENT 'what the job did or found, or how a person answered (StoreChoices::OUTCOMES)',
  detail               JSON              NULL COMMENT 'what it found: the item a barcode is on, the item of the same name, ...',
  listing_id           INT UNSIGNED      NULL COMMENT 'the variant, once it arrived',
  decision_id          BIGINT UNSIGNED   NULL COMMENT 'the decision that followed the choice, or the person''s answer',
  looked_at            DATETIME(6)       NULL COMMENT 'when the job last looked at it',
  settled_by           INT UNSIGNED      NULL COMMENT 'the warehouse person who answered a to_check or refused row',
  settled_at           DATETIME(6)       NULL,
  updated_at           DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_choice_variant (channel_id, external_variant_id),
  KEY ix_store_choice_state (state, id),
  KEY ix_store_choice_listing (listing_id),
  CONSTRAINT fk_store_choice_channel FOREIGN KEY (channel_id) REFERENCES channel (id),
  CONSTRAINT ck_store_choice_pack CHECK ((choice = 'pack') = (pack_of_code IS NOT NULL AND units IS NOT NULL)),
  CONSTRAINT ck_store_choice_units CHECK (units IS NULL OR units BETWEEN 2 AND 1000),
  CONSTRAINT ck_store_choice_settled CHECK (settled_by IS NULL OR settled_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='what the store''s staff said a new variant is, at Add product (SC1); written by PUT /v1/store-choices, followed by bin/apply_store_choices.php';
