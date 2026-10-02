-- 0013_company_profile.sql — the company details printed on purchase orders get their own screen (docs/decisions.md I90-I99;
-- the owner's request of 2 Oct 2026: "add an option in the setting where i can add or edit these details").
--
--  * company_profile: one row per saved version, append-only for cw_app (SELECT, INSERT; CW\Schema\Grants::APPEND_ONLY). The
--    highest version is what the PO letterhead and "Deliver to" box print (CW\Company\CompanyDetails; Settings::company()).
--    Saving changed details and confirming them each add a version; the PRIMARY KEY on version is the optimistic lock (two
--    saves of the same version: one wins, the other is told the details changed meanwhile and writes nothing). A posted PO
--    keeps its own snapshot (purchase_order.company_snapshot), as before. A confirmation records the confirmed version it was
--    compared with (baseline_version, I94). Append-only does not stop the app login from ADDING a made-up version: the
--    nightly invariants C1-C4 (CW\Company\CompanyInvariants) find one (I91).
--  * Version 1 (kind seed) copies the nine company.* settings of 0009 as they are now (on staging they may have been set
--    with bin/settings.php), tidied as the screen tidies what is typed: names on one line with single spaces, addresses one
--    line per line (CR LF read as LF, spaces collapsed and trimmed, blank lines dropped), the phone's spaces collapsed; the
--    company and VAT numbers without spaces and in capitals when that gives a valid number (a VAT number of 9 or 12 digits
--    gains GB), otherwise as typed; the confirmation is kept with the actor and time of the company.confirmed row. The seed
--    is audited (company.change, actor system:migrate). Then the company.* rows are deleted from app_setting: one source of
--    truth, and code still reading them fails loudly (an unknown setting is a LogicException).
--  * review_task.subject_type gains 'company': a person who confirms their own change of the legal name, company number,
--    VAT, purchasing e-mail or delivery address opens a non-blocking review for another reviewer (I94).
-- Re-runnable (the migrator records a file only after every statement succeeded): each statement finds its work done
-- (IF NOT EXISTS, NOT EXISTS guards, an idempotent DELETE and MODIFY) or does it.

CREATE TABLE IF NOT EXISTS company_profile (
  version           INT UNSIGNED  NOT NULL COMMENT '1, 2, 3 ...: every save and every confirmation adds one; the highest is in use',
  kind              ENUM('seed','change','confirm','unconfirm') NOT NULL COMMENT 'seed (0013), change (details edited), confirm, unconfirm (a review rejected a change)',
  legal_name        VARCHAR(255)  NOT NULL DEFAULT '',
  trading_name      VARCHAR(255)  NOT NULL DEFAULT '',
  company_number    VARCHAR(255)  NOT NULL DEFAULT '' COMMENT '8 characters: 8 digits, 2 letters + 6 digits, R + 7 digits or IP/SP/NP + 5 digits + R (a seed may hold what was typed before)',
  vat_registered    TINYINT(1)    NULL COMMENT '1 VAT registered (vat_number set), 0 not VAT registered (an explicit choice), NULL not said yet',
  vat_number        VARCHAR(255)  NOT NULL DEFAULT '' COMMENT 'GB or XI + 9 or 12 digits, no spaces',
  address           VARCHAR(4000) NOT NULL DEFAULT '' COMMENT 'registered address, one line per line (LF)',
  phone             VARCHAR(255)  NOT NULL DEFAULT '',
  email             VARCHAR(255)  NOT NULL DEFAULT '' COMMENT 'purchasing e-mail',
  delivery_address  VARCHAR(4000) NOT NULL DEFAULT '' COMMENT 'warehouse delivery address, one line per line (LF)',
  confirmed         TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1: a person said these details are correct; 0: every PO PDF says DO NOT SEND',
  confirmed_by      INT UNSIGNED  NULL,
  confirmed_actor   VARCHAR(64)   NULL,
  confirmed_at      DATETIME(6)   NULL,
  baseline_version  INT UNSIGNED  NULL COMMENT 'a confirm: the confirmed version whose watched fields it was compared with (I94); NULL: none before it',
  reason            VARCHAR(500)  NULL COMMENT 'why, as typed (optional)',
  saved_by          INT UNSIGNED  NULL COMMENT 'staff_user.id; NULL: the migration (saved_actor names it)',
  saved_actor       VARCHAR(64)   NOT NULL,
  saved_at          DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (version),
  KEY ix_company_profile_saved_by (saved_by),
  KEY ix_company_profile_confirmed_by (confirmed_by),
  CONSTRAINT fk_company_profile_saved_by FOREIGN KEY (saved_by) REFERENCES staff_user (id),
  CONSTRAINT fk_company_profile_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES staff_user (id),
  CONSTRAINT ck_company_profile_version CHECK (version >= 1),
  CONSTRAINT ck_company_profile_confirmed CHECK (confirmed IN (0, 1)
    AND (confirmed = 1) = (confirmed_actor IS NOT NULL AND confirmed_at IS NOT NULL) AND (confirmed = 1 OR confirmed_by IS NULL)),
  CONSTRAINT ck_company_profile_kind CHECK ((kind <> 'confirm' OR confirmed = 1) AND (kind NOT IN ('change', 'unconfirm') OR confirmed = 0)),
  CONSTRAINT ck_company_profile_baseline CHECK (baseline_version IS NULL OR (kind = 'confirm' AND baseline_version < version)),
  CONSTRAINT ck_company_profile_vat CHECK (COALESCE(vat_registered, 0) IN (0, 1) AND (vat_number <> '') = (COALESCE(vat_registered, 0) = 1)),
  CONSTRAINT ck_company_profile_numbers CHECK (kind = 'seed' OR (
    (company_number = '' OR REGEXP_LIKE(company_number, '^([0-9]{8}|[A-Z]{2}[0-9]{6}|R[0-9]{7}|(IP|SP|NP)[0-9]{5}R)$', 'c'))
    AND (vat_number = '' OR REGEXP_LIKE(vat_number, '^(GB|XI)([0-9]{9}|[0-9]{12})$', 'c')))),
  CONSTRAINT ck_company_profile_who CHECK (kind = 'seed' OR saved_by IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='the company details printed on POs, one row per version (append-only for cw_app); the highest version is in use';

INSERT INTO company_profile (version, kind, legal_name, trading_name, company_number, vat_registered, vat_number, address, phone, email,
    delivery_address, confirmed, confirmed_actor, confirmed_at, reason, saved_by, saved_actor)
  SELECT 1, 'seed', c.legal_name, c.trading_name,
         IF(REGEXP_LIKE(c.cn, '^([0-9]{8}|[A-Z]{2}[0-9]{6}|R[0-9]{7}|(IP|SP|NP)[0-9]{5}R)$', 'c'), c.cn, c.company_number),
         IF(c.vat_number = '', NULL, 1),
         CASE WHEN REGEXP_LIKE(c.vn, '^(GB|XI)([0-9]{9}|[0-9]{12})$', 'c') THEN c.vn
              WHEN REGEXP_LIKE(c.vn, '^([0-9]{9}|[0-9]{12})$', 'c') THEN CONCAT('GB', c.vn)
              ELSE c.vat_number END,
         c.address, c.phone, c.email, c.delivery_address,
         c.confirmed, IF(c.confirmed = 1, COALESCE(c.confirmed_actor, 'system:settings'), NULL), IF(c.confirmed = 1, COALESCE(c.confirmed_at, NOW(6)), NULL),
         'copied from the old company settings', NULL, 'system:migrate'
  FROM (
    SELECT TRIM(REGEXP_REPLACE(s.legal_name, '[\t\r\n ]+', ' ')) AS legal_name,
           TRIM(REGEXP_REPLACE(s.trading_name, '[\t\r\n ]+', ' ')) AS trading_name,
           TRIM(s.company_number) AS company_number,
           TRIM(s.vat_number) AS vat_number,
           UPPER(REPLACE(REPLACE(TRIM(s.company_number), ' ', ''), '-', '')) AS cn,
           UPPER(REPLACE(REPLACE(REPLACE(TRIM(s.vat_number), ' ', ''), '-', ''), '.', '')) AS vn,
           -- An address one line per line: CR LF and CR read as LF, tabs as spaces, runs of spaces as one, no space at either
           -- end of a line, no blank line (CompanyDetails::check does the same to what is typed).
           TRIM(BOTH CHAR(10) FROM TRIM(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(
             REPLACE(REPLACE(REPLACE(s.address, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(9), ' '),
             ' {2,}', ' '), CONCAT(' ?', CHAR(10), ' ?'), CHAR(10)), CONCAT(CHAR(10), '{2,}'), CHAR(10)))) AS address,
           TRIM(REGEXP_REPLACE(s.phone, '[[:space:]]+', ' ')) AS phone,
           TRIM(s.email) AS email,
           TRIM(BOTH CHAR(10) FROM TRIM(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(
             REPLACE(REPLACE(REPLACE(s.delivery_address, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(9), ' '),
             ' {2,}', ' '), CONCAT(' ?', CHAR(10), ' ?'), CHAR(10)), CONCAT(CHAR(10), '{2,}'), CHAR(10)))) AS delivery_address,
           s.confirmed, s.confirmed_actor, s.confirmed_at
    FROM (
      SELECT
        COALESCE(MAX(CASE WHEN setting_key = 'company.legal_name' THEN JSON_UNQUOTE(value_json) END), '') AS legal_name,
        COALESCE(MAX(CASE WHEN setting_key = 'company.trading_name' THEN JSON_UNQUOTE(value_json) END), '') AS trading_name,
        COALESCE(MAX(CASE WHEN setting_key = 'company.company_number' THEN JSON_UNQUOTE(value_json) END), '') AS company_number,
        COALESCE(MAX(CASE WHEN setting_key = 'company.vat_number' THEN JSON_UNQUOTE(value_json) END), '') AS vat_number,
        COALESCE(MAX(CASE WHEN setting_key = 'company.address' THEN JSON_UNQUOTE(value_json) END), '') AS address,
        COALESCE(MAX(CASE WHEN setting_key = 'company.phone' THEN JSON_UNQUOTE(value_json) END), '') AS phone,
        COALESCE(MAX(CASE WHEN setting_key = 'company.email' THEN JSON_UNQUOTE(value_json) END), '') AS email,
        COALESCE(MAX(CASE WHEN setting_key = 'company.delivery_address' THEN JSON_UNQUOTE(value_json) END), '') AS delivery_address,
        COALESCE(MAX(CASE WHEN setting_key = 'company.confirmed' AND CAST(value_json AS CHAR) = 'true' THEN 1 END), 0) AS confirmed,
        MAX(CASE WHEN setting_key = 'company.confirmed' THEN updated_actor END) AS confirmed_actor,
        MAX(CASE WHEN setting_key = 'company.confirmed' THEN updated_at END) AS confirmed_at
      FROM app_setting WHERE setting_key LIKE 'company.%'
    ) s
  ) c
  WHERE NOT EXISTS (SELECT 1 FROM company_profile);

INSERT INTO audit_log (actor, action, entity_type, entity_id, detail)
  SELECT 'system:migrate', 'company.change', 'company_profile', '1',
         JSON_OBJECT('migration', '0013_company_profile.sql', 'version', 1, 'kind', 'seed',
           'confirmed', IF(p.confirmed = 1, CAST('true' AS JSON), CAST('false' AS JSON)),
           'after', JSON_OBJECT('legal_name', p.legal_name, 'trading_name', p.trading_name, 'company_number', p.company_number,
             'vat_registered', CASE p.vat_registered WHEN 1 THEN CAST('true' AS JSON) WHEN 0 THEN CAST('false' AS JSON) ELSE CAST('null' AS JSON) END,
             'vat_number', p.vat_number, 'address', p.address, 'phone', p.phone, 'email', p.email, 'delivery_address', p.delivery_address))
  FROM company_profile p
  WHERE p.version = 1 AND p.kind = 'seed'
    AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.entity_type = 'company_profile' AND a.entity_id = '1' AND a.action = 'company.change');

DELETE FROM app_setting WHERE setting_key IN ('company.legal_name', 'company.trading_name', 'company.address', 'company.company_number',
  'company.vat_number', 'company.phone', 'company.email', 'company.delivery_address', 'company.confirmed');

ALTER TABLE review_task MODIFY COLUMN subject_type ENUM('document','supplier','company') NOT NULL;
