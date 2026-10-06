-- 0014_key_bulk_hold.sql — listings held back from every Key bulk confirm for one-at-a-time review (docs/decisions.md M30).
-- One append-only table (app login: SELECT, INSERT; CW\Schema\Grants::APPEND_ONLY):
--
--  * key_bulk_hold: one row per HOLD and one per RELEASE. A hold names a proposal of a spot-check's population (the file names
--    it so) and sets its LISTING aside for one-at-a-time review: KeyEligibility then gives every proposal of that listing,
--    the held one and any newer one (a new matching run, a re-band), the reason `held_for_review`, so no bulk confirm of any
--    sample links the listing, a re-run without the file included, while the listing stays in the normal Key queue. A
--    release ends one hold (released_hold_id, at most once: the unique key). A listing is held while some hold row of it has
--    no release row, so no row is ever changed or removed: who held what and why, and who let it go again and why, stays
--    readable.
--    The schema holds: the hold names a member of the sample's POPULATION (the foreign key to key_sample_member); a release
--    names a HOLD row (not a release) of the same sample and proposal (the self foreign key through released_kind), once.
--    The code also refuses the sample's own 20 (they are the owner's one-at-a-time confirmations) and a listing other than
--    the proposal's.
-- Written by bin/key_bulk_hold.php (CW\Mapping\KeyHold); no seeds, no backfill.

CREATE TABLE key_bulk_hold (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sample_id         INT UNSIGNED NOT NULL COMMENT 'the spot-check whose population the proposal is in',
  proposal_id       BIGINT UNSIGNED NOT NULL,
  listing_id        INT UNSIGNED NOT NULL COMMENT 'the proposal''s listing (the file names both, and they must agree)',
  kind              ENUM('hold','release') NOT NULL,
  released_hold_id  BIGINT UNSIGNED NULL COMMENT 'release: the hold row it ends (a hold is released at most once)',
  released_kind     ENUM('hold','release') GENERATED ALWAYS AS (IF(released_hold_id IS NULL, NULL, 'hold')) STORED
                    COMMENT 'release: always hold, so the self foreign key accepts only a hold row as the row a release ends',
  reason            VARCHAR(500) NOT NULL COMMENT 'hold: why (the listing page and the bulk report show it); release: why it may go into a bulk again',
  staff_user_id     INT UNSIGNED NOT NULL COMMENT 'the mapping lead who held or released it',
  actor             VARCHAR(64)  NOT NULL,
  created_at        DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_key_bulk_hold_release (released_hold_id, sample_id, proposal_id),
  UNIQUE KEY uq_key_bulk_hold_self (id, kind, sample_id, proposal_id),
  KEY ix_key_bulk_hold_member (sample_id, proposal_id),
  KEY ix_key_bulk_hold_proposal (proposal_id),
  KEY ix_key_bulk_hold_listing (listing_id),
  KEY ix_key_bulk_hold_staff (staff_user_id),
  CONSTRAINT fk_key_bulk_hold_member FOREIGN KEY (sample_id, proposal_id) REFERENCES key_sample_member (sample_id, proposal_id),
  CONSTRAINT fk_key_bulk_hold_listing FOREIGN KEY (listing_id) REFERENCES channel_listing (id),
  CONSTRAINT fk_key_bulk_hold_staff FOREIGN KEY (staff_user_id) REFERENCES staff_user (id),
  CONSTRAINT fk_key_bulk_hold_released FOREIGN KEY (released_hold_id, released_kind, sample_id, proposal_id)
    REFERENCES key_bulk_hold (id, kind, sample_id, proposal_id),
  CONSTRAINT ck_key_bulk_hold_kind CHECK ((kind = 'hold') = (released_hold_id IS NULL)),
  CONSTRAINT ck_key_bulk_hold_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='append-only: listings held back from every Key bulk confirm (named by a proposal of a spot-check population), and the releases (M30)';
