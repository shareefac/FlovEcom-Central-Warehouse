-- 0010_key_bulk.sql — the Key spot-check and the bulk confirm of Key proposals (docs/decisions.md M26-M28; the owner's
-- decisions of 2 Oct 2026). Three append-only tables (app login: SELECT, INSERT; CW\Schema\Grants::APPEND_ONLY):
--
--  * match_proposal_basis: what a proposal was made against (ProposalBasis, M27): the listing's map_version after the
--    proposal and its suggest were written (moves on every link change and every identity change, M20), the listing
--    profile's identity_hash, the proposed item's fingerprint, the identity of the listing the item was minted from, and
--    the link of the LANE TARGET (the Vape and Go listing the barcode/transfer key named: its map_version, status, item and
--    units per item). Recorded by Proposals::add from now on; for an older proposal only when it can be proved unchanged
--    since it was made (source `backfill`). A bulk step acts only on a proposal whose basis still holds.
--  * key_sample: a named, stratified spot-check sample of Key proposals (M28): at least 20 members, a seed the server drew
--    (never the caller's), who made it, and the failed samples it was allowed to draw from again (overrides).
--  * key_sample_member: the population the sample was drawn from (every Key proposal eligible for the bulk confirm at
--    that moment) with its stratum; the sample members carry their position 1..n. The bulk confirm acts on this
--    population only, never on proposals that became Key afterwards.
-- No backfill here: bin/sample_proposals.php and bin/reband_proposals.php write the proved bases of older proposals.
-- (Numbered 0010: the phase I-2 build adds 0009_suppliers.sql; the migrator applies every pending file in name order.)

CREATE TABLE match_proposal_basis (
  proposal_id           BIGINT UNSIGNED NOT NULL,
  listing_id            INT UNSIGNED NOT NULL,
  map_version           INT UNSIGNED NOT NULL COMMENT 'channel_listing.map_version once the proposal (and its suggest) was written',
  identity_hash         CHAR(64)     NULL COMMENT 'listing_profile.identity_hash then (NULL: no profile)',
  sku_id                INT UNSIGNED NULL COMMENT 'the proposed item (NULL: none)',
  item_hash             CHAR(64)     NULL COMMENT 'CW\\Mapping\\ProposalBasis::itemHash of the item then',
  origin_identity_hash  CHAR(64)     NULL COMMENT 'identity_hash of the listing the item was minted from, then',
  target_listing_id     INT UNSIGNED NULL COMMENT 'the lane target: the listing of the proposed item the barcode/transfer key named (NULL: none found)',
  target_map_version    INT UNSIGNED NULL COMMENT 'its map_version then (moves on every link, status or identity change of it)',
  target_status         VARCHAR(16)  NULL COMMENT 'its status then',
  target_sku_id         INT UNSIGNED NULL COMMENT 'its item then',
  target_units_per_item SMALLINT UNSIGNED NULL COMMENT 'its units per item then',
  basis_hash            CHAR(64)     NOT NULL COMMENT 'sha256 of the parts above',
  source                ENUM('recorded','backfill') NOT NULL COMMENT 'recorded with the proposal, or proved unchanged since it was made',
  detail                JSON NULL COMMENT 'backfill: how it was proved (the suggest decision, the item row time, the origin and target decisions)',
  created_at            DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (proposal_id),
  KEY ix_match_proposal_basis_listing (listing_id),
  KEY ix_match_proposal_basis_sku (sku_id),
  KEY ix_match_proposal_basis_target (target_listing_id),
  CONSTRAINT fk_match_proposal_basis_proposal FOREIGN KEY (proposal_id) REFERENCES match_proposal (id),
  CONSTRAINT fk_match_proposal_basis_listing FOREIGN KEY (listing_id) REFERENCES channel_listing (id),
  CONSTRAINT fk_match_proposal_basis_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
  CONSTRAINT fk_match_proposal_basis_target FOREIGN KEY (target_listing_id) REFERENCES channel_listing (id),
  CONSTRAINT ck_match_proposal_basis_hash CHECK (basis_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT ck_match_proposal_basis_item CHECK ((sku_id IS NULL) = (item_hash IS NULL)),
  CONSTRAINT ck_match_proposal_basis_target CHECK ((target_listing_id IS NULL) = (target_map_version IS NULL)
    AND (target_listing_id IS NULL) = (target_status IS NULL) AND (target_listing_id IS NULL) = (target_units_per_item IS NULL)),
  CONSTRAINT ck_match_proposal_basis_detail CHECK (detail IS NULL OR JSON_TYPE(detail) = 'OBJECT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='append-only: what each proposal was made against (M27); one row per proposal';

CREATE TABLE key_sample (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(40)  NOT NULL COMMENT 'the bulk confirm''s batch is key_bulk:<name>',
  seed          BIGINT UNSIGNED NOT NULL COMMENT 'drawn by the server (random_int) when the sample was stored, after the population was fixed',
  method        VARCHAR(255) NOT NULL COMMENT 'how members were drawn from the seed',
  band_version  VARCHAR(16)  NOT NULL COMMENT 'CW\\Matching\\Band::VERSION when the sample was drawn',
  sample_size   SMALLINT UNSIGNED NOT NULL,
  population    INT UNSIGNED NOT NULL COMMENT 'eligible Key proposals the sample was drawn from (key_sample_member rows)',
  strata        JSON NOT NULL COMMENT '[{name, min_confidence, max_confidence, population, sample}]',
  excluded      JSON NULL COMMENT 'open Key proposals left out of the population, by reason',
  overrides     JSON NULL COMMENT 'failed samples whose listings this sample may hold again (proposals made after them only), and why',
  created_by    INT UNSIGNED NOT NULL COMMENT 'the mapping_lead who drew it: the only person whose confirmations count, and who runs the bulk',
  actor         VARCHAR(64)  NOT NULL,
  created_at    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_key_sample_name (name),
  KEY ix_key_sample_created_by (created_by),
  CONSTRAINT fk_key_sample_created_by FOREIGN KEY (created_by) REFERENCES staff_user (id),
  CONSTRAINT ck_key_sample_name CHECK (name REGEXP '^[A-Za-z0-9._-]{1,40}$'),
  CONSTRAINT ck_key_sample_size CHECK (sample_size >= 20 AND sample_size <= population),
  CONSTRAINT ck_key_sample_seed CHECK (seed >= 1),
  CONSTRAINT ck_key_sample_strata CHECK (JSON_TYPE(strata) = 'ARRAY'),
  CONSTRAINT ck_key_sample_excluded CHECK (excluded IS NULL OR JSON_TYPE(excluded) = 'OBJECT'),
  CONSTRAINT ck_key_sample_overrides CHECK (overrides IS NULL OR JSON_TYPE(overrides) = 'ARRAY')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='append-only: a named spot-check sample of Key proposals (M28)';

CREATE TABLE key_sample_member (
  sample_id      INT UNSIGNED NOT NULL,
  proposal_id    BIGINT UNSIGNED NOT NULL,
  listing_id     INT UNSIGNED NOT NULL,
  stratum        VARCHAR(16)  NOT NULL,
  ai_confidence  TINYINT UNSIGNED NULL,
  position       SMALLINT UNSIGNED NULL COMMENT '1..sample_size: in the spot-check; NULL: population only',
  PRIMARY KEY (sample_id, proposal_id),
  UNIQUE KEY uq_key_sample_member_position (sample_id, position),
  KEY ix_key_sample_member_proposal (proposal_id),
  KEY ix_key_sample_member_listing (listing_id),
  CONSTRAINT fk_key_sample_member_sample FOREIGN KEY (sample_id) REFERENCES key_sample (id),
  CONSTRAINT fk_key_sample_member_proposal FOREIGN KEY (proposal_id) REFERENCES match_proposal (id),
  CONSTRAINT fk_key_sample_member_listing FOREIGN KEY (listing_id) REFERENCES channel_listing (id),
  CONSTRAINT ck_key_sample_member_position CHECK (position IS NULL OR position >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='append-only: the population of a sample; position marks the spot-check members';
